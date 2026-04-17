<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

use Drupal\Core\Site\Settings;

/**
 * Strips sensitive values from config data before transmission.
 *
 * Reads redaction rules from $settings['config_pull']['redact']. Rules are
 * keyed by config name (fnmatch wildcards supported). Values are either an
 * array of keys to redact or TRUE to redact the entire item.
 */
final class RedactionService {

  /**
   * Sentinel value replacing redacted config values.
   */
  public const REDACTED = 'CONFIG_PULL_REDACTED';

  /**
   * Returns a redacted copy of config data.
   *
   * @param string $configName
   *   The config name (e.g. "smtp.settings").
   * @param array $data
   *   Config data array.
   *
   * @return array
   *   Config data with sensitive values replaced by the redaction sentinel.
   */
  public function redact(string $configName, array $data): array {
    $rules = $this->getRules();
    $keysToRedact = [];

    foreach ($rules as $pattern => $keys) {
      if ($keys === TRUE) {
        continue;
      }
      if (!fnmatch($pattern, $configName)) {
        continue;
      }
      $keysToRedact = array_merge($keysToRedact, (array) $keys);
    }

    if ($keysToRedact === []) {
      return $data;
    }

    return $this->redactKeys($data, $keysToRedact);
  }

  /**
   * Whether an entire config item should be excluded from export.
   *
   * @param string $configName
   *   The config name.
   *
   * @return bool
   *   TRUE if the entire item should be omitted.
   */
  public function shouldRedactEntirely(string $configName): bool {
    $rules = $this->getRules();

    foreach ($rules as $pattern => $keys) {
      if ($keys !== TRUE) {
        continue;
      }
      if (fnmatch($pattern, $configName)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Loads redaction rules from settings.php.
   *
   * @return array
   *   Keyed by config name pattern. Values are arrays of key patterns or TRUE.
   */
  private function getRules(): array {
    $settings = Settings::get('config_pull', []);
    return $settings['redact'] ?? [];
  }

  /**
   * Replaces matching keys in a data array with the redaction sentinel.
   *
   * @param array $data
   *   Config data.
   * @param array $keyPatterns
   *   Key patterns to redact (fnmatch wildcards supported).
   *
   * @return array
   *   Data with matched keys replaced.
   */
  private function redactKeys(array $data, array $keyPatterns): array {
    $result = [];
    foreach ($data as $key => $value) {
      if ($this->keyMatchesAny((string) $key, $keyPatterns)) {
        $result[$key] = self::REDACTED;
      }
      elseif (is_array($value)) {
        $result[$key] = $this->redactKeys($value, $keyPatterns);
      }
      else {
        $result[$key] = $value;
      }
    }
    return $result;
  }

  /**
   * Tests if a key matches any of the given patterns.
   */
  private function keyMatchesAny(string $key, array $patterns): bool {
    foreach ($patterns as $pattern) {
      if (fnmatch($pattern, $key)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
