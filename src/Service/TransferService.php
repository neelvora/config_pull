<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

class TransferService {

  public function __construct(
    private readonly RemoteClient $client,
    private readonly ConfigDiffService $diffService,
  ) {}

  public function pull(string $remoteName, string $syncDir, ?string $onlyPattern = NULL, bool $dryRun = FALSE): array {
    $localHashes = $this->diffService->computeLocalHashes($syncDir, $onlyPattern);
    $serverDiff = $this->client->diff($remoteName, $localHashes);

    if ($serverDiff === NULL) {
      return ['new' => 0, 'changed' => 0, 'deleted' => 0, 'written' => [], 'removed' => []];
    }

    $diffResult = $this->diffService->buildDiffResult($localHashes, $serverDiff);

    if ($dryRun) {
      return [
        'new' => count($diffResult['new']),
        'changed' => count($diffResult['changed']),
        'deleted' => count($diffResult['deleted']),
        'written' => [],
        'removed' => [],
      ];
    }

    return $this->downloadAndWrite($remoteName, $diffResult, $syncDir);
  }

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
      throw new \RuntimeException(
        "Transfer interrupted after writing " . count($written) . " files: " . $e->getMessage(),
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

  private function writeConfigFile(string $syncDir, string $name, string $yaml): void {
    $path = $syncDir . '/' . $name . '.yml';
    $dir = dirname($path);
    if (!is_dir($dir)) {
      mkdir($dir, 0755, TRUE);
    }
    file_put_contents($path, $yaml);
  }

  private function deleteConfigFile(string $syncDir, string $name): void {
    $path = $syncDir . '/' . $name . '.yml';
    if (file_exists($path)) {
      unlink($path);
    }
  }

}
