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

    public function initialize(): void {
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = $gateways[$this->name] ?? null;
    }

    public function is_active(): bool {
        return $this->gateway instanceof NCX_CP_API_Gateway && $this->gateway->is_available();
    }

    public function get_supported_features(): array {
        return ['products'];
    }

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
            NCX_CP_API::VERSION,
            true
        );

        return [$handle];
    }

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

    public function get_payment_method_data(): array {
        if (!$this->gateway) {
            return [];
        }

        $region_host = $this->gateway->get_region_host();

        return [
            'title'              => $this->gateway->get_title(),
            'description'        => $this->gateway->get_description(),
            'supports'           => array_values(array_diff($this->gateway->supports, ['tokenization'])),
            'ajaxUrl'            => admin_url('admin-ajax.php'),
            'nonce'              => wp_create_nonce('ncx_cp_checkout_nonce'),
            'regionHost'         => $region_host,
            'environment'        => $this->gateway->get_option('test_mode', 'yes') === 'yes' ? 'test' : 'live',
            'brands'             => NCX_CP_API_Gateway::PAYMENT_BRANDS,
            'gatewayId'          => $this->name,
            'createRegistration' => is_user_logged_in() ? '1' : '0',
            'allowCardSaving'    => is_user_logged_in() ? '1' : '0',
            'loggedIn'           => is_user_logged_in() ? '1' : '0',
            'typography'         => $this->gateway->get_checkout_typography_tokens(),
        ];
    }
}
