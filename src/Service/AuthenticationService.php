<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Validates HMAC-authenticated requests to config_pull endpoints.
 *
 * Validation chain (short-circuits on first failure):
 * 1. Emergency kill switch (state-based)
 * 2. Server enabled check (settings)
 * 3. TLS enforcement
 * 4. IP allowlist
 * 5. Rate limit (flood)
 * 6. Auth failure lockout (flood)
 * 7. HMAC signature verification (tries current + previous secret)
 * 8. Nonce replay prevention.
 */
class AuthenticationService {

  private const FLOOD_REQUEST = 'config_pull.request';

  private const FLOOD_AUTH_FAIL = 'config_pull.auth_fail';

  private const NONCE_COLLECTION = 'config_pull_nonces';

  private const NONCE_TTL = 600;

  private const KILL_SWITCH_KEY = 'config_pull.emergency_kill';

  private KeyValueStoreExpirableInterface $nonceStore;

  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirableFactory,
    private readonly FloodInterface $flood,
    private readonly TimeInterface $time,
    private readonly StateInterface $state,
  ) {}

  /**
   * Validates an incoming request through the full authentication chain.
   *
   * @return array{valid: bool, using_previous_secret?: bool, code?: int, error?: string, detail?: string}
   */
  public function validateRequest(Request $request): array {
    $settings = Settings::get('config_pull', []);

    if ($this->state->get(self::KILL_SWITCH_KEY, FALSE)) {
      return $this->fail(503, 'service_unavailable', 'Emergency kill switch active');
    }

    if (empty($settings['server_enabled'])) {
      return $this->fail(503, 'service_unavailable', 'Server not enabled');
    }

    if (!$request->isSecure() && empty($settings['allow_insecure'])) {
      return $this->fail(403, 'tls_required', 'HTTPS required');
    }

    $ip = $request->getClientIp() ?? '0.0.0.0';

    $allowedIps = $settings['allowed_ips'] ?? [];
    if (!empty($allowedIps) && !$this->ipMatches($ip, $allowedIps)) {
      return $this->fail(403, 'ip_denied', 'IP not in allowlist');
    }

    $rateLimit = (int) ($settings['rate_limit'] ?? 10);
    if (!$this->flood->isAllowed(self::FLOOD_REQUEST, $rateLimit, 60, $ip)) {
      return $this->fail(429, 'rate_limited', 'Rate limit exceeded');
    }

    $lockoutThreshold = (int) ($settings['auth_failure_lockout'] ?? 5);
    if (!$this->flood->isAllowed(self::FLOOD_AUTH_FAIL, $lockoutThreshold, 300, $ip)) {
      return $this->fail(429, 'auth_lockout', 'Too many auth failures');
    }

    $timestamp = $request->headers->get('X-Config-Pull-Timestamp', '');
    $nonce = $request->headers->get('X-Config-Pull-Nonce', '');
    $signature = $request->headers->get('X-Config-Pull-Signature', '');

    if ($timestamp === '' || $nonce === '' || $signature === '') {
      $this->registerAuthFailure($ip);
      return $this->fail(401, 'authentication_failed', 'Missing auth headers');
    }

    $tolerance = (int) ($settings['timestamp_tolerance'] ?? 300);
    $serverTime = $this->time->getCurrentTime();
    if (abs($serverTime - (int) $timestamp) > $tolerance) {
      $this->registerAuthFailure($ip);
      return $this->fail(401, 'authentication_failed', 'Timestamp out of range');
    }

    if (!preg_match('/^[a-f0-9]{64}$/', $nonce)) {
      $this->registerAuthFailure($ip);
      return $this->fail(401, 'authentication_failed', 'Invalid nonce format');
    }

    $payload = $this->buildCanonicalPayload($request, $timestamp, $nonce);

    $secret = $settings['secret'] ?? '';
    $previousSecret = $settings['previous_secret'] ?? '';
    $usingPrevious = FALSE;

    if ($secret !== '' && hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
      // Current secret matches.
    }
    elseif ($previousSecret !== '' && hash_equals(hash_hmac('sha256', $payload, $previousSecret), $signature)) {
      $usingPrevious = TRUE;
    }
    else {
      $this->registerAuthFailure($ip);
      return $this->fail(401, 'authentication_failed', 'Invalid signature');
    }

    $store = $this->getNonceStore();
    if ($store->get($nonce) !== NULL) {
      $this->registerAuthFailure($ip);
      return $this->fail(401, 'authentication_failed', 'Nonce already used');
    }

    $store->setWithExpire($nonce, TRUE, self::NONCE_TTL);
    $this->flood->register(self::FLOOD_REQUEST, 60, $ip);

    return ['valid' => TRUE, 'using_previous_secret' => $usingPrevious];
  }

  /**
   * Builds the canonical string used for HMAC signing.
   */
  private function buildCanonicalPayload(Request $request, string $timestamp, string $nonce): string {
    $method = strtoupper($request->getMethod());
    $path = $request->getPathInfo();
    $body = $request->getContent();
    return implode("\n", [$method, $path, $timestamp, $nonce, $body]);
  }

  /**
   * Checks if an IP matches any CIDR pattern in the allowlist.
   *
   * @param string $ip
   * @param string[] $patterns
   */
  private function ipMatches(string $ip, array $patterns): bool {
    foreach ($patterns as $pattern) {
      if (str_contains($pattern, '/')) {
        if ($this->ipInCidr($ip, $pattern)) {
          return TRUE;
        }
      }
      elseif ($ip === $pattern) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   *
   */
  private function ipInCidr(string $ip, string $cidr): bool {
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int) $bits;
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === FALSE || $subnetLong === FALSE) {
      return FALSE;
    }
    $mask = -1 << (32 - $bits);
    return ($ipLong & $mask) === ($subnetLong & $mask);
  }

  /**
   * @return array{valid: false, code: int, error: string, detail: string}
   */
  private function fail(int $code, string $error, string $detail): array {
    return ['valid' => FALSE, 'code' => $code, 'error' => $error, 'detail' => $detail];
  }

  /**
   *
   */
  private function registerAuthFailure(string $ip): void {
    $this->flood->register(self::FLOOD_AUTH_FAIL, 300, $ip);
  }

  /**
   *
   */
  private function getNonceStore(): KeyValueStoreExpirableInterface {
    if (!isset($this->nonceStore)) {
      $this->nonceStore = $this->keyValueExpirableFactory->get(self::NONCE_COLLECTION);
    }
    return $this->nonceStore;
  }

}
