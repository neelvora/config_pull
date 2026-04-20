<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Kernel\EventSubscriber;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

#[Group('config_pull')]
final class ConfigChangeSubscriberTest extends KernelTestBase {

  protected static $modules = ['system', 'config_pull'];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
  }

  public function testSavingConfigBumpsHashVersion(): void {
    $state = $this->container->get('state');
    $before = (int) ($state->get('config_pull.hash_version') ?? 0);

    $this->config('system.site')->set('name', 'Test Change')->save();

    $after = (int) $state->get('config_pull.hash_version');
    $this->assertGreaterThan($before, $after);
  }

  public function testDeletingConfigBumpsHashVersion(): void {
    $state = $this->container->get('state');
    $this->config('system.site')->set('name', 'Before Delete')->save();
    $before = (int) $state->get('config_pull.hash_version');

    $this->config('system.site')->delete();

    $after = (int) $state->get('config_pull.hash_version');
    $this->assertGreaterThan($before, $after);
  }

}
