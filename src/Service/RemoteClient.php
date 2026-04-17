<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;

class RemoteClient {

  public function __construct(
    private readonly ClientInterface $httpClient,
  ) {}

  public function getRemoteConfig(string $remoteName): array {
    $remotes = Settings::get('config_pull_remotes', []);
    if (!isset($remotes[$remoteName])) {
      throw new \InvalidArgumentException("Remote '$remoteName' is not defined in settings.php.");
    }
    $remote = $remotes[$remoteName];
    if (empty($remote['uri']) || empty($remote['secret'])) {
      throw new \InvalidArgumentException("Remote '$remoteName' is missing 'uri' or 'secret'.");
    }
    return [
      'uri' => $remote['uri'],
      'secret' => $remote['secret'],
      'timeout' => $remote['timeout'] ?? 30,
      'verify_ssl' => $remote['verify_ssl'] ?? TRUE,
    ];
  }

  public function handshake(string $remoteName): array {
    $remote = $this->getRemoteConfig($remoteName);
    return $this->sendRequest('POST', $remote, '/config-pull/handshake');
  }

  public function diff(string $remoteName, array $localHashes): ?array {
    $remote = $this->getRemoteConfig($remoteName);
    return $this->sendRequest('POST', $remote, '/config-pull/diff', ['hashes' => $localHashes], TRUE);
  }

  public function item(string $remoteName, string $name): array {
    $remote = $this->getRemoteConfig($remoteName);
    $nonce = bin2hex(random_bytes(32));
    $timestamp = (string) time();
    $path = '/config-pull/item/' . $name;
    $signature = $this->computeHmac('GET', $path, $timestamp, $nonce, '', $remote['secret']);

    $response = $this->httpClient->request('GET', rtrim($remote['uri'], '/') . $path, [
      'headers' => [
        'X-Config-Pull-Timestamp' => $timestamp,
        'X-Config-Pull-Nonce' => $nonce,
        'X-Config-Pull-Signature' => $signature,
      ],
      'timeout' => $remote['timeout'],
      'verify' => $remote['verify_ssl'],
      'http_errors' => TRUE,
    ]);

    return [
      'yaml' => (string) $response->getBody(),
      'hash' => $response->getHeaderLine('X-Config-Hash'),
    ];
  }

  public function export(string $remoteName, array $names): string {
    $remote = $this->getRemoteConfig($remoteName);
    $path = '/config-pull/export';
    $bodyJson = json_encode(['names' => $names], JSON_THROW_ON_ERROR);
    $nonce = bin2hex(random_bytes(32));
    $timestamp = (string) time();
    $signature = $this->computeHmac('POST', $path, $timestamp, $nonce, $bodyJson, $remote['secret']);

    $response = $this->httpClient->request('POST', rtrim($remote['uri'], '/') . $path, [
      'headers' => [
        'Content-Type' => 'application/json',
        'X-Config-Pull-Timestamp' => $timestamp,
        'X-Config-Pull-Nonce' => $nonce,
        'X-Config-Pull-Signature' => $signature,
      ],
      'body' => $bodyJson,
      'timeout' => $remote['timeout'],
      'verify' => $remote['verify_ssl'],
      'http_errors' => TRUE,
    ]);

    return (string) $response->getBody();
  }

  public function exportFull(string $remoteName): string {
    $remote = $this->getRemoteConfig($remoteName);
    $path = '/config-pull/export/full';
    $nonce = bin2hex(random_bytes(32));
    $timestamp = (string) time();
    $signature = $this->computeHmac('GET', $path, $timestamp, $nonce, '', $remote['secret']);

    $response = $this->httpClient->request('GET', rtrim($remote['uri'], '/') . $path, [
      'headers' => [
        'X-Config-Pull-Timestamp' => $timestamp,
        'X-Config-Pull-Nonce' => $nonce,
        'X-Config-Pull-Signature' => $signature,
      ],
      'timeout' => $remote['timeout'],
      'verify' => $remote['verify_ssl'],
      'http_errors' => TRUE,
    ]);

    return (string) $response->getBody();
  }

  private function sendRequest(string $method, array $remote, string $path, array $body = [], bool $allow304 = FALSE): ?array {
    $bodyJson = !empty($body) ? json_encode($body, JSON_THROW_ON_ERROR) : '';
    $nonce = bin2hex(random_bytes(32));
    $timestamp = (string) time();
    $signature = $this->computeHmac($method, $path, $timestamp, $nonce, $bodyJson, $remote['secret']);

    $options = [
      'headers' => [
        'X-Config-Pull-Timestamp' => $timestamp,
        'X-Config-Pull-Nonce' => $nonce,
        'X-Config-Pull-Signature' => $signature,
      ],
      'timeout' => $remote['timeout'],
      'verify' => $remote['verify_ssl'],
      'http_errors' => !$allow304,
    ];

    if ($bodyJson !== '') {
      $options['headers']['Content-Type'] = 'application/json';
      $options['body'] = $bodyJson;
    }

    try {
      $response = $this->httpClient->request($method, rtrim($remote['uri'], '/') . $path, $options);
    }
    catch (ClientException $e) {
      $status = $e->getResponse()->getStatusCode();
      $errorBody = (string) $e->getResponse()->getBody();
      $errorData = json_decode($errorBody, TRUE) ?? [];
      $errorMsg = $errorData['error'] ?? 'unknown_error';
      throw new \RuntimeException("Server returned $status: $errorMsg", $status, $e);
    }
    catch (ServerException $e) {
      $status = $e->getResponse()->getStatusCode();
      throw new \RuntimeException("Server error $status on $path", $status, $e);
    }
    catch (ConnectException $e) {
      throw new \RuntimeException("Connection failed: " . $e->getMessage(), 0, $e);
    }

    if ($allow304 && $response->getStatusCode() === 304) {
      return NULL;
    }

    return json_decode((string) $response->getBody(), TRUE);
  }

  private function computeHmac(string $method, string $path, string $timestamp, string $nonce, string $body, string $secret): string {
    $payload = implode("\n", [$method, $path, $timestamp, $nonce, $body]);
    return hash_hmac('sha256', $payload, $secret);
  }

}
