<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\consumer_node_preview\PreviewTargetsProviderInterface;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
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
   * The theme manager.
   */
  protected ThemeManagerInterface $themeManager;

  /**
   * The library discovery.
   */
  protected LibraryDiscoveryInterface $libraryDiscovery;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->targetsProvider = $container->get('consumer_node_preview.targets_provider');
    $instance->requestStack = $container->get('request_stack');
    $instance->themeManager = $container->get('theme.manager');
    $instance->libraryDiscovery = $container->get('library.discovery');
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
    // Drupal 11.4 core attaches its preview bar to page_top, and the page
    // renderer reads page_top only from the top of the main content. So it
    // moves up to this build. Drupal 10 core adds the bar in hook_page_top()
    // and rejects page_top in #attached, so then there is nothing to move.
    $attached = ['library' => ['consumer_node_preview/preview']];
    // Olivero styles tabs in a library that only its own local tasks attach.
    // So the page attaches the theme's tabs library, when it has one.
    $tabs = $this->themeTabsLibrary();
    if ($tabs !== NULL) {
      $attached['library'][] = $tabs;
    }
    if (isset($drupal['#attached']['page_top'])) {
      $attached['page_top'] = $drupal['#attached']['page_top'];
      unset($drupal['#attached']['page_top']);
    }

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
      '#attached' => $attached,
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Returns the tabs library of the active theme, or of its nearest base theme.
   *
   * @return string|null
   *   The library, such as "olivero/tabs", or NULL when there is none.
   */
  protected function themeTabsLibrary(): ?string {
    $theme = $this->themeManager->getActiveTheme();
    foreach ([$theme->getName(), ...array_keys($theme->getBaseThemeExtensions())] as $name) {
      if ($this->libraryDiscovery->getLibraryByName($name, 'tabs') !== FALSE) {
        return $name . '/tabs';
      }
    }
    return NULL;
  }

}
