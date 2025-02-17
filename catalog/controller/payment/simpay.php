<?php

namespace Opencart\Catalog\Controller\Extension\SimPay\Payment;

class SimPay extends \Opencart\System\Engine\Controller
{
    public function index(): string
    {
        $this->load->language('extension/simpay/payment/simpay');

        $data['action'] = $this->url->link('extension/simpay/payment/simpay|checkout', '', true);
        $data['message'] = $this->language->get('simpay_pay');

        return $this->load->view('extension/simpay/payment/simpay', $data);
    }

    public function checkout(): void
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/simpay/payment/simpay');

        $order_id = $this->session->data['order_id'];

        if (empty($order_id)) {
            $this->response->redirect($this->url->link('checkout/cart', '', true));
        }

        $order_info = $this->model_checkout_order->getOrder($order_id);
        $payload = array(
            'amount' => (float)$order_info['total'],
            'currency' => $order_info['currency_code'],
            'description' => 'Zamówienie #' . $order_info['order_id'] . ' - ' . $order_info['store_name'],
            'control' => (string)$order_info['order_id'],
            'customer' => array(
                'name' => substr($order_info['firstname'] . ' ' . $order_info['lastname'], 0, 64),
                'email' => $order_info['email'],
            ),
            'antifraud' => array(
                'systemId' => !empty($order_info['customer_id']) ? $order_info['customer_id'] : null,
            ),
            'returns' => array(
                'success' => $this->url->link('checkout/success'),
                'failure' => $this->url->link('checkout/failure'),
            ),
        );

        $bearer = $this->config->get('payment_simpay_bearer');
        $serviceId = $this->config->get('payment_simpay_service_id');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf('https://api.simpay.pl/payment/%s/transactions', $serviceId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $bearer,
            'Content-Type: application/json',
            'Accept: application/json',
            'X-SIM-PLATFORM: opencart',
            'X-SIM-PLATFORM-VERSION: ' . VERSION,
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            echo '<h3 style="color:red;">Wystąpił błąd podczas generowania płatności [SIMPAY001].</h3><h4>' . curl_error($ch) . ' ' . $response . '</p>';
            die();
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ((int)$httpCode < 200 || $httpCode >= 300) {
            echo '<h3 style="color:red;">Wystąpił błąd podczas generowania płatności [SIMPAY002].</h3><h4>' . $httpCode . ' ' . $response . '</p>';
            die();
        }

        $json = json_decode($response, true);
        curl_close($ch);

        $this->response->redirect($json['data']['redirectUrl']);
    }
}
