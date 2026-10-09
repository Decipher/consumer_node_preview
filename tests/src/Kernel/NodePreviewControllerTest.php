<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\consumer_node_preview\Controller\NodePreviewController;
use Drupal\consumers\Entity\Consumer;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the tabbed preview built around core's node preview.
 *
 * @group consumer_node_preview
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class NodePreviewControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'filter',
    'text',
    'node',
    'path_alias',
    'serialization',
    'entity_reference_revisions',
    'jsonapi',
    'jsonapi_node_preview',
    'consumers',
    'consumer_node_preview',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('consumer');
    $this->installConfig(['system', 'node', 'filter']);
    NodeType::create(['type' => 'page', 'name' => 'Page', 'display_submitted' => FALSE])->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Tests that the preview route is a node operation on this controller.
   */
  public function testThePreviewRouteIsNodeOperation(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('entity.node.preview');
    $this->assertSame(NodePreviewController::class . '::view', $route->getDefault('_controller'));
    $this->assertTrue($route->getOption('_node_operation_route'));
  }

  /**
   * Tests that core's preview bar stays where the page renderer reads it.
   */
  public function testCoresPreviewBarStaysAtTheTopOfThePage(): void {
    $this->assertArrayHasKey('node_preview', $this->preview()['#attached']['page_top'] ?? []);
  }

  /**
   * Tests that the page has the three tabs.
   */
  public function testThePageHasThreeTabs(): void {
    $html = $this->html();
    $this->assertSame(3, substr_count($html, 'role="tab"'));
    foreach (['frontend', 'drupal', 'jsonapi'] as $tab) {
      $this->assertStringContainsString('id="consumer-node-preview-' . $tab . '"', $html);
    }
  }

  /**
   * Tests that without targets the frontend tab says so, and Drupal opens.
   */
  public function testWithoutTargetsTheFrontendTabSaysSo(): void {
    $html = $this->html();
    $this->assertStringContainsString('data-consumer-node-preview-unconfigured', $html);
    $this->assertStringNotContainsString('<iframe', $html);
    $this->assertStringContainsString('id="consumer-node-preview-drupal-tab" class="tabs__link is-active"', $html);
  }

  /**
   * Tests that a site target from settings.php is framed with its tokens.
   */
  public function testSiteTargetIsFramed(): void {
    $this->setSetting('consumer_node_preview', [
      'targets' => [
        'frontend' => [
          'label' => 'Frontend',
          'url' => '/node/preview?vm=[view_mode]#[jsonapi_node_preview_path]',
        ],
      ],
    ]);
    $node = $this->node();
    $html = $this->html($node);
    $this->assertStringContainsString('<iframe src="/node/preview?vm=full#/jsonapi/node/page/' . $node->uuid() . '/preview"', $html);
    $this->assertStringNotContainsString('data-consumer-node-preview-unconfigured', $html);
    $this->assertStringNotContainsString('data-consumer-node-preview-target', $html);
  }

  /**
   * Tests that the default setting picks the target the frame opens on.
   */
  public function testTheDefaultTargetOpens(): void {
    $this->setSetting('consumer_node_preview', [
      'targets' => [
        'local' => ['label' => 'Local', 'url' => 'http://localhost:3000/[uuid]'],
        'live' => ['label' => 'Live', 'url' => 'https://example.com/[uuid]'],
      ],
      'default' => 'settings:live',
    ]);
    $node = $this->node();
    $html = $this->html($node);
    $this->assertStringContainsString('<iframe src="https://example.com/' . $node->uuid() . '"', $html);
    $this->assertStringContainsString('data-consumer-node-preview-target', $html);
  }

  /**
   * Tests that a consumer's target is listed, and the query picks it.
   */
  public function testTheQueryPicksConsumerTarget(): void {
    $consumer = Consumer::create([
      'label' => 'Nuxt',
      'client_id' => 'nuxt',
      'preview_targets' => [['label' => 'Staging', 'url' => 'https://staging.example.com/preview#[jsonapi_node_preview]']],
    ]);
    $consumer->save();
    $this->setSetting('consumer_node_preview', [
      'targets' => ['local' => ['label' => 'Local', 'url' => 'http://localhost:3000/']],
    ]);
    $request = Request::create('/', 'GET', ['frontend' => $consumer->id() . ':0']);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $node = $this->node();
    $html = $this->html($node);
    $this->assertStringContainsString('Nuxt: Staging', $html);
    $this->assertMatchesRegularExpression('#<iframe src="https://staging\.example\.com/preview\#http[^"]*/jsonapi/node/page/' . $node->uuid() . '/preview"#', $html);
  }

  /**
   * Tests that the JSON:API tab and token include revisioned references.
   */
  public function testTheEndpointIncludesRevisionedReferences(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_content',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_content',
      'entity_type' => 'node',
      'bundle' => 'page',
    ])->save();
    $this->container->get('router.builder')->rebuild();

    $node = $this->node();
    $this->assertSame('/jsonapi/node/page/' . $node->uuid() . '/preview?include=field_content', $this->preview($node)['#jsonapi_url']);
  }

  /**
   * Returns an unsaved page, flagged as core's Preview button leaves it.
   */
  protected function node(): NodeInterface {
    $node = Node::create(['type' => 'page', 'title' => 'Unsaved']);
    $node->in_preview = TRUE;
    return $node;
  }

  /**
   * Returns the controller's build for a node's preview.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  protected function preview(?NodeInterface $node = NULL): array {
    return NodePreviewController::create($this->container)->view($node ?? $this->node(), 'full');
  }

  /**
   * Returns the preview rendered to HTML.
   */
  protected function html(?NodeInterface $node = NULL): string {
    $build = $this->preview($node);
    return (string) $this->render($build);
  }

}
