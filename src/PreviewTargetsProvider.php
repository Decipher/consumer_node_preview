<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
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

  /**
   * The settings.php targets already logged as left out, keyed by name.
   *
   * @var array<string, true>
   */
  protected array $leftOut = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ResourceTypeRepositoryInterface $resourceTypeRepository,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected TimeInterface $time,
    protected PreviewToken $previewToken,
    protected ConfigFactoryInterface $configFactory,
    protected ?LoggerInterface $logger = NULL,
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
    // The site's own targets share one group. This label and the inactive
    // one are not likely labels for a consumer.
    $site = (string) new TranslatableMarkup('Site settings');
    foreach ($this->getSiteTargets() as $name => $target) {
      $targets['settings:' . $name] = $target + [
        'group' => $site,
        'option_label' => $target['label'],
        'last_seen' => NULL,
        'inactive' => FALSE,
      ];
    }
    // An inactive target goes in a group at the end. Its option names the
    // consumer, because the group does not.
    $inactive = [];
    $inactive_group = (string) new TranslatableMarkup('Inactive targets');
    $inactive_before = $this->time->getRequestTime() - $this->getInactiveAfter();
    $storage = $this->entityTypeManager->getStorage('consumer');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->sort('label')
      ->execute();
    /** @var \Drupal\consumers\Entity\ConsumerInterface $consumer */
    foreach ($storage->loadMultiple($ids) as $consumer) {
      $layouts = $this->getLayouts($consumer);
      foreach ($consumer->get('preview_targets') as $delta => $item) {
        $last_seen = $item->get('last_seen')->getValue();
        $last_seen = $last_seen === NULL ? NULL : (int) $last_seen;
        $url = (string) $item->get('url')->getValue();
        if (!$this->allowsStoredUrl($url)) {
          continue;
        }
        $label = (string) $item->get('label')->getValue();
        $target = [
          'label' => $consumer->label() . ': ' . $label,
          'url' => $url,
          'layouts' => $layouts,
          'consumer' => $consumer,
          'group' => (string) $consumer->label(),
          'option_label' => $label,
          'last_seen' => $last_seen,
          'inactive' => FALSE,
        ];
        if ($last_seen !== NULL && $last_seen < $inactive_before) {
          $inactive[$consumer->id() . ':' . $delta] = [
            'group' => $inactive_group,
            'option_label' => $target['label'],
            'inactive' => TRUE,
          ] + $target;
        }
        else {
          $targets[$consumer->id() . ':' . $delta] = $target;
        }
      }
    }
    return $targets + $inactive;
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
  public function getGroupedTargetOptions(?array $targets = NULL): array {
    $targets ??= $this->getAllTargets();
    $counts = [];
    foreach ($targets as $target) {
      $counts[$target['group']][$target['option_label']] = ($counts[$target['group']][$target['option_label']] ?? 0) + 1;
    }
    $options = [];
    foreach ($targets as $key => $target) {
      $label = $target['option_label'];
      // Options that read the same in a group add their host. A path has none.
      $host = parse_url($target['url'], PHP_URL_HOST);
      if ($counts[$target['group']][$label] > 1 && $host) {
        $port = parse_url($target['url'], PHP_URL_PORT);
        $label = (string) new TranslatableMarkup('@label (@host)', [
          '@label' => $label,
          '@host' => $host . ($port ? ':' . $port : ''),
        ]);
      }
      $options[$target['group']][(string) $key] = $label;
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
    if ($target === NULL || !$this->allowsStoredUrl($target['url'])) {
      return NULL;
    }
    return $target + ['layouts' => $this->getLayouts($consumer), 'consumer' => $consumer];
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
  public function resolveUrl(string $url, NodeInterface $node_preview, string $view_mode_id, string $layout = '', ?ConsumerInterface $consumer = NULL): string {
    $replacements = [
      '[uuid]' => rawurlencode((string) $node_preview->uuid()),
      '[view_mode]' => rawurlencode($view_mode_id),
      '[layout]' => rawurlencode($layout),
    ];
    // Only a URL that asks for one gets a token, so a frontend that reads the
    // preview with the editor's session never holds one.
    if (str_contains($url, '[preview_token]')) {
      $replacements['[preview_token]'] = $consumer ? $this->previewToken->create($consumer) : '';
    }
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
   * {@inheritdoc}
   */
  public function getInactiveAfter(): int {
    return $this->secondsSetting('inactive_after', self::INACTIVE_AFTER);
  }

  /**
   * {@inheritdoc}
   */
  public function getDeleteAfter(): int {
    return $this->secondsSetting('delete_after', self::DELETE_AFTER);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteExpiredTargets(): int {
    $before = $this->time->getRequestTime() - $this->getDeleteAfter();
    $storage = $this->entityTypeManager->getStorage('consumer');
    // Unpublished consumers too: their targets would show again on publish.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('preview_targets.last_seen', $before, '<')
      ->execute();
    $deleted = 0;
    /** @var \Drupal\consumers\Entity\ConsumerInterface $consumer */
    foreach ($storage->loadMultiple($ids) as $consumer) {
      $targets = $consumer->get('preview_targets');
      $count = $targets->count();
      $targets->filter(function ($item) use ($before): bool {
        $last_seen = $item->get('last_seen')->getValue();
        return $last_seen === NULL || (int) $last_seen >= $before;
      });
      if ($targets->count() < $count) {
        $deleted += $count - $targets->count();
        $consumer->save();
      }
    }
    return $deleted;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllowedHosts(): array {
    $hosts = $this->configFactory->get('consumer_node_preview.settings')->get('allowed_target_hosts');
    return is_array($hosts) ? array_values(array_map(
      fn(mixed $host): string => strtolower((string) $host),
      $hosts,
    )) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function isAllowedHost(string $host): bool {
    $allowed = $this->getAllowedHosts();
    if (!$allowed) {
      return TRUE;
    }
    foreach ($allowed as $pattern) {
      if ($host === $pattern) {
        return TRUE;
      }
      if (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)) && strlen($host) > strlen($pattern) - 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasPlainAuthority(string $url): bool {
    // Read the URL as a browser does. It drops leading and trailing spaces and
    // control characters, removes tabs and line breaks, and reads a backslash
    // as a slash.
    $url = str_replace(["\t", "\n", "\r", '\\'], ['', '', '', '/'], trim($url, "\x00..\x20"));
    // For http and https, a browser reads any number of slashes after the
    // scheme, even none, as the start of the host part. Other URLs have one
    // only after "//". A path, such as a settings.php target's, has none.
    if (!preg_match('#^https?:/*([^/?\#]*)#i', $url, $matches) && !preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//([^/?\#]*)#i', $url, $matches)) {
      return TRUE;
    }
    // A user name ends at "@". A token is anything in square brackets other
    // than an IPv6 address, which holds only hex digits, colons and dots.
    return !str_contains($matches[1], '@') && !preg_match('/\[(?![0-9a-f:.]*\])[^\]]*\]/i', $matches[1]);
  }

  /**
   * Whether a saved target's URL is on a host the site still allows.
   *
   * The allowed hosts can change after a target is saved. A target outside
   * them must not get a preview token.
   *
   * @param string $url
   *   The target's URL template.
   */
  protected function allowsStoredUrl(string $url): bool {
    if (!$this->hasPlainAuthority($url)) {
      return FALSE;
    }
    if (!$this->getAllowedHosts()) {
      return TRUE;
    }
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) && !str_contains($url, '\\') && $this->isAllowedHost(strtolower($host));
  }

  /**
   * Returns a number of seconds from the module's settings, or its default.
   *
   * @param string $name
   *   The key in consumer_node_preview.settings.
   * @param int $default
   *   The value when the setting is missing, or is not a whole number above
   *   zero.
   */
  protected function secondsSetting(string $name, int $default): int {
    $value = $this->configFactory->get('consumer_node_preview.settings')->get($name);
    return is_int($value) && $value > 0 ? $value : $default;
  }

  /**
   * Returns the site's targets from settings.php.
   *
   * @return array<string, array{label: string, url: string, layouts: list<string>, consumer: \Drupal\consumers\Entity\ConsumerInterface|null}>
   *   The targets that have a URL, keyed by name. A layout with a name
   *   that does not match the pattern is left out. The consumer is the one
   *   whose client ID the target names, if it is published.
   */
  protected function getSiteTargets(): array {
    $targets = [];
    foreach (Settings::get('consumer_node_preview', [])['targets'] ?? [] as $name => $target) {
      $url = trim((string) ($target['url'] ?? ''));
      if ($url !== '' && !$this->hasPlainAuthority($url)) {
        // The URL check never sees settings.php, so say why the target is
        // gone. Once per request is enough.
        if (!isset($this->leftOut[$name])) {
          $this->leftOut[$name] = TRUE;
          $this->logger?->warning('Left out the settings.php preview target @name: its URL has a user name or a token before the path.', ['@name' => $name]);
        }
        continue;
      }
      if ($url !== '') {
        $layouts = array_filter(
          (array) ($target['layouts'] ?? []),
          fn(mixed $layout): bool => is_string($layout) && preg_match(self::LAYOUT_PATTERN, $layout) === 1,
        );
        $targets[(string) $name] = [
          'label' => (string) ($target['label'] ?? $name),
          'url' => $url,
          'layouts' => array_values(array_unique($layouts)),
          'consumer' => is_string($target['consumer'] ?? NULL) ? $this->loadConsumer($target['consumer']) : NULL,
        ];
      }
    }
    return $targets;
  }

  /**
   * Loads a published consumer by its client ID.
   *
   * @param string $client_id
   *   The client ID.
   */
  protected function loadConsumer(string $client_id): ?ConsumerInterface {
    $consumers = $this->entityTypeManager->getStorage('consumer')->loadByProperties([
      'client_id' => $client_id,
      'status' => 1,
    ]);
    $consumer = reset($consumers);
    return $consumer instanceof ConsumerInterface ? $consumer : NULL;
  }

}
