<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Reads preview targets from consumers and resolves their URL tokens.
 */
class PreviewTargetsProvider implements PreviewTargetsProviderInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getTargets(ConsumerInterface $consumer): array {
    $targets = [];
    foreach ($consumer->get('preview_targets') as $item) {
      $targets[] = [
        'label' => (string) $item->get('label')->getValue(),
        'url' => (string) $item->get('url')->getValue(),
      ];
    }
    return $targets;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetOptions(): array {
    $options = [];
    $storage = $this->entityTypeManager->getStorage('consumer');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->sort('label')
      ->execute();
    /** @var \Drupal\consumers\Entity\ConsumerInterface $consumer */
    foreach ($storage->loadMultiple($ids) as $consumer) {
      foreach ($this->getTargets($consumer) as $delta => $target) {
        $options[$consumer->id() . ':' . $delta] = $consumer->label() . ': ' . $target['label'];
      }
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetByKey(string $key): ?array {
    if (!preg_match('/^(\d+):(\d+)$/', $key, $matches)) {
      return NULL;
    }
    $consumer = $this->entityTypeManager->getStorage('consumer')->load($matches[1]);
    if (!$consumer instanceof ConsumerInterface) {
      return NULL;
    }
    return $this->getTargets($consumer)[(int) $matches[2]] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveUrl(string $url, NodeInterface $node_preview, string $view_mode_id): string {
    $endpoint = Url::fromRoute(
      "jsonapi.node--{$node_preview->bundle()}.individual.preview",
      ['node_preview' => $node_preview->uuid()],
      ['absolute' => TRUE],
    )->toString();

    return str_replace(
      ['[jsonapi_node_preview]', '[view_mode]'],
      [$endpoint, $view_mode_id],
      $url,
    );
  }

}
