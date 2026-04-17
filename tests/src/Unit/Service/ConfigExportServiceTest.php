<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\config_pull\Service\ConfigExportService;
use Drupal\config_pull\Service\ConfigHashService;
use Drupal\config_pull\Service\RedactionService;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Site\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\config_pull\Service\ConfigExportService
 * @group config_pull
 */
final class ConfigExportServiceTest extends TestCase {

  private ConfigExportService $service;

  private StorageInterface $storage;

  protected function setUp(): void {
    parent::setUp();
    new Settings(['config_pull' => ['redact' => []]]);
    $this->storage = $this->createMock(StorageInterface::class);
    $this->service = new ConfigExportService(
      $this->storage,
      new ConfigHashService(),
      new RedactionService(),
    );
  }

  /**
   * @covers ::getConfigCount
   */
  public function testGetConfigCount(): void {
    $this->storage->method('listAll')->willReturn([
      'system.site',
      'system.date',
      'node.settings',
    ]);
    $this->assertSame(3, $this->service->getConfigCount());
  }

  /**
   * @covers ::listConfigNames
   */
  public function testListConfigNamesExcludesFullyRedacted(): void {
    new Settings(['config_pull' => ['redact' => [
      'secret.config' => TRUE,
    ]]]);
    $this->storage->method('listAll')->willReturn([
      'system.site',
      'secret.config',
      'node.settings',
    ]);
    $names = $this->service->listConfigNames();
    $this->assertSame(['system.site', 'node.settings'], $names);
  }

  /**
   * @covers ::listConfigNames
   */
  public function testListConfigNamesNoRedaction(): void {
    $this->storage->method('listAll')->willReturn(['system.site', 'system.date']);
    $this->assertSame(['system.site', 'system.date'], $this->service->listConfigNames());
  }

  /**
   * @covers ::computeAllHashes
   */
  public function testComputeAllHashes(): void {
    $items = [
      'system.site' => ['name' => 'Test'],
      'system.date' => ['timezone' => ['default' => 'UTC']],
    ];
    $this->storage->method('listAll')->willReturn(array_keys($items));
    $this->storage->method('readMultiple')->willReturn($items);

    $hashes = $this->service->computeAllHashes();

    $this->assertCount(2, $hashes);
    $hashService = new ConfigHashService();
    $this->assertSame($hashService->hash($items['system.site']), $hashes['system.site']);
  }

  /**
   * @covers ::computeAllHashes
   */
  public function testComputeAllHashesWithRedaction(): void {
    new Settings(['config_pull' => ['redact' => [
      'smtp.settings' => ['smtp_password'],
    ]]]);
    $items = [
      'smtp.settings' => ['smtp_host' => 'mail.test', 'smtp_password' => 'secret'],
      'system.site' => ['name' => 'Test'],
    ];
    $this->storage->method('listAll')->willReturn(array_keys($items));
    $this->storage->method('readMultiple')->willReturn($items);

    $hashes = $this->service->computeAllHashes();

    $hashService = new ConfigHashService();
    $redacted = ['smtp_host' => 'mail.test', 'smtp_password' => 'CONFIG_PULL_REDACTED'];
    $this->assertSame($hashService->hash($redacted), $hashes['smtp.settings']);
  }

  /**
   * @covers ::getItem
   */
  public function testGetItemReturnsRedactedData(): void {
    new Settings(['config_pull' => ['redact' => [
      'smtp.settings' => ['smtp_password'],
    ]]]);
    $this->storage->method('read')
      ->with('smtp.settings')
      ->willReturn(['smtp_host' => 'h', 'smtp_password' => 'secret']);

    $result = $this->service->getItem('smtp.settings');
    $this->assertSame('CONFIG_PULL_REDACTED', $result['smtp_password']);
    $this->assertSame('h', $result['smtp_host']);
  }

  /**
   * @covers ::getItem
   */
  public function testGetItemReturnsNullForMissing(): void {
    $this->storage->method('read')->willReturn(FALSE);
    $this->assertNull($this->service->getItem('nonexistent'));
  }

  /**
   * @covers ::getItem
   */
  public function testGetItemReturnsNullForFullyRedacted(): void {
    new Settings(['config_pull' => ['redact' => [
      'secret.config' => TRUE,
    ]]]);
    $this->assertNull($this->service->getItem('secret.config'));
  }

  /**
   * @covers ::getItems
   */
  public function testGetItemsExcludesFullyRedacted(): void {
    new Settings(['config_pull' => ['redact' => [
      'secret.config' => TRUE,
    ]]]);
    $this->storage->method('readMultiple')
      ->with(['system.site'])
      ->willReturn(['system.site' => ['name' => 'Test']]);

    $result = $this->service->getItems(['system.site', 'secret.config']);
    $this->assertCount(1, $result);
    $this->assertArrayHasKey('system.site', $result);
  }

  /**
   * @covers ::getAllItems
   */
  public function testGetAllItems(): void {
    $items = [
      'system.site' => ['name' => 'Test'],
      'system.date' => ['timezone' => ['default' => 'UTC']],
    ];
    $this->storage->method('listAll')->willReturn(array_keys($items));
    $this->storage->method('readMultiple')->willReturn($items);

    $result = $this->service->getAllItems();
    $this->assertSame($items, $result);
  }

  /**
   * @covers ::buildTarGz
   */
  public function testBuildTarGzCreatesValidArchive(): void {
    $items = [
      'system.site' => ['name' => 'Test'],
      'system.date' => ['timezone' => ['default' => 'UTC']],
    ];

    $path = $this->service->buildTarGz($items);

    $this->assertFileExists($path);
    $this->assertStringEndsWith('.tar.gz', $path);

    $tar = new \Archive_Tar($path, 'gz');
    $contents = $tar->listContent();
    $filenames = array_column($contents, 'filename');
    $this->assertContains('system.site.yml', $filenames);
    $this->assertContains('system.date.yml', $filenames);

    $extracted = $tar->extractInString('system.site.yml');
    $this->assertSame(Yaml::encode(['name' => 'Test']), $extracted);

    @unlink($path);
  }

  /**
   * @covers ::buildTarGz
   */
  public function testBuildTarGzIdempotent(): void {
    $items = ['system.site' => ['name' => 'Test']];
    $path1 = $this->service->buildTarGz($items);
    $path2 = $this->service->buildTarGz($items);

    $this->assertSame(md5_file($path1), md5_file($path2));

    @unlink($path1);
    @unlink($path2);
  }

  /**
   * @covers ::buildTarGz
   */
  public function testBuildTarGzEmptyReturnsPath(): void {
    $path = $this->service->buildTarGz([]);
    // Archive_Tar does not create a file when no entries are added.
    // The controller layer validates items before calling buildTarGz.
    $this->assertStringEndsWith('.tar.gz', $path);
    @unlink($path);
  }

}
