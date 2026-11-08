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

    // PHP reads the host before the tokens go in. A token or a user name
    // there could move the host elsewhere once the tokens are filled in.
    $this->assertFalse($check('https://evil.test[jsonapi_node_preview_path]:[preview_token]@x.preview.example.com/'));
    $this->assertFalse($check('https://user:pass@x.preview.example.com/p'));
    $this->assertFalse($check('https://x.preview.example.com:[uuid]/p'));
    $this->assertFalse($check('https://x[layout].preview.example.com/p'));
    $this->assertTrue($check('https://x.preview.example.com/p?vm=[view_mode]&layout=[layout]#[jsonapi_node_preview];token=[preview_token]'));
  }

  /**
   * Tests that the URL's host part takes no user name and no tokens.
   *
   * It holds without the allowlist too, so a URL never moves its host.
   */
  public function testAuthorityIsPlain(): void {
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
    $this->assertFalse($check('https://user@example.com/p'));
    $this->assertFalse($check('https://example.com[jsonapi_node_preview]/p'));
    $this->assertTrue($check('http://[::1]:3000/p#[jsonapi_node_preview_path]'), 'An IPv6 host is not a token.');
    $this->assertTrue($check('http://localhost:3000/p@x#[jsonapi_node_preview_path]'), 'An @ in the path is fine.');

    $provider = $this->container->get('consumer_node_preview.targets_provider');
    $this->assertTrue($provider->hasPlainAuthority('/node/preview?vm=[view_mode]#[jsonapi_node_preview_path]'), 'A path has no host part.');
    $this->assertFalse($provider->hasPlainAuthority('//evil.test[uuid]@example.com/p'), 'A URL without a scheme still has a host part.');
  }

}
