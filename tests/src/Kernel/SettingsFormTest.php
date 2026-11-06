<?php

declare(strict_types=1);

namespace Drupal\Tests\consumer_node_preview\Kernel;

use Drupal\consumer_node_preview\Form\SettingsForm;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the settings form.
 */
#[Group('consumer_node_preview')]
#[RunTestsInSeparateProcesses]
class SettingsFormTest extends KernelTestBase {

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
    $this->installConfig(['consumer_node_preview']);
  }

  /**
   * Tests that the form saves one host per line, and shows them the same way.
   */
  public function testSavesHostsAndLimits(): void {
    $form_state = (new FormState())->setValues([
      'allowed_target_hosts' => " www.Example.com\r\n\r\n*.preview.example.com \n",
      'token_lifetime' => '60',
      'inactive_after' => '3600',
      'delete_after' => '7200',
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());

    $config = $this->config('consumer_node_preview.settings');
    $this->assertSame(['www.example.com', '*.preview.example.com'], $config->get('allowed_target_hosts'));
    $this->assertSame(60, $config->get('token_lifetime'));
    $this->assertSame(3600, $config->get('inactive_after'));
    $this->assertSame(7200, $config->get('delete_after'));

    $form = $this->container->get('form_builder')->getForm(SettingsForm::class);
    $this->assertSame("www.example.com\n*.preview.example.com", $form['allowed_target_hosts']['#default_value']);
  }

  /**
   * Tests the form on a site installed before the settings existed.
   */
  public function testShowsDefaultsWithoutConfig(): void {
    $this->config('consumer_node_preview.settings')->delete();
    $form = $this->container->get('form_builder')->getForm(SettingsForm::class);
    $this->assertSame('', $form['allowed_target_hosts']['#default_value']);
    $this->assertSame(900, $form['token_lifetime']['#default_value']);
    $this->assertSame(86400, $form['expiry']['inactive_after']['#default_value']);
    $this->assertSame(2592000, $form['expiry']['delete_after']['#default_value']);
  }

  /**
   * Tests that a line that is not a host is refused, and named in the error.
   */
  public function testRefusesLinesThatAreNotHosts(): void {
    foreach (['https://preview.example.com', 'preview.example.com:3000', 'preview.example.com/p', 'two words'] as $line) {
      $form_state = (new FormState())->setValues([
        'allowed_target_hosts' => "localhost\n" . $line,
        'token_lifetime' => '60',
        'inactive_after' => '3600',
        'delete_after' => '7200',
      ]);
      $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);
      $errors = $form_state->getErrors();
      $this->assertArrayHasKey('allowed_target_hosts', $errors, $line);
      $this->assertStringContainsString($line, (string) $errors['allowed_target_hosts']);
    }
    $saved = $this->container->get('config.storage')->read('consumer_node_preview.settings');
    $this->assertIsArray($saved);
    $this->assertSame([], $saved['allowed_target_hosts']);
  }

  /**
   * Tests that a limit below one second is refused.
   */
  public function testRefusesZero(): void {
    $form_state = (new FormState())->setValues([
      'allowed_target_hosts' => '',
      'token_lifetime' => '0',
      'inactive_after' => '3600',
      'delete_after' => '7200',
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);
    $this->assertArrayHasKey('token_lifetime', $form_state->getErrors());
    // The form copies values into its config object before validating, so
    // read what was saved.
    $saved = $this->container->get('config.storage')->read('consumer_node_preview.settings');
    $this->assertIsArray($saved);
    $this->assertSame(900, $saved['token_lifetime']);
  }

}
