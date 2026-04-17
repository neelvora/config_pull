<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;

/**
 * Handles shared-secret authentication for config_pull API requests.
 */
final class AuthenticationService {

  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirableFactory,
    private readonly FloodInterface $flood,
    private readonly TimeInterface $time,
    private readonly StateInterface $state,
  ) {}

}
