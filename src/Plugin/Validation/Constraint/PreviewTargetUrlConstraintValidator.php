<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Plugin\Validation\Constraint;

use Drupal\Core\Site\Settings;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ConsumerPreviewTargetUrl constraint.
 */
class PreviewTargetUrlConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof PreviewTargetUrlConstraint);
    // An empty URL is the NotBlank constraint's job.
    if (!is_string($value) || trim($value) === '') {
      return;
    }
    // The value as stored. A leading space would break the URL in the frame,
    // so it fails here. The form and the endpoint trim before they save.
    $parts = parse_url($value);
    // parse_url() fails on a badly malformed URL, such as "http:///p".
    if (!is_array($parts)) {
      $this->context->addViolation($constraint->message, ['%url' => $value]);
      return;
    }
    $scheme = strtolower($parts['scheme'] ?? '');
    if (empty($parts['host']) || !in_array($scheme, ['http', 'https'], TRUE)) {
      $this->context->addViolation($constraint->message, ['%url' => $value]);
      return;
    }
    if (!$this->hostAllowed(strtolower($parts['host']))) {
      $this->context->addViolation($constraint->hostMessage, ['%host' => $parts['host']]);
    }
  }

  /**
   * Whether the site's allowed_target_hosts setting admits a host.
   *
   * Without the setting, any host is allowed. A pattern's "*." matches one or
   * more subdomains, and not the bare domain.
   *
   * @param string $host
   *   The URL's host, in lower case.
   *
   * @return bool
   *   TRUE when the host may be saved.
   */
  protected function hostAllowed(string $host): bool {
    $allowed = Settings::get('consumer_node_preview', [])['allowed_target_hosts'] ?? NULL;
    if (!is_array($allowed)) {
      return TRUE;
    }
    foreach ($allowed as $pattern) {
      $pattern = strtolower((string) $pattern);
      if ($host === $pattern) {
        return TRUE;
      }
      if (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)) && strlen($host) > strlen($pattern) - 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
