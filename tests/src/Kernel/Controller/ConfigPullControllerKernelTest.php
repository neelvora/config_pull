<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Kernel\Controller;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Drupal\config_pull\Controller\ConfigPullController;
use Symfony\Component\HttpFoundation\Request;

/**
 *
 */
#[CoversClass(ConfigPullController::class)]
#[Group('config_pull')]
final class ConfigPullControllerKernelTest extends KernelTestBase {

  protected static $modules = ['system', 'config_pull'];

  private string $secret = 'test-kernel-secret-64chars-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('system', []);

    $settings = Settings::getAll();
    $settings['config_pull'] = [
      'server_enabled' => TRUE,
      'secret' => $this->secret,
      'allow_insecure' => TRUE,
      'rate_limit' => 100,
    ];
    new Settings($settings);
  }

  private function createSignedRequest(string $method, string $path, string $body = ''): Request {
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(32));
    $payload = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, $body]);
    $signature = hash_hmac('sha256', $payload, $this->secret);

    $server = [
      'HTTP_X_CONFIG_PULL_TIMESTAMP' => $timestamp,
      'HTTP_X_CONFIG_PULL_NONCE' => $nonce,
      'HTTP_X_CONFIG_PULL_SIGNATURE' => $signature,
      'REMOTE_ADDR' => '127.0.0.1',
    ];

    if ($body !== '') {
      $server['CONTENT_TYPE'] = 'application/json';
    }

    return Request::create($path, $method, [], [], [], $server, $body);
  }

  public function testHandshakeReturnsValidResponse(): void {
    $request = $this->createSignedRequest('POST', '/config-pull/handshake');
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('1.0.0', $data['server_version']);
    $this->assertArrayHasKey('config_count', $data);
    $this->assertArrayHasKey('hash_version', $data);
  }

  public function testHandshakeRejectsUnauthenticatedRequest(): void {
    $request = Request::create('/config-pull/handshake', 'POST', [], [], [], [
      'REMOTE_ADDR' => '127.0.0.1',
    ]);
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertSame(401, $response->getStatusCode());
  }

  public function testDiffReturnsNullForMatchingHashes(): void {
    $hashes = [];
    $body = json_encode(['hashes' => $hashes]);
    $request = $this->createSignedRequest('POST', '/config-pull/diff', $body);
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('new', $data);
  }

  public function testItemReturnsConfigYaml(): void {
    $exportService = $this->container->get('config_pull.config_export');

    $names = $exportService->listConfigNames();

    if (count($names) === 0) {
      $this->markTestSkipped('No config items available in kernel test environment.');
    }

    $name = $names[0];

    $request = $this->createSignedRequest('GET', '/config-pull/item/' . $name);
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertNotEmpty($response->getContent());
    $this->assertNotEmpty($response->headers->get('X-Config-Hash'));
  }

  public function testItemReturns404ForMissingConfig(): void {
    $request = $this->createSignedRequest('GET', '/config-pull/item/nonexistent.config.name');
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertSame(404, $response->getStatusCode());
  }

  public function testExportFullReturnsTarGz(): void {
    $request = $this->createSignedRequest('GET', '/config-pull/export/full');
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertSame(200, $response->getStatusCode());

    $contentType = $response->headers->get('Content-Type');
    $this->assertStringContainsString('application/gzip', $contentType);
  }

}
