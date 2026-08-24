<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\node\NodeInterface;

/**
 * Reads preview targets from consumers and resolves their URL tokens.
 */
interface PreviewTargetsProviderInterface {

  /**
   * Returns the preview targets of one consumer.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer.
   *
   * @return array<int, array{label: string, url: string}>
   *   The targets, in field order.
   */
  public function getTargets(ConsumerInterface $consumer): array;

  /**
   * Returns select options for every target of every published consumer.
   *
   * @return array<string, string>
   *   Options keyed by "<consumer id>:<delta>". The value combines the
   *   consumer label and the target label.
   */
  public function getTargetOptions(): array;

  /**
   * Returns the target for one option key.
   *
   * @param string $key
   *   An option key in the form "<consumer id>:<delta>".
   *
   * @return array{label: string, url: string}|null
   *   The target, or NULL when the key does not resolve.
   */
  public function getTargetByKey(string $key): ?array;

  /**
   * Resolves the tokens in a target URL for one previewed node.
   *
   * @param string $url
   *   The URL template.
   * @param \Drupal\node\NodeInterface $node_preview
   *   The node in the preview temp store.
   * @param string $view_mode_id
   *   The view mode.
   *
   * @return string
   *   The URL with [jsonapi_node_preview] and [view_mode] replaced.
   */
  public function resolveUrl(string $url, NodeInterface $node_preview, string $view_mode_id): string;

}
