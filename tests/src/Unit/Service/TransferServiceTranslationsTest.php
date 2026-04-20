<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\Service;

use Drupal\config_pull\Service\ConfigDiffService;
use Drupal\config_pull\Service\ConfigHashService;
use Drupal\config_pull\Service\RemoteClient;
use Drupal\config_pull\Service\TransferService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;

#[CoversClass(TransferService::class)]
#[Group('config_pull')]
final class TransferServiceTranslationsTest extends TestCase {

  private RemoteClient $client;

  private ConfigDiffService $diffService;

  private TransferService $service;

  private \org\bovigo\vfs\vfsStreamDirectory $root;

  protected function setUp(): void {
    parent::setUp();
    $this->client = $this->createMock(RemoteClient::class);
    $this->diffService = $this->createMock(ConfigDiffService::class);
    $this->service = new TransferService($this->client, $this->diffService);
    $this->root = vfsStream::setup('config_sync');
  }

  public function testPullWithTranslationsPassesFlagToClient(): void {
    $syncDir = vfsStream::url('config_sync');

    $this->diffService->method('computeLocalHashes')->willReturn(['system.site' => 'hash1']);
    $this->diffService->method('computeLocalCollectionHashes')->willReturn([]);
    $this->diffService->method('buildDiffResult')->willReturn([
      'new' => [], 'changed' => [], 'deleted' => [], 'unchanged_count' => 1,
    ]);
    $this->diffService->method('filterDiffResult')->willReturn([
      'new' => [], 'changed' => [], 'deleted' => [], 'unchanged_count' => 1,
    ]);

    $this->client->expects($this->once())
      ->method('diff')
      ->with('local', ['system.site' => 'hash1'], TRUE, [])
      ->willReturn(NULL);

    $result = $this->service->pull('local', $syncDir, NULL, NULL, FALSE, TRUE);
    $this->assertSame(0, $result['new']);
  }

  public function testPullDownloadsCollectionItems(): void {
    $syncDir = vfsStream::url('config_sync');

    $this->diffService->method('computeLocalHashes')->willReturn([]);
    $this->diffService->method('computeLocalCollectionHashes')->willReturn([]);
    $this->diffService->method('buildDiffResult')->willReturn([
      'new' => [], 'changed' => [], 'deleted' => [], 'unchanged_count' => 0,
    ]);
    $this->diffService->method('filterDiffResult')->willReturn([
      'new' => [], 'changed' => [], 'deleted' => [], 'unchanged_count' => 0,
    ]);

    $this->client->method('diff')->willReturn([
      'new' => [],
      'changed' => [],
      'deleted' => [],
      'unchanged_count' => 0,
      'collections' => [
        'language.es' => [
          'new' => ['system.site' => 'hash_es'],
          'changed' => [],
          'deleted' => [],
        ],
      ],
    ]);

    $this->client->expects($this->once())
      ->method('collectionItem')
      ->with('local', 'language.es', 'system.site')
      ->willReturn(['yaml' => "name: Sitio\n", 'hash' => 'hash_es']);

    $result = $this->service->pull('local', $syncDir, NULL, NULL, FALSE, TRUE);
    $this->assertContains('language.es:system.site', $result['written']);
    $this->assertTrue(file_exists($syncDir . '/language/es/system.site.yml'));
    $this->assertSame("name: Sitio\n", file_get_contents($syncDir . '/language/es/system.site.yml'));
  }

  public function testDryRunWithTranslationsIncludesCollectionCount(): void {
    $syncDir = vfsStream::url('config_sync');

    $this->diffService->method('computeLocalHashes')->willReturn([]);
    $this->diffService->method('computeLocalCollectionHashes')->willReturn([]);
    $this->diffService->method('buildDiffResult')->willReturn([
      'new' => [], 'changed' => [], 'deleted' => [], 'unchanged_count' => 0,
    ]);
    $this->diffService->method('filterDiffResult')->willReturn([
      'new' => [], 'changed' => [], 'deleted' => [], 'unchanged_count' => 0,
    ]);

    $this->client->method('diff')->willReturn([
      'new' => [],
      'changed' => [],
      'deleted' => [],
      'unchanged_count' => 0,
      'collections' => [
        'language.es' => [
          'new' => ['system.site' => 'h1', 'system.date' => 'h2'],
          'changed' => [],
          'deleted' => ['old.item'],
        ],
      ],
    ]);

    $result = $this->service->pull('local', $syncDir, NULL, NULL, TRUE, TRUE);
    $this->assertSame(3, $result['collection_changes']);
  }

}
