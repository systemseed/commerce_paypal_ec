<?php

namespace Drupal\commerce_paypal_ec;

use Drupal\Core\Logger\LoggerChannelInterface;
use PayPal\Api\Agreement;
use PayPal\Api\Patch;
use PayPal\Api\PatchRequest;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\Plan;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Common\PayPalModel;
use PayPal\Rest\ApiContext;

class PayPal implements PayPalInterface {

  /**
   * @var \PayPal\Rest\ApiContext
   */
  protected $apiContext;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * PayPal constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Handler which logs incidents.
   */
  public function __construct(LoggerChannelInterface $logger) {
    $this->logger = $logger;
  }

  /**
   * @param $client_id
   *   PayPal REST client ID.
   *
   * @param $client_secret
   *   PayPal REST client secret string.
   *
   * @param string $mode
   *   Payment mode (live or sandbox).
   *
   * @return $this
   */
  public function initialize($client_id, $client_secret, $mode = 'sandbox') {

    $this->apiContext = new ApiContext(
      new OAuthTokenCredential($client_id, $client_secret)
    );

    $this->apiContext->setConfig([
      'mode' => $mode === 'live' ? 'live' : 'sandbox',
      'log.AdapterFactory' => '\Drupal\commerce_paypal_ec\PaypalLogFactory',
      // Use cache file to improve PayPal SDK performance.
      'cache.enabled' => TRUE,
      'cache.FileName' => 'private://paypal/paypal_sdk.auth.cache',
    ]);

    return $this;
  }

  /**
   * Creates a single payment agreement for PayPal.
   *
   * @param array $data
   *   TODO: Example.
   *
   * @return bool|string
   *   Payment token for PayPal's checkout.js library.
   */
  public function createSinglePayment(array $data) {
    $payment = new Payment();
    $payment->fromArray($data);
    $createdPayment = $payment->create($this->apiContext);
    return !empty($createdPayment) ? $createdPayment->getId() : FALSE;
  }

  /**
   * Creates a billing plan & billing agreement
   * for subscriptions in PayPal.
   *
   * @param array $data
   *   TODO: Example.
   *
   * @return string
   *   Payment token for PayPal's checkout.js library.
   */
  public function createSubscriptionPayment(array $data) {

    // Create a new billing plan in PayPal.
    $plan = new Plan();
    $plan->fromArray($data['billing_plan']);
    $plan = $plan->create($this->apiContext);

    // Active recently created billing plan.
    $patch = new Patch();
    $value = new PayPalModel('{"state":"ACTIVE"}');
    $patch->setOp('replace')
      ->setPath('/')
      ->setValue($value);
    $patchRequest = new PatchRequest();
    $patchRequest->addPatch($patch);
    $plan->update($patchRequest, $this->apiContext);

    // Create billing agreement for the client.
    $agreement = new Agreement();
    $agreement->fromArray($data['billing_agreement']);
    $newPlan = new Plan();
    $newPlan->setId($plan->getId());
    $agreement->setPlan($newPlan);
    $payer = new Payer();
    $payer->setPaymentMethod('paypal');
    $agreement->setPayer($payer);

    // The token for the checkout.js exists in the approval link. So we
    // grab it, parse the url and get token from it.
    $agreement = $agreement->create($this->apiContext);
    $approvalUrl = $agreement->getApprovalLink();
    $query = parse_url($approvalUrl, PHP_URL_QUERY);
    parse_str($query, $query);
    return !empty($query['token']) ? $query['token'] : FALSE;
  }

  /**
   * Finalizes the PayPal payment after user's
   * transaction confirmation
   *
   * @param $paymentID
   *   Payment ID returned from PayPal.
   *
   * @return string|bool
   *   PayPal payment id or FALSE depending on transaction's success.
   */
  public function executeSinglePayment($paymentID) {

    // Load payment details from PayPal.
    $paypalPayment = Payment::get($paymentID, $this->apiContext);

    // Create a new PayPal payment execution object.
    $paymentExecution = new PaymentExecution();

    // Set payer ID from the PayPal's payment details.
    $payer = $paypalPayment->getPayer();
    if (!empty($payer)) {
      $payerID = $payer->getPayerInfo()->getPayerId();
      $paymentExecution->setPayerId($payerID);
    }

    // Fetch existing PayPal payment & execute it.
    $result = $paypalPayment->execute($paymentExecution, $this->apiContext);
    return $result->getState() == 'approved' ? $result->getId() : FALSE;
  }

  /**
   * Finalizes the PayPal subscription payment after
   * user's transaction confirmation.
   *
   * @param $agreementID
   *   Subscription ID returned from PayPal
   *
   * @return string|bool
   *   PayPal payment id or FALSE depending on transaction's success.
   */
  public function executeSubscriptionPayment($agreementID) {

    // Execute a billing agreement which must have been already
    // confirmed by a client in a checkout popup.
    $agreement = new Agreement();
    $result = $agreement->execute($agreementID, $this->apiContext);
    return $result->getState() == 'Active' ? $result->getId() : FALSE;
  }

  /**
   * Returns list of supported payment types for Paypal EC.
   *
   * @return array
   */
  public function getTypeLabels() {
    return [
      'single' => t('Single Payment'),
      'subscription' => t('Recurring Payment'),
    ];
  }

}
