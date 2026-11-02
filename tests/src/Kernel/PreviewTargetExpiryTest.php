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
   * Tests the defaults of the two settings, and their overrides.
   *
   * A value that is not a whole number above zero falls back to the default.
   */
  public function testSettingsDefaultsAndOverrides(): void {
    $this->assertSame(86400, $this->provider->getInactiveAfter());
    $this->assertSame(2592000, $this->provider->getDeleteAfter());

    $this->setSetting('consumer_node_preview', ['inactive_after' => 3600, 'delete_after' => 7200]);
    $this->assertSame(3600, $this->provider->getInactiveAfter());
    $this->assertSame(7200, $this->provider->getDeleteAfter());

    foreach (['3600', 0, -1, 1.5, NULL] as $invalid) {
      $this->setSetting('consumer_node_preview', ['inactive_after' => $invalid, 'delete_after' => $invalid]);
      $this->assertSame(86400, $this->provider->getInactiveAfter(), var_export($invalid, TRUE));
      $this->assertSame(2592000, $this->provider->getDeleteAfter(), var_export($invalid, TRUE));
    }
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
      'Site' => ['settings:site' => 'Site target'],
      'Alpha' => [$alpha->id() . ':0' => 'Fresh', $alpha->id() . ':2' => 'By hand'],
      'Inactive' => [$alpha->id() . ':1' => 'Alpha: Stale', $beta->id() . ':0' => 'Beta: Asleep'],
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

    $this->setSetting('consumer_node_preview', ['inactive_after' => 3599]);
    $this->assertSame(['Inactive' => [$alpha->id() . ':0' => 'Alpha: Recent']], $this->provider->getGroupedTargetOptions());
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

    $this->setSetting('consumer_node_preview', ['delete_after' => 7199]);
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
   * Tests that a consumer labelled "Inactive" keeps a group of its own.
   */
  public function testConsumerLabelledInactiveStaysApart(): void {
    $inactive = $this->createConsumer('Inactive', [
      ['label' => 'Live', 'url' => 'https://inactive.example.com/p'],
    ]);
    $alpha = $this->createConsumer('Alpha', [
      ['label' => 'Stale', 'url' => 'http://localhost:3000/p', 'last_seen' => $this->now - 2 * 86400],
    ]);

    $this->assertSame([
      'Inactive (' . $inactive->id() . ')' => [$inactive->id() . ':0' => 'Live'],
      'Inactive' => [$alpha->id() . ':0' => 'Alpha: Stale'],
    ], $this->provider->getGroupedTargetOptions());
  }

}
