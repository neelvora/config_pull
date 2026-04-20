<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\Service;

use Drupal\config_pull\Service\ConfigExportService;
use Drupal\config_pull\Service\ConfigHashCacheService;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigHashCacheService::class)]
#[Group('config_pull')]
final class ConfigHashCacheServiceTest extends TestCase {

  private CacheBackendInterface $cache;
  private ConfigExportService $exportService;
  private StateInterface $state;
  private LockBackendInterface $lock;
  private ConfigHashCacheService $service;

  protected function setUp(): void {
    parent::setUp();
    $this->cache = $this->createMock(CacheBackendInterface::class);
    $this->exportService = $this->createMock(ConfigExportService::class);
    $this->state = $this->createMock(StateInterface::class);
    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->service = new ConfigHashCacheService(
      $this->cache,
      $this->exportService,
      $this->state,
      $this->lock,
    );
  }

  public function testCacheHitReturnsCachedHashes(): void {
    $hashes = ['system.site' => 'abc123'];
    $cacheItem = new \stdClass();
    $cacheItem->data = ['hashes' => $hashes, 'version' => 1];

    $this->cache->method('get')
      ->with('config_pull:hashes')
      ->willReturn($cacheItem);

    $this->exportService->expects($this->never())->method('computeAllHashes');

    $this->assertSame($hashes, $this->service->getHashes());
  }

  public function testCacheMissRebuilds(): void {
    $hashes = ['system.site' => 'abc123', 'system.date' => 'def456'];

    $this->cache->method('get')->willReturn(FALSE);
    $this->lock->method('acquire')->willReturn(TRUE);
    $this->exportService->method('computeAllHashes')->willReturn($hashes);
    $this->state->method('get')->willReturn(5);

    $this->cache->expects($this->once())
      ->method('set')
      ->with(
        'config_pull:hashes',
        ['hashes' => $hashes, 'version' => 5],
        CacheBackendInterface::CACHE_PERMANENT,
        ['config_pull_hashes'],
      );

    $this->lock->expects($this->once())->method('release');

    $this->assertSame($hashes, $this->service->getHashes());
  }

  public function testStampedeWaitsAndReadsCacheAfterRebuild(): void {
    $hashes = ['system.site' => 'abc123'];
    $cacheItem = new \stdClass();
    $cacheItem->data = ['hashes' => $hashes, 'version' => 1];

    // First get: cache miss. Second get after wait: cache hit.
    $this->cache->method('get')
      ->willReturnOnConsecutiveCalls(FALSE, $cacheItem);

    $this->lock->method('acquire')->willReturn(FALSE);
    $this->lock->expects($this->once())->method('wait');

    $this->exportService->expects($this->never())->method('computeAllHashes');

    $this->assertSame($hashes, $this->service->getHashes());
  }

  public function testStampedeFallsBackOnLockTimeout(): void {
    $hashes = ['system.site' => 'abc123'];

    // Both cache reads return miss. Lock not acquired (another holder crashed).
    $this->cache->method('get')->willReturn(FALSE);
    $this->lock->method('acquire')->willReturn(FALSE);
    $this->lock->expects($this->once())->method('wait');

    // Falls back to direct computation without caching.
    $this->exportService->method('computeAllHashes')->willReturn($hashes);

    $this->assertSame($hashes, $this->service->getHashes());
  }

  public function testGetHashVersionReturnsStateValue(): void {
    $this->state->method('get')
      ->with('config_pull.hash_version')
      ->willReturn(42);

    $this->assertSame(42, $this->service->getHashVersion());
  }

  public function testGetHashVersionDefaultsToZero(): void {
    $this->state->method('get')->willReturn(NULL);
    $this->assertSame(0, $this->service->getHashVersion());
  }

  public function testInvalidate(): void {
    $this->cache->expects($this->once())
      ->method('invalidate')
      ->with('config_pull:hashes');

    $this->service->invalidate();
  }

  public function testRebuildReleasesLockOnException(): void {
    $this->cache->method('get')->willReturn(FALSE);
    $this->lock->method('acquire')->willReturn(TRUE);
    $this->exportService->method('computeAllHashes')
      ->willThrowException(new \RuntimeException('Storage error'));

    $this->lock->expects($this->once())->method('release');

    $this->expectException(\RuntimeException::class);
    $this->service->getHashes();
  }

}
