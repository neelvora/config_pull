<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit;

use Drupal\Core\Site\Settings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 *
 */
#[Group('config_pull')]
class RequirementsTest extends TestCase {

  protected function setUp(): void {
    parent::setUp();
    if (!defined('REQUIREMENT_INFO')) {
      define('REQUIREMENT_INFO', -1);
      define('REQUIREMENT_OK', 0);
      define('REQUIREMENT_WARNING', 1);
      define('REQUIREMENT_ERROR', 2);
    }
    require_once dirname(__DIR__, 3) . '/config_pull.install';
  }

  public function testInstallPhaseReturnsEmpty(): void {
    new Settings([]);
    $result = config_pull_requirements('install');
    $this->assertSame([], $result);
  }

  public function testServerDisabled(): void {
    new Settings(['config_pull' => []]);
    $result = config_pull_requirements('runtime');
    $this->assertArrayHasKey('config_pull_server', $result);
    $this->assertSame(REQUIREMENT_INFO, $result['config_pull_server']['severity']);
    $this->assertArrayNotHasKey('config_pull_secret', $result);
  }

  public function testMissingSecret(): void {
    new Settings(['config_pull' => ['server_enabled' => TRUE]]);
    $result = config_pull_requirements('runtime');
    $this->assertSame(REQUIREMENT_OK, $result['config_pull_server']['severity']);
    $this->assertArrayHasKey('config_pull_secret', $result);
    $this->assertSame(REQUIREMENT_ERROR, $result['config_pull_secret']['severity']);
  }

  public function testSecretTooShort(): void {
    new Settings(['config_pull' => ['server_enabled' => TRUE, 'secret' => 'short']]);
    $result = config_pull_requirements('runtime');
    $this->assertSame(REQUIREMENT_ERROR, $result['config_pull_secret']['severity']);
  }

  public function testPlaceholderSecret(): void {
    new Settings(['config_pull' => ['server_enabled' => TRUE, 'secret' => 'changeme']]);
    $result = config_pull_requirements('runtime');
    $this->assertSame(REQUIREMENT_ERROR, $result['config_pull_secret']['severity']);
  }

  public function testValidSecretNoIpAllowlist(): void {
    new Settings(['config_pull' => [
      'server_enabled' => TRUE,
      'secret' => str_repeat('a', 64),
    ]]);
    $result = config_pull_requirements('runtime');
    $this->assertSame(REQUIREMENT_OK, $result['config_pull_secret']['severity']);
    $this->assertSame(REQUIREMENT_WARNING, $result['config_pull_ip']['severity']);
  }

  public function testFullyConfigured(): void {
    new Settings(['config_pull' => [
      'server_enabled' => TRUE,
      'secret' => str_repeat('a', 64),
      'allowed_ips' => ['10.0.0.0/8'],
      'redact' => ['*.secret_key' => TRUE],
    ]]);
    $result = config_pull_requirements('runtime');
    $this->assertSame(REQUIREMENT_OK, $result['config_pull_server']['severity']);
    $this->assertSame(REQUIREMENT_OK, $result['config_pull_secret']['severity']);
    $this->assertSame(REQUIREMENT_OK, $result['config_pull_ip']['severity']);
    $this->assertSame(REQUIREMENT_OK, $result['config_pull_redaction']['severity']);
  }

  public function testNoRedactionRulesWarning(): void {
    new Settings(['config_pull' => [
      'server_enabled' => TRUE,
      'secret' => str_repeat('a', 64),
      'allowed_ips' => ['10.0.0.0/8'],
    ]]);
    $result = config_pull_requirements('runtime');
    $this->assertSame(REQUIREMENT_WARNING, $result['config_pull_redaction']['severity']);
  }

}
