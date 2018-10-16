<?php

namespace Drupal\commerce_paypal_ec\Plugin\Commerce\PaymentMethodType;

use Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\PaymentMethodTypeBase;
use Drupal\entity\BundleFieldDefinition;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;

/**
 * Provides the Paypal Express Checkout payment method type.
 *
 * @CommercePaymentMethodType(
 *   id = "paypal_ec",
 *   label = @Translation("Paypal Express Checkout"),
 * )
 */
class PaypalExpressCheckout extends PaymentMethodTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildLabel(PaymentMethodInterface $payment_method) {
    $args = [
      '@payment_type' => $payment_method->payment_type->value,
      '@payment_id' => $payment_method->getRemoteId(),
    ];
    return $this->t('**** @payment_type (@payment_id)', $args);
  }

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    $fields = parent::buildFieldDefinitions();

    // Store payment type (single or subscription).
    $fields['payment_type'] = BundleFieldDefinition::create('list_string')
      ->setLabel(t('Payment type'))
      ->setDescription(t('Single or recurring payment type.'))
      ->setSetting('allowed_values_function', ['\Drupal\commerce_paypal_ec\Paypal', 'getTypeLabels']);

    return $fields;
  }

}