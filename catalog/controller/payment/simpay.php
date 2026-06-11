<?php

namespace Opencart\Catalog\Controller\Extension\SimPay\Payment;

use SimPay\SDK\SimPay as SimPaySDK;
use SimPay\SDK\TransactionBuilder;

class SimPay extends \Opencart\System\Engine\Controller
{
    public function __construct(\Opencart\System\Engine\Registry $registry)
    {
        parent::__construct($registry);
        require_once DIR_EXTENSION . 'simpay/vendor/autoload.php';
    }

    public function index(): string
    {
        $this->load->language('extension/simpay/payment/simpay');

        $data['action'] = $this->url->link('extension/simpay/payment/simpay|checkout', 'language=' . $this->config->get('config_language'), true);
        $data['message'] = $this->language->get('simpay_pay');

        return $this->load->view('extension/simpay/payment/simpay', $data);
    }

    public function checkout(): void
    {
        $this->load->model('checkout/order');

        $order_id = $this->session->data['order_id'];

        if (empty($order_id)) {
            $this->response->redirect($this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'), true));
        }

        $order_info = $this->model_checkout_order->getOrder($order_id);

        $bearer = $this->config->get('payment_simpay_bearer');
        $serviceId = $this->config->get('payment_simpay_service_id');
        $serviceHash = $this->config->get('payment_simpay_service_hash');

        $successUrl = $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true);
        $failureUrl = $this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true);

        try {
            $simpay = new SimPaySDK(
                $bearer,
                $serviceId,
                $serviceHash,
                'opencart',
                defined('VERSION') ? VERSION : '4.x'
            );

            $payload = TransactionBuilder::create()
                ->setAmount((float) $order_info['total'], $order_info['currency_code'])
                ->setDescription('Zamówienie #' . $order_info['order_id'] . ' - ' . $order_info['store_name'])
                ->setControl((string) $order_info['order_id'])
                ->setCustomer(
                    trim($order_info['firstname'] . ' ' . $order_info['lastname']),
                    $order_info['email']
                )
                ->setAntifraud(!empty($order_info['customer_id']) ? (string) $order_info['customer_id'] : null)
                ->setReturnUrls($successUrl, $failureUrl)
                ->toArray();

            $response = $simpay->client()->createTransaction($payload);

            if (isset($response['data']['redirectUrl'])) {
                $this->response->redirect($response['data']['redirectUrl']);
            } else {
                throw new \Exception('SimPay missing redirectUrl in response');
            }
        } catch (\Exception $e) {
            $this->log->write('SimPay Error: ' . $e->getMessage());
            $this->response->redirect($this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true));
        }
    }
}
