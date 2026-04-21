<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\Service;

use Drupal\config_pull\Exception\TransferInterruptedException;
use Drupal\config_pull\Service\ConfigDiffService;
use Drupal\config_pull\Service\ConfigHashService;
use Drupal\config_pull\Service\RemoteClient;
use Drupal\config_pull\Service\TransferService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 *
 */
#[CoversClass(TransferService::class)]
#[Group('config_pull')]
final class TransferServiceTest extends TestCase {

  private RemoteClient $remoteClient;

  private TransferService $service;

  private string $tempDir;

  protected function setUp(): void {
    parent::setUp();
    $this->remoteClient = $this->createMock(RemoteClient::class);
    $hashService = new ConfigHashService();
    $diffService = new ConfigDiffService($hashService);
    $this->service = new TransferService($this->remoteClient, $diffService);
    $this->tempDir = sys_get_temp_dir() . '/config_pull_transfer_test_' . uniqid();
    mkdir($this->tempDir, 0755, TRUE);
  }

  protected function tearDown(): void {
    $files = glob($this->tempDir . '/*');
    if ($files) {
      foreach ($files as $file) {
        unlink($file);
      }
    }
    if (is_dir($this->tempDir)) {
      rmdir($this->tempDir);
    }
    parent::tearDown();
  }

  private function writeYml(string $name, string $content): void {
    file_put_contents($this->tempDir . '/' . $name . '.yml', $content);
  }

  public function testPullRoundTripWritesNewAndChangedFiles(): void {
    $this->writeYml('existing.config', "key: old_value\n");

    $this->remoteClient->method('diff')
      ->willReturn([
        'new' => ['new.config' => 'hash_new'],
        'changed' => ['existing.config' => 'hash_changed'],
        'deleted' => [],
        'unchanged_count' => 0,
      ]);

    $this->remoteClient->method('item')
      ->willReturnCallback(function (string $remote, string $name): array {
        return match ($name) {
          'new.config' => ['yaml' => "key: new_value\n", 'hash' => 'hash_new'],
          'existing.config' => ['yaml' => "key: updated_value\n", 'hash' => 'hash_changed'],
        };
      });

    $result = $this->service->pull('staging', $this->tempDir);

    $this->assertSame(1, $result['new']);
    $this->assertSame(1, $result['changed']);
    $this->assertSame(0, $result['deleted']);
    $this->assertContains('new.config', $result['written']);
    $this->assertContains('existing.config', $result['written']);
    $this->assertSame("key: new_value\n", file_get_contents($this->tempDir . '/new.config.yml'));
    $this->assertSame("key: updated_value\n", file_get_contents($this->tempDir . '/existing.config.yml'));
  }

  public function testPullDeletesRemovedConfigs(): void {
    $this->writeYml('to.delete', "key: value\n");

    $this->remoteClient->method('diff')
      ->willReturn([
        'new' => [],
        'changed' => [],
        'deleted' => ['to.delete'],
        'unchanged_count' => 0,
      ]);

    $result = $this->service->pull('staging', $this->tempDir);

    $this->assertSame(1, $result['deleted']);
    $this->assertContains('to.delete', $result['removed']);
    $this->assertFileDoesNotExist($this->tempDir . '/to.delete.yml');
  }

  public function testPullReturnsZerosOn304(): void {
    $this->remoteClient->method('diff')->willReturn(NULL);

    $result = $this->service->pull('staging', $this->tempDir);

    $this->assertSame(0, $result['new']);
    $this->assertSame(0, $result['changed']);
    $this->assertSame(0, $result['deleted']);
    $this->assertSame([], $result['written']);
  }

  public function testPullDryRunDoesNotWriteFiles(): void {
    $this->remoteClient->method('diff')
      ->willReturn([
        'new' => ['new.config' => 'hash1'],
        'changed' => [],
        'deleted' => ['old.config'],
        'unchanged_count' => 5,
      ]);

    $this->writeYml('old.config', "key: keep\n");

    $result = $this->service->pull('staging', $this->tempDir, NULL, NULL, TRUE);

    $this->assertSame(1, $result['new']);
    $this->assertSame(0, $result['changed']);
    $this->assertSame(1, $result['deleted']);
    $this->assertSame([], $result['written']);
    $this->assertFileDoesNotExist($this->tempDir . '/new.config.yml');
    $this->assertFileExists($this->tempDir . '/old.config.yml');
  }

  public function testPullWithFilterPassesPatternThrough(): void {
    $this->writeYml('system.site', "name: Test\n");
    $this->writeYml('node.settings', "use_admin_theme: true\n");

    $capturedHashes = NULL;
    $this->remoteClient->method('diff')
      ->willReturnCallback(function (string $remote, array $hashes) use (&$capturedHashes) {
        $capturedHashes = $hashes;
        return NULL;
      });

    $this->service->pull('staging', $this->tempDir, 'system.*', NULL);

    $this->assertArrayHasKey('system.site', $capturedHashes);
    $this->assertArrayNotHasKey('node.settings', $capturedHashes);
  }

  public function testPullWithExcludeFilterRemovesMatchingItems(): void {
    $this->writeYml('system.site', "name: Test\n");
    $this->writeYml('node.settings', "use_admin_theme: true\n");

    $capturedHashes = NULL;
    $this->remoteClient->method('diff')
      ->willReturnCallback(function (string $remote, array $hashes) use (&$capturedHashes) {
        $capturedHashes = $hashes;
        return NULL;
      });

    $this->service->pull('staging', $this->tempDir, NULL, 'node.*');

    $this->assertArrayHasKey('system.site', $capturedHashes);
    $this->assertArrayNotHasKey('node.settings', $capturedHashes);
  }

  public function testPullFiltersDiffResultToo(): void {
    $this->writeYml('system.site', "name: Test\n");

    $this->remoteClient->method('diff')
      ->willReturn([
        'new' => ['system.date' => 'h1', 'node.type.page' => 'h2'],
        'changed' => [],
        'deleted' => [],
        'unchanged_count' => 1,
      ]);

    $this->remoteClient->method('item')
      ->willReturn(['yaml' => "key: value\n", 'hash' => 'abc']);

    $result = $this->service->pull('staging', $this->tempDir, 'system.*', NULL, FALSE);

    $this->assertSame(1, $result['new']);
    $this->assertContains('system.date', $result['written']);
    $this->assertNotContains('node.type.page', $result['written']);
  }

  public function testPartialDownloadFailureThrowsWithContext(): void {
    $this->remoteClient->method('diff')
      ->willReturn([
        'new' => ['first.config' => 'h1', 'second.config' => 'h2'],
        'changed' => [],
        'deleted' => [],
        'unchanged_count' => 0,
      ]);

    $callCount = 0;
    $this->remoteClient->method('item')
      ->willReturnCallback(function () use (&$callCount): array {
        $callCount++;
        if ($callCount === 2) {
          throw new \RuntimeException('Connection reset');
        }
        return ['yaml' => "key: value\n", 'hash' => 'abc'];
      });

    try {
      $this->service->pull('staging', $this->tempDir);
      $this->fail('Expected TransferInterruptedException');
    }
    catch (TransferInterruptedException $e) {
      $this->assertStringContainsString('Transfer interrupted after writing 1 of 2 files', $e->getMessage());
      $this->assertSame(['first.config'], $e->written);
      $this->assertSame([], $e->removed);
    }
  }

  public function testDeleteNonexistentFileDoesNotThrow(): void {
    $this->remoteClient->method('diff')
      ->willReturn([
        'new' => [],
        'changed' => [],
        'deleted' => ['ghost.config'],
        'unchanged_count' => 0,
      ]);

    $result = $this->service->pull('staging', $this->tempDir);
    $this->assertSame(1, $result['deleted']);
    $this->assertContains('ghost.config', $result['removed']);
  }

}
