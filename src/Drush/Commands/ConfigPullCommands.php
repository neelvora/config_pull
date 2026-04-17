<?php

declare(strict_types=1);

namespace Drupal\config_pull\Drush\Commands;

use Drupal\config_pull\Service\ConfigDiffService;
use Drupal\config_pull\Service\RemoteClient;
use Drupal\config_pull\Service\TransferService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Psr\Container\ContainerInterface;

final class ConfigPullCommands extends DrushCommands {

  public function __construct(
    private readonly RemoteClient $client,
    private readonly ConfigDiffService $diffService,
    private readonly TransferService $transferService,
  ) {
    parent::__construct();
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
  #[CLI\Option(name: 'only', description: 'Glob pattern to filter config names')]
  public function pull(string $remote, array $options = ['dry-run' => FALSE, 'only' => '']): void {
    $dryRun = (bool) $options['dry-run'];
    $only = $options['only'] ?: NULL;

    $this->io()->text("Connecting to '$remote'...");

    $handshake = $this->client->handshake($remote);
    $this->io()->text("Server: v{$handshake['server_version']}, {$handshake['config_count']} configs");

    $syncDir = $this->getSyncDir();

    $result = $this->transferService->pull($remote, $syncDir, $only, $dryRun);

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
  #[CLI\Option(name: 'only', description: 'Glob pattern to filter config names')]
  public function status(string $remote, array $options = ['only' => '']): void {
    $only = $options['only'] ?: NULL;

    $handshake = $this->client->handshake($remote);
    $this->io()->text("Server: v{$handshake['server_version']}, {$handshake['config_count']} configs, hash_version={$handshake['hash_version']}");

    $syncDir = $this->getSyncDir();
    $localHashes = $this->diffService->computeLocalHashes($syncDir, $only);
    $this->io()->text("Local: " . count($localHashes) . " configs");

    $serverDiff = $this->client->diff($remote, $localHashes);
    if ($serverDiff === NULL) {
      $this->io()->success('In sync. No changes.');
      return;
    }

    $diff = $this->diffService->buildDiffResult($localHashes, $serverDiff);

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

}
