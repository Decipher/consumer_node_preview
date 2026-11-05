<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\jwt\JsonWebToken\JsonWebToken;
use Drupal\jwt\Transcoder\JwtTranscoderInterface;

/**
 * Short-lived tokens that let a frontend read one node preview as its editor.
 *
 * A frontend on another site never receives the editor's session cookie. The
 * [preview_token] URL token gives it this instead: a JSON Web Token that the
 * JWT module's JWT Path Authentication submodule accepts. It names the editor
 * and the path of one preview document, and expires.
 *
 * Without that submodule, or without a key for JWT, the token is empty.
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
    protected ModuleHandlerInterface $moduleHandler,
    protected ?JwtTranscoderInterface $transcoder = NULL,
  ) {}

  /**
   * Creates a token for the current user and one path.
   *
   * @param string $path
   *   The path the token opens, with any base path and without a query.
   *
   * @return string
   *   The token, or an empty string when JWT Path Authentication is not set
   *   up.
   */
  public function create(string $path): string {
    if (!$this->transcoder instanceof JwtTranscoderInterface || !$this->moduleHandler->moduleExists('jwt_path_auth')) {
      return '';
    }
    // The transcoder fails, rather than returning nothing, without a key.
    if (!$this->configFactory->get('jwt.config')->get('key_id')) {
      return '';
    }
    $lifetime = $this->configFactory->get('consumer_node_preview.settings')->get('token_lifetime');
    $lifetime = is_int($lifetime) && $lifetime > 0 ? $lifetime : self::LIFETIME;
    $now = $this->time->getCurrentTime();
    $jwt = new JsonWebToken();
    $jwt->setClaim('iat', $now);
    $jwt->setClaim('exp', $now + $lifetime);
    $jwt->setClaim(['drupal', 'path_auth', 'uid'], (int) $this->currentUser->id());
    $jwt->setClaim(['drupal', 'path_auth', 'path'], $path);
    return $this->transcoder->encode($jwt) ?? '';
  }

}
