<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Plugin\Field\FieldType;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemList;

/**
 * The preview targets of a consumer.
 *
 * The consumer form and a whole-list PUT send targets without a stamp. On
 * save, a target keeps the stamp of a stored target with the same label and
 * URL. Each stored stamp goes to one target only. A target with a new label
 * or URL gets no stamp, so it is then a target added by hand. A stamp that
 * the save sets wins, and it uses up a stored stamp. When a save has fewer
 * targets with a label and URL than the stored list, no stamp moves. The save
 * does not tell which copy went, and a copy added by hand must not expire.
 *
 * @extends \Drupal\Core\Field\FieldItemList<\Drupal\consumer_node_preview\Plugin\Field\FieldType\PreviewTargetItem>
 */
class PreviewTargetItemList extends FieldItemList {

  /**
   * {@inheritdoc}
   */
  public function preSave(): void {
    parent::preSave();
    $name = $this->getFieldDefinition()->getName();
    $original = $this->originalEntity();
    if (!$original instanceof ContentEntityInterface || !$original->hasField($name)) {
      return;
    }

    $stored = [];
    $stamps = [];
    foreach ($original->get($name) as $item) {
      $identity = $this->identity($item);
      $stored[$identity] = ($stored[$identity] ?? 0) + 1;
      $stamps[$identity] ??= [];
      $last_seen = $item->get('last_seen')->getValue();
      if ($last_seen !== NULL) {
        $stamps[$identity][] = (int) $last_seen;
      }
    }
    $saved = [];
    foreach ($this->list as $item) {
      $identity = $this->identity($item);
      $saved[$identity] = ($saved[$identity] ?? 0) + 1;
      if ($item->get('last_seen')->getValue() !== NULL && isset($stamps[$identity])) {
        array_shift($stamps[$identity]);
      }
    }
    foreach ($this->list as $item) {
      $identity = $this->identity($item);
      if ($item->get('last_seen')->getValue() === NULL && ($stamps[$identity] ?? []) !== [] && $saved[$identity] >= $stored[$identity]) {
        $item->set('last_seen', array_shift($stamps[$identity]));
      }
    }
  }

  /**
   * Returns the stored entity that this save changes, if any.
   *
   * The storage loads it unchanged, as it does for the "original" entity.
   * Drupal 11.2 moved that one behind getOriginal(), which Drupal 10 does
   * not have, so it is loaded here on both.
   */
  protected function originalEntity(): ?ContentEntityInterface {
    $entity = $this->getEntity();
    if ($entity->isNew()) {
      return NULL;
    }
    $original = \Drupal::entityTypeManager()
      ->getStorage($entity->getEntityTypeId())
      ->loadUnchanged($entity->id());
    return $original instanceof ContentEntityInterface ? $original : NULL;
  }

  /**
   * Returns what makes two targets the same target: the label and the URL.
   *
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *   The target.
   */
  protected function identity(FieldItemInterface $item): string {
    return json_encode([
      (string) $item->get('label')->getValue(),
      (string) $item->get('url')->getValue(),
    ], JSON_THROW_ON_ERROR);
  }

}
