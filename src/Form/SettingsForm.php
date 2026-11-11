<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Form;

use Drupal\consumer_node_preview\PreviewTargetsProviderInterface;
use Drupal\consumer_node_preview\PreviewToken;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures preview target hosts, preview tokens and target expiry.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'consumer_node_preview_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['consumer_node_preview.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['allowed_target_hosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed target hosts'),
      '#description' => $this->t('One host per line. A preview target can only use these hosts, including targets that frontends register. Start a line with <code>*.</code> to match any subdomain, such as <code>*.preview.example.com</code>. Leave it empty to allow any host.'),
      '#config_target' => new ConfigTarget(
        'consumer_node_preview.settings',
        'allowed_target_hosts',
        // A site installed before the settings existed has no list yet.
        static fn(?array $hosts): string => implode("\n", $hosts ?? []),
        static fn(string $value): array => array_values(array_filter(array_map(
          static fn(string $host): string => strtolower(trim($host)),
          explode("\n", $value),
        ))),
      ),
    ];
    $form['token_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Preview token lifetime'),
      '#description' => $this->t('The seconds a <code>[preview_token]</code> lasts. The preview page makes its tokens when it opens, so after this an editor reloads the page for new ones.'),
      '#min' => 1,
      '#field_suffix' => $this->t('seconds'),
      '#config_target' => $this->seconds('token_lifetime', PreviewToken::LIFETIME),
    ];
    $form['expiry'] = [
      '#type' => 'details',
      '#title' => $this->t('Registered targets'),
      '#description' => $this->t('Only targets that frontends register expire. A target added on the consumer form or in settings.php never does. A frontend can also give its target an earlier expiry of its own, up to the Delete after time.'),
      '#open' => TRUE,
    ];
    $form['expiry']['inactive_after'] = [
      '#type' => 'number',
      '#title' => $this->t('Inactive after'),
      '#description' => $this->t('The seconds without a registration before a target moves to the Inactive targets group.'),
      '#min' => 1,
      '#field_suffix' => $this->t('seconds'),
      '#config_target' => $this->seconds('inactive_after', PreviewTargetsProviderInterface::INACTIVE_AFTER),
    ];
    $form['expiry']['delete_after'] = [
      '#type' => 'number',
      '#title' => $this->t('Delete after'),
      '#description' => $this->t('The seconds without a registration before cron deletes a target.'),
      '#min' => 1,
      '#field_suffix' => $this->t('seconds'),
      '#config_target' => $this->seconds('delete_after', PreviewTargetsProviderInterface::DELETE_AFTER),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // A line with a scheme, port or path would never match a target's host.
    foreach (explode("\n", (string) $form_state->getValue('allowed_target_hosts')) as $line) {
      $host = strtolower(trim($line));
      if ($host !== '' && !preg_match('/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) {
        $form_state->setErrorByName('allowed_target_hosts', $this->t('%line is not a host. Enter a host such as <code>preview.example.com</code>, without a scheme, port or path.', ['%line' => trim($line)]));
        break;
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * Returns the config target for a number of seconds.
   *
   * A site installed before the settings existed has no value yet, so the
   * field shows the default.
   *
   * @param string $name
   *   The key in consumer_node_preview.settings.
   * @param int $default
   *   The value to show when there is none.
   */
  protected function seconds(string $name, int $default): ConfigTarget {
    return new ConfigTarget(
      'consumer_node_preview.settings',
      $name,
      static fn(?int $value): int => $value ?? $default,
      static fn(string $value): int => (int) $value,
    );
  }

}
