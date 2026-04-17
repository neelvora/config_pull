<?php

declare(strict_types=1);

namespace Drupal\config_pull\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds security headers to config_pull API responses.
 */
final class ResponseSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [];
  }

}
