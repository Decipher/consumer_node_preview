<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\consumer_node_preview\Plugin\Validation\Constraint\PreviewTargetUrlConstraint;
use Drupal\consumer_node_preview\Plugin\Validation\Constraint\PreviewTargetUrlConstraintValidator;
use Drupal\consumer_node_preview\PreviewToken;
use Drupal\consumers\Entity\Consumer;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Drupal\simple_oauth\Exception\OAuthUnauthorizedHttpException;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Tests the preview token with Simple OAuth, and the target host allowlist.
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
    'options',
    'path_alias',
    'simple_oauth',
  ];

  /**
   * The editor the tokens are for.
   */
  private UserInterface $user;

  /**
   * The consumer the tokens are issued to.
   */
  private ConsumerInterface $consumer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_token');
    $this->installConfig(['consumer_node_preview', 'simple_oauth']);
    $keys = $this->siteDirectory . '/keys';
    mkdir($keys, 0700, TRUE);
    $this->container->get('simple_oauth.key.generator')->generateKeys($keys);
    $this->setSetting('simple_oauth.key_permissions_check', FALSE);
    $this->config('simple_oauth.settings')
      ->set('private_key', $keys . '/private.key')
      ->set('public_key', $keys . '/public.key')
      ->save();
    // User 1 first, so the editor is an ordinary account.
    User::create(['name' => 'admin', 'status' => 1])->save();
    $this->user = User::create(['name' => 'editor', 'status' => 1]);
    $this->user->save();
    $this->consumer = Consumer::create([
      'label' => 'App',
      'client_id' => 'app',
      'grant_types' => ['authorization_code'],
    ]);
    $this->consumer->save();
  }

  /**
   * Returns a token service for the editor, at a moment in time.
   */
  private function tokens(int $now): PreviewToken {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($now);
    return new PreviewToken(
      $this->user,
      $time,
      $this->container->get('config.factory'),
      $this->container->get('simple_oauth.repositories.access_token'),
      $this->container->get('simple_oauth.repositories.client'),
      $this->container->get('simple_oauth.repositories.scope'),
    );
  }

  /**
   * Returns the user Simple OAuth finds for a bearer token, or NULL.
   */
  private function authenticate(string $token): ?AccountInterface {
    $request = Request::create('/jsonapi');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    $provider = $this->container->get('simple_oauth.authentication.simple_oauth');
    $this->assertTrue($provider->applies($request));
    try {
      return $provider->authenticate($request);
    }
    catch (\Exception $exception) {
      // Simple OAuth's interface does not declare it, but a refused token
      // throws this.
      $this->assertInstanceOf(OAuthUnauthorizedHttpException::class, $exception);
      return NULL;
    }
  }

  /**
   * Tests that Simple OAuth accepts a token as the editor, for the consumer.
   */
  public function testAcceptedBySimpleOauth(): void {
    $token = $this->tokens(time())->create($this->consumer);
    $account = $this->authenticate($token);
    $this->assertInstanceOf(TokenAuthUserInterface::class, $account);
    $this->assertSame($this->user->id(), $account->id());
    $this->assertSame($this->consumer->id(), $account->getConsumer()->id());
    $this->assertNull($this->authenticate($token . 'x'));
  }

  /**
   * Tests that a token lasts its lifetime, which the setting changes.
   */
  public function testLifetime(): void {
    $this->assertNotNull($this->authenticate($this->tokens(time() - PreviewToken::LIFETIME + 60)->create($this->consumer)));
    $this->assertNull($this->authenticate($this->tokens(time() - PreviewToken::LIFETIME - 60)->create($this->consumer)));

    $this->config('consumer_node_preview.settings')->set('token_lifetime', 120)->save();
    $this->assertNotNull($this->authenticate($this->tokens(time() - 60)->create($this->consumer)));
    $this->assertNull($this->authenticate($this->tokens(time() - 180)->create($this->consumer)));
  }

  /**
   * Tests that a blocked editor's token is refused.
   */
  public function testBlockedEditor(): void {
    $token = $this->tokens(time())->create($this->consumer);
    $this->user->block()->save();
    $this->assertNull($this->authenticate($token));
  }

  /**
   * Tests that a consumer an editor cannot sign in to gets no token.
   */
  public function testEmptyWithoutAuthorizationCodeGrant(): void {
    $this->consumer->set('grant_types', ['client_credentials'])->save();
    $this->assertSame('', $this->tokens(time())->create($this->consumer));
  }

  /**
   * Tests that there is no token without Simple OAuth, or without its key.
   */
  public function testEmptyWithoutSimpleOauth(): void {
    $tokens = new PreviewToken($this->user, $this->container->get('datetime.time'), $this->container->get('config.factory'));
    $this->assertSame('', $tokens->create($this->consumer));
    $this->config('simple_oauth.settings')->set('private_key', $this->siteDirectory . '/missing.key')->save();
    $this->assertSame('', $this->tokens(time())->create($this->consumer));
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
