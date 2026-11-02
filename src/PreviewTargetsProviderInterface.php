<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\node\NodeInterface;

/**
 * Reads preview targets and resolves their URL tokens.
 *
 * A target comes from one of two places. A consumer lists targets in its
 * preview_targets field, keyed "<consumer id>:<delta>". The site lists
 * targets in settings.php, keyed "settings:<name>", which suits a URL that
 * differs per environment.
 */
interface PreviewTargetsProviderInterface {

  /**
   * The pattern a layout name matches: letters, numbers, - and _.
   */
  public const LAYOUT_PATTERN = '/^[A-Za-z0-9_-]+$/D';

  /**
   * The default time, in seconds, before a registered target goes inactive.
   */
  public const INACTIVE_AFTER = 86400;

  /**
   * The default time, in seconds, before cron deletes a registered target.
   */
  public const DELETE_AFTER = 2592000;

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
   * Returns the layouts one consumer's frontend can render a preview in.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer.
   *
   * @return list<string>
   *   The layout names, in field order.
   */
  public function getLayouts(ConsumerInterface $consumer): array;

  /**
   * Returns every target: the site's first, then each published consumer's.
   *
   * The inactive targets come last. An inactive target is a registered one
   * whose last registration is older than getInactiveAfter().
   *
   * @return array<string, array{label: string, url: string, layouts: list<string>, group: string, option_label: string, last_seen: int|null, inactive: bool}>
   *   The targets, keyed "settings:<name>" or "<consumer id>:<delta>". A
   *   consumer target's label starts with the consumer's label, and its
   *   layouts are the consumer's. A site target lists its own layouts. The
   *   group is the consumer's label, "Site settings" for a site target, or
   *   "Inactive targets". The option label is the target's own label, for use
   *   inside its group. In the inactive group, it is the label with the
   *   consumer's. The last_seen value is the time of the last registration,
   *   or NULL for a target added by hand. The inactive value is TRUE for a
   *   target in the inactive group.
   */
  public function getAllTargets(): array;

  /**
   * Returns flat select options for every target.
   *
   * Use it where a target shows on one line without a group. Each label
   * names the consumer.
   *
   * @return array<string, string>
   *   The target labels, keyed as getAllTargets() keys them.
   */
  public function getTargetOptions(): array;

  /**
   * Returns select options for every target, grouped by consumer.
   *
   * The site's targets come first, in a "Site settings" group. Each consumer
   * follows by label, with its targets in field order. The inactive targets
   * come last, in an "Inactive targets" group. The shape suits the #options of
   * a Form API select, which renders a nested array as an optgroup. Consumers
   * with one label share a group. Options that read the same in a group add
   * their host.
   *
   * @param array<string, array{url: string, group: string, option_label: string}>|null $targets
   *   (optional) The targets from getAllTargets(), when the caller has them
   *   already. Else they are loaded again.
   *
   * @return array<string, array<string, string>>
   *   The groups, keyed by group label. Each holds the targets' own labels,
   *   keyed as getAllTargets() keys them.
   */
  public function getGroupedTargetOptions(?array $targets = NULL): array;

  /**
   * Returns the target for one key.
   *
   * @param string $key
   *   A key in the form "settings:<name>" or "<consumer id>:<delta>".
   *
   * @return array{label: string, url: string, layouts: list<string>}|null
   *   The target, or NULL when the key does not resolve.
   */
  public function getTargetByKey(string $key): ?array;

  /**
   * Returns the key of the target the preview opens on.
   *
   * @return string|null
   *   The default from settings, else the first target, or NULL when there
   *   are no targets.
   */
  public function getDefaultKey(): ?string;

  /**
   * Returns how long a registered target stays active.
   *
   * @return int
   *   The seconds from "inactive_after" in settings.php, or a day.
   */
  public function getInactiveAfter(): int;

  /**
   * Returns how long cron keeps a registered target.
   *
   * @return int
   *   The seconds from "delete_after" in settings.php, or 30 days.
   */
  public function getDeleteAfter(): int;

  /**
   * Deletes the registered targets older than getDeleteAfter().
   *
   * A target added by hand has no stamp, so it is never deleted.
   *
   * @return int
   *   The number of targets deleted.
   */
  public function deleteExpiredTargets(): int;

  /**
   * Returns the JSON:API preview document's URL for a node.
   *
   * The URL includes the node's entity_reference_revisions fields, such as
   * paragraphs, so a frontend gets their unsaved values in one request.
   *
   * @param \Drupal\node\NodeInterface $node_preview
   *   The node in the preview temp store.
   * @param bool $absolute
   *   TRUE for an absolute URL, FALSE for a path from the site root.
   *
   * @return string|null
   *   The URL, or NULL when JSON:API has no preview for the node's bundle.
   */
  public function getEndpoint(NodeInterface $node_preview, bool $absolute = TRUE): ?string;

  /**
   * Resolves the tokens in a target URL for one previewed node.
   *
   * @param string $url
   *   The URL template.
   * @param \Drupal\node\NodeInterface $node_preview
   *   The node in the preview temp store.
   * @param string $view_mode_id
   *   The view mode.
   * @param string $layout
   *   The layout, or an empty string for none.
   *
   * @return string
   *   The URL with [jsonapi_node_preview], [jsonapi_node_preview_path],
   *   [uuid], [view_mode] and [layout] replaced. When JSON:API has no
   *   preview for the node's bundle, the two endpoint tokens stay as they are.
   */
  public function resolveUrl(string $url, NodeInterface $node_preview, string $view_mode_id, string $layout = ''): string;

}
