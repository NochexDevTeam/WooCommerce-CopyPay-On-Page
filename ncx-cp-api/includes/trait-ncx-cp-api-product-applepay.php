<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product-page Apple Pay express checkout (isolated from checkout Apple Pay flow).
 */
trait NCX_CP_API_Product_Apple_Pay {

    // Order meta value tagging product-page Apple Pay orders (traits cannot declare constants on PHP < 8.2).
    private static $product_apple_pay_source = 'apple_pay_product';

    // Wires product-page Apple Pay hooks without touching checkout handlers.
    public function register_product_apple_pay_hooks(): void {
        add_action('woocommerce_after_add_to_cart_button', [$this, 'render_product_apple_pay_markup'], 15);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_product_apple_pay_assets'], 25);
    }

    // Returns true when the admin product-page Apple Pay setting is enabled.
    public function is_product_apple_pay_enabled(): bool {
        return $this->is_apple_pay_enabled()
            && 'yes' === $this->get_option('enable_apple_pay_product_page', 'no');
    }

    // Returns true when the product-page Apple Pay UI should render for the current request.
    public function should_render_product_apple_pay_ui(?WC_Product $product = null): bool {
        if (!$this->is_product_apple_pay_enabled() || 'yes' !== $this->enabled) {
            return false;
        }

        if (!is_user_logged_in()) {
            return false;
        }

        if (!$this->customer_profile_ready_for_product_apple_pay($product)) {
            return false;
        }

        if (!$product instanceof WC_Product) {
            $product = $this->get_current_single_product();
        }

        return $this->is_product_eligible_for_apple_pay($product);
    }

    // Returns true when the logged-in customer has required billing (and shipping when needed).
    public function customer_profile_ready_for_product_apple_pay(?WC_Product $product = null): bool {
        $validation = $this->validate_customer_profile_for_product_apple_pay($product);

        return true === $validation;
    }

    /**
     * @return true|WP_Error
     */
    private function validate_customer_profile_for_product_apple_pay(?WC_Product $product = null) {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'ncx_cp_product_ap_guest',
                __('Please log in to use Apple Pay on the product page.', 'ncx-cp-api')
            );
        }

        if (!function_exists('WC') || !WC()->customer) {
            return new WP_Error(
                'ncx_cp_product_ap_no_customer',
                __('Your account details could not be loaded. Please complete checkout instead.', 'ncx-cp-api')
            );
        }

        if (!$product instanceof WC_Product) {
            $product = $this->get_current_single_product();
        }

        $needs_shipping = $product instanceof WC_Product && $product->needs_shipping();
        $posted         = $this->get_customer_profile_as_checkout_post($needs_shipping);

        return $this->validate_checkout_ready_for_payment($posted);
    }

    /**
     * @return array<string, string>
     */
    private function get_customer_profile_as_checkout_post(bool $needs_shipping): array {
        $customer = WC()->customer;
        $posted   = [
            'billing_first_name' => (string) $customer->get_billing_first_name(),
            'billing_last_name'  => (string) $customer->get_billing_last_name(),
            'billing_company'    => (string) $customer->get_billing_company(),
            'billing_address_1'  => (string) $customer->get_billing_address_1(),
            'billing_address_2'  => (string) $customer->get_billing_address_2(),
            'billing_city'       => (string) $customer->get_billing_city(),
            'billing_state'      => (string) $customer->get_billing_state(),
            'billing_postcode'   => (string) $customer->get_billing_postcode(),
            'billing_country'    => (string) $customer->get_billing_country(),
            'billing_email'      => (string) $customer->get_billing_email(),
            'billing_phone'      => (string) $customer->get_billing_phone(),
        ];

        if (!$needs_shipping) {
            return $posted;
        }

        $posted['shipping_first_name'] = (string) $customer->get_shipping_first_name();
        $posted['shipping_last_name']  = (string) $customer->get_shipping_last_name();
        $posted['shipping_company']    = (string) $customer->get_shipping_company();
        $posted['shipping_address_1']  = (string) $customer->get_shipping_address_1();
        $posted['shipping_address_2']  = (string) $customer->get_shipping_address_2();
        $posted['shipping_city']       = (string) $customer->get_shipping_city();
        $posted['shipping_state']      = (string) $customer->get_shipping_state();
        $posted['shipping_postcode']   = (string) $customer->get_shipping_postcode();
        $posted['shipping_country']    = (string) $customer->get_shipping_country();

        $posted['ship_to_different_address'] = $this->customer_has_distinct_shipping_address() ? '1' : '0';

        return $posted;
    }

    // Returns true when the customer shipping address differs from billing.
    private function customer_has_distinct_shipping_address(): bool {
        if (!function_exists('WC') || !WC()->customer) {
            return false;
        }

        $customer = WC()->customer;
        $pairs    = [
            ['get_billing_address_1', 'get_shipping_address_1'],
            ['get_billing_city', 'get_shipping_city'],
            ['get_billing_postcode', 'get_shipping_postcode'],
            ['get_billing_country', 'get_shipping_country'],
        ];

        foreach ($pairs as [$billing_getter, $shipping_getter]) {
            $billing  = (string) $customer->{$billing_getter}();
            $shipping = (string) $customer->{$shipping_getter}();
            if ('' !== $shipping && $billing !== $shipping) {
                return true;
            }
        }

        return '' !== (string) $customer->get_shipping_address_1()
            && (
                (string) $customer->get_shipping_first_name() !== (string) $customer->get_billing_first_name()
                || (string) $customer->get_shipping_last_name() !== (string) $customer->get_billing_last_name()
            );
    }

    // Returns true when this product type can be bought via product-page Apple Pay.
    private function is_product_eligible_for_apple_pay(?WC_Product $product): bool {
        if (!$product instanceof WC_Product) {
            return false;
        }

        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            return false;
        }

        if ($product->is_type(['external', 'grouped'])) {
            return false;
        }

        return $this->is_product_eligible_for_buy_now($product);
    }

    private function get_current_single_product(): ?WC_Product {
        if (!function_exists('is_product') || !is_product()) {
            return null;
        }

        global $product;

        return $product instanceof WC_Product ? $product : null;
    }

    // Outputs the product-page Apple Pay mount containers below add to cart.
    public function render_product_apple_pay_markup(): void {
        if (!is_product() || !$this->should_render_product_apple_pay_ui()) {
            return;
        }

        echo '<div id="ncx-cp-product-applepay-wrap" class="ncx-cp-product-applepay-wrap">';
        echo '<div id="ncx-cp-product-applepay-trigger" class="ncx-cp-applepay-frame"></div>';
        echo '<div id="ncx-cp-product-applepay-pay" class="ncx-cp-applepay-pay"></div>';
        echo '</div>';
    }

    // Enqueues product-page Apple Pay assets on single product pages only.
    public function maybe_enqueue_product_apple_pay_assets(): void {
        if (!is_product() || !$this->is_product_apple_pay_enabled() || 'yes' !== $this->enabled) {
            return;
        }

        $product = $this->get_current_single_product();
        if (!$this->should_render_product_apple_pay_ui($product)) {
            return;
        }

        $this->register_checkout_styles();
        wp_enqueue_style('ncx-cp-api-inline-style');

        $handle = 'ncx-cp-api-product-applepay';
        wp_enqueue_script(
            $handle,
            plugins_url('../assets/js/product-applepay.js', __FILE__),
            ['jquery'],
            NCX_CP_API::asset_version('assets/js/product-applepay.js'),
            true
        );

        wp_localize_script($handle, 'ncxCpProductApplePay', $this->get_product_apple_pay_script_config($product));
        if ($this->client_console_logging_enabled()) {
            wp_add_inline_script(
                $handle,
                'window.ncxCpApiLog=window.ncxCpApiLog||function(){if(window.console){console.log.apply(console,arguments);}};',
                'before'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function get_product_apple_pay_script_config(?WC_Product $product = null): array {
        if (!$product instanceof WC_Product) {
            $product = $this->get_current_single_product();
        }

        return [
            'productId'             => $product instanceof WC_Product ? $product->get_id() : 0,
            'isVariable'            => $product instanceof WC_Product && $product->is_type('variable') ? '1' : '0',
            'ajaxUrl'               => WC_AJAX::get_endpoint('ncx_cp_applepay_product_process'),
            'checkoutRedirectUrl'   => WC_AJAX::get_endpoint('ncx_cp_applepay_product_checkout_redirect'),
            'checkoutUrl'           => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '',
            'nonce'                 => wp_create_nonce('ncx_cp_checkout_nonce'),
            'regionHost'            => $this->get_region_host(),
            'environment'           => $this->test_mode ? 'test' : 'live',
            'applePay'              => $this->get_product_page_apple_pay_client_config($product),
            'minimumPaymentAmount'  => $this->get_minimum_payment_amount(),
            'minimumPaymentMessage' => $this->get_minimum_payment_notice(),
            'consoleLogging'        => $this->client_console_logging_enabled() ? '1' : '0',
            'applePayClientLog'     => $this->apple_pay_logging ? '1' : '0',
            'isLoggedIn'            => '1',
            'profileComplete'       => '1',
        ];
    }

    /**
     * Apple Pay client config for product pages (separate process URL and shipping flags).
     *
     * @return array<string, mixed>
     */
    private function get_product_page_apple_pay_client_config(?WC_Product $product = null): array {
        $config = $this->get_apple_pay_client_config();
        $config['processUrl'] = WC_AJAX::get_endpoint('ncx_cp_applepay_product_process');

        $config['buttonType'] = 'pay';

        $needs_shipping = $product instanceof WC_Product && $product->needs_shipping();
        if ($needs_shipping) {
            $config['requiredShippingContactFields'] = ['postalAddress', 'name', 'email', 'phone'];
        } else {
            unset($config['requiredShippingContactFields']);
        }

        return $config;
    }

    /**
     * AJAX: logged-in shoppers with complete profiles — buy now + Apple Pay session.
     */
    public function ajax_applepay_product_process(): void {
        $this->applepay_log('product process: request received', [
            'user_id' => get_current_user_id(),
        ]);

        if (!is_user_logged_in()) {
            wp_send_json_error([
                'message'  => __('Please log in to use Apple Pay.', 'ncx-cp-api'),
                'redirect' => wc_get_checkout_url(),
            ]);
            return;
        }

        if (!check_ajax_referer('ncx_cp_checkout_nonce', 'security', false)) {
            wp_send_json_error(['message' => __('Security check failed (nonce). Please reload the page.', 'ncx-cp-api')]);
            return;
        }

        if (!$this->is_product_apple_pay_enabled()) {
            wp_send_json_error(['message' => __('Apple Pay on the product page is not enabled.', 'ncx-cp-api')]);
            return;
        }

        $product = $this->load_product_from_request();
        if (is_wp_error($product)) {
            wp_send_json_error(['message' => $product->get_error_message()]);
            return;
        }

        $profile = $this->validate_customer_profile_for_product_apple_pay($product);
        if (is_wp_error($profile)) {
            wp_send_json_error([
                'message'  => $profile->get_error_message(),
                'redirect' => wc_get_checkout_url(),
            ]);
            return;
        }

        $cart_result = $this->build_product_buy_now_cart_from_request($product);
        if (is_wp_error($cart_result)) {
            wp_send_json_error(['message' => $cart_result->get_error_message()]);
            return;
        }

        if (!WC()->cart || WC()->cart->is_empty()) {
            wp_send_json_error(['message' => __('Cart is empty.', 'ncx-cp-api')]);
            return;
        }

        $amount = (float) WC()->cart->get_total('edit');
        if ($amount <= 0) {
            wp_send_json_error(['message' => __('Cart total is zero.', 'ncx-cp-api')]);
            return;
        }

        if ($this->amount_below_minimum($amount)) {
            wp_send_json_error(['message' => $this->get_minimum_payment_error_message()]);
            return;
        }

        $session_apply = $this->apply_customer_profile_to_session_for_product_apple_pay();
        if (is_wp_error($session_apply)) {
            wp_send_json_error(['message' => $session_apply->get_error_message()]);
            return;
        }

        $order_result = $this->coordinate_wallet_order('intent', [], []);
        if (is_wp_error($order_result['order'])) {
            $this->restore_cart_contents(WC()->session ? WC()->session->get(self::BUY_NOW_CART_SESSION_KEY) : true);
            $this->send_coordinator_error($order_result['order']);
            return;
        }

        /** @var WC_Order $order */
        $order      = $order_result['order'];
        $was_reused = (bool) $order_result['reused'];

        $use_test = (bool) $this->test_mode;
        $order->update_meta_data(self::ENVIRONMENT_META_KEY, $use_test ? 'test' : 'live');
        $order->update_meta_data('_ncx_cp_payment_source', self::$product_apple_pay_source);
        $order->set_payment_method_title(__('Apple Pay', 'ncx-cp-api'));
        if (!$was_reused) {
            $order->add_order_note(__('Product-page Apple Pay payment initiated.', 'ncx-cp-api'));
        }
        $order->save();

        $env_error = $this->validate_checkout_environment_consistency($order);
        if (is_wp_error($env_error)) {
            $this->restore_cart_contents(WC()->session ? WC()->session->get(self::BUY_NOW_CART_SESSION_KEY) : true);
            wp_send_json_error(['message' => $env_error->get_error_message()]);
            return;
        }

        $response = $this->create_product_apple_pay_session_response($order, $use_test);
        if (is_wp_error($response)) {
            $this->restore_cart_contents(WC()->session ? WC()->session->get(self::BUY_NOW_CART_SESSION_KEY) : true);
            wp_send_json_error(['message' => $response->get_error_message()]);
            return;
        }

        $this->restore_cart_contents(WC()->session ? WC()->session->get(self::BUY_NOW_CART_SESSION_KEY) : true);
        wp_send_json_success($response);
    }

    /**
     * AJAX: add product to cart and return checkout URL (logged-in fallback path).
     */
    public function ajax_applepay_product_checkout_redirect(): void {
        if (!is_user_logged_in()) {
            wp_send_json_error([
                'message'  => __('Please log in to continue.', 'ncx-cp-api'),
                'redirect' => wp_login_url(wc_get_checkout_url()),
            ]);
            return;
        }

        if (!check_ajax_referer('ncx_cp_checkout_nonce', 'security', false)) {
            wp_send_json_error(['message' => __('Security check failed (nonce). Please reload the page.', 'ncx-cp-api')]);
            return;
        }

        if (!$this->is_product_apple_pay_enabled()) {
            wp_send_json_error(['message' => __('Apple Pay on the product page is not enabled.', 'ncx-cp-api')]);
            return;
        }

        $product = $this->load_product_from_request();
        if (is_wp_error($product)) {
            wp_send_json_error(['message' => $product->get_error_message()]);
            return;
        }

        $cart_result = $this->build_product_buy_now_cart_from_request($product);
        if (is_wp_error($cart_result)) {
            wp_send_json_error(['message' => $cart_result->get_error_message()]);
            return;
        }

        wp_send_json_success([
            'redirect' => wc_get_checkout_url(),
        ]);
    }

    /**
     * @return WC_Product|WP_Error
     */
    private function load_product_from_request() {
        $product_id = isset($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;
        if ($product_id <= 0) {
            return new WP_Error('ncx_cp_product_missing', __('Product not found.', 'ncx-cp-api'));
        }

        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product || !$this->is_product_eligible_for_apple_pay($product)) {
            return new WP_Error('ncx_cp_product_invalid', __('This product cannot be purchased with Apple Pay.', 'ncx-cp-api'));
        }

        return $product;
    }

    /**
     * @return true|WP_Error
     */
    private function build_product_buy_now_cart_from_request(WC_Product $product) {
        if (!function_exists('WC') || !WC()->cart) {
            return new WP_Error('ncx_cp_no_cart', __('Cart is not available.', 'ncx-cp-api'));
        }

        $quantity     = isset($_POST['quantity']) ? wc_stock_amount(wp_unslash($_POST['quantity'])) : 1;
        $quantity     = max(1, (float) $quantity);
        $variation_id = isset($_POST['variation_id']) ? absint(wp_unslash($_POST['variation_id'])) : 0;
        $variations   = $this->parse_variation_attributes_from_request();

        if ($product->is_type('variable')) {
            if ($variation_id <= 0) {
                return new WP_Error(
                    'ncx_cp_variation_required',
                    __('Please choose product options before using Apple Pay.', 'ncx-cp-api')
                );
            }

            $variation = wc_get_product($variation_id);
            if (!$variation instanceof WC_Product_Variation || (int) $variation->get_parent_id() !== (int) $product->get_id()) {
                return new WP_Error('ncx_cp_variation_invalid', __('Invalid product variation.', 'ncx-cp-api'));
            }

            if (!$variation->is_purchasable() || !$variation->is_in_stock()) {
                return new WP_Error('ncx_cp_variation_unavailable', __('Selected variation is not available.', 'ncx-cp-api'));
            }
        }

        $snapshot = $this->snapshot_cart_contents();

        WC()->cart->empty_cart();

        $cart_item_key = WC()->cart->add_to_cart(
            $product->get_id(),
            $quantity,
            $variation_id,
            $variations
        );

        if (!$cart_item_key) {
            $this->restore_cart_contents($snapshot);
            return new WP_Error(
                'ncx_cp_add_to_cart_failed',
                __('Could not add this product to the cart.', 'ncx-cp-api')
            );
        }

        $session_apply = $this->apply_customer_profile_to_session_for_product_apple_pay();
        if (is_wp_error($session_apply)) {
            return $session_apply;
        }
        WC()->cart->calculate_totals();

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function parse_variation_attributes_from_request(): array {
        $attributes = [];
        if (!isset($_POST['variation']) || !is_array($_POST['variation'])) {
            return $attributes;
        }

        foreach (wp_unslash($_POST['variation']) as $key => $value) {
            $attributes[sanitize_title((string) $key)] = wc_clean((string) $value);
        }

        return $attributes;
    }

    /**
     * Copies the logged-in customer profile into the session for shipping calculation.
     *
     * @return true|WP_Error
     */
    private function apply_customer_profile_to_session_for_product_apple_pay() {
        if (!function_exists('WC') || !WC()->customer || !WC()->session) {
            return true;
        }

        $posted = $this->get_customer_profile_as_checkout_post(
            WC()->cart && WC()->cart->needs_shipping()
        );
        $applied = $this->apply_checkout_post_to_session($posted);
        if (is_wp_error($applied)) {
            return $applied;
        }

        if (!WC()->cart || !WC()->cart->needs_shipping()) {
            return true;
        }

        WC()->cart->calculate_totals();
        WC()->shipping()->calculate_shipping(WC()->cart->get_shipping_packages());

        $packages = WC()->shipping()->get_packages();
        $chosen   = [];
        foreach ($packages as $index => $package) {
            $rates = $package['rates'] ?? [];
            if (!empty($rates)) {
                $rate_ids     = array_keys($rates);
                $chosen[$index] = (string) $rate_ids[0];
            }
        }

        if (!empty($chosen)) {
            WC()->session->set('chosen_shipping_methods', $chosen);
            WC()->cart->calculate_totals();
        }

        return true;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function create_product_apple_pay_session_response(WC_Order $order, bool $use_test) {
        $prepared = $this->create_committed_provider_checkout($order, 'applepay');
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        $checkout_id = (string) $prepared['checkout_id'];
        $this->promote_canonical_order_to_intent($order, 'wallet');

        if (WC()->session) {
            $this->store_canonical_order_pointer((int) $order->get_id());
        }

        $shopper_result_url = add_query_arg(
            [
                'order_id'  => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ],
            WC()->api_request_url(self::CALLBACK_ACTION)
        );

        return [
            'order_id'         => $order->get_id(),
            'order_key'        => $order->get_order_key(),
            'checkoutId'       => $checkout_id,
            'environment'      => $use_test ? 'test' : 'live',
            'regionHost'       => $this->get_region_host($use_test),
            'shopperResultUrl' => $shopper_result_url,
            'total'            => $order->get_total(),
            'currency'         => $order->get_currency(),
        ];
    }
}
