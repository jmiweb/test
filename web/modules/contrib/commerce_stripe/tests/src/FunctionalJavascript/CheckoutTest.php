<?php

namespace Drupal\Tests\commerce_stripe\FunctionalJavascript;

use Behat\Mink\Driver\Selenium2Driver;
use Behat\Mink\Element\NodeElement;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\SoftDeclineException;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripeInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Url;
use Drupal\Tests\commerce\FunctionalJavascript\CommerceWebDriverTestBase;
use Drupal\Tests\commerce_stripe\Kernel\StripeIntegrationTestBase;

/**
 * @group commerce_stripe
 */
class CheckoutTest extends CommerceWebDriverTestBase {

  /**
   * The product.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected $product;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_number_pattern',
    'commerce_product',
    'commerce_cart',
    'commerce_checkout',
    'commerce_stripe',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $variation = $this->createEntity('commerce_product_variation', [
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'price' => [
        'number' => '9.99',
        'currency_code' => 'USD',
      ],
    ]);

    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $this->product = $this->createEntity('commerce_product', [
      'type' => 'default',
      'title' => 'My product',
      'variations' => [$variation],
      'stores' => [$this->store->id()],
    ]);

    $gateway = PaymentGateway::create([
      'id' => 'stripe_testing',
      'label' => 'Stripe',
      'plugin' => 'stripe',
      'configuration' => [
        'payment_method_types' => ['credit_card'],
        'publishable_key' => StripeIntegrationTestBase::TEST_PUBLISHABLE_KEY,
        'secret_key' => StripeIntegrationTestBase::TEST_SECRET_KEY,
      ],
    ]);
    $gateway->save();

    // Cheat so we don't need JS to interact w/ Address field widget.
    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $customer_form_display */
    $customer_form_display = EntityFormDisplay::load('profile.customer.default');
    $address_component = $customer_form_display->getComponent('address');
    $address_component['settings']['default_country'] = 'US';
    $customer_form_display->setComponent('address', $address_component);
    $customer_form_display->save();
    $this->drupalLogout();

  }

  /**
   * Tests an anonymous customer can checkout.
   *
   * This uses a card which does not trigger SCA or 3DS authentication.
   *
   * @dataProvider dataProviderUserAuthenticated
   */
  public function testCheckoutAndPayment($authenticated) {
    if ($authenticated) {
      $customer = $this->createUser();
      $this->drupalLogin($customer);
    }
    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $cart_link = $this->getSession()->getPage()->findLink('your cart');
    $cart_link->click();
    $this->submitForm([], 'Checkout');

    if (!$authenticated) {
      $this->submitForm([], 'Continue as Guest');
      $this->getSession()->getPage()->fillField('contact_information[email]', 'guest@example.com');
      $this->getSession()->getPage()->fillField('contact_information[email_confirm]', 'guest@example.com');
    }

    $this->fillCreditCardData('4242424242424242', '0322', '123');

    $this->submitForm([
      'payment_information[add_payment_method][billing_information][address][0][address][given_name]' => 'Johnny',
      'payment_information[add_payment_method][billing_information][address][0][address][family_name]' => 'Appleseed',
      'payment_information[add_payment_method][billing_information][address][0][address][address_line1]' => '123 New York Drive',
      'payment_information[add_payment_method][billing_information][address][0][address][locality]' => 'New York City',
      'payment_information[add_payment_method][billing_information][address][0][address][administrative_area]' => 'NY',
      'payment_information[add_payment_method][billing_information][address][0][address][postal_code]' => '10001',
    ], 'Continue to review');

    $this->assertWaitForText('Visa ending in 4242');
    $this->assertWaitForText('Expires 3/2022');
    $this->submitForm([], 'Pay and complete purchase');

    $this->assertWaitForText('Your order number is 1. You can view your order on your account page when logged in.');
  }

  /**
   * Tests checkout without billing information.
   *
   * This uses a card which does not trigger SCA or 3DS authentication.
   *
   * @dataProvider dataProviderUserAuthenticated
   */
  public function testNoBillingCheckout($authenticated) {
    $payment_gateway = PaymentGateway::load('stripe_testing');
    $configuration = $payment_gateway->getPlugin()->getConfiguration();
    $configuration['collect_billing_information'] = FALSE;
    $payment_gateway->getPlugin()->setConfiguration($configuration);
    $payment_gateway->save();

    if ($authenticated) {
      $customer = $this->createUser();
      $this->drupalLogin($customer);
    }
    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $cart_link = $this->getSession()->getPage()->findLink('your cart');
    $cart_link->click();
    $this->submitForm([], 'Checkout');

    if (!$authenticated) {
      $this->submitForm([], 'Continue as Guest');
      $this->getSession()->getPage()->fillField('contact_information[email]', 'guest@example.com');
      $this->getSession()->getPage()->fillField('contact_information[email_confirm]', 'guest@example.com');
    }

    $this->fillCreditCardData('4242424242424242', '0322', '123');
    $this->submitForm([], 'Continue to review');

    $this->assertWaitForText('Visa ending in 4242');
    $this->assertWaitForText('Expires 3/2022');
    $this->submitForm([], 'Pay and complete purchase');

    $this->assertWaitForText('Your order number is 1. You can view your order on your account page when logged in.');
  }

  /**
   * Tests customer, with regulations, can checkout.
   *
   * This card requires authentication for one-time payments. However, if you
   * set up this card and use the saved card for subsequent off-session
   * payments, no further authentication is needed. In live mode, Stripe
   * dynamically determines when a particular transaction requires
   * authentication due to regional regulations such as
   * Strong Customer Authentication.
   *
   * @dataProvider dataProviderUserAuthenticatedAndCardAuthentication
   * @group threeds
   */
  public function testCheckoutAndPayPayment3ds($authenticated, $pass) {
    if ($authenticated) {
      $customer = $this->createUser();
      $this->drupalLogin($customer);
    }

    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $cart_link = $this->getSession()->getPage()->findLink('your cart');
    $cart_link->click();
    $this->submitForm([], 'Checkout');

    if (!$authenticated) {
      $this->submitForm([], 'Continue as Guest');
      $this->getSession()->getPage()->fillField('contact_information[email]', 'guest@example.com');
      $this->getSession()->getPage()->fillField('contact_information[email_confirm]', 'guest@example.com');
    }

    $this->fillCreditCardData('4000002500003155', '0322', '123');
    $this->submitForm([
      'payment_information[add_payment_method][billing_information][address][0][address][given_name]' => 'Johnny',
      'payment_information[add_payment_method][billing_information][address][0][address][family_name]' => 'Appleseed',
      'payment_information[add_payment_method][billing_information][address][0][address][address_line1]' => '123 New York Drive',
      'payment_information[add_payment_method][billing_information][address][0][address][locality]' => 'New York City',
      'payment_information[add_payment_method][billing_information][address][0][address][administrative_area]' => 'NY',
      'payment_information[add_payment_method][billing_information][address][0][address][postal_code]' => '10001',
    ], 'Continue to review');

    $this->assertWaitForText('Visa ending in 3155');
    $this->assertWaitForText('Expires 3/2022');
    $this->getSession()->getPage()->pressButton('Pay and complete purchase');
    $this->complete3ds($pass);

    if ($pass) {
      $this->assertWaitForText('Your order number is 1. You can view your order on your account page when logged in.');
    }
    else {
      $this->assertWaitForText('We encountered an error processing your payment method. Please verify your details and try again.');
    }
  }

  /**
   * Tests customer, with regulations, can checkout.
   *
   * This card requires authentication on all transactions, regardless of how
   * the card is set up.
   *
   * @note: When always using SetupIntent, this would cause two authentication
   * modals. One when persisting the payment method and another when confirming
   * the payment intent.
   *
   * @dataProvider dataProviderUserAuthenticatedAndCardAuthentication
   * @group threeds
   */
  public function test3dsAlwaysAuthenticate($authenticated, $pass) {
    if ($authenticated) {
      $customer = $this->createUser();
      $this->drupalLogin($customer);
    }

    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $cart_link = $this->getSession()->getPage()->findLink('your cart');
    $cart_link->click();
    $this->submitForm([], 'Checkout');

    if (!$authenticated) {
      $this->submitForm([], 'Continue as Guest');
      $this->getSession()->getPage()->fillField('contact_information[email]', 'guest@example.com');
      $this->getSession()->getPage()->fillField('contact_information[email_confirm]', 'guest@example.com');
    }

    $this->fillCreditCardData('4000002760003184', '0322', '123');
    $this->submitForm([
      'payment_information[add_payment_method][billing_information][address][0][address][given_name]' => 'Johnny',
      'payment_information[add_payment_method][billing_information][address][0][address][family_name]' => 'Appleseed',
      'payment_information[add_payment_method][billing_information][address][0][address][address_line1]' => '123 New York Drive',
      'payment_information[add_payment_method][billing_information][address][0][address][locality]' => 'New York City',
      'payment_information[add_payment_method][billing_information][address][0][address][administrative_area]' => 'NY',
      'payment_information[add_payment_method][billing_information][address][0][address][postal_code]' => '10001',
    ], 'Continue to review');

    $this->assertWaitForText('Visa ending in 3184');
    $this->assertWaitForText('Expires 3/2022');
    $this->getSession()->getPage()->pressButton('Pay and complete purchase');

    $this->complete3ds($pass);

    if ($pass) {
      $this->assertWaitForText('Your order number is 1. You can view your order on your account page when logged in.');
    }
    else {
      $this->assertWaitForText('We encountered an error processing your payment method. Please verify your details and try again.');
    }
  }

  /**
   * Tests checkout with a previously created payment method.
   *
   * @dataProvider dataProviderExistingPaymentMethodCardNumber
   * @group threeds
   * @group existing
   * @group on_session
   */
  public function testCheckoutWithExistingPaymentMethod($card_number) {
    $customer = $this->createUser([
      'manage own commerce_payment_method',
    ]);
    $this->drupalLogin($customer);

    $this->drupalGet(Url::fromRoute('entity.commerce_payment_method.add_form', [
      'user' => $customer->id(),
    ]));
    $this->fillCreditCardData($card_number, '0322', '123');
    $this->submitForm([
      'payment_method[billing_information][address][0][address][given_name]' => 'Johnny',
      'payment_method[billing_information][address][0][address][family_name]' => 'Appleseed',
      'payment_method[billing_information][address][0][address][address_line1]' => '123 New York Drive',
      'payment_method[billing_information][address][0][address][locality]' => 'New York City',
      'payment_method[billing_information][address][0][address][administrative_area]' => 'NY',
      'payment_method[billing_information][address][0][address][postal_code]' => '10001',
    ], 'Save');

    $this->complete3ds(TRUE, FALSE);

    $this->assertWaitForText('Visa ending in ' . substr($card_number, -4) . ' saved to your payment methods.');
    $this->drupalGet(Url::fromRoute('entity.commerce_payment_method.collection', [
      'user' => $customer->id(),
    ]));
    $this->assertSession()->pageTextContains('Visa ending in ' . substr($card_number, -4));

    $this->drupalGet($this->product->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $cart_link = $this->getSession()->getPage()->findLink('your cart');
    $cart_link->click();
    $this->getSession()->getPage()->pressButton('Checkout');
    $this->getSession()->getPage()->pressButton('Continue to review');
    $this->waitForStripe();
    $this->assertSession()->pageTextContains('Payment information');
    $this->assertSession()->pageTextContains('Visa ending in ' . substr($card_number, -4));
    $this->assertSession()->pageTextContains('Expires 3/2022');
    $this->assertSession()->pageTextContains('Order Summary');
    $this->getSession()->getPage()->pressButton('Pay and complete purchase');

    $this->complete3ds(TRUE);

    $this->assertWaitForText('Your order number is 1. You can view your order on your account page when logged in.');
  }

  /**
   * Tests checkout with a previously created payment method.
   *
   * @dataProvider dataProviderExistingPaymentMethodCardNumber
   * @group threeds
   * @group existing
   * @group off_session
   */
  public function testCheckoutWithExistingPaymentMethodOffSession($card_number) {
    $customer = $this->createUser([
      'manage own commerce_payment_method',
    ]);
    $this->drupalLogin($customer);

    $this->drupalGet(Url::fromRoute('entity.commerce_payment_method.add_form', [
      'user' => $customer->id(),
    ]));
    $this->fillCreditCardData($card_number, '0322', '123');
    $this->submitForm([
      'payment_method[billing_information][address][0][address][given_name]' => 'Johnny',
      'payment_method[billing_information][address][0][address][family_name]' => 'Appleseed',
      'payment_method[billing_information][address][0][address][address_line1]' => '123 New York Drive',
      'payment_method[billing_information][address][0][address][locality]' => 'New York City',
      'payment_method[billing_information][address][0][address][administrative_area]' => 'NY',
      'payment_method[billing_information][address][0][address][postal_code]' => '10001',
    ], 'Save');

    $this->complete3ds(TRUE, FALSE);

    $this->assertWaitForText('Visa ending in ' . substr($card_number, -4) . ' saved to your payment methods.');
    $this->drupalGet(Url::fromRoute('entity.commerce_payment_method.collection', [
      'user' => $customer->id(),
    ]));
    $this->assertSession()->pageTextContains('Visa ending in ' . substr($card_number, -4));

    // Create an off_session order with the payment method generated.
    $cart_provider = $this->container->get('commerce_cart.cart_provider');
    $cart_manager = $this->container->get('commerce_cart.cart_manager');

    $cart = $cart_provider->createCart('default', $this->store, $customer);
    $cart_manager->addEntity($cart, $this->product->getDefaultVariation());

    $gateway = PaymentGateway::load('stripe_testing');
    $payment_method = PaymentMethod::load(1);

    $cart->set('billing_profile', $payment_method->getBillingProfile());
    $cart->set('payment_method', $payment_method);
    $cart->set('payment_gateway', $gateway->id());
    $cart->save();

    $plugin = $gateway->getPlugin();
    assert($plugin instanceof StripeInterface);
    $plugin->createPaymentIntent($cart);

    $payment = Payment::create([
      'state' => 'new',
      'amount' => $cart->getBalance(),
      'payment_gateway' => $gateway,
      'payment_method' => $payment_method,
      'order_id' => $cart,
    ]);

    // @todo 4000003800000446 _should_ not require authentication. Supposedly.
    // Discussed with Stripe support in IRC and they could not confirm.
    $this->setExpectedException(
      SoftDeclineException::class,
      'The payment intent requires action by the customer for authentication'
    );
    try {
      $plugin->createPayment($payment);
    }
    catch (HardDeclineException $e) {
      $this->fail($e->getMessage());
    }
  }

  /**
   * Data provider to provide a pass or truthy data set.
   *
   * @return \Generator
   *   The data.
   */
  public function dataProviderUserAuthenticated() {
    yield [TRUE];
    yield [FALSE];
  }

  /**
   * Data provider for user authentication and card authentication.
   *
   * @return \Generator
   *   The data.
   */
  public function dataProviderUserAuthenticatedAndCardAuthentication() {
    // Logged in, card authorized.
    yield [TRUE, TRUE];
    // Anonymous, card authorized.
    yield [FALSE, TRUE];
    // Logged in, card unauthorized.
    yield [TRUE, FALSE];
    // Anonymous, card unauthorized.
    yield [FALSE, FALSE];
  }

  /**
   * Data provider for card numbers when testing existing payment methods.
   *
   * @return \Generator
   *   The data.
   */
  public function dataProviderExistingPaymentMethodCardNumber() {
    // These can be added, but must go through one authentication approval via
    // an on-session payment intent.
    yield ['4000002500003155'];
    yield ['4000002760003184'];
    // This card requires authentication for one-time and other on-session
    // payments. However, all off-session payments will succeed as if the card
    // has been previously set up.
    yield ['4000003800000446'];
  }

  /**
   * Helper method to wait for Stripe actions on the client.
   */
  protected function waitForStripe() {
    // @todo better assertion to wait for the form to submit.
    sleep(6);
  }

  /**
   * Fills the credit card form inputs.
   *
   * @param string $card_number
   *   The card number.
   * @param string $card_exp
   *   The card expiration.
   * @param string $card_cvv
   *   The card CVV.
   *
   * @throws \Behat\Mink\Exception\ElementNotFoundException
   * @throws \Behat\Mink\Exception\ResponseTextException
   * @throws \WebDriver\Exception
   */
  protected function fillCreditCardData($card_number, $card_exp, $card_cvv) {
    $this->getSession()->switchToIFrame('__privateStripeFrame5');
    $element = $this->getSession()->getPage()->findField('cardnumber');
    $this->fieldTypeInput($element, $card_number);
    $this->getSession()->switchToIFrame();
    $this->assertSession()->pageTextNotContains('Your card number is invalid.');
    $this->getSession()->switchToIFrame('__privateStripeFrame6');
    $element = $this->getSession()->getPage()->findField('exp-date');
    $this->fieldTypeInput($element, $card_exp);
    $this->getSession()->switchToIFrame();
    $this->getSession()->switchToIFrame('__privateStripeFrame7');
    $this->getSession()->getPage()->fillField('cvc', $card_cvv);
    $this->getSession()->switchToIFrame();
  }

  /**
   * Fills an inputs values by simulated typing.
   *
   * @param \Behat\Mink\Element\NodeElement $element
   *   The element.
   * @param string $value
   *   The value.
   *
   * @throws \WebDriver\Exception
   */
  protected function fieldTypeInput(NodeElement $element, $value) {
    $driver = $this->getSession()->getDriver();
    $element->click();
    if ($driver instanceof Selenium2Driver) {
      $wd_element = $driver->getWebDriverSession()->element('xpath', $element->getXpath());
      foreach (str_split($value) as $char) {
        $wd_element->postValue(['value' => [$char]]);
        usleep(100);
      }
    }
    $element->blur();
  }

  /**
   * Asserts text will become visible on the page.
   *
   * @param string $text
   *   The text.
   * @param int $wait
   *   The wait time, in seconds.
   *
   * @return bool
   *   Returns TRUE if operation succeeds.
   *
   * @throws \Exception
   */
  public function assertWaitForText($text, $wait = 20) {
    $last_exception = NULL;
    $stopTime = time() + $wait;
    while (time() < $stopTime) {
      try {
        $this->assertSession()->pageTextContains($text);
        return TRUE;
      }
      catch (\Exception $e) {
        // If the text has not been found, keep waiting.
        $last_exception = $e;
      }
      usleep(250000);
    }
    $this->createScreenshot('../challenge_frame_wtf.png');
    throw $last_exception;
  }

  /**
   * Waits for a frame to become available and then switches to it.
   *
   * @param string $name
   *   The frame name.
   * @param int $wait
   *   The wait time, in seconds.
   *
   * @return bool
   *   Returns TRUE if operation succeeds.
   *
   * @throws \Exception
   */
  public function switchToFrame($name, $wait = 20) {
    $last_exception = NULL;
    $stopTime = time() + $wait;
    while (time() < $stopTime) {
      try {
        $this->assertSession()->elementExists('xpath', "//iframe[@id='$name' or @name='$name']");
        $this->getSession()->switchToIFrame($name);
        sleep(1);
        return;
      }
      catch (\Exception $e) {
        // If the frame has not been found, keep waiting.
        $last_exception = $e;
      }
      usleep(250000);
    }
    throw $last_exception;
  }

  /**
   * Completes 3DS authentication using Stripe's modal.
   *
   * @param $pass
   *   Whether to pass or fail the 3DS authentication.
   * @param $payment
   *   Whether this is a payment or non-payment 3DS.
   *
   * @throws \Behat\Mink\Exception\ElementNotFoundException
   */
  protected function complete3ds($pass, $payment = TRUE) {
    $stripeFrame = '__privateStripeFrame9';
    $text = 'This is a 3D Secure non-payment authentication test page.';
    if ($payment) {
      $stripeFrame = '__privateStripeFrame4';
      $text = '3D Secure Test Payment Page';
    }
    $this->switchToFrame($stripeFrame);
    $this->switchToFrame('challengeFrame');
    $this->switchToFrame('acsFrame');
    $this->assertWaitForText($text);
    $button = $pass ? 'Complete authentication' : 'Fail authentication';
    $this->getSession()->getPage()->pressButton($button);
    $this->getSession()->switchToIFrame();
  }

}
