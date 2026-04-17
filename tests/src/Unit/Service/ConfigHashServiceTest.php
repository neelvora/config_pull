<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\config_pull\Service\ConfigHashService;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\config_pull\Service\ConfigHashService
 * @group config_pull
 */
final class ConfigHashServiceTest extends TestCase {

  private ConfigHashService $service;

  protected function setUp(): void {
    parent::setUp();
    $this->service = new ConfigHashService();
  }

  /**
   * @covers ::hash
   */
  public function testHashProducesSha256OfYamlEncoded(): void {
    $data = ['name' => 'Test site', 'slogan' => ''];
    $expected = hash('sha256', Yaml::encode($data));
    $this->assertSame($expected, $this->service->hash($data));
  }

  /**
   * @covers ::hash
   */
  public function testHashIsDeterministic(): void {
    $data = ['key' => 'value', 'nested' => ['a' => 1, 'b' => 2]];
    $this->assertSame($this->service->hash($data), $this->service->hash($data));
  }

  /**
   * @covers ::hash
   */
  public function testHashDiffersForDifferentData(): void {
    $a = ['key' => 'value_a'];
    $b = ['key' => 'value_b'];
    $this->assertNotSame($this->service->hash($a), $this->service->hash($b));
  }

  /**
   * @covers ::hash
   */
  public function testEmptyArray(): void {
    $expected = hash('sha256', Yaml::encode([]));
    $this->assertSame($expected, $this->service->hash([]));
  }

  /**
   * @covers ::hashMultiple
   */
  public function testHashMultiple(): void {
    $items = [
      'system.site' => ['name' => 'Test'],
      'system.date' => ['timezone' => ['default' => 'UTC']],
    ];
    $result = $this->service->hashMultiple($items);

    $this->assertCount(2, $result);
    $this->assertArrayHasKey('system.site', $result);
    $this->assertArrayHasKey('system.date', $result);
    $this->assertSame($this->service->hash($items['system.site']), $result['system.site']);
    $this->assertSame($this->service->hash($items['system.date']), $result['system.date']);
  }

  /**
   * @covers ::hashMultiple
   */
  public function testHashMultipleEmpty(): void {
    $this->assertSame([], $this->service->hashMultiple([]));
  }

  /**
   * @covers ::hashYaml
   */
  public function testHashYaml(): void {
    $yaml = "name: Test\nslogan: ''\n";
    $expected = hash('sha256', $yaml);
    $this->assertSame($expected, $this->service->hashYaml($yaml));
  }

  /**
   * @covers ::hash
   */
  public function testHashMatchesManualYamlEncodeThenSha256(): void {
    $data = [
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => ['module' => ['node', 'user']],
    ];
    $yaml = Yaml::encode($data);
    $manualHash = hash('sha256', $yaml);
    $this->assertSame($manualHash, $this->service->hash($data));
  }

  /**
   * @covers ::hashYaml
   */
  public function testHashYamlReturnsSixtyFourHexChars(): void {
    $hash = $this->service->hashYaml('test');
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
  }

}
