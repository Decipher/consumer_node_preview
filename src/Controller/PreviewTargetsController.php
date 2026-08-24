<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Controller;

use Drupal\consumer_node_preview\PreviewTargetsProviderInterface;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\consumers\NegotiatorInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads and replaces the preview targets of one consumer.
 *
 * The route requires the "register consumer node preview targets"
 * permission. On top of that, this controller only serves a consumer the
 * current user owns, unless the user may administer consumers. The
 * X-Consumer-ID header only selects a consumer. It never grants access,
 * because any client can send any value in it.
 */
final class PreviewTargetsController extends ControllerBase {

  public function __construct(
    protected NegotiatorInterface $negotiator,
    protected PreviewTargetsProviderInterface $previewTargetsProvider,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('consumer.negotiator'),
      $container->get('consumer_node_preview.targets_provider'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Handles GET and PUT for the targets resource.
   */
  public function handle(Request $request): JsonResponse {
    $consumer = $this->resolveConsumer($request);
    if (!$consumer instanceof ConsumerInterface) {
      return new JsonResponse(['message' => 'No consumer could be identified from the request.'], 404);
    }
    if (!$this->allowed($consumer)) {
      return new JsonResponse(['message' => 'This user does not own the consumer.'], 403);
    }

    if ($request->getMethod() === 'PUT') {
      $error = $this->replaceTargets($consumer, (string) $request->getContent());
      if ($error !== NULL) {
        return new JsonResponse(['message' => $error], 422);
      }
    }

    return new JsonResponse([
      'consumer' => $consumer->uuid(),
      'targets' => $this->previewTargetsProvider->getTargets($consumer),
    ]);
  }

  /**
   * Finds the consumer the request talks about.
   *
   * A "consumer" query parameter with a uuid wins. Otherwise the consumers
   * negotiator inspects the request.
   */
  protected function resolveConsumer(Request $request): ?ConsumerInterface {
    $uuid = $request->query->get('consumer');
    if (is_string($uuid) && $uuid !== '') {
      $consumers = $this->entityTypeManager->getStorage('consumer')
        ->loadByProperties(['uuid' => $uuid]);
      $consumer = reset($consumers);
      return $consumer instanceof ConsumerInterface ? $consumer : NULL;
    }
    return $this->negotiator->negotiateFromRequest($request);
  }

  /**
   * Tells whether the current user may manage this consumer's targets.
   */
  protected function allowed(ConsumerInterface $consumer): bool {
    $account = $this->currentUser();
    if ($account->hasPermission('administer consumer entities')) {
      return TRUE;
    }
    return (int) $consumer->getOwnerId() === (int) $account->id();
  }

  /**
   * Validates the payload and replaces the consumer's targets.
   *
   * @return string|null
   *   An error message, or NULL on success.
   */
  protected function replaceTargets(ConsumerInterface $consumer, string $payload): ?string {
    $decoded = json_decode($payload, TRUE);
    if (!is_array($decoded) || !isset($decoded['targets']) || !is_array($decoded['targets'])) {
      return 'The payload must be a JSON object with a "targets" list.';
    }

    $values = [];
    foreach (array_values($decoded['targets']) as $delta => $target) {
      $error = $this->validateTarget($target);
      if ($error !== NULL) {
        return sprintf('Target %d: %s', $delta, $error);
      }
      $values[] = [
        'label' => trim($target['label']),
        'url' => trim($target['url']),
      ];
    }

    $consumer->set('preview_targets', $values);
    $consumer->save();
    return NULL;
  }

  /**
   * Validates one target entry.
   *
   * @return string|null
   *   An error message, or NULL when the target is valid.
   */
  protected function validateTarget(mixed $target): ?string {
    if (!is_array($target)) {
      return 'each target must be an object with "label" and "url".';
    }
    $label = $target['label'] ?? NULL;
    $url = $target['url'] ?? NULL;
    if (!is_string($label) || trim($label) === '' || mb_strlen($label) > 255) {
      return 'the label must be a non-empty string of at most 255 characters.';
    }
    if (!is_string($url) || trim($url) === '' || mb_strlen($url) > 2048) {
      return 'the url must be a non-empty string of at most 2048 characters.';
    }
    $parts = parse_url(trim($url));
    if (!is_array($parts) || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE)) {
      return 'the url must be absolute and use http or https.';
    }
    return NULL;
  }

}
