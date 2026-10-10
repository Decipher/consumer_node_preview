<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Controller;

use Drupal\consumer_node_preview\PreviewTargetsProviderInterface;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\consumers\NegotiatorInterface;
use Drupal\Component\Render\PlainTextOutput;
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
      if (!is_array($target) || !is_string($target['label'] ?? NULL) || !is_string($target['url'] ?? NULL)) {
        return sprintf('Target %d: each target must be an object with a "label" string and a "url" string.', $delta);
      }
      // The field drops an item with neither value, so say so instead.
      if (trim($target['label']) === '' && trim($target['url']) === '') {
        return sprintf('Target %d: a target needs a label and a URL.', $delta);
      }
      $values[] = [
        'label' => trim($target['label']),
        'url' => trim($target['url']),
      ];
    }

    // The field's constraints hold the rules, so this endpoint and the
    // consumer form cannot drift apart. Only this field's violations count:
    // a problem elsewhere on the consumer is not this endpoint's to report.
    $consumer->set('preview_targets', $values);
    $violations = $consumer->validate()->getByField('preview_targets');
    if (count($violations) > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = sprintf('%s: %s', $violation->getPropertyPath(), PlainTextOutput::renderFromHtml((string) $violation->getMessage()));
      }
      return implode(' ', $messages);
    }
    $consumer->save();
    return NULL;
  }

}
