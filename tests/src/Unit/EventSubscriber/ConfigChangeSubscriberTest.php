<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\EventSubscriber;

use Drupal\config_pull\EventSubscriber\ConfigChangeSubscriber;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigRenameEvent;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 *
 */
#[CoversClass(ConfigChangeSubscriber::class)]
#[Group('config_pull')]
final class ConfigChangeSubscriberTest extends TestCase {

  private CacheTagsInvalidatorInterface $cacheTagsInvalidator;
  private StateInterface $state;
  private ConfigChangeSubscriber $subscriber;

  protected function setUp(): void {
    parent::setUp();
    $this->cacheTagsInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $this->state = $this->createMock(StateInterface::class);
    $this->subscriber = new ConfigChangeSubscriber(
    $this->cacheTagsInvalidator,
    $this->state,
    );
  }

  public function testSubscribedEvents(): void {
    $events = ConfigChangeSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(ConfigEvents::SAVE, $events);
    $this->assertArrayHasKey(ConfigEvents::DELETE, $events);
    $this->assertArrayHasKey(ConfigEvents::RENAME, $events);
  }

  public function testSaveInvalidatesTagsAndBumpsVersion(): void {
    $this->state->method('get')
      ->with('config_pull.hash_version')
      ->willReturn(3);

    $this->cacheTagsInvalidator->expects($this->once())
      ->method('invalidateTags')
      ->with(['config_pull_hashes']);

    $this->state->expects($this->once())
      ->method('set')
      ->with('config_pull.hash_version', 4);

    $event = $this->createMock(ConfigCrudEvent::class);
    $this->subscriber->onConfigChange($event);
  }

  public function testDeleteInvalidatesTagsAndBumpsVersion(): void {
    $this->state->method('get')->willReturn(0);

    $this->cacheTagsInvalidator->expects($this->once())
      ->method('invalidateTags')
      ->with(['config_pull_hashes']);

    $this->state->expects($this->once())
      ->method('set')
      ->with('config_pull.hash_version', 1);

    $event = $this->createMock(ConfigCrudEvent::class);
    $this->subscriber->onConfigChange($event);
  }

  public function testRenameInvalidatesTagsAndBumpsVersion(): void {
    $this->state->method('get')->willReturn(10);

    $this->cacheTagsInvalidator->expects($this->once())
      ->method('invalidateTags')
      ->with(['config_pull_hashes']);

    $this->state->expects($this->once())
      ->method('set')
      ->with('config_pull.hash_version', 11);

    $event = $this->createMock(ConfigRenameEvent::class);
    $this->subscriber->onConfigRename($event);
  }

  public function testVersionDefaultsToZeroWhenNotSet(): void {
    $this->state->method('get')->willReturn(NULL);

    $this->state->expects($this->once())
      ->method('set')
      ->with('config_pull.hash_version', 1);

    $event = $this->createMock(ConfigCrudEvent::class);
    $this->subscriber->onConfigChange($event);
  }

}
