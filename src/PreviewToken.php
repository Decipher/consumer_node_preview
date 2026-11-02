<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview;

// cspell:ignore cnpt

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\PrivateKey;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;

/**
 * Short-lived tokens that let a frontend read one node preview as its editor.
 *
 * A frontend on another site never receives the editor's session cookie. The
 * [preview_token] URL token gives it this instead: a signed statement of which
 * user may read which preview, until when. It carries no session and grants
 * nothing by itself. A site's authentication provider decides where to accept
 * it, and should accept it only on the preview route for its node.
 */
class PreviewToken {

  /**
   * What every token starts with, so a provider can tell it from others.
   */
  public const PREFIX = 'cnpt.';

  /**
   * The default lifetime, in seconds.
   */
  protected const LIFETIME = 900;

  public function __construct(
    protected AccountInterface $currentUser,
    protected PrivateKey $privateKey,
    protected TimeInterface $time,
  ) {}

  /**
   * Creates a token for the current user and a node preview.
   *
   * @param string $uuid
   *   The previewed node's UUID.
   *
   * @return string
   *   The token.
   */
  public function create(string $uuid): string {
    $settings = Settings::get('consumer_node_preview', []);
    $lifetime = (int) ($settings['token_lifetime'] ?? self::LIFETIME);
    $payload = rtrim(strtr(base64_encode(json_encode([
      'u' => (int) $this->currentUser->id(),
      'n' => $uuid,
      'e' => $this->time->getCurrentTime() + $lifetime,
    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    return self::PREFIX . $payload . '.' . $this->sign($payload);
  }

  /**
   * Reads a token.
   *
   * @param string $token
   *   The token.
   *
   * @return array{uid: int, uuid: string}|null
   *   The user and the node's UUID, or NULL for a forged or expired token.
   */
  public function read(string $token): ?array {
    if (!str_starts_with($token, self::PREFIX)) {
      return NULL;
    }
    $parts = explode('.', substr($token, strlen(self::PREFIX)));
    if (count($parts) !== 2 || !hash_equals($this->sign($parts[0]), $parts[1])) {
      return NULL;
    }
    $claims = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), TRUE);
    if (!is_array($claims) || !isset($claims['u'], $claims['n'], $claims['e'])) {
      return NULL;
    }
    if ((int) $claims['e'] <= $this->time->getCurrentTime()) {
      return NULL;
    }
    return ['uid' => (int) $claims['u'], 'uuid' => (string) $claims['n']];
  }

  /**
   * Signs a payload with this site's own secrets.
   *
   * @param string $payload
   *   The encoded payload.
   *
   * @return string
   *   The URL-safe signature.
   */
  protected function sign(string $payload): string {
    return Crypt::hmacBase64('consumer_node_preview:' . $payload, $this->privateKey->get() . Settings::getHashSalt());
  }

}
