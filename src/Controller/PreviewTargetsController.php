<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\consumer_node_preview\PreviewTargetsProviderInterface;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads, replaces and registers the preview targets of one consumer.
 *
 * GET reads the targets and layouts. PUT replaces the whole list. PATCH
 * registers one target: it replaces the target with the same label, or adds
 * it, and stamps it with the request time. A stamped target expires when no
 * PATCH refreshes it.
 *
 * The route requires the "register consumer node preview targets"
 * permission. On top of that, this controller only serves a consumer the
 * current user owns, unless the user may administer consumers. The
 * X-Consumer-ID header only selects a consumer. It never grants access,
 * because any client can send any value in it. A request must name its
 * consumer: there is no fallback to the site's default consumer.
 */
final class PreviewTargetsController extends ControllerBase {

  public function __construct(
    protected PreviewTargetsProviderInterface $previewTargetsProvider,
    EntityTypeManagerInterface $entity_type_manager,
    protected TimeInterface $time,
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('consumer_node_preview.targets_provider'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Handles GET, PUT and PATCH for the targets resource.
   */
  public function handle(Request $request): JsonResponse {
    $consumer = $this->resolveConsumer($request);
    if (!$consumer instanceof ConsumerInterface) {
      return new JsonResponse(['message' => 'No consumer could be identified from the request.'], 404);
    }
    if (!$this->allowed($consumer)) {
      return new JsonResponse(['message' => 'This user does not own the consumer.'], 403);
    }

    $error = match ($request->getMethod()) {
      'PUT' => $this->replaceTargets($consumer, (string) $request->getContent()),
      'PATCH' => $this->registerTarget($consumer, (string) $request->getContent()),
      default => NULL,
    };
    if ($error !== NULL) {
      return new JsonResponse(['message' => $error], 422);
    }

    return new JsonResponse([
      'consumer' => $consumer->uuid(),
      'targets' => $this->previewTargetsProvider->getTargets($consumer),
      'layouts' => $this->previewTargetsProvider->getLayouts($consumer),
    ]);
  }

  /**
   * Finds the consumer the request names.
   *
   * A "consumer" query parameter holds a uuid, and wins. Otherwise the
   * X-Consumer-ID header, or a "consumerId" query parameter, holds a client
   * id. The consumers negotiator is not used, because it falls back to the
   * default consumer, and a write must never land on a consumer the request
   * did not name.
   */
  protected function resolveConsumer(Request $request): ?ConsumerInterface {
    $uuid = $request->query->get('consumer');
    if (is_string($uuid) && $uuid !== '') {
      $properties = ['uuid' => $uuid];
    }
    else {
      $client_id = $request->headers->get('X-Consumer-ID') ?: $request->query->get('consumerId');
      if (!is_string($client_id) || $client_id === '') {
        return NULL;
      }
      $properties = ['client_id' => $client_id];
    }
    $consumers = $this->entityTypeManager->getStorage('consumer')->loadByProperties($properties);
    $consumer = reset($consumers);
    return $consumer instanceof ConsumerInterface ? $consumer : NULL;
  }

  /**
   * Tells whether the current user may manage this consumer's targets.
   */
  protected function allowed(ConsumerInterface $consumer): bool {
    $account = $this->currentUser();
    if ($account->hasPermission('administer consumer entities')) {
      return TRUE;
    }
    // Consumers stores 0 as the owner of a consumer that has none, and an
    // anonymous user owns nothing.
    return $account->isAuthenticated() && (int) $consumer->getOwnerId() === (int) $account->id();
  }

  /**
   * Validates the payload and replaces the consumer's targets.
   *
   * A "layouts" list in the payload replaces the consumer's layouts too.
   * Without it, the layouts stay as they are. A target with the same label
   * and URL as a stored one keeps its stamp. No target gets a new stamp, so
   * a list managed by hand never expires, and one client's PUT does not
   * refresh the stamp of another client's target.
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
      $value = $this->targetValue($target);
      if (is_string($value)) {
        return sprintf('Target %d: %s', $delta, $value);
      }
      $values[] = $value;
    }
    return $this->save($consumer, $values, $decoded);
  }

  /**
   * Validates the payload and registers one target on the consumer.
   *
   * The target replaces the stored target with the same label, in its
   * place, or goes at the end. It gets the request time as its stamp. The
   * other targets and their stamps stay as they are. A "layouts" list
   * replaces the consumer's layouts, as for a PUT.
   *
   * @return string|null
   *   An error message, or NULL on success.
   */
  protected function registerTarget(ConsumerInterface $consumer, string $payload): ?string {
    $decoded = json_decode($payload, TRUE);
    if (!is_array($decoded) || !isset($decoded['target'])) {
      return 'The payload must be a JSON object with a "target" object.';
    }
    $value = $this->targetValue($decoded['target']);
    if (is_string($value)) {
      return 'Target: ' . $value;
    }
    $value['last_seen'] = $this->time->getRequestTime();

    $values = $consumer->get('preview_targets')->getValue();
    $delta = count($values);
    foreach ($values as $index => $stored) {
      if (($stored['label'] ?? NULL) === $value['label']) {
        $delta = $index;
        break;
      }
    }
    $values[$delta] = $value;
    return $this->save($consumer, array_values($values), $decoded);
  }

  /**
   * Checks the shape of one target in a payload, and trims its values.
   *
   * @param mixed $target
   *   The target from the payload.
   *
   * @return array{label: string, url: string}|string
   *   The target's field values, or an error message.
   */
  protected function targetValue(mixed $target): array|string {
    if (!is_array($target) || !is_string($target['label'] ?? NULL) || !is_string($target['url'] ?? NULL)) {
      return 'each target must be an object with a "label" string and a "url" string.';
    }
    // The field drops an item with neither value, so say so instead.
    if (trim($target['label']) === '' && trim($target['url']) === '') {
      return 'a target needs a label and a URL.';
    }
    return [
      'label' => trim($target['label']),
      'url' => trim($target['url']),
    ];
  }

  /**
   * Validates and saves the consumer's targets, and its layouts if given.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer.
   * @param list<array<string, mixed>> $values
   *   The preview_targets field values.
   * @param array<mixed> $decoded
   *   The decoded payload. A "layouts" key replaces the consumer's layouts.
   *
   * @return string|null
   *   An error message, or NULL on success.
   */
  protected function save(ConsumerInterface $consumer, array $values, array $decoded): ?string {
    $fields = ['preview_targets'];
    if (array_key_exists('layouts', $decoded)) {
      $layouts = $decoded['layouts'];
      if (!is_array($layouts) || !array_is_list($layouts) || array_filter($layouts, is_string(...)) !== $layouts) {
        return 'The "layouts" value must be a list of layout name strings.';
      }
      $consumer->set('preview_layouts', $layouts);
      $fields[] = 'preview_layouts';
    }

    // The fields' constraints hold the rules, so this endpoint and the
    // consumer form cannot drift apart. Only these fields' violations count:
    // a problem elsewhere on the consumer is not this endpoint's to report.
    $consumer->set('preview_targets', $values);
    $violations = $consumer->validate();
    $messages = [];
    foreach ($fields as $field) {
      foreach ($violations->getByField($field) as $violation) {
        $messages[] = sprintf('%s: %s', $violation->getPropertyPath(), PlainTextOutput::renderFromHtml((string) $violation->getMessage()));
      }
    }
    if ($messages) {
      return implode(' ', $messages);
    }
    $consumer->save();
    return NULL;
  }

}
