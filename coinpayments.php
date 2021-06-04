<?php

/**
 * @table paysystems
 * @id coinpayments
 * @title CoinPayments
 * @visible_link http://www.coinpayments.net/
 * @recurring paysystem
 * @img https://www.coinpayments.net/images/home3/Logo_with_slogan.svg
 * @country CA
 */
class Am_Paysystem_Coinpayments extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '6.2.10';

    protected $defaultTitle = 'CoinPayments';
    protected $defaultDescription = 'Cryptocurrency Payments';


    public function __construct(Am_Di $di, array $config, $id = false)
    {
        parent::__construct($di, $config, $id);

        $coinApiFile = realpath(dirname(__FILE__) . '/coinpayments/CoinpaymentsApi.php');
        if (file_exists($coinApiFile)) {
            require_once $coinApiFile;
        }

    }


    public function createWebhooks(Am_Config $before, Am_Config $after)
    {

        $client_id_key = "{$this->_configPrefix}{$this->getId()}.client_id";
        $webhooks_key = "{$this->_configPrefix}{$this->getId()}.webhooks";
        $client_secret_key = "{$this->_configPrefix}{$this->getId()}.client_secret";

        $api = new CoinpaymentsApi();

        $client_id = $after->get($client_id_key);
        $webhooks = $after->get($webhooks_key);
        $client_secret = $after->get($client_secret_key);

        if ($webhooks) {
            $webhooks_list = $api->getWebhooksList($client_id, $client_secret);
            if (!empty($webhooks_list)) {
                $webhooks_urls_list = array();
                if (!empty($webhooks_list['items'])) {
                    $webhooks_urls_list = array_map(function ($webHook) {
                        return $webHook['notificationsUrl'];
                    }, $webhooks_list['items']);
                }
                $callback_url = $this->getPluginUrl(CoinpaymentsApi::WEBHOOK_NOTIFICATION_URL);
                if (!in_array($api->getNotificationUrl($callback_url, $client_id, CoinpaymentsApi::CANCELLED_EVENT), $webhooks_urls_list)) {
                    $api->createWebHook($client_id, $client_secret, $api->getNotificationUrl($callback_url, $client_id, CoinpaymentsApi::CANCELLED_EVENT), CoinpaymentsApi::CANCELLED_EVENT);
                }
                if (!in_array($api->getNotificationUrl($callback_url, $client_id, CoinpaymentsApi::PAID_EVENT), $webhooks_urls_list)) {
                    $api->createWebHook($client_id, $client_secret, $api->getNotificationUrl($callback_url, $client_id, CoinpaymentsApi::PAID_EVENT), CoinpaymentsApi::PAID_EVENT);
                }
            }
        }

    }

    public function _initSetupForm(Am_Form_Setup $form)
    {

        $form->addText("client_id")->setLabel(array(
            'Client ID',
            'The Client ID of your CoinPayments.net account.'
        ));

        $form->addSelect('webhooks', array(), array('options' =>
            array(
                '1' => 'Yes',
                '0' => 'No',
            )))
            ->setLabel(array(
                'Webhooks',
                'Enable CoinPayments.net gateway webhooks.'
            ));

        $form->addText("client_secret")->setLabel(array(
            'Client Secret',
            'Client Secret of your CoinPayments.net account.'
        ));

        $form->addSaveCallbacks([$this, 'createWebhooks'], null);
    }

    public function getSupportedCurrencies()
    {
        return array('USD', 'GBP', 'EUR', 'CAD', 'JPY', 'BTC');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        if (!$this->getConfig('client_id'))
            throw new Am_Exception_Configuration("There is a configuration error in [coinpayments] plugin - no Client ID configured");

        $api = new CoinpaymentsApi();

        $coin_invoice = null;

        $u = $invoice->getUser();
        $invoice_id = sprintf('%s|%s', md5($this->getDi()->request->getHttpHost()), $invoice->public_id);
        $coin_currency = $api->getCoinCurrency($invoice->currency);
        $amount = number_format($invoice->first_total, $coin_currency['decimalPlaces'], '', '');
        $display_value = $invoice->first_total;

        $invoice_url = $this->getDi()->url("admin-user-payments/index/user_id/{$invoice->user_id}#invoice-{$invoice->pk()}", null, false, true);

        $invoice_params = array(
            'invoice_id' => $invoice_id,
            'currency_id' => $coin_currency['id'],
            'amount' => $amount,
            'display_value' => $display_value,
            'billing_data' => $u->toArray(),
            'notes_link' => sprintf(
                "%s|Store name: %s|Order #%s",
                $invoice_url,
                $this->getDi()->config->get('site_title'),
                $invoice->public_id),
        );

        if ($this->config['webhooks']) {
            $coin_invoices = $api->createMerchantInvoice($this->config['client_id'], $this->config['client_secret'], $invoice_params);
            $coin_invoice = array_shift($coin_invoices['invoices']);
        } else {
            $coin_invoice = $api->createSimpleInvoice($this->config['client_id'], $invoice_params);
        }

        $redirect_url = sprintf('%s/%s/', CoinpaymentsApi::CHECKOUT_URL, CoinpaymentsApi::API_CHECKOUT_ACTION);
        $a = new Am_Paysystem_Action_Redirect($redirect_url);
        $a->setParams([
            'invoice-id' => $coin_invoice['id'],
            'success-url' => $this->getReturnUrl(),
            'cancel-url' => $this->getCancelUrl(),
        ]);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Coinpayments($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Coinpayments_Thanks($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function getReadme()
    {
        return <<<CUT
Accept Bitcoin, Litecoin, Dogecoin, and other cryptocurrencies with CoinPayments.net


If you haven't already created a CoinPayments account, you can sign up at <a href="https://www.coinpayments.net/index.php?cmd=register&ref=606a89bb575311badf510a4a8b79a45e">https://www.coinpayments.net/</a>.
CUT;

    }
}

class Am_Paysystem_Transaction_Coinpayments extends Am_Paysystem_Transaction_Incoming
{

    /**
     * @var false|string
     */
    protected $signature;
    /**
     * @var false|string
     */
    protected $content;
    /**
     * @var mixed
     */
    protected $request_data;
    /**
     * @var mixed|null
     */
    protected $invoice_id;
    /**
     * @var mixed
     */
    protected $status;
    /**
     * @var mixed|null
     */
    protected $host_hash;

    public function __construct(/*Am_Paysystem_Abstract*/ $plugin, /*Am_Mvc_Request*/ $request, /*Am_Mvc_Response*/ $response, $invokeArgs)
    {
        $this->request = $request;
        $this->response = $response;
        $this->invokeArgs = $invokeArgs;

        $this->signature = $this->request->getHeader('X-CoinPayments-Signature');
        $this->content = file_get_contents('php://input');
        $this->request_data = json_decode($this->content, true);


        $invoice_str = $this->request_data['invoice']['invoiceId'];
        $invoice_str = explode('|', $invoice_str);
        $this->host_hash = array_shift($invoice_str);
        $this->invoice_id = array_shift($invoice_str);

        $this->status = $this->request_data['invoice']['status'];

        parent::__construct($plugin, $request, $response, $invokeArgs);
    }

    public function findInvoiceId()
    {
        return $this->invoice_id;
    }

    public function getUniqId()
    {
        return $this->request_data['invoice']['id'];
    }

    public function validateSource()
    {
        if ($this->getPlugin()->getConfig('webhooks')) {
            $client_id = $this->getPlugin()->getConfig('client_id');
            $client_secret = $this->getPlugin()->getConfig('client_secret');

            $api = new CoinpaymentsApi();

            $callback_url = $this->getPlugin()->getPluginUrl(CoinpaymentsApi::WEBHOOK_NOTIFICATION_URL);
            $request_url = $api->getNotificationUrl($callback_url, $client_id, $this->status);

            if ($this->checkDataSignature($this->signature, $this->content, $request_url, $client_secret)) {


                if ($this->host_hash == md5($this->request->getHttpHost()) && $this->invoice_id) {
                    return true;
                }
            }
        }

        return false;
    }

    public function validateStatus()
    {
        return ($this->status == CoinpaymentsApi::PAID_EVENT || $this->status == CoinpaymentsApi::CANCELLED_EVENT) ? true : false;
    }

    public function validateTerms()
    {
        if (strcasecmp($this->request_data["invoice"]["currency"]["symbol"], $this->invoice->currency)) {
            return false;
        }
        return $this->request_data["invoice"]["amount"]["displayValue"] >= ($this->invoice->isFirstPayment() ? $this->invoice->first_total : $this->invoice->second_total);
    }

    public function processValidated()
    {
        if ($this->status == CoinpaymentsApi::PAID_EVENT) {
            $this->invoice->addPayment($this);
        }
    }

    protected function checkDataSignature($signature, $content, $request_url, $client_secret)
    {
        $api = new CoinpaymentsApi();
        $signature_string = sprintf('%s%s', $request_url, $content);
        $encoded_pure = $api->encodeSignatureString($signature_string, $client_secret);
        return $signature == $encoded_pure;
    }
}
