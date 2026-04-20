<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\Service;

use Drupal\config_pull\Service\AuditService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(AuditService::class)]
#[Group('config_pull')]
final class AuditServiceTest extends TestCase {

  private LoggerInterface $logger;
  private AuditService $service;

  protected function setUp(): void {
    parent::setUp();
    $this->logger = $this->createMock(LoggerInterface::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->with('config_pull')->willReturn($this->logger);
    $this->service = new AuditService($factory);
  }

  public function testSuccessLogsInfo(): void {
    $this->logger->expects($this->once())
      ->method('info')
      ->with(
        $this->isType('string'),
        $this->callback(function (array $context): bool {
          return $context['operation'] === 'handshake'
            && $context['result'] === 'success'
            && $context['status_code'] === 200
            && $context['item_count'] === 0
            && $context['ip'] === '127.0.0.1';
        }),
      );

    $request = Request::create('https://example.com/config-pull/handshake', 'POST');
    $this->service->log($request, 'handshake', 'success', 200);
  }

  public function testFailureLogsWarning(): void {
    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->isType('string'),
        $this->callback(function (array $context): bool {
          return $context['result'] === 'auth_failed'
            && $context['status_code'] === 401;
        }),
      );

    $request = Request::create('https://example.com/config-pull/handshake', 'POST');
    $this->service->log($request, 'handshake', 'auth_failed', 401);
  }

  public function testContextIncludesAllFields(): void {
    $nonce = bin2hex(random_bytes(32));
    $this->logger->expects($this->once())
      ->method('info')
      ->with(
        $this->isType('string'),
        $this->callback(function (array $context) use ($nonce): bool {
          return isset($context['ip'])
            && $context['operation'] === 'export'
            && $context['result'] === 'success'
            && $context['status_code'] === 200
            && $context['item_count'] === 42
            && $context['duration_ms'] === 150.0
            && $context['nonce_prefix'] === substr($nonce, 0, 8)
            && $context['request_id'] === 'req-abc-123'
            && $context['user_agent'] === 'ConfigPull/1.0';
        }),
      );

    $request = Request::create('https://example.com/config-pull/export', 'POST');
    $request->headers->set('X-Config-Pull-Nonce', $nonce);
    $request->headers->set('X-Request-ID', 'req-abc-123');
    $request->headers->set('User-Agent', 'ConfigPull/1.0');
    $this->service->log($request, 'export', 'success', 200, 42, 0.15);
  }

  public function testMissingHeadersDefaultGracefully(): void {
    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->isType('string'),
        $this->callback(function (array $context): bool {
          return $context['nonce_prefix'] === ''
            && $context['request_id'] === '';
        }),
      );

    $request = Request::create('https://example.com/config-pull/diff', 'POST');
    $this->service->log($request, 'diff', 'error', 500);
  }

}
