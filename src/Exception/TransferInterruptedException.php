<?php

declare(strict_types=1);

namespace Drupal\config_pull\Exception;

/**
 *
 */
class TransferInterruptedException extends \RuntimeException {

  public function __construct(
    string $message,
    public readonly array $written = [],
    public readonly array $removed = [],
    int $code = 0,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, $code, $previous);
  }

}
