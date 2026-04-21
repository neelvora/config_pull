<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\config_pull\Service\AuthenticationService;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 *
 */
#[CoversClass(AuthenticationService::class)]
#[Group('config_pull')]
final class AuthenticationServiceTest extends TestCase {

  private KeyValueExpirableFactoryInterface $kvFactory;
  private KeyValueStoreExpirableInterface $nonceStore;
  private FloodInterface $flood;
  private TimeInterface $time;
  private StateInterface $state;
  private AuthenticationService $service;

  private string $secret = 'test-secret-with-at-least-32-characters-long';

  protected function setUp(): void {
    parent::setUp();
    $this->kvFactory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $this->nonceStore = $this->createMock(KeyValueStoreExpirableInterface::class);
    $this->flood = $this->createMock(FloodInterface::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->state = $this->createMock(StateInterface::class);

    $this->kvFactory->method('get')
      ->with('config_pull_nonces')
      ->willReturn($this->nonceStore);

    $this->flood->method('isAllowed')->willReturn(TRUE);
    $this->state->method('get')->willReturn(FALSE);
    $this->time->method('getCurrentTime')->willReturn(1000000);

    $this->service = new AuthenticationService(
    $this->kvFactory,
    $this->flood,
    $this->time,
    $this->state,
    );

    new Settings([
      'config_pull' => [
        'server_enabled' => TRUE,
        'secret' => $this->secret,
        'allowed_ips' => [],
        'rate_limit' => 10,
      ],
    ]);
  }

  public function testValidRequestSucceeds(): void {
    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}');
    $result = $this->service->validateRequest($request);
    $this->assertTrue($result['valid']);
    $this->assertFalse($result['using_previous_secret']);
  }

  public function testEmergencyKillSwitch(): void {
    $this->state = $this->createMock(StateInterface::class);
    $this->state->method('get')
      ->with('config_pull.emergency_kill', FALSE)
      ->willReturn(TRUE);
    $service = new AuthenticationService(
    $this->kvFactory, $this->flood, $this->time, $this->state,
    );
    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}');
    $result = $service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(503, $result['code']);
    $this->assertSame('service_unavailable', $result['error']);
  }

  public function testServerNotEnabled(): void {
    new Settings(['config_pull' => ['server_enabled' => FALSE]]);
    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}');
    $result = $this->service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(503, $result['code']);
  }

  public function testTlsRequired(): void {
    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}', secure: FALSE);
    $result = $this->service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(403, $result['code']);
    $this->assertSame('tls_required', $result['error']);
  }

  public function testAllowInsecureBypassesTls(): void {
    new Settings([
      'config_pull' => [
        'server_enabled' => TRUE,
        'secret' => $this->secret,
        'allow_insecure' => TRUE,
      ],
    ]);
    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}', secure: FALSE);
    $result = $this->service->validateRequest($request);
    $this->assertTrue($result['valid']);
  }

  public function testIpNotInAllowlist(): void {
    new Settings([
      'config_pull' => [
        'server_enabled' => TRUE,
        'secret' => $this->secret,
        'allowed_ips' => ['10.0.0.0/8'],
      ],
    ]);
    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}');
    $result = $this->service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(403, $result['code']);
    $this->assertSame('ip_denied', $result['error']);
  }

  public function testIpInCidrAllowlist(): void {
    new Settings([
      'config_pull' => [
        'server_enabled' => TRUE,
        'secret' => $this->secret,
        'allowed_ips' => ['127.0.0.0/8'],
      ],
    ]);
    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}');
    $result = $this->service->validateRequest($request);
    $this->assertTrue($result['valid']);
  }

  public function testExactIpMatch(): void {
    new Settings([
      'config_pull' => [
        'server_enabled' => TRUE,
        'secret' => $this->secret,
        'allowed_ips' => ['127.0.0.1'],
      ],
    ]);
    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}');
    $result = $this->service->validateRequest($request);
    $this->assertTrue($result['valid']);
  }

  public function testRateLimited(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')
      ->willReturnCallback(function ($name) {
        return $name !== 'config_pull.request';
      });
    $service = new AuthenticationService(
    $this->kvFactory, $flood, $this->time, $this->state,
    );
    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}');
    $result = $service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(429, $result['code']);
    $this->assertSame('rate_limited', $result['error']);
  }

  public function testAuthFailureLockout(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')
      ->willReturnCallback(function ($name) {
        return $name !== 'config_pull.auth_fail';
      });
    $service = new AuthenticationService(
    $this->kvFactory, $flood, $this->time, $this->state,
    );
    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}');
    $result = $service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(429, $result['code']);
    $this->assertSame('auth_lockout', $result['error']);
  }

  public function testMissingAuthHeaders(): void {
    $request = Request::create(
    'https://example.com/config-pull/handshake',
    'POST',
    [],
    [],
    [],
    ['HTTPS' => 'on'],
    '{}',
    );
    $result = $this->service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(401, $result['code']);
  }

  public function testTimestampOutOfRange(): void {
    $nonce = bin2hex(random_bytes(32));
    $staleTimestamp = '999000';
    $body = '{}';
    $payload = implode("\n", ['POST', '/config-pull/handshake', $staleTimestamp, $nonce, $body]);
    $signature = hash_hmac('sha256', $payload, $this->secret);

    $request = Request::create(
    'https://example.com/config-pull/handshake',
    'POST',
    [],
    [],
    [],
    ['HTTPS' => 'on'],
    $body,
    );
    $request->headers->set('X-Config-Pull-Timestamp', $staleTimestamp);
    $request->headers->set('X-Config-Pull-Nonce', $nonce);
    $request->headers->set('X-Config-Pull-Signature', $signature);

    $result = $this->service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(401, $result['code']);
  }

  public function testInvalidNonceFormat(): void {
    $timestamp = '1000000';
    $nonce = 'not-a-valid-nonce';
    $body = '{}';
    $payload = implode("\n", ['POST', '/config-pull/handshake', $timestamp, $nonce, $body]);
    $signature = hash_hmac('sha256', $payload, $this->secret);

    $request = Request::create(
    'https://example.com/config-pull/handshake',
    'POST',
    [],
    [],
    [],
    ['HTTPS' => 'on'],
    $body,
    );
    $request->headers->set('X-Config-Pull-Timestamp', $timestamp);
    $request->headers->set('X-Config-Pull-Nonce', $nonce);
    $request->headers->set('X-Config-Pull-Signature', $signature);

    $result = $this->service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(401, $result['code']);
  }

  public function testInvalidSignature(): void {
    $timestamp = '1000000';
    $nonce = bin2hex(random_bytes(32));
    $body = '{}';

    $request = Request::create(
    'https://example.com/config-pull/handshake',
    'POST',
    [],
    [],
    [],
    ['HTTPS' => 'on'],
    $body,
    );
    $request->headers->set('X-Config-Pull-Timestamp', $timestamp);
    $request->headers->set('X-Config-Pull-Nonce', $nonce);
    $request->headers->set('X-Config-Pull-Signature', str_repeat('a', 64));

    $result = $this->service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(401, $result['code']);
  }

  public function testPreviousSecretAccepted(): void {
    $previousSecret = 'old-secret-also-at-least-32-chars-long';
    new Settings([
      'config_pull' => [
        'server_enabled' => TRUE,
        'secret' => $this->secret,
        'previous_secret' => $previousSecret,
      ],
    ]);
    $request = $this->makeSignedRequest(
    'POST', '/config-pull/handshake', '{}',
    secret: $previousSecret,
    );
    $result = $this->service->validateRequest($request);
    $this->assertTrue($result['valid']);
    $this->assertTrue($result['using_previous_secret']);
  }

  public function testNonceReplayRejected(): void {
    $this->nonceStore->method('get')->willReturn(TRUE);

    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}');
    $result = $this->service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(401, $result['code']);
  }

  public function testNonceStoredAfterSuccess(): void {
    $this->nonceStore->expects($this->once())
      ->method('setWithExpire')
      ->with(
      $this->isType('string'),
      TRUE,
      600,
    );

    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}');
    $this->service->validateRequest($request);
  }

  public function testGetRequestWithEmptyBody(): void {
    $request = $this->makeSignedRequest('GET', '/config-pull/export/full', '');
    $result = $this->service->validateRequest($request);
    $this->assertTrue($result['valid']);
  }

  public function testFloodRegisteredOnSuccess(): void {
    $this->flood->expects($this->once())
      ->method('register')
      ->with('config_pull.request', 60, '127.0.0.1');

    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}');
    $this->service->validateRequest($request);
  }

  public function testAuthFailureRegistersFlood(): void {
    $this->flood->expects($this->once())
      ->method('register')
      ->with('config_pull.auth_fail', 300, '127.0.0.1');

    $request = Request::create(
    'https://example.com/config-pull/handshake',
    'POST',
    [],
    [],
    [],
    ['HTTPS' => 'on'],
    '{}',
    );
    $request->headers->set('X-Config-Pull-Timestamp', '1000000');
    $request->headers->set('X-Config-Pull-Nonce', bin2hex(random_bytes(32)));
    $request->headers->set('X-Config-Pull-Signature', str_repeat('b', 64));

    $this->service->validateRequest($request);
  }

  public function testMissingSignatureOnly(): void {
    $timestamp = '1000000';
    $nonce = bin2hex(random_bytes(32));
    $body = '{}';

    $request = Request::create(
    'https://example.com/config-pull/handshake',
    'POST',
    [],
    [],
    [],
    ['HTTPS' => 'on'],
    $body,
    );
    $request->headers->set('X-Config-Pull-Timestamp', $timestamp);
    $request->headers->set('X-Config-Pull-Nonce', $nonce);

    $result = $this->service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(401, $result['code']);
  }

  public function testMissingTimestampOnly(): void {
    $nonce = bin2hex(random_bytes(32));
    $body = '{}';
    $payload = implode("\n", ['POST', '/config-pull/handshake', '', $nonce, $body]);
    $signature = hash_hmac('sha256', $payload, $this->secret);

    $request = Request::create(
    'https://example.com/config-pull/handshake',
    'POST',
    [],
    [],
    [],
    ['HTTPS' => 'on'],
    $body,
    );
    $request->headers->set('X-Config-Pull-Nonce', $nonce);
    $request->headers->set('X-Config-Pull-Signature', $signature);

    $result = $this->service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(401, $result['code']);
  }

  public function testEmptyAllowlistPermitsAll(): void {
    new Settings([
      'config_pull' => [
        'server_enabled' => TRUE,
        'secret' => $this->secret,
        'allowed_ips' => [],
      ],
    ]);
    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}');
    $result = $this->service->validateRequest($request);
    $this->assertTrue($result['valid']);
  }

  public function testRateLimitAllowedWhenUnderThreshold(): void {
    $request = $this->makeSignedRequest('POST', '/config-pull/handshake', '{}');
    $result = $this->service->validateRequest($request);
    $this->assertTrue($result['valid']);
    $this->assertArrayNotHasKey('code', $result);
  }

  public function testRotatedSecretFullyRejected(): void {
    $wrongSecret = 'completely-wrong-secret-at-least-32-chars-here';
    new Settings([
      'config_pull' => [
        'server_enabled' => TRUE,
        'secret' => $this->secret,
        'previous_secret' => 'old-secret-also-at-least-32-chars-long',
      ],
    ]);
    $request = $this->makeSignedRequest(
    'POST', '/config-pull/handshake', '{}',
    secret: $wrongSecret,
    );
    $result = $this->service->validateRequest($request);
    $this->assertFalse($result['valid']);
    $this->assertSame(401, $result['code']);
  }

  private function makeSignedRequest(
    string $method,
    string $path,
    string $body,
    bool $secure = TRUE,
    ?string $secret = NULL,
  ): Request {
    $secret ??= $this->secret;
    $timestamp = '1000000';
    $nonce = bin2hex(random_bytes(32));
    $payload = implode("\n", [$method, $path, $timestamp, $nonce, $body]);
    $signature = hash_hmac('sha256', $payload, $secret);

    $serverParams = $secure ? ['HTTPS' => 'on'] : [];
    $request = Request::create(
    ($secure ? 'https' : 'http') . '://example.com' . $path,
    $method,
    [],
    [],
    [],
    $serverParams,
    $body,
    );
    $request->headers->set('X-Config-Pull-Timestamp', $timestamp);
    $request->headers->set('X-Config-Pull-Nonce', $nonce);
    $request->headers->set('X-Config-Pull-Signature', $signature);

    return $request;
  }

}
