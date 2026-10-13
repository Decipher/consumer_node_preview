<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the JSON:API preview endpoint that the provider builds.
 *
 * @group consumer_node_preview
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class PreviewEndpointTest extends KernelTestBase {

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
    'serialization',
    'entity_reference_revisions',
    'entity_test',
    'jsonapi',
    'jsonapi_node_preview',
    'consumers',
    'consumer_node_preview',
  ];

  /**
   * The targets provider.
   *
   * @var \Drupal\consumer_node_preview\PreviewTargetsProviderInterface
   */
  protected $provider;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('consumer');
    $this->installConfig(['system', 'node']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->container->get('router.builder')->rebuild();
    $this->provider = $this->container->get('consumer_node_preview.targets_provider');
  }

  /**
   * Tests the endpoint as an absolute URL and as a path.
   */
  public function testEndpoint(): void {
    $node = $this->node('page');
    $this->assertMatchesRegularExpression('#^https?://[^/]+.*/jsonapi/node/page/' . $node->uuid() . '/preview$#', (string) $this->provider->getEndpoint($node));
    $this->assertStringEndsWith('/jsonapi/node/page/' . $node->uuid() . '/preview', (string) $this->provider->getEndpoint($node, FALSE));
    $this->assertStringStartsWith('/', (string) $this->provider->getEndpoint($node, FALSE));
  }

  /**
   * Tests that a bundle without a JSON:API resource type has no endpoint.
   */
  public function testNoEndpointWithoutResourceType(): void {
    $node = $this->node('missing');
    $this->assertNull($this->provider->getEndpoint($node));
    $this->assertNull($this->provider->getEndpoint($node, FALSE));
  }

  /**
   * Tests that there is no endpoint when the preview route is gone.
   *
   * Another module can remove the route. The preview must still open.
   */
  public function testNoEndpointWithoutPreviewRoute(): void {
    // The test route provider builds the router when it is first used. So use
    // it before the route is deleted.
    $route_provider = $this->container->get('router.route_provider');
    $route_provider->reset();
    $this->container->get('database')->delete('router')
      ->condition('name', 'jsonapi.node--page.individual.preview')
      ->execute();
    $route_provider->reset();

    $this->assertNull($this->provider->getEndpoint($this->node('page')));
  }

  /**
   * Tests that the includes stop at a resource type JSON:API keeps internal.
   */
  public function testIncludesStopAtInternalTypes(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_internal',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'settings' => ['target_type' => 'entity_test_no_label'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_internal',
      'entity_type' => 'node',
      'bundle' => 'page',
    ])->save();
    // The internal type has a revisioned field of its own. So only the stop
    // at internal types keeps "field_internal.field_nested" out.
    $this->installEntitySchema('entity_test_no_label');
    FieldStorageConfig::create([
      'field_name' => 'field_nested',
      'entity_type' => 'entity_test_no_label',
      'type' => 'entity_reference_revisions',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_nested',
      'entity_type' => 'entity_test_no_label',
      'bundle' => 'entity_test_no_label',
    ])->save();
    $this->container->get('router.builder')->rebuild();

    $endpoint = (string) $this->provider->getEndpoint($this->node('page'));
    parse_str((string) parse_url($endpoint, PHP_URL_QUERY), $query);
    $this->assertSame('field_internal', $query['include']);
  }

  /**
   * Returns an unsaved node of a bundle.
   */
  protected function node(string $bundle): NodeInterface {
    return Node::create(['type' => $bundle, 'title' => 'Unsaved']);
  }

}
