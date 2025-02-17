<?php

namespace Opencart\Catalog\Model\Extension\SimPay\Payment;

class SimPay extends \Opencart\System\Engine\Model
{
	public function getMethods(array $address = []): array
	{

		if (!$this->config->get('payment_simpay_status')) {
			return [];
		}

		return [
			'code' => 'simpay',
			'name' => 'SimPay',
			'terms' => 'https://simpay.pl/uploads/download/documents/regulamin.pdf',
			'sort_order' => 1,
			'option' => [
				'simpay' => [
					'code' => 'simpay.simpay',
					'name' => 'Przelew Online / BLIK',
				],
			],
		];
	}
}
