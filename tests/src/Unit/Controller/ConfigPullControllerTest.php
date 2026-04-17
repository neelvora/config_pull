<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\Controller;

use Drupal\config_pull\Controller\ConfigPullController;
use Drupal\config_pull\Service\AuditService;
use Drupal\config_pull\Service\AuthenticationService;
use Drupal\config_pull\Service\ConfigExportService;
use Drupal\config_pull\Service\ConfigHashCacheService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @coversDefaultClass \Drupal\config_pull\Controller\ConfigPullController
 * @group config_pull
 */
final class ConfigPullControllerTest extends TestCase {

  private AuthenticationService $auth;
  private ConfigExportService $exportService;
  private ConfigHashCacheService $hashCache;
  private AuditService $audit;
  private ConfigPullController $controller;

  protected function setUp(): void {
    parent::setUp();
    $this->auth = $this->createMock(AuthenticationService::class);
    $this->exportService = $this->createMock(ConfigExportService::class);
    $this->hashCache = $this->createMock(ConfigHashCacheService::class);
    $this->audit = $this->createMock(AuditService::class);

    $this->auth->method('validateRequest')
      ->willReturn(['valid' => TRUE, 'using_previous_secret' => FALSE]);

    $this->controller = new ConfigPullController(
      $this->auth,
      $this->exportService,
      $this->hashCache,
      $this->audit,
    );
  }

  /**
   * @covers ::handshake
   */
  public function testHandshakeReturnsServerInfo(): void {
    $this->exportService->method('getConfigCount')->willReturn(150);
    $this->hashCache->method('getHashVersion')->willReturn(7);

    $request = Request::create('https://example.com/config-pull/handshake', 'POST');
    $response = $this->controller->handshake($request);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('1.0.0', $data['server_version']);
    $this->assertSame(1, $data['protocol_version']);
    $this->assertSame(150, $data['config_count']);
    $this->assertSame(7, $data['hash_version']);
    $this->assertArrayNotHasKey('warning', $data);
  }

  /**
   * @covers ::handshake
   */
  public function testHandshakeWithPreviousSecretWarning(): void {
    $this->auth = $this->createMock(AuthenticationService::class);
    $this->auth->method('validateRequest')
      ->willReturn(['valid' => TRUE, 'using_previous_secret' => TRUE]);
    $this->exportService->method('getConfigCount')->willReturn(10);
    $this->hashCache->method('getHashVersion')->willReturn(1);

    $controller = new ConfigPullController(
      $this->auth, $this->exportService, $this->hashCache, $this->audit,
    );
    $request = Request::create('https://example.com/config-pull/handshake', 'POST');
    $response = $controller->handshake($request);

    $data = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('warning', $data);
  }

  /**
   * @covers ::handshake
   */
  public function testHandshakeAuthFailure(): void {
    $this->auth = $this->createMock(AuthenticationService::class);
    $this->auth->method('validateRequest')
      ->willReturn(['valid' => FALSE, 'code' => 401, 'error' => 'authentication_failed', 'detail' => 'Bad sig']);
    $controller = new ConfigPullController(
      $this->auth, $this->exportService, $this->hashCache, $this->audit,
    );

    $request = Request::create('https://example.com/config-pull/handshake', 'POST');
    $response = $controller->handshake($request);

    $this->assertSame(401, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('authentication_failed', $data['error']);
  }

  /**
   * @covers ::diff
   */
  public function testDiffReturnsChanges(): void {
    $this->hashCache->method('getHashes')->willReturn([
      'system.site' => 'aaa',
      'system.date' => 'bbb',
      'webform.webform.new' => 'ccc',
    ]);
    $this->hashCache->method('getHashVersion')->willReturn(5);

    $body = json_encode([
      'hashes' => [
        'system.site' => 'aaa',
        'system.date' => 'old_hash',
        'deleted.item' => 'ddd',
      ],
    ]);
    $request = Request::create('https://example.com/config-pull/diff', 'POST', [], [], [], [], $body);

    $response = $this->controller->diff($request);
    $this->assertSame(200, $response->getStatusCode());

    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(['webform.webform.new' => 'ccc'], $data['new']);
    $this->assertSame(['system.date' => 'bbb'], $data['changed']);
    $this->assertSame(['deleted.item'], $data['deleted']);
    $this->assertSame(1, $data['unchanged_count']);
    $this->assertSame(5, $data['hash_version']);
  }

  /**
   * @covers ::diff
   */
  public function testDiffReturns304WhenUnchanged(): void {
    $hashes = ['system.site' => 'aaa'];
    $this->hashCache->method('getHashes')->willReturn($hashes);

    $body = json_encode(['hashes' => $hashes]);
    $request = Request::create('https://example.com/config-pull/diff', 'POST', [], [], [], [], $body);

    $response = $this->controller->diff($request);
    $this->assertSame(304, $response->getStatusCode());
  }

  /**
   * @covers ::diff
   */
  public function testDiffRejectsInvalidBody(): void {
    $request = Request::create('https://example.com/config-pull/diff', 'POST', [], [], [], [], 'not json');
    $response = $this->controller->diff($request);
    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * @covers ::diff
   */
  public function testDiffRejectsTooManyHashes(): void {
    $hashes = [];
    for ($i = 0; $i < 10001; $i++) {
      $hashes["item.$i"] = str_repeat('a', 64);
    }
    $body = json_encode(['hashes' => $hashes]);
    $request = Request::create('https://example.com/config-pull/diff', 'POST', [], [], [], [], $body);

    $response = $this->controller->diff($request);
    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * @covers ::item
   */
  public function testItemReturnsYaml(): void {
    $this->exportService->method('getItem')
      ->with('system.site')
      ->willReturn(['data' => ['name' => 'Test Site'], 'hash' => 'abc123']);

    $request = Request::create('https://example.com/config-pull/item/system.site', 'GET');
    $response = $this->controller->item($request, 'system.site');

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('text/yaml', $response->headers->get('Content-Type'));
    $this->assertSame('abc123', $response->headers->get('X-Config-Hash'));
    $this->assertStringContainsString('name:', $response->getContent());
  }

  /**
   * @covers ::item
   */
  public function testItemNotFound(): void {
    $this->exportService->method('getItem')->willReturn(NULL);

    $request = Request::create('https://example.com/config-pull/item/nonexistent', 'GET');
    $response = $this->controller->item($request, 'nonexistent');

    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * @covers ::export
   */
  public function testExportReturnsTarGz(): void {
    $this->exportService->method('getItems')
      ->willReturn(['system.site' => ['name' => 'Test']]);

    $tempFile = tempnam(sys_get_temp_dir(), 'config_pull_test_');
    file_put_contents($tempFile, 'fake tar content');
    $this->exportService->method('buildTarGz')->willReturn($tempFile);

    $body = json_encode(['names' => ['system.site']]);
    $request = Request::create('https://example.com/config-pull/export', 'POST', [], [], [], [], $body);

    $response = $this->controller->export($request);
    $this->assertInstanceOf(BinaryFileResponse::class, $response);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/gzip', $response->headers->get('Content-Type'));

    @unlink($tempFile);
  }

  /**
   * @covers ::export
   */
  public function testExportRejectsEmptyNames(): void {
    $body = json_encode(['names' => []]);
    $request = Request::create('https://example.com/config-pull/export', 'POST', [], [], [], [], $body);

    $response = $this->controller->export($request);
    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * @covers ::export
   */
  public function testExportRejectsMissingNames(): void {
    $body = json_encode(['other' => 'data']);
    $request = Request::create('https://example.com/config-pull/export', 'POST', [], [], [], [], $body);

    $response = $this->controller->export($request);
    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * @covers ::export
   */
  public function testExportReturns404WhenNoItemsFound(): void {
    $this->exportService->method('getItems')->willReturn([]);

    $body = json_encode(['names' => ['nonexistent']]);
    $request = Request::create('https://example.com/config-pull/export', 'POST', [], [], [], [], $body);

    $response = $this->controller->export($request);
    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * @covers ::exportFull
   */
  public function testExportFullReturnsTarGz(): void {
    $this->exportService->method('getAllItems')
      ->willReturn(['system.site' => ['name' => 'Test']]);

    $tempFile = tempnam(sys_get_temp_dir(), 'config_pull_test_');
    file_put_contents($tempFile, 'fake tar content');
    $this->exportService->method('buildTarGz')->willReturn($tempFile);

    $request = Request::create('https://example.com/config-pull/export/full', 'GET');
    $response = $this->controller->exportFull($request);

    $this->assertInstanceOf(BinaryFileResponse::class, $response);
    $this->assertSame(200, $response->getStatusCode());

    @unlink($tempFile);
  }

  /**
   * @covers ::exportFull
   */
  public function testExportFullEmptyReturnsJson(): void {
    $this->exportService->method('getAllItems')->willReturn([]);

    $request = Request::create('https://example.com/config-pull/export/full', 'GET');
    $response = $this->controller->exportFull($request);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * @covers ::diff
   */
  public function testDiffAuthFailureReturns401(): void {
    $this->auth = $this->createMock(AuthenticationService::class);
    $this->auth->method('validateRequest')
      ->willReturn(['valid' => FALSE, 'code' => 401, 'error' => 'authentication_failed', 'detail' => 'Bad sig']);
    $controller = new ConfigPullController(
      $this->auth, $this->exportService, $this->hashCache, $this->audit,
    );

    $request = Request::create('https://example.com/config-pull/diff', 'POST');
    $response = $controller->diff($request);
    $this->assertSame(401, $response->getStatusCode());
  }

  /**
   * @covers ::export
   */
  public function testExportAuthFailureReturns503(): void {
    $this->auth = $this->createMock(AuthenticationService::class);
    $this->auth->method('validateRequest')
      ->willReturn(['valid' => FALSE, 'code' => 503, 'error' => 'service_unavailable', 'detail' => 'Kill switch']);
    $controller = new ConfigPullController(
      $this->auth, $this->exportService, $this->hashCache, $this->audit,
    );

    $body = json_encode(['names' => ['system.site']]);
    $request = Request::create('https://example.com/config-pull/export', 'POST', [], [], [], [], $body);
    $response = $controller->export($request);
    $this->assertSame(503, $response->getStatusCode());
  }

}
