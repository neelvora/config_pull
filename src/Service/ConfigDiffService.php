<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

use Drupal\Component\Serialization\Yaml;
use Symfony\Component\Finder\Finder;

class ConfigDiffService {

  public function __construct(
    private readonly ConfigHashService $hashService,
  ) {}

  public function computeLocalHashes(string $syncDir, ?string $onlyPattern = NULL): array {
    if (!is_dir($syncDir)) {
      throw new \InvalidArgumentException("Sync directory does not exist: $syncDir");
    }

    $hashes = [];
    $finder = new Finder();
    $finder->files()->in($syncDir)->name('*.yml')->depth(0);

    foreach ($finder as $file) {
      $name = $file->getBasename('.yml');
      if ($onlyPattern !== NULL && !fnmatch($onlyPattern, $name)) {
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

}
