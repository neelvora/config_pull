<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

use Drupal\Component\Serialization\Yaml;

/**
 * Computes SHA-256 hashes of configuration data.
 */
final class ConfigHashService {

  /**
   * Hashes a config data array.
   *
   * Encodes the array to YAML first, producing the same byte sequence
   * that Drupal's FileStorage writes to disk.
   *
   * @param array $data
   *   Config data as a PHP array.
   *
   * @return string
   *   Hex-encoded SHA-256 hash.
   */
  public function hash(array $data): string {
    return $this->hashYaml(Yaml::encode($data));
  }

  /**
   * Hashes multiple config items.
   *
   * @param array<string, array> $items
   *   Keyed by config name, values are config data arrays.
   *
   * @return array<string, string>
   *   Keyed by config name, values are hex-encoded SHA-256 hashes.
   */
  public function hashMultiple(array $items): array {
    $hashes = [];
    foreach ($items as $name => $data) {
      $hashes[$name] = $this->hash($data);
    }
    return $hashes;
  }

  /**
   * Hashes a raw YAML string.
   *
   * @param string $yaml
   *   YAML-encoded config string.
   *
   * @return string
   *   Hex-encoded SHA-256 hash.
   */
  public function hashYaml(string $yaml): string {
    return hash('sha256', $yaml);
  }

}
