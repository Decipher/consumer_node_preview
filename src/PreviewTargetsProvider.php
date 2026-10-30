<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Reads preview targets and resolves their URL tokens.
 *
 * Site targets come from settings.php:
 *
 * @code
 * $settings['consumer_node_preview'] = [
 *   'targets' => [
 *     'frontend' => [
 *       'label' => 'Frontend',
 *       'url' => '/node/preview?vm=[view_mode]&layout=[layout]#[jsonapi_node_preview_path]',
 *       'layouts' => ['default', 'preview'],
 *     ],
 *   ],
 *   'default' => 'settings:frontend',
 * ];
 * @endcode
 */
class PreviewTargetsProvider implements PreviewTargetsProviderInterface {

  /**
   * How deep the include goes into paragraphs inside paragraphs.
   */
  protected const INCLUDE_DEPTH = 3;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ResourceTypeRepositoryInterface $resourceTypeRepository,
    protected EntityFieldManagerInterface $entityFieldManager,
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
  public function getLayouts(ConsumerInterface $consumer): array {
    $layouts = [];
    foreach ($consumer->get('preview_layouts') as $item) {
      $layouts[] = (string) $item->get('value')->getValue();
    }
    return array_values(array_unique($layouts));
  }

  /**
   * {@inheritdoc}
   */
  public function getAllTargets(): array {
    $targets = [];
    // The site's own targets share one group. "Site" matches the name
    // the README gives them: site targets.
    $site = (string) new TranslatableMarkup('Site');
    foreach ($this->getSiteTargets() as $name => $target) {
      $targets['settings:' . $name] = $target + [
        'group' => $site,
        'option_label' => $target['label'],
      ];
    }
    $storage = $this->entityTypeManager->getStorage('consumer');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->sort('label')
      ->execute();
    /** @var \Drupal\consumers\Entity\ConsumerInterface $consumer */
    foreach ($storage->loadMultiple($ids) as $consumer) {
      $layouts = $this->getLayouts($consumer);
      foreach ($this->getTargets($consumer) as $delta => $target) {
        $targets[$consumer->id() . ':' . $delta] = [
          'label' => $consumer->label() . ': ' . $target['label'],
          'url' => $target['url'],
          'layouts' => $layouts,
          'group' => (string) $consumer->label(),
          'option_label' => $target['label'],
        ];
      }
    }
    return $targets;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetOptions(): array {
    return array_map(fn(array $target): string => $target['label'], $this->getAllTargets());
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupedTargetOptions(): array {
    $options = [];
    foreach ($this->getAllTargets() as $key => $target) {
      $options[$target['group']][(string) $key] = $target['option_label'];
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetByKey(string $key): ?array {
    if (str_starts_with($key, 'settings:')) {
      return $this->getSiteTargets()[substr($key, 9)] ?? NULL;
    }
    if (!preg_match('/^(\d+):(\d+)$/', $key, $matches)) {
      return NULL;
    }
    // Like getTargetOptions(), only a published consumer's targets count.
    $consumer = $this->entityTypeManager->getStorage('consumer')->load($matches[1]);
    if (!$consumer instanceof ConsumerInterface || !$consumer->isPublished()) {
      return NULL;
    }
    $target = $this->getTargets($consumer)[(int) $matches[2]] ?? NULL;
    return $target === NULL ? NULL : $target + ['layouts' => $this->getLayouts($consumer)];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultKey(): ?string {
    $default = Settings::get('consumer_node_preview', [])['default'] ?? NULL;
    if (is_string($default) && $this->getTargetByKey($default) !== NULL) {
      return $default;
    }
    $key = array_key_first($this->getAllTargets());
    return $key === NULL ? NULL : (string) $key;
  }

  /**
   * {@inheritdoc}
   */
  public function getEndpoint(NodeInterface $node_preview, bool $absolute = TRUE): ?string {
    $resource_type = $this->resourceTypeRepository->get('node', $node_preview->bundle());
    if ($resource_type === NULL || $resource_type->isInternal()) {
      return NULL;
    }
    $includes = $this->revisionedIncludes($resource_type, $node_preview->getFieldDefinitions());
    $options = ['absolute' => $absolute];
    if ($includes) {
      $options['query']['include'] = implode(',', $includes);
    }
    try {
      return Url::fromRoute(
        'jsonapi.' . $resource_type->getTypeName() . '.individual.preview',
        ['node_preview' => $node_preview->uuid()],
        $options,
      )->toString();
    }
    catch (RouteNotFoundException) {
      return NULL;
    }
  }

  /**
   * Returns include paths for entity_reference_revisions fields.
   *
   * The paths go into paragraphs inside paragraphs too, so the document
   * carries every unsaved level. The depth is capped, because a paragraph
   * type can hold paragraphs of its own type.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceType $resource_type
   *   The resource type to start from.
   * @param \Drupal\Core\Field\FieldDefinitionInterface[] $definitions
   *   Its field definitions, keyed by field name.
   * @param string $prefix
   *   The path so far, with a trailing dot, or an empty string.
   * @param int $depth
   *   The level of $resource_type, from 1.
   *
   * @return list<string>
   *   The include paths.
   */
  protected function revisionedIncludes(ResourceType $resource_type, array $definitions, string $prefix = '', int $depth = 1): array {
    $paths = [];
    foreach ($definitions as $name => $definition) {
      if ($definition->getType() !== 'entity_reference_revisions' || !$resource_type->isFieldEnabled($name)) {
        continue;
      }
      $public_name = $resource_type->getPublicName($name);
      $paths[] = $prefix . $public_name;
      if ($depth >= self::INCLUDE_DEPTH) {
        continue;
      }
      foreach ($resource_type->getRelatableResourceTypesByField($public_name) as $target) {
        if ($target->isInternal()) {
          continue;
        }
        $target_definitions = $this->entityFieldManager->getFieldDefinitions($target->getEntityTypeId(), $target->getBundle());
        array_push($paths, ...$this->revisionedIncludes($target, $target_definitions, $prefix . $public_name . '.', $depth + 1));
      }
    }
    return array_values(array_unique($paths));
  }

  /**
   * {@inheritdoc}
   */
  public function resolveUrl(string $url, NodeInterface $node_preview, string $view_mode_id, string $layout = ''): string {
    $replacements = [
      '[uuid]' => rawurlencode((string) $node_preview->uuid()),
      '[view_mode]' => rawurlencode($view_mode_id),
      '[layout]' => rawurlencode($layout),
    ];
    // Without an endpoint, the endpoint tokens stay as they are. A visible
    // token is easier to debug than an empty fragment.
    $endpoint = $this->getEndpoint($node_preview);
    if ($endpoint !== NULL) {
      $replacements['[jsonapi_node_preview]'] = $endpoint;
      $replacements['[jsonapi_node_preview_path]'] = (string) $this->getEndpoint($node_preview, FALSE);
    }
    return strtr($url, $replacements);
  }

  /**
   * Returns the site's targets from settings.php.
   *
   * @return array<string, array{label: string, url: string, layouts: list<string>}>
   *   The targets that have a URL, keyed by name. A layout with a name
   *   that does not match the pattern is left out.
   */
  protected function getSiteTargets(): array {
    $targets = [];
    foreach (Settings::get('consumer_node_preview', [])['targets'] ?? [] as $name => $target) {
      $url = trim((string) ($target['url'] ?? ''));
      if ($url !== '') {
        $layouts = array_filter(
          (array) ($target['layouts'] ?? []),
          fn(mixed $layout): bool => is_string($layout) && preg_match(self::LAYOUT_PATTERN, $layout) === 1,
        );
        $targets[(string) $name] = [
          'label' => (string) ($target['label'] ?? $name),
          'url' => $url,
          'layouts' => array_values(array_unique($layouts)),
        ];
      }
    }
    return $targets;
  }

}
