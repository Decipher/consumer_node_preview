<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Functional;

use Drupal\consumers\Entity\Consumer;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the owner field on the consumer form.
 *
 * The owner of a consumer may register its preview targets over the API.
 *
 * @group consumer_node_preview
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class ConsumerOwnerFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['consumer_node_preview'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The name of the owner field's widget.
   */
  protected const OWNER = 'owner_id[0][target_id]';

  /**
   * Tests that an administrator sets the owner, and the API follows it.
   */
  public function testAdministratorSetsTheOwner(): void {
    $first = $this->account(['register consumer node preview targets']);
    $second = $this->account(['register consumer node preview targets']);
    $this->drupalLogin($this->account(['administer consumer entities']));

    $this->drupalGet(Url::fromRoute('entity.consumer.add_form'));
    $assert = $this->assertSession();
    $assert->fieldExists(self::OWNER);
    $assert->pageTextContains('Owner');
    $assert->pageTextContains("The owner can register this consumer's preview targets over the API.");
    $this->submitForm([
      'label[0][value]' => 'App',
      'client_id[0][value]' => 'app',
      self::OWNER => $this->autocompleteValue($first),
    ], 'Save');
    $consumer = $this->reloadConsumer('app');
    $this->assertSame((int) $first->id(), (int) $consumer->getOwnerId());

    // The edit form shows the owner, and hands the consumer to another user.
    $this->drupalGet($consumer->toUrl('edit-form'));
    $assert->fieldValueEquals(self::OWNER, $this->autocompleteValue($first));
    $this->submitForm([self::OWNER => $this->autocompleteValue($second)], 'Save');
    $consumer = $this->reloadConsumer('app');
    $this->assertSame((int) $second->id(), (int) $consumer->getOwnerId());

    // The new owner may register targets. The old owner may not.
    $this->assertSame(200, $this->putTargets($second, $consumer, 'Second'));
    $this->assertSame(403, $this->putTargets($first, $consumer, 'First'));
    $this->assertSame('Second', $this->reloadConsumer('app')->get('preview_targets')->label);
  }

  /**
   * Tests that a user who edits their own consumer cannot change its owner.
   */
  public function testOwnerCannotHandOverTheirConsumer(): void {
    $other = $this->account();
    $owner = $this->account([
      'add consumer entities',
      'update own consumer entities',
      'view own consumer entities',
    ]);
    $this->drupalLogin($owner);

    // A new consumer belongs to the user who adds it.
    $this->drupalGet(Url::fromRoute('entity.consumer.add_form'));
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    $assert->fieldNotExists(self::OWNER);
    $this->submitForm([
      'label[0][value]' => 'Mine',
      'client_id[0][value]' => 'mine',
    ], 'Save');
    $consumer = $this->reloadConsumer('mine');
    $this->assertSame((int) $owner->id(), (int) $consumer->getOwnerId());

    // The edit form has no owner field, so the owner stays as it is.
    $this->drupalGet($consumer->toUrl('edit-form'));
    $assert->statusCodeEquals(200);
    $assert->fieldNotExists(self::OWNER);
    $this->submitForm(['label[0][value]' => 'Still mine'], 'Save');
    $consumer = $this->reloadConsumer('mine');
    $this->assertSame('Still mine', $consumer->label());
    $this->assertSame((int) $owner->id(), (int) $consumer->getOwnerId());

    // The field refuses an edit from this user in any other client too.
    $this->assertFalse($consumer->get('owner_id')->access('edit', $owner));
    $this->assertTrue($consumer->get('owner_id')->access('view', $owner));
    $this->assertNotSame((int) $other->id(), (int) $consumer->getOwnerId());
  }

  /**
   * Creates an active user with the given permissions.
   *
   * Drupal 10 types drupalCreateUser() as User|false and Drupal 11 types it
   * as UserInterface. This narrows the result to a user on both versions.
   *
   * @param string[] $permissions
   *   The permissions the user's role grants.
   */
  protected function account(array $permissions = []): UserInterface {
    return $this->asAccount($this->drupalCreateUser($permissions));
  }

  /**
   * Narrows a created user to a user.
   *
   * @param mixed $account
   *   The created user.
   *
   * @return \Drupal\user\UserInterface
   *   The user.
   */
  protected function asAccount(mixed $account): UserInterface {
    if (!$account instanceof UserInterface) {
      throw new \LogicException('The user was not created.');
    }
    return $account;
  }

  /**
   * Returns what the autocomplete widget holds for a user.
   */
  protected function autocompleteValue(UserInterface $account): string {
    return sprintf('%s (%d)', $account->getAccountName(), $account->id());
  }

  /**
   * Loads a consumer by its client ID, past the static cache.
   */
  protected function reloadConsumer(string $client_id): ConsumerInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $storage->resetCache();
    $consumers = $storage->loadByProperties(['client_id' => $client_id]);
    $consumer = reset($consumers);
    $this->assertInstanceOf(Consumer::class, $consumer);
    return $consumer;
  }

  /**
   * Replaces a consumer's targets over the API as a user.
   *
   * The request carries the user's session cookie, so it sends a CSRF token.
   *
   * @return int
   *   The response status code.
   */
  protected function putTargets(UserInterface $account, ConsumerInterface $consumer, string $label): int {
    $this->drupalLogin($account);
    $token = $this->drupalGet('session/token');
    $url = Url::fromRoute('consumer_node_preview.targets', [], [
      'query' => ['consumer' => $consumer->uuid()],
      'absolute' => TRUE,
    ]);
    $response = $this->getHttpClient()->request('PUT', $url->toString(), [
      'cookies' => $this->getSessionCookies(),
      'headers' => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $token,
      ],
      'body' => json_encode([
        'targets' => [['label' => $label, 'url' => 'https://example.com/p']],
      ], JSON_THROW_ON_ERROR),
      'http_errors' => FALSE,
    ]);
    return $response->getStatusCode();
  }

}
