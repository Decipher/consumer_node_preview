<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Form widget for a preview target.
 *
 * @FieldWidget(
 *   id = "consumer_preview_target_default",
 *   label = @Translation("Preview target"),
 *   field_types = {
 *     "consumer_preview_target",
 *   },
 * )
 */
class PreviewTargetWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The field items.
   * @param int $delta
   *   The item delta.
   * @param array $element
   *   The base form element.
   * @param array $form
   *   The full form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $element['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $items[$delta]->label ?? '',
      '#maxlength' => 255,
    ];
    $description = $this->t('An absolute http or https URL. It can use the tokens [jsonapi_node_preview], [jsonapi_node_preview_path], [uuid], [view_mode], [layout] and [preview_token].');
    // Show the site's host allowlist, so the rule is known before a save.
    $hosts = Settings::get('consumer_node_preview', [])['allowed_target_hosts'] ?? NULL;
    if (is_array($hosts) && $hosts) {
      $description = $this->t('@description Allowed hosts: @hosts.', [
        '@description' => $description,
        '@hosts' => implode(', ', $hosts),
      ]);
    }
    $element['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL template'),
      '#description' => $description,
      '#default_value' => $items[$delta]->url ?? '',
      '#maxlength' => 2048,
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * Trims the label and the URL, as the targets endpoint does. A pasted URL
   * often starts with a space.
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    foreach ($values as &$value) {
      foreach (['label', 'url'] as $property) {
        if (isset($value[$property]) && is_string($value[$property])) {
          $value[$property] = trim($value[$property]);
        }
      }
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   *
   * Puts a violation on the label or URL field it is about. The property
   * path is relative to the field here, for example "0.url".
   */
  public function errorElement(array $element, ConstraintViolationInterface $error, array $form, FormStateInterface $form_state): array|bool {
    $property = explode('.', $error->getPropertyPath())[1] ?? NULL;
    return is_string($property) && isset($element[$property]) ? $element[$property] : $element;
  }

}
