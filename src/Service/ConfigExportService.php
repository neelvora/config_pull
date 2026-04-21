<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\StorageInterface;

/**
 * Reads config from the export storage, applies redaction, builds archives.
 */
class ConfigExportService {

  public function __construct(
    private readonly StorageInterface $exportStorage,
    private readonly ConfigHashService $hashService,
    private readonly RedactionService $redactionService,
  ) {}

  /**
   * Number of config items in the export storage.
   */
  public function getConfigCount(): int {
    return count($this->exportStorage->listAll());
  }

  /**
   * All config names in the export storage, excluding fully redacted items.
   *
   * @return string[]
   */
  public function listConfigNames(): array {
    $names = $this->exportStorage->listAll();
    return array_values(array_filter(
      $names,
      fn(string $name): bool => !$this->redactionService->shouldRedactEntirely($name),
    ));
  }

  /**
   * Computes hashes for all exportable config items (redacted).
   *
   * @return array<string, string>
   *   Keyed by config name, values are hex SHA-256 hashes of redacted YAML.
   */
  public function computeAllHashes(): array {
    $names = $this->listConfigNames();
    $items = $this->exportStorage->readMultiple($names);
    $redacted = [];
    foreach ($items as $name => $data) {
      $redacted[$name] = $this->redactionService->redact($name, $data);
    }
    return $this->hashService->hashMultiple($redacted);
  }

  /**
   * Returns a single redacted config item with its hash.
   *
   * @return array|null
   *   Array with 'data' and 'hash' keys, or NULL if the item does not exist
   *   or is fully redacted.
   */
  public function getItem(string $name): ?array {
    if ($this->redactionService->shouldRedactEntirely($name)) {
      return NULL;
    }
    $data = $this->exportStorage->read($name);
    if ($data === FALSE) {
      return NULL;
    }
    $redacted = $this->redactionService->redact($name, $data);
    return [
      'data' => $redacted,
      'hash' => $this->hashService->hash($redacted),
    ];
  }

  /**
   * Returns multiple redacted config items.
   *
   * @param string[] $names
   *   Config names to retrieve.
   *
   * @return array<string, array>
   *   Keyed by config name. Fully redacted and missing items are excluded.
   */
  public function getItems(array $names): array {
    $filtered = array_filter(
      $names,
      fn(string $name): bool => !$this->redactionService->shouldRedactEntirely($name),
    );
    $items = $this->exportStorage->readMultiple($filtered);
    $result = [];
    foreach ($items as $name => $data) {
      $result[$name] = $this->redactionService->redact($name, $data);
    }
    return $result;
  }

  /**
   * Returns all exportable config items, redacted.
   *
   * @return array<string, array>
   */
  public function getAllItems(): array {
    return $this->getItems($this->listConfigNames());
  }

  /**
   * Builds a tar.gz archive of config items and returns the temp file path.
   *
   * @param array<string, array> $items
   *   Keyed by config name, values are config data arrays.
   *
   * @return string
   *   Absolute path to the temporary .tar.gz file. Caller is responsible
   *   for cleanup (BinaryFileResponse::deleteFileAfterSend handles this).
   */
  public function buildTarGz(array $items): string {
    $path = sys_get_temp_dir() . '/config_pull_' . bin2hex(random_bytes(8)) . '.tar.gz';
    $tar = new \Archive_Tar($path, 'gz');

    foreach ($items as $name => $data) {
      $yaml = Yaml::encode($data);
      $tar->addString($name . '.yml', $yaml, FALSE, ['stamp' => 0]);
    }

    return $path;
  }

  public function listCollections(): array {
    return $this->exportStorage->getAllCollectionNames();
  }

  public function getCollectionHashes(string $collection): array {
    $storage = $this->exportStorage->createCollection($collection);
    $names = $storage->listAll();
    $items = $storage->readMultiple($names);
    return $this->hashService->hashMultiple($items);
  }

  public function getCollectionItem(string $collection, string $name): ?array {
    $storage = $this->exportStorage->createCollection($collection);
    $data = $storage->read($name);
    if ($data === FALSE) {
      return NULL;
    }
    return [
      'data' => $data,
      'hash' => $this->hashService->hash($data),
    ];
  }

  public function getCollectionItems(string $collection, array $names): array {
    $storage = $this->exportStorage->createCollection($collection);
    return $storage->readMultiple($names);
  }

}
