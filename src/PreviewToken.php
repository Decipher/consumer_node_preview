<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * Short-lived tokens that let a frontend read node previews as their editor.
 *
 * A frontend on another site never receives the editor's session cookie. The
 * [preview_token] URL token gives it this instead: a Simple OAuth access
 * token for the editor, issued to the target's consumer. It has the scopes
 * the editor would get by signing in to that consumer, and Simple OAuth
 * accepts it as a bearer token.
 *
 * Without Simple OAuth the token is empty.
 */
class PreviewToken {

  /**
   * The default lifetime, in seconds.
   */
  public const LIFETIME = 900;

  public function __construct(
    protected AccountInterface $currentUser,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected ?AccessTokenRepositoryInterface $accessTokens = NULL,
    protected ?ClientRepositoryInterface $clients = NULL,
    protected ?ScopeRepositoryInterface $scopes = NULL,
  ) {}

  /**
   * Creates a token for the current user, issued to a consumer.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer whose frontend reads the preview.
   *
   * @return string
   *   The token. It is empty without Simple OAuth or its private key, for
   *   an anonymous user, or for a consumer an editor cannot sign in to.
   */
  public function create(ConsumerInterface $consumer): string {
    if (!$this->accessTokens instanceof AccessTokenRepositoryInterface || !$this->clients instanceof ClientRepositoryInterface || !$this->scopes instanceof ScopeRepositoryInterface) {
      return '';
    }
    if ($this->currentUser->isAnonymous() || !$this->signsInEditors($consumer)) {
      return '';
    }
    $client = $this->clients->getClientEntity($consumer->getClientId());
    $key = $this->privateKey();
    if (!$client instanceof ClientEntityInterface || !$key instanceof CryptKey) {
      return '';
    }
    $lifetime = $this->configFactory->get('consumer_node_preview.settings')->get('token_lifetime');
    $lifetime = is_int($lifetime) && $lifetime > 0 ? $lifetime : self::LIFETIME;
    $uid = (string) $this->currentUser->id();
    try {
      // The scopes an editor gets by signing in to this consumer.
      $scopes = $this->scopes->finalizeScopes([], 'authorization_code', $client, $uid);
      $token = $this->accessTokens->getNewToken($client, $scopes, $uid);
      $token->setIdentifier(bin2hex(random_bytes(40)));
      $token->setExpiryDateTime(new \DateTimeImmutable('@' . ($this->time->getCurrentTime() + $lifetime)));
      $token->setPrivateKey($key);
      $this->accessTokens->persistNewAccessToken($token);
      return $token->toString();
    }
    catch (OAuthServerException) {
      return '';
    }
  }

  /**
   * Checks that editors can sign in to a consumer.
   *
   * A token gives a consumer what an editor's sign-in would, so a consumer
   * without the authorization code grant gets none.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer.
   */
  protected function signsInEditors(ConsumerInterface $consumer): bool {
    // Simple OAuth adds the field, and is installed by now.
    $grants = array_column($consumer->get('grant_types')->getValue(), 'value');
    return in_array('authorization_code', $grants, TRUE);
  }

  /**
   * Returns Simple OAuth's private key, as its authorization server does.
   */
  protected function privateKey(): ?CryptKey {
    $path = $this->configFactory->get('simple_oauth.settings')->get('private_key');
    if (!is_string($path) || $path === '' || !is_readable($path)) {
      return NULL;
    }
    $contents = file_get_contents($path);
    if ($contents === FALSE || $contents === '') {
      return NULL;
    }
    try {
      return new CryptKey($contents, NULL, (bool) Settings::get('simple_oauth.key_permissions_check', TRUE));
    }
    catch (\LogicException) {
      return NULL;
    }
  }

}
