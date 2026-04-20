<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\config_pull\Service\ConfigDiffService;
use Drupal\config_pull\Service\ConfigHashService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 *
 */
#[CoversClass(ConfigDiffService::class)]
#[Group('config_pull')]
final class ConfigDiffServiceTest extends TestCase {

  private ConfigDiffService $service;

  private ConfigHashService $hashService;

  private string $tempDir;

  /**
   *
   */
  protected function setUp(): void {
    parent::setUp();
    $this->hashService = new ConfigHashService();
    $this->service = new ConfigDiffService($this->hashService);
    $this->tempDir = sys_get_temp_dir() . '/config_pull_diff_test_' . uniqid();
    mkdir($this->tempDir, 0755, TRUE);
  }

  /**
   *
   */
  protected function tearDown(): void {
    $files = glob($this->tempDir . '/*');
    foreach ($files as $file) {
      unlink($file);
    }
    rmdir($this->tempDir);
    parent::tearDown();
  }

  /**
   *
   */
  private function writeYml(string $name, array $data): void {
    file_put_contents($this->tempDir . '/' . $name . '.yml', Yaml::encode($data));
  }

  /**
   *
   */
  public function testComputeLocalHashesReturnsHashesForAllFiles(): void {
    $this->writeYml('system.site', ['name' => 'Test']);
    $this->writeYml('system.date', ['timezone' => 'UTC']);

    $hashes = $this->service->computeLocalHashes($this->tempDir);
    $this->assertCount(2, $hashes);
    $this->assertArrayHasKey('system.date', $hashes);
    $this->assertArrayHasKey('system.site', $hashes);
    $this->assertSame(64, strlen($hashes['system.site']));
  }

  /**
   *
   */
  public function testComputeLocalHashesWithFilter(): void {
    $this->writeYml('system.site', ['name' => 'Test']);
    $this->writeYml('system.date', ['timezone' => 'UTC']);
    $this->writeYml('node.settings', ['use_admin_theme' => TRUE]);

    $hashes = $this->service->computeLocalHashes($this->tempDir, 'system.*');
    $this->assertCount(2, $hashes);
    $this->assertArrayHasKey('system.site', $hashes);
    $this->assertArrayHasKey('system.date', $hashes);
    $this->assertArrayNotHasKey('node.settings', $hashes);
  }

  /**
   *
   */
  public function testComputeLocalHashesReturnsConsistentHash(): void {
    $data = ['name' => 'Test', 'slogan' => ''];
    $this->writeYml('system.site', $data);

    $expected = $this->hashService->hash($data);
    $hashes = $this->service->computeLocalHashes($this->tempDir);
    $this->assertSame($expected, $hashes['system.site']);
  }

  /**
   *
   */
  public function testComputeLocalHashesReturnsSortedKeys(): void {
    $this->writeYml('z.config', ['a' => 1]);
    $this->writeYml('a.config', ['b' => 2]);
    $this->writeYml('m.config', ['c' => 3]);

    $hashes = $this->service->computeLocalHashes($this->tempDir);
    $keys = array_keys($hashes);
    $this->assertSame(['a.config', 'm.config', 'z.config'], $keys);
  }

  /**
   *
   */
  public function testComputeLocalHashesThrowsForMissingDir(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->service->computeLocalHashes('/nonexistent/path');
  }

  /**
   *
   */
  public function testComputeLocalHashesSkipsInvalidYaml(): void {
    $this->writeYml('good.config', ['key' => 'value']);
    file_put_contents($this->tempDir . '/bad.yml', 'just a string');

    $hashes = $this->service->computeLocalHashes($this->tempDir);
    $this->assertCount(1, $hashes);
    $this->assertArrayHasKey('good.config', $hashes);
  }

  /**
   *
   */
  public function testComputeLocalHashesEmptyDir(): void {
    $hashes = $this->service->computeLocalHashes($this->tempDir);
    $this->assertSame([], $hashes);
  }

  /**
   *
   */
  public function testBuildDiffResultPassesThroughServerResponse(): void {
    $serverResponse = [
      'new' => ['a.b' => 'hash1'],
      'changed' => ['c.d' => 'hash2'],
      'deleted' => ['e.f'],
      'unchanged_count' => 10,
    ];
    $result = $this->service->buildDiffResult(['c.d' => 'old'], $serverResponse);
    $this->assertSame(['a.b' => 'hash1'], $result['new']);
    $this->assertSame(['c.d' => 'hash2'], $result['changed']);
    $this->assertSame(['e.f'], $result['deleted']);
    $this->assertSame(10, $result['unchanged_count']);
  }

  /**
   *
   */
  public function testBuildDiffResultDefaultsMissingKeys(): void {
    $result = $this->service->buildDiffResult([], []);
    $this->assertSame([], $result['new']);
    $this->assertSame([], $result['changed']);
    $this->assertSame([], $result['deleted']);
    $this->assertSame(0, $result['unchanged_count']);
  }

  /**
   *
   */
  public function testComputeLocalHashesWithExcludeFilter(): void {
    $this->writeYml('system.site', ['name' => 'Test']);
    $this->writeYml('system.date', ['timezone' => 'UTC']);
    $this->writeYml('node.settings', ['use_admin_theme' => TRUE]);

    $hashes = $this->service->computeLocalHashes($this->tempDir, NULL, 'node.*');
    $this->assertCount(2, $hashes);
    $this->assertArrayHasKey('system.site', $hashes);
    $this->assertArrayNotHasKey('node.settings', $hashes);
  }

  /**
   *
   */
  public function testComputeLocalHashesOnlyAndExcludeCombined(): void {
    $this->writeYml('system.site', ['name' => 'Test']);
    $this->writeYml('system.date', ['timezone' => 'UTC']);
    $this->writeYml('system.performance', ['cache' => TRUE]);
    $this->writeYml('node.settings', ['use_admin_theme' => TRUE]);

    $hashes = $this->service->computeLocalHashes($this->tempDir, 'system.*', 'system.performance');
    $this->assertCount(2, $hashes);
    $this->assertArrayHasKey('system.site', $hashes);
    $this->assertArrayHasKey('system.date', $hashes);
    $this->assertArrayNotHasKey('system.performance', $hashes);
  }

  /**
   *
   */
  public function testFilterDiffResultWithOnlyPattern(): void {
    $diff = [
      'new' => ['system.site' => 'h1', 'node.type.page' => 'h2'],
      'changed' => ['system.date' => 'h3', 'views.view.content' => 'h4'],
      'deleted' => ['system.performance', 'field.field.node'],
      'unchanged_count' => 50,
    ];
    $result = $this->service->filterDiffResult($diff, 'system.*');
    $this->assertSame(['system.site' => 'h1'], $result['new']);
    $this->assertSame(['system.date' => 'h3'], $result['changed']);
    $this->assertSame(['system.performance'], $result['deleted']);
    $this->assertSame(50, $result['unchanged_count']);
  }

  /**
   *
   */
  public function testFilterDiffResultWithExcludePattern(): void {
    $diff = [
      'new' => ['system.site' => 'h1', 'node.type.page' => 'h2'],
      'changed' => ['system.date' => 'h3'],
      'deleted' => ['views.view.content'],
      'unchanged_count' => 50,
    ];
    $result = $this->service->filterDiffResult($diff, NULL, 'views.*');
    $this->assertSame(['system.site' => 'h1', 'node.type.page' => 'h2'], $result['new']);
    $this->assertSame(['system.date' => 'h3'], $result['changed']);
    $this->assertSame([], $result['deleted']);
  }

  /**
   *
   */
  public function testFilterDiffResultNoFiltersReturnsUnchanged(): void {
    $diff = [
      'new' => ['a' => 'h1'],
      'changed' => ['b' => 'h2'],
      'deleted' => ['c'],
      'unchanged_count' => 10,
    ];
    $this->assertSame($diff, $this->service->filterDiffResult($diff));
  }

  /**
   *
   */
  public function testFilterDiffResultOnlyWithNoMatch(): void {
    $diff = [
      'new' => ['system.site' => 'h1'],
      'changed' => ['system.date' => 'h2'],
      'deleted' => ['system.performance'],
      'unchanged_count' => 10,
    ];
    $result = $this->service->filterDiffResult($diff, 'webform.*');
    $this->assertSame([], $result['new']);
    $this->assertSame([], $result['changed']);
    $this->assertSame([], $result['deleted']);
  }

}
