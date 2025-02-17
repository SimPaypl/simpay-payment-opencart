<?php

namespace Opencart\Admin\Controller\Extension\SimPay\Payment;

class SimPay extends \Opencart\System\Engine\Controller
{
    private const SIMPAY_VERSION = '1.0.0';
    private $error = [];

    public function index(): void
    {
        $this->load->language('extension/simpay/payment/simpay');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        $data['webhook_url'] = 'URL_SHOP/index.php?route=extension/simpay/payment/simpaywebhook';

        if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_simpay', [
                'payment_simpay_bearer' => $this->request->post['payment_simpay_bearer'],
                'payment_simpay_service_id' => $this->request->post['payment_simpay_service_id'],
                'payment_simpay_service_hash' => $this->request->post['payment_simpay_service_hash'],
                'payment_simpay_status' => $this->request->post['payment_simpay_status'],
                'payment_simpay_approved_status_id' => $this->request->post['payment_simpay_approved_status_id'],
            ]);
            $this->session->data['success'] = $this->language->get('simpay_config_updated');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        $data['error_warning'] = $this->error['warning'] ?? '';

        $data['action'] = $this->url->link('extension/simpay/payment/simpay', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        $data['payment_simpay_bearer'] = $this->config->get('payment_simpay_bearer') ?? '';
        $data['payment_simpay_service_id'] = $this->config->get('payment_simpay_service_id') ?? '';
        $data['payment_simpay_service_hash'] = $this->config->get('payment_simpay_service_hash') ?? '';
        $data['payment_simpay_status'] = $this->config->get('payment_simpay_status') ?? 0;
        $data['payment_simpay_approved_status_id'] = $this->config->get('payment_simpay_approved_status_id') ?? 0;

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $data['heading_title'] = $this->language->get('simpay_text_edit');

        $data['simpay_ipn_text'] = $this->language->get('simpay_ipn_text');
        $data['simpay_entry_bearer'] = $this->language->get('simpay_entry_bearer');
        $data['simpay_entry_bearer_description'] = $this->language->get('simpay_entry_bearer_description');
        $data['simpay_entry_service_id'] = $this->language->get('simpay_entry_service_id');
        $data['simpay_entry_service_id_description'] = $this->language->get('simpay_entry_service_id_description');
        $data['simpay_entry_service_hash'] = $this->language->get('simpay_entry_service_hash');
        $data['simpay_entry_service_hash_description'] = $this->language->get('simpay_entry_service_hash_description');
        $data['simpay_entry_status'] = $this->language->get('simpay_entry_status');
        $version = $this->simpay_plugin_version_message();
        $data['simpay_save'] = $this->language->get('simpay_save');
        $data['simpay_version_actual'] = $version[0];
        $data['simpay_version_message'] = $version[1];

        $data['simpay_enabled'] = $this->language->get('simpay_enabled');
        $data['simpay_disabled'] = $this->language->get('simpay_disabled');

        $this->load->model('localisation/order_status');

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['payment_simpay_approved_status'] = $this->language->get('payment_simpay_approved_status');

        $this->response->setOutput($this->load->view('extension/simpay/payment/simpay', $data));
    }

    private function validate(): bool
    {
        if (!$this->user->hasPermission('modify', 'extension/simpay/payment/simpay')) {
            $this->error['warning'] = $this->language->get('simpay_error_permission');
        }

        if (
            empty($this->request->post['payment_simpay_bearer']) ||
            empty($this->request->post['payment_simpay_service_id']) ||
            empty($this->request->post['payment_simpay_service_hash']) ||
            empty($this->request->post['payment_simpay_status']) ||
            empty($this->request->post['payment_simpay_approved_status_id'])
        ) {
            $this->error['warning'] = $this->language->get('simpay_error_validation');
        }

        return !$this->error;
    }

    private function simpay_plugin_version_message()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.simpay.pl/ecommerce/plugin/opencart/version');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);

        $response = json_decode($response, true);

        if (version_compare(($response['data']['version'] ?? '1.0.0'), self::SIMPAY_VERSION, '>')) {
            return [false, sprintf($this->language->get('simpay_version_old'), self::SIMPAY_VERSION, ($response['data']['version'] ?? '1.0.0'), $response['data']['zip_url'] ?? '')];
        }

        return [true, $this->language->get('simpay_version_current') . self::SIMPAY_VERSION];
    }
}
