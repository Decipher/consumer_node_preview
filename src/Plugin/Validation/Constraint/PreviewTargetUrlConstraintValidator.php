<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Plugin\Validation\Constraint;

use Drupal\consumer_node_preview\PreviewTargetsProviderInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ConsumerPreviewTargetUrl constraint.
 */
class PreviewTargetUrlConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  final public function __construct(
    protected PreviewTargetsProviderInterface $targetsProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('consumer_node_preview.targets_provider'));
  }

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
    // parse_url() fails on a badly malformed URL, such as "http:///p". It also
    // reads "https://evil.test\@example.com" as example.com, where a browser
    // goes to evil.test, so a backslash fails too.
    if (!is_array($parts) || str_contains($value, '\\')) {
      $this->context->addViolation($constraint->message, ['%url' => $value]);
      return;
    }
    $scheme = strtolower($parts['scheme'] ?? '');
    if (empty($parts['host']) || !in_array($scheme, ['http', 'https'], TRUE)) {
      $this->context->addViolation($constraint->message, ['%url' => $value]);
      return;
    }
    if (!$this->targetsProvider->isAllowedHost(strtolower($parts['host']))) {
      $this->context->addViolation($constraint->hostMessage, ['%host' => $parts['host']]);
    }
  }

}
