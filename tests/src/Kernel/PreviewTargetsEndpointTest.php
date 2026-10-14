<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
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
    $this->account();
    $this->owner = $this->account(['register consumer node preview targets']);

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
   * Creates an active user with the given permissions.
   *
   * The user trait's createUser() returns User|false on Drupal 10 and a user
   * on Drupal 11. Building the user here gives one type on both versions.
   *
   * @param string[] $permissions
   *   The permissions the user's role grants.
   */
  protected function account(array $permissions = []): UserInterface {
    $role = Role::create([
      'id' => strtolower($this->randomMachineName()),
      'label' => $this->randomMachineName(),
    ]);
    foreach ($permissions as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
    $account = User::create([
      'name' => $this->randomMachineName(),
      'status' => 1,
      'roles' => [$role->id()],
    ]);
    $account->save();
    return $account;
  }

  /**
   * Sends a request to the endpoint and returns the response.
   *
   * @param string $method
   *   The HTTP method.
   * @param array<string, mixed>|null $payload
   *   The JSON body, if any.
   * @param string|null $consumer_uuid
   *   The consumer query parameter, if any.
   * @param array<string, string> $headers
   *   Extra request headers.
   * @param bool $session
   *   TRUE to send a session cookie, as a browser does after sign-in.
   */
  protected function call(string $method, ?array $payload = NULL, ?string $consumer_uuid = NULL, array $headers = [], bool $session = FALSE): Response {
    $uri = '/api/consumer-node-preview/targets';
    if ($consumer_uuid !== NULL) {
      $uri .= '?consumer=' . $consumer_uuid;
    }
    $server = ['CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $name => $value) {
      $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $request = Request::create(
      $uri,
      $method,
      server: $server,
      content: $payload === NULL ? NULL : json_encode($payload, JSON_THROW_ON_ERROR),
    );
    if ($session) {
      $name = $this->container->get('session_configuration')->getOptions($request)['name'];
      $request->cookies->set($name, 'test-session');
    }
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

    $no_permission = $this->account();
    $this->setCurrentUser($no_permission);
    $this->assertSame(403, $this->call('GET', consumer_uuid: $this->consumer->uuid())->getStatusCode());
  }

  /**
   * Tests that ownership is required on top of the permission.
   */
  public function testOwnershipRequired(): void {
    $intruder = $this->account(['register consumer node preview targets']);
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

    // A target that is not an object, or has a value that is not a string.
    $this->assertSame(422, $this->call('PUT', ['targets' => ['https://ok.example.com/p']], $uuid)->getStatusCode());
    $this->assertSame(422, $this->call('PUT', [
      'targets' => [['label' => 'Bad', 'url' => ['https://ok.example.com/p']]],
    ], $uuid)->getStatusCode());

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

    // A target with neither value is an error. It is not dropped in silence.
    $this->assertSame(422, $this->call('PUT', [
      'targets' => [['label' => ' ', 'url' => '']],
    ], $uuid)->getStatusCode());

    // The message is plain text, with no HTML entities.
    $response = $this->call('PUT', [
      'targets' => [['label' => 'Bad', 'url' => 'ftp://example.com/?a=1&b=2']],
    ], $uuid);
    $this->assertStringContainsString('ftp://example.com/?a=1&b=2', json_decode((string) $response->getContent(), TRUE)['message']);

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
   * Tests that a PUT with a session cookie needs a valid X-CSRF-Token header.
   *
   * Core only checks the header when the request has a session cookie, so
   * the other tests in this class never reach the check.
   */
  public function testSessionPutNeedsCsrfToken(): void {
    $this->setCurrentUser($this->owner);
    $uuid = $this->consumer->uuid();
    $payload = ['targets' => [['label' => 'Staging', 'url' => 'https://stage.example.com/p']]];

    $this->assertSame(403, $this->call('PUT', $payload, $uuid, session: TRUE)->getStatusCode());
    $this->assertSame(403, $this->call('PUT', $payload, $uuid, ['X-CSRF-Token' => 'wrong'], TRUE)->getStatusCode());
    $this->assertSame('Local', $this->storedTargets()[0]['label']);

    $token = $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY);
    $this->assertSame(200, $this->call('PUT', $payload, $uuid, ['X-CSRF-Token' => $token], TRUE)->getStatusCode());
    $this->assertSame('Staging', $this->storedTargets()[0]['label']);
  }

  /**
   * Tests that a consumer administrator manages any consumer's targets.
   */
  public function testAdministratorManagesAnyConsumer(): void {
    $admin = $this->account([
      'register consumer node preview targets',
      'administer consumer entities',
    ]);
    $this->setCurrentUser($admin);

    $response = $this->call('PUT', [
      'targets' => [['label' => 'Staging', 'url' => 'https://stage.example.com/p']],
    ], $this->consumer->uuid());
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('Staging', $this->storedTargets()[0]['label']);
  }

  /**
   * Tests that the X-Consumer-ID header selects, but never grants access.
   *
   * Any client can send any value in the header.
   */
  public function testConsumerIdHeaderGrantsNothing(): void {
    $this->setCurrentUser($this->account(['register consumer node preview targets']));
    $this->assertSame(403, $this->call('GET', headers: ['X-Consumer-ID' => 'app'])->getStatusCode());
  }

  /**
   * Tests that an unknown consumer uuid is a 404.
   */
  public function testUnknownConsumer(): void {
    $this->setCurrentUser($this->owner);
    $this->assertSame(404, $this->call('GET', consumer_uuid: 'ffffffff-ffff-4fff-8fff-ffffffffffff')->getStatusCode());
  }

  /**
   * Tests that a request must name its consumer.
   *
   * The consumers negotiator would fall back to the default consumer, so a
   * missing or mistyped X-Consumer-ID header would replace its targets.
   */
  public function testNoFallbackToTheDefaultConsumer(): void {
    $default = Consumer::create([
      'client_id' => 'default_consumer',
      'label' => 'Default',
      'is_default' => TRUE,
      'owner_id' => $this->owner->id(),
      'preview_targets' => [['label' => 'Kept', 'url' => 'https://kept.example.com/p']],
    ]);
    $default->save();
    $this->setCurrentUser($this->account([
      'register consumer node preview targets',
      'administer consumer entities',
    ]));
    $payload = ['targets' => [['label' => 'Lost', 'url' => 'https://lost.example.com/p']]];

    $this->assertSame(404, $this->call('PUT', $payload)->getStatusCode());
    $this->assertSame(404, $this->call('PUT', $payload, headers: ['X-Consumer-ID' => 'mistyped'])->getStatusCode());

    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $storage->resetCache([$default->id()]);
    $reloaded = $storage->load($default->id());
    $this->assertInstanceOf(Consumer::class, $reloaded);
    $this->assertSame('Kept', $reloaded->get('preview_targets')->label);
  }

  /**
   * Tests that the X-Consumer-ID header names a consumer by its client id.
   */
  public function testClientIdNamesTheConsumer(): void {
    $this->setCurrentUser($this->owner);
    $response = $this->call('GET', headers: ['X-Consumer-ID' => 'app']);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($this->consumer->uuid(), json_decode((string) $response->getContent(), TRUE)['consumer']);
  }

  /**
   * Tests that an anonymous user owns no consumer.
   *
   * Consumers stores 0 as the owner of a consumer that has none.
   */
  public function testAnonymousOwnsNothing(): void {
    $ownerless = Consumer::create(['client_id' => 'ownerless', 'label' => 'Ownerless']);
    $ownerless->save();
    $this->assertSame(0, (int) $ownerless->getOwnerId());

    Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous user'])
      ->grantPermission('register consumer node preview targets')
      ->save();
    $this->setUpCurrentUser(['uid' => 0]);
    $this->assertTrue($this->container->get('current_user')->hasPermission('register consumer node preview targets'));
    $this->assertSame(403, $this->call('GET', consumer_uuid: $ownerless->uuid())->getStatusCode());
  }

}
