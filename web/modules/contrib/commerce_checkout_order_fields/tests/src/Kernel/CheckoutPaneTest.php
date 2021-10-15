<?php

namespace Drupal\Tests\commerce_checkout_order_fields\Kernel;

use Drupal\commerce_checkout\Entity\CheckoutFlow;
use Drupal\commerce_order\Entity\Order;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;

/**
 * Tests the checkout pane.
 *
 * @group commerce_checkout_order_fields
 */
class CheckoutPaneTest extends OrderKernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'commerce_product',
    'commerce_checkout',
    'commerce_checkout_order_fields',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installConfig('commerce_order');
    $this->installConfig('commerce_product');
    $this->installConfig('commerce_checkout');
    $this->installConfig('commerce_checkout_order_fields');
    $checkout_pane_manager = $this->container->get('plugin.manager.commerce_checkout_pane');
    $checkout_pane_manager->clearCachedDefinitions();

    $field_storage = FieldStorageConfig::create([
      'field_name' => 'test_string_field',
      'entity_type' => 'commerce_order',
      'type' => 'string',
    ]);
    $field_storage->save();
    $instance = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'default',
      'label' => 'Test String Field',
    ]);
    $instance->save();

    $form_display = EntityFormDisplay::create([
      'targetEntityType' => 'commerce_order',
      'bundle' => 'default',
      'mode' => 'checkout',
      'status' => TRUE,
    ]);
    $form_display->removeComponent('adjustments');
    $form_display->removeComponent('billing_profile');
    $form_display->removeComponent('order_items');
    $form_display->setComponent('test_string_field', [
      'type' => 'string_textfield',
      'region' => 'content',
    ]);
    $form_display->save();

    $view_mode = EntityViewMode::create([
      'id' => 'commerce_order.checkout',
      'targetEntityType' => 'commerce_order',
      'status' => TRUE,
      'enabled' => TRUE,
      'label' => 'Checkout view mode',
    ]);
    $view_mode->save();
    $view_display = EntityViewDisplay::create([
      'targetEntityType' => 'commerce_order',
      'bundle' => 'default',
      'mode' => 'checkout',
      'status' => TRUE,
    ]);
    $view_display->setComponent('test_string_field', [
      'type' => 'string',
      'label' => 'above',
      'region' => 'content',
    ])->save();
    $view_display->save();

  }

  public function testOrderFieldsPanesDeriver() {
    $checkout_pane_manager = $this->container->get('plugin.manager.commerce_checkout_pane');
    $definitions = $checkout_pane_manager->getDefinitions();
    $this->assertTrue(isset($definitions['order_fields:checkout']));
  }

  /**
   * Tests the pane plugin.
   *
   * @dataProvider dataCheckoutPaneConfiguration
   */
  public function testCheckoutPaneConfiguration(array $pane_configuration, $test_string_value, array $expected) {
    $checkout_flow = CheckoutFlow::load('default');
    $configuration = $checkout_flow->get('configuration');
    $configuration['panes']['order_fields:checkout'] = $pane_configuration;
    $checkout_flow->set('configuration', $configuration);
    // Save so we can verify the config schema.
    $checkout_flow->save();

    /** @var \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowWithPanesInterface $plugin */
    $plugin = $checkout_flow->getPlugin();
    $panes = $plugin->getPanes();

    $this->assertTrue(isset($panes['order_fields:checkout']));
    $pane = $panes['order_fields:checkout'];
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'mail' => 'text@example.com',
      'ip_address' => '127.0.0.1',
    ]);
    $order->get('test_string_field')->setValue($test_string_value);
    $pane->setOrder($order);
    $this->assertEquals($pane->getStepId(), $expected[0]);
    $this->assertEquals($expected[1], (string) $pane->buildConfigurationSummary());
    $this->assertEquals($expected[2], $pane->getWrapperElement());

    $pane_summary = $pane->buildPaneSummary();
    if (empty($test_string_value)) {
      $this->assertEmpty($pane_summary, print_r($pane_summary, true));
    }
    else {
      $this->render($pane_summary);
      $this->assertText($test_string_value);
    }
  }

  /**
   * Verifies the coupons field widget is always removed.
   */
  public function testCheckoutCouponsRemoved() {
    $this->installModule('commerce_promotion');
    $checkout_flow = CheckoutFlow::load('default');
    /** @var \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowWithPanesInterface $plugin */
    $plugin = $checkout_flow->getPlugin();
    $pane = $plugin->getPane('order_fields:checkout');

    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'mail' => 'text@example.com',
      'ip_address' => '127.0.0.1',
    ]);
    $pane->setOrder($order);
    $form_state = new FormState();
    $complete_form = [];
    $form = $pane->buildPaneForm([], $form_state, $complete_form);
    $this->assertFalse(isset($form['coupons']));
  }

  /**
   * Data generator for test.
   */
  public function dataCheckoutPaneConfiguration() {
    yield [
      [],
      'Test string',
      [
        '_disabled',
        '<p>Wrapper element: Container</p><p>Display label: Order fields</p>',
        'container',
      ],
    ];
    yield [
      [
        'step' => 'order_information',
        'wrapper_element' => 'fieldset',
      ],
      'Test string',
      [
        'order_information',
        '<p>Wrapper element: Fieldset</p><p>Display label: Order fields</p>',
        'fieldset',
      ],
    ];
    yield [
      [
        'step' => 'order_information',
        'wrapper_element' => 'container',
      ],
      'Test string',
      [
        'order_information',
        '<p>Wrapper element: Container</p><p>Display label: Order fields</p>',
        'container',
      ],
    ];
    yield [
      [
        'step' => 'order_information',
        'wrapper_element' => 'fieldset',
        'display_label' => 'Custom fields',
      ],
      'Test string',
      [
        'order_information',
        '<p>Wrapper element: Fieldset</p><p>Display label: Custom fields</p>',
        'fieldset',
      ],
    ];
    yield [
      [
        'step' => 'order_information',
        'wrapper_element' => 'fieldset',
      ],
      null,
      [
        'order_information',
        '<p>Wrapper element: Fieldset</p><p>Display label: Order fields</p>',
        'fieldset',
      ],
    ];
  }

}
