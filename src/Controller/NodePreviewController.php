<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Controller;

use Drupal\consumer_node_preview\PreviewTargetsProviderInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\Controller\NodePreviewController as CoreNodePreviewController;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders a node preview inside the selected frontend.
 *
 * When no frontend is chosen, when the chosen key does not resolve, or when
 * the editor picked the Drupal option, the core preview renders instead.
 */
final class NodePreviewController extends CoreNodePreviewController {

  /**
   * The preview targets provider.
   */
  protected PreviewTargetsProviderInterface $targetsProvider;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->targetsProvider = $container->get('consumer_node_preview.targets_provider');
    $instance->requestStack = $container->get('request_stack');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Entity\EntityInterface $node_preview
   *   The node in the preview temp store.
   * @param string $view_mode_id
   *   The view mode.
   * @param string|null $langcode
   *   The language code.
   */
  public function view(EntityInterface $node_preview, $view_mode_id = 'full', $langcode = NULL) {
    // The core build carries the preview bar in its attachments. Build it
    // first, so the bar and its view mode state stay correct in both
    // branches.
    $core_build = parent::view($node_preview, $view_mode_id, $langcode);

    $request = $this->requestStack->getCurrentRequest();
    $key = $request?->query->get('frontend');
    $target = is_string($key) && $key !== '' ? $this->targetsProvider->getTargetByKey($key) : NULL;
    if ($target === NULL) {
      return $core_build;
    }

    assert($node_preview instanceof NodeInterface);
    return [
      '#theme' => 'consumer_node_preview',
      '#label' => $target['label'],
      '#url' => $this->targetsProvider->resolveUrl($target['url'], $node_preview, $view_mode_id),
      '#view_mode_id' => $view_mode_id,
      '#attached' => $core_build['#attached'] ?? [],
      '#cache' => ['max-age' => 0],
    ];
  }

}
