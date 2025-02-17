<?php

namespace Opencart\Catalog\Controller\Extension\SimPay\Payment;

class SimPayWebhook extends \Opencart\System\Engine\Controller
{
	public function index()
	{
		$this->load->model('setting/setting');
		$this->load->model('extension/simpay/payment/simpay');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			$this->ipn_error('Method not allowed');
		}

		$payload = json_decode(@file_get_contents('php://input'), true);
		if (empty($payload)) {
			$this->ipn_error('cannot read payload');
		}

		if (empty($payload['id']) ||
			empty($payload['service_id']) ||
			empty($payload['status']) ||
			empty($payload['amount']['value']) ||
			empty($payload['amount']['currency']) ||
			empty($payload['control']) ||
			empty($payload['channel']) ||
			empty($payload['environment']) ||
			empty($payload['signature'])
		) {
			$this->ipn_error('invalid payload');
		}

		$signature = $this->calculate_signature($payload, $this->config->get('payment_simpay_service_hash'));
		if (!hash_equals($signature, $payload['signature'])) {
			$this->ipn_error('invalid signature');
		}

		if ($payload['service_id'] !== $this->config->get('payment_simpay_service_id')) {
			$this->ipn_error('invalid service_id');
		}

		if ($payload['status'] !== 'transaction_paid') {
			header('Content-Type: text/plain', true, 200);
			echo 'OK';
			die();
		}

		$order_id = (int)$payload['control'];
		$this->load->model('checkout/order');

		$order_info = $this->model_checkout_order->getOrder($order_id);

		if (!$order_info) {
			$this->ipn_error('order not found');
		}

		$price = (float)$order_info['total'];
		$priceSim = $payload['amount']['value'];

		if ($order_info['currency_code'] !== 'PLN') {
			if (empty($payload['originalAmount']['currency']) || empty($payload['originalAmount']['value'])) {
				$this->ipn_error('originalAmount currency or value is missing');
			}

			$priceSim = (float)$payload['originalAmount']['value'];

			if ($order_info['currency_code'] !== $payload['originalAmount']['currency']) {
				$this->ipn_error('originalAmount currency mismatch');
			}
		}

		if ($this->floatLessThan($price, $priceSim)) {
			$this->ipn_error('paid price is smaller than order price: ' . $price . ';' . $priceSim);
		}

		$comment = sprintf('SimPay ID: %s' , $payload['id']);
		$this->model_checkout_order->addHistory($order_id, $this->config->get('payment_simpay_approved_status_id'), $comment);
		http_response_code(200);
		exit('OK');
	}

	private function ipn_error(string $message)
	{
		if (!headers_sent()) {
			http_response_code(400);
		}

		echo $message;
		die();
	}

	private function calculate_signature($payload, $serviceHash)
	{
		unset($payload['signature']);

		$data = $this->ipn_flatten_array($payload);
		$data[] = $serviceHash;

		return hash('sha256', implode('|', $data));
	}

	private function ipn_flatten_array(array $array): array
	{
		$return = array();

		array_walk_recursive($array, function ($a) use (&$return) {
			$return[] = $a;
		});

		return $return;
	}

	private function floatLessThan(float $a, float $b, float $epsilon = 0.00001): bool
	{
		// $a < $b
		return ($b - $a) > $epsilon;
	}
}
