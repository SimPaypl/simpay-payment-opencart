<?php

namespace Opencart\Catalog\Controller\Extension\SimPay\Payment;

use SimPay\SDK\SimPay as SimPaySDK;
use SimPay\SDK\AmountVerifier;
use SimPay\SDK\Exception\IpnException;
use SimPay\SDK\Exception\IpNotAllowedException;
use SimPay\SDK\IpnPayload;

class SimPayWebhook extends \Opencart\System\Engine\Controller
{
	private const MAX_TRANSACTION_ID_LENGTH = 64;

	public function __construct(\Opencart\System\Engine\Registry $registry)
	{
		parent::__construct($registry);
		require_once DIR_EXTENSION . 'simpay/vendor/autoload.php';
	}

	public function index(): void
	{
		if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
			$this->respond(405, 'Method not allowed');
		}

		$payload = json_decode(file_get_contents('php://input'), true);
		if (empty($payload) || !is_array($payload)) {
			$this->respond(400, 'Cannot read payload');
		}

		$userAgent = $this->request->server['HTTP_USER_AGENT'] ?? null;
		$remoteIp = $this->request->server['REMOTE_ADDR'] ?? null;

		try {
			$simpay = new SimPaySDK(
				(string)$this->config->get('payment_simpay_bearer'),
				(string)$this->config->get('payment_simpay_service_id'),
				(string)$this->config->get('payment_simpay_service_hash'),
				'opencart',
				defined('VERSION') ? VERSION : '4.x'
			);

			$ipn = $simpay->handleIpn($payload, $userAgent, $remoteIp);
		} catch (IpNotAllowedException $e) {
			$this->log->write('SimPay IPN IP Error: ' . $e->getMessage());
			$this->respond(403, $e->getMessage());
		} catch (IpnException $e) {
			$this->log->write('SimPay IPN Error: ' . $e->getMessage());
			$this->respond(400, $e->getMessage());
		}

		if (!$ipn->isTransactionEvent() || !$ipn->isPaid()) {
			$this->respond(200, 'OK');
		}

		$transactionId = $ipn->getTransactionId();
		if ($transactionId === '' || strlen($transactionId) > self::MAX_TRANSACTION_ID_LENGTH) {
			$this->respond(400, 'Invalid transaction id');
		}

		$order_id = (int)$ipn->getControl();
		$this->load->model('checkout/order');
		$order_info = $this->model_checkout_order->getOrder($order_id);

		if (!$order_info) {
			$this->respond(400, 'Order not found');
		}

		$this->verifyAmount($ipn, $order_info);

		$this->load->model('extension/simpay/payment/simpay');
		$model = $this->model_extension_simpay_payment_simpay;
		$model->createTable();

		// One handler per order at a time: covers parallel deliveries of the same IPN
		// and different transactions paying the same order.
		if (!$model->lockOrder($order_id)) {
			$this->log->write("SimPay IPN: order $order_id is locked by another request, TX: $transactionId - asking SimPay to retry");
			$this->respond(503, 'Busy, retry later');
		}

		try {
			[$code, $message] = $this->applyPayment($model, $order_id, $transactionId);
		} catch (\Throwable $e) {
			// transaction stays "processing" - SimPay retries and the handler resumes safely
			$this->log->write("SimPay IPN Error for order $order_id, TX $transactionId: " . $e->getMessage());
			[$code, $message] = [500, 'Internal error, retry later'];
		} finally {
			$model->unlockOrder($order_id);
		}

		$this->respond($code, $message);
	}

	/**
	 * Applies a paid transaction to the order exactly once. Must run under lockOrder().
	 *
	 * @param object $model extension/simpay/payment/simpay model (OpenCart passes models as a Proxy)
	 * @return array{0: int, 1: string} HTTP status and body
	 */
	private function applyPayment(object $model, int $order_id, string $transactionId): array
	{
		$transaction = $model->getTransaction($transactionId);

		if ($transaction && (int)$transaction['order_id'] !== $order_id) {
			$this->log->write("SimPay IPN: TX $transactionId belongs to order {$transaction['order_id']}, not $order_id - rejected");
			return [400, 'Transaction belongs to another order'];
		}

		if ($transaction && $model->isTransactionFinished($transaction)) {
			$this->log->write("SimPay IPN: TX $transactionId for order $order_id already processed ({$transaction['status']}) - ignored");
			return [200, 'OK'];
		}

		// Recorded before any effect; marked finished only after all effects are applied.
		$model->startTransaction($transactionId, $order_id);

		$paidComment = sprintf('SimPay ID: %s', $transactionId);

		// Effects already applied by a request that died before finishing, or by a module version without the record.
		if ($model->hasHistoryComment($order_id, $paidComment)) {
			$model->markTransactionPaid($transactionId);
			$this->log->write("SimPay IPN: TX $transactionId for order $order_id was already applied - recorded, order not changed");
			return [200, 'OK'];
		}

		// Fresh read under the lock - the status may have changed since the request started.
		$order_info = $this->model_checkout_order->getOrder($order_id);
		$currentStatusId = (int)$order_info['order_status_id'];
		$otherPaid = $model->getOtherPaidTransaction($order_id, $transactionId);
		$approvedStatusId = (int)$this->config->get('payment_simpay_approved_status_id');

		// Retry of this transaction after a crash inside addHistory(): the status was already set, the comment not.
		// Finish it without notifying the customer again.
		if ($transaction && !$otherPaid && $currentStatusId === $approvedStatusId) {
			$this->model_checkout_order->addHistory($order_id, $currentStatusId, $paidComment, false);
			$model->markTransactionPaid($transactionId);
			$this->log->write("SimPay IPN: TX $transactionId for order $order_id resumed after an interrupted attempt - recorded without notification");
			return [200, 'OK'];
		}

		// This module leaves the order without a status (0) until it is paid; the store's default
		// new-order status is accepted as unpaid as well. Anything else means the order was already paid
		// or processed further (shipped, completed, cancelled...) and must not be moved back.
		$isUnpaid = $currentStatusId === 0 || $currentStatusId === (int)$this->config->get('config_order_status_id');

		if ($otherPaid || !$isUnpaid) {
			$note = sprintf(
				'SimPay: additional paid transaction %s received for an order that is already paid or processed%s. The order was not processed again - verify the payment in the SimPay panel and refund it if needed.',
				$transactionId,
				$otherPaid ? ' (by SimPay ID: ' . $otherPaid['transaction_id'] . ')' : ''
			);

			if (!$model->hasHistoryComment($order_id, $note)) {
				// keeps the current status, no customer notification
				$this->model_checkout_order->addHistory($order_id, $currentStatusId, $note, false);
			}

			$model->markTransactionDuplicate($transactionId);
			$this->log->write("SimPay IPN Warning: order $order_id already paid/processed (status $currentStatusId), additional TX $transactionId recorded as duplicate");

			return [200, 'OK'];
		}

		$this->model_checkout_order->addHistory($order_id, $approvedStatusId, $paidComment, true);
		$model->markTransactionPaid($transactionId);

		$this->log->write("SimPay IPN processed successfully for order ID: $order_id, TX: $transactionId");

		return [200, 'OK'];
	}

	private function verifyAmount(IpnPayload $ipn, array $order_info): void
	{
		$orderTotal = (float)$order_info['total'];
		$paidAmount = $ipn->getAmount();

		if ($order_info['currency_code'] !== 'PLN') {
			if ($ipn->getOriginalCurrency() === null || $ipn->getOriginalAmount() === null) {
				$this->respond(400, 'originalAmount currency or value is missing');
			}

			if ($order_info['currency_code'] !== $ipn->getOriginalCurrency()) {
				$this->respond(400, 'originalAmount currency mismatch');
			}
		}

		if (!AmountVerifier::isAmountSufficient($orderTotal, $paidAmount)) {
			$err = 'Paid price is smaller than order price: ' . $orderTotal . ';' . $paidAmount;
			$this->log->write('SimPay IPN Warning: ' . $err);
			$this->respond(400, $err);
		}
	}

	private function respond(int $code, string $message): never
	{
		if (!headers_sent()) {
			http_response_code($code);
		}
		echo $message;
		exit;
	}
}
