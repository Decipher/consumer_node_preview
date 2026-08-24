<?php

declare(strict_types=1);

namespace Drupal\consumer_node_preview\Routing;

use Drupal\consumer_node_preview\Controller\NodePreviewController;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Points the node preview route at this module's controller.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('entity.node.preview')) {
      $route->setDefault('_controller', NodePreviewController::class . '::view');
    }
  }

}
