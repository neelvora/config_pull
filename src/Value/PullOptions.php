<?php

declare(strict_types=1);

namespace Drupal\config_pull\Value;

/**
 *
 */
final class PullOptions {

  public function __construct(
    public readonly string $remoteName,
    public readonly string $syncDir,
    public readonly ?string $onlyPattern = NULL,
    public readonly ?string $excludePattern = NULL,
    public readonly bool $dryRun = FALSE,
    public readonly bool $withTranslations = FALSE,
  ) {}

}
