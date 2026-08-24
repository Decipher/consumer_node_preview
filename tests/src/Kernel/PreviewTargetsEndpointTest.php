<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests access and validation on the targets endpoint.
 *
 * @group consumer_node_preview
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class PreviewTargetsEndpointTest extends KernelTestBase {

  use UserCreationTrait;

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
   * A consumer owned by the owner user.
   *
   * @var \Drupal\consumers\Entity\Consumer
   */
  protected $consumer;

  /**
   * The user who owns the consumer.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $owner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $this->installConfig(['system']);

    // The first created user would own uid 1 and bypass access checks.
    $this->createUser();
    $this->owner = $this->createUser(['register consumer node preview targets']);

    $this->consumer = Consumer::create([
      'client_id' => 'app',
      'label' => 'App',
      'owner_id' => $this->owner->id(),
      'preview_targets' => [
        ['label' => 'Local', 'url' => 'http://localhost:3000/p'],
      ],
    ]);
    $this->consumer->save();
  }

  /**
   * Sends a request to the endpoint and returns the response.
   */
  protected function call(string $method, ?array $payload = NULL, ?string $consumer_uuid = NULL): Response {
    $uri = '/api/consumer-node-preview/targets';
    if ($consumer_uuid !== NULL) {
      $uri .= '?consumer=' . $consumer_uuid;
    }
    $request = Request::create(
      $uri,
      $method,
      server: ['CONTENT_TYPE' => 'application/json'],
      content: $payload === NULL ? NULL : json_encode($payload, JSON_THROW_ON_ERROR),
    );
    /** @var \Symfony\Component\HttpKernel\HttpKernelInterface $kernel */
    $kernel = $this->container->get('http_kernel');
    return $kernel->handle($request);
  }

  /**
   * Tests that the permission is required.
   */
  public function testPermissionRequired(): void {
    $this->setUpCurrentUser(['uid' => 0]);
    $this->assertSame(403, $this->call('GET', consumer_uuid: $this->consumer->uuid())->getStatusCode());

    $no_permission = $this->createUser();
    $this->setCurrentUser($no_permission);
    $this->assertSame(403, $this->call('GET', consumer_uuid: $this->consumer->uuid())->getStatusCode());
  }

  /**
   * Tests that ownership is required on top of the permission.
   */
  public function testOwnershipRequired(): void {
    $intruder = $this->createUser(['register consumer node preview targets']);
    $this->setCurrentUser($intruder);
    $response = $this->call('GET', consumer_uuid: $this->consumer->uuid());
    $this->assertSame(403, $response->getStatusCode());

    $response = $this->call('PUT', [
      'targets' => [['label' => 'Evil', 'url' => 'https://evil.example.com/p']],
    ], $this->consumer->uuid());
    $this->assertSame(403, $response->getStatusCode());

    // The stored targets did not change.
    $this->assertSame('Local', $this->storedTargets()[0]['label']);
  }

  /**
   * Tests that the owner can read and replace their targets.
   */
  public function testOwnerRoundTrip(): void {
    $this->setCurrentUser($this->owner);

    $response = $this->call('GET', consumer_uuid: $this->consumer->uuid());
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('Local', $data['targets'][0]['label']);

    $response = $this->call('PUT', [
      'targets' => [
        ['label' => 'Local', 'url' => 'http://localhost:3000/node/preview/[view_mode]#[jsonapi_node_preview]'],
        ['label' => 'Staging', 'url' => 'https://stage.example.com/p'],
      ],
    ], $this->consumer->uuid());
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertCount(2, $data['targets']);

    $this->assertSame('Staging', $this->storedTargets()[1]['label']);
  }

  /**
   * Tests that invalid payloads are rejected and change nothing.
   */
  public function testValidation(): void {
    $this->setCurrentUser($this->owner);
    $uuid = $this->consumer->uuid();

    // Not a targets list.
    $this->assertSame(422, $this->call('PUT', ['nope' => TRUE], $uuid)->getStatusCode());

    // A scheme an iframe must never get.
    $response = $this->call('PUT', [
      'targets' => [['label' => 'Bad', 'url' => 'javascript:alert(1)']],
    ], $uuid);
    $this->assertSame(422, $response->getStatusCode());

    // A relative URL.
    $this->assertSame(422, $this->call('PUT', [
      'targets' => [['label' => 'Bad', 'url' => '/relative/path']],
    ], $uuid)->getStatusCode());

    // An empty label.
    $this->assertSame(422, $this->call('PUT', [
      'targets' => [['label' => '', 'url' => 'https://ok.example.com/p']],
    ], $uuid)->getStatusCode());

    $this->assertSame('http://localhost:3000/p', $this->storedTargets()[0]['url']);
  }

  /**
   * Returns the stored targets of the test consumer, freshly loaded.
   *
   * @return array<int, array{label: string, url: string}>
   *   The stored targets.
   */
  protected function storedTargets(): array {
    /** @var \Drupal\consumer_node_preview\PreviewTargetsProviderInterface $provider */
    $provider = $this->container->get('consumer_node_preview.targets_provider');
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $storage->resetCache([$this->consumer->id()]);
    $reloaded = $storage->load($this->consumer->id());
    assert($reloaded instanceof Consumer);
    return $provider->getTargets($reloaded);
  }

  /**
   * Tests that an unknown consumer uuid is a 404.
   */
  public function testUnknownConsumer(): void {
    $this->setCurrentUser($this->owner);
    $this->assertSame(404, $this->call('GET', consumer_uuid: 'ffffffff-ffff-4fff-8fff-ffffffffffff')->getStatusCode());
  }

}
