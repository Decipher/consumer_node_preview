<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverWebAssert;
use WebDriver\Service\CurlService;
use WebDriver\ServiceFactory;

/**
 * Makes the browser tests work the same on Drupal 10 and 11.
 */
trait PreviewBrowserTestTrait {

  /**
   * {@inheritdoc}
   *
   * Drupal 10's driver sends its requests through core's
   * WebDriverCurlService, and current ChromeDriver answers those with a 400.
   * Drupal 11 uses the WebDriver library's own curl service, so this does the
   * same on Drupal 10. On Drupal 11 it changes nothing.
   */
  protected function getDefaultDriverInstance() {
    $driver = parent::getDefaultDriverInstance();
    ServiceFactory::getInstance()->setServiceClass('service.curl', CurlService::class);
    return $driver;
  }

  /**
   * Returns the JavaScript assertions, typed the same on Drupal 10 and 11.
   *
   * It is what assertSession() returns on both versions.
   */
  protected function jsAssert(): WebDriverWebAssert {
    return new WebDriverWebAssert($this->getSession(), $this->baseUrl);
  }

}
