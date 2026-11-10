<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the update that adds the preview targets' newer columns.
 *
 * A site that ran an earlier copy has stored targets and lacks a column. So
 * Drupal can't change the field itself, and the update adds the column.
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
   * Tests that the update changes nothing on a site that has the columns.
   */
  public function testTheUpdateLeavesAnUpToDateSiteAlone(): void {
    $this->assertSame([], $this->pendingChanges());
    $this->assertSame('The preview targets columns are already in place.', consumer_node_preview_update_10001());
    $this->assertSame([], $this->pendingChanges());
  }

  /**
   * Tests that the update adds the missing columns, and keeps the targets.
   *
   * @param list<string> $missing
   *   The properties whose columns the earlier copy lacked.
   * @param string $message
   *   The update's message.
   *
   * @dataProvider earlierCopies
   */
  #[DataProvider('earlierCopies')]
  public function testTheUpdateAddsTheMissingColumns(array $missing, string $message): void {
    $consumer = Consumer::create([
      'client_id' => 'app',
      'label' => 'App',
      'preview_targets' => [
        ['label' => 'Frontend', 'url' => 'https://example.com/p', 'last_seen' => 1_700_000_000],
        ['label' => 'By hand', 'url' => 'https://example.com/q'],
      ],
    ]);
    $consumer->save();
    $this->removeColumns($missing);
    $this->assertNotSame([], $this->pendingChanges(), 'Drupal sees the field as changed.');

    $this->assertSame($message, consumer_node_preview_update_10001());

    $this->assertSame([], $this->pendingChanges());
    $targets = $this->reload($consumer)->get('preview_targets')->getValue();
    $this->assertSame(['Frontend', 'By hand'], array_column($targets, 'label'));
    $this->assertSame(in_array('last_seen', $missing, TRUE) ? NULL : 1_700_000_000, isset($targets[0]['last_seen']) ? (int) $targets[0]['last_seen'] : NULL);
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
    $this->assertSame('The preview targets columns are already in place.', consumer_node_preview_update_10001());
  }

  /**
   * Returns the earlier copies of the module, by the columns they lacked.
   *
   * @return array<string, array{list<string>, string}>
   *   The missing properties and the update's message.
   */
  public static function earlierCopies(): array {
    return [
      '1.0.0-beta1' => [
        ['expires'],
        'Added preview_targets_expires to consumer__preview_targets.',
      ],
      'Before 1.0.0-beta1' => [
        ['last_seen', 'expires'],
        'Added preview_targets_last_seen and preview_targets_expires to consumer__preview_targets.',
      ],
    ];
  }

  /**
   * Leaves the database as an earlier copy of the module did.
   *
   * The columns go from the table and from the schema Drupal recorded for
   * the field.
   *
   * @param list<string> $properties
   *   The properties whose columns go.
   */
  protected function removeColumns(array $properties): void {
    /** @var \Drupal\Core\Entity\Sql\SqlEntityStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $definition = $this->container->get('entity.definition_update_manager')->getFieldStorageDefinition('preview_targets', 'consumer');
    $mapping = $storage->getTableMapping();
    $table = $mapping->getFieldTableName('preview_targets');
    $key_value = $this->container->get('keyvalue')->get('entity.storage_schema.sql');
    $stored = $key_value->get('consumer.field_schema_data.preview_targets');
    foreach ($properties as $property) {
      $column = $mapping->getFieldColumnName($definition, $property);
      $this->container->get('database')->schema()->dropField($table, $column);
      unset($stored[$table]['fields'][$column]);
    }
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
