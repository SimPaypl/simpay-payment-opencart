<?php

namespace Opencart\Admin\Model\Extension\SimPay\Payment;

class SimPay extends \Opencart\System\Engine\Model
{
    /**
     * Durable record of processed SimPay transactions (IPN idempotency).
     * Same definition as in the catalog model, which also creates it lazily for updates done by replacing files.
     */
    public function install(): void
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
}
