<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\Service;

use Drupal\config_pull\Service\RemoteClient;
use Drupal\Core\Site\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoteClient::class)]
#[Group('config_pull')]
final class RemoteClientTest extends TestCase {

  private MockHandler $mockHandler;

  private array $requestHistory = [];

  private RemoteClient $client;

  private const SECRET = 'test-secret-64chars-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

  protected function setUp(): void {
    parent::setUp();
    new Settings([
      'config_pull_remotes' => [
        'staging' => [
          'uri' => 'https://staging.example.com',
          'secret' => self::SECRET,
          'timeout' => 15,
          'verify_ssl' => FALSE,
        ],
      ],
    ]);
    $this->mockHandler = new MockHandler();
    $history = Middleware::history($this->requestHistory);
    $handlerStack = HandlerStack::create($this->mockHandler);
    $handlerStack->push($history);
    $httpClient = new Client(['handler' => $handlerStack]);
    $this->client = new RemoteClient($httpClient);
  }

  public function testGetRemoteConfigReturnsNormalizedConfig(): void {
    $config = $this->client->getRemoteConfig('staging');
    $this->assertSame('https://staging.example.com', $config['uri']);
    $this->assertSame(self::SECRET, $config['secret']);
    $this->assertSame(15, $config['timeout']);
    $this->assertFalse($config['verify_ssl']);
  }

  public function testGetRemoteConfigDefaultsTimeoutAndVerify(): void {
    new Settings([
      'config_pull_remotes' => [
        'prod' => ['uri' => 'https://prod.example.com', 'secret' => 'abc'],
      ],
    ]);
    $config = $this->client->getRemoteConfig('prod');
    $this->assertSame(30, $config['timeout']);
    $this->assertTrue($config['verify_ssl']);
  }

  public function testGetRemoteConfigThrowsForMissingRemote(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("Remote 'nonexistent' is not defined");
    $this->client->getRemoteConfig('nonexistent');
  }

  public function testGetRemoteConfigThrowsForMissingUri(): void {
    new Settings([
      'config_pull_remotes' => [
        'bad' => ['secret' => 'abc'],
      ],
    ]);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("missing 'uri' or 'secret'");
    $this->client->getRemoteConfig('bad');
  }

  public function testHandshakeReturnsDecodedResponse(): void {
    $payload = [
      'server_version' => '1.0.0',
      'protocol_version' => 1,
      'config_count' => 42,
      'hash_version' => 5,
      'supported_features' => ['diff', 'export'],
    ];
    $this->mockHandler->append(new Response(200, [], json_encode($payload)));

    $result = $this->client->handshake('staging');
    $this->assertSame($payload, $result);
  }

  public function testHandshakeSendsCorrectHmacHeaders(): void {
    $this->mockHandler->append(new Response(200, [], '{}'));
    $this->client->handshake('staging');

    $request = $this->requestHistory[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertStringEndsWith('/config-pull/handshake', (string) $request->getUri());
    $this->assertTrue($request->hasHeader('X-Config-Pull-Timestamp'));
    $this->assertTrue($request->hasHeader('X-Config-Pull-Nonce'));
    $this->assertTrue($request->hasHeader('X-Config-Pull-Signature'));
  }

  public function testHandshakeSignatureIsValid(): void {
    $this->mockHandler->append(new Response(200, [], '{}'));
    $this->client->handshake('staging');

    $request = $this->requestHistory[0]['request'];
    $ts = $request->getHeaderLine('X-Config-Pull-Timestamp');
    $nonce = $request->getHeaderLine('X-Config-Pull-Nonce');
    $sig = $request->getHeaderLine('X-Config-Pull-Signature');
    $body = (string) $request->getBody();

    $expected = hash_hmac('sha256', implode("\n", ['POST', '/config-pull/handshake', $ts, $nonce, $body]), self::SECRET);
    $this->assertSame($expected, $sig);
  }

  public function testDiffSendsHashesAndReturnsResult(): void {
    $diffResponse = [
      'new' => ['a.b' => 'hash1'],
      'changed' => ['c.d' => 'hash2'],
      'deleted' => ['e.f'],
      'unchanged_count' => 10,
    ];
    $this->mockHandler->append(new Response(200, [], json_encode($diffResponse)));

    $result = $this->client->diff('staging', ['c.d' => 'oldhash', 'e.f' => 'hash3']);
    $this->assertSame($diffResponse, $result);

    $request = $this->requestHistory[0]['request'];
    $body = json_decode((string) $request->getBody(), TRUE);
    $this->assertSame(['c.d' => 'oldhash', 'e.f' => 'hash3'], $body['hashes']);
  }

  public function testDiffReturnsNullOn304(): void {
    $this->mockHandler->append(new Response(304));
    $result = $this->client->diff('staging', ['a.b' => 'hash']);
    $this->assertNull($result);
  }

  public function testItemReturnsYamlAndHash(): void {
    $yaml = "name: Test\nslogan: ''\n";
    $this->mockHandler->append(new Response(200, ['X-Config-Hash' => 'abc123'], $yaml));

    $result = $this->client->item('staging', 'system.site');
    $this->assertSame($yaml, $result['yaml']);
    $this->assertSame('abc123', $result['hash']);
  }

  public function testItemRequestUsesGetMethod(): void {
    $this->mockHandler->append(new Response(200, ['X-Config-Hash' => 'x'], 'data'));
    $this->client->item('staging', 'system.site');

    $request = $this->requestHistory[0]['request'];
    $this->assertSame('GET', $request->getMethod());
    $this->assertStringEndsWith('/config-pull/item/system.site', (string) $request->getUri());
  }

  public function testExportReturnsTarGzBytes(): void {
    $tarContent = 'fake-tar-gz-bytes';
    $this->mockHandler->append(new Response(200, ['Content-Type' => 'application/gzip'], $tarContent));

    $result = $this->client->export('staging', ['system.site', 'system.date']);
    $this->assertSame($tarContent, $result);

    $request = $this->requestHistory[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $body = json_decode((string) $request->getBody(), TRUE);
    $this->assertSame(['system.site', 'system.date'], $body['names']);
  }

  public function testExportFullReturnsTarGzBytes(): void {
    $tarContent = 'full-tar-gz';
    $this->mockHandler->append(new Response(200, [], $tarContent));

    $result = $this->client->exportFull('staging');
    $this->assertSame($tarContent, $result);

    $request = $this->requestHistory[0]['request'];
    $this->assertSame('GET', $request->getMethod());
    $this->assertStringEndsWith('/config-pull/export/full', (string) $request->getUri());
  }

  public function testAuthenticationFailureThrowsRuntimeException(): void {
    $this->mockHandler->append(new Response(401, [], '{"error":"authentication_failed"}'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('401');
    $this->client->handshake('staging');
  }

  public function testRateLimitThrowsRuntimeException(): void {
    $this->mockHandler->append(new Response(429, [], '{"error":"rate_limited"}'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('429');
    $this->client->handshake('staging');
  }

  public function testServerErrorThrowsRuntimeException(): void {
    $this->mockHandler->append(new Response(503, [], 'Service Unavailable'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('503');
    $this->client->handshake('staging');
  }

  public function testConnectionTimeoutThrowsRuntimeException(): void {
    $this->mockHandler->append(
      new \GuzzleHttp\Exception\ConnectException(
        'Connection timed out',
        new \GuzzleHttp\Psr7\Request('POST', 'https://staging.example.com/config-pull/handshake'),
      ),
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Connection failed');
    $this->client->handshake('staging');
  }

  public function testRequestSetsTimeoutAndVerify(): void {
    $this->mockHandler->append(new Response(200, [], '{}'));
    $this->client->handshake('staging');

    $options = $this->requestHistory[0]['options'];
    $this->assertSame(15, $options['timeout']);
    $this->assertFalse($options['verify']);
  }

}
