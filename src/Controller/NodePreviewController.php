<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\consumer_node_preview\PreviewTargetsProviderInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\node\Controller\NodePreviewController as CoreNodePreviewController;
use Drupal\node\Form\NodePreviewForm;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders core's node preview as tabs: the frontend, Drupal and JSON:API.
 *
 * The frontend tab frames a preview target. It opens on the target in the
 * "frontend" query parameter, else the site's default target. With no
 * targets, it says how to add one.
 *
 * Core's View mode select sits with the tabs. A change of view mode reloads
 * only the frame and the Drupal tab, not the page.
 */
final class NodePreviewController extends CoreNodePreviewController {

  use StringTranslationTrait;

  /**
   * Stands in for the view mode in URLs that the page's script completes.
   */
  public const VIEW_MODE_PLACEHOLDER = '__consumer_node_preview_view_mode__';

  /**
   * The preview targets provider.
   */
  protected PreviewTargetsProviderInterface $targetsProvider;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * The form builder, for core's View mode form.
   */
  protected FormBuilderInterface $previewFormBuilder;

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
    $instance->previewFormBuilder = $container->get('form_builder');
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
    $templates = [];
    foreach ($this->targetsProvider->getAllTargets() as $key => $target) {
      $template = UrlHelper::stripDangerousProtocols($this->targetsProvider->resolveUrl($target['url'], $node_preview, self::VIEW_MODE_PLACEHOLDER));
      $templates[$key] = ['label' => $target['label'], 'url' => $template];
      $targets[$key] = [
        'label' => $target['label'],
        'url' => str_replace(self::VIEW_MODE_PLACEHOLDER, rawurlencode($view_mode_id), $template),
      ];
    }
    $requested = $this->requestStack->getCurrentRequest()?->query->get('frontend');
    $active = is_string($requested) && isset($targets[$requested]) ? $requested : $this->targetsProvider->getDefaultKey();
    if ($active === NULL || !isset($targets[$active])) {
      $active = array_key_first($targets);
    }

    $parameters = ['node_preview' => $node_preview->uuid(), 'view_mode_id' => self::VIEW_MODE_PLACEHOLDER];
    $attached['drupalSettings']['consumerNodePreview'] = [
      'placeholder' => self::VIEW_MODE_PLACEHOLDER,
      'viewMode' => $view_mode_id,
      'active' => $active,
      'targets' => $templates,
      'previewUrl' => Url::fromRoute('entity.node.preview', $parameters)->toString(),
      'drupalUrl' => Url::fromRoute('consumer_node_preview.drupal', $parameters)->toString(),
    ];

    return [
      '#theme' => 'consumer_node_preview',
      '#title' => $node_preview->label(),
      '#drupal' => $drupal,
      '#view_mode' => $this->viewModeForm($node_preview),
      '#frontend' => $targets ? $this->frontendSelect($targets, (string) $active) : [],
      '#targets' => $targets,
      '#active_target' => $active,
      '#jsonapi_url' => $this->targetsProvider->getEndpoint($node_preview, FALSE),
      '#no_targets' => $this->warning($this->t("No frontend preview is set up. Add a preview target to a consumer, or to <code>\$settings['consumer_node_preview']</code> in settings.php. See the Consumer Node Preview README.")),
      '#no_document' => $this->warning($this->t('JSON:API has no preview for this content type.')),
      '#attached' => $attached,
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Returns the Drupal tab's content in another view mode, over AJAX.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node_preview
   *   The node in the preview temp store.
   * @param string $view_mode_id
   *   The view mode.
   * @param string|null $langcode
   *   The language code.
   */
  public function drupal(EntityInterface $node_preview, $view_mode_id = 'full', $langcode = NULL): AjaxResponse {
    $build = parent::view($node_preview, $view_mode_id, $langcode);
    unset($build['#attached']['page_top']);
    return (new AjaxResponse())->addCommand(new HtmlCommand('[data-consumer-node-preview-drupal]', $build));
  }

  /**
   * Builds core's View mode form for the tab row.
   *
   * Its link back to the edit form stays in core's bar. Its auto-submit is
   * left off, so the page's script changes view mode without a reload. The
   * Switch button still works without JavaScript.
   *
   * @return array<string, mixed>
   *   The form.
   */
  protected function viewModeForm(NodeInterface $node_preview): array {
    // What getForm() does, with the node passed as the form's argument.
    $form_state = (new FormState())->addBuildInfo('args', [$node_preview]);
    $form = $this->previewFormBuilder->buildForm(NodePreviewForm::class, $form_state);
    $form['backlink']['#access'] = FALSE;
    $form['#attributes']['class'][] = 'container-inline';
    unset($form['view_mode']['#attributes']['data-drupal-autosubmit']);
    $form['view_mode']['#attributes']['data-consumer-node-preview-view-mode'] = '';
    return $form;
  }

  /**
   * Builds the frontend picker.
   *
   * It shows even with one target, so the editor sees which one is framed.
   *
   * @param array<string, array{label: string, url: string}> $targets
   *   The targets, keyed by target key.
   * @param string $active
   *   The key of the framed target.
   *
   * @return array<string, mixed>
   *   The select element.
   */
  protected function frontendSelect(array $targets, string $active): array {
    return [
      '#type' => 'select',
      '#title' => $this->t('Frontend'),
      '#options' => array_map(fn(array $target): string => $target['label'], $targets),
      '#value' => $active,
      '#name' => 'frontend',
      '#id' => 'consumer-node-preview-target',
      '#attributes' => ['data-consumer-node-preview-target' => ''],
      '#wrapper_attributes' => ['class' => ['container-inline']],
    ];
  }

  /**
   * Builds a warning in the active theme's own message style.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  protected function warning(mixed $message): array {
    return [
      '#theme' => 'status_messages',
      '#message_list' => ['warning' => [$message]],
      '#status_headings' => ['warning' => $this->t('Warning message')],
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
