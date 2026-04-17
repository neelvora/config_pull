<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;

/**
 * Caches config hashes in cache.default with stampede prevention.
 *
 * The hash cache is invalidated by ConfigChangeSubscriber when config changes.
 * A monotonic hash_version counter in State tracks generations.
 */
final class ConfigHashCacheService {

  private const CACHE_CID = 'config_pull:hashes';

  private const CACHE_TAG = 'config_pull_hashes';

  private const LOCK_NAME = 'config_pull_hash_rebuild';

  private const STATE_VERSION_KEY = 'config_pull.hash_version';

  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly ConfigExportService $exportService,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Returns cached config hashes, rebuilding from export storage on miss.
   *
   * Uses lock-based stampede prevention: only one request rebuilds the cache.
   * Others wait for the rebuild to finish and read the fresh cache.
   *
   * @return array<string, string>
   *   Keyed by config name, values are hex SHA-256 hashes.
   */
  public function getHashes(): array {
    $cached = $this->cache->get(self::CACHE_CID);
    if ($cached !== FALSE) {
      return $cached->data['hashes'];
    }

    return $this->rebuild();
  }

  /**
   * Returns the current hash version counter.
   *
   * Incremented by ConfigChangeSubscriber on every config change.
   */
  public function getHashVersion(): int {
    return (int) ($this->state->get(self::STATE_VERSION_KEY) ?? 0);
  }

  /**
   * Forces a cache rebuild on the next getHashes() call.
   */
  public function invalidate(): void {
    $this->cache->invalidate(self::CACHE_CID);
  }

  /**
   * Rebuilds the hash cache with stampede prevention.
   *
   * @return array<string, string>
   */
  private function rebuild(): array {
    if (!$this->lock->acquire(self::LOCK_NAME, 30)) {
      $this->lock->wait(self::LOCK_NAME, 10);
      $cached = $this->cache->get(self::CACHE_CID);
      if ($cached !== FALSE) {
        return $cached->data['hashes'];
      }
      // Another request held the lock but didn't finish (crash, timeout).
      // Fall through to compute directly without caching (avoids deadlock).
      return $this->exportService->computeAllHashes();
    }

    try {
      $hashes = $this->exportService->computeAllHashes();
      $version = $this->getHashVersion();
      $this->cache->set(
        self::CACHE_CID,
        ['hashes' => $hashes, 'version' => $version],
        CacheBackendInterface::CACHE_PERMANENT,
        [self::CACHE_TAG],
      );
      return $hashes;
    }
    finally {
      $this->lock->release(self::LOCK_NAME);
    }
  }

}
