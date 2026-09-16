<?php
/**
 * WooCommerce Blocks integration for the NCX CopyAndPay gateway.
 *
 * Registers the payment method with the WooCommerce Block Checkout
 * so it renders in the React-based checkout flow.
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class NCX_CP_API_Blocks extends AbstractPaymentMethodType {

    protected $name = 'ncx_cp_api';

    private ?NCX_CP_API_Gateway $gateway = null;

    // Loads the gateway instance from WooCommerce when the integration initialises.
    public function initialize(): void {
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = $gateways[$this->name] ?? null;
    }

    // Returns true when the gateway exists and is available at checkout.
    public function is_active(): bool {
        return $this->gateway instanceof NCX_CP_API_Gateway && $this->gateway->is_available();
    }

    // Declares product payments as the only supported Blocks feature for this method.
    public function get_supported_features(): array {
        return ['products'];
    }

    // Registers and returns the block-checkout script handle for WooCommerce Blocks.
    public function get_payment_method_script_handles(): array {
        $handle = 'ncx-cp-api-blocks-checkout';
        $gateway = $this->resolve_gateway();

        // Register styles here; enqueue via gateway maybe_enqueue_assets (script deps must be scripts only).
        if ($gateway instanceof NCX_CP_API_Gateway) {
            $gateway->register_checkout_styles();
        }

        wp_register_script(
            $handle,
            plugins_url('../assets/js/checkout-blocks.js', __FILE__),
            ['wc-blocks-registry', 'wc-settings', 'wp-element'],
            NCX_CP_API::asset_version('assets/js/checkout-blocks.js'),
            true
        );

        return [$handle];
    }

    // Resolves the gateway instance, including a lazy lookup when initialize() has not run.
    private function resolve_gateway(): ?NCX_CP_API_Gateway {
        if ($this->gateway instanceof NCX_CP_API_Gateway) {
            return $this->gateway;
        }

        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return null;
        }

        $gateways = WC()->payment_gateways->payment_gateways();
        $gateway = $gateways[$this->name] ?? null;

        return $gateway instanceof NCX_CP_API_Gateway ? $gateway : null;
    }

    // Supplies settings and AJAX config consumed by checkout-blocks.js via wcSettings.
    public function get_payment_method_data(): array {
        if (!$this->gateway) {
            return [];
        }

        $region_host = $this->gateway->get_region_host();

        return [
            'title'              => $this->gateway->get_title(),
            'description'        => $this->gateway->get_description(),
            'supports'           => array_values(array_diff($this->gateway->supports, ['tokenization'])),
            'ajaxUrl'            => WC_AJAX::get_endpoint('ncx_cp_request_checkout_id'),
            'nonce'              => wp_create_nonce('ncx_cp_checkout_nonce'),
            'regionHost'         => $region_host,
            'environment'        => $this->gateway->get_option('test_mode', 'yes') === 'yes' ? 'test' : 'live',
            'brands'             => $this->gateway->get_payment_brands(),
            'cardBrands'         => $this->gateway->get_card_payment_brands(),
            'applePay'           => $this->gateway->get_apple_pay_client_config(),
            'googlePay'          => $this->gateway->get_google_pay_client_config(),
            'gatewayId'          => $this->name,
            'createRegistration' => $this->gateway->client_allows_card_saving() ? '1' : '0',
            'allowCardSaving'    => $this->gateway->client_allows_card_saving() ? '1' : '0',
            'loggedIn'           => is_user_logged_in() ? '1' : '0',
            'typography'         => $this->gateway->get_checkout_typography_tokens(),
            'themeClass'         => $this->gateway->get_checkout_theme_class(),
            'minimumPaymentAmount'  => $this->gateway->get_minimum_payment_amount(),
            'consoleLogging'     => $this->gateway->client_console_logging_enabled() ? '1' : '0',
            'applePayClientLog'  => $this->gateway->get_option('enable_apple_pay_log', 'no') === 'yes' ? '1' : '0',
            'googlePayClientLog' => $this->gateway->get_option('enable_google_pay_log', 'no') === 'yes' ? '1' : '0',
        ];
    }
}
