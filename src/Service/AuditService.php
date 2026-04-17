<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Logs config_pull API access events.
 */
final class AuditService {

  public function __construct(
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

}
