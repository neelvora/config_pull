<?php

declare(strict_types=1);

namespace Drupal\config_pull\EventSubscriber;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigRenameEvent;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Invalidates the config hash cache when configuration changes.
 */
final class ConfigChangeSubscriber implements EventSubscriberInterface {

  private const CACHE_TAG = 'config_pull_hashes';

  private const STATE_VERSION_KEY = 'config_pull.hash_version';

  public function __construct(
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'onConfigChange',
      ConfigEvents::DELETE => 'onConfigChange',
      ConfigEvents::RENAME => 'onConfigRename',
    ];
  }

  /**
   * Reacts to config save or delete.
   */
  public function onConfigChange(ConfigCrudEvent $event): void {
    $this->invalidateAndBump();
  }

  /**
   * Reacts to config rename.
   */
  public function onConfigRename(ConfigRenameEvent $event): void {
    $this->invalidateAndBump();
  }

  /**
   *
   */
  private function invalidateAndBump(): void {
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    $current = (int) ($this->state->get(self::STATE_VERSION_KEY) ?? 0);
    $this->state->set(self::STATE_VERSION_KEY, $current + 1);
  }

}
