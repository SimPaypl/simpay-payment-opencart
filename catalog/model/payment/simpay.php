<?php

namespace Opencart\Catalog\Model\Extension\SimPay\Payment;

class SimPay extends \Opencart\System\Engine\Model
{
	/** IPN received, effects possibly not applied yet - safe to retry. */
	public const TX_PROCESSING = 'processing';
	/** Payment applied to the order (status changed, customer notified). */
	public const TX_PAID = 'paid';
	/** Paid transaction for an order that was already paid / processed - recorded, order not touched. */
	public const TX_DUPLICATE = 'duplicate';

	public function getMethods(array $address = []): array
	{
		if (!$this->config->get('payment_simpay_status')) {
			return [];
		}

		// Not connected yet (e.g. "Connect with SimPay" not finished or disconnected)
		if (
			!$this->config->get('payment_simpay_bearer') ||
			!$this->config->get('payment_simpay_service_id') ||
			!$this->config->get('payment_simpay_service_hash')
		) {
			return [];
		}

		return [
			'code'       => 'simpay',
			'name'       => $this->language->get('heading_title') ?? 'SimPay',
			'terms'      => 'https://simpay.pl/uploads/download/documents/regulamin.pdf',
			'sort_order' => $this->config->get('payment_simpay_sort_order') ?? 1,
			'option'     => [
				'simpay' => [
					'code' => 'simpay.simpay',
					'name' => 'Przelew Online / BLIK',
				],
			],
		];
	}

	// ──────────────────────────────────────────────────────────────────────
	// IPN idempotency: durable record of processed SimPay transactions
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Created on install and lazily here, because updating the module by replacing files
	 * does not run install() again.
	 */
	public function createTable(): void
	{
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "simpay_transaction` (
			`transaction_id` VARCHAR(64) NOT NULL,
			`order_id` INT(11) NOT NULL,
			`status` VARCHAR(16) NOT NULL,
			`attempts` INT(11) NOT NULL DEFAULT 1,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`transaction_id`),
			KEY `order_id` (`order_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
	}

	/**
	 * Serializes IPN handling per order across PHP processes (MySQL/MariaDB named lock).
	 * The lock is bound to the DB connection, so a crashed request releases it automatically.
	 */
	public function lockOrder(int $order_id, int $timeout = 15): bool
	{
		$query = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($this->getLockName($order_id)) . "', " . (int)$timeout . ") AS `locked`");

		return isset($query->row['locked']) && (int)$query->row['locked'] === 1;
	}

	public function unlockOrder(int $order_id): void
	{
		$this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($this->getLockName($order_id)) . "')");
	}

	public function getTransaction(string $transaction_id): array
	{
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "simpay_transaction` WHERE `transaction_id` = '" . $this->db->escape($transaction_id) . "'");

		return $query->row;
	}

	/**
	 * Another transaction that already paid (or was recorded as a duplicate payment for) this order.
	 */
	public function getOtherPaidTransaction(int $order_id, string $transaction_id): array
	{
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "simpay_transaction` WHERE `order_id` = '" . (int)$order_id . "' AND `transaction_id` != '" . $this->db->escape($transaction_id) . "' AND `status` = '" . self::TX_PAID . "' LIMIT 1");

		return $query->row;
	}

	/**
	 * Records the IPN before any effect is applied. Status stays "processing" until the effects are done,
	 * so an interrupted request is retried by SimPay instead of being treated as finished.
	 */
	public function startTransaction(string $transaction_id, int $order_id): void
	{
		$this->db->query("INSERT INTO `" . DB_PREFIX . "simpay_transaction` SET `transaction_id` = '" . $this->db->escape($transaction_id) . "', `order_id` = '" . (int)$order_id . "', `status` = '" . self::TX_PROCESSING . "', `attempts` = 1, `date_added` = NOW(), `date_modified` = NOW() ON DUPLICATE KEY UPDATE `attempts` = `attempts` + 1, `date_modified` = NOW()");
	}

	public function isTransactionFinished(array $transaction): bool
	{
		return in_array($transaction['status'] ?? '', [self::TX_PAID, self::TX_DUPLICATE], true);
	}

	public function markTransactionPaid(string $transaction_id): void
	{
		$this->finishTransaction($transaction_id, self::TX_PAID);
	}

	public function markTransactionDuplicate(string $transaction_id): void
	{
		$this->finishTransaction($transaction_id, self::TX_DUPLICATE);
	}

	private function finishTransaction(string $transaction_id, string $status): void
	{
		$this->db->query("UPDATE `" . DB_PREFIX . "simpay_transaction` SET `status` = '" . $this->db->escape($status) . "', `date_modified` = NOW() WHERE `transaction_id` = '" . $this->db->escape($transaction_id) . "'");
	}

	/**
	 * Whether the order history already contains the given comment - used to detect effects applied by
	 * a request that crashed before finishTransaction(), and payments processed by module versions
	 * that did not keep the transaction record yet.
	 */
	public function hasHistoryComment(int $order_id, string $comment): bool
	{
		$query = $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "order_history` WHERE `order_id` = '" . (int)$order_id . "' AND `comment` = '" . $this->db->escape($comment) . "' LIMIT 1");

		return (bool)$query->num_rows;
	}

	private function getLockName(int $order_id): string
	{
		// lock names are server-wide - scope them to this shop's database; max length is 64 chars
		return 'simpay_' . substr(md5(DB_DATABASE . '|' . DB_PREFIX), 0, 16) . '_' . $order_id;
	}
}
