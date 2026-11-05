<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Plugin\Validation\Constraint;

use Drupal\consumer_node_preview\PreviewTargetsProviderInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ConsumerPreviewTargetUrl constraint.
 */
class PreviewTargetUrlConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  final public function __construct(
    protected PreviewTargetsProviderInterface $targetsProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('consumer_node_preview.targets_provider'),
      $container->get('entity_type.manager'),
    );
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
    if (!$this->targetsProvider->isAllowedHost(strtolower($parts['host'])) && !$this->isStored($value)) {
      $this->context->addViolation($constraint->hostMessage, ['%host' => $parts['host']]);
    }
  }

  /**
   * Checks whether the consumer already has this URL saved.
   *
   * A saved target on a host taken off the allowlist stays out of the
   * Frontend picker. Keeping it must not stop the consumer saving, or one
   * frontend's old target would block another frontend's registration.
   *
   * @param string $url
   *   The URL being validated.
   */
  protected function isStored(string $url): bool {
    $property = $this->context->getObject();
    $item = $property instanceof TypedDataInterface ? $property->getParent() : NULL;
    if (!$item instanceof FieldItemInterface) {
      return FALSE;
    }
    $entity = $item->getEntity();
    if ($entity->isNew()) {
      return FALSE;
    }
    $stored = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    if (!$stored instanceof ContentEntityInterface || !$stored->hasField($item->getFieldDefinition()->getName())) {
      return FALSE;
    }
    $urls = array_column($stored->get($item->getFieldDefinition()->getName())->getValue(), 'url');
    return in_array($url, $urls, TRUE);
  }

}
