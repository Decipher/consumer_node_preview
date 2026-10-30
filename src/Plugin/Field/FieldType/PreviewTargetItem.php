<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * A preview target: a label, a URL template and when it was last seen.
 *
 * A frontend that registers its own target stamps last_seen. A target added
 * by hand has no stamp, and never expires.
 *
 * @FieldType(
 *   id = "consumer_preview_target",
 *   label = @Translation("Preview target"),
 *   description = @Translation("A labelled frontend URL that renders node previews."),
 *   default_widget = "consumer_preview_target_default",
 *   list_class = "\Drupal\consumer_node_preview\Plugin\Field\FieldType\PreviewTargetItemList",
 *   no_ui = TRUE,
 * )
 */
class PreviewTargetItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['label'] = DataDefinition::create('string')
      ->setLabel(t('Label'))
      ->setRequired(TRUE)
      ->addConstraint('NotBlank', [
        'allowNull' => TRUE,
        'normalizer' => 'trim',
        'message' => 'A preview target needs a label.',
      ])
      ->addConstraint('Length', ['max' => 255]);
    $properties['url'] = DataDefinition::create('string')
      ->setLabel(t('URL template'))
      ->setRequired(TRUE)
      ->addConstraint('NotBlank', [
        'allowNull' => TRUE,
        'normalizer' => 'trim',
        'message' => 'A preview target needs a URL.',
      ])
      ->addConstraint('Length', ['max' => 2048])
      ->addConstraint('ConsumerPreviewTargetUrl');
    $properties['last_seen'] = DataDefinition::create('timestamp')
      ->setLabel(t('Last seen'))
      ->setDescription(t('When the frontend last registered the target, or empty for a target added by hand.'));
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'label' => [
          'type' => 'varchar',
          'length' => 255,
        ],
        'url' => [
          'type' => 'varchar',
          'length' => 2048,
        ],
        'last_seen' => [
          'type' => 'int',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): string {
    return 'url';
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    // A row with only a label or only a URL is not empty. Validation reports
    // the missing half, so the row is not dropped without a word.
    foreach (['label', 'url'] as $property) {
      $value = $this->get($property)->getValue();
      if ($value !== NULL && trim((string) $value) !== '') {
        return FALSE;
      }
    }
    return TRUE;
  }

}
