<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

use Drupal\Component\Serialization\Yaml;
use Symfony\Component\Finder\Finder;

/**
 *
 */
class ConfigDiffService {

  public function __construct(
    private readonly ConfigHashService $hashService,
  ) {}

  public function computeLocalHashes(string $syncDir, ?string $onlyPattern = NULL, ?string $excludePattern = NULL): array {
    if (!is_dir($syncDir)) {
      throw new \InvalidArgumentException("Sync directory does not exist: $syncDir");
    }

    $hashes = [];
    $finder = new Finder();
    $finder->files()->in($syncDir)->name('*.yml')->depth(0);

    foreach ($finder as $file) {
      $name = $file->getBasename('.yml');
      if (!$this->matchesFilter($name, $onlyPattern, $excludePattern)) {
        continue;
      }
      $data = Yaml::decode(file_get_contents($file->getPathname()));
      if (!is_array($data)) {
        continue;
      }
      $hashes[$name] = $this->hashService->hash($data);
    }

    ksort($hashes);
    return $hashes;
  }

  public function buildDiffResult(array $localHashes, array $serverResponse): array {
    return [
      'new' => $serverResponse['new'] ?? [],
      'changed' => $serverResponse['changed'] ?? [],
      'deleted' => $serverResponse['deleted'] ?? [],
      'unchanged_count' => $serverResponse['unchanged_count'] ?? 0,
    ];
  }

  public function filterDiffResult(array $diffResult, ?string $onlyPattern = NULL, ?string $excludePattern = NULL): array {
    if ($onlyPattern === NULL && $excludePattern === NULL) {
      return $diffResult;
    }

    $filterKeys = function (array $items) use ($onlyPattern, $excludePattern): array {
      return array_filter($items, fn(string $name) => $this->matchesFilter($name, $onlyPattern, $excludePattern), ARRAY_FILTER_USE_KEY);
    };

    $filterValues = function (array $items) use ($onlyPattern, $excludePattern): array {
      return array_values(array_filter($items, fn(string $name) => $this->matchesFilter($name, $onlyPattern, $excludePattern)));
    };

    return [
      'new' => $filterKeys($diffResult['new'] ?? []),
      'changed' => $filterKeys($diffResult['changed'] ?? []),
      'deleted' => $filterValues($diffResult['deleted'] ?? []),
      'unchanged_count' => $diffResult['unchanged_count'] ?? 0,
    ];
  }

  private function matchesFilter(string $name, ?string $onlyPattern, ?string $excludePattern): bool {
    if ($onlyPattern !== NULL && !fnmatch($onlyPattern, $name)) {
      return FALSE;
    }
    if ($excludePattern !== NULL && fnmatch($excludePattern, $name)) {
      return FALSE;
    }
    return TRUE;
  }

  public function computeLocalCollectionHashes(string $syncDir): array {
    $collectionHashes = [];
    $languageDir = $syncDir . '/language';
    if (!is_dir($languageDir)) {
      return [];
    }
    $langDirs = new \DirectoryIterator($languageDir);
    foreach ($langDirs as $langDir) {
      if ($langDir->isDot() || !$langDir->isDir()) {
        continue;
      }
      $collection = 'language.' . $langDir->getFilename();
      $hashes = [];
      $finder = new Finder();
      $finder->files()->in($langDir->getPathname())->name('*.yml')->depth(0);
      foreach ($finder as $file) {
        $name = $file->getBasename('.yml');
        $data = Yaml::decode(file_get_contents($file->getPathname()));
        if (is_array($data)) {
          $hashes[$name] = $this->hashService->hash($data);
        }
      }
      if (!empty($hashes)) {
        ksort($hashes);
        $collectionHashes[$collection] = $hashes;
      }
    }
    return $collectionHashes;
  }

}
