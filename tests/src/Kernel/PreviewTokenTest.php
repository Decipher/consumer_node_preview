<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\consumer_node_preview\PreviewTargetsTokenExpiryTriggerHandler;
use Drupal\consumer_node_preview\PreviewToken;
use Drupal\consumers\Entity\Consumer;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Drupal\simple_oauth\Exception\OAuthUnauthorizedHttpException;
use Drupal\simple_oauth\TokenExpiryTriggerHandlerInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the preview token with Simple OAuth.
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
   * Tests that writing the consumer's preview fields keeps its tokens.
   *
   * Simple OAuth revokes every token of a consumer when the consumer is
   * saved. A registration, its refresh, a removal and cron write only the
   * preview targets, and an editor's open preview must keep working. Any
   * other change still revokes them, as Simple OAuth intends.
   */
  public function testPreviewFieldWritesKeepTokens(): void {
    $token = $this->tokens(time())->create($this->consumer);
    $this->assertNotNull($this->authenticate($token));

    $consumer = Consumer::load($this->consumer->id());
    $consumer->set('preview_targets', [
      ['label' => 'Local', 'url' => 'http://localhost:3000/p', 'last_seen' => time()],
    ])->save();
    $this->assertNotNull($this->authenticate($token), 'A target write keeps the token.');
    $consumer = Consumer::load($this->consumer->id());
    $consumer->set('preview_layouts', ['default'])->save();
    $this->assertNotNull($this->authenticate($token), 'A layouts write keeps it too.');

    // A change beside the targets revokes, and so does any other change.
    $consumer = Consumer::load($this->consumer->id());
    $consumer->set('preview_targets', [])->set('label', 'Renamed')->save();
    $this->assertNull($this->authenticate($token));
    $second = $this->tokens(time())->create($this->consumer);
    Consumer::load($this->consumer->id())->set('description', 'Changed')->save();
    $this->assertNull($this->authenticate($second));
  }

  /**
   * Tests that the module passes Simple OAuth's other token handling on.
   *
   * Only a consumer update that changes the preview fields alone is kept from
   * Simple OAuth. Its cron, a user update and a consumer's deletion all go on
   * to Simple OAuth as before.
   */
  public function testOtherTokenHandlingGoesToSimpleOauth(): void {
    $handler = $this->container->get(TokenExpiryTriggerHandlerInterface::class);
    $this->assertInstanceOf(PreviewTargetsTokenExpiryTriggerHandler::class, $handler);
    $tokens = $this->container->get('entity_type.manager')->getStorage('oauth2_token');

    // Cron deletes an expired token.
    $this->tokens(time() - PreviewToken::LIFETIME - 60)->create($this->consumer);
    $this->assertCount(1, $tokens->loadMultiple());
    $handler->handleCron();
    $this->assertCount(0, $tokens->loadMultiple());

    // A user update and a consumer's deletion revoke the editor's token.
    $token = $this->tokens(time())->create($this->consumer);
    $this->user->setPassword('changed')->save();
    $this->assertNull($this->authenticate($token));
    $token = $this->tokens(time())->create($this->consumer);
    $this->consumer->delete();
    $this->assertNull($this->authenticate($token));
  }

  /**
   * Tests that a consumer the storage no longer has gets no exemption.
   */
  public function testGoneConsumerIsNotExempt(): void {
    $changes = $this->container->get('consumer_node_preview.preview_field_changes');
    $consumer = Consumer::load($this->consumer->id());
    $this->consumer->delete();
    $changes->record($consumer);
    $this->assertFalse($changes->onlyPreviewFieldsChanged($consumer));
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
    foreach (['empty.key' => '', 'broken.key' => 'not a key'] as $name => $contents) {
      file_put_contents($this->siteDirectory . '/' . $name, $contents);
      $this->config('simple_oauth.settings')->set('private_key', $this->siteDirectory . '/' . $name)->save();
      $this->assertSame('', $this->tokens(time())->create($this->consumer), $name);
    }
  }

  /**
   * Tests that there is no token when Simple OAuth cannot store it.
   */
  public function testEmptyWhenNotStored(): void {
    $repository = $this->container->get('simple_oauth.repositories.access_token');
    $accessTokens = $this->createMock(AccessTokenRepositoryInterface::class);
    $accessTokens->method('getNewToken')->willReturnCallback($repository->getNewToken(...));
    $accessTokens->method('persistNewAccessToken')->willThrowException(UniqueTokenIdentifierConstraintViolationException::create());
    $tokens = new PreviewToken(
      $this->user,
      $this->container->get('datetime.time'),
      $this->container->get('config.factory'),
      $accessTokens,
      $this->container->get('simple_oauth.repositories.client'),
      $this->container->get('simple_oauth.repositories.scope'),
    );
    $this->assertSame('', $tokens->create($this->consumer));
  }

}
