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

  /**
   * Waits until the frontend check marks every target as not responding.
   *
   * Use it when all the targets are on one origin that does not answer.
   * The check then made one request, which failed. Core's test wrapper
   * around fetch() adds one to its count when a request starts. It takes
   * one away only when the request succeeds. So this takes away the failed
   * one, else the test fails on tear down with "Unfinished AJAX requests".
   */
  protected function waitForSilentFrontends(): void {
    $this->assertTrue($this->getSession()->wait(10000, "Array.from(document.querySelectorAll('[data-consumer-node-preview-target] option')).every((option) => option.hasAttribute('data-consumer-node-preview-not-responding') && option.textContent.endsWith(' (not responding)'))"), 'The frontend check did not mark the targets.');
    $this->getSession()->executeScript('window.drupalActiveXhrCount -= 1;');
  }

}
