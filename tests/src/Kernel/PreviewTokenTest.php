<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\consumer_node_preview\Plugin\Validation\Constraint\PreviewTargetUrlConstraint;
use Drupal\consumer_node_preview\Plugin\Validation\Constraint\PreviewTargetUrlConstraintValidator;
use Drupal\consumer_node_preview\PreviewToken;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Tests the preview token and the target host allowlist.
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class PreviewTokenTest extends KernelTestBase {

  private const UUID = '639d65b0-6903-53e7-8b5c-4c1d3b4a1953';

  /**
   * Returns a token service for user 7, at a moment in time.
   */
  private function tokens(int $now): PreviewToken {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(7);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($now);
    return new PreviewToken($account, $this->container->get('private_key'), $time);
  }

  /**
   * Tests that a token states its user and node, and lasts its lifetime.
   */
  public function testRoundTripAndExpiry(): void {
    $token = $this->tokens(1000)->create(self::UUID);
    $this->assertStringStartsWith(PreviewToken::PREFIX, $token);
    $this->assertSame(['uid' => 7, 'uuid' => self::UUID], $this->tokens(1899)->read($token));
    $this->assertNull($this->tokens(1900)->read($token));
  }

  /**
   * Tests that a changed payload, signature or prefix is refused.
   */
  public function testForgeries(): void {
    $tokens = $this->tokens(1000);
    $token = $tokens->create(self::UUID);
    [, $payload, $signature] = explode('.', $token);
    $other = rtrim(strtr(base64_encode((string) json_encode(['u' => 1, 'n' => self::UUID, 'e' => 9999])), '+/', '-_'), '=');
    $this->assertNull($tokens->read(PreviewToken::PREFIX . $other . '.' . $signature));
    $this->assertNull($tokens->read(PreviewToken::PREFIX . $payload . '.x'));
    $this->assertNull($tokens->read($payload . '.' . $signature));
    $this->assertNull($tokens->read(PreviewToken::PREFIX . $payload));
  }

  /**
   * Tests what the host allowlist admits.
   */
  public function testAllowedHosts(): void {
    $check = function (string $url): bool {
      $context = $this->createMock(ExecutionContextInterface::class);
      $violations = 0;
      $context->method('addViolation')->willReturnCallback(function () use (&$violations): void {
        $violations++;
      });
      $validator = new PreviewTargetUrlConstraintValidator();
      $validator->initialize($context);
      $validator->validate($url, new PreviewTargetUrlConstraint());
      return $violations === 0;
    };
    $this->assertTrue($check('https://anywhere.example/p'), 'Any host is allowed without the setting.');

    new Settings(Settings::getAll() + ['consumer_node_preview' => ['allowed_target_hosts' => ['example.com', '*.preview.example.com']]]);
    $this->assertTrue($check('https://example.com/p'));
    $this->assertTrue($check('https://a.preview.example.com/p'));
    $this->assertTrue($check('https://a.b.preview.example.com/p'));
    $this->assertFalse($check('https://preview.example.com/p'), 'A pattern leaves out the bare domain.');
    $this->assertFalse($check('https://evilpreview.example.com/p'));
    $this->assertFalse($check('https://a.preview.example.com.evil.test/p'));
  }

}
