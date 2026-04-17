<?php

declare(strict_types=1);

namespace Drupal\config_pull\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds security and cache-prevention headers to config_pull API responses.
 */
final class ResponseSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => 'onKernelResponse',
    ];
  }

  public function onKernelResponse(ResponseEvent $event): void {
    $route = $event->getRequest()->attributes->get('_route', '');
    if (!str_starts_with($route, 'config_pull.')) {
      return;
    }

    $response = $event->getResponse();
    $response->headers->set('Cache-Control', 'no-store, private, no-cache, must-revalidate');
    $response->headers->set('Surrogate-Control', 'no-store');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('X-Frame-Options', 'DENY');
    $response->headers->set('Pragma', 'no-cache');

    $requestId = $event->getRequest()->headers->get('X-Request-ID');
    if ($requestId !== null) {
      $response->headers->set('X-Request-ID', $requestId);
    }
  }

}
