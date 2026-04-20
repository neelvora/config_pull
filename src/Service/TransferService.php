<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

use Drupal\config_pull\Exception\TransferInterruptedException;
use Drupal\config_pull\Value\PullOptions;

/**
 *
 */
class TransferService {

  public function __construct(
    private readonly RemoteClient $client,
    private readonly ConfigDiffService $diffService,
  ) {}

  /**
   *
   */
  public function pull(string|PullOptions $remoteNameOrOptions, string $syncDir = '', ?string $onlyPattern = NULL, ?string $excludePattern = NULL, bool $dryRun = FALSE, bool $withTranslations = FALSE): array {
    if ($remoteNameOrOptions instanceof PullOptions) {
      $opts = $remoteNameOrOptions;
    }
    else {
      $opts = new PullOptions($remoteNameOrOptions, $syncDir, $onlyPattern, $excludePattern, $dryRun, $withTranslations);
    }

    $localHashes = $this->diffService->computeLocalHashes($opts->syncDir, $opts->onlyPattern, $opts->excludePattern);

    $collectionHashes = [];
    if ($opts->withTranslations) {
      $collectionHashes = $this->diffService->computeLocalCollectionHashes($opts->syncDir);
    }

    $serverDiff = $this->client->diff($opts->remoteName, $localHashes, $opts->withTranslations, $collectionHashes);

    if ($serverDiff === NULL) {
      return ['new' => 0, 'changed' => 0, 'deleted' => 0, 'written' => [], 'removed' => []];
    }

    $diffResult = $this->diffService->buildDiffResult($localHashes, $serverDiff);
    $diffResult = $this->diffService->filterDiffResult($diffResult, $opts->onlyPattern, $opts->excludePattern);

    if ($opts->dryRun) {
      $collectionCount = 0;
      if (!empty($serverDiff['collections'])) {
        foreach ($serverDiff['collections'] as $collectionDiff) {
          $collectionCount += count($collectionDiff['new'] ?? []) + count($collectionDiff['changed'] ?? []) + count($collectionDiff['deleted'] ?? []);
        }
      }
      return [
        'new' => count($diffResult['new']),
        'changed' => count($diffResult['changed']),
        'deleted' => count($diffResult['deleted']),
        'collection_changes' => $collectionCount,
        'written' => [],
        'removed' => [],
      ];
    }

    $result = $this->downloadAndWrite($opts->remoteName, $diffResult, $opts->syncDir);

    if (!empty($serverDiff['collections'])) {
      $this->downloadCollections($opts->remoteName, $serverDiff['collections'], $opts->syncDir, $result);
    }

    return $result;
  }

  /**
   *
   */
  public function downloadAndWrite(string $remoteName, array $diffResult, string $syncDir): array {
    $written = [];
    $removed = [];

    try {
      $toDownload = array_merge(
        array_keys($diffResult['new']),
        array_keys($diffResult['changed']),
      );

      foreach ($toDownload as $name) {
        $item = $this->client->item($remoteName, $name);
        $this->writeConfigFile($syncDir, $name, $item['yaml']);
        $written[] = $name;
      }

      foreach ($diffResult['deleted'] as $name) {
        $this->deleteConfigFile($syncDir, $name);
        $removed[] = $name;
      }
    }
    catch (\Throwable $e) {
      throw new TransferInterruptedException(
        "Transfer interrupted after writing " . count($written) . " of " . count($toDownload) . " files: " . $e->getMessage(),
        $written,
        $removed,
        0,
        $e,
      );
    }

    return [
      'new' => count($diffResult['new']),
      'changed' => count($diffResult['changed']),
      'deleted' => count($diffResult['deleted']),
      'written' => $written,
      'removed' => $removed,
    ];
  }

  /**
   *
   */
  private function writeConfigFile(string $syncDir, string $name, string $yaml): void {
    $path = $syncDir . '/' . $name . '.yml';
    $dir = dirname($path);
    if (!is_dir($dir)) {
      mkdir($dir, 0755, TRUE);
    }
    file_put_contents($path, $yaml);
  }

  /**
   *
   */
  private function deleteConfigFile(string $syncDir, string $name): void {
    $path = $syncDir . '/' . $name . '.yml';
    if (file_exists($path)) {
      unlink($path);
    }
  }

  /**
   *
   */
  private function downloadCollections(string $remoteName, array $collections, string $syncDir, array &$result): void {
    foreach ($collections as $collection => $collectionDiff) {
      $collectionDir = $this->collectionDir($syncDir, $collection);

      $toDownload = array_merge(
        array_keys($collectionDiff['new'] ?? []),
        array_keys($collectionDiff['changed'] ?? []),
      );

      foreach ($toDownload as $name) {
        $item = $this->client->collectionItem($remoteName, $collection, $name);
        $this->writeConfigFile($collectionDir, $name, $item['yaml']);
        $result['written'][] = $collection . ':' . $name;
      }

      foreach ($collectionDiff['deleted'] ?? [] as $name) {
        $this->deleteConfigFile($collectionDir, $name);
        $result['removed'][] = $collection . ':' . $name;
      }
    }
  }

  /**
   *
   */
  private function collectionDir(string $syncDir, string $collection): string {
    return $syncDir . '/' . str_replace('.', '/', $collection);
  }

}
