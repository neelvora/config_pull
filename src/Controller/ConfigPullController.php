<?php

declare(strict_types=1);

namespace Drupal\config_pull\Controller;

use Drupal\Component\Serialization\Yaml;
use Drupal\config_pull\Service\AuditService;
use Drupal\config_pull\Service\AuthenticationService;
use Drupal\config_pull\Service\ConfigExportService;
use Drupal\config_pull\Service\ConfigHashCacheService;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles config_pull API endpoints.
 */
final class ConfigPullController implements ContainerInjectionInterface {

  private const SERVER_VERSION = '1.0.0';

  private const PROTOCOL_VERSION = 1;

  private const MIN_PROTOCOL_VERSION = 1;

  public function __construct(
    private readonly AuthenticationService $auth,
    private readonly ConfigExportService $exportService,
    private readonly ConfigHashCacheService $hashCache,
    private readonly AuditService $audit,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config_pull.authentication'),
      $container->get('config_pull.config_export'),
      $container->get('config_pull.hash_cache'),
      $container->get('config_pull.audit'),
    );
  }

  public function handshake(Request $request): JsonResponse {
    $start = microtime(TRUE);
    $authResult = $this->auth->validateRequest($request);
    if (!$authResult['valid']) {
      return $this->authError($request, 'handshake', $authResult, $start);
    }

    $settings = Settings::get('config_pull', []);

    $data = [
      'server_version' => self::SERVER_VERSION,
      'protocol_version' => self::PROTOCOL_VERSION,
      'min_protocol_version' => self::MIN_PROTOCOL_VERSION,
      'drupal_version' => \Drupal::VERSION,
      'config_count' => $this->exportService->getConfigCount(),
      'hash_version' => $this->hashCache->getHashVersion(),
      'supported_features' => ['diff', 'export', 'export_full'],
      'supports_translations' => !empty($settings['include_translations']),
      'supports_hash_cache' => TRUE,
    ];

    if ($authResult['using_previous_secret']) {
      $data['warning'] = 'Using previous secret. Rotate clients to the new secret.';
    }

    $this->audit->log($request, 'handshake', 'success', 200, 0, microtime(TRUE) - $start);
    return new JsonResponse($data);
  }

  public function diff(Request $request): JsonResponse {
    $start = microtime(TRUE);
    $authResult = $this->auth->validateRequest($request);
    if (!$authResult['valid']) {
      return $this->authError($request, 'diff', $authResult, $start);
    }

    if (!$this->requireJsonContentType($request)) {
      $this->audit->log($request, 'diff', 'error', 415, 0, microtime(TRUE) - $start);
      return new JsonResponse(['error' => 'unsupported_media_type', 'detail' => 'Content-Type must be application/json'], 415);
    }

    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body) || !isset($body['hashes']) || !is_array($body['hashes'])) {
      $this->audit->log($request, 'diff', 'error', 400, 0, microtime(TRUE) - $start);
      return new JsonResponse(['error' => 'invalid_request', 'detail' => 'Missing or invalid hashes field'], 400);
    }

    $clientHashes = $body['hashes'];
    if (count($clientHashes) > 10000) {
      $this->audit->log($request, 'diff', 'error', 400, 0, microtime(TRUE) - $start);
      return new JsonResponse(['error' => 'invalid_request', 'detail' => 'Hash map exceeds 10000 entries'], 400);
    }

    $serverHashes = $this->hashCache->getHashes();

    $new = [];
    $changed = [];
    $deleted = [];
    $unchangedCount = 0;

    foreach ($serverHashes as $name => $hash) {
      if (!isset($clientHashes[$name])) {
        $new[$name] = $hash;
      }
      elseif ($clientHashes[$name] !== $hash) {
        $changed[$name] = $hash;
      }
      else {
        $unchangedCount++;
      }
    }

    foreach ($clientHashes as $name => $hash) {
      if (!isset($serverHashes[$name])) {
        $deleted[] = $name;
      }
    }

    $totalItems = count($new) + count($changed) + count($deleted);

    if ($totalItems === 0) {
      $this->audit->log($request, 'diff', 'success', 304, 0, microtime(TRUE) - $start);
      return new JsonResponse(NULL, 304);
    }

    $data = [
      'new' => $new,
      'changed' => $changed,
      'deleted' => $deleted,
      'unchanged_count' => $unchangedCount,
      'hash_version' => $this->hashCache->getHashVersion(),
    ];

    $includeTranslations = !empty($body['include_translations']);
    $serverAllows = !empty(Settings::get('config_pull', [])['include_translations']);
    if ($includeTranslations && $serverAllows) {
      $clientCollections = $body['collection_hashes'] ?? [];
      $data['collections'] = $this->computeCollectionDiffs($clientCollections);
    }

    $this->audit->log($request, 'diff', 'success', 200, $totalItems, microtime(TRUE) - $start);
    return new JsonResponse($data);
  }

  public function item(Request $request, string $name): Response {
    $start = microtime(TRUE);
    $authResult = $this->auth->validateRequest($request);
    if (!$authResult['valid']) {
      return $this->authError($request, 'item', $authResult, $start);
    }

    $collection = $request->query->get('collection', '');
    if ($collection !== '') {
      $item = $this->exportService->getCollectionItem($collection, $name);
    }
    else {
      $item = $this->exportService->getItem($name);
    }
    if ($item === NULL) {
      $this->audit->log($request, 'item', 'error', 404, 0, microtime(TRUE) - $start);
      return new JsonResponse(['error' => 'not_found', 'detail' => 'Config item not found'], 404);
    }

    $yaml = Yaml::encode($item['data']);
    $response = new Response($yaml, 200, [
      'Content-Type' => 'text/yaml',
      'X-Config-Hash' => $item['hash'],
    ]);

    $this->audit->log($request, 'item', 'success', 200, 1, microtime(TRUE) - $start);
    return $response;
  }

  public function export(Request $request): Response {
    $start = microtime(TRUE);
    $authResult = $this->auth->validateRequest($request);
    if (!$authResult['valid']) {
      return $this->authError($request, 'export', $authResult, $start);
    }

    if (!$this->requireJsonContentType($request)) {
      $this->audit->log($request, 'export', 'error', 415, 0, microtime(TRUE) - $start);
      return new JsonResponse(['error' => 'unsupported_media_type', 'detail' => 'Content-Type must be application/json'], 415);
    }

    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body) || !isset($body['names']) || !is_array($body['names'])) {
      $this->audit->log($request, 'export', 'error', 400, 0, microtime(TRUE) - $start);
      return new JsonResponse(['error' => 'invalid_request', 'detail' => 'Missing or invalid names field'], 400);
    }

    $names = $body['names'];
    if (empty($names)) {
      $this->audit->log($request, 'export', 'error', 400, 0, microtime(TRUE) - $start);
      return new JsonResponse(['error' => 'invalid_request', 'detail' => 'Names array is empty'], 400);
    }

    $items = $this->exportService->getItems($names);
    if (empty($items)) {
      $this->audit->log($request, 'export', 'error', 404, 0, microtime(TRUE) - $start);
      return new JsonResponse(['error' => 'not_found', 'detail' => 'No matching config items found'], 404);
    }

    $tarPath = $this->exportService->buildTarGz($items);

    $response = new BinaryFileResponse($tarPath, 200, [
      'Content-Type' => 'application/gzip',
      'Content-Disposition' => 'attachment; filename="config-export.tar.gz"',
    ]);
    $response->deleteFileAfterSend();

    $this->audit->log($request, 'export', 'success', 200, count($items), microtime(TRUE) - $start);
    return $response;
  }

  public function exportFull(Request $request): Response {
    $start = microtime(TRUE);
    $authResult = $this->auth->validateRequest($request);
    if (!$authResult['valid']) {
      return $this->authError($request, 'export_full', $authResult, $start);
    }

    $items = $this->exportService->getAllItems();
    if (empty($items)) {
      $this->audit->log($request, 'export_full', 'success', 200, 0, microtime(TRUE) - $start);
      return new JsonResponse(['error' => 'empty', 'detail' => 'No exportable config items'], 200);
    }

    $tarPath = $this->exportService->buildTarGz($items);

    $response = new BinaryFileResponse($tarPath, 200, [
      'Content-Type' => 'application/gzip',
      'Content-Disposition' => 'attachment; filename="config-export-full.tar.gz"',
    ]);
    $response->deleteFileAfterSend();

    $this->audit->log($request, 'export_full', 'success', 200, count($items), microtime(TRUE) - $start);
    return $response;
  }

  private function computeCollectionDiffs(array $clientCollections): array {
    $result = [];
    $serverCollections = $this->exportService->listCollections();

    foreach ($serverCollections as $collection) {
      $serverHashes = $this->exportService->getCollectionHashes($collection);
      $clientHashes = $clientCollections[$collection] ?? [];

      $new = [];
      $changed = [];
      $deleted = [];
      $unchangedCount = 0;

      foreach ($serverHashes as $name => $hash) {
        if (!isset($clientHashes[$name])) {
          $new[$name] = $hash;
        }
        elseif ($clientHashes[$name] !== $hash) {
          $changed[$name] = $hash;
        }
        else {
          $unchangedCount++;
        }
      }

      foreach ($clientHashes as $name => $hash) {
        if (!isset($serverHashes[$name])) {
          $deleted[] = $name;
        }
      }

      $totalItems = count($new) + count($changed) + count($deleted);
      if ($totalItems > 0) {
        $result[$collection] = [
          'new' => $new,
          'changed' => $changed,
          'deleted' => $deleted,
          'unchanged_count' => $unchangedCount,
        ];
      }
    }

    foreach (array_keys($clientCollections) as $collection) {
      if (!in_array($collection, $serverCollections, TRUE) && !isset($result[$collection])) {
        $clientHashes = $clientCollections[$collection];
        if (!empty($clientHashes)) {
          $result[$collection] = [
            'new' => [],
            'changed' => [],
            'deleted' => array_keys($clientHashes),
            'unchanged_count' => 0,
          ];
        }
      }
    }

    return $result;
  }

  /**
   * @param array{valid: false, code: int, error: string, detail: string} $authResult
   */
  private function authError(Request $request, string $operation, array $authResult, float $start): JsonResponse {
    $code = $authResult['code'];
    $this->audit->log($request, $operation, $authResult['error'], $code, 0, microtime(TRUE) - $start);
    return new JsonResponse(['error' => $authResult['error']], $code);
  }

  private function requireJsonContentType(Request $request): bool {
    $contentType = $request->headers->get('Content-Type', '');
    return str_starts_with($contentType, 'application/json');
  }

}
