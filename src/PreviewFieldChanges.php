<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Tells whether a consumer's save changes only its preview fields.
 *
 * The consumer's presave hook records it, comparing the consumer with the
 * stored one before the save, as the storage loads it. Simple OAuth then asks,
 * through PreviewTargetsTokenExpiryTriggerHandler, after the save.
 */
final class PreviewFieldChanges {

  /**
   * The consumer fields this module writes, which leave tokens as they are.
   */
  private const PREVIEW_FIELDS = ['preview_targets', 'preview_layouts', 'changed'];

  /**
   * The consumers being saved whose only changes are preview fields.
   *
   * @var array<int, true>
   */
  private array $previewOnly = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Records whether a consumer about to be saved changes only preview fields.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer, before its save.
   */
  public function record(ConsumerInterface $consumer): void {
    unset($this->previewOnly[spl_object_id($consumer)]);
    if ($consumer->isNew()) {
      return;
    }
    $stored = $this->entityTypeManager->getStorage('consumer')->loadUnchanged($consumer->id());
    if (!$stored instanceof ContentEntityInterface) {
      return;
    }
    foreach ($consumer->getFields() as $name => $items) {
      if (in_array($name, self::PREVIEW_FIELDS, TRUE)) {
        continue;
      }
      if (!$stored->hasField($name)) {
        return;
      }
      // An empty item is no value, as core compares fields. The copies keep
      // both entities as they are.
      $now = clone $items;
      $before = clone $stored->get($name);
      if (!$now->filterEmptyItems()->equals($before->filterEmptyItems())) {
        return;
      }
    }
    $this->previewOnly[spl_object_id($consumer)] = TRUE;
  }

  /**
   * Whether the consumer's save changed only its preview fields.
   *
   * It answers once for each save, as Simple OAuth asks once.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer, after its save.
   */
  public function onlyPreviewFieldsChanged(ConsumerInterface $consumer): bool {
    $key = spl_object_id($consumer);
    $answer = isset($this->previewOnly[$key]);
    unset($this->previewOnly[$key]);
    return $answer;
  }

}
