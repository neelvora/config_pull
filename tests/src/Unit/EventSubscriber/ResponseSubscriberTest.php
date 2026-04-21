<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\EventSubscriber;

use Drupal\config_pull\EventSubscriber\ResponseSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 *
 */
#[CoversClass(ResponseSubscriber::class)]
#[Group('config_pull')]
final class ResponseSubscriberTest extends TestCase {

  private ResponseSubscriber $subscriber;

  protected function setUp(): void {
    parent::setUp();
    $this->subscriber = new ResponseSubscriber();
  }

  public function testSubscribesToKernelResponse(): void {
    $events = ResponseSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
  }

  public function testSetsSecurityHeadersOnConfigPullRoute(): void {
    $event = $this->makeEvent('config_pull.handshake');
    $this->subscriber->onKernelResponse($event);

    $headers = $event->getResponse()->headers;
    $cacheControl = $headers->get('Cache-Control');
    $this->assertStringContainsString('no-store', $cacheControl);
    $this->assertStringContainsString('private', $cacheControl);
    $this->assertStringContainsString('no-cache', $cacheControl);
    $this->assertStringContainsString('must-revalidate', $cacheControl);
    $this->assertSame('no-store', $headers->get('Surrogate-Control'));
    $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
    $this->assertSame('DENY', $headers->get('X-Frame-Options'));
    $this->assertSame('no-cache', $headers->get('Pragma'));
  }

  public function testDoesNotModifyNonConfigPullRoutes(): void {
    $event = $this->makeEvent('system.admin');
    $this->subscriber->onKernelResponse($event);

    $headers = $event->getResponse()->headers;
    $this->assertNull($headers->get('Surrogate-Control'));
    $this->assertNull($headers->get('X-Frame-Options'));
  }

  public function testPropagatesRequestId(): void {
    $request = new Request();
    $request->attributes->set('_route', 'config_pull.diff');
    $request->headers->set('X-Request-ID', 'req-test-123');
    $response = new Response();
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->onKernelResponse($event);
    $this->assertSame('req-test-123', $response->headers->get('X-Request-ID'));
  }

  public function testNoRequestIdWhenNotProvided(): void {
    $event = $this->makeEvent('config_pull.export');
    $this->subscriber->onKernelResponse($event);
    $this->assertNull($event->getResponse()->headers->get('X-Request-ID'));
  }

  public function testAllConfigPullRoutesGetHeaders(): void {
    $routes = [
      'config_pull.handshake',
      'config_pull.diff',
      'config_pull.item',
      'config_pull.export',
      'config_pull.export_full',
    ];
    foreach ($routes as $route) {
      $event = $this->makeEvent($route);
      $this->subscriber->onKernelResponse($event);
      $this->assertSame('DENY', $event->getResponse()->headers->get('X-Frame-Options'), "Failed for route: $route");
    }
  }

  private function makeEvent(string $routeName): ResponseEvent {
    $request = new Request();
    $request->attributes->set('_route', $routeName);
    $response = new Response();
    $kernel = $this->createMock(HttpKernelInterface::class);
    return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
  }

}
