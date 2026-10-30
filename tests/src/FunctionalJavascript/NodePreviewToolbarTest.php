<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\FunctionalJavascript;

use Drupal\consumers\Entity\Consumer;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
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

  use PreviewBrowserTestTrait;

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
              'one' => [
                'label' => 'One',
                'url' => '/frontend-one?vm=[view_mode]&layout=[layout]#[jsonapi_node_preview_path]',
                'layouts' => ['default', 'bare_page'],
              ],
              'two' => ['label' => 'Two', 'url' => '/frontend-two?vm=[view_mode]'],
              'three' => [
                'label' => 'Three',
                'url' => '/frontend-three?vm=[view_mode]&layout=[layout]',
                'layouts' => ['default', 'bare_page'],
              ],
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
   * Tests the view mode switch, which reloads the frame but not the page.
   */
  public function testViewModeSwitchesWithoutPageReload(): void {
    $this->preview();
    $assert = $this->jsAssert();

    // Core's bar keeps only its link back to the edit form.
    $assert->elementExists('css', '.node-preview-container .node-preview-backlink');
    $assert->elementNotExists('css', '.node-preview-container select');
    $this->assertStringContainsString('/frontend-one?vm=full', $this->frameSource());

    // The Drupal tab holds the full body, past the teaser's trim length.
    $this->assertStringContainsString(self::BODY_END, $assert->elementExists('css', '[data-consumer-node-preview-drupal]')->getHtml());

    $this->getSession()->executeScript('window.consumerNodePreviewTest = true;');
    $this->getSession()->executeScript("document.querySelector('[data-consumer-node-preview-drupal] > *').setAttribute('data-test-stale', '1');");
    $history = $this->getSession()->evaluateScript('window.history.length');
    $this->getSession()->getPage()->find('css', '[data-consumer-node-preview-view-mode]')->selectOption('teaser');

    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="vm=teaser"]'));
    $this->assertStringEndsWith('/teaser', (string) parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH));
    $this->assertStringContainsString('vm=teaser', (string) $assert->elementExists('css', '[data-consumer-node-preview-open]')->getAttribute('href'));

    // The Drupal tab fetches the new view mode when it is shown.
    $this->getSession()->getPage()->clickLink('Drupal');
    $this->assertTrue($assert->waitForElementRemoved('css', '[data-consumer-node-preview-drupal] [data-test-stale]'));
    // The teaser trims the body, so the end is gone.
    $this->assertStringNotContainsString(self::BODY_END, $assert->elementExists('css', '[data-consumer-node-preview-drupal]')->getHtml());
    // The frame reloaded without a history entry, so Back leaves the preview.
    $this->assertSame($history, $this->getSession()->evaluateScript('window.history.length'));

    // Checked last, once every step has had time to cause a reload.
    $this->assertTrue($this->getSession()->evaluateScript('window.consumerNodePreviewTest === true'), 'The view mode change reloaded the page.');
  }

  /**
   * Tests the frontend picker, the widths and remembered choices.
   */
  public function testFrontendWidthsAndJsonApi(): void {
    $this->preview();
    $assert = $this->jsAssert();
    $page = $this->getSession()->getPage();

    // The browser ties the Frontend label to the picker. Its id is used once.
    $this->assertSame(1, $this->getSession()->evaluateScript("document.querySelector('[data-consumer-node-preview-target]').labels.length"));
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
   * Tests the layout picker, which follows the framed frontend.
   */
  public function testLayoutFollowsTheFrontend(): void {
    $this->preview();
    $assert = $this->jsAssert();
    $layouts = $assert->elementExists('css', '[data-consumer-node-preview-layout]');
    $targets = $assert->elementExists('css', '[data-consumer-node-preview-target]');
    $this->assertTrue($layouts->isVisible());
    $this->assertStringContainsString('layout=default', $this->frameSource());

    $this->getSession()->executeScript('window.consumerNodePreviewTest = true;');
    $layouts->selectOption('Bare page');
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="layout=bare_page"]'));
    $this->assertStringContainsString('layout=bare_page', (string) $assert->elementExists('css', '[data-consumer-node-preview-open]')->getAttribute('href'));

    // A frontend without layouts hides the picker.
    $targets->selectOption('Two');
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="/frontend-two"]'));
    $this->assertFalse($layouts->isVisible());

    // Back on One, the picker shows One's layout again.
    $targets->selectOption('One');
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="layout=bare_page"]'));
    $this->assertTrue($layouts->isVisible());
    $this->assertSame('bare_page', $layouts->getValue());
    $this->assertTrue($this->getSession()->evaluateScript('window.consumerNodePreviewTest === true'), 'The layout change reloaded the page.');

    // A reload keeps the layout.
    $this->getSession()->reload();
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="layout=bare_page"]'));
  }

  /**
   * Tests that a layout in the URL wins, and an unlisted one is ignored.
   */
  public function testTheUrlLayoutWins(): void {
    $this->preview();
    $assert = $this->jsAssert();
    $page_url = strtok($this->getSession()->getCurrentUrl(), '?');

    // One is the default. Remember bare_page for it, then ask for a layout
    // it does not list: the remembered layout stays.
    $assert->elementExists('css', '[data-consumer-node-preview-layout]')->selectOption('Bare page');
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="/frontend-one"][src*="layout=bare_page"]'));
    $this->getSession()->visit($page_url . '?layout=nope');
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="/frontend-one"][src*="layout=bare_page"]'));

    // Remember Three with its default layout. The server still opens One,
    // so the layout in the URL must reach the remembered Three.
    $assert->elementExists('css', '[data-consumer-node-preview-target]')->selectOption('Three');
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="/frontend-three"][src*="layout=default"]'));
    $this->getSession()->visit($page_url . '?layout=bare_page');
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="/frontend-three"][src*="layout=bare_page"]'));
    $this->assertSame('bare_page', $assert->elementExists('css', '[data-consumer-node-preview-layout]')->getValue());
  }

  /**
   * Tests that the picker groups the targets by consumer.
   *
   * Picking a target inside a group still frames it, and the layout picker
   * still follows it.
   */
  public function testThePickerGroupsTargetsByConsumer(): void {
    $beta = Consumer::create([
      'label' => 'Beta',
      'client_id' => 'beta',
      'preview_targets' => [
        ['label' => 'Staging', 'url' => '/beta-staging?vm=[view_mode]'],
        ['label' => 'Live', 'url' => '/beta-live?vm=[view_mode]'],
      ],
    ]);
    $beta->save();
    $alpha = Consumer::create([
      'label' => 'Alpha',
      'client_id' => 'alpha',
      'preview_targets' => [
        ['label' => 'Local', 'url' => '/alpha-local?vm=[view_mode]&layout=[layout]'],
      ],
      'preview_layouts' => ['default', 'bare_page'],
    ]);
    $alpha->save();

    $this->preview();
    $assert = $this->jsAssert();
    $targets = $assert->elementExists('css', '[data-consumer-node-preview-target]');
    $layouts = $assert->elementExists('css', '[data-consumer-node-preview-layout]');

    // The site's targets come first, then each consumer by label. An option
    // shows the target's own label, because its group names the consumer.
    $groups = $this->getSession()->evaluateScript("Array.from(document.querySelectorAll('[data-consumer-node-preview-target] optgroup')).map((group) => [group.label, Array.from(group.querySelectorAll('option')).map((option) => option.textContent.trim())])");
    $this->assertSame([
      ['Site', ['One', 'Two', 'Three']],
      ['Alpha', ['Local']],
      ['Beta', ['Staging', 'Live']],
    ], $groups);
    $this->assertSame(0, $this->getSession()->evaluateScript("document.querySelectorAll('[data-consumer-node-preview-target] > option').length"));

    $this->getSession()->executeScript('window.consumerNodePreviewTest = true;');
    $targets->selectOption($beta->id() . ':1');
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="/beta-live?vm=full"]'));
    $this->assertStringContainsString('/beta-live?vm=full', (string) $assert->elementExists('css', '[data-consumer-node-preview-open]')->getAttribute('href'));
    $this->assertFalse($layouts->isVisible());

    // The layout picker follows a target in another group.
    $targets->selectOption($alpha->id() . ':0');
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="/alpha-local?vm=full&layout=default"]'));
    $this->assertTrue($layouts->isVisible());
    $layouts->selectOption('Bare page');
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="/alpha-local?vm=full&layout=bare_page"]'));
    $this->assertTrue($this->getSession()->evaluateScript('window.consumerNodePreviewTest === true'), 'Picking a target reloaded the page.');

    // A reload keeps the target picked in a group, by its key.
    $this->getSession()->reload();
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="/alpha-local?vm=full&layout=bare_page"]'));
    $this->assertSame($alpha->id() . ':0', $assert->elementExists('css', '[data-consumer-node-preview-target]')->getValue());
  }

  /**
   * Tests that an expired target shows in the Inactive group.
   *
   * It can still be picked, because picking it may wake a sleeping
   * frontend. Its option names the consumer, as the group does not.
   */
  public function testAnExpiredTargetIsInactiveAndCanBePicked(): void {
    $alpha = Consumer::create([
      'label' => 'Alpha',
      'client_id' => 'alpha',
      'preview_targets' => [
        ['label' => 'Asleep', 'url' => '/alpha-asleep?vm=[view_mode]', 'last_seen' => time() - 2 * 86400],
        ['label' => 'Awake', 'url' => '/alpha-awake?vm=[view_mode]', 'last_seen' => time()],
      ],
    ]);
    $alpha->save();

    $this->preview();
    $assert = $this->jsAssert();
    $groups = $this->getSession()->evaluateScript("Array.from(document.querySelectorAll('[data-consumer-node-preview-target] optgroup')).map((group) => [group.label, Array.from(group.querySelectorAll('option')).map((option) => [option.value, option.textContent.trim()])])");
    $this->assertSame([
      ['Site', [['settings:one', 'One'], ['settings:two', 'Two'], ['settings:three', 'Three']]],
      ['Alpha', [[$alpha->id() . ':1', 'Awake']]],
      ['Inactive', [[$alpha->id() . ':0', 'Alpha: Asleep']]],
    ], $groups);

    $this->getSession()->executeScript('window.consumerNodePreviewTest = true;');
    $assert->elementExists('css', '[data-consumer-node-preview-target]')->selectOption($alpha->id() . ':0');
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="/alpha-asleep?vm=full"]'));
    $this->assertStringContainsString('/alpha-asleep?vm=full', (string) $assert->elementExists('css', '[data-consumer-node-preview-open]')->getAttribute('href'));
    $this->assertTrue($this->getSession()->evaluateScript('window.consumerNodePreviewTest === true'), 'Picking a target reloaded the page.');

    // The key stays the same, so a link to the target still opens it.
    $this->drupalGet($this->getSession()->getCurrentUrl(), ['query' => ['frontend' => $alpha->id() . ':0']]);
    $this->assertNotNull($assert->waitForElement('css', '[data-consumer-node-preview-frame][src*="/alpha-asleep?vm=full"]'));
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
   * Returns the frontend frame's source.
   */
  protected function frameSource(): string {
    return (string) $this->jsAssert()->elementExists('css', '[data-consumer-node-preview-frame]')->getAttribute('src');
  }

}
