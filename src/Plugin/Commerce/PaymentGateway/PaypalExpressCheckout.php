<?php

namespace Drupal\commerce_paypal_ec\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_paypal_ec\PayPalInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
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
class PaypalExpressCheckout extends OnsitePaymentGatewayBase {

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
        'recurring_start_date' => 0,
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

    $form['recurring_start_date'] = [
      '#type' => 'select',
      '#title' => t('Select when to start billing recurring payments'),
      '#description' => t('Choose "0" to start billing as soon as payment is made, otherwise choose day of the month when the first billing should be made.'),
      '#options' => range(0, 31),
      '#default_value' => $this->configuration['recurring_start_date'],
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
      $this->configuration['recurring_start_date'] = $values['recurring_start_date'];
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

    if ($payment_details['type'] == 'single' && !empty($payment_details['transactions']) && count($payment_details['transactions']) > 1) {
      throw new \InvalidArgumentException(sprintf('We do not support more than 1 transaction for security reasons.'));
    }

    if ($payment_details['type'] == 'subscription' && !empty($payment_details['payment_definitions']) && count($payment_details['payment_definitions']) > 1) {
      throw new \InvalidArgumentException(sprintf('We do not support more than 1 payment definition for security reasons.'));
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

    // Get created payment method.
    $payment_method = $payment->getPaymentMethod();

    // Get payment type (single or subscription) and payment payload
    // sent from the frontend.
    $payment_type = $payment_method->payment_type->value;
    $payment_details = $payment_method->payment_details;

    // Grab the order details.
    $order = $payment->getOrder();

    // Fill some of the values for single payment payload from the order.
    if ($payment_type == 'single') {

      // Pre-populate array with default values if they were not set by
      // the frontend.
      $payment_details += [
        'intent' => 'sale',
        'payer' => [
          'payment_method' => 'paypal',
        ],
      ];

      $payment_details['redirect_urls']['return_url'] = \Drupal::request()
        ->getSchemeAndHttpHost();
      $payment_details['redirect_urls']['cancel_url'] = \Drupal::request()
        ->getSchemeAndHttpHost();

      $payment_details['transactions'][0]['amount'] = [
        'currency' => $order->getTotalPrice()->getCurrencyCode(),
        'total' => $order->getTotalPrice()->getNumber(),
      ];

      // Don't let frontend define items - it should be added from
      // the order.
      unset($payment_details['transactions'][0]['item_list']['items']);
      foreach ($order->getItems() as $orderItem) {
        $payment_details['transactions'][0]['item_list']['items'][] = [
          'name' => $orderItem->getTitle(),
          'currency' => $orderItem->getTotalPrice()->getCurrencyCode(),
          'price' => $orderItem->getTotalPrice()->getNumber(),
          'quantity' => (int) $orderItem->getQuantity(),
        ];
      }
    }
    // Fill some of the values for subscription payment payload from the order.
    elseif ($payment_type == 'subscription') {

      // Prefill merchant preferences with old school url values.
      $payment_details['billing_plan']['merchant_preferences']['return_url'] = \Drupal::request()
        ->getSchemeAndHttpHost();
      $payment_details['billing_plan']['merchant_preferences']['cancel_url'] = \Drupal::request()
        ->getSchemeAndHttpHost();

      // Hard set payment amount from the order.
      $payment_details['billing_plan']['payment_definitions'][0]['amount'] = [
        'value' => $order->getTotalPrice()->getNumber(),
        'currency' => $order->getTotalPrice()->getCurrencyCode(),
      ];

      $recurring_start_date = $this->configuration['recurring_start_date'];

      // If recurring date is empty, means that we should start billing
      // recurring payment as soon as possible - in PayPal terms it's at least +24 hrs.
      if (empty($recurring_start_date)) {
        $datetime = new \DateTime();
        $datetime->modify('+25 hours');
      }
      else {
        $datetime = new \DateTime();
        $day_of_month = $datetime->format('j');
        // If the current day of the month is leaser than day when to start
        // billing, then we need to set the payment for this month, otherwise
        // it will be the next month.
        if ($day_of_month >= $recurring_start_date) {
          $datetime->modify('+1 month');
        }
        
        // This is needed to prevent cases like adding 1 month to 31rd of
        // October results in 1st of December instead of 30th of November.
        // See https://stackoverflow.com/questions/5760262/php-adding-months-to-a-date-while-not-exceeding-the-last-day-of-the-month
        // for example of the issue.
        $end_day_of_month = $datetime->format('j');
        if ($day_of_month != $end_day_of_month) {
          // The day of the month isn't the same anymore, so we correct the date.
          $datetime->modify('last day of last month');
        }
        
        // Build the date of recurring payment to start.
        $date = $datetime->format('Y') . '-' . $datetime->format('m') . '-' . $recurring_start_date;

        // Due to details of PayPal dates conversion (see
        // https://developer.paypal.com/docs/api/payments.billing-agreements/v1/)
        // we need to set the mid of the day to start the payment when expected.
        $datetime = new \DateTime($date);
        $datetime->modify('+12 hours');
      }

      // Set the date of the first recurring payment.
      $payment_details['billing_agreement']['start_date'] = $datetime->format(\DateTime::ATOM);
    }

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
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $payment_method = $payment->getPaymentMethod();
    $payment_type = $payment_method->payment_type->value;

    if ($payment_type == 'single') {
      $remote_id = $this->payPal->executeSinglePayment($payment_method->getRemoteId());
    }
    elseif ($payment_type == 'subscription') {
      $remote_id = $this->payPal->executeSubscriptionPayment($payment_method->getRemoteId());
    }

    // Save payment internally.
    if (!empty($remote_id)) {
      $payment->setState('completed');
      $payment->setAuthorizedTime($this->time->getRequestTime());
      $payment->setExpiresTime($this->time->getRequestTime() + (86400 * 29));
      $payment->setRemoteId($remote_id);

      $payment->save();

      // Remote id may have changed during payment capturing so we save
      // the most recent value again.
      $payment_method->setRemoteId($remote_id);
      $payment_method->save();
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
