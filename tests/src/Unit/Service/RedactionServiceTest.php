<?php

declare(strict_types=1);

namespace Drupal\Tests\config_pull\Unit\Service;

use Drupal\config_pull\Service\RedactionService;
use Drupal\Core\Site\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\config_pull\Service\RedactionService
 * @group config_pull
 */
final class RedactionServiceTest extends TestCase {

  private RedactionService $service;

  protected function setUp(): void {
    parent::setUp();
    $this->service = new RedactionService();
  }

  private function setRedactionRules(array $rules): void {
    new Settings(['config_pull' => ['redact' => $rules]]);
  }

  /**
   * @covers ::redact
   */
  public function testNoRulesReturnsDataUnchanged(): void {
    $this->setRedactionRules([]);
    $data = ['smtp_password' => 'secret123', 'smtp_host' => 'mail.example.com'];
    $this->assertSame($data, $this->service->redact('smtp.settings', $data));
  }

  /**
   * @covers ::redact
   */
  public function testExactConfigNameMatch(): void {
    $this->setRedactionRules([
      'smtp.settings' => ['smtp_password'],
    ]);
    $data = ['smtp_password' => 'secret123', 'smtp_host' => 'mail.example.com'];
    $result = $this->service->redact('smtp.settings', $data);

    $this->assertSame(RedactionService::REDACTED, $result['smtp_password']);
    $this->assertSame('mail.example.com', $result['smtp_host']);
  }

  /**
   * @covers ::redact
   */
  public function testWildcardConfigNameMatch(): void {
    $this->setRedactionRules([
      'key.key.*' => ['key_value'],
    ]);
    $data = ['key_value' => 'abc', 'key_label' => 'My Key'];
    $result = $this->service->redact('key.key.my_api_key', $data);

    $this->assertSame(RedactionService::REDACTED, $result['key_value']);
    $this->assertSame('My Key', $result['key_label']);
  }

  /**
   * @covers ::redact
   */
  public function testWildcardKeyPattern(): void {
    $this->setRedactionRules([
      'system.mail' => ['*password*'],
    ]);
    $data = [
      'smtp_password' => 'secret',
      'imap_password_backup' => 'also_secret',
      'host' => 'mail.example.com',
    ];
    $result = $this->service->redact('system.mail', $data);

    $this->assertSame(RedactionService::REDACTED, $result['smtp_password']);
    $this->assertSame(RedactionService::REDACTED, $result['imap_password_backup']);
    $this->assertSame('mail.example.com', $result['host']);
  }

  /**
   * @covers ::redact
   */
  public function testNestedKeyRedaction(): void {
    $this->setRedactionRules([
      'smtp.settings' => ['password'],
    ]);
    $data = [
      'connection' => [
        'host' => 'mail.example.com',
        'password' => 'nested_secret',
      ],
      'password' => 'top_level_secret',
    ];
    $result = $this->service->redact('smtp.settings', $data);

    $this->assertSame(RedactionService::REDACTED, $result['password']);
    $this->assertSame(RedactionService::REDACTED, $result['connection']['password']);
    $this->assertSame('mail.example.com', $result['connection']['host']);
  }

  /**
   * @covers ::redact
   */
  public function testNonMatchingConfigNameReturnsUnchanged(): void {
    $this->setRedactionRules([
      'smtp.settings' => ['smtp_password'],
    ]);
    $data = ['api_key' => 'abc123'];
    $this->assertSame($data, $this->service->redact('other.config', $data));
  }

  /**
   * @covers ::redact
   */
  public function testMultipleRulesApply(): void {
    $this->setRedactionRules([
      'smtp.settings' => ['smtp_password'],
      'smtp.*' => ['api_key'],
    ]);
    $data = ['smtp_password' => 'p', 'api_key' => 'k', 'host' => 'h'];
    $result = $this->service->redact('smtp.settings', $data);

    $this->assertSame(RedactionService::REDACTED, $result['smtp_password']);
    $this->assertSame(RedactionService::REDACTED, $result['api_key']);
    $this->assertSame('h', $result['host']);
  }

  /**
   * @covers ::shouldRedactEntirely
   */
  public function testShouldRedactEntirelyTrue(): void {
    $this->setRedactionRules([
      'mailchimp.settings' => TRUE,
    ]);
    $this->assertTrue($this->service->shouldRedactEntirely('mailchimp.settings'));
  }

  /**
   * @covers ::shouldRedactEntirely
   */
  public function testShouldRedactEntirelyFalseForKeyRules(): void {
    $this->setRedactionRules([
      'smtp.settings' => ['smtp_password'],
    ]);
    $this->assertFalse($this->service->shouldRedactEntirely('smtp.settings'));
  }

  /**
   * @covers ::shouldRedactEntirely
   */
  public function testShouldRedactEntirelyWildcard(): void {
    $this->setRedactionRules([
      'secret_module.*' => TRUE,
    ]);
    $this->assertTrue($this->service->shouldRedactEntirely('secret_module.api_config'));
    $this->assertFalse($this->service->shouldRedactEntirely('other_module.settings'));
  }

  /**
   * @covers ::shouldRedactEntirely
   */
  public function testShouldRedactEntirelyNoRules(): void {
    $this->setRedactionRules([]);
    $this->assertFalse($this->service->shouldRedactEntirely('anything'));
  }

  /**
   * @covers ::redact
   */
  public function testEntireRedactionRuleSkippedByRedactMethod(): void {
    $this->setRedactionRules([
      'mailchimp.settings' => TRUE,
    ]);
    $data = ['api_key' => 'abc'];
    // redact() only handles key-level rules. Full-item redaction
    // is handled by the caller via shouldRedactEntirely().
    $this->assertSame($data, $this->service->redact('mailchimp.settings', $data));
  }

  /**
   * @covers ::redact
   */
  public function testEmptyDataArray(): void {
    $this->setRedactionRules([
      'smtp.settings' => ['password'],
    ]);
    $this->assertSame([], $this->service->redact('smtp.settings', []));
  }

}
