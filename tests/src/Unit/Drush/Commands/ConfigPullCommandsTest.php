<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\Drush\Commands;

use Drupal\config_pull\Drush\WizardPrompterInterface;
use Drupal\config_pull\Drush\Commands\ConfigPullCommands;
use Drupal\config_pull\Service\ConfigDiffService;
use Drupal\config_pull\Service\RemoteClient;
use Drupal\config_pull\Service\TransferService;
use Drupal\Core\Site\Settings;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 *
 */
#[CoversClass(ConfigPullCommands::class)]
#[Group('config_pull')]
final class ConfigPullCommandsTest extends TestCase {

  private RemoteClient $client;

  private ConfigDiffService $diffService;

  private TransferService $transferService;

  private ConfigPullCommands $commands;

  private BufferedOutput $output;

  protected function setUp(): void {
    parent::setUp();
    new Settings(['config_sync_directory' => '/tmp/config_sync_test']);
    $this->client = $this->createMock(RemoteClient::class);
    $this->diffService = $this->createMock(ConfigDiffService::class);
    $this->transferService = $this->createMock(TransferService::class);
    $this->commands = new ConfigPullCommands(
    $this->client,
    $this->diffService,
    $this->transferService,
    );
    $this->output = new BufferedOutput();
    $input = new ArrayInput([]);
    $input->setInteractive(FALSE);
    $this->commands->restoreState($input, $this->output);
  }

  public function testPullShowsUpToDateWhenNoChanges(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 10,
    ]);
    $this->transferService->method('pull')->willReturn([
      'new' => 0, 'changed' => 0, 'deleted' => 0,
      'written' => [], 'removed' => [],
    ]);

    $this->commands->pull('local');
    $out = $this->output->fetch();
    $this->assertStringContainsString('up to date', $out);
  }

  public function testPullDryRunReportsChangesWithoutWriting(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 10,
    ]);
    $this->transferService->method('pull')->willReturn([
      'new' => 3, 'changed' => 2, 'deleted' => 1,
      'written' => [], 'removed' => [],
    ]);

    $this->commands->pull('local', [
      'dry-run' => TRUE,
      'only' => '',
      'exclude' => '',
      'format' => 'table',
      'with-translations' => FALSE,
    ]);
    $out = $this->output->fetch();
    $this->assertStringContainsString('Dry run', $out);
    $this->assertStringContainsString('3 new', $out);
    $this->assertStringContainsString('2 changed', $out);
    $this->assertStringContainsString('1 deleted', $out);
  }

  public function testPullReportsWrittenAndDeleted(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 10,
    ]);
    $this->transferService->method('pull')->willReturn([
      'new' => 1, 'changed' => 1, 'deleted' => 1,
      'written' => ['new.config', 'changed.config'],
      'removed' => ['old.config'],
    ]);

    $this->commands->pull('local');
    $out = $this->output->fetch();
    $this->assertStringContainsString('wrote new.config', $out);
    $this->assertStringContainsString('wrote changed.config', $out);
    $this->assertStringContainsString('deleted old.config', $out);
    $this->assertStringContainsString('1 new, 1 changed, 1 deleted', $out);
  }

  public function testStatusShowsInSyncWhen304(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 10,
      'hash_version' => 5,
    ]);
    $this->diffService->method('computeLocalHashes')->willReturn(['a' => 'hash']);
    $this->client->method('diff')->willReturn(NULL);

    $this->commands->status('local', ['only' => '', 'exclude' => '', 'format' => 'table']);
    $out = $this->output->fetch();
    $this->assertStringContainsString('In sync', $out);
  }

  public function testStatusListsChanges(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 50,
      'hash_version' => 3,
    ]);
    $this->diffService->method('computeLocalHashes')->willReturn([]);
    $this->client->method('diff')->willReturn([
      'new' => ['new.config' => 'h1'],
      'changed' => ['changed.config' => 'h2'],
      'deleted' => ['gone.config'],
      'unchanged_count' => 47,
    ]);
    $diffResult = [
      'new' => ['new.config' => 'h1'],
      'changed' => ['changed.config' => 'h2'],
      'deleted' => ['gone.config'],
      'unchanged_count' => 47,
    ];
    $this->diffService->method('buildDiffResult')->willReturn($diffResult);
    $this->diffService->method('filterDiffResult')->willReturn($diffResult);

    $this->commands->status('local');
    $out = $this->output->fetch();
    $this->assertStringContainsString('+ new.config', $out);
    $this->assertStringContainsString('~ changed.config', $out);
    $this->assertStringContainsString('- gone.config', $out);
    $this->assertStringContainsString('3 change(s)', $out);
    $this->assertStringContainsString('47 unchanged', $out);
  }

  public function testStatusJsonOutputUsesPlainNameArrays(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 50,
      'hash_version' => 3,
    ]);
    $this->diffService->method('computeLocalHashes')->willReturn([]);
    $this->client->method('diff')->willReturn([
      'new' => ['new.config' => 'h1'],
      'changed' => ['changed.config' => 'h2'],
      'deleted' => ['gone.config'],
      'unchanged_count' => 47,
    ]);
    $diffResult = [
      'new' => ['new.config' => 'h1'],
      'changed' => ['changed.config' => 'h2'],
      'deleted' => ['gone.config'],
      'unchanged_count' => 47,
    ];
    $this->diffService->method('buildDiffResult')->willReturn($diffResult);
    $this->diffService->method('filterDiffResult')->willReturn($diffResult);

    $this->commands->status('local', ['only' => '', 'exclude' => '', 'format' => 'json']);
    $out = $this->output->fetch();
    $jsonStart = strpos($out, '{');
    $decoded = json_decode(substr($out, $jsonStart), TRUE);
    $this->assertSame(['new.config'], $decoded['new']);
    $this->assertSame(['changed.config'], $decoded['changed']);
    $this->assertSame(['gone.config'], $decoded['deleted']);
    $this->assertSame(47, $decoded['unchanged_count']);
  }

  public function testPullPassesOnlyFilterToTransferService(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 10,
    ]);

    $this->transferService->expects($this->once())
      ->method('pull')
      ->with('local', $this->anything(), 'system.*', NULL, FALSE)
      ->willReturn([
        'new' => 0, 'changed' => 0, 'deleted' => 0,
        'written' => [], 'removed' => [],
      ]);

    $this->commands->pull('local', [
      'dry-run' => FALSE,
      'only' => 'system.*',
      'exclude' => '',
      'format' => 'table',
      'with-translations' => FALSE,
    ]);
  }

  public function testDiffShowsNoDifferencesWhenInSync(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 10,
      'hash_version' => 5,
    ]);
    $this->diffService->method('computeLocalHashes')->willReturn(['a' => 'hash']);
    $this->client->method('diff')->willReturn(NULL);

    $this->commands->diff('local', ['only' => '', 'exclude' => '', 'show-values' => FALSE, 'format' => 'table']);
    $out = $this->output->fetch();
    $this->assertStringContainsString('No differences', $out);
  }

  public function testDiffListsChanges(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 50,
      'hash_version' => 5,
    ]);
    $this->diffService->method('computeLocalHashes')->willReturn([]);
    $this->client->method('diff')->willReturn([
      'new' => ['new.config' => 'h1'],
      'changed' => ['changed.config' => 'h2'],
      'deleted' => ['gone.config'],
      'unchanged_count' => 47,
    ]);
    $diffResult = [
      'new' => ['new.config' => 'h1'],
      'changed' => ['changed.config' => 'h2'],
      'deleted' => ['gone.config'],
      'unchanged_count' => 47,
    ];
    $this->diffService->method('buildDiffResult')->willReturn($diffResult);
    $this->diffService->method('filterDiffResult')->willReturn($diffResult);

    $this->commands->diff('local', ['only' => '', 'exclude' => '', 'show-values' => FALSE, 'format' => 'table']);
    $out = $this->output->fetch();
    $this->assertStringContainsString('+ new.config', $out);
    $this->assertStringContainsString('~ changed.config', $out);
    $this->assertStringContainsString('- gone.config', $out);
    $this->assertStringContainsString('3 difference(s)', $out);
  }

  public function testDiffJsonOutputIncludesStructuredData(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 5,
      'hash_version' => 5,
    ]);
    $this->diffService->method('computeLocalHashes')->willReturn([]);
    $this->client->method('diff')->willReturn([
      'new' => ['a.config' => 'h1'],
      'changed' => [],
      'deleted' => [],
      'unchanged_count' => 4,
    ]);
    $diffResult = [
      'new' => ['a.config' => 'h1'],
      'changed' => [],
      'deleted' => [],
      'unchanged_count' => 4,
    ];
    $this->diffService->method('buildDiffResult')->willReturn($diffResult);
    $this->diffService->method('filterDiffResult')->willReturn($diffResult);

    $this->commands->diff('local', ['only' => '', 'exclude' => '', 'show-values' => FALSE, 'format' => 'json']);
    $out = $this->output->fetch();
    $jsonStart = strpos($out, '{');
    $decoded = json_decode(substr($out, $jsonStart), TRUE);
    $this->assertSame(['a.config'], $decoded['new']);
    $this->assertSame([], $decoded['changed']);
    $this->assertSame([], $decoded['deleted']);
    $this->assertArrayHasKey('diffs', $decoded);
  }

  public function testSetSiteAliasManagerForwardsToClient(): void {
    $aliasManager = $this->createMock(SiteAliasManagerInterface::class);
    $client = $this->createMock(RemoteClient::class);
    $client->expects($this->once())
      ->method('setAliasManager')
      ->with($this->identicalTo($aliasManager));

    $commands = new ConfigPullCommands(
    $client,
    $this->createMock(ConfigDiffService::class),
    $this->createMock(TransferService::class),
    );
    $commands->setSiteAliasManager($aliasManager);
    $this->assertTrue($commands->hasSiteAliasManager());
  }

  public function testPullWarnsWhenServerLacksTranslationSupport(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 10,
      'supports_translations' => FALSE,
    ]);
    $this->transferService->method('pull')->willReturn([
      'new' => 0, 'changed' => 0, 'deleted' => 0,
      'written' => [], 'removed' => [],
    ]);

    $this->commands->pull('local', [
      'dry-run' => FALSE,
      'only' => '',
      'exclude' => '',
      'format' => 'table',
      'with-translations' => TRUE,
    ]);
    $out = $this->output->fetch();
    $this->assertStringContainsString('does not support translations', $out);
  }

  public function testHandshakeCapabilityFlags(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 10,
      'supports_translations' => TRUE,
      'supports_hash_cache' => TRUE,
    ]);
    $this->transferService->method('pull')->willReturn([
      'new' => 0, 'changed' => 0, 'deleted' => 0,
      'written' => [], 'removed' => [],
    ]);

    $this->commands->pull('local', [
      'dry-run' => FALSE,
      'only' => '',
      'exclude' => '',
      'format' => 'table',
      'with-translations' => TRUE,
    ]);
    $out = $this->output->fetch();
    $this->assertStringNotContainsString('does not support translations', $out);
  }

  public function testDiffShowValuesBatchFetchesViaExport(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 5,
      'hash_version' => 1,
    ]);
    $this->diffService->method('computeLocalHashes')->willReturn([]);
    $this->client->method('diff')->willReturn([
      'new' => [],
      'changed' => ['system.site' => 'h1', 'system.date' => 'h2'],
      'deleted' => [],
      'unchanged_count' => 3,
    ]);
    $diffResult = [
      'new' => [],
      'changed' => ['system.site' => 'h1', 'system.date' => 'h2'],
      'deleted' => [],
      'unchanged_count' => 3,
    ];
    $this->diffService->method('buildDiffResult')->willReturn($diffResult);
    $this->diffService->method('filterDiffResult')->willReturn($diffResult);

    $this->client->expects($this->once())
      ->method('export')
      ->with('local', ['system.site', 'system.date']);

    $this->client->expects($this->never())
      ->method('item');

    $this->commands->diff('local', ['only' => '', 'exclude' => '', 'show-values' => TRUE, 'format' => 'table']);
  }

  public function testSetupSnippetsContainRemoteConfig(): void {
    $this->commands->printSetupSnippets('prod', 'https://example.com', 'test-secret-value');
    $out = $this->output->fetch();

    $this->assertStringContainsString('server_enabled', $out);
    $this->assertStringContainsString("'secret' => 'test-secret-value'", $out);
    $this->assertStringContainsString('config_pull_remotes', $out);
    $this->assertStringContainsString("'uri' => 'https://example.com'", $out);
    $this->assertStringContainsString("'prod'", $out);
  }

  public function testSetupSnippetsContainCustomRemoteName(): void {
    $this->commands->printSetupSnippets('staging', 'https://staging.example.com', 'staging-secret');
    $out = $this->output->fetch();

    $this->assertStringContainsString("'staging'", $out);
    $this->assertStringContainsString("'uri' => 'https://staging.example.com'", $out);
    $this->assertStringContainsString("'secret' => 'staging-secret'", $out);
  }

  public function testStripControlBytesRemovesAnsi(): void {
    $reflection = new \ReflectionMethod($this->commands, 'stripControlBytes');
    $result = $reflection->invoke($this->commands, "hello\x1B[31mred\x1B[0m world\x00\x07");
    $this->assertSame('hellored world', $result);
  }

  public function testStripControlBytesPreservesNormalText(): void {
    $reflection = new \ReflectionMethod($this->commands, 'stripControlBytes');
    $result = $reflection->invoke($this->commands, "normal text with\nnewlines\tand tabs");
    $this->assertSame("normal text with\nnewlines\tand tabs", $result);
  }

  public function testWizardGeneratesSnippetsWithProvidedSecret(): void {
    $this->commands->setPrompter(new TestWizardPrompter([
      'asks' => ['staging', 'https://staging.example.com', 'manual-secret-value'],
      'confirms' => [FALSE, FALSE],
    ]));

    $this->commands->setup();
    $out = $this->output->fetch();

    $this->assertStringContainsString("'staging'", $out);
    $this->assertStringContainsString("'uri' => 'https://staging.example.com'", $out);
    $this->assertStringContainsString("'secret' => 'manual-secret-value'", $out);
  }

  public function testWizardGeneratesRandomSecretWhenRequested(): void {
    $this->commands->setPrompter(new TestWizardPrompter([
      'asks' => ['prod', 'https://example.com'],
      'confirms' => [TRUE, FALSE],
    ]));

    $this->commands->setup();
    $out = $this->output->fetch();

    $this->assertStringContainsString('Generated secret:', $out);
    $this->assertStringContainsString("'prod'", $out);
    $this->assertStringContainsString("'uri' => 'https://example.com'", $out);
  }

  public function testWizardAbortsWhenUriEmpty(): void {
    $this->commands->setPrompter(new TestWizardPrompter([
      'asks' => ['prod', ''],
      'confirms' => [],
    ]));

    $this->commands->setup();
    $out = $this->output->fetch();

    $this->assertStringContainsString('URI is required', $out);
    $this->assertStringNotContainsString('Generated secret', $out);
  }

  public function testWizardAbortsWhenManualSecretEmpty(): void {
    $this->commands->setPrompter(new TestWizardPrompter([
      'asks' => ['prod', 'https://example.com', ''],
      'confirms' => [FALSE],
    ]));

    $this->commands->setup();
    $out = $this->output->fetch();

    $this->assertStringContainsString('Secret is required', $out);
  }

  public function testWizardRunsHandshakeWhenConfirmed(): void {
    $this->client->expects($this->once())
      ->method('handshake')
      ->with('prod')
      ->willReturn(['server_version' => '1.0.0', 'config_count' => 42]);

    $this->commands->setPrompter(new TestWizardPrompter([
      'asks' => ['prod', 'https://example.com'],
      'confirms' => [TRUE, TRUE],
    ]));

    $this->commands->setup();
    $out = $this->output->fetch();

    $this->assertStringContainsString('Connected. Server v1.0.0, 42 configs', $out);
  }

}

/**
 *
 */
final class TestWizardPrompter implements WizardPrompterInterface {

  private array $asks;
  private array $confirms;

  public function __construct(array $answers) {
    $this->asks = $answers['asks'] ?? [];
    $this->confirms = $answers['confirms'] ?? [];
  }

  public function ask(string $question, ?string $default = NULL): ?string {
    if (empty($this->asks)) {
      return $default;
    }
    return array_shift($this->asks);
  }

  public function confirm(string $question, bool $default = TRUE): bool {
    if (empty($this->confirms)) {
      return $default;
    }
    return (bool) array_shift($this->confirms);
  }

}
