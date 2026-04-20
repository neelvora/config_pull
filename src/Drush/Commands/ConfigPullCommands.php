<?php

declare(strict_types=1);

namespace Drupal\config_pull\Drush\Commands;

use Drupal\config_pull\Exception\RemoteAuthenticationException;
use Drupal\config_pull\Exception\RemoteNetworkException;
use Drupal\config_pull\Exception\RemoteRateLimitException;
use Drupal\config_pull\Exception\RemoteServerException;
use Drupal\config_pull\Exception\TransferInterruptedException;
use Drupal\config_pull\Service\ConfigDiffService;
use Drupal\config_pull\Service\RemoteClient;
use Drupal\config_pull\Service\TransferService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use Psr\Container\ContainerInterface;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

final class ConfigPullCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait {
    setSiteAliasManager as traitSetSiteAliasManager;
  }

  public function __construct(
    private readonly RemoteClient $client,
    private readonly ConfigDiffService $diffService,
    private readonly TransferService $transferService,
  ) {
    parent::__construct();
  }

  public function setSiteAliasManager(SiteAliasManagerInterface $siteAliasManager): void {
    $this->traitSetSiteAliasManager($siteAliasManager);
    $this->client->setAliasManager($siteAliasManager);
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config_pull.client'),
      $container->get('config_pull.diff'),
      $container->get('config_pull.transfer'),
    );
  }

  #[CLI\Command(name: 'config-pull:fetch', aliases: ['cpf'])]
  #[CLI\Argument(name: 'remote', description: 'Remote name from settings.php')]
  #[CLI\Option(name: 'dry-run', description: 'Show changes without writing')]
  #[CLI\Option(name: 'only', description: 'Glob pattern to include config names')]
  #[CLI\Option(name: 'exclude', description: 'Glob pattern to exclude config names')]
  #[CLI\Option(name: 'format', description: 'Output format: table (default) or json')]
  #[CLI\Option(name: 'with-translations', description: 'Include config translation collections')]
  public function pull(string $remote, array $options = ['dry-run' => FALSE, 'only' => '', 'exclude' => '', 'format' => 'table', 'with-translations' => FALSE]): void {
    $dryRun = (bool) $options['dry-run'];
    $only = $options['only'] ?: NULL;
    $exclude = $options['exclude'] ?: NULL;
    $withTranslations = (bool) $options['with-translations'];

    $this->io()->text("Connecting to '$remote'...");

    try {
      $handshake = $this->client->handshake($remote);
      $this->io()->text("Server: v{$handshake['server_version']}, {$handshake['config_count']} configs");

      if ($withTranslations && empty($handshake['supports_translations'])) {
        $this->io()->warning('Server does not support translations. The --with-translations flag will be ignored.');
        $withTranslations = FALSE;
      }

      $syncDir = $this->getSyncDir();

      $result = $this->transferService->pull($remote, $syncDir, $only, $exclude, $dryRun, $withTranslations);
    }
    catch (TransferInterruptedException $e) {
      foreach ($e->written as $name) {
        $this->io()->text("  wrote $name");
      }
      $this->io()->error("Transfer interrupted: " . $e->getMessage());
      throw $e;
    }
    catch (\Throwable $e) {
      $this->io()->error($this->formatRemoteError($e));
      throw $e;
    }

    if ($options['format'] === 'json') {
      $this->output()->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
      return;
    }

    if ($result['new'] === 0 && $result['changed'] === 0 && $result['deleted'] === 0) {
      $this->io()->success('Already up to date.');
      return;
    }

    if ($dryRun) {
      $this->io()->note("Dry run: {$result['new']} new, {$result['changed']} changed, {$result['deleted']} deleted");
      return;
    }

    foreach ($result['written'] as $name) {
      $this->io()->text("  wrote $name");
    }
    foreach ($result['removed'] as $name) {
      $this->io()->text("  deleted $name");
    }

    $this->io()->success("{$result['new']} new, {$result['changed']} changed, {$result['deleted']} deleted");
  }

  #[CLI\Command(name: 'config-pull:status', aliases: ['cps'])]
  #[CLI\Argument(name: 'remote', description: 'Remote name from settings.php')]
  #[CLI\Option(name: 'only', description: 'Glob pattern to include config names')]
  #[CLI\Option(name: 'exclude', description: 'Glob pattern to exclude config names')]
  #[CLI\Option(name: 'format', description: 'Output format: table (default) or json')]
  public function status(string $remote, array $options = ['only' => '', 'exclude' => '', 'format' => 'table']): void {
    $only = $options['only'] ?: NULL;
    $exclude = $options['exclude'] ?: NULL;

    try {
      $handshake = $this->client->handshake($remote);
      $this->io()->text("Server: v{$handshake['server_version']}, {$handshake['config_count']} configs, hash_version={$handshake['hash_version']}");

      $syncDir = $this->getSyncDir();
      $localHashes = $this->diffService->computeLocalHashes($syncDir, $only, $exclude);
      $this->io()->text("Local: " . count($localHashes) . " configs");

      $serverDiff = $this->client->diff($remote, $localHashes);
    }
    catch (\Throwable $e) {
      $this->io()->error($this->formatRemoteError($e));
      throw $e;
    }

    if ($serverDiff === NULL) {
      if ($options['format'] === 'json') {
        $this->output()->writeln(json_encode(['new' => [], 'changed' => [], 'deleted' => [], 'unchanged_count' => count($localHashes)], JSON_PRETTY_PRINT));
        return;
      }
      $this->io()->success('In sync. No changes.');
      return;
    }

    $diff = $this->diffService->buildDiffResult($localHashes, $serverDiff);
    $diff = $this->diffService->filterDiffResult($diff, $only, $exclude);

    if ($options['format'] === 'json') {
      $output = [
        'new' => array_keys($diff['new']),
        'changed' => array_keys($diff['changed']),
        'deleted' => $diff['deleted'],
        'unchanged_count' => $diff['unchanged_count'],
      ];
      $this->output()->writeln(json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
      return;
    }

    if (!empty($diff['new'])) {
      $this->io()->section('New on remote');
      foreach (array_keys($diff['new']) as $name) {
        $this->io()->text("  + $name");
      }
    }

    if (!empty($diff['changed'])) {
      $this->io()->section('Changed on remote');
      foreach (array_keys($diff['changed']) as $name) {
        $this->io()->text("  ~ $name");
      }
    }

    if (!empty($diff['deleted'])) {
      $this->io()->section('Deleted on remote');
      foreach ($diff['deleted'] as $name) {
        $this->io()->text("  - $name");
      }
    }

    $total = count($diff['new']) + count($diff['changed']) + count($diff['deleted']);
    $this->io()->note("$total change(s), {$diff['unchanged_count']} unchanged");
  }

  #[CLI\Command(name: 'config-pull:diff', aliases: ['cpd'])]
  #[CLI\Argument(name: 'remote', description: 'Remote name from settings.php')]
  #[CLI\Option(name: 'only', description: 'Glob pattern to include config names')]
  #[CLI\Option(name: 'exclude', description: 'Glob pattern to exclude config names')]
  #[CLI\Option(name: 'show-values', description: 'Show unified diff of changed values')]
  #[CLI\Option(name: 'format', description: 'Output format: table (default) or json')]
  public function diff(string $remote, array $options = ['only' => '', 'exclude' => '', 'show-values' => FALSE, 'format' => 'table']): void {
    $only = $options['only'] ?: NULL;
    $exclude = $options['exclude'] ?: NULL;
    $showValues = (bool) $options['show-values'];

    try {
      $handshake = $this->client->handshake($remote);
      $this->io()->text("Server: v{$handshake['server_version']}, {$handshake['config_count']} configs");

      $syncDir = $this->getSyncDir();
      $localHashes = $this->diffService->computeLocalHashes($syncDir, $only, $exclude);
      $serverDiff = $this->client->diff($remote, $localHashes);
    }
    catch (\Throwable $e) {
      $this->io()->error($this->formatRemoteError($e));
      throw $e;
    }

    if ($serverDiff === NULL) {
      if ($options['format'] === 'json') {
        $this->output()->writeln(json_encode(['new' => [], 'changed' => [], 'deleted' => [], 'diffs' => []], JSON_PRETTY_PRINT));
        return;
      }
      $this->io()->success('No differences.');
      return;
    }

    $diff = $this->diffService->buildDiffResult($localHashes, $serverDiff);
    $diff = $this->diffService->filterDiffResult($diff, $only, $exclude);

    $totalChanges = count($diff['new']) + count($diff['changed']) + count($diff['deleted']);
    if ($totalChanges === 0) {
      if ($options['format'] === 'json') {
        $this->output()->writeln(json_encode(['new' => [], 'changed' => [], 'deleted' => [], 'diffs' => []], JSON_PRETTY_PRINT));
        return;
      }
      $this->io()->success('No differences after filtering.');
      return;
    }

    $valueDiffs = [];
    if ($showValues) {
      $valueDiffs = $this->computeValueDiffs($remote, $syncDir, $diff);
    }

    if ($options['format'] === 'json') {
      $output = [
        'new' => array_keys($diff['new']),
        'changed' => array_keys($diff['changed']),
        'deleted' => $diff['deleted'],
        'diffs' => $valueDiffs,
      ];
      $this->output()->writeln(json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
      return;
    }

    if (!empty($diff['new'])) {
      $this->io()->section('New on remote');
      foreach (array_keys($diff['new']) as $name) {
        $this->io()->text("  + $name");
        if ($showValues && isset($valueDiffs[$name])) {
          $this->printTruncatedDiff($valueDiffs[$name]);
        }
      }
    }

    if (!empty($diff['changed'])) {
      $this->io()->section('Changed on remote');
      foreach (array_keys($diff['changed']) as $name) {
        $this->io()->text("  ~ $name");
        if ($showValues && isset($valueDiffs[$name])) {
          $this->printTruncatedDiff($valueDiffs[$name]);
        }
      }
    }

    if (!empty($diff['deleted'])) {
      $this->io()->section('Deleted on remote');
      foreach ($diff['deleted'] as $name) {
        $this->io()->text("  - $name");
      }
    }

    $this->io()->note("$totalChanges difference(s)");
  }

  private function computeValueDiffs(string $remote, string $syncDir, array $diff): array {
    $diffs = [];
    $differ = new Differ(new UnifiedDiffOutputBuilder('', FALSE));
    $names = array_merge(array_keys($diff['new']), array_keys($diff['changed']));

    if (empty($names)) {
      return $diffs;
    }

    $remoteYamls = $this->batchFetchRemoteYamls($remote, $names);

    foreach ($names as $name) {
      $remoteYaml = $remoteYamls[$name] ?? NULL;
      if ($remoteYaml === NULL) {
        $diffs[$name] = "  (could not fetch remote value)";
        continue;
      }

      $localFile = $syncDir . '/' . $name . '.yml';
      $localYaml = file_exists($localFile) ? file_get_contents($localFile) : '';

      $result = $differ->diff($localYaml, $remoteYaml);
      if (trim($result) !== '') {
        $diffs[$name] = $result;
      }
    }
    return $diffs;
  }

  private function batchFetchRemoteYamls(string $remote, array $names): array {
    try {
      $tarGzContent = $this->client->export($remote, $names);
    }
    catch (\Throwable) {
      return [];
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'config_pull_diff_');
    file_put_contents($tmpFile, $tarGzContent);

    $yamls = [];
    try {
      $tar = new \Archive_Tar($tmpFile, 'gz');
      $files = $tar->listContent();
      if (is_array($files)) {
        foreach ($files as $entry) {
          $filename = $entry['filename'];
          $configName = basename($filename, '.yml');
          $content = $tar->extractInString($filename);
          if ($content !== NULL) {
            $yamls[$configName] = $content;
          }
        }
      }
    }
    finally {
      @unlink($tmpFile);
    }

    return $yamls;
  }

  private function printTruncatedDiff(string $diffText): void {
    $diffText = $this->stripControlBytes($diffText);
    $lines = explode("\n", $diffText);
    $totalLines = count($lines);

    if ($totalLines <= 200) {
      foreach ($lines as $line) {
        $this->io()->text("    $line");
      }
      return;
    }

    $shown = array_slice($lines, 0, 50);
    foreach ($shown as $line) {
      $this->io()->text("    $line");
    }
    $remaining = $totalLines - 50;
    $this->io()->text("    [...$remaining more lines, pipe to a file for full output]");
  }

  private function stripControlBytes(string $text): string {
    return preg_replace('/\x1B\[[0-9;]*[A-Za-z]|[\x00-\x08\x0B\x0C\x0E-\x1A\x1C-\x1F\x7F]/', '', $text);
  }

  private function getSyncDir(): string {
    $syncDir = \Drupal\Core\Site\Settings::get('config_sync_directory');
    if (!$syncDir) {
      throw new \RuntimeException('config_sync_directory is not configured in settings.php');
    }
    if (!str_starts_with($syncDir, '/')) {
      $syncDir = DRUPAL_ROOT . '/' . $syncDir;
    }
    return $syncDir;
  }

  private function formatRemoteError(\Throwable $e): string {
    return match (TRUE) {
      $e instanceof RemoteAuthenticationException => "Authentication failed. Check the shared secret in settings.php.",
      $e instanceof RemoteRateLimitException => "Rate limited by the server. Try again in {$e->retryAfter} seconds.",
      $e instanceof RemoteNetworkException => "Cannot connect to the remote. Check the URI and network connectivity.",
      $e instanceof RemoteServerException => "Remote server error: " . $e->getMessage(),
      default => $e->getMessage(),
    };
  }

  #[CLI\Command(name: 'config-pull:setup', aliases: ['cpsetup'])]
  public function setup(): void {
    $this->io()->title('Config Pull Setup');

    $remoteName = $this->io()->ask('Remote name', 'prod');
    $uri = $this->io()->ask('Remote URI (e.g. https://www.example.com)');
    if (empty($uri)) {
      $this->io()->error('URI is required.');
      return;
    }

    $generateSecret = $this->io()->confirm('Generate a shared secret?', TRUE);
    if ($generateSecret) {
      $secret = bin2hex(random_bytes(32));
      $this->io()->text("Generated secret: $secret");
    }
    else {
      $secret = $this->io()->ask('Enter the shared secret');
      if (empty($secret)) {
        $this->io()->error('Secret is required.');
        return;
      }
    }

    $this->printSetupSnippets($remoteName, $uri, $secret);

    $testConnection = $this->io()->confirm('Test connectivity now?', TRUE);
    if (!$testConnection) {
      return;
    }

    $this->io()->text("Testing connection to $uri...");
    try {
      $handshake = $this->client->handshake($remoteName);
      $this->io()->success("Connected. Server v{$handshake['server_version']}, {$handshake['config_count']} configs.");
    }
    catch (\Throwable $e) {
      $this->io()->warning("Connection test failed: " . $this->formatRemoteError($e));
      $this->io()->text("This is expected if you haven't configured the server yet. Add the snippets above and try again.");
    }
  }

  public function printSetupSnippets(string $remoteName, string $uri, string $secret): void {
    $this->io()->section('Server settings.php snippet');
    $this->io()->text([
      "Add to the server's settings.php (or settings.local.php):",
      '',
      "\$settings['config_pull'] = [",
      "  'server_enabled' => TRUE,",
      "  'secret' => '$secret',",
      "];",
    ]);

    $this->io()->section('Client settings.php snippet');
    $this->io()->text([
      "Add to the client's settings.php (or settings.local.php):",
      '',
      "\$settings['config_pull_remotes'] = [",
      "  '$remoteName' => [",
      "    'uri' => '$uri',",
      "    'secret' => '$secret',",
      "  ],",
      "];",
    ]);
  }

}
