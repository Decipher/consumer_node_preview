<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\simple_oauth\TokenExpiryTriggerHandlerInterface;
use Drupal\user\UserInterface;

/**
 * Keeps a consumer's tokens when only its preview fields change.
 *
 * Simple OAuth revokes every token of a consumer each time the consumer is
 * saved. A frontend's registration, its refresh, a removal and cron's clean-up
 * all save the consumer to write its preview targets. Without this, each of
 * them would revoke every editor's preview token, and an open preview would
 * fail until the editor pressed Preview again. Any other change, such as the
 * secret, the grant types or the scopes, still revokes the tokens.
 *
 * It decorates Simple OAuth's handler, and only when Simple OAuth is there.
 * PreviewFieldChanges records what each save changed, before the save.
 */
final class PreviewTargetsTokenExpiryTriggerHandler implements TokenExpiryTriggerHandlerInterface {

  public function __construct(
    private readonly TokenExpiryTriggerHandlerInterface $tokenExpiryTriggerHandler,
    private readonly PreviewFieldChanges $previewFieldChanges,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handleCron(): void {
    $this->tokenExpiryTriggerHandler->handleCron();
  }

  /**
   * {@inheritdoc}
   */
  public function handleUserUpdate(UserInterface $user): void {
    $this->tokenExpiryTriggerHandler->handleUserUpdate($user);
  }

  /**
   * {@inheritdoc}
   */
  public function handleConsumerUpdate(ConsumerInterface $consumer): void {
    if (!$this->previewFieldChanges->onlyPreviewFieldsChanged($consumer)) {
      $this->tokenExpiryTriggerHandler->handleConsumerUpdate($consumer);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function handleConsumerDelete(ConsumerInterface $consumer): void {
    $this->tokenExpiryTriggerHandler->handleConsumerDelete($consumer);
  }

}
