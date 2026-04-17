<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\Drush\Commands;

use Drupal\config_pull\Drush\Commands\ConfigPullCommands;
use Drupal\config_pull\Service\ConfigDiffService;
use Drupal\config_pull\Service\RemoteClient;
use Drupal\config_pull\Service\TransferService;
use Drupal\Core\Site\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

    $this->commands->pull('local', ['dry-run' => TRUE, 'only' => '']);
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

    $this->commands->status('local');
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
    $this->diffService->method('buildDiffResult')->willReturn([
      'new' => ['new.config' => 'h1'],
      'changed' => ['changed.config' => 'h2'],
      'deleted' => ['gone.config'],
      'unchanged_count' => 47,
    ]);

    $this->commands->status('local');
    $out = $this->output->fetch();
    $this->assertStringContainsString('+ new.config', $out);
    $this->assertStringContainsString('~ changed.config', $out);
    $this->assertStringContainsString('- gone.config', $out);
    $this->assertStringContainsString('3 change(s)', $out);
    $this->assertStringContainsString('47 unchanged', $out);
  }

  public function testPullPassesOnlyFilterToTransferService(): void {
    $this->client->method('handshake')->willReturn([
      'server_version' => '1.0.0',
      'config_count' => 10,
    ]);

    $this->transferService->expects($this->once())
      ->method('pull')
      ->with('local', $this->anything(), 'system.*', FALSE)
      ->willReturn([
        'new' => 0, 'changed' => 0, 'deleted' => 0,
        'written' => [], 'removed' => [],
      ]);

    $this->commands->pull('local', ['dry-run' => FALSE, 'only' => 'system.*']);
  }

}
