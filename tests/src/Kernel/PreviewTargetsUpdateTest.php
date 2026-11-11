<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the update that adds the preview targets' expires column.
 *
 * A 1.0.0-beta1 site with stored targets lacks the column, and Drupal can't
 * change the field itself while it holds data. So the update adds it.
 *
 * @group consumer_node_preview
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class PreviewTargetsUpdateTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $this->container->get('module_handler')->loadInclude('consumer_node_preview', 'install');
  }

  /**
   * Tests that the update changes nothing on a site that has the column.
   */
  public function testTheUpdateLeavesAnUpToDateSiteAlone(): void {
    $this->assertSame([], $this->pendingChanges());
    $this->assertSame('The expires column is already in place.', consumer_node_preview_update_10001());
    $this->assertSame([], $this->pendingChanges());
  }

  /**
   * Tests that the update adds and records the column, and keeps the targets.
   *
   * @param bool $drop_column
   *   TRUE to drop the column, FALSE to leave it and drop only its record.
   * @param string $message
   *   The update's message.
   *
   * @dataProvider sitesWithoutTheColumn
   */
  #[DataProvider('sitesWithoutTheColumn')]
  public function testTheUpdateAddsTheColumn(bool $drop_column, string $message): void {
    $consumer = Consumer::create([
      'client_id' => 'app',
      'label' => 'App',
      'preview_targets' => [
        ['label' => 'Frontend', 'url' => 'https://example.com/p', 'last_seen' => 1_700_000_000],
        ['label' => 'By hand', 'url' => 'https://example.com/q'],
      ],
    ]);
    $consumer->save();
    $this->removeColumn($drop_column);
    $this->assertNotSame([], $this->pendingChanges(), 'Drupal sees the field as changed.');

    $this->assertSame($message, consumer_node_preview_update_10001());

    $this->assertSame([], $this->pendingChanges());
    $targets = $this->reload($consumer)->get('preview_targets')->getValue();
    $this->assertSame(['Frontend', 'By hand'], array_column($targets, 'label'));
    $this->assertSame(1_700_000_000, (int) $targets[0]['last_seen']);
    $this->assertNull($targets[0]['expires'] ?? NULL);

    // A target saves with both times now.
    $consumer = $this->reload($consumer);
    $consumer->get('preview_targets')->appendItem([
      'label' => 'Tunnel',
      'url' => 'https://tunnel.example.com/p',
      'last_seen' => 1_800_000_000,
      'expires' => 1_800_000_120,
    ]);
    $consumer->save();
    $tunnel = $this->reload($consumer)->get('preview_targets')->get(2);
    $this->assertSame(1_800_000_000, (int) $tunnel->get('last_seen')->getValue());
    $this->assertSame(1_800_000_120, (int) $tunnel->get('expires')->getValue());

    // A second run finds nothing to do.
    $this->assertSame('The expires column is already in place.', consumer_node_preview_update_10001());
  }

  /**
   * Returns the sites the update has work on.
   *
   * @return array<string, array{bool, string}>
   *   Whether the column goes too, and the update's message.
   */
  public static function sitesWithoutTheColumn(): array {
    return [
      '1.0.0-beta1' => [
        TRUE,
        'Added preview_targets_expires to consumer__preview_targets.',
      ],
      'A run that stopped before recording the column' => [
        FALSE,
        'Recorded preview_targets_expires on consumer__preview_targets as installed.',
      ],
    ];
  }

  /**
   * Leaves the database as 1.0.0-beta1, or a stopped update, left it.
   *
   * The column goes from the schema Drupal recorded for the field, and from
   * the table too for 1.0.0-beta1.
   *
   * @param bool $drop_column
   *   TRUE to drop the column from the table as well.
   */
  protected function removeColumn(bool $drop_column): void {
    /** @var \Drupal\Core\Entity\Sql\SqlEntityStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $definition = $this->container->get('entity.definition_update_manager')->getFieldStorageDefinition('preview_targets', 'consumer');
    $mapping = $storage->getTableMapping();
    $table = $mapping->getFieldTableName('preview_targets');
    $key_value = $this->container->get('keyvalue')->get('entity.storage_schema.sql');
    $stored = $key_value->get('consumer.field_schema_data.preview_targets');
    $column = $mapping->getFieldColumnName($definition, 'expires');
    if ($drop_column) {
      $this->container->get('database')->schema()->dropField($table, $column);
    }
    unset($stored[$table]['fields'][$column]);
    $key_value->set('consumer.field_schema_data.preview_targets', $stored);
  }

  /**
   * Loads a consumer as it is stored.
   */
  protected function reload(Consumer $consumer): Consumer {
    $reloaded = $this->container->get('entity_type.manager')->getStorage('consumer')->loadUnchanged($consumer->id());
    $this->assertInstanceOf(Consumer::class, $reloaded);
    return $reloaded;
  }

  /**
   * Returns the changes Drupal would make to the consumer entity type.
   *
   * @return array<mixed>
   *   The change summary for consumers, empty when there is nothing to do.
   */
  protected function pendingChanges(): array {
    return $this->container->get('entity.definition_update_manager')->getChangeSummary()['consumer'] ?? [];
  }

}
