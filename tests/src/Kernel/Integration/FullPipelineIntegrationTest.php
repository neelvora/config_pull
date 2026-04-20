<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Kernel\Integration;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

#[Group('config_pull')]
final class FullPipelineIntegrationTest extends KernelTestBase {

  protected static $modules = ['system', 'config_pull'];

  private string $secret = 'integration-test-secret-64chars-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);

    $settings = Settings::getAll();
    $settings['config_pull'] = [
      'server_enabled' => TRUE,
      'secret' => $this->secret,
      'allow_insecure' => TRUE,
      'rate_limit' => 100,
    ];
    new Settings($settings);
  }

  public function testFullRoundTrip(): void {
    $kernel = $this->container->get('http_kernel');

    $response = $kernel->handle($this->signedRequest('POST', '/config-pull/handshake'));
    $this->assertSame(200, $response->getStatusCode());
    $handshake = json_decode($response->getContent(), TRUE);
    $this->assertSame('1.0.0', $handshake['server_version']);
    $this->assertGreaterThan(0, $handshake['config_count']);

    $diffBody = json_encode(['hashes' => []]);
    $response = $kernel->handle($this->signedRequest('POST', '/config-pull/diff', $diffBody));
    $this->assertSame(200, $response->getStatusCode());
    $diff = json_decode($response->getContent(), TRUE);
    $this->assertNotEmpty($diff['new']);
    $this->assertEmpty($diff['changed']);
    $this->assertEmpty($diff['deleted']);

    $firstItem = array_key_first($diff['new']);
    $response = $kernel->handle($this->signedRequest('GET', '/config-pull/item/' . $firstItem));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertNotEmpty($response->headers->get('X-Config-Hash'));
    $this->assertNotEmpty($response->getContent());

    $correctHashes = $diff['new'];
    $diffBody = json_encode(['hashes' => $correctHashes]);
    $response = $kernel->handle($this->signedRequest('POST', '/config-pull/diff', $diffBody));
    $this->assertSame(304, $response->getStatusCode());
  }

  public function testDiffDetectsChangedConfig(): void {
    $kernel = $this->container->get('http_kernel');

    $diffBody = json_encode(['hashes' => ['system.site' => 'wrong-hash']]);
    $response = $kernel->handle($this->signedRequest('POST', '/config-pull/diff', $diffBody));
    $this->assertSame(200, $response->getStatusCode());
    $diff = json_decode($response->getContent(), TRUE);
    $this->assertArrayHasKey('system.site', $diff['changed']);
  }

  public function testDiffDetectsDeletedConfig(): void {
    $kernel = $this->container->get('http_kernel');

    $diffBody = json_encode(['hashes' => ['nonexistent.config.item' => 'somehash']]);
    $response = $kernel->handle($this->signedRequest('POST', '/config-pull/diff', $diffBody));
    $this->assertSame(200, $response->getStatusCode());
    $diff = json_decode($response->getContent(), TRUE);
    $this->assertContains('nonexistent.config.item', $diff['deleted']);
  }

  public function testExportReturnsTarGz(): void {
    $kernel = $this->container->get('http_kernel');

    $body = json_encode(['names' => ['system.site']]);
    $response = $kernel->handle($this->signedRequest('POST', '/config-pull/export', $body));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('gzip', $response->headers->get('Content-Type'));
  }

  public function testInvalidAuthReturnsError(): void {
    $kernel = $this->container->get('http_kernel');

    $request = Request::create('/config-pull/handshake', 'POST', [], [], [], [
      'HTTP_X_CONFIG_PULL_TIMESTAMP' => (string) time(),
      'HTTP_X_CONFIG_PULL_NONCE' => bin2hex(random_bytes(32)),
      'HTTP_X_CONFIG_PULL_SIGNATURE' => 'invalid-signature',
      'REMOTE_ADDR' => '127.0.0.1',
    ]);

    $response = $kernel->handle($request);
    $this->assertContains($response->getStatusCode(), [401, 403]);
  }

  private function signedRequest(string $method, string $path, string $body = ''): Request {
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

}
