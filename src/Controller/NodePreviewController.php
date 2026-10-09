<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\consumer_node_preview\PreviewTargetsProviderInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\Controller\NodePreviewController as CoreNodePreviewController;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders core's node preview as tabs: the frontend, Drupal and JSON:API.
 *
 * The frontend tab frames a preview target. It opens on the target in the
 * "frontend" query parameter, else the site's default target. With no
 * targets, it says how to add one.
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
  public function view(EntityInterface $node_preview, $view_mode_id = 'full', $langcode = NULL): array {
    $drupal = parent::view($node_preview, $view_mode_id, $langcode);
    // The page renderer reads page_top only from the top of the main content,
    // and core's preview bar is there.
    $page_top = $drupal['#attached']['page_top'] ?? [];
    unset($drupal['#attached']['page_top']);

    assert($node_preview instanceof NodeInterface);
    $targets = [];
    foreach ($this->targetsProvider->getAllTargets() as $key => $target) {
      $targets[$key] = [
        'label' => $target['label'],
        'url' => UrlHelper::stripDangerousProtocols($this->targetsProvider->resolveUrl($target['url'], $node_preview, $view_mode_id)),
      ];
    }
    $requested = $this->requestStack->getCurrentRequest()?->query->get('frontend');
    $active = is_string($requested) && isset($targets[$requested]) ? $requested : $this->targetsProvider->getDefaultKey();

    return [
      '#theme' => 'consumer_node_preview',
      '#title' => $node_preview->label(),
      '#drupal' => $drupal,
      '#targets' => $targets,
      '#active_target' => $active,
      '#jsonapi_url' => $this->targetsProvider->getEndpoint($node_preview, FALSE),
      '#attached' => [
        'library' => ['consumer_node_preview/preview'],
        'page_top' => $page_top,
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
