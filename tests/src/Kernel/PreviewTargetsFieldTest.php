<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests the preview targets field and the provider service.
 *
 * @group consumer_node_preview
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class PreviewTargetsFieldTest extends KernelTestBase {

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
    'jsonapi',
    'jsonapi_node_preview',
    'consumers',
    'consumer_node_preview',
  ];

  /**
   * The provider under test.
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
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->provider = $this->container->get('consumer_node_preview.targets_provider');
  }

  /**
   * Creates a consumer with targets.
   */
  protected function createConsumer(string $label, array $targets): Consumer {
    $consumer = Consumer::create([
      'client_id' => strtolower($label),
      'label' => $label,
      'preview_targets' => $targets,
    ]);
    $consumer->save();
    return $consumer;
  }

  /**
   * Tests that targets survive a save and reload in order.
   */
  public function testTargetsRoundTrip(): void {
    $consumer = $this->createConsumer('App', [
      ['label' => 'Local', 'url' => 'http://localhost:3000/p#[jsonapi_node_preview]'],
      ['label' => 'Staging', 'url' => 'https://stage.example.com/p#[jsonapi_node_preview]'],
    ]);

    $reloaded = Consumer::load($consumer->id());
    $this->assertSame([
      ['label' => 'Local', 'url' => 'http://localhost:3000/p#[jsonapi_node_preview]'],
      ['label' => 'Staging', 'url' => 'https://stage.example.com/p#[jsonapi_node_preview]'],
    ], $this->provider->getTargets($reloaded));
  }

  /**
   * Tests the option list and the key lookup.
   */
  public function testOptionsAndKeyLookup(): void {
    $a = $this->createConsumer('Alpha', [
      ['label' => 'Local', 'url' => 'http://localhost:3000/p'],
    ]);
    $this->createConsumer('Beta', [
      ['label' => 'Prod', 'url' => 'https://beta.example.com/p'],
    ]);

    $options = $this->provider->getTargetOptions();
    $this->assertCount(2, $options);
    $this->assertSame('Alpha: Local', $options[$a->id() . ':0']);

    $target = $this->provider->getTargetByKey($a->id() . ':0');
    $this->assertSame('http://localhost:3000/p', $target['url']);
    $this->assertNull($this->provider->getTargetByKey('999:0'));
    $this->assertNull($this->provider->getTargetByKey('nonsense'));
  }

  /**
   * Tests that URL tokens resolve to the preview endpoint.
   */
  public function testResolveUrl(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Test']);
    $node->save();

    $url = $this->provider->resolveUrl(
      'http://localhost:3000/node/preview/[view_mode]#[jsonapi_node_preview]',
      $node,
      'full',
    );

    $this->assertStringContainsString('http://localhost:3000/node/preview/full#', $url);
    $this->assertStringContainsString("/jsonapi/node/article/{$node->uuid()}/preview", $url);
  }

}
