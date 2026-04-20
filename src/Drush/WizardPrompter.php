<?php

declare(strict_types=1);

namespace Drupal\config_pull\Drush;

interface WizardPrompter {

  public function ask(string $question, ?string $default = NULL): ?string;

  public function confirm(string $question, bool $default = TRUE): bool;

}
