<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\consumer_node_preview\Plugin\Validation\Constraint\PreviewTargetUrlConstraint;
use Drupal\consumer_node_preview\Plugin\Validation\Constraint\PreviewTargetUrlConstraintValidator;
use Drupal\consumer_node_preview\PreviewToken;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Tests the preview token with JWT Path Authentication, and the host allowlist.
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class PreviewTokenTest extends KernelTestBase {

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
    'key',
    'jwt',
    'jwt_path_auth',
  ];

  private const PATH = '/jsonapi/node/page/639d65b0-6903-53e7-8b5c-4c1d3b4a1953/preview';

  /**
   * The editor the tokens are for.
   */
  private UserInterface $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['consumer_node_preview', 'jwt_path_auth']);
    Key::create([
      'id' => 'preview',
      'label' => 'Preview',
      'key_type' => 'jwt_hs',
      'key_type_settings' => ['algorithm' => 'HS256'],
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => str_repeat('k', 64)],
    ])->save();
    $this->config('jwt.config')->set('algorithm', 'HS256')->set('key_id', 'preview')->save();
    $this->config('jwt_path_auth.config')->set('allowed_path_prefixes', ['/jsonapi/'])->save();
    // User 1 first, so the editor is an ordinary account.
    User::create(['name' => 'admin', 'status' => 1])->save();
    $this->user = User::create(['name' => 'editor', 'status' => 1]);
    $this->user->save();
  }

  /**
   * Returns a token service for the editor, at a moment in time.
   */
  private function tokens(int $now, bool $path_auth = TRUE): PreviewToken {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($now);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnMap([['jwt_path_auth', $path_auth]]);
    return new PreviewToken($this->user, $time, $this->container->get('config.factory'), $modules, $this->container->get('jwt.transcoder'));
  }

  /**
   * Returns the user JWT Path Authentication finds for a token and a path.
   */
  private function authenticate(string $token, string $path = self::PATH): ?AccountInterface {
    $request = Request::create($path, 'GET', ['jwt' => $token]);
    $provider = $this->container->get('jwt_path_auth.authentication.jwt');
    $this->assertTrue($provider->applies($request));
    return $provider->authenticate($request);
  }

  /**
   * Tests that JWT Path Authentication accepts a token on its own path only.
   */
  public function testAcceptedOnItsPathOnly(): void {
    $token = $this->tokens(time())->create(self::PATH);
    $this->assertSame($this->user->id(), $this->authenticate($token)?->id());
    $this->assertNull($this->authenticate($token, '/jsonapi/node/page/00000000-0000-0000-0000-000000000000/preview'));
    $this->assertNull($this->authenticate($token, '/jsonapi/user/user'));
  }

  /**
   * Tests that a token lasts its lifetime, which the setting changes.
   *
   * JWT allows five minutes of clock drift, so the expired tokens are older.
   */
  public function testLifetime(): void {
    $this->assertNotNull($this->authenticate($this->tokens(time() - PreviewToken::LIFETIME + 60)->create(self::PATH)));
    $this->assertNull($this->authenticate($this->tokens(time() - PreviewToken::LIFETIME - 400)->create(self::PATH)));

    $this->config('consumer_node_preview.settings')->set('token_lifetime', 120)->save();
    $this->assertNotNull($this->authenticate($this->tokens(time() - 60)->create(self::PATH)));
    $this->assertNull($this->authenticate($this->tokens(time() - 120 - 400)->create(self::PATH)));
  }

  /**
   * Tests that a blocked editor's token is refused.
   */
  public function testBlockedEditor(): void {
    $token = $this->tokens(time())->create(self::PATH);
    $this->user->block()->save();
    $this->assertNull($this->authenticate($token));
  }

  /**
   * Tests that there is no token until JWT Path Authentication is set up.
   */
  public function testEmptyWithoutJwtPathAuth(): void {
    $this->assertSame('', $this->tokens(time(), FALSE)->create(self::PATH));
    $time = $this->container->get('datetime.time');
    $modules = $this->container->get('module_handler');
    $this->assertSame('', (new PreviewToken($this->user, $time, $this->container->get('config.factory'), $modules))->create(self::PATH));
    // JWT makes no token without a key.
    $this->config('jwt.config')->set('key_id', '')->save();
    $this->container->set('jwt.transcoder', NULL);
    $this->assertSame('', $this->tokens(time())->create(self::PATH));
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
