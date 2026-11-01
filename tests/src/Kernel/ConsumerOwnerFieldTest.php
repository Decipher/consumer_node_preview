<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Tests the owner field of consumers.
 *
 * @group consumer_node_preview
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class ConsumerOwnerFieldTest extends KernelTestBase {

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
    $this->installConfig(['system']);
  }

  /**
   * Tests that the default form display has an autocomplete owner widget.
   */
  public function testFormDisplayHasTheOwner(): void {
    $component = EntityFormDisplay::collectRenderDisplay(Consumer::create(), 'default')->getComponent('owner_id');
    $this->assertIsArray($component);
    $this->assertSame('entity_reference_autocomplete', $component['type']);
  }

  /**
   * Tests that a form display a site saved before still gets the owner.
   *
   * Core does not store a field in a form display while its display is not
   * configurable. So a saved display has no entry for the owner, and core
   * fills in the field's default widget. No update hook is needed.
   */
  public function testSavedFormDisplayGetsTheOwner(): void {
    $this->config('core.entity_form_display.consumer.consumer.default')
      ->setData([
        'langcode' => 'en',
        'status' => TRUE,
        'dependencies' => [],
        'id' => 'consumer.consumer.default',
        'targetEntityType' => 'consumer',
        'bundle' => 'consumer',
        'mode' => 'default',
        'content' => [
          'label' => [
            'type' => 'string_textfield',
            'weight' => -5,
            'region' => 'content',
            'settings' => ['size' => 60, 'placeholder' => ''],
            'third_party_settings' => [],
          ],
        ],
        'hidden' => [],
      ])
      ->save();

    $display = EntityFormDisplay::load('consumer.consumer.default');
    $this->assertInstanceOf(EntityFormDisplay::class, $display);
    $this->assertFalse($display->isNew());
    $component = $display->getComponent('owner_id');
    $this->assertIsArray($component);
    $this->assertSame('entity_reference_autocomplete', $component['type']);
  }

  /**
   * Tests that only a consumer administrator may edit the owner.
   */
  public function testOnlyAdministratorsEditTheOwner(): void {
    // The first created user would be uid 1 and bypass access checks.
    $this->account();
    $owner = $this->account(['update own consumer entities', 'view own consumer entities']);
    $admin = $this->account(['administer consumer entities']);

    $consumer = Consumer::create([
      'client_id' => 'app',
      'label' => 'App',
      'owner_id' => $owner->id(),
    ]);
    $consumer->save();

    $this->assertTrue($consumer->access('update', $owner));
    $this->assertFalse($consumer->get('owner_id')->access('edit', $owner));
    $this->assertTrue($consumer->get('owner_id')->access('view', $owner));
    $this->assertTrue($consumer->get('owner_id')->access('edit', $admin));
    // Other fields stay editable for the owner.
    $this->assertTrue($consumer->get('label')->access('edit', $owner));
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

}
