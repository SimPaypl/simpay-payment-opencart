<?php

namespace Opencart\Catalog\Controller\Extension\SimPay\Payment;

/**
 * OAuth redirect_uri for "Connect with SimPay".
 *
 * OpenCart's admin session cookie is SameSite=Strict, so it is not sent on the cross-site redirect
 * from panel.simpay.pl and the admin would land on the login page. SimPay therefore redirects here,
 * and this page forwards the browser to the admin callback. That navigation starts on the shop's own
 * site, so the admin session cookie is sent.
 *
 * The target URL is stored server-side by the admin when the flow starts (never taken from the request),
 * so this endpoint cannot be used as an open redirect, and it is only revealed when the request carries
 * the OAuth state of the flow in progress. The admin callback verifies the state again against the session.
 */
class SimPayOauth extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        $returnUrl = (string) $this->config->get('payment_simpay_oauth_return_url');
        $stateHash = (string) $this->config->get('payment_simpay_oauth_state_hash');
        $state = html_entity_decode((string) ($this->request->get['state'] ?? ''), ENT_QUOTES, 'UTF-8');

        // The return URL carries the admin user_token - only reveal it to the browser that holds the state of the flow.
        if (
            !preg_match('#^https?://#i', $returnUrl)
            || $stateHash === ''
            || !hash_equals($stateHash, hash('sha256', $state))
        ) {
            $this->response->addHeader('HTTP/1.1 400 Bad Request');
            $this->response->setOutput('No SimPay connection in progress.');
            return;
        }

        $query = [];
        foreach (['code', 'state', 'error', 'error_description'] as $key) {
            if (isset($this->request->get[$key]) && is_string($this->request->get[$key])) {
                // OpenCart HTML-escapes request data - forward raw values
                $query[$key] = html_entity_decode($this->request->get[$key], ENT_QUOTES, 'UTF-8');
            }
        }

        $target = $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $escaped = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');

        // keep the authorization code out of Referer headers and caches
        $this->response->addHeader('Referrer-Policy: no-referrer');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->addHeader('Content-Type: text/html; charset=utf-8');

        $this->response->setOutput(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer">'
            . '<meta http-equiv="refresh" content="0;url=' . $escaped . '"><title>SimPay</title></head>'
            . '<body><p><a href="' . $escaped . '">Continue / Kontynuuj</a></p></body></html>'
        );
    }
}
