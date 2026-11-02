<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the preview screen in the themes editors use.
 *
 * The preview is a node operation route. It takes the admin theme while
 * "Use the administration theme when editing" is on, as on a standard site.
 * Without it, it takes the default theme.
 *
 * @group consumer_node_preview
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class NodePreviewThemesTest extends WebDriverTestBase {

  use PreviewBrowserTestTrait;

  /**
   * {@inheritdoc}
   *
   * Admin pages have a toolbar, and Gin 4, for Drupal 10, fails without it.
   */
  protected static $modules = ['node', 'toolbar', 'consumer_node_preview'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    // JSON:API routes a new bundle's preview after a router rebuild.
    $this->container->get('router.builder')->rebuild();
    // The test never reaches a frontend. A Drupal page in the frame would
    // show its own toolbar, whose requests could outlive the test.
    $this->writeSettings([
      'settings' => [
        'consumer_node_preview' => (object) [
          'value' => [
            'targets' => [
              'one' => [
                'label' => 'One',
                'url' => 'http://localhost:9/frontend-one?vm=[view_mode]&layout=[layout]',
                'layouts' => ['default', 'bare_page'],
              ],
              'two' => ['label' => 'Two', 'url' => 'http://localhost:9/frontend-two?vm=[view_mode]'],
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
   * Returns the themes to test.
   *
   * @return array<string, array{string, bool}>
   *   The theme, and whether it is the admin theme used for editing.
   */
  public static function themes(): array {
    return [
      'Claro' => ['claro', TRUE],
      'Gin' => ['gin', TRUE],
      'Olivero, editing in the default theme' => ['olivero', FALSE],
    ];
  }

  /**
   * Tests that the screen's controls show and work in a theme.
   *
   * @dataProvider themes
   */
  #[DataProvider('themes')]
  public function testThePreviewScreenWorksInTheTheme(string $theme, bool $admin): void {
    // The build adds Gin only when Composer can resolve it with the core
    // under test. See scripts/assemble-gin.sh.
    if ($theme === 'gin' && !$this->container->get('extension.list.theme')->exists('gin')) {
      $this->markTestSkipped('The gin theme is not in the codebase, because drupal/gin does not resolve with this core.');
    }
    $this->container->get('theme_installer')->install([$theme]);
    $this->config('system.theme')->set($admin ? 'admin' : 'default', $theme)->save();
    $this->config('node.settings')->set('use_admin_theme', $admin)->save();
    // The Drupal tab renders Olivero's teaser component after a view mode
    // change. Drupal 10 finds a newly installed theme's components only after
    // a full cache clear.
    drupal_flush_all_caches();

    $this->getSession()->resizeWindow(1440, 900);
    $this->drupalGet('node/add/page');
    $page = $this->getSession()->getPage();
    $page->fillField('title[0][value]', 'Theme preview test');
    $page->pressButton('Preview');
    $this->assertTrue($this->getSession()->wait(10000, "!!document.querySelector('[data-consumer-node-preview][data-once]')"), 'The preview page did not open.');
    $this->assertTrue($this->getSession()->evaluateScript("!!document.querySelector('link[rel=\"stylesheet\"][href*=\"/$theme/\"]')"), "The preview is not in $theme.");
    // The targets' frontend never answers, and the browser check says so.
    $this->waitForSilentFrontends();

    // The theme styles the tabs: no list bullets, all on one row.
    $tabs = $this->getSession()->evaluateScript(<<<'JS'
      [...document.querySelectorAll('[data-consumer-node-preview] [role="tab"]')].map((tab) => {
        const item = getComputedStyle(tab.closest('li'));
        return {
          bullet: item.display === 'list-item' && item.listStyleType !== 'none',
          top: Math.round(tab.getBoundingClientRect().top),
        };
      })
      JS);
    $this->assertSame([FALSE, FALSE, FALSE], array_column($tabs, 'bullet'), 'The tabs show list bullets.');
    $this->assertCount(1, array_unique(array_column($tabs, 'top')), 'The tabs are not on one row.');

    // Every control shows, and nothing covers it.
    $this->assertSame([], $this->coveredControls());

    // The controls work. The tabs are found by their own attribute, as admin
    // menus have links with the same text.
    $page->find('css', '[data-consumer-node-preview-layout]')->selectOption('bare_page');
    $this->assertNotNull($this->jsAssert()->waitForElement('css', '[data-consumer-node-preview-frame][src*="layout=bare_page"]'));
    $page->find('css', '[data-consumer-node-preview-target]')->selectOption('Two');
    $this->assertNotNull($this->jsAssert()->waitForElement('css', '[data-consumer-node-preview-frame][src*="/frontend-two"]'));
    // Two lists no layouts, so its picker hides.
    $this->assertFalse($page->find('css', '[data-consumer-node-preview-layout]')->isVisible());
    $page->find('css', '[data-consumer-node-preview-view-mode]')->selectOption('teaser');
    $this->assertNotNull($this->jsAssert()->waitForElement('css', '[data-consumer-node-preview-frame][src*="vm=teaser"]'));
    $page->pressButton('Phone');
    $this->assertSame(390, $this->getSession()->evaluateScript("Math.round(document.querySelector('[data-consumer-node-preview-frame]').getBoundingClientRect().width)"));
    $page->find('css', '[data-consumer-node-preview-tab="drupal"]')->click();
    $this->assertNotNull($this->jsAssert()->waitForElementVisible('css', '#consumer-node-preview-drupal'));
    $page->find('css', '[data-consumer-node-preview-tab="jsonapi"]')->click();
    $this->assertTrue($this->getSession()->wait(10000, "document.querySelector('[data-consumer-node-preview-jsonapi]').textContent.includes('Theme preview test')"));
    $page->find('css', '[data-consumer-node-preview-tab="frontend"]')->click();
    $this->assertNotNull($this->jsAssert()->waitForElementVisible('css', '[data-consumer-node-preview-frame]'));
  }

  /**
   * Returns the controls that do not show, or that something covers.
   *
   * @return string[]
   *   A line per control with a problem.
   */
  protected function coveredControls(): array {
    return $this->getSession()->evaluateScript(<<<'JS'
      [
        '[data-consumer-node-preview-tab="frontend"]',
        '[data-consumer-node-preview-tab="drupal"]',
        '[data-consumer-node-preview-tab="jsonapi"]',
        '[data-consumer-node-preview-view-mode]',
        '[data-consumer-node-preview-target]',
        '[data-consumer-node-preview-layout]',
        '[data-consumer-node-preview-width="390px"]',
        '[data-consumer-node-preview-open]',
        '[data-consumer-node-preview-frame]',
      ].flatMap((selector) => {
        const element = document.querySelector(selector);
        const box = element && element.getBoundingClientRect();
        if (!box || !box.width || !box.height) {
          return [`${selector} does not show`];
        }
        const top = document.elementFromPoint(box.left + box.width / 2, box.top + Math.min(box.height / 2, 20));
        return top === element || element.contains(top) ? [] : [`${selector} is under ${top && top.outerHTML.slice(0, 80)}`];
      })
      JS);
  }

}
