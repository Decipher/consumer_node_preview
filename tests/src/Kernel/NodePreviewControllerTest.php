<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\consumer_node_preview\Controller\NodePreviewController;
use Drupal\consumers\Entity\Consumer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Controller\NodePreviewController as CoreNodePreviewController;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\Form\NodePreviewForm;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the frontend preview built around core's node preview.
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
    'jsonapi',
    'jsonapi_node_preview',
    'consumers',
    'consumer_node_preview',
  ];

  /**
   * A consumer with one preview target.
   *
   * @var \Drupal\consumers\Entity\Consumer
   */
  protected $consumer;

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
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->container->get('router.builder')->rebuild();

    $this->consumer = Consumer::create([
      'label' => 'Nuxt',
      'client_id' => 'nuxt',
      'preview_targets' => [
        ['label' => 'Staging', 'url' => 'https://staging.example.com/preview?vm=[view_mode]#[jsonapi_node_preview]'],
      ],
    ]);
    $this->consumer->save();
  }

  /**
   * Tests that without a frontend in the query, core's preview shows.
   */
  public function testWithoutFrontendCoresPreviewShows(): void {
    $build = $this->preview();
    $this->assertArrayHasKey('#node', $build);
    $this->assertNotSame('consumer_node_preview', $build['#theme'] ?? NULL);
  }

  /**
   * Tests that a frontend key that matches no target shows core's preview.
   */
  public function testUnknownFrontendCoresPreviewShows(): void {
    foreach (['nonsense', $this->consumer->id() . ':9', '99:0'] as $key) {
      $build = $this->preview($key);
      $this->assertArrayHasKey('#node', $build, $key);
      $this->assertNotSame('consumer_node_preview', $build['#theme'] ?? NULL, $key);
    }
  }

  /**
   * Tests that a frontend key frames the target with its tokens replaced.
   */
  public function testFrontendTargetIsFramed(): void {
    $node = $this->node();
    $build = $this->preview($this->consumer->id() . ':0', $node);
    $this->assertSame('consumer_node_preview', $build['#theme']);
    $this->assertSame('Staging', $build['#label']);
    $this->assertSame('teaser', $build['#view_mode_id']);
    $this->assertSame(0, $build['#cache']['max-age']);
    $this->assertMatchesRegularExpression('#^https://staging\.example\.com/preview\?vm=teaser\#http[^"]*/jsonapi/node/page/' . $node->uuid() . '/preview$#', $build['#url']);

    // Core's preview bar and its libraries stay on the page.
    $core = CoreNodePreviewController::create($this->container)->view($node, 'teaser');
    $this->assertSame(array_keys($core['#attached']), array_keys($build['#attached']));
    $this->assertContains('node/drupal.node.preview', $build['#attached']['library']);

    $html = (string) $this->render($build);
    $this->assertStringContainsString('<iframe src="https://staging.example.com/preview?vm=teaser#http', $html);
  }

  /**
   * Tests that core's preview bar lists the targets, with the current one.
   */
  public function testPreviewBarListsTheTargets(): void {
    $key = $this->consumer->id() . ':0';
    $build = $this->preview($key);
    $form = $build['#attached']['page_top']['node_preview']['view_mode'] ?? NULL;
    if ($form === NULL) {
      // Drupal 10 core adds the bar in hook_page_top(), so build it here.
      $form = $this->container->get('form_builder')->getForm(NodePreviewForm::class, $this->node());
    }
    $this->assertSame(['' => '- Drupal -', $key => 'Nuxt: Staging'], array_map(strval(...), $form['frontend']['#options']));
    $this->assertSame($key, $form['frontend']['#value']);
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
   * @param string|null $frontend
   *   The frontend query parameter, if any.
   * @param \Drupal\node\NodeInterface|null $node
   *   The node, or NULL for a new one.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  protected function preview(?string $frontend = NULL, ?NodeInterface $node = NULL): array {
    $request = Request::create('/', 'GET', $frontend === NULL ? [] : ['frontend' => $frontend]);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    return NodePreviewController::create($this->container)->view($node ?? $this->node(), 'teaser');
  }

}
