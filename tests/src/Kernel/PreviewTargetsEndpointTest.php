<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\Component\Datetime\TimeInterface;
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
   * @param int|null $time
   *   The request time for the datetime.time service to report, or NULL to
   *   leave the service as it is.
   */
  protected function call(string $method, ?array $payload = NULL, ?string $consumer_uuid = NULL, array $headers = [], bool $session = FALSE, ?int $time = NULL): Response {
    $uri = '/api/consumer-node-preview/targets';
    if ($consumer_uuid !== NULL) {
      $uri .= '?consumer=' . $consumer_uuid;
    }
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($time !== NULL) {
      // As core's CronQueueTest does. The kernel test's time service does not
      // read the time from the request on every core version.
      $clock = $this->createStub(TimeInterface::class);
      $clock->method('getRequestTime')->willReturn($time);
      $clock->method('getCurrentTime')->willReturn($time);
      $this->container->set('datetime.time', $clock);
    }
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
   * Tests that the owner can read and replace the consumer's layouts.
   *
   * A PUT without "layouts" leaves them as they are, so a frontend that only
   * registers targets does not wipe them.
   */
  public function testLayoutsRoundTrip(): void {
    $this->setCurrentUser($this->owner);
    $uuid = $this->consumer->uuid();
    $targets = [['label' => 'Local', 'url' => 'http://localhost:3000/p?layout=[layout]']];

    $response = $this->call('PUT', ['targets' => $targets, 'layouts' => ['default', 'preview']], $uuid);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['default', 'preview'], json_decode((string) $response->getContent(), TRUE)['layouts']);

    $this->assertSame(200, $this->call('PUT', ['targets' => $targets], $uuid)->getStatusCode());
    $data = json_decode((string) $this->call('GET', consumer_uuid: $uuid)->getContent(), TRUE);
    $this->assertSame(['default', 'preview'], $data['layouts']);

    $this->assertSame(200, $this->call('PUT', ['targets' => $targets, 'layouts' => []], $uuid)->getStatusCode());
    $data = json_decode((string) $this->call('GET', consumer_uuid: $uuid)->getContent(), TRUE);
    $this->assertSame([], $data['layouts']);
  }

  /**
   * Tests that invalid layouts are rejected and change nothing.
   */
  public function testInvalidLayoutsAreRejected(): void {
    $this->setCurrentUser($this->owner);
    $uuid = $this->consumer->uuid();
    $targets = [['label' => 'Local', 'url' => 'http://localhost:3000/p']];

    $this->assertSame(422, $this->call('PUT', ['targets' => $targets, 'layouts' => 'default'], $uuid)->getStatusCode());
    $this->assertSame(422, $this->call('PUT', ['targets' => $targets, 'layouts' => [['name' => 'default']]], $uuid)->getStatusCode());
    $this->assertSame(422, $this->call('PUT', ['targets' => $targets, 'layouts' => ['not a name']], $uuid)->getStatusCode());

    // What is stored, not what the rejected request left on the cached entity.
    $this->container->get('entity_type.manager')->getStorage('consumer')->resetCache([$this->consumer->id()]);
    $data = json_decode((string) $this->call('GET', consumer_uuid: $uuid)->getContent(), TRUE);
    $this->assertSame([], $data['layouts']);
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
   * Returns the stored stamps of the test consumer, freshly loaded.
   *
   * @return array<string, int|null>
   *   The last_seen value of each target, keyed by label, in field order.
   */
  protected function storedStamps(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $storage->resetCache([$this->consumer->id()]);
    $reloaded = $storage->load($this->consumer->id());
    assert($reloaded instanceof Consumer);
    $stamps = [];
    foreach ($reloaded->get('preview_targets') as $item) {
      $last_seen = $item->get('last_seen')->getValue();
      $stamps[(string) $item->get('label')->getValue()] = $last_seen === NULL ? NULL : (int) $last_seen;
    }
    return $stamps;
  }

  /**
   * Gives the test consumer these targets, saved.
   *
   * @param list<array{label: string, url: string, last_seen?: int}> $targets
   *   The targets.
   */
  protected function giveTargets(array $targets): void {
    $this->consumer->set('preview_targets', $targets)->save();
  }

  /**
   * Tests that a PATCH replaces the target with the same label, in place.
   *
   * It stamps that target with the request time. The other targets keep
   * their place, their values and their stamps.
   */
  public function testRegisterReplacesTheTargetWithItsLabel(): void {
    $this->giveTargets([
      ['label' => 'Local', 'url' => 'http://localhost:3000/p'],
      ['label' => 'Staging', 'url' => 'https://stage.example.com/p', 'last_seen' => 1_700_000_000],
    ]);
    $this->setCurrentUser($this->owner);

    $response = $this->call('PATCH', [
      'target' => ['label' => 'Local', 'url' => ' http://localhost:4000/p '],
    ], $this->consumer->uuid(), time: 1_800_000_000);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame($this->consumer->uuid(), $data['consumer']);
    $this->assertSame([
      ['label' => 'Local', 'url' => 'http://localhost:4000/p'],
      ['label' => 'Staging', 'url' => 'https://stage.example.com/p'],
    ], $data['targets']);
    $this->assertSame($data['targets'], $this->storedTargets());
    $this->assertSame(['Local' => 1_800_000_000, 'Staging' => 1_700_000_000], $this->storedStamps());
  }

  /**
   * Tests that a PATCH with a new label adds the target at the end.
   */
  public function testRegisterAddsAnUnknownLabelAtTheEnd(): void {
    $this->setCurrentUser($this->owner);

    $response = $this->call('PATCH', [
      'target' => ['label' => 'Preview', 'url' => 'https://preview.example.com/p'],
    ], $this->consumer->uuid(), time: 1_800_000_000);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([
      ['label' => 'Local', 'url' => 'http://localhost:3000/p'],
      ['label' => 'Preview', 'url' => 'https://preview.example.com/p'],
    ], $this->storedTargets());
    // The hand-added target stays unstamped, so it never expires.
    $this->assertSame(['Local' => NULL, 'Preview' => 1_800_000_000], $this->storedStamps());
  }

  /**
   * Tests that a PATCH refreshes only the stamp of the caller's target.
   *
   * Another frontend's target keeps its own stamp, so it can still expire.
   */
  public function testRegisterStampsOnlyTheCallersTarget(): void {
    $this->giveTargets([
      ['label' => 'Alice', 'url' => 'http://localhost:3000/p', 'last_seen' => 1_700_000_000],
      ['label' => 'Bob', 'url' => 'http://localhost:3001/p', 'last_seen' => 1_700_000_000],
    ]);
    $this->setCurrentUser($this->owner);

    $payload = ['target' => ['label' => 'Bob', 'url' => 'http://localhost:3001/p']];
    $this->assertSame(200, $this->call('PATCH', $payload, $this->consumer->uuid(), time: 1_700_000_500)->getStatusCode());
    $this->assertSame(['Alice' => 1_700_000_000, 'Bob' => 1_700_000_500], $this->storedStamps());

    // A heartbeat with the same values refreshes the stamp again.
    $this->assertSame(200, $this->call('PATCH', $payload, $this->consumer->uuid(), time: 1_700_009_000)->getStatusCode());
    $this->assertSame(['Alice' => 1_700_000_000, 'Bob' => 1_700_009_000], $this->storedStamps());
  }

  /**
   * Tests that a PATCH can replace the layouts, and keeps them without any.
   */
  public function testRegisterLayouts(): void {
    $this->setCurrentUser($this->owner);
    $uuid = $this->consumer->uuid();
    $target = ['label' => 'Local', 'url' => 'http://localhost:3000/p?layout=[layout]'];

    $response = $this->call('PATCH', ['target' => $target, 'layouts' => ['default', 'preview']], $uuid);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['default', 'preview'], json_decode((string) $response->getContent(), TRUE)['layouts']);

    $response = $this->call('PATCH', ['target' => $target], $uuid);
    $this->assertSame(['default', 'preview'], json_decode((string) $response->getContent(), TRUE)['layouts']);

    $this->assertSame(422, $this->call('PATCH', ['target' => $target, 'layouts' => ['not a name']], $uuid)->getStatusCode());
    $this->container->get('entity_type.manager')->getStorage('consumer')->resetCache([$this->consumer->id()]);
    $data = json_decode((string) $this->call('GET', consumer_uuid: $uuid)->getContent(), TRUE);
    $this->assertSame(['default', 'preview'], $data['layouts']);
  }

  /**
   * Tests that an invalid PATCH is rejected and changes nothing.
   *
   * The field's constraints check the target, as for a PUT.
   */
  public function testRegisterValidation(): void {
    $this->setCurrentUser($this->owner);
    $uuid = $this->consumer->uuid();

    $invalid = [
      // No target, or a whole list instead of one target.
      ['nope' => TRUE],
      ['targets' => [['label' => 'Local', 'url' => 'http://localhost:3000/p']]],
      // A target that is not an object, or has a value that is not a string.
      ['target' => 'http://localhost:3000/p'],
      ['target' => ['label' => 'Local', 'url' => ['http://localhost:3000/p']]],
      ['target' => ['url' => 'http://localhost:3000/p']],
      // Values the field's constraints refuse.
      ['target' => ['label' => 'Local', 'url' => 'javascript:alert(1)']],
      ['target' => ['label' => 'Local', 'url' => '/relative/path']],
      ['target' => ['label' => '', 'url' => 'https://ok.example.com/p']],
      ['target' => ['label' => ' ', 'url' => '']],
    ];
    // Each request starts from what is stored, as a real request does, not
    // from what a rejected request left on the cached entity.
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    foreach ($invalid as $index => $payload) {
      $this->assertSame(422, $this->call('PATCH', $payload, $uuid)->getStatusCode(), (string) $index);
      $storage->resetCache([$this->consumer->id()]);
    }

    // The message names the field and is plain text.
    $response = $this->call('PATCH', ['target' => ['label' => 'New', 'url' => 'ftp://example.com/?a=1&b=2']], $uuid);
    $message = json_decode((string) $response->getContent(), TRUE)['message'];
    $this->assertStringContainsString('preview_targets.1.url', $message);
    $this->assertStringContainsString('ftp://example.com/?a=1&b=2', $message);

    $this->assertSame([['label' => 'Local', 'url' => 'http://localhost:3000/p']], $this->storedTargets());
    $this->assertSame(['Local' => NULL], $this->storedStamps());
  }

  /**
   * Tests that a PATCH has the same access rules as a PUT.
   */
  public function testRegisterAccess(): void {
    $payload = ['target' => ['label' => 'Evil', 'url' => 'https://evil.example.com/p']];
    $uuid = $this->consumer->uuid();

    $this->setUpCurrentUser(['uid' => 0]);
    $this->assertSame(403, $this->call('PATCH', $payload, $uuid)->getStatusCode());
    $this->setCurrentUser($this->account());
    $this->assertSame(403, $this->call('PATCH', $payload, $uuid)->getStatusCode());
    // The permission without ownership is not enough, even with the header.
    $this->setCurrentUser($this->account(['register consumer node preview targets']));
    $this->assertSame(403, $this->call('PATCH', $payload, $uuid)->getStatusCode());
    $this->assertSame(403, $this->call('PATCH', $payload, headers: ['X-Consumer-ID' => 'app'])->getStatusCode());
    // A request must name its consumer.
    $this->setCurrentUser($this->owner);
    $this->assertSame(404, $this->call('PATCH', $payload)->getStatusCode());
    // With a session cookie, it needs the CSRF header.
    $this->assertSame(403, $this->call('PATCH', $payload, $uuid, session: TRUE)->getStatusCode());
    $this->assertSame([['label' => 'Local', 'url' => 'http://localhost:3000/p']], $this->storedTargets());

    // The owner names the consumer by its client id.
    $this->assertSame(200, $this->call('PATCH', $payload, headers: ['X-Consumer-ID' => 'app'])->getStatusCode());
    $token = $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY);
    $this->assertSame(200, $this->call('PATCH', $payload, $uuid, ['X-CSRF-Token' => $token], TRUE)->getStatusCode());
    // An administrator may register on any consumer.
    $this->setCurrentUser($this->account([
      'register consumer node preview targets',
      'administer consumer entities',
    ]));
    $this->assertSame(200, $this->call('PATCH', ['target' => ['label' => 'Admin', 'url' => 'https://admin.example.com/p']], $uuid)->getStatusCode());
    $this->assertSame(['Local', 'Evil', 'Admin'], array_column($this->storedTargets(), 'label'));
  }

  /**
   * Tests that a PUT keeps the stamps of unchanged targets, and adds none.
   *
   * A hand-managed list then never expires, and one frontend's PUT does not
   * refresh another frontend's stamp.
   */
  public function testPutKeepsStampsOfUnchangedTargets(): void {
    $this->giveTargets([
      ['label' => 'Alice', 'url' => 'http://localhost:3000/p', 'last_seen' => 1_700_000_000],
      ['label' => 'Bob', 'url' => 'http://localhost:3001/p', 'last_seen' => 1_700_000_100],
      ['label' => 'Carol', 'url' => 'http://localhost:3002/p', 'last_seen' => 1_700_000_200],
    ]);
    $this->setCurrentUser($this->owner);

    $this->assertSame(200, $this->call('PUT', [
      'targets' => [
        ['label' => 'Dave', 'url' => 'http://localhost:3003/p'],
        ['label' => 'Bob', 'url' => 'http://localhost:3001/p'],
        ['label' => 'Alice', 'url' => 'http://localhost:4000/p'],
      ],
    ], $this->consumer->uuid(), time: 1_800_000_000)->getStatusCode());
    // Bob is unchanged, so the target keeps its stamp in its new place. Alice
    // has a new URL, so that target is now managed by hand. Dave is new, and
    // Carol went.
    $this->assertSame(['Dave' => NULL, 'Bob' => 1_700_000_100, 'Alice' => NULL], $this->storedStamps());
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
