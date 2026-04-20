<?php

declare(strict_types=1);

namespace Drupal\config_pull\Service;

use Drupal\config_pull\Exception\RemoteAuthenticationException;
use Drupal\config_pull\Exception\RemoteNetworkException;
use Drupal\config_pull\Exception\RemoteRateLimitException;
use Drupal\config_pull\Exception\RemoteServerException;
use Drupal\Core\Site\Settings;
use Consolidation\SiteAlias\SiteAliasManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;

class RemoteClient {

  private ?SiteAliasManagerInterface $aliasManager = NULL;

  public function __construct(
    private readonly ClientInterface $httpClient,
  ) {}

  public function setAliasManager(SiteAliasManagerInterface $aliasManager): void {
    $this->aliasManager = $aliasManager;
  }

  public function getRemoteConfig(string $remoteName): array {
    $name = ltrim($remoteName, '@');
    $remotes = Settings::get('config_pull_remotes', []);
    if (!isset($remotes[$name])) {
      $available = implode(', ', array_keys($remotes));
      throw new \InvalidArgumentException("Remote '$name' is not defined in settings.php. Available: $available");
    }
    $remote = $remotes[$name];
    if (empty($remote['secret'])) {
      throw new \InvalidArgumentException("Remote '$name' is missing 'secret' in settings.php.");
    }

    $uri = $remote['uri'] ?? '';
    if (empty($uri) && !empty($remote['alias'])) {
      $uri = $this->resolveAliasUri($remote['alias'], $name);
    }
    if (empty($uri)) {
      throw new \InvalidArgumentException("Remote '$name' is missing 'uri' (and no alias configured).");
    }

    return [
      'uri' => $uri,
      'secret' => $remote['secret'],
      'timeout' => $remote['timeout'] ?? 30,
      'verify_ssl' => $remote['verify_ssl'] ?? TRUE,
    ];
  }

  private function resolveAliasUri(string $aliasName, string $remoteName): string {
    if ($this->aliasManager === NULL) {
      throw new \RuntimeException("Remote '$remoteName' uses alias '$aliasName' but SiteAliasManager is not available.");
    }
    $alias = $this->aliasManager->get($aliasName);
    if ($alias === FALSE) {
      throw new \InvalidArgumentException("Drush alias '$aliasName' for remote '$remoteName' could not be resolved.");
    }
    $uri = $alias->get('uri', '');
    if (empty($uri)) {
      throw new \InvalidArgumentException("Drush alias '$aliasName' has no URI defined.");
    }
    return $uri;
  }

  public function handshake(string $remoteName): array {
    $remote = $this->getRemoteConfig($remoteName);
    return $this->sendRequest('POST', $remote, '/config-pull/handshake');
  }

  public function diff(string $remoteName, array $localHashes, bool $includeTranslations = FALSE, array $collectionHashes = []): ?array {
    $remote = $this->getRemoteConfig($remoteName);
    $body = ['hashes' => $localHashes];
    if ($includeTranslations) {
      $body['include_translations'] = TRUE;
      if (!empty($collectionHashes)) {
        $body['collection_hashes'] = $collectionHashes;
      }
    }
    return $this->sendRequest('POST', $remote, '/config-pull/diff', $body, TRUE);
  }

  public function collectionItem(string $remoteName, string $collection, string $name): array {
    $remote = $this->getRemoteConfig($remoteName);
    $nonce = bin2hex(random_bytes(32));
    $timestamp = (string) time();
    $path = '/config-pull/item/' . $name;
    $queryString = '?collection=' . urlencode($collection);
    $signature = $this->computeHmac('GET', $path, $timestamp, $nonce, '', $remote['secret']);

    try {
      $response = $this->httpClient->request('GET', rtrim($remote['uri'], '/') . $path . $queryString, [
        'headers' => [
          'X-Config-Pull-Timestamp' => $timestamp,
          'X-Config-Pull-Nonce' => $nonce,
          'X-Config-Pull-Signature' => $signature,
        ],
        'timeout' => $remote['timeout'],
        'verify' => $remote['verify_ssl'],
        'http_errors' => TRUE,
      ]);
    }
    catch (ClientException | ServerException | ConnectException $e) {
      throw $this->convertException($e, $path);
    }

    return [
      'yaml' => (string) $response->getBody(),
      'hash' => $response->getHeaderLine('X-Config-Hash'),
    ];
  }

  public function item(string $remoteName, string $name): array {
    $remote = $this->getRemoteConfig($remoteName);
    $nonce = bin2hex(random_bytes(32));
    $timestamp = (string) time();
    $path = '/config-pull/item/' . $name;
    $signature = $this->computeHmac('GET', $path, $timestamp, $nonce, '', $remote['secret']);

    try {
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
    }
    catch (ClientException | ServerException | ConnectException $e) {
      throw $this->convertException($e, $path);
    }

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

    try {
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
    }
    catch (ClientException | ServerException | ConnectException $e) {
      throw $this->convertException($e, $path);
    }

    return (string) $response->getBody();
  }

  public function exportFull(string $remoteName): string {
    $remote = $this->getRemoteConfig($remoteName);
    $path = '/config-pull/export/full';
    $nonce = bin2hex(random_bytes(32));
    $timestamp = (string) time();
    $signature = $this->computeHmac('GET', $path, $timestamp, $nonce, '', $remote['secret']);

    try {
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
    }
    catch (ClientException | ServerException | ConnectException $e) {
      throw $this->convertException($e, $path);
    }

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
    catch (ClientException | ServerException | ConnectException $e) {
      throw $this->convertException($e, $path);
    }

    if ($allow304 && $response->getStatusCode() === 304) {
      return NULL;
    }

    return json_decode((string) $response->getBody(), TRUE);
  }

  private function convertException(\Throwable $e, string $path): \RuntimeException {
    if ($e instanceof ClientException) {
      $status = $e->getResponse()->getStatusCode();
      $errorBody = (string) $e->getResponse()->getBody();
      $errorData = json_decode($errorBody, TRUE) ?? [];
      $errorMsg = $errorData['error'] ?? 'unknown_error';
      if ($status === 401 || $status === 403) {
        return new RemoteAuthenticationException("Server returned $status: $errorMsg", $status, $e);
      }
      if ($status === 429) {
        $retryAfter = (int) ($errorData['retry_after'] ?? 0);
        return new RemoteRateLimitException("Server returned 429: $errorMsg", $retryAfter, $status, $e);
      }
      return new RemoteServerException("Server returned $status: $errorMsg", $status, $e);
    }
    if ($e instanceof ServerException) {
      $status = $e->getResponse()->getStatusCode();
      return new RemoteServerException("Server error $status on $path", $status, $e);
    }
    if ($e instanceof ConnectException) {
      return new RemoteNetworkException("Connection failed: " . $e->getMessage(), 0, $e);
    }
    return new \RuntimeException($e->getMessage(), 0, $e);
  }

  private function computeHmac(string $method, string $path, string $timestamp, string $nonce, string $body, string $secret): string {
    $payload = implode("\n", [$method, $path, $timestamp, $nonce, $body]);
    return hash_hmac('sha256', $payload, $secret);
  }

}
