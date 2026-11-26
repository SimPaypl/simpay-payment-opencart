<?php

namespace Opencart\Admin\Controller\Extension\SimPay\Payment;

use SimPay\SDK\Exception\ApiException;
use SimPay\SDK\OAuth\OAuthClient;
use SimPay\SDK\OAuth\OAuthConnector;
use SimPay\SDK\OAuth\OAuthException;
use SimPay\SDK\OAuth\OAuthStorageInterface;
use SimPay\SDK\OAuth\PaymentService;

class SimPay extends \Opencart\System\Engine\Controller
{
    private const SIMPAY_VERSION = '1.2.0';

    /** OAuth client registered by SimPay for the OpenCart plugin (public client, PKCE). */
    private const OAUTH_CLIENT_ID = '01a1e47c-00fe-71f0-a346-b0a8006396df';

    private const ROUTE = 'extension/simpay/payment/simpay';
    private const SETTING_CODE = 'payment_simpay';

    public const AUTH_METHOD_OAUTH = 'oauth';
    public const AUTH_METHOD_MANUAL = 'manual';

    private const FLASH_KEY = 'simpay_flash';

    public function __construct(\Opencart\System\Engine\Registry $registry)
    {
        parent::__construct($registry);
        require_once DIR_EXTENSION . 'simpay/vendor/autoload.php';
    }

    public function index(): void
    {
        $data = $this->load->language(self::ROUTE);
        $this->document->setTitle($this->language->get('heading_title'));

        $token = 'user_token=' . $this->session->data['user_token'];

        $data['webhook_url'] = $this->getWebhookUrl();
        $data['error_warning'] = '';

        $data['action'] = $this->url->link($this->route('save'), $token, true);
        $data['cancel'] = $this->url->link('marketplace/extension', $token . '&type=payment', true);

        $data['payment_simpay_bearer'] = $this->config->get('payment_simpay_bearer') ?? '';
        $data['payment_simpay_service_id'] = $this->config->get('payment_simpay_service_id') ?? '';
        $data['payment_simpay_service_hash'] = $this->config->get('payment_simpay_service_hash') ?? '';
        $data['payment_simpay_ip_verify'] = $this->config->get('payment_simpay_ip_verify') ?? 0;
        $data['payment_simpay_status'] = $this->config->get('payment_simpay_status') ?? 0;
        $data['payment_simpay_sort_order'] = $this->config->get('payment_simpay_sort_order') ?? 1;
        $data['payment_simpay_approved_status_id'] = $this->config->get('payment_simpay_approved_status_id') ?? 2;
        $data['payment_simpay_auth_method'] = $this->getAuthMethod();

        $data['oauth'] = $this->getOAuthViewData($token);

        $data['flash'] = $this->session->data[self::FLASH_KEY] ?? null;
        unset($this->session->data[self::FLASH_KEY]);

        $version = $this->simpay_plugin_version_message();
        $data['simpay_version_actual'] = $version[0];
        $data['simpay_version_message'] = $version[1];

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    public function save(): void
    {
        $this->load->language(self::ROUTE);

        $json = [];
        $post = $this->request->post;

        $authMethod = ($post['payment_simpay_auth_method'] ?? '') === self::AUTH_METHOD_OAUTH
            ? self::AUTH_METHOD_OAUTH
            : self::AUTH_METHOD_MANUAL;
        $post['payment_simpay_auth_method'] = $authMethod;

        if ($authMethod === self::AUTH_METHOD_OAUTH) {
            // Credentials are provisioned by "Connect with SimPay" - never overwrite them from the (hidden) manual fields
            unset($post['payment_simpay_bearer'], $post['payment_simpay_service_id'], $post['payment_simpay_service_hash']);
        }

        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $json['error'] = $this->language->get('simpay_error_permission');
        } elseif (
            $authMethod === self::AUTH_METHOD_MANUAL && (
                empty($post['payment_simpay_bearer']) ||
                empty($post['payment_simpay_service_id']) ||
                empty($post['payment_simpay_service_hash'])
            )
        ) {
            $json['error'] = $this->language->get('simpay_error_validation');
        }

        if (!$json) {
            $this->saveSettings($post);
            $json['success'] = $this->language->get('simpay_config_updated');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    // ──────────────────────────────────────────────────────────────────────
    // "Connect with SimPay" (OAuth) - flow lives in simpay/ecommerce OAuthConnector
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Step 1: redirect the browser to the SimPay consent screen.
     */
    public function oauthConnect(): void
    {
        $this->load->language(self::ROUTE);

        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $this->flashAndRedirect('danger', $this->language->get('simpay_error_permission'));
            return;
        }

        try {
            $authorizationUrl = $this->createConnector()->start($this->getOAuthRedirectUri());
        } catch (\Throwable $e) {
            $this->fail($e);
            return;
        }

        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationQuery);

        // The SimPay redirect lands on the public catalog bounce page (see catalog/controller/payment/simpayoauth.php),
        // which forwards the browser here. Stored server-side so user_token is never sent to SimPay; the bounce page
        // reveals it only to a request carrying this flow's state.
        $this->saveSettings([
            'payment_simpay_oauth_return_url' => $this->url->link(
                $this->route('oauthCallback'),
                'user_token=' . $this->session->data['user_token'],
                true
            ),
            'payment_simpay_oauth_state_hash' => hash('sha256', (string) ($authorizationQuery['state'] ?? '')),
        ]);

        $this->response->redirect($authorizationUrl);
    }

    /**
     * Step 2: SimPay redirected back (through the catalog bounce page) - exchange the code and load services.
     */
    public function oauthCallback(): void
    {
        $this->load->language(self::ROUTE);

        $this->saveSettings(['payment_simpay_oauth_return_url' => '', 'payment_simpay_oauth_state_hash' => '']);

        $query = [];
        foreach (['code', 'state', 'error', 'error_description'] as $key) {
            if (isset($this->request->get[$key])) {
                // OpenCart HTML-escapes request data - SDK needs raw values
                $query[$key] = html_entity_decode((string) $this->request->get[$key], ENT_QUOTES, 'UTF-8');
            }
        }

        try {
            $this->createConnector()->handleCallback($query);
        } catch (\Throwable $e) {
            $this->fail($e);
            return;
        }

        // the service picker is rendered on the configuration page from the pending connection
        $this->redirectToConfig();
    }

    /**
     * Step 3: merchant picked a service - provision API key, IPN URL and IPN signature key.
     */
    public function oauthProvision(): void
    {
        $this->load->language(self::ROUTE);

        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $this->flashAndRedirect('danger', $this->language->get('simpay_error_permission'));
            return;
        }

        try {
            $result = $this->createConnector()->connect(
                (string) ($this->request->post['service_id'] ?? ''),
                $this->getWebhookUrl(),
                'OpenCart ' . (string) parse_url(HTTP_CATALOG, PHP_URL_HOST)
            );
        } catch (\Throwable $e) {
            $this->fail($e);
            return;
        }

        $this->saveSettings([
            'payment_simpay_bearer' => $result->apiKey,
            'payment_simpay_service_id' => $result->serviceId,
            'payment_simpay_service_hash' => $result->ipnSignatureKey,
            'payment_simpay_auth_method' => self::AUTH_METHOD_OAUTH,
            'payment_simpay_oauth_account_email' => $result->accountEmail,
            'payment_simpay_oauth_service_name' => $result->serviceName,
        ]);

        $this->flashAndRedirect('success', sprintf($this->language->get('simpay_oauth_connected'), $result->serviceName));
    }

    /**
     * Removes OAuth-provisioned credentials from the shop.
     * The API has no endpoint for revoking API keys, so the key stays in the SimPay panel.
     */
    public function oauthDisconnect(): void
    {
        $this->load->language(self::ROUTE);

        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $this->flashAndRedirect('danger', $this->language->get('simpay_error_permission'));
            return;
        }

        $this->createConnector()->cancel();

        $this->saveSettings([
            'payment_simpay_bearer' => '',
            'payment_simpay_service_id' => '',
            'payment_simpay_service_hash' => '',
            'payment_simpay_auth_method' => self::AUTH_METHOD_OAUTH,
            'payment_simpay_oauth_account_email' => '',
            'payment_simpay_oauth_service_name' => '',
        ]);

        $this->flashAndRedirect('success', $this->language->get('simpay_oauth_disconnected'));
    }

    public function oauthCancel(): void
    {
        $this->createConnector()->cancel();
        $this->saveSettings(['payment_simpay_oauth_return_url' => '', 'payment_simpay_oauth_state_hash' => '']);
        $this->redirectToConfig();
    }

    private function getOAuthViewData(string $token): array
    {
        $pending = $this->createConnector()->getPendingConnection();
        $redirectUri = $this->getOAuthRedirectUri();

        return [
            'https_ok' => OAuthClient::isRedirectUriAllowed($redirectUri),
            'redirect_uri' => $redirectUri,
            'connected' => $this->getAuthMethod() === self::AUTH_METHOD_OAUTH
                && (string) $this->config->get('payment_simpay_bearer') !== ''
                && (string) $this->config->get('payment_simpay_service_id') !== ''
                && (string) $this->config->get('payment_simpay_service_hash') !== '',
            'account_email' => (string) $this->config->get('payment_simpay_oauth_account_email'),
            'service_name' => (string) $this->config->get('payment_simpay_oauth_service_name'),
            'service_id' => (string) $this->config->get('payment_simpay_service_id'),
            'connect_url' => $this->url->link($this->route('oauthConnect'), $token, true),
            'provision_url' => $this->url->link($this->route('oauthProvision'), $token, true),
            'disconnect_url' => $this->url->link($this->route('oauthDisconnect'), $token, true),
            'cancel_url' => $this->url->link($this->route('oauthCancel'), $token, true),
            'pending' => $pending === null ? null : [
                'email' => $pending->accountEmail,
                'services' => array_map(static fn (PaymentService $s): array => $s->toArray(), $pending->services),
                'suggested_service_id' => $pending->suggestServiceId(HTTP_CATALOG),
            ],
        ];
    }

    private function createConnector(): OAuthConnector
    {
        $session = $this->session;

        $storage = new class ($session) implements OAuthStorageInterface {
            public function __construct(private readonly object $session)
            {
            }

            public function get(string $key): ?array
            {
                $value = $this->session->data[$key] ?? null;

                return is_array($value) ? $value : null;
            }

            public function set(string $key, array $value): void
            {
                $this->session->data[$key] = $value;
            }

            public function remove(string $key): void
            {
                unset($this->session->data[$key]);
            }
        };

        return OAuthConnector::create(self::OAUTH_CLIENT_ID, $storage);
    }

    /**
     * OpenCart's admin session cookie is SameSite=Strict, so it is not sent when SimPay redirects
     * the browser straight to the admin panel. SimPay therefore returns to a public catalog page,
     * which forwards the browser to the admin callback with a same-site navigation.
     *
     * Built from HTTP_CATALOG (config.php) rather than the current request, so it stays https
     * behind a TLS-terminating proxy.
     */
    private function getOAuthRedirectUri(): string
    {
        return HTTP_CATALOG . 'index.php?route=extension/simpay/payment/simpayoauth';
    }

    private function getWebhookUrl(): string
    {
        return HTTP_CATALOG . 'index.php?route=extension/simpay/payment/simpaywebhook';
    }

    /**
     * Stored auth method; shops without credentials default to OAuth,
     * existing installations (credentials entered before OAuth existed) to manual.
     */
    private function getAuthMethod(): string
    {
        $method = (string) $this->config->get('payment_simpay_auth_method');

        if (in_array($method, [self::AUTH_METHOD_OAUTH, self::AUTH_METHOD_MANUAL], true)) {
            return $method;
        }

        return (string) $this->config->get('payment_simpay_bearer') === '' ? self::AUTH_METHOD_OAUTH : self::AUTH_METHOD_MANUAL;
    }

    /**
     * editSetting() replaces the whole group and editValue() cannot insert new keys - merge instead.
     */
    private function saveSettings(array $changes): void
    {
        $this->load->model('setting/setting');

        $settings = $this->model_setting_setting->getSetting(self::SETTING_CODE);
        $this->model_setting_setting->editSetting(self::SETTING_CODE, array_merge($settings, $changes));
    }

    /**
     * OpenCart < 4.0.2.0 uses "|" as the controller method separator, newer versions use ".".
     */
    private function route(string $method): string
    {
        $separator = version_compare(VERSION, '4.0.2.0', '>=') ? '.' : '|';

        return self::ROUTE . $separator . $method;
    }

    private function redirectToConfig(): void
    {
        $this->response->redirect($this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'], true));
    }

    private function flashAndRedirect(string $type, string $text): void
    {
        $this->session->data[self::FLASH_KEY] = ['type' => $type, 'text' => $text];
        $this->redirectToConfig();
    }

    private function fail(\Throwable $e): void
    {
        $this->log->write('SimPay OAuth error: ' . $e->getMessage());
        $this->flashAndRedirect('danger', $this->getOAuthErrorMessage($e));
    }

    private function getOAuthErrorMessage(\Throwable $e): string
    {
        if ($e instanceof OAuthException) {
            return match ($e->getReason()) {
                OAuthException::REDIRECT_URI_NOT_ALLOWED => $this->language->get('simpay_oauth_error_https'),
                OAuthException::ACCESS_DENIED => $this->language->get('simpay_oauth_error_access_denied'),
                OAuthException::AUTHORIZATION_FAILED => sprintf($this->language->get('simpay_oauth_error_authorization'), (string) $e->getDetails()),
                OAuthException::INVALID_STATE => $this->language->get('simpay_oauth_error_invalid_state'),
                OAuthException::NO_PAYMENT_SERVICES => $this->language->get('simpay_oauth_error_no_services'),
                OAuthException::EXPIRED => $this->language->get('simpay_oauth_error_expired'),
                OAuthException::UNKNOWN_SERVICE => $this->language->get('simpay_oauth_error_unknown_service'),
                OAuthException::UNSUPPORTED_IPN_VERSION => sprintf($this->language->get('simpay_oauth_error_ipn_version'), (string) $e->getDetails()),
                default => $e->getMessage(),
            };
        }

        return sprintf(
            $this->language->get('simpay_oauth_error_generic'),
            $e instanceof ApiException && $e->getApiMessage() ? $e->getApiMessage() : $e->getMessage()
        );
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

        $this->model_setting_setting->editSetting(self::SETTING_CODE, [
            'payment_simpay_status' => 0,
            'payment_simpay_sort_order' => 1,
            'payment_simpay_ip_verify' => 0,
            'payment_simpay_approved_status_id' => 2,
            'payment_simpay_auth_method' => self::AUTH_METHOD_OAUTH,
        ]);

        $this->load->model('extension/simpay/payment/simpay');
        $this->model_extension_simpay_payment_simpay->install();
    }

    /**
     * The simpay_transaction table is kept on purpose: after a reinstall it still prevents
     * a repeated IPN from processing an already paid order again.
     */
    public function uninstall(): void
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting(self::SETTING_CODE);
    }
}
