<?php

if (!defined('ABSPATH')) {
    exit;
}

class NCX_CP_API_Gateway extends WC_Payment_Gateway {
    private const PLUGIN_PREFIX = 'ncx_cp_';
    private const CHECKOUT_META_KEY = '_ncx_cp_checkout_id';
    private const ENVIRONMENT_META_KEY = '_ncx_cp_environment';
    private const SESSION_ENVIRONMENT_KEY = 'ncx_cp_checkout_environment';
    private const TOKEN_META_TEST_FLAG = 'test';
    private const CALLBACK_ACTION = 'ncx_cp_api_return';
    private const NOTIFICATION_ACTION = 'ncx_cp_api_notify';
    private const APC_URL = 'https://secure.nochex.com/callback/callback.aspx';
    private const DUPE_CRON_HOOK = 'ncx_cp_api_dupe_check';
    private const SUCCESS_CODES = [
        '000.000.000',
        '000.100.110',
    ];
    private const PENDING_CODES = [
        '000.200.000',
        '000.200.100',
    ];

    // ── Hardcoded (matches nochexapi pattern) ──────────────────────
    private const PAYMENT_TYPE          = 'DB';
    public const PAYMENT_BRANDS         = 'VISA MASTER';
    private const INCLUDE_CART_DATA     = true;   // nochexapi: includeCartData    = true
    private const THREE_DS_ENABLED      = true;   // nochexapi: threeDv2           = true
    private const THREE_DS_WINDOW_DAYS  = 180;
    private const MERCHANT_COUNTRY      = 'GB';
    private const MERCHANT_CURRENCY     = 'GBP';
    /** Minimum payable total (GBP) — matches legacy nochexapi checkout rule. */
    private const MIN_PAYMENT_AMOUNT    = 0.5;

    // ── Hardcoded test credentials (same as nochexapi) ─────────────
    private const TEST_ENTITY_ID    = '8ac7a4ca7843f17d017844faa85f0829';
    private const TEST_ACCESS_TOKEN = 'OGFjN2E0Y2E3ODQzZjE3ZDAxNzg0NGY4MTFjNjA4MjR8V2hFMlB4WHdFcA';

    // ── Instance properties loaded from settings ──────────────────
    private bool $test_mode;
    private string $checkout_theme;
    private string $primary_color;
    private string $accent_color;
    private bool $enable_dupe_check;
    private string $inline_note_text;
    private string $inline_note_color;
    private string $inline_muted_color;
    private string $inline_text_color;
    private string $inline_border_color;
    private string $inline_border_radius;
    private bool $allow_card_saving;
    private bool $console_logging;
    private bool $server_logging;
    private array $log_levels;
    private ?WC_Logger $logger = null;

    // Initializes the gateway settings, defaults, and runtime hooks.
    public function __construct() {
        // Bootstraps gateway defaults, reads settings, and wires runtime hooks.
        $this->id = 'ncx_cp_api';
        $this->method_title = __('Nochex CopyandPay On Page', 'ncx-cp-api');
        $this->method_description = __('Accept credit and debit cards via OPP COPYandPAY.', 'ncx-cp-api');
        $this->has_fields = true;
        $this->order_button_text = __('Place order', 'ncx-cp-api');
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();
        $this->maybe_migrate_legacy_settings();
        $this->maybe_refresh_stale_defaults();

        $this->test_mode = 'yes' === $this->get_option('test_mode', 'yes');
        $this->console_logging = 'yes' === $this->get_option('enable_console_log', 'no');
        $this->server_logging = 'yes' === $this->get_option('enable_server_log', 'no');
        $this->log_levels = (array) $this->get_option('log_levels', ['error', 'warning', 'info']);

        $this->enabled = $this->get_option('enabled', 'no');
        $this->title = $this->get_option('title', __('Pay with card', 'ncx-cp-api'));
        $this->description = $this->get_option('description', __('Checkout with Card', 'ncx-cp-api'));
        $this->checkout_theme = $this->get_option('checkout_theme', 'light');
        $palette = $this->resolve_theme_palette($this->checkout_theme);
        $this->primary_color = $this->sanitize_color($palette['primary']);
        $this->accent_color = $this->sanitize_color($palette['accent']);
        $this->enable_dupe_check = 'yes' === $this->get_option('enable_dupe_check', 'no');
        $this->inline_note_text = $this->get_option('inline_note_text', __('Click "Place order" to load the secure card form without leaving this page.', 'ncx-cp-api'));
        $this->inline_note_color = $this->sanitize_color($palette['note']);
        $this->inline_muted_color = $this->sanitize_color($palette['muted'] ?? '#6b7280');
        $this->inline_text_color = $this->sanitize_color($palette['text'] ?? '#111827');
        $this->inline_border_color = $this->sanitize_color($palette['border']);
        $this->inline_border_radius = $this->sanitize_radius($this->get_option('inline_border_radius', '12px'));
        $this->allow_card_saving = true;

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'render_receipt'], 10, 1);
        add_action('woocommerce_api_' . self::CALLBACK_ACTION, [$this, 'handle_result']);
        add_action('woocommerce_api_' . self::NOTIFICATION_ACTION, [$this, 'handle_notification']);
        add_action('woocommerce_api_' . $this->id, [$this, 'handle_apc']);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets']);
        add_action('init', [$this, 'maybe_schedule_duplicate_guard']);
        add_action(self::DUPE_CRON_HOOK, [$this, 'run_duplicate_guard']);

        // Deregister token at OPP when customer deletes a saved card.
        add_action('woocommerce_payment_token_deleted', [$this, 'deregister_token_at_opp'], 10, 2);

        // Filter saved payment tokens so only the current environment's tokens are shown.
        add_filter('woocommerce_get_customer_payment_tokens', [$this, 'filter_tokens_by_environment'], 10, 3);

        // Remove "Make default" from the Payment Methods page – only delete is allowed.
        add_filter('woocommerce_payment_methods_list_item', [$this, 'filter_token_list_actions'], 10, 2);

        // Hide the gateway on "Add payment method" – cards are saved during checkout only.
        add_filter('woocommerce_available_payment_gateways', [$this, 'hide_gateway_on_add_payment_method_page']);

        // TeraWallet top-up: cart may be £0 until amount is entered; still require payment UI.
        add_filter('woocommerce_cart_needs_payment', [$this, 'filter_cart_needs_payment_for_wallet_recharge'], 20);
    }

    // Declares the WooCommerce settings schema rendered on the admin screen.
    public function init_form_fields(): void {
        // Defines the configurable admin fields exposed in WooCommerce settings.
        $this->form_fields = [
            // ── General ───────────────────────────────────────────────────
            'enabled' => [
                'title' => __('Enable CopyAndPay', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Turn on this payment option at checkout.', 'ncx-cp-api'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'ncx-cp-api'),
                'type' => 'text',
                'default' => __('Pay with card', 'ncx-cp-api'),
                'description' => __('Shown to shoppers as the payment method name.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'ncx-cp-api'),
                'type' => 'text',
                'default' => __('Checkout with Card', 'ncx-cp-api'),
                'description' => __('Short helper line that appears under the name at checkout.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],

            // ── Environment & Credentials ──────────────────────────────────
            'environment_heading' => [
                'title' => __('Environment & Credentials', 'ncx-cp-api'),
                'type' => 'title',
                'description' => __('Choose between Test and Live mode and enter the Live details once you are ready to charge real cards.', 'ncx-cp-api'),
            ],
            'test_mode' => [
                'title' => __('Mode', 'ncx-cp-api'),
                'type' => 'select',
                'default' => 'yes',
                'options' => [
                    'no'  => __('Live', 'ncx-cp-api'),
                    'yes' => __('Test', 'ncx-cp-api'),
                ],
                'description' => __('Stay in Test while experimenting. Switch to Live when COPYandPAY has approved your account.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            'live_entity_id' => [
                'title' => __('Entity ID', 'ncx-cp-api') . ' <b style="color:green">(LIVE)</b>',
                'type' => 'text',
                'default' => '',
                'description' => __('Provided by COPYandPAY for your live merchant channel.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            'live_access_token' => [
                'title' => __('Access Token', 'ncx-cp-api') . ' <b style="color:green">(LIVE)</b>',
                'type' => 'text',
                'default' => '',
                'description' => __('Paste the live access token issued by COPYandPAY support.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            // Test credentials are hardcoded (matching nochexapi) — no admin fields needed.

            // ── Checkout Appearance ───────────────────────────────────────
            'appearance_heading' => [
                'title' => __('Checkout Appearance', 'ncx-cp-api'),
                'type' => 'title',
                'description' => __('Tweak the wording and colors so the payment box feels on-brand.', 'ncx-cp-api'),
            ],
            'checkout_theme' => [
                'title' => __('Checkout theme', 'ncx-cp-api'),
                'type' => 'select',
                'default' => 'light',
                'description' => __('Choose a preset palette. Each option is tuned for contrast and can be swapped anytime.', 'ncx-cp-api'),
                'desc_tip' => true,
                'options' => [
                    'light'     => __('Light (white + charcoal)', 'ncx-cp-api'),
                    'dark'      => __('Dark (midnight blue)', 'ncx-cp-api'),
                    'soft_gray' => __('Soft gray (subtle borders)', 'ncx-cp-api'),
                    'calm'      => __('Calm (gentle teal)', 'ncx-cp-api'),
                ],
            ],
            'inline_note_text' => [
                'title' => __('Inline helper text', 'ncx-cp-api'),
                'type' => 'text',
                'default' => __('Click "Place order" to load the secure card form without leaving this page.', 'ncx-cp-api'),
                'description' => __('Sentence shown above the embedded form to reassure shoppers.', 'ncx-cp-api'),
            ],
            'inline_border_radius' => [
                'title' => __('Inline border radius', 'ncx-cp-api'),
                'type' => 'text',
                'default' => '12px',
                'description' => __('How rounded the payment box corners are (examples: 12px, 0.75rem).', 'ncx-cp-api'),
            ],

            // ── Advanced ──────────────────────────────────────────────────
            'advanced_heading' => [
                'title' => __('Advanced', 'ncx-cp-api'),
                'type' => 'title',
                'description' => __('Optional safeguards. You can leave these off unless instructed otherwise.', 'ncx-cp-api'),
            ],
            'enable_dupe_check' => [
                'title' => __('Duplicate payment monitor', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Email an alert if two very similar orders happen within minutes.', 'ncx-cp-api'),
                'default' => 'no',
            ],

            // ── Logging & Diagnostics ─────────────────────────────────────
            'logging_heading' => [
                'title' => __('Logging & Diagnostics', 'ncx-cp-api'),
                'type' => 'title',
                'description' => __('Only enable these when troubleshooting with support.', 'ncx-cp-api'),
            ],
            'enable_console_log' => [
                'title' => __('Console logging', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Show checkout debug messages in the browser console (admins only).', 'ncx-cp-api'),
                'default' => 'no',
            ],
            'enable_server_log' => [
                'title' => __('Server logging', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Record gateway events inside WooCommerce → Status → Logs.', 'ncx-cp-api'),
                'default' => 'no',
            ],
            'log_levels' => [
                'title' => __('Log levels', 'ncx-cp-api'),
                'type' => 'multiselect',
                'description' => __('Choose which types of messages get written when logging is on.', 'ncx-cp-api'),
                'default' => ['emergency', 'critical', 'error', 'warning'],
                'options' => [
                    'critical'  => __('Critical', 'ncx-cp-api'),
                    'debug'     => __('Debugging', 'ncx-cp-api'),
                    'emergency' => __('Emergency', 'ncx-cp-api'),
                    'error'     => __('Error', 'ncx-cp-api'),
                    'info'      => __('Information', 'ncx-cp-api'),
                    'warning'   => __('Warning', 'ncx-cp-api'),
                ],
            ],
        ];
    }

    // Renders the inline wrapper that hosts the CopyAndPay experience on checkout.
    public function payment_fields() {
        // Outputs the frontend HTML container that hosts the CopyAndPay widget.
        if (!empty($this->description)) {
            echo wpautop(wp_kses_post($this->description));
        }

        // Always ensure WooCommerce container is cleanly styled.
        echo '<style type="text/css">'
            . 'li.payment_method_' . esc_attr($this->id) . ' div.payment_box {'
            . 'padding: 20px!important;'
            . 'background: #f9fafb!important;'
            . 'border: 1px solid #e5e7eb!important;'
            . 'border-radius: 12px!important;'
            . 'box-shadow: 0 1px 3px rgba(0,0,0,0.04)!important;'
            . 'margin-top: 8px!important;'
            . '}'
            . 'li.payment_method_' . esc_attr($this->id) . ' div.payment_box::before {'
            . 'border-bottom-color: #e5e7eb!important;'
            . '}'
            . 'li.payment_method_' . esc_attr($this->id) . ' div.payment_box > p:first-child {'
            . 'color: #6b7280;font-size:13px;margin:0 0 12px;padding:0;'
            . '}'
            . '</style>';

        if (WC()->cart && $this->amount_below_minimum((float) WC()->cart->get_total('edit'))) {
            echo '<style>#place_order{display:none!important;}</style>';
            echo '<p class="ncx-cp-minimum-notice" style="text-align:center;font-size:1.1rem;font-weight:600;margin:1rem 0;">';
            echo esc_html($this->get_minimum_payment_error_message());
            echo '</p>';
            return;
        }

        $checkout_field_id = self::PLUGIN_PREFIX . 'checkout_id';
        if (is_user_logged_in()) {
            echo '<input type="hidden" id="' . esc_attr(self::PLUGIN_PREFIX . 'save_card_intent') . '" name="ncx_cp_create_registration" value="0">';
        }
        echo '<input type="hidden" id="ncx_cp_payment_container" name="ncx_cp_payment_container" value="card">';

        // Hidden field to carry the pre-created checkout ID into WooCommerce's form POST.
        echo '<input type="hidden" id="' . esc_attr($checkout_field_id) . '" name="ncx_cp_checkout_id" value="">';

        if ('' !== trim($this->inline_note_text)) {
            echo '<p class="ncx-cp-inline-note">' . esc_html($this->inline_note_text) . '</p>';
        }

        // Container where the OPP COPYandPAY widget will be mounted immediately via JS.
        $wrapper_classes = ['ncx-cp-inline-wrapper', 'ncx-cp-theme-' . sanitize_html_class($this->checkout_theme)];

        echo '<div id="ncx-cp-inline-wrapper" class="' . esc_attr(implode(' ', $wrapper_classes)) . '">';
        echo '<div id="ncx-cp-inline-frame" class="ncx-cp-inline-frame"><p class="ncx-cp-inline-note">' . esc_html__( 'Loading secure card form…', 'ncx-cp-api' ) . '</p></div>';
        echo '</div>';

        // Debug: confirm the localized settings will be output, and tell user to check console.
        echo '<!-- NCX debug: payment_fields rendered, JS handle=ncx-cp-api-inline -->';
        echo '<script>console.log("NCX: payment_fields() HTML rendered on server");</script>';
    }

    // Determines whether the gateway can be offered on the current store request.
    public function is_available(): bool {
        // Determines if the gateway should appear at checkout based on state and currency.
        if ('yes' !== $this->enabled || !parent::is_available()) {
            return false;
        }

        // Enforce accepted store currency (GB / GBP only for now).
        if (function_exists('get_woocommerce_currency') && get_woocommerce_currency() !== self::MERCHANT_CURRENCY) {
            return false;
        }

        $credentials = $this->get_active_credentials();

        return !empty($credentials['entity_id']) && !empty($credentials['access_token']);
    }

    // Connects WooCommerce orders with COPYandPAY checkouts and returns execution data.
    public function process_payment($order_id): array {
        // Binds WooCommerce orders to COPYandPAY checkouts and returns execution metadata.
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['result' => 'failure'];
        }

        if ($this->amount_below_minimum((float) $order->get_total())) {
            wc_add_notice($this->get_minimum_payment_error_message(), 'error');
            return ['result' => 'failure'];
        }

        // Match nochexapi orderStatusHandler: only process pending orders.
        // Allow failed orders to retry (WC reuses orders via order_awaiting_payment session).
        $status = $order->get_status();
        if ('failed' === $status) {
            $order->set_status('pending', __('Customer retrying payment.', 'ncx-cp-api'));
            $order->save();
        } elseif ('pending' !== $status && 'checkout-draft' !== $status) {
            if ('cancelled' === $status) {
                wc_add_notice(__('This order was cancelled. Please start a new order.', 'ncx-cp-api'), 'error');
                return ['result' => 'failure'];
            }
            // Already processing/completed — redirect to thank-you.
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        $env_error = $this->validate_checkout_environment_consistency($order);
        if (is_wp_error($env_error)) {
            wc_add_notice($env_error->get_error_message(), 'error');
            return ['result' => 'failure'];
        }

        $use_test = $this->resolve_use_test($order);
        if (!$order->get_meta(self::ENVIRONMENT_META_KEY)) {
            $order->update_meta_data(self::ENVIRONMENT_META_KEY, $use_test ? 'test' : 'live');
            $order->save();
        }

        // Phase 2 of nochexapi-inspired flow:
        // The checkout ID was pre-created from cart data (Phase 1 = AJAX).
        // Now we bind the real order data to that checkout via updateTransactionData.
        $checkout_id = $this->get_request_checkout_id();

        if ('' === $checkout_id) {
            // Fallback: no pre-created ID (e.g. order-pay page). Create one from the order.
            $session = $this->request_checkout_session($order);
            if (is_wp_error($session)) {
                wc_add_notice($session->get_error_message(), 'error');
                return ['result' => 'failure'];
            }
            $checkout_id = $session['id'];
        } else {
            $payment_container = $this->get_request_payment_container();
            $create_reg = $this->get_request_create_registration_flag();
            if ('card' !== $payment_container) {
                $create_reg = false;
            }
            $update = $this->update_checkout_data($checkout_id, $order, $create_reg, $payment_container);
            if (is_wp_error($update)) {
                wc_add_notice($update->get_error_message(), 'error');
                return ['result' => 'failure'];
            }
        }

        $order->update_meta_data(self::CHECKOUT_META_KEY, $checkout_id);
        $order->save();

        // Match nochexapi: redirect MUST be false so the JS does NOT follow a
        // redirect URL.  Stage 2 of the JS checks `json.redirect !== false` and
        // would navigate away before executePayment() can fire if we sent a URL.
        // nochexapi's orderStatusHandler returns redirect => false for pending.
        $response = [
            'result'       => 'success',
            'redirect'     => false,
            'refresh'      => false,
            'reload'       => false,
            'pending'      => true,
            'execute'      => true,
            'platformbase' => $this->get_region_host($use_test),
            'checkout_id'  => $checkout_id,
            'uuid'         => $checkout_id,
            'order_id'     => $order_id,
            'order_key'    => $order->get_order_key(),
        ];

        return $response;
    }

    // Loads the receipt page widget after an order is placed.
    public function render_receipt(int $order_id): void {
        // Displays the receipt page instructions plus the selected widget style.
        $order = wc_get_order($order_id);
        if (!$order) {
            echo '<p>' . esc_html__('Unable to load order.', 'ncx-cp-api') . '</p>';
            return;
        }

        $use_test = $this->resolve_use_test($order);
        if (!$order->get_meta(self::ENVIRONMENT_META_KEY)) {
            $order->update_meta_data(self::ENVIRONMENT_META_KEY, $use_test ? 'test' : 'live');
            $order->save();
        }

        $checkout_id = (string) $order->get_meta(self::CHECKOUT_META_KEY, true);
        if ('' === $checkout_id) {
            $session = $this->request_checkout_session($order);
            if (is_wp_error($session)) {
                echo '<p>' . esc_html($session->get_error_message()) . '</p>';
                return;
            }
            $checkout_id = $session['id'];
            $order->update_meta_data(self::CHECKOUT_META_KEY, $checkout_id);
            $order->save();
        }

        echo '<p>' . esc_html__('Complete your payment below. The order will update automatically once confirmed.', 'ncx-cp-api') . '</p>';
        echo NCX_CP_API::render_widget_markup($checkout_id, self::PAYMENT_BRANDS, $this->get_region_host($use_test)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    // Handles shopper redirects from COPYandPAY and finalises order status.
    public function handle_result(): void {
        // Handles shopper redirects from COPYandPAY and finalises the order state.
        $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_key = isset($_GET['order_key']) ? sanitize_text_field(wp_unslash($_GET['order_key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $resource_path = isset($_GET['resourcePath']) ? sanitize_text_field(wp_unslash($_GET['resourcePath'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = $order_id ? wc_get_order($order_id) : false;

        if (!$order || $order->get_order_key() !== $order_key) {
            wc_add_notice(__('Unable to match the order for this payment.', 'ncx-cp-api'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Idempotency guard — if the notification already finalised this order, just redirect.
        if (!in_array($order->get_status(), ['pending', 'on-hold', 'checkout-draft'], true)) {
            if ($order->is_paid()) {
                wp_safe_redirect($order->get_checkout_order_received_url());
            } else {
                wp_safe_redirect(wc_get_checkout_url());
            }
            exit;
        }

        if ('' === $resource_path) {
            $order->update_status('failed', __('Missing payment reference from COPYandPAY.', 'ncx-cp-api'));
            wc_add_notice(__('Payment was cancelled or failed before completion.', 'ncx-cp-api'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $payment = $this->fetch_payment_details($resource_path, $order);
        if (is_wp_error($payment)) {
            $order->update_status('failed', $payment->get_error_message());
            wc_add_notice($payment->get_error_message(), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Verify payment data matches the order (amount, currency, cart_hash, order_key).
        $verification = $this->verify_payment_against_order($order, $payment);
        if (is_wp_error($verification)) {
            $order->update_status('failed', $verification->get_error_message());
            wc_add_notice(__('Payment verification failed. Please contact support.', 'ncx-cp-api'), 'error');
            $this->log_event('error', 'Payment verification failed on callback', [
                'order_id' => $order_id,
                'reason'   => $verification->get_error_message(),
            ]);
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $state = $this->determine_state($payment);
        $result_code = (string) ($payment['result']['code'] ?? '');
        $transaction_id = (string) ($payment['id'] ?? '');

        if ('success' === $state) {
            $order->payment_complete($transaction_id);
            $order->add_order_note(sprintf(__('COPYandPAY approved (%s).', 'ncx-cp-api'), $result_code));
            // Match nochexapi: store token if registrationId present in response
            // (OPP only returns registrationId when the shopper checked the save-card box).
            if ($this->allow_card_saving && isset($payment['registrationId'])) {
                $this->maybe_tokenize_card($order, $payment);
            }
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        if ('pending' === $state) {
            $order->update_status('on-hold', sprintf(__('COPYandPAY pending (%s).', 'ncx-cp-api'), $result_code));
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        $order->update_status('failed', sprintf(__('COPYandPAY declined (%s).', 'ncx-cp-api'), $result_code));
        wc_add_notice(__('Payment was declined. Please try another method.', 'ncx-cp-api'), 'error');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * Server-to-server notification handler (notificationUrl).
     *
     * OPP sends this asynchronously — even if the customer closes the browser.
     * We fetch the payment details and finalise the order if it is still pending.
     */
    public function handle_notification(): void {
        // Processes asynchronous server notifications sent by COPYandPAY.
        $order_id  = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_key = isset($_GET['order_key']) ? sanitize_text_field(wp_unslash($_GET['order_key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $resource_path = isset($_POST['resourcePath']) ? sanitize_text_field(wp_unslash($_POST['resourcePath'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ('' === $resource_path && isset($_GET['resourcePath'])) { // Fallback for GET-based callbacks.
            $resource_path = sanitize_text_field(wp_unslash($_GET['resourcePath'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order || $order->get_order_key() !== $order_key) {
            wp_send_json(['status' => 'invalid_order'], 400);
            return;
        }

        // Only process if the order has not already been finalised.
        if (!in_array($order->get_status(), ['pending', 'on-hold'], true)) {
            $response = $order->is_paid() ? 'already_paid' : 'already_finalised';
            wp_send_json(['status' => $response]);
            return;
        }

        if ('' === $resource_path) {
            $this->log_event('error', 'Notification missing resourcePath', [
                'order_id' => $order_id,
            ]);
            wp_send_json(['status' => 'missing_resource_path'], 400);
            return;
        }

        $payment = $this->fetch_payment_details($resource_path, $order);
        if (is_wp_error($payment)) {
            $this->log_event('error', 'Notification fetch failed', [
                'order_id' => $order_id,
                'error'    => $payment->get_error_message(),
            ]);
            wp_send_json(['status' => 'fetch_failed'], 502);
            return;
        }

        // Verify payment data matches the order.
        $verification = $this->verify_payment_against_order($order, $payment);
        if (is_wp_error($verification)) {
            $this->log_event('error', 'Notification payment verification failed', [
                'order_id' => $order_id,
                'reason'   => $verification->get_error_message(),
            ]);
            wp_send_json(['status' => 'verification_failed'], 400);
            return;
        }

        $state          = $this->determine_state($payment);
        $result_code    = (string) ($payment['result']['code'] ?? '');
        $transaction_id = (string) ($payment['id'] ?? '');

        if ('success' === $state) {
            $order->payment_complete($transaction_id);
            $order->add_order_note(sprintf(__('COPYandPAY approved via notification (%s).', 'ncx-cp-api'), $result_code));
            if ($this->allow_card_saving && isset($payment['registrationId'])) {
                $this->maybe_tokenize_card($order, $payment);
            }
        } elseif ('pending' === $state) {
            $order->update_status('on-hold', sprintf(__('COPYandPAY pending via notification (%s).', 'ncx-cp-api'), $result_code));
        } else {
            $order->update_status('failed', sprintf(__('COPYandPAY declined via notification (%s).', 'ncx-cp-api'), $result_code));
        }

        $this->log_event('info', 'Notification processed', [
            'order_id' => $order_id,
            'state'    => $state,
            'code'     => $result_code,
        ]);

        wp_send_json(['status' => 'ok']);
    }

    /**
     * Nochex APC (Automatic Payment Confirmation) callback handler.
     * Receives POST data from Nochex, verifies it against secure.nochex.com,
     * and finalises the order on success.
     * Mirrors nochexapi apc() method.
     * URL: /?wc-api=ncx_cp_api
     */
    public function handle_apc(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if (empty($_POST['order_id'])) {
            wp_die('Nochex APC – Request Failed', 'APC Error', ['response' => 400]);
            return;
        }

        $order_id           = absint(wp_unslash($_POST['order_id']));
		$order_id = str_replace("order-", "", $order_id);
        $transaction_id     = isset($_POST['transaction_id']) ? sanitize_text_field(wp_unslash($_POST['transaction_id'])) : '';
        $transaction_date   = isset($_POST['transaction_date']) ? sanitize_text_field(wp_unslash($_POST['transaction_date'])) : '';
        $transaction_amount = isset($_POST['amount']) ? sanitize_text_field(wp_unslash($_POST['amount'])) : '';
        $transaction_status = isset($_POST['transaction_status']) ? sanitize_text_field(wp_unslash($_POST['transaction_status'])) : '';
        $merchant_id        = isset($_POST['merchant_id']) ? sanitize_text_field(wp_unslash($_POST['merchant_id'])) : '';
        $email_address      = isset($_POST['email_address']) ? sanitize_text_field(wp_unslash($_POST['email_address'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $this->log_event('info', 'APC callback received', [
            'order_id'       => $order_id,
            'transaction_id' => $transaction_id,
            'amount'         => $transaction_amount,
            'status'         => $transaction_status,
        ]);

        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log_event('error', 'APC callback – invalid order ID', ['order_id' => $order_id]);
            wp_die('Nochex APC – Invalid Order', 'APC Error', ['response' => 400]);
            return;
        }

        // Post back to Nochex to verify the callback is genuine.
        $verify_response = wp_remote_post(self::APC_URL, [
            'timeout'    => 30,
            'sslverify'  => true,
            'body'       => wp_unslash($_POST), // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'headers'    => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'user-agent' => 'WooCommerce/' . WC()->version,
        ]);

        $output = is_wp_error($verify_response) ? '' : wp_remote_retrieve_body($verify_response);

        $this->log_event('info', 'APC verification response', [
            'order_id' => $order_id,
            'response' => $output,
        ]);

        $status_label = ('100' === $transaction_status) ? 'TEST' : 'LIVE';

        $callback_notes  = '<ul style="list-style:none;">';
        $callback_notes .= '<li>Transaction Status: ' . esc_html($status_label) . '</li>';
        $callback_notes .= '<li>Transaction ID: ' . esc_html($transaction_id) . '</li>';
        $callback_notes .= '</ul>';

        if ('AUTHORISED' === $output) {
            $order->add_order_note($callback_notes);
            $order->payment_complete($transaction_id);

            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }

            $this->log_event('info', 'APC callback authorised', [
                'order_id'       => $order_id,
                'transaction_id' => $transaction_id,
                'status'         => $status_label,
            ]);
        } else {
            $order->add_order_note($callback_notes);

            $this->log_event('warning', 'APC callback not authorised', [
                'order_id'       => $order_id,
                'transaction_id' => $transaction_id,
                'response'       => $output,
                'status'         => $status_label,
            ]);
        }

        exit;
    }

    // Composes and submits the payload required to create a checkout session for an order.
    private function request_checkout_session(WC_Order $order) {
        // Constructs the full payload and creates a COPYandPAY checkout for an order.
        if ($this->amount_below_minimum((float) $order->get_total())) {
            return new WP_Error('ncx_cp_below_minimum', $this->get_minimum_payment_error_message());
        }

        $use_test = $this->resolve_use_test($order);
        $credentials = $this->get_active_credentials($use_test);
        if (!$this->credentials_present($credentials)) {
            return new WP_Error('ncx_cp_missing_creds', __('CopyAndPay credentials are missing.', 'ncx-cp-api'));
        }

        $callback = add_query_arg(
            [
                'order_id' => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ],
            WC()->api_request_url(self::CALLBACK_ACTION)
        );

        $body = [
            'entityId' => $credentials['entity_id'],
            'amount' => $this->format_amount($order->get_total()),
            'currency' => $order->get_currency(),
            'paymentType' => self::PAYMENT_TYPE,
            'merchantTransactionId' => (string) $order->get_id(),
            'shopperResultUrl' => add_query_arg(
            [
                'order_id' => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ],
            WC()->api_request_url(self::CALLBACK_ACTION)
            ),
            'notificationUrl'  => add_query_arg(
                [
                    'order_id'  => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                ],
                WC()->api_request_url(self::NOTIFICATION_ACTION)
            ),
        ];

        $this->apply_opp_customer_fields_to_body($body, $order);

        $order_user_id = (int) $order->get_user_id();
        if ($order_user_id > 0) {
            $body['customer.merchantCustomerId'] = (string) $order_user_id;
        }

        $this->add_address_fields($body, 'billing', $order);
        if ($order->has_shipping_address()) {
            $this->add_address_fields($body, 'shipping', $order);
        }

        $body['cart.items[0].name'] = $this->get_cart_description($order);
        $body['cart.items[0].productUrl'] = add_query_arg('wc-api', $this->id, home_url('/'));

        $body['customParameters[SHOPPER_amount]']    = $this->format_amount($order->get_total());
        $body['customParameters[SHOPPER_currency]']  = $order->get_currency();
        $body['customParameters[SHOPPER_order_key]'] = $order->get_order_key();
        $body['customParameters[SHOPPER_cart_hash]']  = $order->get_cart_hash();
        $body['customParameters[SHOPPER_platform]']  = 'WooCommerce';
        $body['customParameters[SHOPPER_plugin]']    = NCX_CP_API::VERSION;

        $this->apply_standing_instruction_parameters($body);

        // Match nochexapi: during normal checkout, do NOT send createRegistration.
        // Only attach existing saved-card registrationIds so OPP shows them in the widget.
        // The OPP widget's own createRegistration checkbox (injected by JS) tells OPP
        // whether to create a new registration — the backend never forces it.
        if ($this->allow_card_saving && is_user_logged_in()) {
            $customer_id = $order_user_id > 0 ? $order_user_id : (int) get_current_user_id();
            if ($customer_id > 0) {
                $body['customer.merchantCustomerId'] = (string) $customer_id;
                $tokens = $this->get_tokens_for_environment($customer_id, $use_test);
                foreach ($tokens as $idx => $token) {
                    $body['registrations[' . $idx . '].id'] = $token->get_token();
                }
            }
        }

        foreach ($this->build_three_ds_parameters($order) as $key => $value) {
            $body["customParameters[$key]"] = $value;
        }

        return $this->post_to_checkouts($credentials, $body, $use_test);
    }

    /**
     * AJAX handler: create a checkout session from the current cart (Phase 1).
     * Mirrors nochexapi\'s genCheckoutIdOrder() \u2013 card fields appear immediately.
     */
    public function ajax_request_checkout_id(): void {
        // AJAX endpoint that pre-creates a checkout ID from the current cart context.
        if (!check_ajax_referer('ncx_cp_checkout_nonce', 'security', false)) {
            wp_send_json_error(['message' => 'Security check failed (nonce). Please reload the page.']);
            return;
        }

        if (!WC()->cart || WC()->cart->is_empty()) {
            wp_send_json_error(['message' => 'Cart is empty']);
            return;
        }

        $credentials = $this->get_active_credentials();
        if (!$this->credentials_present($credentials)) {
            wp_send_json_error(['message' => 'Credentials missing']);
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

        // Match nochexapi's createCheckoutArray: minimal payload for Phase 1.
        $body = [
            'entityId'    => $credentials['entity_id'],
            'paymentType' => self::PAYMENT_TYPE,
            'amount'      => number_format((float) $amount, 2, '.', ''),
            'currency'    => get_woocommerce_currency(),
        ];

        $this->apply_standing_instruction_parameters($body);

        // Match nochexapi: during normal checkout, do NOT send createRegistration.
        // Only attach existing saved-card registrationIds so OPP shows them.
        // The OPP widget checkbox handles createRegistration natively.
        if ($this->allow_card_saving && is_user_logged_in()) {
            $customer_id = (int) get_current_user_id();
            if ($customer_id > 0) {
                $body['customer.merchantCustomerId'] = (string) $customer_id;
                $tokens = $this->get_tokens_for_environment($customer_id);
                foreach ($tokens as $idx => $token) {
                    $body['registrations[' . $idx . '].id'] = $token->get_token();
                }
            }
        }

        $use_test = (bool) $this->test_mode;
        $this->set_checkout_session_environment($use_test ? 'test' : 'live');

        $result = $this->post_to_checkouts($credentials, $body, $use_test);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        wp_send_json_success([
            'checkoutId'  => $result['id'],
            'environment' => $use_test ? 'test' : 'live',
        ]);
    }

    /**
     * Phase 2: bind order data to an existing checkout ID.
     * POST to /v1/checkouts/{id} (mirrors nochexapi\'s updateTransactionData).
     */
    private function update_checkout_data(string $checkout_id, WC_Order $order, bool $create_registration = false, string $payment_container = 'card') {
        // Pushes full WooCommerce order information into an existing checkout session.
        $use_test = $this->resolve_use_test($order);
        $credentials = $this->get_active_credentials($use_test);
		
        if (!$this->credentials_present($credentials)) {
            return new WP_Error('ncx_cp_missing_creds', __('CopyAndPay credentials are missing.', 'ncx-cp-api'));
        }

        $region_host = $this->get_region_host($use_test);

        $body = [
            'entityId' => $credentials['entity_id'],
            'paymentType' => self::PAYMENT_TYPE,
            'amount' => $this->format_amount($order->get_total()),
            'currency' => $order->get_currency(),
            'merchantTransactionId' => (string) $order->get_id(),
            'shopperResultUrl' => add_query_arg(
            [
                'order_id' => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ],
            WC()->api_request_url(self::CALLBACK_ACTION)
         ),
            'notificationUrl'  => add_query_arg(
                [
                    'order_id'  => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                ],
                WC()->api_request_url(self::NOTIFICATION_ACTION)
            ),
            'customParameters[SHOPPER_amount]'    => $this->format_amount($order->get_total()),
            'customParameters[SHOPPER_currency]'  => $order->get_currency(),
            'customParameters[SHOPPER_order_key]' => $order->get_order_key(),
            'customParameters[SHOPPER_cart_hash]'  => $order->get_cart_hash(),
            'customParameters[SHOPPER_platform]'  => 'WooCommerce',
            'customParameters[SHOPPER_plugin]'    => NCX_CP_API::VERSION,
        ];

        $this->apply_opp_customer_fields_to_body($body, $order);

        $this->add_address_fields($body, 'billing', $order);
        if ($order->has_shipping_address()) {
            $this->add_address_fields($body, 'shipping', $order);
        }

        $body['cart.items[0].name'] = $this->get_cart_description($order);
        $body['cart.items[0].productUrl'] = add_query_arg('wc-api', $this->id, home_url('/'));

        $customer_id = (int) $order->get_user_id();
        if ($customer_id <= 0 && is_user_logged_in()) {
            $customer_id = (int) get_current_user_id();
        }
        if ($customer_id > 0) {
            $body['customer.merchantCustomerId'] = (string) $customer_id;
        }

        if (!in_array($payment_container, ['card', 'registration'], true)) {
            $payment_container = 'card';
        }

        // createRegistration only when paying with a new card and shopper opted in.
        if ($create_registration && $this->allow_card_saving && $customer_id > 0 && 'card' === $payment_container) {
            $body['createRegistration'] = 'true';
        } elseif ('registration' === $payment_container) {
            $body['standingInstruction.source'] = 'CIT';
            $body['standingInstruction.mode']   = 'REPEATED';
            $body['standingInstruction.type']   = 'UNSCHEDULED';
        }
		
		if ($this->allow_card_saving && $customer_id > 0) {
			$tokens = $this->get_tokens_for_environment($customer_id, $use_test);
			foreach ($tokens as $idx => $token) {
				$body['registrations[' . $idx . '].id'] = $token->get_token();
			}
		}

        foreach ($this->build_three_ds_parameters($order) as $key => $value) {
            $body["customParameters[$key]"] = $value;
        }

		$update_url = add_query_arg(
			'entityId',
			$credentials['entity_id'],
			untrailingslashit($region_host) . '/v1/checkouts/' . $checkout_id
		);
		
       $response = wp_remote_post(
			$update_url,
			[
				'timeout' => 60,
				'headers' => $this->build_auth_headers($credentials),
				'body'    => $body,
			]
		);

        if (is_wp_error($response)) {
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $code = $data['result']['code'] ?? '';

        if ('000.200.101' !== $code) {
            $desc = $data['result']['description'] ?? 'Failed to update checkout';
            $this->log_event('error', 'update_checkout_data failed: ' . $code . ' - ' . $desc);
            return new WP_Error('ncx_cp_update_failed', $desc);
        }

        return $data;
    }

    /**
     * Common helper: POST to /v1/checkouts and return the parsed response.
     */
    private function post_to_checkouts(array $credentials, array $body, ?bool $force_test = null) {
        // Issues a /v1/checkouts request and returns either the checkout payload or WP_Error.
        $region_host = $this->get_region_host($force_test);

        $response = wp_remote_post(
            $region_host . '/v1/checkouts',
            [
                'timeout' => 60,
                'headers' => $this->build_auth_headers($credentials),
                'body'    => $body,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $raw_body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        $data = json_decode($raw_body, true);

        if (!isset($data['id'])) {
            $code = $data['result']['code'] ?? 'unknown';
            $desc = $data['result']['description'] ?? 'No checkout ID returned';
            $this->log_event('error', 'Checkout session failed: ' . $code . ' - ' . $desc . ' (HTTP ' . $http_code . ')');
            // Return the actual OPP error so it's visible in the frontend for debugging.
            return new WP_Error('ncx_cp_no_checkout', $code . ': ' . $desc);
        }

        return $data;
    }

    // Retrieves payment details from the COPYandPAY API using a resource path.
    private function fetch_payment_details(string $resource_path, ?WC_Order $order = null) {
        // Pulls payment information from the resourcePath supplied by COPYandPAY.
        $use_test = $this->resolve_use_test($order);
        $credentials = $this->get_active_credentials($use_test);
        if (!$this->credentials_present($credentials)) {
            return new WP_Error('ncx_cp_missing_creds', __('CopyAndPay credentials are missing. Save them in the plugin settings.', 'ncx-cp-api'));
        }

        $region_host = $this->get_region_host($use_test);
        $normalized_path = '/' . ltrim($resource_path, '/');
        $url = $region_host . $normalized_path;
        $url = add_query_arg('entityId', $credentials['entity_id'], $url);

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 60,
                'headers' => $this->build_auth_headers($credentials),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['result']['code'])) {
            return new WP_Error('ncx_cp_invalid_result', __('Invalid response from COPYandPAY.', 'ncx-cp-api'));
        }

        return $data;
    }

    // Builds the Authorization headers shared by COPYandPAY API requests.
    private function build_auth_headers(array $settings): array {
        // Prepares the bearer Authorization header shared by all API requests.
        return [
            'Content-Type'  => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Authorization' => 'Bearer ' . $settings['access_token'],
        ];
    }

    /**
     * Deregister a stored card token at OPP when the customer deletes it in WooCommerce.
     * Mirrors nochexapi's tp_card_deregistration approach.
     */
    /**
     * @param WC_Payment_Token|null $token Token object passed by WooCommerce (may be incomplete after DB delete).
     */
    public function deregister_token_at_opp(int $token_id, $token = null): void {
        if (!$token instanceof WC_Payment_Token) {
            $token = WC_Payment_Tokens::get($token_id);
        }

        if (!$token instanceof WC_Payment_Token || $token->get_gateway_id() !== $this->id) {
            return;
        }

        $registration_id = (string) $token->get_token();
        if ('' === $registration_id) {
            return;
        }

        $token_is_test = $token->meta_exists(self::TOKEN_META_TEST_FLAG);

        // Run after the redirect response so My Account / admin delete never white-screens on a slow OPP call.
        add_action(
            'shutdown',
            function () use ($registration_id, $token_is_test, $token_id) {
                $this->perform_opp_token_deregistration($registration_id, $token_is_test, $token_id);
            },
            0
        );
    }

    private function perform_opp_token_deregistration(string $registration_id, bool $token_is_test, int $token_id): void {
        $credentials = $this->get_active_credentials($token_is_test);
        if (!$this->credentials_present($credentials)) {
            return;
        }

        $region_host = $this->get_region_host($token_is_test);
        $url = $region_host . '/v1/registrations/' . rawurlencode($registration_id);
        $url = add_query_arg('entityId', $credentials['entity_id'], $url);

        $response = wp_remote_request(
            $url,
            [
                'method'  => 'DELETE',
                'timeout' => 15,
                'headers' => $this->build_auth_headers($credentials),
            ]
        );

        if (is_wp_error($response)) {
            $this->log_event('error', 'Token deregistration failed', [
                'token_id' => $token_id,
                'error'    => $response->get_error_message(),
            ]);
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $code = is_array($data) ? (string) ($data['result']['code'] ?? '') : '';

        if (in_array($code, ['000.000.000', '000.100.110'], true)) {
            $this->log_event('info', 'Token deregistered at OPP', ['token_id' => $token_id]);
        } else {
            $this->log_event('warning', 'Token deregistration returned unexpected code', [
                'token_id' => $token_id,
                'code'     => $code,
            ]);
        }
    }

    // Normalises COPYandPAY result codes into success, pending, or failure states.
    private function determine_state(array $payload): string {
        // Maps COPYandPAY result codes to success, pending, or failure states.
        $code = (string) ($payload['result']['code'] ?? '');

        if (in_array($code, self::SUCCESS_CODES, true)) {
            return 'success';
        }

        if (in_array($code, self::PENDING_CODES, true)) {
            return 'pending';
        }

        return 'failure';
    }

    /**
     * Server-side verification of the payment response against order data.
     *
     * Mirrors nochexapi's parseResponseData checks: amount, currency, order_key, cart_hash.
     * The expected values were sent as customParameters during checkout session creation.
     *
     * @return true|WP_Error
     */
    private function verify_payment_against_order(WC_Order $order, array $payment) {
        // Confirms the payment payload matches the expected order metadata.
        $custom = $payment['customParameters'] ?? [];

        // Amount check.
        $expected_amount = $this->format_amount($order->get_total());
        $response_amount = (string) ($custom['SHOPPER_amount'] ?? '');
        if ('' !== $response_amount && $expected_amount !== $response_amount) {
            return new WP_Error('ncx_cp_amount_mismatch', sprintf(
                'Amount mismatch: expected %s, got %s',
                $expected_amount,
                $response_amount
            ));
        }

        // Currency check.
        $response_currency = (string) ($custom['SHOPPER_currency'] ?? '');
        if ('' !== $response_currency && $order->get_currency() !== $response_currency) {
            return new WP_Error('ncx_cp_currency_mismatch', sprintf(
                'Currency mismatch: expected %s, got %s',
                $order->get_currency(),
                $response_currency
            ));
        }

        // Order key check.
        $response_key = (string) ($custom['SHOPPER_order_key'] ?? '');
        if ('' !== $response_key && $order->get_order_key() !== $response_key) {
            return new WP_Error('ncx_cp_key_mismatch', 'Order key mismatch');
        }

        // Cart hash check.
        $response_hash = (string) ($custom['SHOPPER_cart_hash'] ?? '');
        if ('' !== $response_hash && $order->get_cart_hash() !== $response_hash) {
            return new WP_Error('ncx_cp_hash_mismatch', 'Cart hash mismatch — cart contents may have changed');
        }

        return true;
    }

    // Verifies that both the entity ID and access token have values.
    private function credentials_present(array $settings): bool {
        // Checks both entity ID and access token are populated.
        return !empty($settings['entity_id']) && !empty($settings['access_token']);
    }

    /**
     * Reads pre-created checkout ID from classic or block checkout POST data.
     */
    private function get_request_checkout_id(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (isset($_POST['ncx_cp_checkout_id'])) {
            $checkout_id = sanitize_text_field(wp_unslash($_POST['ncx_cp_checkout_id']));
            if ('' !== $checkout_id) {
                return $checkout_id;
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (isset($_POST['payment_data']) && is_array($_POST['payment_data'])) {
            foreach ($_POST['payment_data'] as $item) {
                if (!is_array($item) || !isset($item['key'], $item['value'])) {
                    continue;
                }
                if ('ncx_cp_checkout_id' !== $item['key']) {
                    continue;
                }
                $checkout_id = sanitize_text_field((string) $item['value']);
                if ('' !== $checkout_id) {
                    return $checkout_id;
                }
            }
        }

        return '';
    }

    /**
     * Reads active payment container from classic or block checkout POST data.
     */
    private function get_request_payment_container(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (isset($_POST['ncx_cp_payment_container'])) {
            $container = sanitize_text_field(wp_unslash($_POST['ncx_cp_payment_container']));
            if (in_array($container, ['card', 'registration'], true)) {
                return $container;
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (isset($_POST['payment_data']) && is_array($_POST['payment_data'])) {
            foreach ($_POST['payment_data'] as $item) {
                if (!is_array($item) || !isset($item['key'], $item['value'])) {
                    continue;
                }
                if ('ncx_cp_payment_container' !== $item['key']) {
                    continue;
                }
                $container = sanitize_text_field((string) $item['value']);
                if (in_array($container, ['card', 'registration'], true)) {
                    return $container;
                }
            }
        }

        return 'card';
    }

    /**
     * Reads save-card intent from classic or block checkout POST data.
     */
    private function get_request_create_registration_flag(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (isset($_POST['ncx_cp_create_registration']) && '1' === $_POST['ncx_cp_create_registration']) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (isset($_POST['payment_data']) && is_array($_POST['payment_data'])) {
            foreach ($_POST['payment_data'] as $item) {
                if (!is_array($item) || !isset($item['key'], $item['value'])) {
                    continue;
                }
                if ('ncx_cp_create_registration' === $item['key'] && '1' === (string) $item['value']) {
                    return true;
                }
            }
        }

        return false;
    }

    // Normalizes WooCommerce amounts to the decimal precision expected by COPYandPAY.
    private function format_amount($amount): string {
        // Formats numeric totals into the decimal precision expected by OPP.
        return wc_format_decimal($amount, wc_get_price_decimals(), false);
    }

    private function amount_below_minimum(float $amount): bool {
        return $amount < self::MIN_PAYMENT_AMOUNT;
    }

    private function get_minimum_payment_error_message(): string {
        return __('Amount less than 50p is not permitted.', 'ncx-cp-api');
    }

    /**
     * Ensures every request is flagged as an unscheduled CIT transaction (matches nochexapi defaults).
     */
    private function apply_standing_instruction_parameters(array &$body): void {
        $body['standingInstruction.source'] = 'CIT';
        $body['standingInstruction.mode']   = 'INITIAL';
        $body['standingInstruction.type']   = 'UNSCHEDULED';
    }

    /**
     * Append address fields to the request body, skipping empty values (matching nochexapi approach).
     */
    private function add_address_fields(array &$body, string $type, WC_Order $order): void {
        // Copies billing or shipping address data into the payload when available.
        $map = [
            'street1'  => 'get_' . $type . '_address_1',
            'street2'  => 'get_' . $type . '_address_2',
            'city'     => 'get_' . $type . '_city',
            'state'    => 'get_' . $type . '_state',
            'postcode' => 'get_' . $type . '_postcode',
            'country'  => 'get_' . $type . '_country',
        ];
        foreach ($map as $field => $method) {
            $value = $order->$method();
            if (!empty($value)) {
                $body["$type.$field"] = $this->sanitize_opp_field((string) $value, 128);
            }
        }
    }

    /**
     * TeraWallet recharge product ID from plugin settings.
     */
    private function get_terawallet_recharge_product_id(): int {
        $product_id = (int) get_option('woo_wallet_recharge_product', 0);
        if ($product_id <= 0) {
            $product_id = (int) get_option('_woo_wallet_recharge_product', 0);
        }

        return $product_id;
    }

    /**
     * Whether the cart contains a TeraWallet / Woo Wallet top-up (recharge) line.
     */
    private function cart_has_terawallet_recharge(): bool {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return false;
        }

        $recharge_product_id = $this->get_terawallet_recharge_product_id();

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            $variation_id = isset($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0;

            if ($recharge_product_id > 0 && ($product_id === $recharge_product_id || $variation_id === $recharge_product_id)) {
                return true;
            }

            $product = $cart_item['data'] ?? null;
            if ($product instanceof WC_Product && 'yes' === $product->get_meta('_woo_wallet_recharge', true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wallet top-up requires payment even when the recharge line total is still £0.
     *
     * @param bool $needs_payment WooCommerce default.
     */
    public function filter_cart_needs_payment_for_wallet_recharge(bool $needs_payment): bool {
        if ($needs_payment) {
            return true;
        }

        return $this->cart_has_terawallet_recharge();
    }

    /**
     * Sanitize text sent to OPP (strip HTML/control chars; preserve normal punctuation).
     */
    private function sanitize_opp_field(string $value, int $max_length = 255): string {
        $value = sanitize_text_field($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        if (function_exists('wc_clean')) {
            $value = wc_clean($value);
        }
        $value = preg_replace('/[^a-zA-Z0-9 ]/', '', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        $value = trim($value);
        if ('' === $value) {
            return '';
        }
        return mb_substr($value, 0, $max_length);
    }

    /**
     * Customer contact fields for COPYandPAY checkout payloads (order data unchanged in WooCommerce).
     */
    private function apply_opp_customer_fields_to_body(array &$body, WC_Order $order): void {
        $body['customer.email'] = sanitize_email((string) $order->get_billing_email());
        $body['customer.givenName'] = $this->sanitize_opp_field((string) $order->get_billing_first_name(), 64);
        $body['customer.surname'] = $this->sanitize_opp_field((string) $order->get_billing_last_name(), 64);
        $body['customer.ip'] = (string) $order->get_customer_ip_address();
        $body['customer.browserFingerprint.value'] = mb_substr((string) $order->get_customer_user_agent(), 0, 512);

        $holder = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $body['card.holder'] = $this->sanitize_opp_field($holder, 128);

        $phone = $order->get_billing_phone();
        if (!empty($phone)) {
            $body['customer.mobile'] = $this->sanitize_opp_field((string) $phone, 32);
        }
    }

    /**
     * Build a cart description string for the payload (mirrors nochexapi's getCartItemsOrderData).
     */
    private function get_cart_description(WC_Order $order): string {
        // Builds a concise description of the order line items for API metadata.
        $parts = [];
        foreach ($order->get_items('line_item') as $item) {
            $name = $this->sanitize_opp_field((string) $item->get_name(), 200);
            if ('' === $name) {
                $name = __('Order item', 'ncx-cp-api');
            }
            $parts[] = $name . ' - ' . $item->get_quantity() . ' x ' . $item->get_total();
        }

        return $this->sanitize_opp_field(implode(', ', $parts), 255);
    }

    // Returns the correct OPP base URL for the current region/mode combination.
    public function get_region_host(?bool $force_test = null): string {
        if (is_null($force_test)) {
            $use_test = (bool) $this->test_mode;
        } else {
            $use_test = (bool) $force_test;
        }

        if ($use_test) {
            return 'https://eu-test.oppwa.com';
        }

        return 'https://eu-prod.oppwa.com';
    }

    // Resolves the entity ID and token that should be used for the current mode.
    private function get_active_credentials(?bool $force_test = null): array {
        if (is_null($force_test)) {
            $use_test = (bool) $this->test_mode;
        } else {
            $use_test = (bool) $force_test;
        }

        if ($use_test) {
            return [
                'entity_id'    => self::TEST_ENTITY_ID,
                'access_token' => self::TEST_ACCESS_TOKEN,
            ];
        }

        return [
            'entity_id'    => $this->get_option('live_entity_id', ''),
            'access_token' => $this->get_option('live_access_token', ''),
        ];
    }

    // Quickly checks whether server logging is enabled for the requested level.
    private function is_log_level_enabled(string $level): bool {
        // Determines if a log entry at the given level should be emitted.
        if (!$this->server_logging) {
            return false;
        }
        return in_array(strtolower($level), $this->log_levels, true);
    }

    /**
     * One-time migration of legacy settings from the separate options page
     * (wp_options key "ncx_cp_api_settings") into the WooCommerce gateway settings.
     */
    private function maybe_migrate_legacy_settings(): void {
        $legacy = get_option('ncx_cp_api_settings');
        if (!is_array($legacy) || empty($legacy)) {
            return;
        }

        $keys = [
            'test_mode',
            'live_entity_id', 'live_access_token',
            'enable_console_log', 'enable_server_log', 'log_levels',
        ];

        $changed = false;
        foreach ($keys as $key) {
            if (!isset($legacy[$key])) {
                continue;
            }
            // Only migrate if the gateway setting is still at its default/empty value.
            $current = $this->get_option($key, '');
            if (is_array($current) ? !empty($current) : '' !== $current) {
                continue;
            }

            $value = $legacy[$key];
            // Convert booleans to WooCommerce 'yes'/'no' format.
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            $this->settings[$key] = $value;
            $changed = true;
        }

        if ($changed) {
            update_option(
                $this->get_option_key(),
                apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings),
                'yes'
            );
        }
        // Remove the legacy option regardless — prevents re-running migration.
        delete_option('ncx_cp_api_settings');
    }

    /**
     * One-time reset of title / description when they still contain old defaults.
     */
    private function maybe_refresh_stale_defaults(): void {
        $stale = [
            'title' => [
                'old' => ['Credit / Debit Card', 'Credit/Debit Card'],
                'new' => 'Pay with card',
            ],
            'description' => [
                'old' => ['Pay securely via COPYandPAY.', 'Pay securely through COPYandPAY.'],
                'new' => 'Checkout with Card',
            ],
        ];

        $changed = false;
        foreach ($stale as $key => $map) {
            $current = $this->get_option($key, '');
            if (in_array($current, $map['old'], true)) {
                $this->settings[$key] = $map['new'];
                $changed = true;
            }
        }

        if ($changed) {
            update_option(
                $this->get_option_key(),
                apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings),
                'yes'
            );
        }
    }

    /**
     * Stripe-like typography tokens shared with checkout JS (OPP iframe placeholders).
     *
     * @return array{fontFamily:string, text:string, muted:string, placeholder:string, sizeInput:string, sizeLabel:string, sizeReg:string}
     */
    public function get_checkout_typography_tokens(): array {
        return [
            'fontFamily'  => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
            'text'        => $this->inline_text_color,
            'muted'       => $this->inline_muted_color,
            'placeholder' => '#9ca3af',
            'sizeInput'   => '16px',
            'sizeLabel'   => '14px',
            'sizeReg'     => '14px',
        ];
    }

    // Registers checkout widget CSS (required before Blocks script dependency check).
    public function register_checkout_styles(): void {
        static $inline_added = false;

        $style_handle = 'ncx-cp-api-inline-style';
        if (wp_style_is($style_handle, 'registered')) {
            return;
        }

        wp_register_style($style_handle, false, [], NCX_CP_API::VERSION);
        if (!$inline_added) {
            wp_add_inline_style($style_handle, $this->get_checkout_widget_css());
            $inline_added = true;
        }
    }

    // Enqueues checkout widget CSS on classic checkout (register first if needed).
    public function enqueue_checkout_styles(): void {
        $this->register_checkout_styles();
        wp_enqueue_style('ncx-cp-api-inline-style');
    }

    /**
     * OPP COPYandPAY widget overrides — Stripe-like typography and saved-card layout.
     */
    private function get_checkout_widget_css(): string {
        $font = 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';

        $base = '.ncx-cp-inline-wrapper,#ncx-cp-blocks-container,.ncx-cp-inline-wrapper .wpwl-form,'
            . '#ncx-cp-blocks-container .wpwl-form,'
            . '.ncx-cp-inline-wrapper .wpwl-group-registration,'
            . '#ncx-cp-blocks-container .wpwl-group-registration,'
            . '.ncx-cp-inline-wrapper .ncx-cp-save-card,'
            . '#ncx-cp-blocks-container .ncx-cp-save-card{'
            . 'font-family:' . $font . '!important;line-height:1.4;}'
            . '.ncx-cp-inline-wrapper{position:relative;border:none;border-radius:0;background:transparent;padding:0;margin-top:0.25rem;box-shadow:none;}'
            . '.ncx-cp-inline-note{display:none;}'
            . '.ncx-cp-inline-frame{min-height:110px;}';

        // %1=primary, %2=glow, %3=border, %4=text, %5=radius, %6=muted, %7=font-family
        return $base . sprintf(
            ' .wpwl-group-brand,.wpwl-group-cardHolder,.wpwl-group-submit,.wpwl-button-pay:not([data-action="show-initial-forms"]){display:none!important;}'
            . ' .wpwl-form{max-width:100%%;margin:0;padding:0;}'
            . ' .wpwl-form-has-inputs{padding:0!important;border:none!important;background:transparent!important;border-radius:0!important;box-shadow:none!important;}'
            . ' div#wpwl-registrations{display:block;width:100%%;max-width:min(480px,100%%);margin-bottom:12px;box-sizing:border-box;}'
            . ' form.wpwl-form-registrations{max-width:100%%;margin:0 0 12px;}'
            . ' form.wpwl-form-card{margin-top:16px;}'
            . ' .ncx-cp-card-row{display:flex;flex-wrap:nowrap;width:100%%;max-width:min(480px,100%%);border:1px solid %3$s;border-radius:%5$s;overflow:hidden;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,0.04);transition:border-color .2s ease,box-shadow .2s ease;margin-bottom:10px;box-sizing:border-box;}'
            . ' .ncx-cp-card-row:focus-within{border-color:%1$s;box-shadow:0 0 0 3px %2$s;}'
            . ' .ncx-cp-card-row .wpwl-group{margin:0!important;padding:0!important;width:auto!important;box-sizing:border-box;}'
            . ' .ncx-cp-card-row .wpwl-group-cardNumber{flex:1 1 auto;min-width:0;}'
            . ' .ncx-cp-card-row .wpwl-group-expiry{flex:0 1 108px;min-width:92px;border-left:1px solid %3$s;}'
            . ' .ncx-cp-card-row .wpwl-group-cvv{flex:0 1 76px;min-width:64px;border-left:1px solid %3$s;}'
            . ' .wpwl-wrapper-cardNumber,.wpwl-wrapper-expiry,.wpwl-wrapper-cvv,.wpwl-wrapper-cardHolder{float:none!important;width:100%%!important;position:unset!important;}'
            . ' .wpwl-control-cardNumber,.wpwl-control-expiry,.wpwl-control-cvv,.wpwl-control-cardHolder{width:100%%!important;color:%4$s!important;font-family:%7$s!important;height:48px!important;border:none!important;background:#fff!important;margin:0!important;border-radius:0!important;box-shadow:none!important;font-size:16px!important;padding:0 14px!important;letter-spacing:0.01em!important;-webkit-appearance:none!important;appearance:none!important;}'
            . ' .wpwl-control-cardNumber:focus,.wpwl-control-expiry:focus,.wpwl-control-cvv:focus{outline:none!important;background:#fafbfc!important;}'
            . ' form.wpwl-form .form-row input::placeholder,input.wpwl-control-expiry::placeholder{color:#9ca3af!important;font-size:16px!important;}'
            . ' div.wpwl-hint{font-size:12px!important;text-align:left!important;color:#ef4444;margin:4px 0 0 0;}'
            . ' .wpwl-control{text-align:left;}'
            . ' .wpwl-group-registration{border:1px solid %3$s!important;border-radius:%5$s!important;margin:0 0 10px!important;padding:0!important;width:100%%;max-width:min(480px,100%%);background:#fff;box-shadow:0 1px 2px rgba(0,0,0,0.04);transition:border-color .2s ease,box-shadow .2s ease,background .2s ease;box-sizing:border-box;overflow:hidden;}'
            . ' .wpwl-group-registration.wpwl-selected{border-color:%1$s!important;background:#fafbfc;box-shadow:0 0 0 3px %2$s;}'
            . ' .wpwl-group-registration label{color:%4$s!important;}'
            . ' .wpwl-group-registration .wpwl-label{display:none!important;}'
            . ' .wpwl-wrapper-registration{float:none!important;width:auto!important;position:static!important;margin:0!important;}'
            . ' label.wpwl-registration{display:flex!important;flex-wrap:nowrap;align-items:center;gap:12px;width:100%%;padding:12px 14px!important;margin:0!important;box-sizing:border-box;cursor:pointer;line-height:1.4;}'
            . ' .wpwl-wrapper-registration-registrationId{flex:0 0 auto;padding:0!important;}'
            . ' .wpwl-wrapper-registration-registrationId input[type=radio]{margin:0!important;accent-color:%1$s;}'
            . ' .wpwl-wrapper-registration-brand,.wpwl-wrapper-registration-cardHolder{display:none!important;}'
            . ' .wpwl-wrapper-registration-details{flex:1 1 auto;min-width:0;padding:0!important;margin:0!important;font-size:15px!important;font-weight:500;letter-spacing:0.02em;color:%4$s!important;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
            . ' .wpwl-wrapper-registration-cvv{flex:0 0 96px;width:96px!important;max-width:96px!important;padding:0!important;margin:0!important;}'
            . ' div.wpwl-wrapper-registration-cvv{line-height:1.4;}'
            . ' div.wpwl-wrapper-registration .wpwl-control-cvv{width:100%%!important;margin:0!important;height:44px!important;border:1px solid %3$s!important;border-radius:8px!important;background:#fff!important;font-size:16px!important;padding:0 12px!important;box-shadow:none!important;box-sizing:border-box;}'
            . ' div.wpwl-wrapper-registration .wpwl-control-cvv:focus{border-color:%1$s!important;outline:none!important;background:#fafbfc!important;}'
            . ' div.wpwl-group-registration{font-size:14px!important;}'
            . ' div.wpwl-container{margin-bottom:4px;}'
            . ' label.ncx-cp-frame-label{color:%6$s!important;font-size:14px!important;font-weight:500;display:block;text-align:left;line-height:1.4;margin:16px 0 8px;max-width:100%%;letter-spacing:0;}'
            . ' span.ncx-cp-required{color:#ef4444;font-weight:400;}'
            . ' #wpwlDynBrand{width:30px;padding:13px 10px;position:absolute;right:0;top:0;}'
            . ' #wpwlDynBrandImg{border-radius:unset;margin:0!important;float:right;max-height:18px;}'
            . ' .wpwl-button-pay[data-action="show-initial-forms"]{display:inline-block!important;background:transparent!important;color:%1$s!important;border:1px solid %3$s!important;border-radius:%5$s!important;padding:8px 16px!important;font-size:14px!important;font-weight:500!important;font-family:%7$s!important;cursor:pointer!important;margin:8px 0 12px!important;transition:background .2s ease,border-color .2s ease!important;float:none!important;text-align:center!important;max-width:480px!important;}'
            . ' .wpwl-button-pay[data-action="show-initial-forms"]:hover{background:%2$s!important;border-color:%1$s!important;}'
            . ' .ncx-cp-save-card{margin:10px 0 0!important;padding:0!important;width:100%%;max-width:min(480px,100%%);box-sizing:border-box;}'
            . ' .ncx-cp-save-card>label,.ncx-cp-save-card label.woocommerce-form__label{display:flex!important;align-items:flex-start!important;gap:10px!important;margin:0!important;padding:12px 14px!important;border:1px solid %3$s!important;border-radius:%5$s!important;background:#fff!important;color:%4$s!important;font-size:14px!important;font-weight:400!important;line-height:1.5!important;cursor:pointer!important;float:none!important;width:100%%!important;box-sizing:border-box!important;transition:border-color .2s ease,box-shadow .2s ease,background .2s ease!important;}'
            . ' .ncx-cp-save-card label:hover{border-color:%1$s!important;background:#fafbfc!important;}'
            . ' .ncx-cp-save-card label:has(input:checked){border-color:%1$s!important;background:#fafbfc!important;box-shadow:0 0 0 3px %2$s!important;}'
            . ' .ncx-cp-save-card input[type=checkbox],.ncx-cp-save-card .woocommerce-form__input-checkbox{flex:0 0 auto!important;width:18px!important;height:18px!important;min-width:18px!important;margin:2px 0 0!important;padding:0!important;float:none!important;position:static!important;accent-color:%1$s!important;border-radius:4px!important;cursor:pointer!important;box-shadow:none!important;}'
            . ' .ncx-cp-save-card span{display:block;flex:1 1 auto;min-width:0;color:%6$s!important;font-size:14px!important;line-height:1.5!important;}'
            . ' @media (max-width:520px){.ncx-cp-inline-wrapper .ncx-cp-card-row,#ncx-cp-blocks-container .ncx-cp-card-row{max-width:100%%!important;width:100%%!important;flex-wrap:wrap;}.ncx-cp-inline-wrapper .ncx-cp-card-row .wpwl-group-cardNumber,#ncx-cp-blocks-container .ncx-cp-card-row .wpwl-group-cardNumber{flex:1 1 100%%;width:100%%!important;min-width:0;border-bottom:1px solid %3$s;}.ncx-cp-inline-wrapper .ncx-cp-card-row .wpwl-group-expiry,#ncx-cp-blocks-container .ncx-cp-card-row .wpwl-group-expiry{flex:1 1 50%%;min-width:0;border-left:none;}.ncx-cp-inline-wrapper .ncx-cp-card-row .wpwl-group-cvv,#ncx-cp-blocks-container .ncx-cp-card-row .wpwl-group-cvv{flex:1 1 50%%;min-width:0;border-left:1px solid %3$s;}.ncx-cp-inline-wrapper div#wpwl-registrations,#ncx-cp-blocks-container div#wpwl-registrations,.ncx-cp-inline-wrapper .wpwl-group-registration,#ncx-cp-blocks-container .wpwl-group-registration{max-width:100%%!important;}.ncx-cp-inline-wrapper label.wpwl-registration,#ncx-cp-blocks-container label.wpwl-registration{flex-wrap:wrap;gap:10px 12px;}.ncx-cp-inline-wrapper .wpwl-wrapper-registration-details,#ncx-cp-blocks-container .wpwl-wrapper-registration-details{flex:1 1 calc(100%% - 36px);white-space:normal;}.ncx-cp-inline-wrapper .wpwl-wrapper-registration-cvv,#ncx-cp-blocks-container .wpwl-wrapper-registration-cvv{flex:1 1 100%%;width:100%%!important;max-width:100%%!important;}.ncx-cp-inline-wrapper .ncx-cp-save-card,#ncx-cp-blocks-container .ncx-cp-save-card{max-width:100%%!important;width:100%%!important;}}'
            . ' @media (max-width:380px){.ncx-cp-inline-wrapper .ncx-cp-card-row .wpwl-group-expiry,.ncx-cp-inline-wrapper .ncx-cp-card-row .wpwl-group-cvv,#ncx-cp-blocks-container .ncx-cp-card-row .wpwl-group-expiry,#ncx-cp-blocks-container .ncx-cp-card-row .wpwl-group-cvv{flex:1 1 100%%;border-left:none;}.ncx-cp-inline-wrapper .ncx-cp-card-row .wpwl-group-cvv,#ncx-cp-blocks-container .ncx-cp-card-row .wpwl-group-cvv{border-top:1px solid %3$s;}.ncx-cp-inline-wrapper .wpwl-control-cardNumber,.ncx-cp-inline-wrapper .wpwl-control-expiry,.ncx-cp-inline-wrapper .wpwl-control-cvv,#ncx-cp-blocks-container .wpwl-control-cardNumber,#ncx-cp-blocks-container .wpwl-control-expiry,#ncx-cp-blocks-container .wpwl-control-cvv{padding:0 12px!important;}}',
            esc_attr($this->primary_color),
            esc_attr($this->hex_to_rgba($this->primary_color, 0.12)),
            esc_attr($this->inline_border_color),
            esc_attr($this->inline_text_color),
            esc_attr($this->inline_border_radius),
            esc_attr($this->inline_muted_color),
            esc_attr($this->get_checkout_typography_tokens()['fontFamily'])
        );
    }

    // Whether checkout widget assets should load on the current request.
    private function should_enqueue_checkout_assets(): bool {
        if (is_checkout() || is_wc_endpoint_url('order-pay')) {
            return true;
        }

        if (function_exists('has_block')) {
            return has_block('woocommerce/checkout') || has_block('woocommerce/cart');
        }

        return false;
    }

    // Enqueues all scripts, styles, and inline data required for the checkout UI.
    public function maybe_enqueue_assets(): void {
        if (!$this->should_enqueue_checkout_assets()) {
            return;
        }

        $this->enqueue_checkout_styles();

        if (is_checkout()) {
            $script_handle = 'ncx-cp-api-inline';
            wp_enqueue_script(
                $script_handle,
                plugins_url('../assets/js/checkout-inline.js', __FILE__),
                ['jquery'],
                NCX_CP_API::VERSION,
                true
            );

            wp_localize_script(
                $script_handle,
                'ncxCpInline',
                [
                    'ajaxUrl'            => admin_url('admin-ajax.php'),
                    'nonce'              => wp_create_nonce('ncx_cp_checkout_nonce'),
                    'regionHost'         => $this->get_region_host(),
                    'environment'        => $this->test_mode ? 'test' : 'live',
                    'brands'             => self::PAYMENT_BRANDS,
                    'gatewayId'          => $this->id,
                    'createRegistration' => is_user_logged_in() ? '1' : '0',
                    'allowCardSaving'    => is_user_logged_in() ? '1' : '0',
                    'loggedIn'           => is_user_logged_in() ? '1' : '0',
                    'typography'         => $this->get_checkout_typography_tokens(),
                ]
            );
        }

        if ($this->console_logging) {
            wp_enqueue_script('jquery');
            wp_add_inline_script(
                'jquery',
                'window.ncxCpApiLog=window.ncxCpApiLog||function(){if(window.console){console.log.apply(console,arguments);}};',
                'before'
            );
        }
    }

    // Ensures the duplicate-attempt cron is scheduled or unscheduled as needed.
    public function maybe_schedule_duplicate_guard(): void {
        $scheduled = wp_next_scheduled(self::DUPE_CRON_HOOK);
        if ($this->enable_dupe_check) {
            if (!$scheduled) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::DUPE_CRON_HOOK);
            }
        } elseif ($scheduled) {
            wp_clear_scheduled_hook(self::DUPE_CRON_HOOK);
        }
    }

    // Scans recent pending orders for matching fingerprints to flag duplicates.
    public function run_duplicate_guard(): void {
        if (!$this->enable_dupe_check) {
            return;
        }

        $orders = wc_get_orders([
            'limit' => 20,
            'status' => ['pending', 'on-hold'],
            'orderby' => 'date',
            'order' => 'DESC',
            'date_created' => '>' . gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS * 2),
        ]);

        $fingerprints = [];
        foreach ($orders as $order) {
            $fingerprint = md5(strtolower((string) $order->get_billing_email()) . '|' . $order->get_total());
            if (isset($fingerprints[$fingerprint])) {
                $this->log_event('warning', 'Potential duplicate payment attempt detected', [
                    'order_a' => $fingerprints[$fingerprint],
                    'order_b' => $order->get_id(),
                    'total' => $order->get_total(),
                ]);
            } else {
                $fingerprints[$fingerprint] = $order->get_id();
            }
        }
    }

    // Saves a registration token on the customer when card saving is enabled.
    // Match nochexapi storeRgToken: store token when registrationId is present in response.
    // The OPP widget checkbox controls whether OPP returns
    // a registrationId, and we only call this when one is present.
    private function maybe_tokenize_card(WC_Order $order, array $payment): void {
        if (!$order->get_user_id()) {
            return;
        }

        $registration_id = $payment['registrationId'] ?? '';
        $card = $payment['card'] ?? [];
        if ('' === $registration_id || empty($card)) {
            $this->log_event('info', 'Skipping tokenization due to missing registration data', ['order_id' => $order->get_id()]);
            return;
        }

        $is_test_order = $this->order_uses_test_mode($order);
        $existing = $this->get_tokens_for_environment($order->get_user_id(), $is_test_order);
        foreach ($existing as $token) {
            if ($token->get_token() === $registration_id) {
                return;
            }
        }

        $digits = preg_replace('/\D+/', '', (string) ($card['last4Digits'] ?? $card['number'] ?? ''));
        $last4 = $digits ? substr($digits, -4) : '0000';

        $token = new WC_Payment_Token_CC();
        $token->set_token($registration_id);
        $token->set_gateway_id($this->id);
        $token->set_user_id($order->get_user_id());
        $token->set_last4($last4);
        $token->set_expiry_month(sprintf('%02d', (int) ($card['expiryMonth'] ?? 0)));
        $token->set_expiry_year((int) ($card['expiryYear'] ?? 0));
        $token->set_card_type(strtolower((string) ($card['brand'] ?? $card['paymentBrand'] ?? 'card')));

        if (!empty($card['holder'])) {
            $token->add_meta_data('holder', sanitize_text_field($card['holder']), true);
        }
        if (!empty($card['paymentBrand'])) {
            $token->add_meta_data('paymentBrand', sanitize_text_field($card['paymentBrand']), true);
        }
        if (!empty($card['bin'])) {
            $token->add_meta_data('bin', substr(preg_replace('/\D+/', '', (string) $card['bin']), 0, 6), true);
        }

        if ($is_test_order) {
            $token->add_meta_data(self::TOKEN_META_TEST_FLAG, '1', true);
        } else {
            $token->delete_meta_data(self::TOKEN_META_TEST_FLAG);
        }

        $token->save();

        $this->log_event('info', 'Stored COPYandPAY registration token', [
            'order_id' => $order->get_id(),
            'user_id' => $order->get_user_id(),
        ]);
    }

    private function order_uses_test_mode(WC_Order $order): bool {
        return $this->resolve_use_test($order);
    }

    /**
     * Resolves test vs live for OPP API calls. Order meta wins, then checkout session, then gateway setting.
     */
    private function resolve_use_test(?WC_Order $order = null): bool {
        if ($order instanceof WC_Order) {
            $stored = (string) $order->get_meta(self::ENVIRONMENT_META_KEY, true);
            if ('test' === $stored) {
                return true;
            }
            if ('live' === $stored) {
                return false;
            }
        }

        $session_env = $this->get_checkout_session_environment();
        if (null !== $session_env) {
            return 'test' === $session_env;
        }

        return (bool) $this->test_mode;
    }

    /**
     * Blocks place-order when Phase 1 session env no longer matches gateway (stale checkout ID).
     *
     * @return WP_Error|null
     */
    private function validate_checkout_environment_consistency(?WC_Order $order = null) {
        if ($order instanceof WC_Order) {
            $stored = (string) $order->get_meta(self::ENVIRONMENT_META_KEY, true);
            if ('test' === $stored || 'live' === $stored) {
                return null;
            }
        }

        $session_env = $this->get_checkout_session_environment();
        if (null === $session_env) {
            return null;
        }

        $gateway_env = $this->test_mode ? 'test' : 'live';
        if ($session_env !== $gateway_env) {
            return new WP_Error(
                'ncx_cp_env_mismatch',
                __('Payment settings changed during checkout. Please refresh the page and try again.', 'ncx-cp-api')
            );
        }

        return null;
    }

    private function get_checkout_session_environment(): ?string {
        if (!function_exists('WC') || !WC()->session) {
            return null;
        }

        $env = WC()->session->get(self::SESSION_ENVIRONMENT_KEY);
        if (!is_string($env)) {
            return null;
        }

        return in_array($env, ['test', 'live'], true) ? $env : null;
    }

    private function set_checkout_session_environment(string $environment): void {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        if (!in_array($environment, ['test', 'live'], true)) {
            return;
        }

        WC()->session->set(self::SESSION_ENVIRONMENT_KEY, $environment);
    }

    private function get_tokens_for_environment(int $user_id, ?bool $force_test = null): array {
        if ($user_id <= 0) {
            return [];
        }

        $tokens = WC_Payment_Tokens::get_tokens([
            'user_id'    => $user_id,
            'gateway_id' => $this->id,
            'limit'      => 100,
        ]);

        if (empty($tokens) || !is_array($tokens)) {
            return [];
        }

        return array_values($this->filter_tokens_for_environment($tokens, $force_test));
    }

    private function filter_tokens_for_environment(array $tokens, ?bool $force_test = null): array {
        $use_test = is_null($force_test) ? $this->test_mode : (bool) $force_test;
        $filtered = [];

        foreach ($tokens as $token) {
            if (!$token instanceof WC_Payment_Token) {
                continue;
            }

            $is_test_token = $token->meta_exists(self::TOKEN_META_TEST_FLAG);

            if ($use_test && !$is_test_token) {
                continue;
            }

            if (!$use_test && $is_test_token) {
                continue;
            }

            $filtered[$token->get_id()] = $token;
        }

        return $filtered;
    }

    // WooCommerce filter: strip our tokens everywhere except My Account and admin.
    // OPP handles saved cards natively via registrations – WC's built-in tokenisation
    // UI must never appear on the checkout (classic or blocks).
    public function filter_tokens_by_environment(array $tokens, int $customer_id, string $gateway_id): array {
        if ($gateway_id !== '' && $gateway_id !== $this->id) {
            return $tokens;
        }

        // My Account pages (payment-methods, etc.) – show tokens for active environment.
        if (function_exists('is_account_page') && is_account_page()) {
            return $this->filter_tokens_for_environment($tokens);
        }

        // Admin pages – show tokens for active environment.
        if (is_admin()) {
            return $this->filter_tokens_for_environment($tokens);
        }

        // Everywhere else (checkout, blocks hydration, REST/Store API) – strip our tokens.
        $id = $this->id;
        return array_filter($tokens, static function ($token) use ($id) {
            return $token->get_gateway_id() !== $id;
        });
    }

    // Remove "Make default" from the token actions on My Account > Payment Methods.
    public function filter_token_list_actions(array $item, WC_Payment_Token $token): array {
        if ($token->get_gateway_id() !== $this->id) {
            return $item;
        }
        unset($item['actions']['default']);
        return $item;
    }

    public function hide_gateway_on_add_payment_method_page(array $gateways): array {
        if (function_exists('is_add_payment_method_page') && is_add_payment_method_page()) {
            unset($gateways[$this->id]);
        }

        return $gateways;
    }

    // Validates hexadecimal color strings and falls back to a safe default.
    private function sanitize_color(string $color): string {
        $color = trim($color);
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return $color;
        }

        return '#111827';
    }

    // Normalises the border radius input into a valid CSS length string.
    private function sanitize_radius(string $value): string {
        $value = trim($value);
        if ('' === $value) {
            return '12px';
        }

        if (preg_match('/^\d+(\.\d+)?(px|em|rem|%)$/', $value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return $value . 'px';
        }

        return '12px';
    }

    // Converts hex color codes into rgba() strings for CSS helpers.
    private function hex_to_rgba(string $hex, float $alpha): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $alpha = min(max($alpha, 0), 1);
        $int = hexdec($hex ?: '000000');
        $r = ($int >> 16) & 255;
        $g = ($int >> 8) & 255;
        $b = $int & 255;
        return sprintf('rgba(%d,%d,%d,%.2f)', $r, $g, $b, $alpha);
    }

    // Maps the chosen checkout theme to a curated set of colors.
    private function resolve_theme_palette(string $theme): array {
        $palettes = [
            'light' => [
                'primary' => '#111827',
                'accent'  => '#F2F4F7',
                'note'    => '#111827',
                'text'    => '#111827',
                'muted'   => '#6b7280',
                'border'  => '#E5E7EB',
            ],
            'dark' => [
                'primary' => '#0F172A',
                'accent'  => '#1E293B',
                'note'    => '#111827',
                'text'    => '#111827',
                'muted'   => '#6b7280',
                'border'  => '#334155',
            ],
            'soft_gray' => [
                'primary' => '#1F2933',
                'accent'  => '#E5E7EB',
                'note'    => '#111827',
                'text'    => '#111827',
                'muted'   => '#6b7280',
                'border'  => '#CBD5F5',
            ],
            'calm' => [
                'primary' => '#0F766E',
                'accent'  => '#D1FAE5',
                'note'    => '#111827',
                'text'    => '#111827',
                'muted'   => '#6b7280',
                'border'  => '#99F6E4',
            ],
        ];

        if (!isset($palettes[$theme])) {
            $theme = 'light';
        }

        return $palettes[$theme];
    }

    /**
     * Build 3DS v2 parameters matching nochexapi's get_threed_version_two_data().
     * nochexapi only sends ReqAuthMethod by default (threeDv2Params = ['ReqAuthMethod']).
     */
    private function build_three_ds_parameters(WC_Order $order): array {
        if (!self::THREE_DS_ENABLED) {
            return [];
        }

        $params = [];

        // ReqAuthMethod: 01 = guest, 02 = logged-in (matches nochexapi exactly).
        if (is_user_logged_in()) {
            $params['ReqAuthMethod'] = '02';
        } else {
            $params['ReqAuthMethod'] = '01';
        }

        return $params;
    }

    // determine_account_age_indicator removed — nochexapi only sends ReqAuthMethod by default.

    // Counts how many successful orders a customer placed within the 3DS lookback window.
    private function count_recent_orders(int $user_id): int {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $window_start = gmdate('Y-m-d H:i:s', time() - (self::THREE_DS_WINDOW_DAYS * DAY_IN_SECONDS));
        $orders = wc_get_orders([
            'return' => 'ids',
            'customer_id' => $user_id,
            'status' => ['processing', 'completed', 'on-hold'],
            'date_created' => '>' . $window_start,
            'limit' => -1,
        ]);

        return is_array($orders) ? count($orders) : 0;
    }

    // Sends gateway messages to the WooCommerce logger when the level is enabled.
    private function log_event(string $level, string $message, array $context = []): void {
        if (!$this->is_log_level_enabled($level)) {
            return;
        }

        $this->get_logger()->log($level, $message, array_merge(['source' => $this->id], $context));
    }

    // Lazily instantiates and memoizes the WooCommerce logger instance.
    private function get_logger(): WC_Logger {
        if (!$this->logger) {
            $this->logger = wc_get_logger();
        }

        return $this->logger;
    }
}
