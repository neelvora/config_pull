<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Logs structured config_pull API access events to watchdog.
 */
class AuditService {

  private LoggerInterface $logger;

  public function __construct(
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Logs a config_pull API event with structured context.
   */
  public function log(
    Request $request,
    string $operation,
    string $result,
    int $statusCode,
    int $itemCount = 0,
    float $duration = 0.0,
  ): void {
    $context = [
      'ip' => $request->getClientIp() ?? '0.0.0.0',
      'operation' => $operation,
      'result' => $result,
      'status_code' => $statusCode,
      'item_count' => $itemCount,
      'duration_ms' => round($duration * 1000, 2),
      'nonce_prefix' => substr($request->headers->get('X-Config-Pull-Nonce', ''), 0, 8),
      'request_id' => $request->headers->get('X-Request-ID', ''),
      'user_agent' => $request->headers->get('User-Agent', ''),
    ];

    $message = 'config_pull @operation: @result (HTTP @status_code, @item_count items, @duration_ms ms) from @ip';

    if ($result === 'success') {
      $this->getLogger()->info($message, $context);
    }
    else {
      $this->getLogger()->warning($message, $context);
    }
  }

  private function getLogger(): LoggerInterface {
    if (!isset($this->logger)) {
      $this->logger = $this->loggerFactory->get('config_pull');
    }
    return $this->logger;
  }

}
