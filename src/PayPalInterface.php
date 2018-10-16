<?php

namespace Drupal\commerce_paypal_ec;

interface PayPalInterface {

  /**
   *
   * @return $this
   */
  public function initialize($client_id, $client_secret, $mode);

  public function createSinglePayment(array $data);

  public function createSubscriptionPayment(array $data);

  public function executeSinglePayment($paymentID);

  public function executeSubscriptionPayment($agreementID);

  public function getTypeLabels();

}
