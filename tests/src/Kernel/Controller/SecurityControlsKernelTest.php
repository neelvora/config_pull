<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Kernel\Controller;

use Drupal\config_pull\Controller\ConfigPullController;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 *
 */
#[CoversClass(ConfigPullController::class)]
#[Group('config_pull')]
final class SecurityControlsKernelTest extends KernelTestBase {

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

  public function testInvalidConfigNameRejectedByRouter(): void {
    $request = $this->createSignedRequest('GET', '/config-pull/item/../../etc/passwd');
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertContains($response->getStatusCode(), [403, 404]);
  }

  public function testValidConfigNameAccepted(): void {
    $request = $this->createSignedRequest('GET', '/config-pull/item/system.site');
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertContains($response->getStatusCode(), [200, 404]);
  }

  public function testOversizedHashMapRejected(): void {
    $hashes = [];
    for ($i = 0; $i < 10001; $i++) {
      $hashes["config.item.$i"] = str_repeat('a', 64);
    }
    $body = json_encode(['hashes' => $hashes]);
    $request = $this->createSignedRequest('POST', '/config-pull/diff', $body);
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertSame(400, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('invalid_request', $data['error']);
  }

  public function testServerDisabledReturns503(): void {
    $settings = Settings::getAll();
    $settings['config_pull']['server_enabled'] = FALSE;
    new Settings($settings);

    $request = $this->createSignedRequest('POST', '/config-pull/handshake');
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertSame(503, $response->getStatusCode());
  }

  public function testEmergencyKillSwitchReturns503(): void {
    $this->container->get('state')->set('config_pull.emergency_kill', TRUE);

    $request = $this->createSignedRequest('POST', '/config-pull/handshake');
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertSame(503, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame('service_unavailable', $data['error']);
  }

  public function testEmergencyKillSwitchCanBeCleared(): void {
    $state = $this->container->get('state');
    $state->set('config_pull.emergency_kill', TRUE);

    $request = $this->createSignedRequest('POST', '/config-pull/handshake');
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);
    $this->assertSame(503, $response->getStatusCode());

    $state->delete('config_pull.emergency_kill');

    $request2 = $this->createSignedRequest('POST', '/config-pull/handshake');
    $response2 = $kernel->handle($request2);
    $this->assertSame(200, $response2->getStatusCode());
  }

  public function testMalformedJsonBodyRejected(): void {
    $server = [
      'HTTP_X_CONFIG_PULL_TIMESTAMP' => (string) time(),
      'HTTP_X_CONFIG_PULL_NONCE' => bin2hex(random_bytes(32)),
      'REMOTE_ADDR' => '127.0.0.1',
      'CONTENT_TYPE' => 'application/json',
    ];
    $body = '{not valid json at all';
    $payload = implode("\n", [
      'POST',
      '/config-pull/diff',
      $server['HTTP_X_CONFIG_PULL_TIMESTAMP'],
      $server['HTTP_X_CONFIG_PULL_NONCE'],
      $body,
    ]);
    $server['HTTP_X_CONFIG_PULL_SIGNATURE'] = hash_hmac('sha256', $payload, $this->secret);

    $request = Request::create('/config-pull/diff', 'POST', [], [], [], $server, $body);
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertSame(400, $response->getStatusCode());
  }

  public function testContentTypeEnforcementOnDiff(): void {
    $body = json_encode(['hashes' => []]);
    $server = [
      'HTTP_X_CONFIG_PULL_TIMESTAMP' => (string) time(),
      'HTTP_X_CONFIG_PULL_NONCE' => bin2hex(random_bytes(32)),
      'REMOTE_ADDR' => '127.0.0.1',
    ];
    $payload = implode("\n", [
      'POST',
      '/config-pull/diff',
      $server['HTTP_X_CONFIG_PULL_TIMESTAMP'],
      $server['HTTP_X_CONFIG_PULL_NONCE'],
      $body,
    ]);
    $server['HTTP_X_CONFIG_PULL_SIGNATURE'] = hash_hmac('sha256', $payload, $this->secret);

    $request = Request::create('/config-pull/diff', 'POST', [], [], [], $server, $body);
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertSame(415, $response->getStatusCode());
  }

  public function testContentTypeEnforcementOnExport(): void {
    $body = json_encode(['names' => ['system.site']]);
    $server = [
      'HTTP_X_CONFIG_PULL_TIMESTAMP' => (string) time(),
      'HTTP_X_CONFIG_PULL_NONCE' => bin2hex(random_bytes(32)),
      'REMOTE_ADDR' => '127.0.0.1',
    ];
    $payload = implode("\n", [
      'POST',
      '/config-pull/export',
      $server['HTTP_X_CONFIG_PULL_TIMESTAMP'],
      $server['HTTP_X_CONFIG_PULL_NONCE'],
      $body,
    ]);
    $server['HTTP_X_CONFIG_PULL_SIGNATURE'] = hash_hmac('sha256', $payload, $this->secret);

    $request = Request::create('/config-pull/export', 'POST', [], [], [], $server, $body);
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertSame(415, $response->getStatusCode());
  }

  public function testResponseSecurityHeaders(): void {
    $request = $this->createSignedRequest('POST', '/config-pull/handshake');
    $request->headers->set('X-Request-ID', 'test-request-id-12345');
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    $this->assertSame('test-request-id-12345', $response->headers->get('X-Request-ID'));
  }

}
