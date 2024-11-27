<?php

namespace Drupal\apirone_payments_module\Plugin\Commerce\PaymentGateway;

use Apirone\API\Exceptions\ValidationFailedException;
use Apirone\SDK\Invoice;
use Apirone\SDK\Model\Settings;
use Apirone\SDK\Service\InvoiceDb;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Annotation\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Apirone Payment Gateway.
 *
 * @CommercePaymentGateway(
 *   id = "apirone_payment_gateway",
 *   label = "Apirone Payment Gateway",
 *   display_label = "Apirone Crypto Payment",
 *   forms = {
 *     "offsite-payment" = "Drupal\apirone_payments_module\PluginForm\ApironeCustomPaymentForm",
 *   },
 * )
 */
class ApironePaymentGateway extends OffsitePaymentGatewayBase {
  private ?Settings $settings;

  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    try {
      $this->initializeLogger();
    } catch (\Exception $e) {
      \Drupal::logger('apirone_payments_module')->error('Initialization error: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * @return string
   */
  public static function getInvoiceTablePrefix(): string
  {
    return Database::getConnection()->getPrefix();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['merchant'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#default_value' => $this->configuration['merchant'],
      '#required' => TRUE,
    ];

    $form['payment_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Payment Timeout (minutes)'),
      '#default_value' => $this->configuration['payment_timeout'] ?? 120,
      '#min' => 1,
    ];

    $form['processing_free_plan'] = [
      '#type' => 'select',
      '#title' => $this->t('Processing Fee Plan'),
      '#options' => [
        'fixed' => $this->t('Fixed Fee'),
        'percentage' => $this->t('Percentage'),
      ],
      '#default_value' => $this->configuration['processing_free_plan'] ?? 'fee',
      '#required' => TRUE,
    ];

    $form['price_adjustment_factor'] = [
      '#type' => 'number',
      '#title' => $this->t('Price Adjustment Factor'),
      '#default_value' => $this->configuration['price_adjustment_factor'] ?? 0.01,
      '#step' => 0.01,
    ];

    $form['apirone_logo'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display Apirone Logo'),
      '#default_value' => $this->configuration['apirone_logo'],
    ];

    $form['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Debug Mode'),
      '#default_value' => $this->configuration['debug_mode'],
    ];

    if($this->configuration['settings']) {

      $settings = Settings::fromJson($this->configuration['settings']);

      $currencies = $settings->getCurrencies();

      $currency_options = [];

      foreach ($currencies as $currency) {
        $currency_options[$currency->getAbbr()] = $currency->getName();
      }

      foreach ($currency_options as $currency_abbr => $currency_name) {
        if (in_array($currency_abbr, ['usdt@trx', 'usdc@trx'])) {
          $form['currencies'][$currency_abbr] = [
            '#type' => 'checkbox',
            '#title' => $this->t('@currency_name', ['@currency_name' => $currency_name]),
            '#default_value' => !empty($this->configuration['currencies'][$currency_abbr]),
          ];
        } else {
          $form['currencies'][$currency_abbr] = [
            '#type' => 'textfield',
            '#title' => $this->t('@currency_name Address', ['@currency_name' => $currency_name]),
            '#default_value' => $this->configuration['currencies'][$currency_abbr] ?? '',
          ];
        }
      }
    }

    $form['settings'] = [
      '#type' => 'hidden',
      '#value' => $this->configuration['settings'] ?? '',
      '#access' => \Drupal::currentUser()->hasPermission('administer site configuration'),
    ];

    return $form;
  }

  /**
   * @throws \ReflectionException
   * @throws ValidationFailedException
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue($form['#parents']);

    $this->configuration['display_label'] = $values['display_label'];
    $this->configuration['merchant'] = $values['merchant'];
    $this->configuration['payment_timeout'] = $values['payment_timeout'];
    $this->configuration['test_currency_customer'] = $values['test_currency_customer'];
    $this->configuration['processing_free_plan'] = $values['processing_free_plan'];
    $this->configuration['price_adjustment_factor'] = $values['price_adjustment_factor'];
    $this->configuration['apirone_logo'] = $values['apirone_logo'];
    $this->configuration['debug_mode'] = $values['debug_mode'];

    if(!$values['settings']) {
      $this->settings = Settings::init();
      $this->settings->createAccount();

      Invoice::db($this->db_callback(), self::getInvoiceTablePrefix());
      InvoiceDb::install(self::getInvoiceTablePrefix());
    } else {
      $this->settings = Settings::fromJSON($values['settings']);
    }

    $this->settings->setMerchant($values['merchant']);
    $this->settings->setMerchantUrl(\Drupal::request()->getHost());
    $this->settings->setTimeout((int)$values['payment_timeout']);
    $this->settings->setLogo($values['apirone_logo']);
    $this->settings->setDebug($values['debug_mode']);
    $this->settings->setFactor((float) $values['price_adjustment_factor']);

    $currencies = $this->configuration['currencies'];

    if($values['currencies']) {
      foreach ($values['currencies'] as $currency_abbr => $currency_address) {
        $currencies[$currency_abbr] = $currency_address;
      }
    }

    foreach ($currencies as $currency => $address) {
      $currency = $this->settings->getCurrency($currency);
      $currency->setPolicy($values['processing_free_plan']);

      if($address) {
        $currency->setAddress($address);
      }
    }

    $this->settings->saveCurrencies();

    $this->configuration['settings'] = json_encode($this->settings->toArray());
  }

  /**
   * @param Request $request
   * @return void
   * @throws EntityStorageException
   * @throws ValidationFailedException
   * @throws \ReflectionException
   */
  public function onNotify(Request $request): void
  {
    Invoice::callbackHandler(/**
     * @throws EntityStorageException
     */ function ($invoice) {
      //get payment by invoice id
      $payment = Payment::load($invoice->invoice);

      if ($invoice->status === 'paid') {
        $payment->setState('paid');
      }
      else {
        $payment->setState('failed');
      }

      $payment->save();
    });
  }

  /**
   * @return \Closure
   */
  public static function db_callback()
  {
    return static function($query) {
      $connection = Database::getConnection();

      if (preg_match('/^select/i', trim($query))) {
        $result = $connection->query($query)->fetchAllAssoc('id');
      }
      else {
        $result = (bool) $connection->query($query);
      }

      return $result;
    };
  }

  public function initializeLogger(): void
  {
    $logger = static function ($level, $message, $context) {
      $message = sprintf('[%s] %s', strtoupper($level), $message);

      if (!empty($context)) {
        $message .= PHP_EOL . json_encode($context, JSON_PRETTY_PRINT);
      }

      \Drupal::logger('apirone_payments_module')->info($message);
    };

    Invoice::setLogger($logger);
  }

  /**
   * Returns the path to the module.
   *
   * @return string
   *   The module path.
   */
  protected function getModulePath(): string
  {
    return \Drupal::service('extension.list.module')->getPath('apirone_payments_module');
  }
}
