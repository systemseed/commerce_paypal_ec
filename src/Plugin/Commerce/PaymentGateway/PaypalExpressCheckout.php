<?php

namespace Drupal\commerce_paypal_ec\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_paypal_ec\PayPalInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_payment_example\Plugin\Commerce\PaymentGateway\OnsiteInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Paypal Express Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "commerce_paypal_ec",
 *   label = @Translation("PayPal (Express Checkout)"),
 *   display_label = @Translation("PayPal (Express Checkout)"),
 *   payment_method_types = {"paypal_ec"},
 * )
 */
class PaypalExpressCheckout extends OnsitePaymentGatewayBase implements OnsiteInterface {

  /**
   * @var \Drupal\commerce_paypal_ec\PayPalInterface
   *   Contains Paypal object for payments handling.
   */
  protected $payPal;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   *  Debugging logger object.
   */
  protected $logger;

  /**
   * Constructs a new PaypalExpressCheckout object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   *   The payment type manager.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   *   The payment method type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_channel_factory
   *   The logger channel factory.
   * @param \Drupal\commerce_paypal_ec\PayPalInterface $payPal
   *   PayPal class.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, LoggerChannelFactoryInterface $logger_channel_factory, PayPalInterface $payPal) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    $this->logger = $logger_channel_factory->get('commerce_paypal_ec');

    // Initialize paypal SDK with required values.
    $client_id = !empty($configuration['client_id']) ? $configuration['client_id'] : '';
    $client_secret = !empty($configuration['client_secret']) ? $configuration['client_secret'] : '';
    $mode = !empty($configuration['mode']) && $configuration['mode'] == 'live' ? 'live' : 'sandbox';
    $this->payPal = $payPal->initialize($client_id, $client_secret, $mode);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('logger.factory'),
      $container->get('commerce_paypal_ec.paypal')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'client_id' => '',
        'client_secret' => '',
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#description' => $this->t('REST Client ID, see <a href="@url">instructions</a>.', ['@url' => 'https://developer.paypal.com/docs/checkout/integrate/prerequisites/#3-get-rest-api-sandbox-credentials']),
      '#default_value' => $this->configuration['client_id'],
      '#required' => TRUE,
    ];

    $form['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Secret'),
      '#description' => $this->t('REST Client Secret, see <a href="@url">instructions</a>.', ['@url' => 'https://developer.paypal.com/docs/checkout/integrate/prerequisites/#3-get-rest-api-sandbox-credentials']),
      '#default_value' => $this->configuration['client_secret'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['client_id'] = $values['client_id'];
      $this->configuration['client_secret'] = $values['client_secret'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {

    // Make sure all required fields exists when the payment is getting created.
    $required_keys = ['type', 'data'];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }

    if (!in_array($payment_details['type'], ['single', 'subscription'])) {
      throw new \InvalidArgumentException(sprintf('Payment type should be either "single" or "subscription", %s given instead.', $payment_details['type']));
    }

    // Actually save payment type value in the database.
    $payment_method->setReusable(FALSE);
    $payment_method->payment_type = $payment_details['type'];
    $payment_method->save();

    // Pass payment details further. It won't save anything in the database
    // because payment method type doesn't have fields apart from these:
    // PaymentMethodType\PaypalExpressCheckout::buildFieldDefinitions
    $payment_method->payment_details = $payment_details['data'];
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);

    // Express checkout assumes that capture will happen after user confirms
    // transaction in the popup, so there is no way to capture the payment
    // during the payment creation.
    if (!empty($capture)) {
      return;
    }

    try {
      $payment_method = $payment->getPaymentMethod();
      $this->assertPaymentMethod($payment_method);

      $payment_type = $payment_method->payment_type->value;
      $payment_details = $payment_method->payment_details;
      if ($payment_type == 'subscription') {
        $token = $this->payPal->createSubscriptionPayment($payment_details);
      }
      elseif ($payment_type == 'single') {
        $token = $this->payPal->createSinglePayment($payment_details);
      }

      if (!empty($token)) {
        $payment_method->setRemoteId($token);
        $payment_method->save();
        $payment->setRemoteId($token);
        $payment->save();
      }
    } catch (\Exception $exception) {
      $this->logger->error($exception->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    try {
      $payment_method = $payment->getPaymentMethod();
      $payment_type = $payment_method->payment_type->value;

      if ($payment_type == 'single') {
        $success = $this->payPal->executeSinglePayment($payment_method->getRemoteId());
      }
      elseif ($payment_type == 'subscription') {
        $success = $this->payPal->executeSubscriptionPayment($payment_method->getRemoteId());
      }

      // Save payment internally.
      if (!empty($success)) {
        $payment->setState('completed');
        $payment->setAuthorizedTime($this->time->getRequestTime());
        $payment->setExpiresTime($this->time->getRequestTime() + (86400 * 29));
        $payment->save();
      }

    } catch (\Exception $exception) {
      $this->logger->error($exception->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // Delete the local entity.
    $payment_method->delete();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    // TODO: Implement voidPayment() method.
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    // TODO: Implement refundPayment() method.
  }

  /**
   * {@inheritdoc}
   */
  public function updatePaymentMethod(PaymentMethodInterface $payment_method) {
    // TODO: Implement updatePaymentMethod() method.
  }

}
