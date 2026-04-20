<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Kernel\Service;

use Drupal\config_pull\Service\ConfigExportService;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(ConfigExportService::class)]
#[Group('config_pull')]
final class ExportTransformIntegrationTest extends KernelTestBase {

  protected static $modules = ['system', 'config_pull'];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);

    $settings = Settings::getAll();
    $settings['config_pull'] = [
      'server_enabled' => TRUE,
      'secret' => str_repeat('a', 64),
      'allow_insecure' => TRUE,
    ];
    new Settings($settings);
  }

  public function testExportServiceRespectsStorageTransformSubscribers(): void {
    $exportService = $this->container->get('config_pull.config_export');

    $namesBefore = $exportService->listConfigNames();
    $this->assertContains('system.site', $namesBefore);

    $this->container->get('event_dispatcher')->addListener(
      ConfigEvents::STORAGE_TRANSFORM_EXPORT,
      function (StorageTransformEvent $event): void {
        $storage = $event->getStorage();
        $storage->delete('system.site');
      },
      -500,
    );

    $this->resetExportStorage();

    $namesAfter = $exportService->listConfigNames();
    $this->assertNotContains('system.site', $namesAfter);

    $item = $exportService->getItem('system.site');
    $this->assertNull($item);

    $hashes = $exportService->computeAllHashes();
    $this->assertArrayNotHasKey('system.site', $hashes);
  }

  public function testExportServiceRespectsTransformedValues(): void {
    $this->container->get('event_dispatcher')->addListener(
      ConfigEvents::STORAGE_TRANSFORM_EXPORT,
      function (StorageTransformEvent $event): void {
        $storage = $event->getStorage();
        $data = $storage->read('system.site');
        if ($data !== FALSE) {
          $data['name'] = 'Transformed by config_split';
          $storage->write('system.site', $data);
        }
      },
      -500,
    );

    $this->resetExportStorage();

    $exportService = $this->container->get('config_pull.config_export');
    $item = $exportService->getItem('system.site');
    $this->assertNotNull($item);
    $this->assertSame('Transformed by config_split', $item['data']['name']);
  }

  public function testExportServiceRespectsNewItemsFromTransform(): void {
    $this->container->get('event_dispatcher')->addListener(
      ConfigEvents::STORAGE_TRANSFORM_EXPORT,
      function (StorageTransformEvent $event): void {
        $storage = $event->getStorage();
        $storage->write('config_split.injected_item', [
          'label' => 'Injected by split',
          'status' => TRUE,
        ]);
      },
      -500,
    );

    $this->resetExportStorage();

    $exportService = $this->container->get('config_pull.config_export');
    $names = $exportService->listConfigNames();
    $this->assertContains('config_split.injected_item', $names);

    $item = $exportService->getItem('config_split.injected_item');
    $this->assertNotNull($item);
    $this->assertSame('Injected by split', $item['data']['label']);
  }

  private function resetExportStorage(): void {
    $property = new \ReflectionProperty($this->container->get('config.storage.export'), 'storage');
    $property->setValue($this->container->get('config.storage.export'), NULL);
  }

}
