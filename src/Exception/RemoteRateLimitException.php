<?php

declare(strict_types=1);

namespace Drupal\config_pull\Exception;

class RemoteRateLimitException extends \RuntimeException {

  public function __construct(
    string $message = 'Rate limited',
    public readonly int $retryAfter = 0,
    int $code = 429,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, $code, $previous);
  }

}
