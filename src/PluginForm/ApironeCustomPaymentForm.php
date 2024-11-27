<?php

namespace Drupal\apirone_payments_module\PluginForm;

use Apirone\API\Exceptions\ValidationFailedException;
use Apirone\SDK\Invoice;
use Apirone\SDK\Model\Settings;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Apirone\SDK\Service\Utils;
use Drupal\Core\Url;
use \Drupal\Core\Routing\TrustedRedirectResponse;

class ApironeCustomPaymentForm extends BasePaymentOffsiteForm
{
  protected Settings $settings;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $payment = $this->entity;

    $this->settings = Settings::fromJson($payment->getPaymentGateway()->getPluginConfiguration()['settings']);
    Invoice::db($payment->getPaymentGateway()->getPlugin()->db_callback(), $payment->getPaymentGateway()->getPlugin()->getInvoiceTablePrefix());
    Invoice::settings($this->settings);

    try {
      $available_currencies = $this->getAvailableCurrencies();

      if(empty($available_currencies)) {
        \Drupal::messenger()->addError(t('No available currencies.'));
        return $form;
      }

      $form_state->set('payment', $payment);

      $default_currency = 'btc';

      if (\Drupal::currentUser()->hasPermission('administer site configuration')) {
        $default_currency = 'tbtc';
      }

      $form['com_currency'] = [
        '#type' => 'select',
        '#title' => t('Select Currency'),
        '#options' => $available_currencies,
        '#default_value' => $default_currency,
        '#required' => TRUE,
        '#description' => t('Choose your preferred currency for payment.')
      ];

      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Pay with Apirone'),
        '#submit' => [[get_class($this), 'submitFormHandler']],
      ];

    } catch (ValidationFailedException $e) {
      \Drupal::logger('apirone_payments_module')->error('Error: @message', ['@message' => $e->getMessage()]);
    } catch (\ReflectionException $e) {
      \Drupal::logger('apirone_payments_module')->error('Error: @message', ['@message' => $e->getMessage()]);
    }

    return $form;
  }

  /**
   * Form submission handler.
   */
  public static function submitFormHandler(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $com_currency = $values['payment_process']['offsite_payment']['com_currency'] ?? null;

    if ($com_currency) {
      $payment = $form_state->get('payment');

      $payment_gateway = $payment->getPaymentGateway()->getPluginConfiguration();
      $crypto_currency = Settings::fromJson($payment_gateway['settings'])->getCurrency($com_currency);

      $invoice = self::createInvoice($crypto_currency, $payment, (int) $payment_gateway['payment_timeout'], $payment_gateway['price_adjustment_factor']);

      if($invoice['details']['invoice-url']) {
        $payment->setRemoteId($invoice['details']['invoice']);
        $payment->setState('pending');
        $payment->setRemoteState('pending');
        $payment->save();

        $response = new TrustedRedirectResponse($invoice['details']['invoice-url']);

        $response->send();
      }
    }
  }

  /**
   * @throws \ReflectionException
   * @throws ValidationFailedException
   */
  public static function createInvoice($currency, $order, $lifetime, $factor)
  {
    $currency_code = $order->getOrder()->getTotalPrice()->getCurrencyCode();
    $order_total_price = $order->getOrder()->getTotalPrice()->getNumber();

    $invoice = Invoice::fromFiatAmount($order_total_price, $currency_code, $currency->getAbbr(), $factor);

    $payment_gateway_id = $order->getPaymentGatewayId();
    $callback_url = Url::fromRoute('commerce_payment.notify', ['commerce_payment_gateway' => $payment_gateway_id], ['absolute' => TRUE])->toString();

    $linkBack = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();

    $invoice->callbackUrl($callback_url);
    $invoice->linkback($linkBack);
    $invoice->lifetime($lifetime);

    $invoice->create();

    return $invoice->toArray();
  }

  /**
   * @throws ValidationFailedException
   * @throws \ReflectionException
   */
  protected function getAvailableCurrencies(): array
  {
    $currencies = $this->settings->getCurrencies();

    $available_currencies = [];

    foreach ($currencies as $currency) {
      $order = $this->entity->getOrder();
      $order_total_price = $order->getTotalPrice()->getNumber();
      $order_currency = $order->getTotalPrice()->getCurrencyCode();

      $available_currencies[$currency->getAbbr()] = $currency->getName() . ' (' . $this->toCrypto($order_total_price, $order_currency, $currency) . ')';

      if (!$currency->getAddress()) {
        unset($available_currencies[$currency->getAbbr()]);
      }
    }

    return $available_currencies;
  }

  /**
   * @param $total
   * @param $drupal_currency
   * @param $currency
   * @return string
   */
  private static function toCrypto($total, $drupal_currency, $currency): string
  {
    $sum = Utils::cur2min(Utils::fiat2crypto($total, $drupal_currency, $currency->getAbbr()), $currency->getUnitsFactor());

    return Utils::humanizeAmount($sum, $currency);
  }
}
