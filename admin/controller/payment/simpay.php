<?php

namespace Opencart\Admin\Controller\Extension\SimPay\Payment;

class SimPay extends \Opencart\System\Engine\Controller
{
    private const SIMPAY_VERSION = '1.1.0';
    private array $error = [];

    public function index(): void
    {
        $this->load->language('extension/simpay/payment/simpay');
        $this->document->setTitle($this->language->get('heading_title'));

        // Populate language variables to TWIG
        $data['heading_title'] = $this->language->get('heading_title');
        $data['simpay_extension'] = $this->language->get('simpay_extension');
        $data['simpay_text_edit'] = $this->language->get('simpay_text_edit');
        $data['simpay_ipn_text'] = $this->language->get('simpay_ipn_text');
        $data['simpay_entry_bearer'] = $this->language->get('simpay_entry_bearer');
        $data['simpay_entry_bearer_description'] = $this->language->get('simpay_entry_bearer_description');
        $data['simpay_entry_service_id'] = $this->language->get('simpay_entry_service_id');
        $data['simpay_entry_service_id_description'] = $this->language->get('simpay_entry_service_id_description');
        $data['simpay_entry_ip_verify'] = $this->language->get('simpay_entry_ip_verify');
        $data['simpay_entry_ip_verify_description'] = $this->language->get('simpay_entry_ip_verify_description');
        $data['simpay_entry_status'] = $this->language->get('simpay_entry_status');
        $data['simpay_save'] = $this->language->get('simpay_save');
        $data['simpay_enabled'] = $this->language->get('simpay_enabled');
        $data['simpay_disabled'] = $this->language->get('simpay_disabled');
        $data['simpay_entry_approved_status'] = $this->language->get('simpay_entry_approved_status');
        $data['simpay_entry_approved_status_description'] = $this->language->get('simpay_entry_approved_status_description');
        $data['simpay_entry_sort_order'] = $this->language->get('simpay_entry_sort_order');

        $this->load->model('setting/setting');

        $data['webhook_url'] = HTTP_CATALOG . 'index.php?route=extension/simpay/payment/simpaywebhook';
        $data['error_warning'] = '';

        $data['action'] = $this->url->link('extension/simpay/payment/simpay|save', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        $data['payment_simpay_bearer'] = $this->config->get('payment_simpay_bearer') ?? '';
        $data['payment_simpay_service_id'] = $this->config->get('payment_simpay_service_id') ?? '';
        $data['payment_simpay_service_hash'] = $this->config->get('payment_simpay_service_hash') ?? '';
        $data['payment_simpay_ip_verify'] = $this->config->get('payment_simpay_ip_verify') ?? 0;
        $data['payment_simpay_status'] = $this->config->get('payment_simpay_status') ?? 0;
        $data['payment_simpay_sort_order'] = $this->config->get('payment_simpay_sort_order') ?? 1;
        $data['payment_simpay_approved_status_id'] = $this->config->get('payment_simpay_approved_status_id') ?? 2;

        $version = $this->simpay_plugin_version_message();
        $data['simpay_version_actual'] = $version[0];
        $data['simpay_version_message'] = $version[1];

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/simpay/payment/simpay', $data));
    }

    public function save(): void
    {
        $this->load->language('extension/simpay/payment/simpay');

        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/simpay/payment/simpay')) {
            $json['error'] = $this->language->get('simpay_error_permission');
        }

        if (
            empty($this->request->post['payment_simpay_bearer']) ||
            empty($this->request->post['payment_simpay_service_id']) ||
            empty($this->request->post['payment_simpay_service_hash'])
        ) {
            $json['error'] = $this->language->get('simpay_error_validation');
        }

        if (!$json) {
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_simpay', $this->request->post);
            $json['success'] = $this->language->get('simpay_config_updated');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    private function simpay_plugin_version_message(): array
    {
        $ch = curl_init('https://api.simpay.pl/ecommerce/plugin/opencart/version');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$response || $httpCode !== 200) {
            return [true, $this->language->get('simpay_version_current') . self::SIMPAY_VERSION];
        }

        $decoded = json_decode($response, true);
        $latestVersion = $decoded['data']['version'] ?? '1.0.0';

        if (version_compare($latestVersion, self::SIMPAY_VERSION, '>')) {
            return [
                false,
                sprintf($this->language->get('simpay_version_old'), self::SIMPAY_VERSION, $latestVersion, $decoded['data']['zip_url'] ?? '')
            ];
        }

        return [
            true,
            $this->language->get('simpay_version_current') . self::SIMPAY_VERSION
        ];
    }

    public function install(): void
    {
        $this->load->model('setting/setting');

        $this->model_setting_setting->editSetting('payment_simpay', [
            'payment_simpay_status' => 0,
            'payment_simpay_sort_order' => 1,
            'payment_simpay_ip_verify' => 0,
            'payment_simpay_approved_status_id' => 2,
        ]);
    }

    public function uninstall(): void
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('payment_simpay');
    }
}
