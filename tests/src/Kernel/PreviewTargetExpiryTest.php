<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the expiry of registered targets: the Inactive group and clean-up.
 *
 * A registered target has a last_seen stamp. A target added by hand has
 * none, and never expires.
 *
 * @group consumer_node_preview
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class PreviewTargetExpiryTest extends KernelTestBase {

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
   * The request time, which the provider compares the stamps with.
   */
  protected int $now;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $this->installConfig(['consumer_node_preview']);
    $this->provider = $this->container->get('consumer_node_preview.targets_provider');
    $this->now = (int) $this->container->get('datetime.time')->getRequestTime();
  }

  /**
   * Creates a consumer with targets.
   *
   * @param string $label
   *   The consumer's label.
   * @param list<array{label: string, url: string, last_seen?: int|null}> $targets
   *   The targets.
   * @param bool $published
   *   Whether the consumer is published.
   */
  protected function createConsumer(string $label, array $targets, bool $published = TRUE): Consumer {
    $consumer = Consumer::create([
      'client_id' => strtolower($label),
      'label' => $label,
      'status' => $published,
      'preview_targets' => $targets,
    ]);
    $consumer->save();
    return $consumer;
  }

  /**
   * Returns the stored stamps of a consumer, freshly loaded.
   *
   * @return array<string, int|null>
   *   The last_seen value of each target, keyed by label, in field order.
   */
  protected function stamps(Consumer $consumer): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $storage->resetCache([$consumer->id()]);
    $reloaded = $storage->load($consumer->id());
    $this->assertInstanceOf(Consumer::class, $reloaded);
    $stamps = [];
    foreach ($reloaded->get('preview_targets') as $item) {
      $last_seen = $item->get('last_seen')->getValue();
      $stamps[(string) $item->get('label')->getValue()] = $last_seen === NULL ? NULL : (int) $last_seen;
    }
    return $stamps;
  }

  /**
   * Runs the module's cron hook.
   */
  protected function cron(): void {
    $this->container->get('module_handler')->invoke('consumer_node_preview', 'cron');
  }

  /**
   * Sets the expiry settings.
   */
  protected function setExpiry(array $values): void {
    $config = $this->config('consumer_node_preview.settings');
    foreach ($values as $name => $value) {
      $config->set($name, $value);
    }
    $config->save();
  }

  /**
   * Tests the defaults of the two settings, and their overrides.
   *
   * A value that is not a whole number above zero falls back to the default.
   */
  public function testSettingsDefaultsAndOverrides(): void {
    $this->assertSame(86400, $this->provider->getInactiveAfter());
    $this->assertSame(2592000, $this->provider->getDeleteAfter());

    $this->setExpiry(['inactive_after' => 3600, 'delete_after' => 7200]);
    $this->assertSame(3600, $this->provider->getInactiveAfter());
    $this->assertSame(7200, $this->provider->getDeleteAfter());

    // An override in settings.php skips the schema, so it can be any type.
    foreach (['3600', 0, -1, 1.5, NULL] as $invalid) {
      $GLOBALS['config']['consumer_node_preview.settings'] = [
        'inactive_after' => $invalid,
        'delete_after' => $invalid,
      ];
      $this->container->get('config.factory')->reset('consumer_node_preview.settings');
      $this->assertSame(86400, $this->provider->getInactiveAfter(), var_export($invalid, TRUE));
      $this->assertSame(2592000, $this->provider->getDeleteAfter(), var_export($invalid, TRUE));
    }
    unset($GLOBALS['config']['consumer_node_preview.settings']);
  }

  /**
   * Tests that a stamped target past the limit moves to the Inactive group.
   *
   * A target exactly at the limit is still active. The Inactive group comes
   * last, and each of its options names the consumer, as the group does not.
   * The keys do not change.
   */
  public function testTargetPastTheLimitIsInactive(): void {
    $this->setSetting('consumer_node_preview', [
      'targets' => ['site' => ['label' => 'Site target', 'url' => '/p']],
    ]);
    $alpha = $this->createConsumer('Alpha', [
      ['label' => 'Fresh', 'url' => 'http://localhost:3000/p', 'last_seen' => $this->now - 86400],
      ['label' => 'Stale', 'url' => 'http://localhost:3001/p', 'last_seen' => $this->now - 86401],
      ['label' => 'By hand', 'url' => 'https://alpha.example.com/p'],
    ]);
    $beta = $this->createConsumer('Beta', [
      ['label' => 'Asleep', 'url' => 'https://beta.example.com/p', 'last_seen' => $this->now - 30 * 86400],
    ]);

    $this->assertSame([
      'Site settings' => ['settings:site' => 'Site target'],
      'Alpha' => [$alpha->id() . ':0' => 'Fresh', $alpha->id() . ':2' => 'By hand'],
      'Inactive targets' => [$alpha->id() . ':1' => 'Alpha: Stale', $beta->id() . ':0' => 'Beta: Asleep'],
    ], $this->provider->getGroupedTargetOptions());

    // An inactive target still resolves by its key, so it can be picked.
    $targets = $this->provider->getAllTargets();
    $this->assertSame('http://localhost:3001/p', $this->provider->getTargetByKey($alpha->id() . ':1')['url']);
    $this->assertSame([
      'settings:site',
      $alpha->id() . ':0',
      $alpha->id() . ':2',
      $alpha->id() . ':1',
      $beta->id() . ':0',
    ], array_keys($targets));
    $this->assertSame($this->now - 86401, $targets[$alpha->id() . ':1']['last_seen']);
    $this->assertNull($targets[$alpha->id() . ':2']['last_seen']);
    $this->assertNull($targets['settings:site']['last_seen']);
    // The browser check skips a target that is flagged inactive.
    $this->assertTrue($targets[$alpha->id() . ':1']['inactive']);
    $this->assertTrue($targets[$beta->id() . ':0']['inactive']);
    $this->assertFalse($targets[$alpha->id() . ':0']['inactive']);
    $this->assertFalse($targets[$alpha->id() . ':2']['inactive']);
    $this->assertFalse($targets['settings:site']['inactive']);
  }

  /**
   * Tests that the limit follows its setting.
   */
  public function testTheLimitFollowsItsSetting(): void {
    $alpha = $this->createConsumer('Alpha', [
      ['label' => 'Recent', 'url' => 'http://localhost:3000/p', 'last_seen' => $this->now - 3600],
    ]);
    $this->assertSame(['Alpha' => [$alpha->id() . ':0' => 'Recent']], $this->provider->getGroupedTargetOptions());

    $this->setExpiry(['inactive_after' => 3599]);
    $this->assertSame(['Inactive targets' => [$alpha->id() . ':0' => 'Alpha: Recent']], $this->provider->getGroupedTargetOptions());
  }

  /**
   * Tests that the preview opens on an active target before an inactive one.
   *
   * A default in settings.php still wins, even when it is inactive.
   */
  public function testDefaultPrefersAnActiveTarget(): void {
    $alpha = $this->createConsumer('Alpha', [
      ['label' => 'Stale', 'url' => 'http://localhost:3000/p', 'last_seen' => $this->now - 2 * 86400],
    ]);
    $beta = $this->createConsumer('Beta', [
      ['label' => 'Live', 'url' => 'https://beta.example.com/p'],
    ]);
    $this->assertSame($beta->id() . ':0', $this->provider->getDefaultKey());

    $this->setSetting('consumer_node_preview', ['default' => $alpha->id() . ':0']);
    $this->assertSame($alpha->id() . ':0', $this->provider->getDefaultKey());
  }

  /**
   * Tests that cron deletes stamped targets past the grace period.
   *
   * A target exactly at the grace period stays. An unstamped target never
   * goes, however old its consumer is. Unpublished consumers are cleaned up
   * too, and a consumer whose targets all went is kept.
   */
  public function testCronDeletesTargetsPastTheGracePeriod(): void {
    $alpha = $this->createConsumer('Alpha', [
      ['label' => 'Gone', 'url' => 'http://localhost:3000/p', 'last_seen' => $this->now - 2592001],
      ['label' => 'Kept', 'url' => 'http://localhost:3001/p', 'last_seen' => $this->now - 2592000],
      ['label' => 'By hand', 'url' => 'https://alpha.example.com/p'],
      ['label' => 'Fresh', 'url' => 'http://localhost:3002/p', 'last_seen' => $this->now],
    ]);
    $beta = $this->createConsumer('Beta', [
      ['label' => 'Gone too', 'url' => 'https://beta.example.com/p', 'last_seen' => 1_000_000_000],
    ], FALSE);
    $gamma = $this->createConsumer('Gamma', [
      ['label' => 'By hand', 'url' => 'https://gamma.example.com/p'],
    ]);

    $this->cron();

    $this->assertSame(['Kept' => $this->now - 2592000, 'By hand' => NULL, 'Fresh' => $this->now], $this->stamps($alpha));
    $this->assertSame([], $this->stamps($beta));
    $this->assertNotNull(Consumer::load($beta->id()));
    $this->assertSame(['By hand' => NULL], $this->stamps($gamma));
  }

  /**
   * Tests that the grace period follows its setting.
   */
  public function testTheGracePeriodFollowsItsSetting(): void {
    $alpha = $this->createConsumer('Alpha', [
      ['label' => 'Recent', 'url' => 'http://localhost:3000/p', 'last_seen' => $this->now - 7200],
    ]);
    $this->cron();
    $this->assertSame(['Recent' => $this->now - 7200], $this->stamps($alpha));

    $this->setExpiry(['delete_after' => 7199]);
    $this->cron();
    $this->assertSame([], $this->stamps($alpha));
  }

  /**
   * Tests that a save keeps the stamp of a target whose values did not change.
   *
   * The consumer form and a PUT send targets without stamps. An unchanged
   * target keeps its stamp, wherever it moved to. A changed one loses it,
   * and is then a target added by hand.
   */
  public function testSaveKeepsTheStampsOfUnchangedTargets(): void {
    $alpha = $this->createConsumer('Alpha', [
      ['label' => 'One', 'url' => 'http://localhost:3000/p', 'last_seen' => 1_700_000_000],
      ['label' => 'Two', 'url' => 'http://localhost:3001/p', 'last_seen' => 1_700_000_100],
      ['label' => 'Two', 'url' => 'http://localhost:3001/p', 'last_seen' => 1_700_000_200],
    ]);

    $alpha->set('preview_targets', [
      ['label' => 'Two', 'url' => 'http://localhost:3001/p'],
      ['label' => 'One', 'url' => 'http://localhost:4000/p'],
      ['label' => 'Two', 'url' => 'http://localhost:3001/p'],
      ['label' => 'Two', 'url' => 'http://localhost:3001/p'],
    ])->save();

    // Each old stamp goes to one new target only.
    $reloaded = Consumer::load($alpha->id());
    $stamps = array_map(fn(FieldItemInterface $item) => $item->get('last_seen')->getValue(), iterator_to_array($reloaded->get('preview_targets')));
    $this->assertSame([1_700_000_100, NULL, 1_700_000_200, NULL], array_map(fn($stamp): ?int => $stamp === NULL ? NULL : (int) $stamp, $stamps));

    // A stamp the save sends wins over the stored one.
    $reloaded->get('preview_targets')->get(0)->set('last_seen', 1_800_000_000);
    $reloaded->save();
    $this->assertSame(1_800_000_000, (int) Consumer::load($alpha->id())->get('preview_targets')->get(0)->get('last_seen')->getValue());
  }

  /**
   * Returns the stored stamps of a consumer's targets, in field order.
   *
   * @return list<int|null>
   *   The stamps.
   */
  protected function storedStamps(Consumer $consumer): array {
    $stamps = [];
    foreach (Consumer::load($consumer->id())->get('preview_targets') as $item) {
      $stamp = $item->get('last_seen')->getValue();
      $stamps[] = $stamp === NULL ? NULL : (int) $stamp;
    }
    return $stamps;
  }

  /**
   * Tests that cron does not stamp a copy that was added by hand.
   *
   * The copy has the same label and URL as the expired target. It must stay
   * after cron, and it must not expire on a later run.
   */
  public function testCronLeavesHandAddedCopyUnstamped(): void {
    $alpha = $this->createConsumer('Alpha', [
      ['label' => 'Same', 'url' => 'http://localhost:3000/p', 'last_seen' => 1_000_000_000],
      ['label' => 'Same', 'url' => 'http://localhost:3000/p'],
    ]);

    $this->cron();
    $this->assertSame([NULL], $this->storedStamps($alpha));

    $this->cron();
    $this->assertSame([NULL], $this->storedStamps($alpha));
  }

  /**
   * Tests that a registration does not stamp a copy that was added by hand.
   *
   * It does not when the registered URL stays, and it does not when the
   * registered URL changes.
   */
  public function testRegisteringLeavesHandAddedCopyUnstamped(): void {
    $same = ['label' => 'Same', 'url' => 'http://localhost:3000/p'];
    $alpha = $this->createConsumer('Alpha', [
      $same + ['last_seen' => 1_700_000_000],
      $same,
    ]);
    $alpha->set('preview_targets', [
      $same + ['last_seen' => $this->now],
      $same,
    ])->save();
    $this->assertSame([$this->now, NULL], $this->storedStamps($alpha));

    $beta = $this->createConsumer('Beta', [
      $same + ['last_seen' => 1_700_000_000],
      $same,
    ]);
    $beta->set('preview_targets', [
      ['label' => 'Same', 'url' => 'http://localhost:4000/p', 'last_seen' => $this->now],
      $same,
    ])->save();
    $this->assertSame([$this->now, NULL], $this->storedStamps($beta));
  }

  /**
   * Tests that a consumer labelled "Inactive" is an ordinary group.
   */
  public function testConsumerLabelledInactiveStaysApart(): void {
    $inactive = $this->createConsumer('Inactive', [
      ['label' => 'Live', 'url' => 'https://inactive.example.com/p'],
    ]);
    $alpha = $this->createConsumer('Alpha', [
      ['label' => 'Stale', 'url' => 'http://localhost:3000/p', 'last_seen' => $this->now - 2 * 86400],
    ]);

    $this->assertSame([
      'Inactive' => [$inactive->id() . ':0' => 'Live'],
      'Inactive targets' => [$alpha->id() . ':0' => 'Alpha: Stale'],
    ], $this->provider->getGroupedTargetOptions());
  }

  /**
   * Tests that a target whose expires time has come leaves the picker.
   *
   * It gets no preview token either, because the preview finds no target for
   * its key. A target whose time is still to come stays, as does one with no
   * time of its own.
   */
  public function testAnExpiredTargetLeavesThePicker(): void {
    $alpha = $this->createConsumer('Alpha', [
      ['label' => 'Past', 'url' => 'http://localhost:3000/p', 'last_seen' => $this->now - 120, 'expires' => $this->now - 1],
      ['label' => 'Now', 'url' => 'http://localhost:3001/p', 'last_seen' => $this->now - 120, 'expires' => $this->now],
      ['label' => 'Soon', 'url' => 'http://localhost:3002/p', 'last_seen' => $this->now, 'expires' => $this->now + 60],
      ['label' => 'Registered', 'url' => 'http://localhost:3003/p', 'last_seen' => $this->now],
      ['label' => 'By hand', 'url' => 'https://alpha.example.com/p'],
    ]);
    $id = $alpha->id();

    $this->assertSame([$id . ':2', $id . ':3', $id . ':4'], array_keys($this->provider->getAllTargets()));
    $this->assertNull($this->provider->getTargetByKey($id . ':0'));
    $this->assertNull($this->provider->getTargetByKey($id . ':1'));
    $this->assertSame('Soon', $this->provider->getTargetByKey($id . ':2')['label']);
    $this->assertSame($this->now + 60, $this->provider->getTargetByKey($id . ':2')['expires']);

    // An expired target that is also past the inactive limit stays out.
    $beta = $this->createConsumer('Beta', [
      ['label' => 'Old', 'url' => 'http://localhost:3004/p', 'last_seen' => $this->now - 2 * 86400, 'expires' => $this->now - 86400],
    ]);
    $this->assertArrayNotHasKey($beta->id() . ':0', $this->provider->getAllTargets());
  }

  /**
   * Tests that cron deletes a target whose expires time has come.
   */
  public function testCronDeletesExpiredTargets(): void {
    $alpha = $this->createConsumer('Alpha', [
      ['label' => 'Past', 'url' => 'http://localhost:3000/p', 'last_seen' => $this->now - 120, 'expires' => $this->now - 1],
      ['label' => 'Soon', 'url' => 'http://localhost:3001/p', 'last_seen' => $this->now, 'expires' => $this->now + 60],
      ['label' => 'By hand', 'url' => 'https://alpha.example.com/p'],
    ]);
    $beta = $this->createConsumer('Beta', [
      ['label' => 'Now', 'url' => 'http://localhost:3002/p', 'last_seen' => $this->now - 120, 'expires' => $this->now],
    ], FALSE);

    $this->cron();

    $this->assertSame(['Soon' => $this->now, 'By hand' => NULL], $this->stamps($alpha));
    $this->assertSame([], $this->stamps($beta));
  }

  /**
   * Tests that a save keeps a target's expires time with its stamp.
   *
   * The consumer form and a PUT send targets without either. An unchanged
   * target keeps both, and a changed one loses both.
   */
  public function testSaveKeepsTheExpiresTimeWithTheStamp(): void {
    $alpha = $this->createConsumer('Alpha', [
      ['label' => 'Tunnel', 'url' => 'http://localhost:3000/p', 'last_seen' => 1_700_000_000, 'expires' => 1_700_000_120],
      ['label' => 'Moved', 'url' => 'http://localhost:3001/p', 'last_seen' => 1_700_000_000, 'expires' => 1_700_000_120],
    ]);
    $alpha->set('preview_targets', [
      ['label' => 'Moved', 'url' => 'http://localhost:4001/p'],
      ['label' => 'Tunnel', 'url' => 'http://localhost:3000/p'],
    ])->save();

    $targets = Consumer::load($alpha->id())->get('preview_targets')->getValue();
    $this->assertNull($targets[0]['last_seen'] ?? NULL);
    $this->assertNull($targets[0]['expires'] ?? NULL);
    $this->assertSame(1_700_000_000, (int) $targets[1]['last_seen']);
    $this->assertSame(1_700_000_120, (int) $targets[1]['expires']);
  }

}
