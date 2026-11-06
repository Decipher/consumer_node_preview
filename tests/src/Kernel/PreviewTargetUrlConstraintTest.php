<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\consumer_node_preview\Plugin\Validation\Constraint\PreviewTargetUrlConstraint;
use Drupal\consumer_node_preview\Plugin\Validation\Constraint\PreviewTargetUrlConstraintValidator;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Tests the preview target URL constraint and the host allowlist.
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class PreviewTargetUrlConstraintTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'filter',
    'text',
    'node',
    'serialization',
    'jsonapi',
    'jsonapi_node_preview',
    'consumers',
    'consumer_node_preview',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['consumer_node_preview']);
  }

  /**
   * Tests that the constraint still takes its groups by position.
   */
  public function testConstraintArgumentOrder(): void {
    $constraint = new PreviewTargetUrlConstraint(NULL, 'Custom message', ['Custom']);
    $this->assertSame('Custom message', $constraint->message);
    $this->assertSame(['Custom'], $constraint->groups);
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
      $validator = PreviewTargetUrlConstraintValidator::create($this->container);
      $validator->initialize($context);
      $validator->validate($url, new PreviewTargetUrlConstraint());
      return $violations === 0;
    };
    $this->assertTrue($check('https://anywhere.example/p'), 'Any host is allowed without the setting.');
    // PHP reads the host after the backslash, and a browser before it.
    $this->assertFalse($check('https://evil.test\\@example.com/p'), 'A backslash is refused.');

    $this->config('consumer_node_preview.settings')
      ->set('allowed_target_hosts', ['example.com', '*.preview.example.com'])
      ->save();
    $this->assertTrue($check('https://example.com/p'));
    $this->assertTrue($check('https://a.preview.example.com/p'));
    $this->assertTrue($check('https://a.b.preview.example.com/p'));
    $this->assertFalse($check('https://preview.example.com/p'), 'A pattern leaves out the bare domain.');
    $this->assertFalse($check('https://evilpreview.example.com/p'));
    $this->assertFalse($check('https://a.preview.example.com.evil.test/p'));
    $this->assertFalse($check('https://evil.test\\@example.com/p'));
  }

}
