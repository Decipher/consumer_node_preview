<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\FunctionalJavascriptTests\WebDriverWebAssert;
use WebDriver\Service\CurlService;
use WebDriver\ServiceFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the preview screen in a browser.
 *
 * It runs in Stark, so the screen does not depend on an admin theme.
 *
 * @group consumer_node_preview
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class NodePreviewToolbarTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'consumer_node_preview'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Text at the end of the body, past the teaser's 600 character trim.
   */
  protected const BODY_END = 'The body ends here.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    // JSON:API routes a new bundle's preview after a router rebuild.
    $this->container->get('router.builder')->rebuild();
    $this->writeSettings([
      'settings' => [
        'consumer_node_preview' => (object) [
          'value' => [
            'targets' => [
              'one' => ['label' => 'One', 'url' => '/frontend-one?vm=[view_mode]#[jsonapi_node_preview_path]'],
              'two' => ['label' => 'Two', 'url' => '/frontend-two?vm=[view_mode]'],
            ],
            'default' => 'settings:one',
          ],
          'required' => TRUE,
        ],
      ],
    ]);
    $this->drupalLogin($this->rootUser);
  }

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
   * Tests the frontend picker, the widths and remembered choices.
   */
  public function testFrontendWidthsAndJsonApi(): void {
    $this->preview();
    $assert = $this->jsAssert();
    $page = $this->getSession()->getPage();

    $page->find('css', '[data-consumer-node-preview-target]')->selectOption('Two');
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="/frontend-two?vm=full"]'));
    $this->assertStringContainsString('/frontend-two?vm=full', (string) $assert->elementExists('css', '[data-consumer-node-preview-open]')->getAttribute('href'));

    $page->pressButton('Phone');
    $this->assertSame('390px', $this->getSession()->evaluateScript("document.querySelector('[data-consumer-node-preview-viewport]').style.getPropertyValue('--consumer-node-preview-width')"));
    $assert->elementAttributeContains('css', '[data-consumer-node-preview-width="390px"]', 'aria-pressed', 'true');

    // The JSON:API tab loads the unsaved document with the editor's session.
    $page->clickLink('JSON:API');
    $this->assertTrue($this->getSession()->wait(10000, "document.querySelector('[data-consumer-node-preview-jsonapi]').textContent.includes('\"type\": \"node--page\"')"));
    $assert->elementTextContains('css', '[data-consumer-node-preview-jsonapi]', '"title": "Toolbar preview test"');

    // A reload keeps the editor's tab, frontend and width.
    $this->getSession()->reload();
    $this->assertNotNull($assert->waitForElementVisible('css', '#consumer-node-preview-jsonapi'));
    $this->assertStringContainsString('/frontend-two?vm=full', $this->frameSource());
    $assert->elementAttributeContains('css', '[data-consumer-node-preview-width="390px"]', 'aria-pressed', 'true');
  }

  /**
   * Tests the tabs and the link that opens the frontend in a new tab.
   */
  public function testTabsAndTheNewTabLink(): void {
    $this->preview();
    $assert = $this->jsAssert();
    $page = $this->getSession()->getPage();

    // The Drupal tab shows core's own preview of the unsaved page.
    $page->clickLink('Drupal');
    $this->assertNotNull($assert->waitForElementVisible('css', '#consumer-node-preview-drupal'));
    $assert->elementTextContains('css', '#consumer-node-preview-drupal', self::BODY_END);
    $assert->elementNotExists('css', '#consumer-node-preview-frontend:not([hidden])');

    // Core asks before a link leaves the preview. The new tab link keeps the
    // preview open, so it skips that dialog.
    $page->clickLink('Frontend');
    $this->assertNotNull($assert->waitForElementVisible('css', '[data-consumer-node-preview-open]'));
    $page->clickLink('Open in new tab');
    $this->assertFalse($this->getSession()->wait(1000, "!!document.querySelector('.ui-dialog')"), 'Core asked before leaving the preview.');
  }

  /**
   * Creates an unsaved page and opens its preview.
   */
  protected function preview(): void {
    $this->drupalGet('node/add/page');
    $this->getSession()->getPage()->fillField('title[0][value]', 'Toolbar preview test');
    $this->getSession()->getPage()->fillField('body[0][value]', 'Body start. ' . str_repeat('More body text. ', 60) . self::BODY_END);
    $this->getSession()->getPage()->pressButton('Preview');
    // DrupalCI serves the site from a subdirectory, so the path does not
    // start with the preview route.
    $this->assertTrue($this->getSession()->wait(10000, "window.location.pathname.indexOf('/node/preview/') !== -1 && !!document.querySelector('[data-consumer-node-preview]')"), 'The preview page did not open.');
    $this->jsAssert()->addressMatches('#/node/preview/[^/]+/full$#');
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
   * Returns the frontend frame's source.
   */
  protected function frameSource(): string {
    return (string) $this->jsAssert()->elementExists('css', '[data-consumer-node-preview-frame]')->getAttribute('src');
  }

}
