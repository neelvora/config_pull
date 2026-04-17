<?php

declare(strict_types=1);

namespace Drupal\config_pull\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Handles config_pull API endpoints.
 */
final class ConfigPullController {

  public function handshake(): JsonResponse {
    return new JsonResponse(['status' => 'not_implemented'], 501);
  }

  public function diff(): JsonResponse {
    return new JsonResponse(['status' => 'not_implemented'], 501);
  }

  public function item(string $name): JsonResponse {
    return new JsonResponse(['status' => 'not_implemented'], 501);
  }

  public function export(): JsonResponse {
    return new JsonResponse(['status' => 'not_implemented'], 501);
  }

  public function exportFull(): JsonResponse {
    return new JsonResponse(['status' => 'not_implemented'], 501);
  }

}
