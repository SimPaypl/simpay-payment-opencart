<?php

namespace Opencart\Catalog\Controller\Extension\SimPay\Payment;

use SimPay\SDK\SimPay as SimPaySDK;
use SimPay\SDK\AmountVerifier;
use SimPay\SDK\Exception\IpnException;
use SimPay\SDK\Exception\IpNotAllowedException;

class SimPayWebhook extends \Opencart\System\Engine\Controller
{
	public function __construct(\Opencart\System\Engine\Registry $registry)
	{
		parent::__construct($registry);
		require_once DIR_EXTENSION . 'simpay/vendor/autoload.php';
	}

	public function index(): void
	{
		if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
			$this->ipn_error('Method not allowed');
		}

		$payload = json_decode(file_get_contents('php://input'), true);
		if (empty($payload)) {
			$this->ipn_error('Cannot read payload');
		}

        $serviceId = $this->config->get('payment_simpay_service_id');
        $serviceHash = $this->config->get('payment_simpay_service_hash');
        $bearer = $this->config->get('payment_simpay_bearer');

        $userAgent = $this->request->server['HTTP_USER_AGENT'] ?? null;
        $remoteIp = $this->request->server['REMOTE_ADDR'] ?? null;

        try {
            $simpay = new SimPaySDK(
                $bearer,
                $serviceId,
                $serviceHash,
                'opencart',
                defined('VERSION') ? VERSION : '4.x'
            );

            $ipn = $simpay->handleIpn($payload, $userAgent, $remoteIp);
        } catch (IpNotAllowedException $e) {
            $this->log->write('IPN IP Error: ' . $e->getMessage());
            $this->ipn_error($e->getMessage());
        } catch (IpnException $e) {
            $this->log->write('IPN Error: ' . $e->getMessage());
            $this->ipn_error($e->getMessage());
        }

		if (!$ipn->isPaid()) {
			http_response_code(200);
			exit('OK');
		}

		$order_id = (int) $ipn->getControl();
		$this->load->model('checkout/order');
		$order_info = $this->model_checkout_order->getOrder($order_id);

		if (!$order_info) {
			$this->ipn_error('Order not found');
		}

		$orderTotal = (float) $order_info['total'];
		$paidAmount = $ipn->getEffectiveAmount($order_info['currency_code']);

		if ($order_info['currency_code'] !== 'PLN') {
			if ($ipn->getOriginalCurrency() === null || $ipn->getOriginalAmount() === null) {
				$this->ipn_error('originalAmount currency or value is missing');
			}

			if ($order_info['currency_code'] !== $ipn->getOriginalCurrency()) {
				$this->ipn_error('originalAmount currency mismatch');
			}
		}

		if (!AmountVerifier::isAmountSufficient($orderTotal, $paidAmount)) {
            $err = 'Paid price is smaller than order price: ' . $orderTotal . ';' . $paidAmount;
            $this->log->write('IPN Warning: ' . $err);
			$this->ipn_error($err);
		}

		$comment = sprintf('SimPay ID: %s', $ipn->getTransactionId());
		$this->model_checkout_order->addHistory($order_id, (int)$this->config->get('payment_simpay_approved_status_id'), $comment, true);

        $this->log->write("SimPay IPN processed successfully for order ID: $order_id, TX: {$ipn->getTransactionId()}");

        http_response_code(200);
		exit('OK');
	}

	private function ipn_error(string $message): void
	{
		if (!headers_sent()) {
			http_response_code(400);
		}
		echo $message;
		die();
	}
}
