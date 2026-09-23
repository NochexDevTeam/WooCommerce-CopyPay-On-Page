<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/trait-ncx-cp-api-order-coordinator.php';
require_once __DIR__ . '/trait-ncx-cp-api-product-applepay.php';
require_once __DIR__ . '/trait-ncx-cp-api-product-googlepay.php';
require_once __DIR__ . '/class-ncx-cp-api-apple-pay-domain.php';

class NCX_CP_API_Gateway extends WC_Payment_Gateway {
    use NCX_CP_API_Order_Coordinator;
    use NCX_CP_API_Product_Apple_Pay;
    use NCX_CP_API_Product_Google_Pay;
    private const PLUGIN_PREFIX = 'ncx_cp_';
    private const CHECKOUT_META_KEY = '_ncx_cp_checkout_id';
    private const ENVIRONMENT_META_KEY = '_ncx_cp_environment';
    private const SESSION_ENVIRONMENT_KEY = 'ncx_cp_checkout_environment';
    private const APPLE_PAY_PENDING_ORDER_SESSION_KEY = 'ncx_cp_applepay_pending_order_id';
    private const TOKEN_META_TEST_FLAG = 'test';
    // Marks notices this plugin queued, so they can be drained without
    // disturbing any other extension's.
    private const NOTICE_MARKER = 'ncx_cp_notice';
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
    // Outcomes OPP documents as not representing a payment attempt: the shopper
    // or merchant abandoned the flow, so no authorisation exists and retrying is
    // safe. 100.390.124 is the 3DS2 equivalent ("authentication is cancelled or
    // abandoned") and is listed here rather than with the declines below so an
    // abandoned challenge keeps the softer handling: no failed status, and so no
    // Failed-order email to administrators every time a shopper backs out.
    // Codes per https://playground.sandbox-next.peachpayments.com/docs/response-codes
    private const DEFINITIVE_CANCEL_CODES = [
        '100.390.124',
        '100.396.101',
        '100.396.102',
        '100.396.103',
        '100.396.104',
        '100.396.106',
        '100.396.201',
        '100.397.101',
    ];
    // 100.390.* is the 3DS authentication rejection family (.100 to .123).
    // Authentication failed, so the payment never reached authorisation and the
    // shopper may retry. Evaluated after DEFINITIVE_CANCEL_CODES, which claims
    // 100.390.124 out of this range.
    private const DEFINITIVE_DECLINE_PREFIXES = [
        '100.390.',
        '800.100.',
    ];
    // Returned when a checkout exists but carries no payment, i.e. the shopper
    // was never charged against it. ASSUMPTION pending confirmation against
    // authoritative OPP documentation; anything not listed here stays ambiguous.
    private const NO_PROVIDER_PAYMENT_CODES = [
        '200.300.404',
    ];

    // ── Hardcoded (matches nochexapi pattern) ──────────────────────
    private const PAYMENT_TYPE          = 'DB';
    public const PAYMENT_BRANDS         = 'VISA MASTER';
    public const APPLE_PAY_BRAND        = 'APPLEPAY';
    public const GOOGLE_PAY_BRAND       = 'GOOGLEPAY';
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
    private string $inline_surface_color;
    private string $inline_surface_alt_color;
    private string $inline_on_surface_color;
    private string $inline_on_surface_muted_color;
    private string $inline_border_radius;
    private bool $allow_card_saving;
    private bool $enable_apple_pay;
    private bool $enable_google_pay;
    private bool $apple_pay_logging;
    private bool $google_pay_logging;
    private bool $console_logging;
    private bool $server_logging;
    private array $log_levels;
    private ?WC_Logger $logger = null;
    private bool $cart_recalculated_for_apple_pay = false;
    /** @var array<int, string> Notices drained from the session for the client to render. */
    private array $pending_shopper_notices = [];

    // Initializes the gateway settings, defaults, and runtime hooks.
    public function __construct() {
        // Bootstraps gateway defaults, reads settings, and wires runtime hooks.
        $this->id = 'ncx_cp_api';
        $this->method_title = __('Nochex CopyandPay On Page', 'ncx-cp-api');
        $this->method_description = __('Take secure card, Apple Pay, and Google Pay payments on your checkout page.', 'ncx-cp-api');
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
        $this->inline_surface_color = $this->sanitize_color($palette['surface'] ?? '#FFFFFF');
        $this->inline_surface_alt_color = $this->sanitize_color($palette['surface_alt'] ?? '#FAFBFC');
        $this->inline_on_surface_color = $this->sanitize_color($palette['on_surface'] ?? '#111827');
        $this->inline_on_surface_muted_color = $this->sanitize_color($palette['on_surface_muted'] ?? '#6B7280');
        $this->inline_border_radius = $this->sanitize_radius($this->get_option('inline_border_radius', '12px'));
        $this->allow_card_saving = 'yes' === $this->get_option('enable_tokenization', 'yes');
        $this->enable_apple_pay = 'yes' === $this->get_option('enable_apple_pay', 'no');
        $this->enable_google_pay = 'yes' === $this->get_option('enable_google_pay', 'no');
        $this->apple_pay_logging = 'yes' === $this->get_option('enable_apple_pay_log', 'no');
        $this->google_pay_logging = 'yes' === $this->get_option('enable_google_pay_log', 'no');

        // Both depend on the wallet flags above, so they are resolved last.
        $this->maybe_sync_generated_title();
        $this->title = $this->get_option('title', $this->generate_payment_method_title());
        $this->description = $this->resolve_checkout_description();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('admin_enqueue_scripts', [$this, 'maybe_enqueue_admin_assets']);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'render_receipt'], 10, 1);
        add_action('woocommerce_api_' . self::CALLBACK_ACTION, [$this, 'handle_result']);
        add_action('woocommerce_api_' . self::NOTIFICATION_ACTION, [$this, 'handle_notification']);
        add_action('woocommerce_api_' . $this->id, [$this, 'handle_apc']);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets']);
        add_action('init', [$this, 'maybe_schedule_duplicate_guard']);
        add_action(self::DUPE_CRON_HOOK, [$this, 'run_duplicate_guard']);
        $this->register_order_coordinator_hooks();

        // Deregister token at OPP when customer deletes a saved card.
        add_action('woocommerce_payment_token_deleted', [$this, 'deregister_token_at_opp'], 10, 2);

        // Filter saved payment tokens so only the current environment's tokens are shown.
        add_filter('woocommerce_get_customer_payment_tokens', [$this, 'filter_tokens_by_environment'], 10, 3);

        // Remove "Make default" from the Payment Methods page – only delete is allowed.
        add_filter('woocommerce_payment_methods_list_item', [$this, 'filter_token_list_actions'], 10, 2);

        // Core hides the My Account "Payment methods" link unless a gateway declares
        // tokenization support, which this one deliberately does not.
        add_filter('woocommerce_account_menu_items', [$this, 'restore_payment_methods_menu_item'], 10, 2);

        // Hide the gateway on "Add payment method" – cards are saved during checkout only.
        add_filter('woocommerce_available_payment_gateways', [$this, 'hide_gateway_on_add_payment_method_page']);

        // TeraWallet top-up: cart may be £0 until amount is entered; still require payment UI.
        add_filter('woocommerce_cart_needs_payment', [$this, 'filter_cart_needs_payment_for_wallet_recharge'], 20);

        $this->register_product_apple_pay_hooks();
        $this->register_product_google_pay_hooks();
    }

    // Declares the WooCommerce settings schema rendered on the admin screen.
    public function init_form_fields(): void {
        // Defines the configurable admin fields exposed in WooCommerce settings.
        $this->form_fields = [
            // ── General ───────────────────────────────────────────────────
            'enabled' => [
                'title' => __('Accept online payments', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Show card payments at checkout', 'ncx-cp-api'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'ncx-cp-api'),
                'type' => 'text',
                'default' => __('Pay with card', 'ncx-cp-api'),
                'description' => __('Shown to shoppers as the payment method name. Left at the suggested wording, it follows the wallets you switch on — for example "Pay with card, Apple Pay or Google Pay". Type your own wording to keep it fixed.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            'show_description' => [
                'title' => __('Description', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Show a short helper line under the payment method name.', 'ncx-cp-api'),
                'default' => 'yes',
                'description' => __('Turn this off to reclaim the space in the payment box. The wording below is kept, so you can switch it back on without retyping it.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description wording', 'ncx-cp-api'),
                'type' => 'text',
                'default' => __('Pay with Card', 'ncx-cp-api'),
                'description' => __('Short helper line that appears under the name at checkout.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            // Merchant-configurable floor for card/Apple Pay; WooCommerce clamps saves below 50p.
            'minimum_payment_amount' => [
                'title' => __('Minimum payment amount', 'ncx-cp-api'),
                'type' => 'number',
                'default' => '0.5',
                'custom_attributes' => [
                    'min' => '0.5',
                    'step' => '0.01',
                ],
                'description' => sprintf(
                    /* translators: %s: absolute minimum amount (50p) */
                    __('Orders below this total cannot pay with card or Apple Pay. Cannot be set lower than %s.', 'ncx-cp-api'),
                    wc_price(self::MIN_PAYMENT_AMOUNT, ['html_format' => false])
                ),
                'desc_tip' => true,
            ],

            // ── Environment & Credentials ──────────────────────────────────
            'environment_heading' => [
                'title' => '<span id="ncx-cp-heading-account">' . __('Payment account', 'ncx-cp-api') . '</span>',
                'type' => 'title',
                'description' => __('Use Test while setting up. Choose Live when you are ready to take real payments.', 'ncx-cp-api'),
            ],
            'test_mode' => [
                'title' => __('Payment mode', 'ncx-cp-api'),
                'type' => 'select',
                'default' => 'yes',
                'options' => [
                    'no'  => __('Live — take real payments', 'ncx-cp-api'),
                    'yes' => __('Test — no real money', 'ncx-cp-api'),
                ],
                'description' => __('Start in Test. Switch to Live only after your payment account has been approved.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            'live_entity_id' => [
                'title' => __('Live account ID', 'ncx-cp-api'),
                'type' => 'text',
                'default' => '',
                'description' => __('Copy this from the account details supplied by your payment provider.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            'live_access_token' => [
                'title' => __('Live access key', 'ncx-cp-api'),
                'type' => 'text',
                'default' => '',
                'description' => __('Paste the private access key supplied with your live account.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            // Test credentials are hardcoded (matching nochexapi) — no admin fields needed.

            // ── Checkout Appearance ───────────────────────────────────────
            'appearance_heading' => [
                'title' => '<span id="ncx-cp-heading-appearance">' . __('Checkout appearance', 'ncx-cp-api') . '</span>',
                'type' => 'title',
                'description' => __('Optional wording and appearance controls.', 'ncx-cp-api'),
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
            'enable_tokenization' => [
                'title' => __('Saved cards (tokenisation)', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Allow logged-in customers to save cards for faster checkout.', 'ncx-cp-api'),
                'default' => 'yes',
                'description' => __('When disabled, the save-card option and stored card list are hidden. Guests never see tokenisation.', 'ncx-cp-api'),
            ],
            'enable_apple_pay' => [
                'title' => __('Offer Apple Pay', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Show Apple Pay on supported Apple devices', 'ncx-cp-api'),
                'default' => 'no',
                'description' => __('After enabling this, complete the short domain-file setup shown below.', 'ncx-cp-api'),
            ],
            'apple_pay_display_name' => [
                'title' => __('Apple Pay display name', 'ncx-cp-api'),
                'type' => 'text',
                'default' => '',
                'description' => __('Store name shown on the Apple Pay sheet (max 64 characters). Leave blank to use the WordPress site name.', 'ncx-cp-api'),
            ],
            'enable_apple_pay_product_page' => [
                'title' => __('Apple Pay on product pages', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Allow registered customers with complete account addresses to buy instantly with Apple Pay on single product pages.', 'ncx-cp-api'),
                'default' => 'no',
                'description' => __('Guests and customers with incomplete billing or shipping details are not shown the button. They can still pay at checkout. Apple devices only.', 'ncx-cp-api'),
                'desc_tip' => false,
            ],
            'enable_apple_pay_log' => [
                'title' => __('Apple Pay logging', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Write dedicated Apple Pay diagnostic events to WooCommerce logs.', 'ncx-cp-api'),
                'default' => 'no',
                'description' => __('Enable temporarily while troubleshooting, then disable it. Customer email and contact details are not recorded.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            'enable_google_pay' => [
                'title' => __('Offer Google Pay', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Show Google Pay on supported browsers', 'ncx-cp-api'),
                'default' => 'no',
                'description' => __('Enable Google Pay for this merchant entity in PAYSTRAX OPP before turning this on. The preparatory button appears on supported browsers; OPP renders the final Google Pay payment button.', 'ncx-cp-api'),
            ],
            'google_pay_display_name' => [
                'title' => __('Google Pay display name', 'ncx-cp-api'),
                'type' => 'text',
                'default' => '',
                'description' => __('Store name used for Google Pay (max 64 characters). Leave blank to use the WordPress site name.', 'ncx-cp-api'),
            ],
            'google_pay_merchant_id' => [
                'title' => __('Google Pay merchant ID', 'ncx-cp-api'),
                'type' => 'text',
                'default' => '',
                'description' => __('The Google merchant identifier approved for this website. Configure the same merchant in PAYSTRAX OPP.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            'enable_google_pay_product_page' => [
                'title' => __('Google Pay on product pages', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Allow registered customers with complete account addresses to buy instantly with Google Pay on single product pages.', 'ncx-cp-api'),
                'default' => 'no',
                'description' => __('Guests and customers with incomplete billing or shipping details are not shown the button. They can still pay at checkout.', 'ncx-cp-api'),
                'desc_tip' => false,
            ],
            'enable_google_pay_log' => [
                'title' => __('Google Pay logging', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Write dedicated Google Pay diagnostic events to WooCommerce logs.', 'ncx-cp-api'),
                'default' => 'no',
                'description' => __('Enable temporarily while troubleshooting, then disable it. Customer email and contact details are not recorded.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            'apple_pay_domain_association' => [
                'title' => __('.well-known association files', 'ncx-cp-api'),
                'type' => 'apple_pay_domain_association',
                'default' => [],
            ],

            // ── Advanced ──────────────────────────────────────────────────
            'advanced_heading' => [
                'title' => '<span id="ncx-cp-heading-advanced">' . __('Advanced', 'ncx-cp-api') . '</span>',
                'type' => 'title',
                'description' => __('Optional safeguards. You can leave these off unless instructed otherwise.', 'ncx-cp-api'),
            ],
            'enable_dupe_check' => [
                'title' => __('Duplicate payment monitor', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Email an alert if two unpaid NCX orders share one checkout attempt.', 'ncx-cp-api'),
                'default' => 'no',
            ],

            // ── Logging & Diagnostics ─────────────────────────────────────
            'logging_heading' => [
                'title' => '<span id="ncx-cp-heading-support">' . __('Support diagnostics', 'ncx-cp-api') . '</span>',
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
            'provider_session_rate_limit' => [
                'title'       => __('Payment attempt limit', 'ncx-cp-api'),
                'type'        => 'number',
                'default'     => (string) self::DEFAULT_PROVIDER_SESSION_ATTEMPTS,
                'custom_attributes' => [
                    'min'  => (string) self::MIN_PROVIDER_SESSION_ATTEMPTS,
                    'max'  => (string) self::MAX_PROVIDER_SESSION_ATTEMPTS,
                    'step' => '1',
                ],
                'description' => sprintf(
                    /* translators: 1: lowest allowed value, 2: highest allowed value */
                    __('How many times one order may start a payment in 10 minutes before the shopper is asked to wait. Each card load and each wallet button counts once. Raise it if testing keeps hitting the limit. Allowed: %1$s to %2$s.', 'ncx-cp-api'),
                    self::MIN_PROVIDER_SESSION_ATTEMPTS,
                    self::MAX_PROVIDER_SESSION_ATTEMPTS
                ),
                'desc_tip'    => true,
            ],
            'opp_entity_in_query' => [
                'title'       => __('Entity ID in URL (Phase 1 test)', 'ncx-cp-api'),
                'type'        => 'checkbox',
                'label'       => __('Send entityId as a query parameter on POST /v1/checkouts (in addition to removing it from the body).', 'ncx-cp-api'),
                'default'     => 'no',
                'description' => __('Diagnostic only. Try this if checkout session creation fails with HTML Access Denied responses.', 'ncx-cp-api'),
            ],
            'opp_connectivity_test' => [
                'title'       => __('OPP connectivity test', 'ncx-cp-api'),
                'type'        => 'opp_connectivity_test',
                'description' => __('Compare direct cURL vs WordPress HTTP API against POST /v1/checkouts. Live traffic uses cURL by default (WordPress is fallback only when cURL is unavailable). Enable Server logging to retain results under WooCommerce → Status → Logs.', 'ncx-cp-api'),
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

        // Block the payment UI when the cart total is below the configured minimum.
        if (WC()->cart && $this->amount_below_minimum((float) WC()->cart->get_total('edit'))) {
            echo '<p class="ncx-cp-minimum-notice" style="text-align:center;font-size:1.1rem;font-weight:600;margin:1rem 0;">';
            echo esc_html($this->get_minimum_payment_error_message());
            echo '</p>';
            return;
        }

        $checkout_field_id = self::PLUGIN_PREFIX . 'checkout_id';
        if ($this->client_allows_card_saving()) {
            echo '<input type="hidden" id="' . esc_attr(self::PLUGIN_PREFIX . 'save_card_intent') . '" name="ncx_cp_create_registration" value="0">';
        }
        echo '<input type="hidden" id="ncx_cp_payment_container" name="ncx_cp_payment_container" value="card">';

        // Hidden field to carry the pre-created checkout ID into WooCommerce's form POST.
        echo '<input type="hidden" id="' . esc_attr($checkout_field_id) . '" name="ncx_cp_checkout_id" value="">';

        if ('' !== trim($this->inline_note_text)) {
            echo '<p class="ncx-cp-inline-note">' . esc_html($this->inline_note_text) . '</p>';
        }

        // Container where the OPP COPYandPAY widget will be mounted immediately via JS.
        $wrapper_classes = ['ncx-cp-inline-wrapper', $this->get_checkout_theme_class()];

        echo '<div id="ncx-cp-inline-wrapper" class="' . esc_attr(implode(' ', $wrapper_classes)) . '">';
        if ($this->is_apple_pay_enabled() || $this->is_google_pay_enabled()) {
            echo '<link rel="preconnect" href="' . esc_url($this->get_region_host()) . '" data-ncx-opp-preconnect="1" />';
        }
        if ($this->is_apple_pay_enabled()) {
            echo '<div id="ncx-cp-applepay-frame" class="ncx-cp-applepay-frame"></div>';
        }
        if ($this->is_google_pay_enabled()) {
            echo '<div id="ncx-cp-googlepay-frame" class="ncx-cp-googlepay-frame"></div>';
        }
        echo '<div id="ncx-cp-inline-frame" class="ncx-cp-inline-frame"><p class="ncx-cp-inline-note">' . esc_html__( 'Loading secure card form…', 'ncx-cp-api' ) . '</p></div>';
        echo '</div>';

        if ($this->console_logging && current_user_can('manage_woocommerce')) {
            echo '<!-- NCX debug: payment_fields rendered, JS handle=ncx-cp-api-inline -->';
            echo '<script>console.log("NCX: payment_fields() HTML rendered on server");</script>';
        }
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

        if (empty($credentials['entity_id']) || empty($credentials['access_token'])) {
            return false;
        }

        // Hide this gateway only; do not disable Place Order for other methods.
        // Skip empty carts so pay-for-order still offers the gateway.
        if (WC()->cart && !WC()->cart->is_empty() && $this->amount_below_minimum((float) WC()->cart->get_total('edit'))) {
            return false;
        }

        return true;
    }

    // Connects WooCommerce orders with COPYandPAY checkouts and returns execution data.
    public function process_payment($order_id): array {
        // Binds WooCommerce orders to COPYandPAY checkouts and returns execution metadata.
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['result' => 'failure'];
        }

        if ($this->amount_below_minimum((float) $order->get_total())) {
            // Reject order placement when the total is below the configured minimum.
            $this->add_gateway_notice($this->get_minimum_payment_error_message(), 'error');
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
                $this->add_gateway_notice(__('This order was cancelled. Please start a new order.', 'ncx-cp-api'), 'error');
                return ['result' => 'failure'];
            }
            // Already processing/completed — redirect to thank-you.
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        $this->bind_order_to_current_attempt($order);
        $this->promote_canonical_order_to_intent($order, 'card');
        $this->store_canonical_order_pointer((int) $order->get_id());

        $env_error = $this->validate_checkout_environment_consistency($order);
        if (is_wp_error($env_error)) {
            $this->add_gateway_notice($env_error->get_error_message(), 'error');
            return ['result' => 'failure'];
        }

        $use_test = $this->resolve_use_test($order);
        if (!$order->get_meta(self::ENVIRONMENT_META_KEY)) {
            $order->update_meta_data(self::ENVIRONMENT_META_KEY, $use_test ? 'test' : 'live');
            $order->save();
        }

        // A browser-supplied checkout ID may never select a payment session: it
        // is accepted only when it is the generation the coordinator committed
        // for this cart, which is also the session the mounted widget submits
        // to. Minting a replacement here would leave the widget executing an
        // older generation than the order records.
        $posted_checkout_id = $this->normalize_opp_checkout_id($this->get_request_checkout_id());
        $committed_checkout_id = $this->reusable_provider_checkout_id($order);

        if ('' !== $posted_checkout_id && '' !== $committed_checkout_id && hash_equals($committed_checkout_id, $posted_checkout_id)) {
            $payment_container = $this->get_request_payment_container();
            $create_registration = 'card' === $payment_container && $this->get_request_create_registration_flag();
            $bound = $this->update_checkout_data($committed_checkout_id, $order, $create_registration, $payment_container);
            if (is_wp_error($bound)) {
                $this->log_event('warning', 'Card payment blocked: could not bind order to checkout', [
                    'order_id' => $order->get_id(),
                    'code'     => $bound->get_error_code(),
                ]);
                $this->add_gateway_notice($bound->get_error_message(), 'error');
                return ['result' => 'failure'];
            }
            $checkout_id = $committed_checkout_id;
        } elseif ('' === $posted_checkout_id) {
            // Order-pay and receipt flows reach payment without a mounted
            // widget, so the coordinator creates the first session here. When
            // the browser *has* a mounted session we must never mint a
            // replacement: the widget would still execute the old one.
            $prepared = $this->create_committed_provider_checkout($order, 'card');
            if (is_wp_error($prepared)) {
                $this->log_event('warning', 'Card payment blocked: checkout session could not be created', [
                    'order_id' => $order->get_id(),
                    'code'     => $prepared->get_error_code(),
                ]);
                $this->add_gateway_notice($prepared->get_error_message(), 'error');
                return ['result' => 'failure'];
            }
            $checkout_id = (string) $prepared['checkout_id'];
        } else {
            $this->log_event('warning', 'Card payment blocked: posted checkout session is not the committed one', [
                'order_id'      => $order->get_id(),
                'has_committed' => '' !== $committed_checkout_id ? 'yes' : 'no',
            ]);

            // Account for an executed session before discarding it, otherwise
            // clearing the checkout ID would destroy the only reference we have
            // for asking the provider what happened to it.
            $abandoned = $this->resolve_abandoned_handoff($order);
            if ($abandoned instanceof WP_Error) {
                $this->add_gateway_notice($abandoned->get_error_message(), 'error');
                return ['result' => 'failure'];
            }

            // Fail closed and drop the stale session so the next attempt mounts
            // a fresh generation rather than paying against an unknown one.
            $reloaded = wc_get_order($order->get_id());
            $target = $reloaded instanceof WC_Order ? $reloaded : $order;
            $this->invalidate_provider_checkout($target, false);
            $target->save();
            $this->add_gateway_notice(__('The checkout was updated while you were paying. Please try again.', 'ncx-cp-api'), 'error');
            return ['result' => 'failure'];
        }

        $this->mark_payment_started($order);

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
            if ('' !== (string) $order->get_meta(self::ATTEMPT_META_KEY, true)) {
                $prepared = $this->create_committed_provider_checkout($order, 'card');
                if (is_wp_error($prepared)) {
                    echo '<p>' . esc_html($prepared->get_error_message()) . '</p>';
                    return;
                }
                $checkout_id = (string) $prepared['checkout_id'];
            } else {
                $session = $this->request_checkout_session($order);
                if (is_wp_error($session)) {
                    echo '<p>' . esc_html($session->get_error_message()) . '</p>';
                    return;
                }
                $checkout_id = (string) $session['id'];
                $order->update_meta_data(self::CHECKOUT_META_KEY, $checkout_id);
                $order->delete_meta_data(self::PROVIDER_CHECKOUT_KIND_META_KEY);
                $order->save();
            }
        }

        echo '<p>' . esc_html__('Complete your payment below. The order will update automatically once confirmed.', 'ncx-cp-api') . '</p>';
        echo NCX_CP_API::render_widget_markup($checkout_id, $this->get_payment_brands(), $this->get_region_host($use_test)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    // Handles shopper redirects from COPYandPAY and finalises order status.
    public function handle_result(): void {
        // Handles shopper redirects from COPYandPAY and finalises the order state.
        $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_key = isset($_GET['order_key']) ? sanitize_text_field(wp_unslash($_GET['order_key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $resource_path = isset($_GET['resourcePath']) ? sanitize_text_field(wp_unslash($_GET['resourcePath'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = $order_id ? wc_get_order($order_id) : false;

        $this->applepay_log('return: callback received', [
            'order_id'          => $order_id,
            'has_order_key'     => '' !== $order_key,
            'has_resource_path' => '' !== $resource_path,
            'resource_path'     => $resource_path,
            'order_found'       => (bool) $order,
        ]);

        if (!$order || $order->get_order_key() !== $order_key) {
            $this->applepay_log('return: unable to match order', [
                'order_id'    => $order_id,
                'order_found' => (bool) $order,
            ]);
            $this->add_gateway_notice(__('Unable to match the order for this payment.', 'ncx-cp-api'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Idempotency guard — if the notification already finalised this order, just redirect.
        if (!in_array($order->get_status(), ['pending', 'on-hold', 'checkout-draft'], true)) {
            $this->applepay_log('return: order already finalised', [
                'order_id' => $order->get_id(),
                'status'   => $order->get_status(),
                'is_paid'  => $order->is_paid(),
            ]);
            if ($order->is_paid()) {
                wp_safe_redirect($order->get_checkout_order_received_url());
            } else {
                wp_safe_redirect(wc_get_checkout_url());
            }
            exit;
        }

        if ('' === $resource_path) {
            $this->applepay_log('return: missing resourcePath', [
                'order_id' => $order->get_id(),
                'status'   => $order->get_status(),
            ]);
            $this->freeze_ambiguous_payment($order, __('Payment returned without a verifiable provider reference.', 'ncx-cp-api'));
            $this->add_gateway_notice(__('Payment status is still being verified. Please do not retry yet.', 'ncx-cp-api'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $payment = $this->fetch_payment_details($resource_path, $order);
        if (is_wp_error($payment)) {
            $this->applepay_log('return: fetch_payment_details failed', [
                'order_id'      => $order->get_id(),
                'resource_path' => '[redacted]',
                'error'         => $payment->get_error_message(),
            ]);
            $this->freeze_ambiguous_payment($order, $payment->get_error_message());
            $this->add_gateway_notice(__('Payment status could not be verified. Please do not retry yet.', 'ncx-cp-api'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $this->applepay_log('return: payment details fetched', [
            'order_id'       => $order->get_id(),
            'payment_id'     => (string) ($payment['id'] ?? ''),
            'result_code'    => (string) ($payment['result']['code'] ?? ''),
            'result_message' => (string) ($payment['result']['description'] ?? ''),
            'payment_brand'  => (string) ($payment['paymentBrand'] ?? ''),
            'payment_type'   => (string) ($payment['paymentType'] ?? ''),
            'amount'         => (string) ($payment['amount'] ?? ''),
            'currency'       => (string) ($payment['currency'] ?? ''),
        ]);

        // Verify payment data matches the order (amount, currency, cart_hash, order_key).
        $verification = $this->verify_payment_against_order($order, $payment);
        if (is_wp_error($verification)) {
            if ('ncx_cp_stale_generation' !== $verification->get_error_code()) {
                $this->freeze_ambiguous_payment($order, $verification->get_error_message());
            } else {
                $order->add_order_note(__('Ignored a callback from an obsolete checkout generation.', 'ncx-cp-api'));
            }
            $this->add_gateway_notice(__('Payment verification failed. Please contact support.', 'ncx-cp-api'), 'error');
            $this->applepay_log('return: payment verification failed', [
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
            $this->applepay_log('return: payment approved', [
                'order_id'       => $order->get_id(),
                'transaction_id' => $transaction_id,
                'result_code'    => $result_code,
            ]);
            $order->payment_complete($transaction_id);
            $order->add_order_note(sprintf(__('COPYandPAY approved (%s).', 'ncx-cp-api'), $result_code));
            $this->rotate_attempt_after_paid($order);
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }
            // Match nochexapi: store token if registrationId present in response
            // (OPP only returns registrationId when the shopper checked the save-card box).
            if ($this->allow_card_saving && isset($payment['registrationId'])) {
                $this->maybe_tokenize_card($order, $payment);
            }
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        if ('pending' === $state) {
            $this->applepay_log('return: payment pending', [
                'order_id'    => $order->get_id(),
                'result_code' => $result_code,
            ]);
            $order->update_status('on-hold', sprintf(__('COPYandPAY pending (%s).', 'ncx-cp-api'), $result_code));
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        $this->applepay_log('return: payment declined', [
            'order_id'       => $order->get_id(),
            'result_code'    => $result_code,
            'result_message' => (string) ($payment['result']['description'] ?? ''),
        ]);
        if ($this->is_payment_cancellation($payment)) {
            $this->mark_cancelled_payment_attempt(
                $order,
                sprintf(__('COPYandPAY cancelled (%s).', 'ncx-cp-api'), $result_code)
            );
            $this->add_gateway_notice(__('Payment was cancelled. Please try again.', 'ncx-cp-api'), 'error');
        } elseif ($this->is_definitive_payment_failure($payment)) {
            $this->mark_definitive_payment_failure(
                $order,
                sprintf(__('COPYandPAY declined (%s).', 'ncx-cp-api'), $result_code)
            );
            $this->add_gateway_notice(__('Payment was declined. Please try again.', 'ncx-cp-api'), 'error');
        } else {
            $this->freeze_ambiguous_payment(
                $order,
                sprintf(__('Unclassified COPYandPAY result (%s); retry frozen.', 'ncx-cp-api'), $result_code)
            );
            $this->add_gateway_notice(__('Payment status is uncertain. Please do not retry yet.', 'ncx-cp-api'), 'error');
        }
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

        // Failed remains processable until a newer generation is reserved; a
        // delayed success notification for the same generation must still win.
        if (!in_array($order->get_status(), ['pending', 'on-hold', 'checkout-draft', 'failed'], true)) {
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
            if ('ncx_cp_stale_generation' !== $verification->get_error_code()) {
                $this->freeze_ambiguous_payment($order, $verification->get_error_message());
            } else {
                $order->add_order_note(__('Ignored a notification from an obsolete checkout generation.', 'ncx-cp-api'));
            }
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

        $this->applepay_log('notification: payment result', [
            'order_id'       => $order_id,
            'state'          => $state,
            'result_code'    => $result_code,
            'result_message' => (string) ($payment['result']['description'] ?? ''),
            'payment_brand'  => (string) ($payment['paymentBrand'] ?? ''),
        ]);

        if ('success' === $state) {
            $order->payment_complete($transaction_id);
            $order->add_order_note(sprintf(__('COPYandPAY approved via notification (%s).', 'ncx-cp-api'), $result_code));
            $this->rotate_attempt_after_paid($order);
            if ($this->allow_card_saving && isset($payment['registrationId'])) {
                $this->maybe_tokenize_card($order, $payment);
            }
        } elseif ('pending' === $state) {
            $order->update_status('on-hold', sprintf(__('COPYandPAY pending via notification (%s).', 'ncx-cp-api'), $result_code));
        } elseif ($this->is_payment_cancellation($payment)) {
            $this->mark_cancelled_payment_attempt(
                $order,
                sprintf(__('COPYandPAY cancelled via notification (%s).', 'ncx-cp-api'), $result_code)
            );
        } elseif ($this->is_definitive_payment_failure($payment)) {
            $this->mark_definitive_payment_failure(
                $order,
                sprintf(__('COPYandPAY declined via notification (%s).', 'ncx-cp-api'), $result_code)
            );
        } else {
            $this->freeze_ambiguous_payment(
                $order,
                sprintf(__('Unclassified COPYandPAY notification result (%s); retry frozen.', 'ncx-cp-api'), $result_code)
            );
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
        $raw_order_id = isset($_POST['order_id']) ? wp_unslash($_POST['order_id']) : '';
        if ('' === $raw_order_id) {
            wp_die('Nochex APC – Request Failed', 'APC Error', ['response' => 400]);
            return;
        }

        $order_id           = absint(str_replace('order-', '', (string) $raw_order_id));
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
        $verify_response = $this->opp_http_request(
            'POST',
            self::APC_URL,
            [
                'timeout' => 30,
                'body'    => wp_unslash($_POST), // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'User-Agent'   => 'WooCommerce/' . WC()->version,
                ],
            ]
        );

        $output = is_wp_error($verify_response) ? '' : $this->http_response_body($verify_response);

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

            if (in_array($order->get_status(), ['pending', 'on-hold', 'checkout-draft'], true)) {
                $checkout_id = $this->normalize_opp_checkout_id((string) $order->get_meta(self::CHECKOUT_META_KEY, true));
                if ('' !== $checkout_id) {
                    $payment = $this->fetch_payment_details('/v1/checkouts/' . $checkout_id . '/payment', $order);
                    if (!is_wp_error($payment)) {
                        $verification = $this->verify_payment_against_order($order, $payment);
                        if (!is_wp_error($verification) && 'success' === $this->determine_state($payment)) {
                            $order->payment_complete((string) ($payment['id'] ?? $transaction_id));
                            $order->add_order_note(__('COPYandPAY payment confirmed after APC wake-up.', 'ncx-cp-api'));
                            $this->rotate_attempt_after_paid($order);
                        } else {
                            $order->add_order_note(__('APC AUTHORISED received; OPP re-query did not confirm a matching success.', 'ncx-cp-api'));
                        }
                    } else {
                        $order->add_order_note(__('APC AUTHORISED received; OPP re-query failed. Order left unchanged.', 'ncx-cp-api'));
                    }
                } else {
                    $order->add_order_note(__('APC AUTHORISED received; no stored checkout ID to re-query.', 'ncx-cp-api'));
                }
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
        // Abort checkout-session creation when the order total is below the minimum.
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

        $this->apply_merchant_url_parameter($body);

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
        $generation = (int) $order->get_meta(self::GENERATION_META_KEY, true);
        if ($generation > 0) {
            $body['customParameters[SHOPPER_generation]'] = (string) $generation;
        }

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
     * Build a minimal order-based checkout session for Apple Pay.
     *
     * Deliberately mirrors the old standalone Apple Pay plugin's proven payload:
     * it omits standingInstruction, saved-card registrations, createRegistration,
     * and 3DS extras. Those parameters reference card-account/token data that the
     * APPLEPAY brand rejects with "invalid account data for given payment brand"
     * (OPP result 200.300.404), even though the same entity ID works for cards.
     */
    private function request_apple_pay_checkout_session(WC_Order $order) {
        // Abort Apple Pay session creation when the order total is below the minimum.
        if ($this->amount_below_minimum((float) $order->get_total())) {
            return new WP_Error('ncx_cp_below_minimum', $this->get_minimum_payment_error_message());
        }

        $use_test = $this->resolve_use_test($order);
        $credentials = $this->get_active_credentials($use_test);
        if (!$this->credentials_present($credentials)) {
            return new WP_Error('ncx_cp_missing_creds', __('CopyAndPay credentials are missing.', 'ncx-cp-api'));
        }

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

        $this->apply_merchant_url_parameter($body);

        // Customer fields only — deliberately NO card.holder (or any card.* field).
        // For the APPLEPAY brand the card data comes from the decrypted Apple Pay
        // token, so sending card.* triggers "invalid account data for given payment
        // brand" (200.300.404). This mirrors the old working Apple Pay plugin.
        $body['customer.email']     = sanitize_email((string) $order->get_billing_email());
        $body['customer.givenName'] = $this->sanitize_opp_field((string) $order->get_billing_first_name(), 64);
        $body['customer.surname']   = $this->sanitize_opp_field((string) $order->get_billing_last_name(), 64);

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
        $generation = (int) $order->get_meta(self::GENERATION_META_KEY, true);
        if ($generation > 0) {
            $body['customParameters[SHOPPER_generation]'] = (string) $generation;
        }

        // NOTE: no standingInstruction, no registrations[], no createRegistration,
        // no card.* fields, and no 3DS parameters here — keep the Apple Pay payload
        // minimal/proven to match the old standalone plugin.

        return $this->post_to_checkouts($credentials, $body, $use_test);
    }

    /**
     * Google Pay uses the same deliberately minimal wallet checkout payload as Apple Pay.
     */
    private function request_google_pay_checkout_session(WC_Order $order) {
        return $this->request_apple_pay_checkout_session($order);
    }

    /**
     * Reserve, create outside the lock, then compare-and-commit one provider generation.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function create_committed_provider_checkout(WC_Order $order, string $kind, bool $prefetch = false) {
        // Establish what happened to any executed session before replacing it,
        // outside the attempt lock because this can call the provider.
        $abandoned = $this->resolve_abandoned_handoff($order);
        if ($abandoned instanceof WP_Error) {
            return $abandoned;
        }

        $reservation = $this->reserve_provider_checkout($order, $kind);
        if (is_wp_error($reservation)) {
            return $reservation;
        }
        if (!empty($reservation['reused'])) {
            return $reservation;
        }
        if ($prefetch) {
            $quota = $this->consume_prefetch_provider_quota();
            if (is_wp_error($quota)) {
                $this->abandon_provider_reservation($order, $reservation);
                return $quota;
            }
        }

        // Reload so the pre-allocated generation is included in SHOPPER_generation.
        $fresh = wc_get_order($order->get_id());
        if (!$fresh instanceof WC_Order) {
            $this->abandon_provider_reservation($order, $reservation);
            return new WP_Error('ncx_cp_order_missing', __('Checkout order could not be loaded.', 'ncx-cp-api'));
        }

        if ('applepay' === $kind) {
            $session = $this->request_apple_pay_checkout_session($fresh);
        } elseif ('googlepay' === $kind) {
            $session = $this->request_google_pay_checkout_session($fresh);
        } else {
            $session = $this->request_checkout_session($fresh);
        }

        if (is_wp_error($session)) {
            $this->abandon_provider_reservation($fresh, $reservation);
            return $session;
        }

        $checkout_id = $this->normalize_opp_checkout_id((string) ($session['id'] ?? ''));
        if ('' === $checkout_id) {
            $this->abandon_provider_reservation($fresh, $reservation);
            return new WP_Error('ncx_cp_no_checkout', __('Payment checkout session could not be created.', 'ncx-cp-api'));
        }

        $generation = $this->commit_provider_checkout($fresh, $reservation, $checkout_id);
        if (is_wp_error($generation)) {
            return $generation;
        }

        return [
            'reused' => false,
            'checkout_id' => $checkout_id,
            'generation' => (int) $generation,
        ];
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

        $amount = (float) WC()->cart->get_total('edit');
        if ($amount <= 0) {
            wp_send_json_error(['message' => __('Cart total is zero.', 'ncx-cp-api')]);
            return;
        }

        // Reject cart-based checkout-ID requests when the total is below the minimum.
        if ($this->amount_below_minimum($amount)) {
            wp_send_json_error(['message' => $this->get_minimum_payment_error_message()]);
            return;
        }

        // Take ownership of anything this plugin left in the session notice
        // queue before the shopper can submit again. On Blocks that queue is
        // never drained by a page render, and the Store API turns whatever is
        // in it into a failure on the next submission. Every exit below
        // carries the text back so the shopper still sees it.
        $this->pending_shopper_notices = $this->drain_gateway_notices();

        $mode = $this->requested_wallet_mode();
        $order_result = $this->coordinate_wallet_order($mode);
        if (is_wp_error($order_result['order'])) {
            $this->send_coordinator_error($order_result['order']);
            return;
        }
        /** @var WC_Order $order */
        $order = $order_result['order'];

        // The widget reports a session the provider refused to render. Clearing
        // it here means the shopper is not stuck re-requesting the same dead
        // checkout ID; the discard itself fails closed on anything unexpected.
        if (!empty($_POST['invalid_checkout_id']) && is_string($_POST['invalid_checkout_id'])) {
            $reported = sanitize_text_field(wp_unslash($_POST['invalid_checkout_id']));
            $this->discard_rejected_provider_checkout($order, substr($reported, 0, 128));
        }

        $prepared = $this->create_committed_provider_checkout($order, 'card', 'prefetch' === $mode);
        if (is_wp_error($prepared)) {
            $this->send_coordinator_error($prepared);
            return;
        }
        wp_send_json_success([
            'checkoutId'  => (string) $prepared['checkout_id'],
            'environment' => $this->test_mode ? 'test' : 'live',
            'orderId'     => (int) $order->get_id(),
            'generation'  => (int) $prepared['generation'],
            'notices'     => $this->pending_shopper_notices,
        ]);
    }

    /**
     * Optional debug logger for the Apple Pay flow.
     *
     * Writes to a dedicated WooCommerce log channel only when explicitly
     * enabled under the Apple Pay gateway settings.
     *
     * @param array<string, mixed> $context
     */
    private function applepay_log(string $message, array $context = []): void {
        if (!$this->apple_pay_logging || !function_exists('wc_get_logger')) {
            return;
        }

        $context = $this->redact_apple_pay_log_context($context);
        $this->get_logger()->log('info', $message, array_merge(['source' => 'ncx-cp-applepay'], $context));
    }

    /**
     * Writes to a dedicated WooCommerce log channel only when explicitly
     * enabled under the Google Pay gateway settings.
     *
     * @param array<string, mixed> $context
     */
    private function googlepay_log(string $message, array $context = []): void {
        if (!$this->google_pay_logging || !function_exists('wc_get_logger')) {
            return;
        }

        $context = $this->redact_apple_pay_log_context($context);
        $this->get_logger()->log('info', $message, array_merge(['source' => 'ncx-cp-googlepay'], $context));
    }

    /**
     * Remove customer contact data and bound caller-supplied diagnostic context.
     *
     * @param array<string|int, mixed> $context
     * @return array<string|int, mixed>
     */
    private function redact_apple_pay_log_context(array $context): array {
        $redacted = [];
        $context = array_slice($context, 0, 50, true);

        foreach ($context as $key => $value) {
            $key_string = (string) $key;
            if (preg_match('/email|phone|address|contact|given.?name|family.?name|first.?name|last.?name|postal|postcode|city|token|nonce|secret|authorization|order.?key/i', $key_string)) {
                $redacted[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redact_apple_pay_log_context($value);
            } elseif (is_string($value)) {
                $redacted[$key] = mb_substr(wp_strip_all_tags($value), 0, 500);
            } elseif (is_scalar($value) || null === $value) {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    /**
     * Parse checkout field data sent from classic (serialized form) or block checkout (JSON).
     *
     * @return array<string, string>
     */
    private function parse_checkout_post_from_request(): array {
        $data = [];

        if (!empty($_POST['checkout_post']) && is_string($_POST['checkout_post'])) {
            $parsed = [];
            parse_str(wp_unslash($_POST['checkout_post']), $parsed);
            if (is_array($parsed)) {
                foreach ($parsed as $key => $value) {
                    if (is_scalar($value)) {
                        $data[(string) $key] = (string) $value;
                    }
                }
            }
        }

        if (!empty($_POST['checkout_billing']) && is_string($_POST['checkout_billing'])) {
            $billing = json_decode(wp_unslash($_POST['checkout_billing']), true);
            if (is_array($billing)) {
                $billing_map = [
                    'first_name' => 'billing_first_name',
                    'last_name'  => 'billing_last_name',
                    'company'    => 'billing_company',
                    'address_1'  => 'billing_address_1',
                    'address_2'  => 'billing_address_2',
                    'city'       => 'billing_city',
                    'state'      => 'billing_state',
                    'postcode'   => 'billing_postcode',
                    'country'    => 'billing_country',
                    'email'      => 'billing_email',
                    'phone'      => 'billing_phone',
                ];
                foreach ($billing_map as $short => $long) {
                    if (isset($billing[$short]) && is_scalar($billing[$short])) {
                        $data[$long] = (string) $billing[$short];
                    }
                }
            }
        }

        if (!empty($_POST['checkout_shipping']) && is_string($_POST['checkout_shipping'])) {
            $shipping = json_decode(wp_unslash($_POST['checkout_shipping']), true);
            if (is_array($shipping)) {
                $shipping_map = [
                    'first_name' => 'shipping_first_name',
                    'last_name'  => 'shipping_last_name',
                    'company'    => 'shipping_company',
                    'address_1'  => 'shipping_address_1',
                    'address_2'  => 'shipping_address_2',
                    'city'       => 'shipping_city',
                    'state'      => 'shipping_state',
                    'postcode'   => 'shipping_postcode',
                    'country'    => 'shipping_country',
                    'phone'      => 'shipping_phone',
                ];
                foreach ($shipping_map as $short => $long) {
                    if (isset($shipping[$short]) && is_scalar($shipping[$short])) {
                        $data[$long] = (string) $shipping[$short];
                    }
                }
            }
        }

        if (isset($_POST['ship_to_different_address'])) {
            $data['ship_to_different_address'] = wc_string_to_bool(wp_unslash($_POST['ship_to_different_address'])) ? '1' : '0';
        }

        return $data;
    }

    /**
     * Ensure required checkout fields are present before creating a payment session.
     *
     * @param array<string, string> $posted_checkout
     * @return true|WP_Error
     */
    private function validate_checkout_ready_for_payment(array $posted_checkout = []) {
        if (!function_exists('WC') || !WC()->checkout()) {
            return true;
        }

        $checkout = WC()->checkout();
        $errors   = new WP_Error();

        foreach ($checkout->get_checkout_fields('billing') as $key => $field) {
            if (empty($field['required'])) {
                continue;
            }
            $value = $posted_checkout[$key] ?? '';
            if ('' === trim((string) $value)) {
                $label = isset($field['label']) ? wp_strip_all_tags((string) $field['label']) : $key;
                $errors->add($key, sprintf(
                    /* translators: %s field name */
                    __('%s is a required field.', 'woocommerce'),
                    $label
                ));
            }
        }

        $needs_shipping   = WC()->cart && WC()->cart->needs_shipping();
        $ship_different   = !empty($posted_checkout['ship_to_different_address']) && '0' !== $posted_checkout['ship_to_different_address'];
        $validate_shipping = $needs_shipping && $ship_different && !wc_ship_to_billing_address_only();

        if ($validate_shipping) {
            foreach ($checkout->get_checkout_fields('shipping') as $key => $field) {
                if (empty($field['required'])) {
                    continue;
                }
                $value = $posted_checkout[$key] ?? '';
                if ('' === trim((string) $value)) {
                    $label = isset($field['label']) ? wp_strip_all_tags((string) $field['label']) : $key;
                    $errors->add($key, sprintf(
                        /* translators: %s field name */
                        __('%s is a required field.', 'woocommerce'),
                        $label
                    ));
                }
            }
        }

        $billing_email = isset($posted_checkout['billing_email']) ? trim((string) $posted_checkout['billing_email']) : '';
        if ('' !== $billing_email && !is_email($billing_email)) {
            $errors->add('billing_email', __('Please enter a valid email address.', 'woocommerce'));
        }

        if ($errors->has_errors()) {
            return $errors;
        }

        return true;
    }

    /**
     * Apply posted checkout data to the customer session so order creation uses it.
     *
     * @param array<string, string> $posted_checkout
     * @return true|WP_Error
     */
    private function apply_checkout_post_to_session(array $posted_checkout) {
        if (empty($posted_checkout) || !function_exists('WC') || !WC()->customer) {
            return true;
        }

        $customer = WC()->customer;

        $billing_setters = [
            'billing_first_name' => 'set_billing_first_name',
            'billing_last_name'  => 'set_billing_last_name',
            'billing_company'    => 'set_billing_company',
            'billing_address_1'  => 'set_billing_address_1',
            'billing_address_2'  => 'set_billing_address_2',
            'billing_city'       => 'set_billing_city',
            'billing_state'      => 'set_billing_state',
            'billing_postcode'   => 'set_billing_postcode',
            'billing_country'    => 'set_billing_country',
            'billing_email'      => 'set_billing_email',
            'billing_phone'      => 'set_billing_phone',
        ];

        $shipping_setters = [
            'shipping_first_name' => 'set_shipping_first_name',
            'shipping_last_name'  => 'set_shipping_last_name',
            'shipping_company'    => 'set_shipping_company',
            'shipping_address_1'  => 'set_shipping_address_1',
            'shipping_address_2'  => 'set_shipping_address_2',
            'shipping_city'       => 'set_shipping_city',
            'shipping_state'      => 'set_shipping_state',
            'shipping_postcode'   => 'set_shipping_postcode',
            'shipping_country'    => 'set_shipping_country',
        ];

        try {
            foreach ($billing_setters as $key => $setter) {
                if (isset($posted_checkout[$key]) && '' !== $posted_checkout[$key] && method_exists($customer, $setter)) {
                    $customer->{$setter}(wc_clean(wp_unslash($posted_checkout[$key])));
                }
            }

            $ship_different = !empty($posted_checkout['ship_to_different_address'])
                && '0' !== (string) $posted_checkout['ship_to_different_address'];

            foreach ($shipping_setters as $key => $setter) {
                if (!method_exists($customer, $setter)) {
                    continue;
                }
                if ($ship_different && isset($posted_checkout[$key]) && '' !== $posted_checkout[$key]) {
                    $customer->{$setter}(wc_clean(wp_unslash($posted_checkout[$key])));
                } elseif (!$ship_different) {
                    // Mirror billing into shipping when not shipping to a different address.
                    $billing_key = str_replace('shipping_', 'billing_', $key);
                    if (isset($posted_checkout[$billing_key]) && '' !== $posted_checkout[$billing_key]) {
                        $customer->{$setter}(wc_clean(wp_unslash($posted_checkout[$billing_key])));
                    }
                }
            }

            $customer->save();
        } catch (WC_Data_Exception $e) {
            return new WP_Error('ncx_cp_checkout_field', $e->getMessage());
        }

        return true;
    }

    /**
     * AJAX handler: create order from cart and a complete order-based OPP checkout for Apple Pay.
     */
    public function ajax_applepay_process(): void {
        $this->applepay_log('process: request received', [
            'has_billing'  => isset($_POST['billing_contact']),
            'has_shipping' => isset($_POST['shipping_contact']),
            'logged_in'    => is_user_logged_in(),
            'post_keys'    => array_keys($_POST),
        ]);

        if (!check_ajax_referer('ncx_cp_checkout_nonce', 'security', false)) {
            $this->applepay_log('process: nonce verification failed');
            wp_send_json_error(['message' => __('Security check failed (nonce). Please reload the page.', 'ncx-cp-api')]);
            return;
        }

        if (!$this->is_apple_pay_enabled()) {
            $this->applepay_log('process: Apple Pay is not enabled');
            wp_send_json_error(['message' => __('Apple Pay is not enabled.', 'ncx-cp-api')]);
            return;
        }

        // Pay for an existing order (My Account > Orders > Pay). That page has no
        // checkout form and the cart may hold unrelated leftovers, so this path
        // deliberately skips checkout validation and cart-based order creation.
        $pay_order = $this->resolve_order_pay_from_request();
        if (is_wp_error($pay_order)) {
            $this->applepay_log('process: pay for order rejected', [
                'code' => $pay_order->get_error_code(),
            ]);
            wp_send_json_error(['message' => $pay_order->get_error_message()]);
            return;
        }

        if ($pay_order instanceof WC_Order) {
            $this->send_apple_pay_order_pay_response($pay_order);
            return;
        }

        if (!WC()->cart || WC()->cart->is_empty()) {
            $this->applepay_log('process: cart is empty or unavailable', [
                'cart_exists' => (bool) WC()->cart,
            ]);
            wp_send_json_error(['message' => __('Cart is empty.', 'ncx-cp-api')]);
            return;
        }

        $amount = (float) WC()->cart->get_total('edit');
        $this->applepay_log('process: cart total', [
            'amount'         => $amount,
            'currency'       => get_woocommerce_currency(),
            'needs_shipping' => WC()->cart->needs_shipping(),
        ]);

        if ($amount <= 0) {
            $this->applepay_log('process: cart total is zero', ['amount' => $amount]);
            wp_send_json_error(['message' => __('Cart total is zero.', 'ncx-cp-api')]);
            return;
        }

        if ($this->amount_below_minimum($amount)) {
            $this->applepay_log('process: amount below minimum', ['amount' => $amount]);
            // Reject Apple Pay when the cart total is below the configured minimum.
            wp_send_json_error(['message' => $this->get_minimum_payment_error_message()]);
            return;
        }

        $posted_checkout = $this->parse_checkout_post_from_request();
        $validation      = $this->validate_checkout_ready_for_payment($posted_checkout);
        if (is_wp_error($validation)) {
            $this->applepay_log('process: checkout validation failed', [
                'errors' => $validation->get_error_messages(),
            ]);
            wp_send_json_error(['message' => $validation->get_error_message()]);
            return;
        }

        $session_apply = $this->apply_checkout_post_to_session($posted_checkout);
        if (is_wp_error($session_apply)) {
            $this->applepay_log('process: checkout session apply failed', [
                'errors' => $session_apply->get_error_messages(),
            ]);
            wp_send_json_error(['message' => $session_apply->get_error_message()]);
            return;
        }

        $this->recalculate_cart_for_apple_pay();

        // Re-read the total: fees such as wallet credit only exist after the
        // recalculation above, so the first reading can be the undiscounted one.
        $amount = (float) WC()->cart->get_total('edit');
        $this->applepay_log('process: cart total after recalculation', ['amount' => $amount]);

        if ($amount <= 0) {
            $this->applepay_log('process: recalculated cart total is zero', ['amount' => $amount]);
            wp_send_json_error(['message' => __('Cart total is zero.', 'ncx-cp-api')]);
            return;
        }

        if ($this->amount_below_minimum($amount)) {
            $this->applepay_log('process: recalculated amount below minimum', ['amount' => $amount]);
            wp_send_json_error(['message' => $this->get_minimum_payment_error_message()]);
            return;
        }

        $billing_contact  = $this->parse_apple_pay_contact_from_request('billing_contact');
        $shipping_contact = $this->parse_apple_pay_contact_from_request('shipping_contact');
        $this->applepay_log('process: contacts parsed', [
            'billing_keys'  => array_keys($billing_contact),
            'shipping_keys' => array_keys($shipping_contact),
        ]);

        $order_result = $this->coordinate_wallet_order($this->requested_wallet_mode(), $billing_contact, $shipping_contact);
        if (is_wp_error($order_result['order'])) {
            $this->applepay_log('process: coordinate_wallet_order failed', [
                'error' => $order_result['order']->get_error_message(),
            ]);
            $this->send_coordinator_error($order_result['order']);
            return;
        }

        /** @var WC_Order $order */
        $order     = $order_result['order'];
        $was_reused = (bool) $order_result['reused'];
        $this->applepay_log($was_reused ? 'process: order reused' : 'process: order created', [
            'order_id'      => $order->get_id(),
            'total'         => $order->get_total(),
            'currency'      => $order->get_currency(),
        ]);

        $total_mismatch = $this->find_order_cart_total_mismatch($order);
        if (is_wp_error($total_mismatch) && 'intent' === $this->requested_wallet_mode()) {
            $this->applepay_log('process: order total does not match cart total', [
                'order_id'    => $order->get_id(),
                'order_total' => (float) $order->get_total(),
                'cart_total'  => $this->get_unfiltered_cart_total(),
            ]);
            wp_send_json_error(['message' => $total_mismatch->get_error_message()]);
            return;
        }

        $use_test = (bool) $this->test_mode;
        $order->update_meta_data(self::ENVIRONMENT_META_KEY, $use_test ? 'test' : 'live');
        $order->update_meta_data('_ncx_cp_payment_source', 'apple_pay');
        $order->set_payment_method_title(__('Apple Pay', 'ncx-cp-api'));
        if (!$was_reused) {
            $order->add_order_note(__('Payment initiated with Apple Pay.', 'ncx-cp-api'));
        }
        $order->save();

        $env_error = $this->validate_checkout_environment_consistency($order);
        if (is_wp_error($env_error)) {
            $this->applepay_log('process: environment consistency check failed', [
                'order_id' => $order->get_id(),
                'error'    => $env_error->get_error_message(),
            ]);
            wp_send_json_error(['message' => $env_error->get_error_message()]);
            return;
        }

        $prepared = $this->create_committed_provider_checkout(
            $order,
            'applepay',
            'prefetch' === $this->requested_wallet_mode()
        );
        if (is_wp_error($prepared)) {
            $this->send_coordinator_error($prepared);
            return;
        }
        $checkout_id = (string) $prepared['checkout_id'];

        $this->applepay_log('process: order checkout created', [
            'order_id'    => $order->get_id(),
            'checkout_id' => $checkout_id,
        ]);

        if (WC()->session) {
            $this->store_canonical_order_pointer((int) $order->get_id());
        }
        // Note: cart is intentionally NOT emptied here. If OPP fails to finalise
        // the Apple Pay charge, the shopper must still have their cart to retry.
        // The cart is emptied only after a confirmed success in handle_result().

        $shopper_result_url = add_query_arg(
            [
                'order_id'  => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ],
            WC()->api_request_url(self::CALLBACK_ACTION)
        );

        $this->applepay_log('process: success response sent', [
            'order_id'         => $order->get_id(),
            'checkout_id'      => $checkout_id,
            'shopperResultUrl' => $shopper_result_url,
        ]);

        wp_send_json_success([
            'order_id'         => $order->get_id(),
            'order_key'        => $order->get_order_key(),
            'checkoutId'       => $checkout_id,
            'environment'        => $use_test ? 'test' : 'live',
            'regionHost'         => $this->get_region_host($use_test),
            'shopperResultUrl' => $shopper_result_url,
            'generation'       => (int) $prepared['generation'],
            'lifecycle'        => (string) $order->get_meta(self::LIFECYCLE_META_KEY, true),
        ]);
    }

    /**
     * AJAX handler: create an order-backed OPP checkout for Google Pay.
     */
    public function ajax_googlepay_process(): void {
        $this->googlepay_log('process: request received', [
            'logged_in' => is_user_logged_in(),
        ]);

        if (!check_ajax_referer('ncx_cp_checkout_nonce', 'security', false)) {
            $this->googlepay_log('process: nonce verification failed');
            wp_send_json_error(['message' => __('Security check failed (nonce). Please reload the page.', 'ncx-cp-api')]);
            return;
        }

        if (!$this->is_google_pay_enabled()) {
            $this->googlepay_log('process: Google Pay is not enabled');
            wp_send_json_error(['message' => __('Google Pay is not enabled.', 'ncx-cp-api')]);
            return;
        }

        // Pay for an existing order (My Account > Orders > Pay) without
        // depending on the shopper's current cart.
        $pay_order = $this->resolve_order_pay_from_request();
        if (is_wp_error($pay_order)) {
            $this->googlepay_log('process: pay for order rejected', [
                'code' => $pay_order->get_error_code(),
            ]);
            wp_send_json_error(['message' => $pay_order->get_error_message()]);
            return;
        }

        if ($pay_order instanceof WC_Order) {
            $this->send_google_pay_order_pay_response($pay_order);
            return;
        }

        if (!WC()->cart || WC()->cart->is_empty()) {
            $this->googlepay_log('process: cart is empty or unavailable', [
                'cart_exists' => (bool) WC()->cart,
            ]);
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

        $posted_checkout = $this->parse_checkout_post_from_request();
        $validation = $this->validate_checkout_ready_for_payment($posted_checkout);
        if (is_wp_error($validation)) {
            $this->googlepay_log('process: checkout validation failed', [
                'errors' => $validation->get_error_messages(),
            ]);
            wp_send_json_error(['message' => $validation->get_error_message()]);
            return;
        }

        $session_apply = $this->apply_checkout_post_to_session($posted_checkout);
        if (is_wp_error($session_apply)) {
            $this->googlepay_log('process: checkout session apply failed', [
                'errors' => $session_apply->get_error_messages(),
            ]);
            wp_send_json_error(['message' => $session_apply->get_error_message()]);
            return;
        }

        $this->recalculate_cart_for_apple_pay();

        $amount = (float) WC()->cart->get_total('edit');
        if ($amount <= 0) {
            wp_send_json_error(['message' => __('Cart total is zero.', 'ncx-cp-api')]);
            return;
        }
        if ($this->amount_below_minimum($amount)) {
            wp_send_json_error(['message' => $this->get_minimum_payment_error_message()]);
            return;
        }

        $order_result = $this->coordinate_wallet_order($this->requested_wallet_mode());
        if (is_wp_error($order_result['order'])) {
            $this->send_coordinator_error($order_result['order']);
            return;
        }

        /** @var WC_Order $order */
        $order = $order_result['order'];
        $was_reused = (bool) $order_result['reused'];

        $total_mismatch = $this->find_order_cart_total_mismatch($order);
        if (is_wp_error($total_mismatch) && 'intent' === $this->requested_wallet_mode()) {
            wp_send_json_error(['message' => $total_mismatch->get_error_message()]);
            return;
        }

        $use_test = (bool) $this->test_mode;
        $order->update_meta_data(self::ENVIRONMENT_META_KEY, $use_test ? 'test' : 'live');
        $order->update_meta_data('_ncx_cp_payment_source', 'google_pay');
        $order->set_payment_method_title(__('Google Pay', 'ncx-cp-api'));
        if (!$was_reused) {
            $order->add_order_note(__('Payment initiated with Google Pay.', 'ncx-cp-api'));
        }
        $order->save();

        $env_error = $this->validate_checkout_environment_consistency($order);
        if (is_wp_error($env_error)) {
            wp_send_json_error(['message' => $env_error->get_error_message()]);
            return;
        }

        $prepared = $this->create_committed_provider_checkout(
            $order,
            'googlepay',
            'prefetch' === $this->requested_wallet_mode()
        );
        if (is_wp_error($prepared)) {
            $this->send_coordinator_error($prepared);
            return;
        }
        $checkout_id = (string) $prepared['checkout_id'];
        $this->store_canonical_order_pointer((int) $order->get_id());

        $shopper_result_url = add_query_arg(
            [
                'order_id' => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ],
            WC()->api_request_url(self::CALLBACK_ACTION)
        );

        $this->googlepay_log('process: success response sent', [
            'order_id' => $order->get_id(),
            'checkout_id' => $checkout_id,
        ]);

        wp_send_json_success([
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key(),
            'checkoutId' => $checkout_id,
            'environment' => $use_test ? 'test' : 'live',
            'regionHost' => $this->get_region_host($use_test),
            'shopperResultUrl' => $shopper_result_url,
            'generation' => (int) $prepared['generation'],
            'lifecycle' => (string) $order->get_meta(self::LIFECYCLE_META_KEY, true),
        ]);
    }

    /**
     * Resolves and authorises the order named by a pay-for-order wallet request.
     *
     * Access mirrors WooCommerce's own pay page: a matching order key, plus
     * ownership when the order belongs to a registered customer. Failures return
     * one generic message so this endpoint cannot be used to probe order IDs.
     *
     * @return WC_Order|WP_Error|null Null when this is an ordinary cart checkout.
     */
    private function resolve_order_pay_from_request() {
        $order_id  = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';

        if ($order_id <= 0 && '' === $order_key) {
            return null;
        }

        $generic = new WP_Error(
            'ncx_cp_pay_order_invalid',
            __('This order cannot be paid. Please refresh the page and try again.', 'ncx-cp-api')
        );

        if ($order_id <= 0 || '' === $order_key) {
            return $generic;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return $generic;
        }

        if (!hash_equals((string) $order->get_order_key(), $order_key)) {
            return $generic;
        }

        // Orders owned by a registered customer may only be paid by that customer.
        // Guest orders (customer ID 0) are reachable with the order key alone.
        $customer_id = (int) $order->get_customer_id();
        if ($customer_id > 0 && $customer_id !== get_current_user_id()) {
            return $generic;
        }

        if ($order->is_paid() || !in_array($order->get_status(), ['pending', 'failed'], true)) {
            return new WP_Error(
                'ncx_cp_pay_order_status',
                __('This order is no longer awaiting payment.', 'ncx-cp-api')
            );
        }

        $total = (float) $order->get_total();
        if ($total <= 0) {
            return new WP_Error('ncx_cp_pay_order_zero', __('Order total is zero.', 'ncx-cp-api'));
        }

        if ($this->amount_below_minimum($total)) {
            return new WP_Error('ncx_cp_below_minimum', $this->get_minimum_payment_error_message());
        }

        return $order;
    }

    /**
     * Creates an Apple Pay session for an existing order and returns it to the client.
     *
     * Never rebuilds the order from the cart; the stored addresses and totals are
     * authoritative on the pay page.
     */
    private function send_apple_pay_order_pay_response(WC_Order $order): void {
        $use_test = $this->resolve_use_test($order);
        if (!$order->get_meta(self::ENVIRONMENT_META_KEY)) {
            $order->update_meta_data(self::ENVIRONMENT_META_KEY, $use_test ? 'test' : 'live');
        }
        $order->update_meta_data('_ncx_cp_payment_source', 'apple_pay_order_pay');
        $order->set_payment_method($this->id);
        $order->set_payment_method_title(__('Apple Pay', 'ncx-cp-api'));
        $order->save();

        $env_error = $this->validate_checkout_environment_consistency($order);
        if (is_wp_error($env_error)) {
            $this->applepay_log('process: pay for order environment mismatch', [
                'order_id' => $order->get_id(),
                'error'    => $env_error->get_error_message(),
            ]);
            wp_send_json_error(['message' => $env_error->get_error_message()]);
            return;
        }

        $this->applepay_log('process: creating pay for order checkout session', [
            'order_id' => $order->get_id(),
            'total'    => $order->get_total(),
            'use_test' => $use_test,
        ]);

        $session = $this->request_apple_pay_checkout_session($order);
        if (is_wp_error($session)) {
            $this->applepay_log('process: pay for order session failed', [
                'order_id' => $order->get_id(),
                'error'    => $session->get_error_message(),
            ]);
            wp_send_json_error(['message' => $session->get_error_message()]);
            return;
        }

        $checkout_id = (string) ($session['id'] ?? '');
        if ('' === $checkout_id) {
            $this->applepay_log('process: pay for order session returned no checkout ID', [
                'order_id' => $order->get_id(),
            ]);
            wp_send_json_error(['message' => __('Apple Pay checkout session could not be created.', 'ncx-cp-api')]);
            return;
        }

        $order->update_meta_data(self::CHECKOUT_META_KEY, $checkout_id);
        // Written outside the coordinator, so clear the recorded session kind
        // rather than leave the previous one describing this checkout ID. An
        // absent kind is never reused, which is the fail-safe direction.
        $order->delete_meta_data(self::PROVIDER_CHECKOUT_KIND_META_KEY);
        if ('pending' !== $order->get_status()) {
            $order->update_status('pending', __('Customer retrying payment with Apple Pay.', 'ncx-cp-api'));
        }
        $order->save();

        $shopper_result_url = add_query_arg(
            [
                'order_id'  => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ],
            WC()->api_request_url(self::CALLBACK_ACTION)
        );

        $this->applepay_log('process: pay for order success response sent', [
            'order_id'    => $order->get_id(),
            'checkout_id' => $checkout_id,
        ]);

        wp_send_json_success([
            'order_id'         => $order->get_id(),
            'order_key'        => $order->get_order_key(),
            'checkoutId'       => $checkout_id,
            'environment'      => $use_test ? 'test' : 'live',
            'regionHost'       => $this->get_region_host($use_test),
            'shopperResultUrl' => $shopper_result_url,
        ]);
    }

    /**
     * Creates a Google Pay session for an existing order and returns it to the client.
     *
     * This mirrors the established Apple Pay order-pay path and deliberately
     * does not rebuild the order from the current cart.
     */
    private function send_google_pay_order_pay_response(WC_Order $order): void {
        $use_test = $this->resolve_use_test($order);
        if (!$order->get_meta(self::ENVIRONMENT_META_KEY)) {
            $order->update_meta_data(self::ENVIRONMENT_META_KEY, $use_test ? 'test' : 'live');
        }
        $order->update_meta_data('_ncx_cp_payment_source', 'google_pay_order_pay');
        $order->set_payment_method($this->id);
        $order->set_payment_method_title(__('Google Pay', 'ncx-cp-api'));
        $order->save();

        $env_error = $this->validate_checkout_environment_consistency($order);
        if (is_wp_error($env_error)) {
            $this->googlepay_log('process: pay for order environment mismatch', [
                'order_id' => $order->get_id(),
                'error'    => $env_error->get_error_message(),
            ]);
            wp_send_json_error(['message' => $env_error->get_error_message()]);
            return;
        }

        $this->googlepay_log('process: creating pay for order checkout session', [
            'order_id' => $order->get_id(),
            'total'    => $order->get_total(),
            'use_test' => $use_test,
        ]);

        $session = $this->request_google_pay_checkout_session($order);
        if (is_wp_error($session)) {
            $this->googlepay_log('process: pay for order session failed', [
                'order_id' => $order->get_id(),
                'error'    => $session->get_error_message(),
            ]);
            wp_send_json_error(['message' => $session->get_error_message()]);
            return;
        }

        $checkout_id = (string) ($session['id'] ?? '');
        if ('' === $checkout_id) {
            $this->googlepay_log('process: pay for order session returned no checkout ID', [
                'order_id' => $order->get_id(),
            ]);
            wp_send_json_error(['message' => __('Google Pay checkout session could not be created.', 'ncx-cp-api')]);
            return;
        }

        $order->update_meta_data(self::CHECKOUT_META_KEY, $checkout_id);
        // Order-pay is outside the coordinator. An absent kind is deliberately
        // not reusable, preventing this wallet checkout from being served to a
        // card or Apple Pay widget later.
        $order->delete_meta_data(self::PROVIDER_CHECKOUT_KIND_META_KEY);
        if ('pending' !== $order->get_status()) {
            $order->update_status('pending', __('Customer retrying payment with Google Pay.', 'ncx-cp-api'));
        }
        $order->save();

        $shopper_result_url = add_query_arg(
            [
                'order_id'  => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ],
            WC()->api_request_url(self::CALLBACK_ACTION)
        );

        $this->googlepay_log('process: pay for order success response sent', [
            'order_id'    => $order->get_id(),
            'checkout_id' => $checkout_id,
        ]);

        wp_send_json_success([
            'order_id'         => $order->get_id(),
            'order_key'        => $order->get_order_key(),
            'checkoutId'       => $checkout_id,
            'environment'      => $use_test ? 'test' : 'live',
            'regionHost'       => $this->get_region_host($use_test),
            'shopperResultUrl' => $shopper_result_url,
        ]);
    }

    /**
     * AJAX logger for browser-side Apple Pay widget events.
     */
    public function ajax_applepay_client_log(): void {
        if (!check_ajax_referer('ncx_cp_checkout_nonce', 'security', false)) {
            $this->applepay_log('client: nonce verification failed');
            wp_send_json_error(['message' => __('Security check failed.', 'ncx-cp-api')]);
            return;
        }

        $event       = isset($_POST['event']) ? sanitize_text_field(wp_unslash($_POST['event'])) : 'unknown';
        $checkout_id = isset($_POST['checkout_id']) ? sanitize_text_field(wp_unslash($_POST['checkout_id'])) : '';
        $this->handle_client_payment_lifecycle_event($event, $checkout_id);
        if (!$this->apple_pay_logging) {
            wp_send_json_success(['logged' => false]);
            return;
        }
        $details     = [];

        if (isset($_POST['details'])) {
            $raw_details = wp_unslash($_POST['details']);
            $decoded     = json_decode((string) $raw_details, true);
            if (is_array($decoded)) {
                $details = $decoded;
            }
        }

        $this->applepay_log('client: ' . $event, [
            'checkout_id' => $checkout_id,
            'details'     => $details,
            'user_agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 300) : '',
        ]);

        // When the widget reports a payment error, ask OPP directly for the real
        // payment result of this checkout so the opaque "Failure reason N" gets
        // resolved into an actual OPP result code/description in the log.
        if ('onError' === $event && '' !== $checkout_id) {
            $this->log_checkout_payment_status($checkout_id);
        }

        wp_send_json_success(['logged' => true]);
    }

    /**
     * AJAX logger for browser-side Google Pay widget events.
     */
    public function ajax_googlepay_client_log(): void {
        if (!check_ajax_referer('ncx_cp_checkout_nonce', 'security', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'ncx-cp-api')]);
            return;
        }

        $event = isset($_POST['event']) ? sanitize_text_field(wp_unslash($_POST['event'])) : 'unknown';
        $checkout_id = isset($_POST['checkout_id']) ? sanitize_text_field(wp_unslash($_POST['checkout_id'])) : '';
        $this->handle_client_payment_lifecycle_event($event, $checkout_id);
        if (!$this->google_pay_logging) {
            wp_send_json_success(['logged' => false]);
            return;
        }
        $details = [];

        if (isset($_POST['details'])) {
            $decoded = json_decode((string) wp_unslash($_POST['details']), true);
            if (is_array($decoded)) {
                $details = $decoded;
            }
        }

        $this->googlepay_log('client: ' . $event, [
            'checkout_id' => $checkout_id,
            'details' => $details,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 300) : '',
        ]);

        wp_send_json_success(['logged' => true]);
    }

    private function handle_client_payment_lifecycle_event(string $event, string $checkout_id): void {
        if ('onCancel' !== $event && 'productOnCancel' !== $event) {
            return;
        }
        $checkout_id = $this->normalize_opp_checkout_id($checkout_id);
        if ('' === $checkout_id) {
            return;
        }
        $order = $this->get_canonical_ncx_order();
        if (!$order instanceof WC_Order
            || !hash_equals(
                $this->normalize_opp_checkout_id((string) $order->get_meta(self::CHECKOUT_META_KEY, true)),
                $checkout_id
            )
        ) {
            return;
        }
        $this->mark_cancelled_payment_attempt(
            $order,
            __('Customer cancelled the wallet payment before completion.', 'ncx-cp-api')
        );
    }

    /**
     * Query OPP for the payment result of a checkout and log the real result code.
     *
     * Uses GET /v1/checkouts/{id}/payment so we can surface the underlying OPP
     * decline reason behind a widget-side "Failure reason N" message.
     */
    private function log_checkout_payment_status(string $checkout_id): void {
        $use_test    = (bool) $this->test_mode;
        $credentials = $this->get_active_credentials($use_test);
        if (!$this->credentials_present($credentials)) {
            $this->applepay_log('status: credentials missing, cannot query checkout payment');
            return;
        }

        $checkout_id = $this->normalize_opp_checkout_id($checkout_id);
        if ('' === $checkout_id) {
            return;
        }

        $url = $this->get_region_host($use_test) . '/v1/checkouts/' . rawurlencode($checkout_id) . '/payment';
        $url = add_query_arg('entityId', $credentials['entity_id'], $url);

        $response = $this->opp_http_request('GET', $url, [
            'timeout' => 30,
            'headers' => $this->build_auth_headers($credentials),
        ]);

        if (is_wp_error($response)) {
            $this->applepay_log('status: transport error querying checkout payment', [
                'checkout_id' => $checkout_id,
                'error'       => $response->get_error_message(),
            ]);
            return;
        }

        $status_code = $this->http_response_code($response);
        $body        = $this->http_response_body($response);
        $data        = json_decode($body, true);

        if (!is_array($data)) {
            $this->applepay_log('status: non-JSON response querying checkout payment', [
                'checkout_id' => $checkout_id,
                'status_code' => $status_code,
                'body_sample' => mb_substr(wp_strip_all_tags((string) $body), 0, 500),
            ]);
            return;
        }

        $result_code = (string) ($data['result']['code'] ?? '');
        $note        = '';
        if ('200.300.404' === $result_code) {
            // The widget already consumed the one-time payment-status read, or OPP
            // rejected the Apple Pay submission before persisting a transaction.
            // Either way the real decline reason is only in the OPP back office.
            $note = 'No payment persisted for this checkout (real reason is in the OPP/PAYSTRAX back office for this checkout ID).';
        }

        $this->applepay_log('status: OPP checkout payment result', [
            'checkout_id'    => $checkout_id,
            'status_code'    => $status_code,
            'result_code'    => $result_code,
            'result_message' => (string) ($data['result']['description'] ?? ''),
            'payment_brand'  => (string) ($data['paymentBrand'] ?? ''),
            'payment_type'   => (string) ($data['paymentType'] ?? ''),
            'amount'         => (string) ($data['amount'] ?? ''),
            'currency'       => (string) ($data['currency'] ?? ''),
            'note'           => $note,
        ]);
    }

    /**
     * Reuse an existing pending Apple Pay order for this checkout session when possible.
     *
     * @param array<string, mixed> $billing_contact
     * @param array<string, mixed> $shipping_contact
     * @return array{order: WC_Order|WP_Error, reused: bool}
     */
    private function get_or_create_apple_pay_order(array $billing_contact = [], array $shipping_contact = []) {
        return $this->coordinate_wallet_order($this->requested_wallet_mode(), $billing_contact, $shipping_contact);
    }

    private function get_stored_apple_pay_pending_order_id(): int {
        $order = $this->get_canonical_ncx_order();

        return $order instanceof WC_Order ? (int) $order->get_id() : 0;
    }

    private function store_apple_pay_pending_order_session(int $order_id): void {
        $this->store_canonical_order_pointer($order_id);
    }

    private function get_or_create_google_pay_order(array $billing_contact = [], array $shipping_contact = []) {
        return $this->get_or_create_apple_pay_order($billing_contact, $shipping_contact);
    }

    private function store_google_pay_pending_order_session(int $order_id): void {
        $this->store_apple_pay_pending_order_session($order_id);
    }

    private function clear_apple_pay_pending_order_session(?int $order_id = null): void {
        $this->clear_canonical_order_pointer($order_id);
    }

    private function get_reusable_apple_pay_order(): ?WC_Order {
        $order_id = $this->get_stored_apple_pay_pending_order_id();
        if ($order_id <= 0) {
            return null;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return null;
        }

        if ($order->get_payment_method() !== $this->id) {
            return null;
        }

        if ($order->is_paid()) {
            return null;
        }

        if (!in_array($order->get_status(), ['pending', 'failed'], true)) {
            return null;
        }

        if (!function_exists('WC') || !WC()->cart) {
            return null;
        }

        $cart_hash = (string) WC()->cart->get_cart_hash();
        $order_hash = (string) $order->get_cart_hash();
        if ('' !== $cart_hash && '' !== $order_hash && $cart_hash !== $order_hash) {
            return null;
        }

        return $order;
    }

    private function cancel_stale_apple_pay_order($order): void {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }

        if (!$order instanceof WC_Order || $order->is_paid()) {
            return;
        }

        if ($order->get_payment_method() !== $this->id || !$this->order_matches_current_attempt($order)) {
            return;
        }

        if (!in_array($order->get_status(), ['pending', 'failed', 'checkout-draft'], true)) {
            return;
        }

        $lifecycle = (string) $order->get_meta(self::LIFECYCLE_META_KEY, true);
        if (in_array($lifecycle, ['intent', 'payment_started'], true)) {
            return;
        }
    }

    private function clear_order_line_items(WC_Order $order): void {
        foreach (['line_item', 'fee', 'shipping', 'coupon', 'tax'] as $type) {
            foreach ($order->get_items($type) as $item_id => $item) {
                $order->remove_item((int) $item_id);
            }
        }
    }

    /**
     * Refresh an existing order from the current cart and checkout session.
     *
     * @param array<string, mixed> $billing_contact
     * @param array<string, mixed> $shipping_contact
     * @return WC_Order|WP_Error
     */
    private function refresh_order_from_cart(WC_Order $order, array $billing_contact = [], array $shipping_contact = []) {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return new WP_Error('ncx_cp_empty_cart', __('Cart is empty.', 'ncx-cp-api'));
        }

        $this->clear_order_line_items($order);

        return $this->sync_order_from_cart($order, $billing_contact, $shipping_contact);
    }

    /**
     * Recalculate the cart so total-modifying plugins apply to this request.
     *
     * Cart fees do not persist: WooCommerce only fires
     * woocommerce_cart_calculate_fees from WC_Cart::calculate_totals(), and a
     * plain AJAX request restores stored totals from the session without
     * recalculating. Anything expressed as a fee — wallet credit, gift cards,
     * payment-method surcharges — is therefore absent from WC()->cart->get_fees()
     * here even though the stored total already reflects it. WC_Checkout does
     * the same recalculation in update_session() during an ordinary checkout
     * POST, which is why the card path is unaffected.
     */
    private function recalculate_cart_for_apple_pay(): void {
        if ($this->cart_recalculated_for_apple_pay || !function_exists('WC') || !WC()->cart) {
            return;
        }

        // Plugins that gate their totals on is_checkout() must see the same
        // context they would during a checkout POST.
        if (function_exists('wc_maybe_define_constant')) {
            wc_maybe_define_constant('WOOCOMMERCE_CHECKOUT', true);
        }

        WC()->cart->calculate_totals();
        $this->cart_recalculated_for_apple_pay = true;
    }

    /**
     * Cart total as stored, bypassing the woocommerce_cart_get_total display
     * filter so the comparison is against the cart's own line totals.
     */
    private function get_unfiltered_cart_total(): float {
        if (!function_exists('WC') || !WC()->cart) {
            return 0.0;
        }

        $totals = WC()->cart->get_totals();

        return isset($totals['total']) ? (float) $totals['total'] : 0.0;
    }

    /**
     * Refuse to charge an amount the shopper was never shown.
     *
     * An order rebuilt from the cart must reproduce the cart total. When it does
     * not, some cart-level adjustment failed to copy across and the OPP session
     * would be created for the wrong amount.
     *
     * @return WP_Error|null Null when the totals agree.
     */
    private function find_order_cart_total_mismatch(WC_Order $order) {
        if (!function_exists('WC') || !WC()->cart) {
            return null;
        }

        $tolerance = pow(10, -wc_get_price_decimals()) / 2;
        if (abs($this->get_unfiltered_cart_total() - (float) $order->get_total()) < $tolerance) {
            return null;
        }

        return new WP_Error(
            'ncx_cp_total_mismatch',
            __('The order total has changed. Please refresh the page and try again.', 'ncx-cp-api')
        );
    }

    /**
     * Build a WooCommerce order from the current cart and Apple Pay contact data.
     *
     * @param array<string, mixed> $billing_contact
     * @param array<string, mixed> $shipping_contact
     * @return WC_Order|WP_Error
     */
    private function create_order_from_cart(array $billing_contact = [], array $shipping_contact = []) {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return new WP_Error('ncx_cp_empty_cart', __('Cart is empty.', 'ncx-cp-api'));
        }

        $customer_id = get_current_user_id();
        $order       = wc_create_order([
            'customer_id' => $customer_id > 0 ? $customer_id : 0,
            'status'      => 'pending',
        ]);

        if (is_wp_error($order)) {
            $this->applepay_log('create_order_from_cart: wc_create_order failed', [
                'error' => $order->get_error_message(),
            ]);
            return $order;
        }

        return $this->sync_order_from_cart($order, $billing_contact, $shipping_contact);
    }

    /**
     * Populate an order from the current cart, customer session, and Apple Pay contacts.
     *
     * @param array<string, mixed> $billing_contact
     * @param array<string, mixed> $shipping_contact
     * @return WC_Order|WP_Error
     */
    private function sync_order_from_cart(WC_Order $order, array $billing_contact = [], array $shipping_contact = []) {
        // create_order_fee_lines() below reads WC()->cart->get_fees(), which is
        // only populated once fees have been calculated in this request.
        $this->recalculate_cart_for_apple_pay();

        $checkout = WC()->checkout();
        if ($checkout) {
            try {
                $chosen_shipping_methods = WC()->session ? (array) WC()->session->get('chosen_shipping_methods', []) : [];
                $packages = (function_exists('WC') && WC()->shipping()) ? WC()->shipping()->get_packages() : [];

                $checkout->create_order_line_items($order, WC()->cart);
                $checkout->create_order_fee_lines($order, WC()->cart);
                $checkout->create_order_shipping_lines($order, $chosen_shipping_methods, $packages);
                $checkout->create_order_tax_lines($order, WC()->cart);
                $checkout->create_order_coupon_lines($order, WC()->cart);
            } catch (\Exception $e) {
                $this->applepay_log('sync_order_from_cart: failed building order lines', [
                    'error' => $e->getMessage(),
                ]);
                return new WP_Error('ncx_cp_order_lines', $e->getMessage());
            }
        } else {
            $this->applepay_log('sync_order_from_cart: WC()->checkout() unavailable');
        }

        $this->apply_apple_pay_contact_to_order($order, 'billing', $billing_contact);

        if (WC()->cart->needs_shipping()) {
            $this->apply_apple_pay_contact_to_order($order, 'shipping', $shipping_contact);
            if (!empty($shipping_contact['emailAddress']) && !$order->get_billing_email()) {
                $order->set_billing_email(sanitize_email((string) $shipping_contact['emailAddress']));
            }
            if (!empty($shipping_contact['phoneNumber']) && !$order->get_billing_phone()) {
                $order->set_billing_phone($this->sanitize_opp_field((string) $shipping_contact['phoneNumber'], 32));
            }
        }

        $this->fill_order_address_from_customer($order, 'billing');
        if (WC()->cart->needs_shipping()) {
            $this->fill_order_address_from_customer($order, 'shipping');
        } else {
            $this->copy_billing_address_to_shipping($order);
        }

        $order->set_payment_method($this);
        $order->set_payment_method_title($this->get_title());
        $order->set_currency(get_woocommerce_currency());
        $order->set_prices_include_tax('yes' === get_option('woocommerce_prices_include_tax'));
        $order->set_customer_ip_address(class_exists('WC_Geolocation') ? WC_Geolocation::get_ip_address() : '');
        $order->set_customer_user_agent(wc_get_user_agent());
        $order->set_created_via('checkout');

        $cart_hash = WC()->cart->get_cart_hash();
        if ('' !== $cart_hash) {
            $order->set_cart_hash($cart_hash);
        }

        $order->calculate_totals();
        $order->save();

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function parse_apple_pay_contact_from_request(string $field): array {
        if (!isset($_POST[$field])) {
            return [];
        }

        $raw = wp_unslash($_POST[$field]);
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $contact
     */
    private function apply_apple_pay_contact_to_order(WC_Order $order, string $type, array $contact): void {
        if (empty($contact)) {
            return;
        }

        $prefix = 'billing' === $type ? 'billing' : 'shipping';

        if (!empty($contact['givenName'])) {
            $order->{"set_{$prefix}_first_name"}($this->sanitize_opp_field((string) $contact['givenName'], 64));
        }
        if (!empty($contact['familyName'])) {
            $order->{"set_{$prefix}_last_name"}($this->sanitize_opp_field((string) $contact['familyName'], 64));
        }

        $lines = $contact['addressLines'] ?? [];
        if (!empty($lines[0])) {
            $order->{"set_{$prefix}_address_1"}($this->sanitize_opp_field((string) $lines[0], 128));
        }
        if (!empty($lines[1])) {
            $order->{"set_{$prefix}_address_2"}($this->sanitize_opp_field((string) $lines[1], 128));
        }
        if (!empty($contact['locality'])) {
            $order->{"set_{$prefix}_city"}($this->sanitize_opp_field((string) $contact['locality'], 128));
        }
        if (!empty($contact['administrativeArea'])) {
            $order->{"set_{$prefix}_state"}($this->sanitize_opp_field((string) $contact['administrativeArea'], 128));
        }
        if (!empty($contact['postalCode'])) {
            $order->{"set_{$prefix}_postcode"}($this->sanitize_opp_field((string) $contact['postalCode'], 32));
        }
        if (!empty($contact['countryCode'])) {
            $order->{"set_{$prefix}_country"}(strtoupper(sanitize_text_field((string) $contact['countryCode'])));
        }

        if ('billing' === $type) {
            if (!empty($contact['emailAddress'])) {
                $order->set_billing_email(sanitize_email((string) $contact['emailAddress']));
            }
            if (!empty($contact['phoneNumber'])) {
                $order->set_billing_phone($this->sanitize_opp_field((string) $contact['phoneNumber'], 32));
            }
        }
    }

    private function fill_order_address_from_customer(WC_Order $order, string $type): void {
        if (!function_exists('WC') || !WC()->customer) {
            return;
        }

        $customer = WC()->customer;
        $prefix   = 'billing' === $type ? 'billing' : 'shipping';
        $fields   = [
            'first_name' => 'get_' . $prefix . '_first_name',
            'last_name'  => 'get_' . $prefix . '_last_name',
            'company'    => 'get_' . $prefix . '_company',
            'address_1'  => 'get_' . $prefix . '_address_1',
            'address_2'  => 'get_' . $prefix . '_address_2',
            'city'       => 'get_' . $prefix . '_city',
            'state'      => 'get_' . $prefix . '_state',
            'postcode'   => 'get_' . $prefix . '_postcode',
            'country'    => 'get_' . $prefix . '_country',
        ];

        foreach ($fields as $field => $getter) {
            $current = $order->{"get_{$prefix}_{$field}"}();
            if ('' !== (string) $current) {
                continue;
            }
            $value = (string) $customer->{$getter}();
            if ('' !== $value) {
                $order->{"set_{$prefix}_{$field}"}($value);
            }
        }

        if ('billing' === $type) {
            if (!$order->get_billing_email()) {
                $order->set_billing_email($customer->get_billing_email());
            }
            if (!$order->get_billing_phone()) {
                $order->set_billing_phone($customer->get_billing_phone());
            }
        }
    }

    private function copy_billing_address_to_shipping(WC_Order $order): void {
        $order->set_shipping_first_name($order->get_billing_first_name());
        $order->set_shipping_last_name($order->get_billing_last_name());
        $order->set_shipping_company($order->get_billing_company());
        $order->set_shipping_address_1($order->get_billing_address_1());
        $order->set_shipping_address_2($order->get_billing_address_2());
        $order->set_shipping_city($order->get_billing_city());
        $order->set_shipping_state($order->get_billing_state());
        $order->set_shipping_postcode($order->get_billing_postcode());
        $order->set_shipping_country($order->get_billing_country());
    }

    /**
     * Phase 2: bind order data to an existing checkout ID.
     * POST to /v1/checkouts/{id} (mirrors nochexapi\'s updateTransactionData).
     */
    private function update_checkout_data(string $checkout_id, WC_Order $order, bool $create_registration = false, string $payment_container = 'card') {
        $checkout_id = $this->normalize_opp_checkout_id($checkout_id);
        if ('' === $checkout_id) {
            return new WP_Error('ncx_cp_invalid_checkout', __('Invalid checkout session.', 'ncx-cp-api'));
        }
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
        ];

        $this->apply_merchant_url_parameter($body);

        $body['shopperResultUrl'] = add_query_arg(
            [
                'order_id' => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ],
            WC()->api_request_url(self::CALLBACK_ACTION)
        );
        $body['notificationUrl'] = add_query_arg(
                [
                    'order_id'  => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                ],
                WC()->api_request_url(self::NOTIFICATION_ACTION)
            );
        $body['customParameters[SHOPPER_amount]']    = $this->format_amount($order->get_total());
        $body['customParameters[SHOPPER_currency]']  = $order->get_currency();
        $body['customParameters[SHOPPER_order_key]'] = $order->get_order_key();
        $body['customParameters[SHOPPER_cart_hash]']  = $order->get_cart_hash();
        $body['customParameters[SHOPPER_platform]']  = 'WooCommerce';
        $body['customParameters[SHOPPER_plugin]']    = NCX_CP_API::VERSION;
        $generation = (int) $order->get_meta(self::GENERATION_META_KEY, true);
        if ($generation > 0) {
            $body['customParameters[SHOPPER_generation]'] = (string) $generation;
        }

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
			untrailingslashit($region_host) . '/v1/checkouts/' . rawurlencode($this->normalize_opp_checkout_id($checkout_id))
		);
		$request_headers = $this->build_opp_outbound_headers($credentials);
		$request_log     = $this->build_opp_outbound_log_context($update_url, $request_headers, $body, $credentials, $use_test);

       $response = $this->opp_http_request(
			'POST',
			$update_url,
			[
				'timeout' => 60,
				'headers' => $request_headers,
				'body'    => $body,
			]
		);

        if (is_wp_error($response)) {
            $this->log_event('error', 'update_checkout_data transport error', array_merge(
                $request_log,
                [
                    'error'      => $response->get_error_message(),
                    'error_code' => $response->get_error_code(),
                ]
            ));
            return $response;
        }

        $raw_body  = $this->http_response_body($response);
        $http_code = $this->http_response_code($response);
        $data      = json_decode($raw_body, true);
        $is_json   = is_array($data);
        $code      = $is_json ? ($data['result']['code'] ?? 'unknown') : 'non_json';

        if ('000.200.101' !== $code) {
            $desc = $is_json
                ? ($data['result']['description'] ?? 'Failed to update checkout')
                : 'Failed to update checkout (non-JSON response)';

            $this->log_event('error', 'update_checkout_data failed: ' . $code . ' - ' . $desc . ' (HTTP ' . $http_code . ')', array_merge(
                $request_log,
                [
                    'http_code'        => $http_code,
                    'response_is_json' => $is_json,
                    'response_preview' => mb_substr($raw_body, 0, 2000),
                    'response_headers' => $this->http_response_headers($response),
                    'parameter_errors' => $is_json ? ($data['result']['parameterErrors'] ?? []) : [],
                    'opp_result'       => $is_json ? ($data['result'] ?? []) : [],
                    'opp_ndc'          => $is_json ? ($data['ndc'] ?? '') : '',
                ]
            ));
            return new WP_Error('ncx_cp_update_failed', $desc);
        }

        return $data;
    }

    /**
     * Common helper: POST to /v1/checkouts and return the parsed response.
     */
    private function post_to_checkouts(array $credentials, array $body, ?bool $force_test = null) {
        // Issues a /v1/checkouts request and returns either the checkout payload or WP_Error.
        $this->apply_merchant_url_parameter($body);

        $request_url = $this->get_region_host($force_test) . '/v1/checkouts';
        $request_body = $body;

        if ($this->use_opp_entity_in_query() && isset($request_body['entityId'])) {
            $request_url = add_query_arg('entityId', $request_body['entityId'], $request_url);
            unset($request_body['entityId']);
        }

        $request_headers = $this->build_opp_outbound_headers($credentials);
        $request_log     = $this->build_opp_outbound_log_context($request_url, $request_headers, $body, $credentials, $force_test);

        $response = $this->opp_http_request(
            'POST',
            $request_url,
            [
                'timeout' => 60,
                'headers' => $request_headers,
                'body'    => $request_body,
            ]
        );

        if (is_wp_error($response)) {
            $this->log_event('error', 'post_to_checkouts transport error', array_merge(
                $request_log,
                [
                    'error'      => $response->get_error_message(),
                    'error_code' => $response->get_error_code(),
                ]
            ));
            return $response;
        }

        $raw_body  = $this->http_response_body($response);
        $http_code = $this->http_response_code($response);
        $headers   = $this->http_response_headers($response);
        $data      = json_decode($raw_body, true);
        $is_json   = is_array($data);

        if (!isset($data['id'])) {
            $code = $is_json ? ($data['result']['code'] ?? 'unknown') : 'non_json';
            if ($is_json) {
                $desc = $data['result']['description'] ?? 'No checkout ID returned';
            } else {
                $response_server = (string) ($headers['server'] ?? $headers['Server'] ?? '');
                $akamai_blocked    = 403 === $http_code
                    && (false !== stripos($response_server, 'Akamai') || false !== stripos($raw_body, 'Access Denied'));
                if ($akamai_blocked) {
                    $desc = sprintf(
                        /* translators: %s: edge server name, e.g. AkamaiGHost */
                        __('Outbound API blocked by %s (HTTP 403). Ask PAYSTRAX to whitelist this server\'s outbound IP.', 'ncx-cp-api'),
                        $response_server ?: 'Akamai'
                    );
                } else {
                    $desc = sprintf(
                        __('No checkout ID returned (HTTP %d, non-JSON response)', 'ncx-cp-api'),
                        $http_code
                    );
                }
            }

            $this->log_event('error', 'Checkout session failed: ' . $code . ' - ' . $desc . ' (HTTP ' . $http_code . ')', array_merge(
                $request_log,
                [
                    'http_code'        => $http_code,
                    'response_is_json' => $is_json,
                    'response_preview' => mb_substr($raw_body, 0, 2000),
                    'response_headers' => $headers,
                    'opp_result'       => $is_json ? ($data['result'] ?? []) : [],
                    'parameter_errors' => $is_json ? ($data['result']['parameterErrors'] ?? []) : [],
                    'opp_ndc'          => $is_json ? ($data['ndc'] ?? '') : '',
                ]
            ));

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

        $normalized_path = $this->normalize_opp_resource_path($resource_path);
        if ('' === $normalized_path) {
            return new WP_Error('ncx_cp_invalid_resource', __('Invalid payment reference.', 'ncx-cp-api'));
        }

        $region_host = $this->get_region_host($use_test);
        $url = $region_host . $normalized_path;
        $url = add_query_arg('entityId', $credentials['entity_id'], $url);

        $this->applepay_log('fetch_payment_details: request', [
            'order_id'      => $order ? $order->get_id() : 0,
            'use_test'      => $use_test,
            'resource_path' => $resource_path,
            'url'           => $this->redact_opp_request_url($url),
        ]);

        $response = $this->opp_http_request(
            'GET',
            $url,
            [
                'timeout' => 60,
                'headers' => $this->build_auth_headers($credentials),
            ]
        );

        if (is_wp_error($response)) {
            $this->applepay_log('fetch_payment_details: transport failed', [
                'order_id' => $order ? $order->get_id() : 0,
                'error'    => $response->get_error_message(),
            ]);
            return $response;
        }

        $status_code = $this->http_response_code($response);
        $body        = $this->http_response_body($response);
        $data        = json_decode($body, true);
        if (!is_array($data) || empty($data['result']['code'])) {
            $this->applepay_log('fetch_payment_details: invalid response', [
                'order_id'    => $order ? $order->get_id() : 0,
                'status_code' => $status_code,
                'body_sample' => mb_substr(wp_strip_all_tags((string) $body), 0, 500),
            ]);
            return new WP_Error('ncx_cp_invalid_result', __('Invalid response from COPYandPAY.', 'ncx-cp-api'));
        }

        $this->applepay_log('fetch_payment_details: response received', [
            'order_id'       => $order ? $order->get_id() : 0,
            'status_code'    => $status_code,
            'result_code'    => (string) ($data['result']['code'] ?? ''),
            'result_message' => (string) ($data['result']['description'] ?? ''),
            'payment_brand'  => (string) ($data['paymentBrand'] ?? ''),
        ]);

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
     * Outbound headers including User-Agent (matches WordPress HTTP defaults when omitted).
     */
    private function build_opp_outbound_headers(array $credentials): array {
        $headers = $this->build_auth_headers($credentials);
        if (!isset($headers['User-Agent']) && !isset($headers['user-agent'])) {
            $headers['User-Agent'] = apply_filters(
                'http_headers_useragent',
                'WordPress/' . get_bloginfo('version') . '; ' . home_url('/')
            );
        }
        return $headers;
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

        $response = $this->opp_http_request(
            'DELETE',
            $url,
            [
                'timeout' => 15,
                'headers' => $this->build_opp_outbound_headers($credentials),
            ]
        );

        if (is_wp_error($response)) {
            $this->log_event('error', 'Token deregistration failed', [
                'token_id' => $token_id,
                'error'    => $response->get_error_message(),
            ]);
            return;
        }

        $data = json_decode($this->http_response_body($response), true);
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

    private function is_definitive_payment_failure(array $payload): bool {
        $code = (string) ($payload['result']['code'] ?? '');
        foreach (self::DEFINITIVE_DECLINE_PREFIXES as $prefix) {
            if (0 === strpos($code, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function is_payment_cancellation(array $payload): bool {
        $code = (string) ($payload['result']['code'] ?? '');

        return in_array($code, self::DEFINITIVE_CANCEL_CODES, true);
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
        $custom = is_array($payment['customParameters'] ?? null) ? $payment['customParameters'] : [];
        if (!$this->payment_generation_is_current($order, $payment)) {
            return new WP_Error('ncx_cp_stale_generation', 'Stale checkout generation');
        }

        $expected_amount = $this->format_amount($order->get_total());
        $top_amount = (string) ($payment['amount'] ?? '');
        if ('' === $top_amount || $expected_amount !== $this->format_amount($top_amount)) {
            return new WP_Error('ncx_cp_amount_mismatch', sprintf(
                'Amount mismatch: expected %s, got %s',
                $expected_amount,
                $top_amount
            ));
        }

        $top_currency = (string) ($payment['currency'] ?? '');
        if ('' === $top_currency || $order->get_currency() !== $top_currency) {
            return new WP_Error('ncx_cp_currency_mismatch', sprintf(
                'Currency mismatch: expected %s, got %s',
                $order->get_currency(),
                $top_currency
            ));
        }

        $merchant_txn = (string) ($payment['merchantTransactionId'] ?? '');
        if ((string) $order->get_id() !== $merchant_txn) {
            return new WP_Error('ncx_cp_txn_mismatch', 'merchantTransactionId mismatch');
        }

        foreach (['SHOPPER_amount', 'SHOPPER_currency', 'SHOPPER_order_key', 'SHOPPER_cart_hash'] as $param) {
            if (!isset($custom[$param]) || '' === (string) $custom[$param]) {
                return new WP_Error('ncx_cp_custom_missing', 'Missing custom payment parameters');
            }
        }

        if ($expected_amount !== (string) $custom['SHOPPER_amount']) {
            return new WP_Error('ncx_cp_amount_mismatch', 'Amount mismatch');
        }
        if ($order->get_currency() !== (string) $custom['SHOPPER_currency']) {
            return new WP_Error('ncx_cp_currency_mismatch', 'Currency mismatch');
        }
        if ($order->get_order_key() !== (string) $custom['SHOPPER_order_key']) {
            return new WP_Error('ncx_cp_key_mismatch', 'Order key mismatch');
        }
        if ($order->get_cart_hash() !== (string) $custom['SHOPPER_cart_hash']) {
            return new WP_Error('ncx_cp_hash_mismatch', 'Cart hash mismatch');
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
    private function normalize_opp_checkout_id(string $checkout_id): string {
        $checkout_id = trim($checkout_id);
        if ('' === $checkout_id) {
            return '';
        }
        if (false !== strpbrk($checkout_id, '/?#') || false !== strpos($checkout_id, '..')) {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $checkout_id)) {
            return '';
        }

        return $checkout_id;
    }

    private function normalize_opp_resource_path(string $resource_path): string {
        $resource_path = trim($resource_path);
        if ('' === $resource_path) {
            return '';
        }

        $parts = wp_parse_url($resource_path);
        $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : strtok($resource_path, '?');
        $path = '/' . ltrim((string) $path, '/');
        if (!preg_match('#^/v1/checkouts/([A-Za-z0-9._-]+)(/payment)?$#', $path, $matches)) {
            return '';
        }

        $encoded = rawurlencode($matches[1]);
        $suffix = isset($matches[2]) ? $matches[2] : '';

        return '/v1/checkouts/' . $encoded . $suffix;
    }

    private function get_request_checkout_id(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (isset($_POST['ncx_cp_checkout_id'])) {
            $checkout_id = $this->normalize_opp_checkout_id(sanitize_text_field(wp_unslash($_POST['ncx_cp_checkout_id'])));
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
                $checkout_id = $this->normalize_opp_checkout_id(sanitize_text_field((string) $item['value']));
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

    // Reads the admin minimum-payment setting, never returning less than the 50p hard floor.
    private function get_min_payment_amount(): float {
        $configured = wc_format_decimal($this->get_option('minimum_payment_amount', (string) self::MIN_PAYMENT_AMOUNT));
        if (!is_numeric($configured)) {
            return self::MIN_PAYMENT_AMOUNT;
        }

        $amount = (float) $configured;
        if ($amount < self::MIN_PAYMENT_AMOUNT) {
            return self::MIN_PAYMENT_AMOUNT;
        }

        return $amount;
    }

    // Returns true when an order or cart total is strictly below the effective minimum.
    private function amount_below_minimum(float $amount): bool {
        return $amount < $this->get_min_payment_amount();
    }

    // Builds the shopper-facing notice shown when payment is blocked for being too small.
    private function get_minimum_payment_error_message(): string {
        return sprintf(
            /* translators: %s: minimum payment amount */
            __('Amount less than %s is not permitted.', 'ncx-cp-api'),
            wc_price($this->get_min_payment_amount(), ['html_format' => false])
        );
    }

    // Sanitises the admin minimum-payment field on save and enforces the 50p floor.
    public function validate_minimum_payment_amount_field($key, $value) {
        $amount = wc_format_decimal($value);
        if (!is_numeric($amount)) {
            WC_Admin_Settings::add_error(
                sprintf(
                    /* translators: %s: default minimum payment amount */
                    __('Minimum payment amount must be a number. Using %s.', 'ncx-cp-api'),
                    wc_price(self::MIN_PAYMENT_AMOUNT, ['html_format' => false])
                )
            );

            return (string) self::MIN_PAYMENT_AMOUNT;
        }

        $amount = (float) $amount;
        if ($amount < self::MIN_PAYMENT_AMOUNT) {
            WC_Admin_Settings::add_error(
                sprintf(
                    /* translators: 1: attempted amount, 2: enforced minimum amount */
                    __('Minimum payment amount cannot be less than %2$s. Value adjusted from %1$s to %2$s.', 'ncx-cp-api'),
                    wc_price($amount, ['html_format' => false]),
                    wc_price(self::MIN_PAYMENT_AMOUNT, ['html_format' => false])
                )
            );

            return wc_format_decimal(self::MIN_PAYMENT_AMOUNT, wc_get_price_decimals());
        }

        return wc_format_decimal($amount, wc_get_price_decimals());
    }

    // Exposes the effective minimum payable total for checkout scripts and integrations.
    public function get_minimum_payment_amount(): float {
        return $this->get_min_payment_amount();
    }

    // Exposes the translated minimum-payment notice for client-side display.
    public function get_minimum_payment_notice(): string {
        return $this->get_minimum_payment_error_message();
    }

    /**
     * Store domain for OPP merchant.url (WordPress site address).
     */
    private function get_merchant_url(): string {
        return untrailingslashit(home_url('/'));
    }

    /**
     * Attach merchant.url when creating or updating a checkout session.
     */
    private function apply_merchant_url_parameter(array &$body): void {
        $merchant_url = $this->get_merchant_url();
        if ('' !== $merchant_url) {
            $body['merchant.url'] = $merchant_url;
        }
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

    /**
     * Queue a shopper-facing notice, marked as ours.
     *
     * The marker exists so drain_gateway_notices() can remove this plugin's
     * notices without touching any other extension's.
     */
    private function add_gateway_notice(string $message, string $type = 'error'): void {
        if (!function_exists('wc_add_notice')) {
            return;
        }

        wc_add_notice($message, $type, [self::NOTICE_MARKER => '1']);
    }

    /**
     * Remove this plugin's queued error notices and return their text.
     *
     * Blocks checkout never drains the session notice queue the way the classic
     * shortcode does via wc_print_notices(): the hydration service blanks
     * wc_notices before the notice handler reads it and restores it afterwards,
     * so a notice queued by a gateway callback that redirects to the checkout
     * URL is invisible on page load. It then resurfaces on the *next* Store API
     * submission, where the cart validator re-persists it and the checkout
     * route turns it into a failure — a shopper who was declined once can never
     * retry (WooCommerce issues 45446 and 67321).
     *
     * Draining here and rendering the text client-side restores both halves:
     * the shopper sees why the last attempt failed, and the stale notice cannot
     * fail the next one. This is presentation only. Every retry gate — the
     * ambiguous freeze, order_payment_is_closed(), the hand-off grace window
     * and the rate limit — is enforced on order state and reported through
     * send_coordinator_error(), not through this queue.
     *
     * @return array<int, string>
     */
    private function drain_gateway_notices(): array {
        if (!function_exists('wc_get_notices') || !function_exists('wc_set_notices')) {
            return [];
        }

        $all = wc_get_notices();
        if (!is_array($all) || empty($all['error']) || !is_array($all['error'])) {
            return [];
        }

        $messages = [];
        $kept = [];
        foreach ($all['error'] as $notice) {
            $data = is_array($notice) ? ($notice['data'] ?? []) : [];
            if (is_array($data) && !empty($data[self::NOTICE_MARKER])) {
                $text = is_array($notice) ? (string) ($notice['notice'] ?? '') : (string) $notice;
                if ('' !== $text) {
                    $messages[] = wp_strip_all_tags($text);
                }
                continue;
            }
            $kept[] = $notice;
        }

        if (empty($messages)) {
            return [];
        }

        $all['error'] = $kept;
        if (empty($all['error'])) {
            unset($all['error']);
        }
        wc_set_notices($all);

        return $messages;
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
                'old' => ['Pay securely via COPYandPAY.', 'Pay securely through COPYandPAY.', 'Checkout with Card'],
                'new' => 'Pay with Card',
            ],
            // Retired theme. Without this the select would show Light while the stored
            // value stayed soft_gray, so the saved setting and the rendered palette
            // would disagree until the merchant re-saved the page.
            'checkout_theme' => [
                'old' => ['soft_gray'],
                'new' => 'light',
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
     * COPYandPAY brands passed to paymentWidgets (cards plus optional Apple Pay).
     */
    public function get_payment_brands(): string {
        $brands = self::PAYMENT_BRANDS;
        if ($this->is_apple_pay_enabled()) {
            $brands .= ' ' . self::APPLE_PAY_BRAND;
        }
        if ($this->is_google_pay_enabled()) {
            $brands .= ' ' . self::GOOGLE_PAY_BRAND;
        }

        return $brands;
    }

    /**
     * Card-only brands for the inline card widget (excludes Apple Pay).
     */
    public function get_card_payment_brands(): string {
        return self::PAYMENT_BRANDS;
    }

    /**
     * Checkout heading wording for the currently enabled payment methods.
     */
    private function generate_payment_method_title(): string {
        if ($this->enable_apple_pay && $this->enable_google_pay) {
            return __('Pay with card, Apple Pay or Google Pay', 'ncx-cp-api');
        }

        if ($this->enable_apple_pay) {
            return __('Pay with card or Apple Pay', 'ncx-cp-api');
        }

        if ($this->enable_google_pay) {
            return __('Pay with card or Google Pay', 'ncx-cp-api');
        }

        return __('Pay with card', 'ncx-cp-api');
    }

    /**
     * Every heading this plugin has ever generated for itself.
     *
     * Only these are safe to rewrite when the wallets change. Anything else is
     * merchant wording and is left alone.
     *
     * @return array<int, string>
     */
    private function generated_payment_method_titles(): array {
        return [
            __('Pay with card', 'ncx-cp-api'),
            __('Pay with card or Apple Pay', 'ncx-cp-api'),
            __('Pay with card or Google Pay', 'ncx-cp-api'),
            __('Pay with card, Apple Pay or Google Pay', 'ncx-cp-api'),
        ];
    }

    /**
     * Keeps the stored title in step with the enabled wallets.
     *
     * Written back to the option rather than swapped at render time, so the
     * settings screen never shows a heading that differs from the live one.
     */
    private function maybe_sync_generated_title(): void {
        $saved = trim((string) $this->get_option('title', ''));
        $generated = $this->generate_payment_method_title();

        if ($saved === $generated) {
            return;
        }

        // Absent or still one of ours: adopt the wording for the current wallets.
        if ('' !== $saved && !in_array($saved, $this->generated_payment_method_titles(), true)) {
            return;
        }

        $this->settings['title'] = $generated;
        update_option(
            $this->get_option_key(),
            apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings),
            'yes'
        );
    }

    /**
     * Helper line shown under the payment method name, or an empty string when
     * the merchant has switched it off.
     *
     * Gated on its own setting rather than on empty wording: WooCommerce refills
     * an absent option from the field default, so a cleared box does not stay
     * cleared.
     */
    private function resolve_checkout_description(): string {
        if ('yes' !== $this->get_option('show_description', 'yes')) {
            return '';
        }

        return (string) $this->get_option('description', '');
    }

    public function is_apple_pay_enabled(): bool {
        return $this->enable_apple_pay;
    }

    public function is_google_pay_enabled(): bool {
        return $this->enable_google_pay;
    }

    public function is_tokenization_enabled(): bool {
        return $this->allow_card_saving;
    }

    /**
     * Tokenisation is admin-enabled and only offered to logged-in customers at checkout.
     */
    public function client_allows_card_saving(): bool {
        return $this->is_tokenization_enabled() && is_user_logged_in();
    }

    /**
     * wpwlOptions.applePay settings for checkout JS (OPP Axcess COPYandPAY).
     *
     * @return array<string, mixed>
     */
    public function get_apple_pay_client_config(): array {
        if (!$this->is_apple_pay_enabled()) {
            return ['enabled' => '0'];
        }

        $display_name = $this->get_apple_pay_display_name();
        $currency     = function_exists('get_woocommerce_currency')
            ? get_woocommerce_currency()
            : self::MERCHANT_CURRENCY;

        $needs_shipping = function_exists('WC') && WC()->cart && WC()->cart->needs_shipping();

        $config = [
            'enabled'              => '1',
            'displayName'          => $display_name,
            'buttonType'           => 'pay',
            'buttonStyle'          => 'white-outline',
            'buttonSource'         => 'js',
            'brand'                => self::APPLE_PAY_BRAND,
            'countryCode'          => self::MERCHANT_COUNTRY,
            'currencyCode'         => $currency,
            'merchantCapabilities' => ['supports3DS'],
            'supportedNetworks'    => ['visa', 'masterCard', 'amex'],
            'totalLabel'           => $display_name,
            'processUrl'           => WC_AJAX::get_endpoint('ncx_cp_applepay_process'),
            'clientLogUrl'         => WC_AJAX::get_endpoint('ncx_cp_applepay_client_log'),
            'nonce'                => wp_create_nonce('ncx_cp_checkout_nonce'),
            'markUrl'              => plugins_url('../assets/images/apple-pay-mark.svg', __FILE__),
            'requiredBillingContactFields' => ['postalAddress'],
        ];

        if ($needs_shipping) {
            $config['requiredShippingContactFields'] = ['postalAddress', 'name', 'email', 'phone'];
        }

        return $config;
    }

    private function get_apple_pay_display_name(): string {
        $name = trim((string) $this->get_option('apple_pay_display_name', ''));
        if ('' === $name) {
            $name = wp_strip_all_tags((string) get_bloginfo('name'));
        }

        return mb_substr($name, 0, 64);
    }

    /**
     * Google Pay settings consumed by the classic, Blocks, and product scripts.
     *
     * @return array<string, mixed>
     */
    public function get_google_pay_client_config(): array {
        if (!$this->is_google_pay_enabled()) {
            return ['enabled' => '0'];
        }

        $display_name = trim((string) $this->get_option('google_pay_display_name', ''));
        if ('' === $display_name) {
            $display_name = wp_strip_all_tags((string) get_bloginfo('name'));
        }
        $credentials = $this->get_active_credentials();

        return [
            'enabled' => '1',
            'displayName' => mb_substr($display_name, 0, 64),
            'merchantName' => mb_substr($display_name, 0, 64),
            'merchantId' => trim((string) $this->get_option('google_pay_merchant_id', '')),
            'gatewayMerchantId' => (string) ($credentials['entity_id'] ?? ''),
            'environment' => $this->test_mode ? 'TEST' : 'PRODUCTION',
            'allowedAuthMethods' => ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
            'allowedCardNetworks' => ['MASTERCARD', 'VISA'],
            'buttonType' => 'pay',
            'buttonColor' => 'white',
            'buttonSizeMode' => 'fill',
            'billingAddressRequired' => true,
            'billingAddressParameters' => [
                'format' => 'FULL',
                'phoneNumberRequired' => true,
            ],
            'brand' => self::GOOGLE_PAY_BRAND,
            'countryCode' => self::MERCHANT_COUNTRY,
            'currencyCode' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : self::MERCHANT_CURRENCY,
            'processUrl' => WC_AJAX::get_endpoint('ncx_cp_googlepay_process'),
            'clientLogUrl' => WC_AJAX::get_endpoint('ncx_cp_googlepay_client_log'),
            'nonce' => wp_create_nonce('ncx_cp_checkout_nonce'),
        ];
    }

    /**
     * Theme hook class for the widget wrapper, shared by classic and Blocks checkout
     * so merchant CSS can target the selected palette in either.
     */
    public function get_checkout_theme_class(): string {
        return 'ncx-cp-theme-' . sanitize_html_class($this->checkout_theme);
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
            . '.ncx-cp-inline-frame{min-height:110px;}'
            . '#ncx-cp-widget-root:empty,#ncx-cp-applepay-frame:empty,#ncx-cp-googlepay-frame:empty{display:none!important;min-height:0!important;}'
            // While the OPP card widget initialises, hide its raw grey/white
            // skeleton fields so only the clean loader below is visible. We keep
            // the widget rendered (opacity:0, taken out of flow) so its iframes
            // still load and fire onReady reliably.
            . '#ncx-cp-widget-root.ncx-cp-widget-loading{position:absolute;left:0;right:0;opacity:0;pointer-events:none;}'
            . '.ncx-cp-card-loader{display:flex;align-items:center;justify-content:center;gap:10px;min-height:110px;color:#6b7280;font-size:14px;}'
            . '.ncx-cp-card-loader-text{line-height:1.4;}'
            . '.ncx-cp-spinner{width:18px;height:18px;border-radius:50%;border:2px solid ' . esc_attr($this->hex_to_rgba($this->primary_color, 0.25)) . ';border-top-color:' . esc_attr($this->primary_color) . ';display:inline-block;animation:ncxCpSpin 0.7s linear infinite;}'
            . '@keyframes ncxCpSpin{to{transform:rotate(360deg);}}'
            // min-width:0 is load-bearing on narrow screens. These frames are
            // often flex items (product pages render inside form.cart), where
            // the default min-width:auto lets an over-wide child stretch the
            // item past the viewport instead of being contained by it.
            . '.ncx-cp-applepay-frame{min-height:0;margin-bottom:6px;max-width:100%;min-width:0;box-sizing:border-box;}'
            // Compact "box" that holds the real Apple Pay button + the pay-by-card link.
            // Wallet chrome is deliberately excluded from the checkout theme: Apple and
            // Google mandate the button's appearance, so recolouring the surround would
            // only make the enforced button look out of place.
            . '.ncx-cp-applepay-pay{margin:6px 0;padding:8px;border:1px solid #e5e7eb!important;border-radius:' . esc_attr($this->inline_border_radius) . '!important;background:#fff!important;max-width:min(480px,100%);min-width:0;box-sizing:border-box;}'
            // Never show an empty bordered box (prevents a leftover "pill" shape).
            . '.ncx-cp-applepay-pay:empty{display:none!important;border:none!important;padding:0!important;margin:0!important;}'
            // Neutralise the OPP container/box so only our slot is the visible box.
            . '.ncx-cp-applepay-pay .wpwl-container,.ncx-cp-applepay-pay .wpwl-container-applepay,.ncx-cp-applepay-pay .wpwl-group-applepay{max-width:100%!important;width:100%!important;min-height:0!important;margin:0!important;padding:0!important;background:transparent!important;border:none!important;box-shadow:none!important;box-sizing:border-box;}'
            // Actual Apple Pay button: full width of the box.
            // Width is set on the host element, not via --apple-pay-button-width.
            // Apple documents that property as taking a length; a percentage is
            // out of spec and resolves against the shadow tree rather than this
            // box, which can render the button wider than its container.
            . '.ncx-cp-applepay-pay apple-pay-button{'
            . '--apple-pay-button-height:44px;'
            . '--apple-pay-button-border-radius:6px;'
            . '--apple-pay-button-padding:0px;'
            . 'display:block;width:100%;max-width:100%;min-width:0;box-sizing:border-box;'
            . '}'
            // Backstop: whatever OPP or Apple inject into the wallet slots must
            // stay inside them. The wallet chrome is third-party markup we do
            // not control, so containment cannot rely on knowing its classes.
            . '.ncx-cp-applepay-pay>*,.ncx-cp-googlepay-pay>*,'
            . '.ncx-cp-applepay-frame>*,.ncx-cp-googlepay-frame>*{max-width:100%;min-width:0;box-sizing:border-box;}'
            . '.ncx-cp-applepay-pay .ncx-cp-applepay-back{margin:8px 0 0;}'
            // float:none is load-bearing on product pages. The wallet markup renders inside
            // form.cart, and WooCommerce floats .button there, which takes the cancel button
            // out of the slot's flow: the bordered box then closes above it and the button
            // hangs outside, flush against the bottom border.
            . '.ncx-cp-applepay-pay .ncx-cp-applepay-back .button{width:100%;margin:0;float:none!important;display:block;box-sizing:border-box;}'
            // flex-wrap and min-width:0 let the message reflow rather than push
            // the row wider than the slot on a narrow screen.
            . '.ncx-cp-applepay-preparing,.ncx-cp-googlepay-preparing{display:flex!important;flex-wrap:wrap;align-items:center;justify-content:flex-start;gap:8px;margin:0 0 6px;padding:0;max-width:100%;min-width:0;color:#6b7280;font-size:14px;line-height:1.4;text-align:left;overflow-wrap:anywhere;}'
            . '.ncx-cp-applepay-preparing:before,.ncx-cp-googlepay-preparing:before{content:"";width:14px;height:14px;flex:0 0 14px;border-radius:50%;border:2px solid #d1d5db;border-top-color:#111827;animation:ncxCpSpin .7s linear infinite;}'
            // OPP injects its own spin.js loader into the widget container. The
            // preparing rows above are the only loader we show, so suppress it
            // wherever the widget mounts. Scoped so the admin spinner is untouched.
            . '#ncx-cp-inline-wrapper .spinner,#ncx-cp-inline-frame .spinner,#ncx-cp-applepay-frame .spinner,#ncx-cp-googlepay-frame .spinner,#ncx-cp-applepay-pay .spinner,#ncx-cp-googlepay-pay .spinner,#ncx-cp-product-applepay-pay .spinner,#ncx-cp-product-googlepay-pay .spinner,.wpwl-container .spinner,.wpwl-spinner{display:none!important;}'
            // Wallet marks are brand-controlled artwork, so their appearance is locked
            // against the store theme: the class is repeated to raise specificity above
            // typical theme button selectors, and every painted property is !important.
            // The logos draw with fill:currentColor, so a theme that forces white button
            // text would otherwise erase the mark entirely. Layout properties are left
            // overridable on purpose so the variant rules below still apply.
            . '.ncx-cp-applepay-trigger.ncx-cp-applepay-trigger{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;min-height:44px!important;padding:8px 14px!important;margin-bottom:12px;background:#fff!important;color:#000!important;border:1.5px solid #000!important;border-radius:6px!important;box-shadow:none!important;box-sizing:border-box;font-size:17px!important;font-weight:600!important;line-height:1!important;letter-spacing:normal!important;text-transform:none!important;text-shadow:none!important;text-decoration:none!important;cursor:pointer;-webkit-appearance:none;appearance:none;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif!important;}'
            . '.ncx-cp-applepay-trigger.ncx-cp-applepay-trigger:hover{background:#f5f5f5!important;}'
            . '.ncx-cp-applepay-trigger:disabled{opacity:0.6;cursor:default;}'
            . '.ncx-cp-applepay-trigger svg{height:20px;width:auto;display:block;}'
            // Checkout uses Apple's own mark artwork, so the white pill is neutralised
            // and the button hugs the image at its natural aspect ratio.
            // The 12px bottom margin collapses with .ncx-cp-applepay-frame's own 12px on
            // classic checkout, but in Blocks both wallet buttons share one container with
            // no frame between them, so the mark needs its own gap or it sits flush on the
            // Google Pay mark below it.
            // Matches the base rule's doubled class so this variant still wins.
            . '.ncx-cp-applepay-trigger--mark.ncx-cp-applepay-trigger--mark{width:auto;min-width:0;min-height:0!important;padding:0!important;margin:0 0 12px;gap:0;background:transparent!important;border:none!important;border-radius:0!important;box-shadow:none!important;}'
            . '.ncx-cp-applepay-trigger--mark.ncx-cp-applepay-trigger--mark:hover{background:transparent!important;}'
            . '.ncx-cp-applepay-trigger--mark img{display:block;height:44px;width:auto;}'
            . '.ncx-cp-product-applepay-wrap{margin-top:12px;max-width:min(480px,100%);min-width:0;box-sizing:border-box;}'
            . '.ncx-cp-product-applepay-wrap .ncx-cp-applepay-pay:empty{display:none!important;}'
            // Product pages run both wallets as full-width white buttons. The explicit height and
            // box-sizing pin the button to 44px next to the Google Pay one: the base rule
            // uses min-height with no box-sizing, which measures the content box on themes
            // that do not set a global border-box.
            . '.ncx-cp-product-applepay-wrap .ncx-cp-applepay-trigger{height:44px!important;padding:0 14px!important;background:#fff!important;color:#000!important;border:1.5px solid #000!important;box-sizing:border-box;}'
            . '.ncx-cp-product-applepay-wrap .ncx-cp-applepay-trigger:hover{background:#f5f5f5!important;}'
            . '.ncx-cp-applepay-back{margin:8px 0 0;}'
            . '.ncx-cp-googlepay-frame{min-height:0;margin-bottom:6px;max-width:100%;min-width:0;box-sizing:border-box;}'
            . '.ncx-cp-googlepay-pay{margin:6px 0;padding:8px;border:1px solid #e5e7eb!important;border-radius:' . esc_attr($this->inline_border_radius) . '!important;background:#fff!important;max-width:min(480px,100%);min-width:0;box-sizing:border-box;}'
            . '.ncx-cp-googlepay-pay:empty{display:none!important;border:none!important;padding:0!important;margin:0!important;}'
            . '.ncx-cp-googlepay-pay .wpwl-container,.ncx-cp-googlepay-pay .wpwl-container-googlepay,.ncx-cp-googlepay-pay .wpwl-group-googlepay{max-width:100%!important;width:100%!important;min-height:0!important;margin:0!important;padding:0!important;background:transparent!important;border:none!important;box-shadow:none!important;box-sizing:border-box;}'
            // Google's own button chrome. Scoped away from .button so it never repaints the
            // cancel / pay-by-card button below the widget, which is a theme button.
            . '.ncx-cp-googlepay-pay .gpay-card-info-container,.ncx-cp-googlepay-pay button:not(.button){max-width:100%;box-sizing:border-box;border:1px solid #747775!important;border-radius:6px!important;}'
            . '.ncx-cp-googlepay-pay .ncx-cp-googlepay-back{margin:8px 0 0;}'
            // See the Apple Pay note above: WooCommerce floats .button inside form.cart.
            . '.ncx-cp-googlepay-pay .ncx-cp-googlepay-back .button{width:100%;margin:0;float:none!important;display:block;box-sizing:border-box;}'
            . '.ncx-cp-payment-verifying .ncx-cp-applepay-frame,.ncx-cp-payment-verifying .ncx-cp-googlepay-frame,.ncx-cp-payment-verifying .ncx-cp-applepay-trigger,.ncx-cp-payment-verifying .ncx-cp-googlepay-trigger{display:none!important;}'
            // While a 3DS2 challenge is on screen, hide the payment form the
            // shopper is NOT paying with, so a saved-card row does not sit
            // alongside the challenge for a new-card payment.
            //
            // Scoped to the non-executing container on purpose: OPP injects the
            // challenge into the form it is authorising, so hiding both would
            // conceal the challenge for a saved-card payment and leave the
            // shopper unable to authorise. Never widen these selectors to cover
            // the container named by the verifying class.
            . '.ncx-cp-verifying-card form.wpwl-form-registrations,.ncx-cp-verifying-card .wpwl-container-registration,.ncx-cp-verifying-card .wpwl-group-registration,.ncx-cp-verifying-card .ncx-cp-save-card{display:none!important;}'
            . '.ncx-cp-verifying-registration form.wpwl-form-card,.ncx-cp-verifying-registration .wpwl-container-card,.ncx-cp-verifying-registration .ncx-cp-save-card{display:none!important;}'
            // OPP injects the 3DS2 challenge as an iframe carrying no size of its
            // own, so it inherits the container width and collapses to a few
            // pixels tall, leaving the issuer page to scroll inside a sliver.
            // The checkout scripts tag it on onLoadThreeDIframe rather than a
            // selector here, because the card number, expiry and CVV fields are
            // iframes too and must never be resized by this rule.
            . '.ncx-cp-threeds-frame{display:block!important;width:100%!important;max-width:100%!important;min-height:min(560px,80vh)!important;margin:12px 0!important;border:0!important;background:#fff!important;}'
            // Sized to sit beside the Apple Pay mark rather than as a full-width pill.
            // Apple's artwork is 165.52x105.97 with a 14.82 corner radius and a 3.53 border,
            // so at a 44px height it renders 68.7px wide with a 6.15px radius and a 1.47px
            // border. The values below reproduce that shape for the Google Pay logo.
            // Locked against the store theme for the same reason as the Apple Pay mark.
            . '.ncx-cp-googlepay-trigger.ncx-cp-googlepay-trigger{display:flex;align-items:center;justify-content:center;width:auto;height:44px!important;min-height:44px!important;padding:0 7px!important;margin:0 0 12px;background:#fff!important;color:#3c4043!important;border:1.5px solid #747775!important;border-radius:6px!important;box-shadow:none!important;box-sizing:border-box;line-height:1!important;letter-spacing:normal!important;text-transform:none!important;text-shadow:none!important;text-decoration:none!important;cursor:pointer;-webkit-appearance:none;appearance:none;font-family:Google Sans,Roboto,Arial,sans-serif!important;}'
            . '.ncx-cp-googlepay-trigger.ncx-cp-googlepay-trigger:hover{background:#f8fafd!important;box-shadow:0 1px 2px rgba(60,64,67,.3)!important;}'
            . '.ncx-cp-googlepay-trigger:disabled{opacity:0.6;cursor:default;}'
            // The "Pay" cap height drives this, so the word carries the same weight it does
            // in Apple's mark: Apple sets it 37.89 of 105.97 units tall (35.8%), which is
            // 15.7px on a 44px mark. The 130x48 logo puts "Pay" at 36.52 units (76%), so a
            // 21px height lands on 16.0px. Width follows at 56.9px, or 73.9px with the box.
            . '.ncx-cp-googlepay-trigger svg{height:21px;width:auto;max-width:130px;display:block;}'
            . '.ncx-cp-product-googlepay-wrap{margin-top:12px;max-width:min(480px,100%);min-width:0;box-sizing:border-box;}'
            . '.ncx-cp-product-googlepay-wrap .ncx-cp-googlepay-pay:empty{display:none!important;}'
            // Product pages stretch the mark into a full-width button and prefix it with
            // "Buy Now with", so the type matches the Apple Pay button beside it. The base
            // rule already supplies Google's light styling. A 17px logo puts "Pay" at a
            // 12.9px cap height, against 12.2px for Apple's 17px/600 button text.
            . '.ncx-cp-product-googlepay-wrap .ncx-cp-googlepay-trigger{width:100%;padding:0 14px!important;gap:6px;font-size:17px!important;font-weight:600!important;}'
            . '.ncx-cp-product-googlepay-wrap .ncx-cp-googlepay-trigger svg{height:17px;}'
            . '.ncx-cp-googlepay-back{margin:8px 0 0;}'
            // On both checkouts the wallet triggers line up with the card form
            // instead of hugging their artwork, so the payment options read as
            // one stack. The mark stays at its natural aspect ratio and centres
            // in the wider button; only the button box grows.
            //
            // Scoped to the two checkout containers because product pages have
            // their own full-width treatment via .ncx-cp-product-*-wrap. The
            // class is repeated to clear the doubled-class base and --mark
            // rules above, which would otherwise tie on specificity.
            . '.ncx-cp-inline-wrapper .ncx-cp-applepay-trigger.ncx-cp-applepay-trigger,'
            . '#ncx-cp-blocks-container .ncx-cp-applepay-trigger.ncx-cp-applepay-trigger,'
            . '.ncx-cp-inline-wrapper .ncx-cp-googlepay-trigger.ncx-cp-googlepay-trigger,'
            . '#ncx-cp-blocks-container .ncx-cp-googlepay-trigger.ncx-cp-googlepay-trigger'
            . '{width:100%;max-width:min(480px,100%);min-width:0;box-sizing:border-box;}';

        // %1=primary, %2=glow, %3=border, %4=field ink, %5=radius, %6=muted,
        // %7=font-family, %8=surface, %9=surface hover/selected, %10=on-surface text,
        // %11=on-surface muted text
        return $base . sprintf(
            ' .wpwl-group-brand,.wpwl-group-cardHolder,.wpwl-group-submit,.wpwl-button-pay:not([data-action="show-initial-forms"]){display:none!important;}'
            . ' .wpwl-form{max-width:100%%;margin:0;padding:0;}'
            . ' .wpwl-form-has-inputs{padding:0!important;border:none!important;background:transparent!important;border-radius:0!important;box-shadow:none!important;}'
            . ' div#wpwl-registrations{display:block;width:100%%;max-width:min(480px,100%%);margin-bottom:12px;box-sizing:border-box;}'
            . ' form.wpwl-form-registrations{max-width:100%%;margin:0 0 12px;}'
            . ' form.wpwl-form-card{margin-top:16px;}'
            . ' .wpwl-container-applepay,.wpwl-group-applepay{margin:0 0 12px!important;max-width:min(480px,100%%)!important;width:100%%!important;box-sizing:border-box;}'
            . ' .ncx-cp-card-row{display:flex;flex-wrap:nowrap;width:100%%;max-width:min(480px,100%%);border:1px solid %3$s!important;border-radius:%5$s!important;overflow:hidden;background:#fff!important;box-shadow:0 1px 2px rgba(0,0,0,0.04)!important;transition:border-color .2s ease,box-shadow .2s ease;margin-bottom:10px;box-sizing:border-box;}'
            . ' .ncx-cp-card-row:focus-within{border-color:%1$s!important;box-shadow:0 0 0 3px %2$s!important;}'
            . ' .ncx-cp-card-row .wpwl-group{margin:0!important;padding:0!important;width:auto!important;box-sizing:border-box;}'
            . ' .ncx-cp-card-row .wpwl-group-cardNumber{flex:1 1 auto;min-width:0;}'
            . ' .ncx-cp-card-row .wpwl-group-expiry{flex:0 1 108px;min-width:92px;border-left:1px solid %3$s!important;}'
            . ' .ncx-cp-card-row .wpwl-group-cvv{flex:0 1 76px;min-width:64px;border-left:1px solid %3$s!important;}'
            . ' .wpwl-wrapper-cardNumber,.wpwl-wrapper-expiry,.wpwl-wrapper-cvv,.wpwl-wrapper-cardHolder{float:none!important;width:100%%!important;position:unset!important;}'
            . ' .wpwl-control-cardNumber,.wpwl-control-expiry,.wpwl-control-cvv,.wpwl-control-cardHolder{width:100%%!important;color:%4$s!important;font-family:%7$s!important;height:48px!important;border:none!important;background:#fff!important;margin:0!important;border-radius:0!important;box-shadow:none!important;font-size:16px!important;padding:0 14px!important;letter-spacing:0.01em!important;-webkit-appearance:none!important;appearance:none!important;}'
            . ' .wpwl-control-cardNumber:focus,.wpwl-control-expiry:focus,.wpwl-control-cvv:focus{outline:none!important;background:#fafbfc!important;}'
            . ' form.wpwl-form .form-row input::placeholder,input.wpwl-control-expiry::placeholder{color:#9ca3af!important;font-size:16px!important;}'
            . ' div.wpwl-hint{font-size:12px!important;text-align:left!important;color:#ef4444;margin:4px 0 0 0;}'
            . ' .wpwl-control{text-align:left;}'
            . ' .wpwl-group-registration{border:1px solid %3$s!important;border-radius:%5$s!important;margin:0 0 10px!important;padding:0!important;width:100%%;max-width:min(480px,100%%);background:%8$s!important;box-shadow:0 1px 2px rgba(0,0,0,0.04)!important;transition:border-color .2s ease,box-shadow .2s ease,background .2s ease;box-sizing:border-box;overflow:hidden;}'
            . ' .wpwl-group-registration.wpwl-selected{border-color:%1$s!important;background:%9$s!important;box-shadow:0 0 0 3px %2$s!important;}'
            . ' .wpwl-group-registration label{color:%10$s!important;}'
            . ' .wpwl-group-registration .wpwl-label{display:none!important;}'
            . ' .wpwl-wrapper-registration{float:none!important;width:auto!important;position:static!important;margin:0!important;}'
            . ' label.wpwl-registration{display:flex!important;flex-wrap:nowrap;align-items:center;gap:12px;width:100%%;padding:12px 14px!important;margin:0!important;box-sizing:border-box;cursor:pointer;line-height:1.4;}'
            . ' .wpwl-wrapper-registration-registrationId{flex:0 0 auto;padding:0!important;}'
            . ' .wpwl-wrapper-registration-registrationId input[type=radio]{margin:0!important;accent-color:%1$s;}'
            . ' .wpwl-wrapper-registration-brand,.wpwl-wrapper-registration-cardHolder{display:none!important;}'
            . ' .wpwl-wrapper-registration-details{flex:1 1 auto;min-width:0;padding:0!important;margin:0!important;font-size:15px!important;font-weight:500;letter-spacing:0.02em;color:%10$s!important;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
            . ' .wpwl-wrapper-registration-cvv{flex:0 0 96px;width:96px!important;max-width:96px!important;padding:0!important;margin:0!important;}'
            . ' div.wpwl-wrapper-registration-cvv{line-height:1.4;}'
            // Colour is pinned to the field ink: the input keeps a white background in
            // every theme, so it must not inherit the panel's on-surface text colour.
            . ' div.wpwl-wrapper-registration .wpwl-control-cvv{width:100%%!important;margin:0!important;height:44px!important;border:1px solid %3$s!important;border-radius:8px!important;background:#fff!important;color:%4$s!important;font-size:16px!important;padding:0 12px!important;box-shadow:none!important;box-sizing:border-box;}'
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
            . ' .ncx-cp-save-card>label,.ncx-cp-save-card label.woocommerce-form__label{display:flex!important;align-items:flex-start!important;gap:10px!important;margin:0!important;padding:12px 14px!important;border:1px solid %3$s!important;border-radius:%5$s!important;background:%8$s!important;color:%10$s!important;font-size:14px!important;font-weight:400!important;line-height:1.5!important;cursor:pointer!important;float:none!important;width:100%%!important;box-sizing:border-box!important;transition:border-color .2s ease,box-shadow .2s ease,background .2s ease!important;}'
            . ' .ncx-cp-save-card label:hover{border-color:%1$s!important;background:%9$s!important;}'
            . ' .ncx-cp-save-card label:has(input:checked){border-color:%1$s!important;background:%9$s!important;box-shadow:0 0 0 3px %2$s!important;}'
            . ' .ncx-cp-save-card input[type=checkbox],.ncx-cp-save-card .woocommerce-form__input-checkbox{flex:0 0 auto!important;width:18px!important;height:18px!important;min-width:18px!important;margin:2px 0 0!important;padding:0!important;float:none!important;position:static!important;accent-color:%1$s!important;border-radius:4px!important;cursor:pointer!important;box-shadow:none!important;}'
            . ' .ncx-cp-save-card span{display:block;flex:1 1 auto;min-width:0;color:%11$s!important;font-size:14px!important;line-height:1.5!important;}'
            . ' @media (max-width:520px){.ncx-cp-inline-wrapper .ncx-cp-card-row,#ncx-cp-blocks-container .ncx-cp-card-row{max-width:100%%!important;width:100%%!important;flex-wrap:wrap;}.ncx-cp-inline-wrapper .ncx-cp-card-row .wpwl-group-cardNumber,#ncx-cp-blocks-container .ncx-cp-card-row .wpwl-group-cardNumber{flex:1 1 100%%;width:100%%!important;min-width:0;border-bottom:1px solid %3$s;}.ncx-cp-inline-wrapper .ncx-cp-card-row .wpwl-group-expiry,#ncx-cp-blocks-container .ncx-cp-card-row .wpwl-group-expiry{flex:1 1 50%%;min-width:0;border-left:none;}.ncx-cp-inline-wrapper .ncx-cp-card-row .wpwl-group-cvv,#ncx-cp-blocks-container .ncx-cp-card-row .wpwl-group-cvv{flex:1 1 50%%;min-width:0;border-left:1px solid %3$s;}.ncx-cp-inline-wrapper div#wpwl-registrations,#ncx-cp-blocks-container div#wpwl-registrations,.ncx-cp-inline-wrapper .wpwl-group-registration,#ncx-cp-blocks-container .wpwl-group-registration{max-width:100%%!important;}.ncx-cp-inline-wrapper label.wpwl-registration,#ncx-cp-blocks-container label.wpwl-registration{flex-wrap:wrap;gap:10px 12px;}.ncx-cp-inline-wrapper .wpwl-wrapper-registration-details,#ncx-cp-blocks-container .wpwl-wrapper-registration-details{flex:1 1 calc(100%% - 36px);white-space:normal;}.ncx-cp-inline-wrapper .wpwl-wrapper-registration-cvv,#ncx-cp-blocks-container .wpwl-wrapper-registration-cvv{flex:1 1 100%%;width:100%%!important;max-width:100%%!important;}.ncx-cp-inline-wrapper .ncx-cp-save-card,#ncx-cp-blocks-container .ncx-cp-save-card{max-width:100%%!important;width:100%%!important;}}'
            . ' @media (max-width:380px){.ncx-cp-inline-wrapper .ncx-cp-card-row .wpwl-group-expiry,.ncx-cp-inline-wrapper .ncx-cp-card-row .wpwl-group-cvv,#ncx-cp-blocks-container .ncx-cp-card-row .wpwl-group-expiry,#ncx-cp-blocks-container .ncx-cp-card-row .wpwl-group-cvv{flex:1 1 100%%;border-left:none;}.ncx-cp-inline-wrapper .ncx-cp-card-row .wpwl-group-cvv,#ncx-cp-blocks-container .ncx-cp-card-row .wpwl-group-cvv{border-top:1px solid %3$s;}.ncx-cp-inline-wrapper .wpwl-control-cardNumber,.ncx-cp-inline-wrapper .wpwl-control-expiry,.ncx-cp-inline-wrapper .wpwl-control-cvv,#ncx-cp-blocks-container .wpwl-control-cardNumber,#ncx-cp-blocks-container .wpwl-control-expiry,#ncx-cp-blocks-container .wpwl-control-cvv{padding:0 12px!important;}}',
            esc_attr($this->primary_color),
            esc_attr($this->hex_to_rgba($this->primary_color, 0.12)),
            esc_attr($this->inline_border_color),
            esc_attr($this->inline_text_color),
            esc_attr($this->inline_border_radius),
            esc_attr($this->inline_muted_color),
            esc_attr($this->get_checkout_typography_tokens()['fontFamily']),
            esc_attr($this->inline_surface_color),
            esc_attr($this->inline_surface_alt_color),
            esc_attr($this->inline_on_surface_color),
            esc_attr($this->inline_on_surface_muted_color)
        );
    }

    /**
     * Identifies a "pay for order" request (My Account > Orders > Pay).
     *
     * That page renders WooCommerce's classic pay form, which carries no billing
     * fields, so Apple Pay must pay the existing order rather than validate the
     * checkout form and build a fresh order from whatever is left in the cart.
     *
     * @return array{order_id: int, order_key: string}|null
     */
    private function get_order_pay_context(): ?array {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
            return null;
        }

        $order_id = absint(get_query_var('order-pay'));
        if ($order_id <= 0) {
            global $wp;
            $order_id = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        }

        $order_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        if ($order_id <= 0 || '' === $order_key) {
            return null;
        }

        return [
            'order_id'  => $order_id,
            'order_key' => $order_key,
        ];
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

    // Detects whether the current checkout page uses the WooCommerce checkout block.
    private function is_block_checkout(): bool {
        if (function_exists('has_block') && has_block('woocommerce/checkout')) {
            return true;
        }

        // Fallback for FSE/block-theme checkout where has_block() on the page
        // content may not detect the block.
        $is_block_theme = function_exists('wp_is_block_theme')
            ? wp_is_block_theme()
            : (function_exists('wc_current_theme_is_fse_theme') && wc_current_theme_is_fse_theme());

        if ($is_block_theme && function_exists('has_block') && has_block('woocommerce/checkout', get_the_ID())) {
            return true;
        }

        return false;
    }

    // Enqueues all scripts, styles, and inline data required for the checkout UI.
    public function maybe_enqueue_assets(): void {
        if (!$this->should_enqueue_checkout_assets()) {
            return;
        }

        $this->enqueue_checkout_styles();

        // Only load the classic inline script on the CLASSIC (shortcode) checkout.
        // On the block checkout, checkout-blocks.js is enqueued by the Blocks
        // integration instead. Loading both caused the inline script's
        // MutationObserver to latch onto the block checkout DOM and loop.
        // The order-pay endpoint always renders WooCommerce's classic pay form,
        // even on block-checkout sites, so it uses the inline script regardless.
        $pay_for_order = $this->get_order_pay_context();

        if ($pay_for_order || (is_checkout() && !$this->is_block_checkout())) {
            $script_handle = 'ncx-cp-api-inline';
            wp_enqueue_script(
                $script_handle,
                plugins_url('../assets/js/checkout-inline.js', __FILE__),
                ['jquery'],
                NCX_CP_API::asset_version('assets/js/checkout-inline.js'),
                true
            );

            wp_localize_script(
                $script_handle,
                'ncxCpInline',
                [
                    'ajaxUrl'            => WC_AJAX::get_endpoint('ncx_cp_request_checkout_id'),
                    'nonce'              => wp_create_nonce('ncx_cp_checkout_nonce'),
                    'regionHost'         => $this->get_region_host(),
                    'environment'        => $this->test_mode ? 'test' : 'live',
                    'brands'             => $this->get_payment_brands(),
                    'cardBrands'         => $this->get_card_payment_brands(),
                    'applePay'           => $this->get_apple_pay_client_config(),
                    'googlePay'          => $this->get_google_pay_client_config(),
                    'gatewayId'          => $this->id,
                    'createRegistration' => $this->client_allows_card_saving() ? '1' : '0',
                    'allowCardSaving'    => $this->client_allows_card_saving() ? '1' : '0',
                    'loggedIn'           => is_user_logged_in() ? '1' : '0',
                    'typography'         => $this->get_checkout_typography_tokens(),
                    'minimumPaymentAmount'   => $this->get_minimum_payment_amount(),
                    'minimumPaymentMessage'  => $this->get_minimum_payment_notice(),
                    'payForOrder'        => $pay_for_order ? '1' : '0',
                    'orderId'            => $pay_for_order ? (string) $pay_for_order['order_id'] : '',
                    'orderKey'           => $pay_for_order ? $pay_for_order['order_key'] : '',
                    'consoleLogging'     => $this->client_console_logging_enabled() ? '1' : '0',
                    'applePayClientLog'  => $this->apple_pay_logging ? '1' : '0',
                    'googlePayClientLog' => $this->google_pay_logging ? '1' : '0',
                ]
            );
        }

        if ($this->client_console_logging_enabled()) {
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
        $recurrence = $this->enable_dupe_check ? 'ncx_cp_every_five_minutes' : 'hourly';
        if ($scheduled) {
            $next = wp_get_scheduled_event(self::DUPE_CRON_HOOK);
            if (is_object($next) && $recurrence !== ($next->schedule ?? '')) {
                wp_clear_scheduled_hook(self::DUPE_CRON_HOOK);
                $scheduled = false;
            }
        }
        if (!$scheduled) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $recurrence, self::DUPE_CRON_HOOK);
        }
    }

    public function run_duplicate_guard(): void {
        $this->run_stale_order_cleanup();
        $this->run_invariant_monitor();
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

            // Core calls the token filter with an empty gateway id, so this list can
            // hold other gateways' tokens. The environment split is ours alone.
            if ($token->get_gateway_id() !== $this->id) {
                $filtered[$token->get_id()] = $token;
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

    /**
     * Puts "Payment methods" back in the My Account menu.
     *
     * wc_get_account_menu_items() removes that item unless an available gateway
     * supports tokenization or add_payment_method. Claiming either would also turn
     * on WooCommerce's own saved-card UI at checkout and the "Add payment method"
     * button, both of which this gateway suppresses on purpose, so the item is
     * restored here instead. The endpoint itself is core and always registered.
     */
    public function restore_payment_methods_menu_item(array $items, array $endpoints = []): array {
        if (isset($items['payment-methods'])) {
            return $items;
        }

        // Merchant cleared the endpoint slug – core disabled the page, so respect that.
        if (array_key_exists('payment-methods', $endpoints) && empty($endpoints['payment-methods'])) {
            return $items;
        }

        if ('yes' !== $this->enabled || !$this->is_tokenization_enabled()) {
            return $items;
        }

        // Core's text domain on purpose: same string, same translations as the theme expects.
        $label = __('Payment methods', 'woocommerce');
        $before = isset($items['edit-account']) ? 'edit-account' : 'customer-logout';

        if (!isset($items[$before])) {
            $items['payment-methods'] = $label;
            return $items;
        }

        $rebuilt = [];
        foreach ($items as $key => $value) {
            if ($key === $before) {
                $rebuilt['payment-methods'] = $label;
            }
            $rebuilt[$key] = $value;
        }

        return $rebuilt;
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

    /**
     * Maps the chosen checkout theme to a curated set of colors.
     *
     * Two text tokens exist on purpose. 'text' is ink for the card entry fields,
     * which stay on a white background in every theme because the card number and
     * CVV are OPP-hosted iframes we cannot repaint. 'on_surface' is for copy that
     * sits on a panel this plugin paints itself, so it has to follow the theme.
     * 'muted' is for labels that sit directly on the store's own background, which
     * we do not control, so it stays a neutral grey in every theme.
     */
    private function resolve_theme_palette(string $theme): array {
        $palettes = [
            'light' => [
                'primary'      => '#111827',
                'accent'       => '#F2F4F7',
                'note'         => '#111827',
                'text'         => '#111827',
                'muted'        => '#6b7280',
                'border'       => '#E5E7EB',
                'surface'      => '#FFFFFF',
                'surface_alt'  => '#FAFBFC',
                'on_surface'   => '#111827',
                'on_surface_muted' => '#6B7280',
            ],
            'dark' => [
                'primary'      => '#38BDF8',
                'accent'       => '#1E293B',
                'note'         => '#111827',
                'text'         => '#111827',
                'muted'        => '#6b7280',
                'border'       => '#334155',
                'surface'      => '#0F172A',
                'surface_alt'  => '#1E293B',
                'on_surface'   => '#E2E8F0',
                'on_surface_muted' => '#94A3B8',
            ],
            'calm' => [
                'primary'      => '#0F766E',
                'accent'       => '#D1FAE5',
                'note'         => '#111827',
                'text'         => '#111827',
                'muted'        => '#6b7280',
                'border'       => '#99F6E4',
                'surface'      => '#FFFFFF',
                'surface_alt'  => '#CCFBF1',
                'on_surface'   => '#134E4A',
                'on_surface_muted' => '#4B7C77',
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

    /**
     * Log context for outbound OPP requests (redacted).
     */
    private function build_opp_outbound_log_context(
        string $request_url,
        array $request_headers,
        array $body,
        array $credentials,
        ?bool $force_test
    ): array {
        return [
            'request_url'          => $this->redact_opp_request_url($request_url),
            'request_headers'      => $this->redact_opp_request_headers($request_headers),
            'request_body'         => $this->redact_opp_log_body($body),
            'request_body_encoded' => $this->encode_opp_log_body($body),
            'credential_summary'   => $this->summarize_opp_credentials($credentials, $force_test),
            'http_transport'       => $this->get_active_http_transport(),
            'entity_in_query'      => $this->use_opp_entity_in_query(),
        ];
    }

    /**
     * Transport used for live outbound requests (cURL preferred; WordPress only if cURL missing).
     */
    private function get_active_http_transport(): string {
        return function_exists('curl_init') ? 'curl' : 'wordpress';
    }

    /**
     * Whether Phase 1 should move entityId from the body into the request URL.
     */
    private function use_opp_entity_in_query(): bool {
        return 'yes' === $this->get_option('opp_entity_in_query', 'no');
    }

    /**
     * How many provider sessions one order may mint inside the rate-limit window.
     *
     * Clamped on read as well as on save: a blank, non-numeric or hand-edited
     * option must still leave a working ceiling, because this counter is the only
     * thing stopping one order from creating provider sessions without end.
     */
    private function get_provider_session_attempt_limit(): int {
        $configured = $this->get_option(
            'provider_session_rate_limit',
            (string) self::DEFAULT_PROVIDER_SESSION_ATTEMPTS
        );

        if (!is_numeric($configured)) {
            return self::DEFAULT_PROVIDER_SESSION_ATTEMPTS;
        }

        return max(
            self::MIN_PROVIDER_SESSION_ATTEMPTS,
            min(self::MAX_PROVIDER_SESSION_ATTEMPTS, (int) $configured)
        );
    }

    /**
     * Clamps the saved value so the settings screen never shows a limit that
     * differs from the one actually enforced.
     */
    public function validate_provider_session_rate_limit_field(string $key, $value): string {
        if (!is_numeric($value)) {
            return (string) self::DEFAULT_PROVIDER_SESSION_ATTEMPTS;
        }

        return (string) max(
            self::MIN_PROVIDER_SESSION_ATTEMPTS,
            min(self::MAX_PROVIDER_SESSION_ATTEMPTS, (int) $value)
        );
    }

    /**
     * @param array|WP_Error $response
     */
    private function http_response_body($response): string {
        if (is_wp_error($response)) {
            return '';
        }

        return (string) ($response['body'] ?? '');
    }

    /**
     * @param array|WP_Error $response
     */
    private function http_response_code($response): int {
        if (is_wp_error($response)) {
            return 0;
        }

        return (int) ($response['response']['code'] ?? 0);
    }

    /**
     * @param array|WP_Error $response
     * @return array<string, string>
     */
    private function http_response_headers($response): array {
        if (is_wp_error($response)) {
            return [];
        }

        return $this->flatten_http_headers($response['headers'] ?? []);
    }

    /**
     * Outbound HTTP using direct cURL by default (bypasses WordPress HTTP API hooks).
     * WordPress wp_remote_request is used only when the cURL extension is unavailable,
     * or when $force_transport is set to "wordpress" for diagnostics.
     *
     * @param array{timeout?: int, headers?: array<string, string>, body?: array<string, mixed>|string} $args
     */
    private function opp_http_request(string $method, string $url, array $args, ?string $force_transport = null) {
        $timeout = isset($args['timeout']) ? (int) $args['timeout'] : 60;
        $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
        $body    = $args['body'] ?? null;

        if ('wordpress' === $force_transport) {
            return $this->opp_http_request_via_wordpress($method, $url, $headers, $body, $timeout);
        }

        if (function_exists('curl_init')) {
            return $this->opp_http_request_via_curl($method, $url, $headers, $body, $timeout);
        }

        if ('curl' === $force_transport) {
            return new WP_Error('ncx_cp_no_curl', __('cURL extension is not available on this server.', 'ncx-cp-api'));
        }

        $this->log_event('warning', 'cURL unavailable — falling back to WordPress HTTP API', [
            'url'    => $this->redact_opp_request_url($url),
            'method' => strtoupper($method),
        ]);

        return $this->opp_http_request_via_wordpress($method, $url, $headers, $body, $timeout);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|string|null $body
     */
    private function opp_http_request_via_wordpress(string $method, string $url, array $headers, $body, int $timeout) {
        $request_args = [
            'method'  => strtoupper($method),
            'timeout' => $timeout,
            'headers' => $headers,
        ];

        if (null !== $body && '' !== $body && [] !== $body) {
            $request_args['body'] = $body;
        }

        return wp_remote_request($url, $request_args);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|string|null $body
     */
    private function opp_http_request_via_curl(string $method, string $url, array $headers, $body, int $timeout) {
        if (!function_exists('curl_init')) {
            return new WP_Error('ncx_cp_no_curl', __('cURL extension is not available on this server.', 'ncx-cp-api'));
        }

        $method = strtoupper($method);
        $ch     = curl_init($url);
        if (false === $ch) {
            return new WP_Error('ncx_cp_curl_init', __('Unable to initialise cURL.', 'ncx-cp-api'));
        }

        $curl_headers = [];
        foreach ($headers as $key => $value) {
            $curl_headers[] = $key . ': ' . $value;
        }

        $encoded_body = null;
        if (null !== $body && '' !== $body && [] !== $body) {
            $encoded_body = is_array($body)
                ? http_build_query($body, '', '&', PHP_QUERY_RFC3986)
                : (string) $body;
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(1, $timeout));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if (null !== $encoded_body && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded_body);
        }

        $raw_response = curl_exec($ch);
        if (false === $raw_response) {
            $error = curl_error($ch);
            curl_close($ch);
            return new WP_Error('ncx_cp_curl_error', $error ?: __('cURL request failed.', 'ncx-cp-api'));
        }

        $status_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $raw_headers = substr($raw_response, 0, $header_size);
        $raw_body    = substr($raw_response, $header_size);

        return [
            'headers'  => $this->parse_raw_http_headers($raw_headers),
            'body'     => $raw_body,
            'response' => [
                'code'    => $status_code,
                'message' => get_status_header_desc($status_code) ?: '',
            ],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parse_raw_http_headers(string $raw_headers): array {
        $headers = [];
        $lines   = preg_split("/\r\n|\n|\r/", trim($raw_headers));
        if (!is_array($lines)) {
            return $headers;
        }

        foreach ($lines as $line) {
            if ('' === $line || false !== stripos($line, 'HTTP/')) {
                continue;
            }
            $parts = explode(':', $line, 2);
            if (2 !== count($parts)) {
                continue;
            }
            $headers[trim($parts[0])] = trim($parts[1]);
        }

        return $headers;
    }

    /**
     * Summarise an HTTP response for admin diagnostics (no secrets).
     *
     * @param array|WP_Error $response
     */
    private function summarize_opp_http_response($response, string $transport): array {
        if (is_wp_error($response)) {
            return [
                'transport'        => $transport,
                'success'          => false,
                'error_code'       => $response->get_error_code(),
                'error_message'    => $response->get_error_message(),
                'http_code'        => 0,
                'response_is_json' => false,
                'checkout_id'      => '',
                'opp_result_code'  => '',
                'response_server'  => '',
                'response_preview' => '',
            ];
        }

        $raw_body  = $this->http_response_body($response);
        $http_code = $this->http_response_code($response);
        $headers   = $this->http_response_headers($response);
        $data      = json_decode($raw_body, true);
        $is_json   = is_array($data);

        return [
            'transport'        => $transport,
            'success'          => $is_json && !empty($data['id']),
            'error_code'       => '',
            'error_message'    => '',
            'http_code'        => $http_code,
            'response_is_json' => $is_json,
            'checkout_id'      => $is_json ? (string) ($data['id'] ?? '') : '',
            'opp_result_code'  => $is_json ? (string) ($data['result']['code'] ?? '') : 'non_json',
            'response_server'  => (string) ($headers['server'] ?? $headers['Server'] ?? ''),
            'response_preview' => mb_substr($raw_body, 0, 400),
        ];
    }

    /**
     * Best-effort detection of this server's outbound IP (for PAYSTRAX whitelist requests).
     */
    private function detect_server_outbound_ip(): string {
        if (!function_exists('curl_init')) {
            return '';
        }

        $ch = curl_init('https://api.ipify.org');
        if (false === $ch) {
            return '';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $body = curl_exec($ch);
        curl_close($ch);

        if (false === $body) {
            return '';
        }

        $ip = trim((string) $body);

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * @return array<string, int>
     */
    private function detect_opp_http_filters(): array {
        global $wp_filter;

        $hooks = ['pre_http_request', 'http_request_args', 'http_api_curl', 'requests-requests.before_request'];
        $out   = [];

        foreach ($hooks as $hook) {
            if (isset($wp_filter[$hook]) && $wp_filter[$hook] instanceof WP_Hook) {
                $count = 0;
                foreach ($wp_filter[$hook]->callbacks as $callbacks) {
                    $count += count($callbacks);
                }
                $out[$hook] = $count;
            } else {
                $out[$hook] = 0;
            }
        }

        return $out;
    }

    /**
     * Admin-only: compare WordPress vs cURL against POST /v1/checkouts.
     */
    public function ajax_opp_connectivity_test(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ncx-cp-api')]);
            return;
        }

        if (!check_ajax_referer('ncx_cp_opp_connectivity_test', 'security', false)) {
            wp_send_json_error(['message' => __('Security check failed. Reload this settings page and try again.', 'ncx-cp-api')]);
            return;
        }

        $use_test = (bool) $this->test_mode;
        $credentials = $this->get_active_credentials($use_test);
        if (!$this->credentials_present($credentials)) {
            wp_send_json_error(['message' => __('Credentials missing for the current Mode.', 'ncx-cp-api')]);
            return;
        }

        $url = $this->get_region_host($use_test) . '/v1/checkouts';
        $body = [
            'entityId'    => $credentials['entity_id'],
            'paymentType' => self::PAYMENT_TYPE,
            'amount'      => '1.00',
            'currency'    => self::MERCHANT_CURRENCY,
        ];
        $this->apply_standing_instruction_parameters($body);
        $this->apply_merchant_url_parameter($body);

        if ($this->use_opp_entity_in_query()) {
            $url = add_query_arg('entityId', $body['entityId'], $url);
            unset($body['entityId']);
        }

        $headers = $this->build_opp_outbound_headers($credentials);
        $args    = [
            'timeout' => 30,
            'headers' => $headers,
            'body'    => $body,
        ];

        $wordpress = $this->summarize_opp_http_response(
            $this->opp_http_request('POST', $url, $args, 'wordpress'),
            'wordpress'
        );
        $curl = $this->summarize_opp_http_response(
            $this->opp_http_request('POST', $url, $args, 'curl'),
            'curl'
        );

        $payload = [
            'mode'               => $use_test ? 'test' : 'live',
            'region_host'        => $this->get_region_host($use_test),
            'request_url'        => $this->redact_opp_request_url($url),
            'entity_in_query'    => $this->use_opp_entity_in_query(),
            'merchant_url'       => $this->get_merchant_url(),
            'active_transport'   => $this->get_active_http_transport(),
            'server_outbound_ip' => $this->detect_server_outbound_ip(),
            'http_filters'       => $this->detect_opp_http_filters(),
            'curl_available'     => function_exists('curl_init'),
            'results'            => [
                'wordpress' => $wordpress,
                'curl'      => $curl,
            ],
        ];

        if ($this->server_logging) {
            $this->get_logger()->log('info', 'OPP connectivity test completed', array_merge(['source' => $this->id], $payload));
        }

        wp_send_json_success($payload);
    }

    /**
     * Persist settings then sync plugin-managed .well-known files when possible.
     */
    public function process_admin_options() {
        if (!current_user_can('manage_woocommerce')) {
            return false;
        }

        $saved = parent::process_admin_options();
        $files = NCX_CP_API_Apple_Pay_Domain::normalize_stored_value(
            $this->get_option(NCX_CP_API_Apple_Pay_Domain::SETTING_KEY, [])
        );
        $sync = NCX_CP_API_Apple_Pay_Domain::sync_physical_files($files);
        set_transient('ncx_cp_apple_pay_domain_write', $sync, MINUTE_IN_SECONDS);

        return $saved;
    }

    /**
     * Accept multiple safe uploads plus pasted Apple association content.
     *
     * @return array<string, string>
     */
    public function validate_apple_pay_domain_association_field(string $key, $value): array {
        $field_key = $this->get_field_key($key);
        $clear_key = $field_key . '_clear';
        $file_key  = $field_key . '_upload';
        $existing = NCX_CP_API_Apple_Pay_Domain::normalize_stored_value($this->get_option($key, []));
        $files = $existing;
        $has_error = false;

        if (isset($_POST[$clear_key])) {
            $clear_files = (array) wp_unslash($_POST[$clear_key]);
            foreach ($clear_files as $filename) {
                $filename = is_scalar($filename) ? (string) $filename : '';
                if (NCX_CP_API_Apple_Pay_Domain::is_safe_filename($filename)) {
                    unset($files[$filename]);
                }
            }
        }

        if (isset($_FILES[$file_key]) && is_array($_FILES[$file_key])) {
            $upload = $_FILES[$file_key]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
            $names = isset($upload['name']) ? (array) $upload['name'] : [];
            $tmp_names = isset($upload['tmp_name']) ? (array) $upload['tmp_name'] : [];
            $sizes = isset($upload['size']) ? (array) $upload['size'] : [];
            $errors = isset($upload['error']) ? (array) $upload['error'] : [];

            foreach ($names as $index => $uploaded_name) {
                $error = isset($errors[$index]) ? (int) $errors[$index] : UPLOAD_ERR_NO_FILE;
                if (UPLOAD_ERR_NO_FILE === $error) {
                    continue;
                }
                if (UPLOAD_ERR_OK !== $error) {
                    WC_Admin_Settings::add_error(
                        sprintf(
                            /* translators: %d: PHP upload error code */
                            __('Association file upload failed (error code %d).', 'ncx-cp-api'),
                            $error
                        )
                    );
                    $has_error = true;
                    continue;
                }

                $filename = is_scalar($uploaded_name) ? (string) wp_unslash($uploaded_name) : '';
                if (!NCX_CP_API_Apple_Pay_Domain::is_safe_filename($filename)) {
                    WC_Admin_Settings::add_error(
                        __('Invalid filename. Use a simple basename with no path and either no extension, .json, or .txt.', 'ncx-cp-api')
                    );
                    $has_error = true;
                    continue;
                }

                $size = isset($sizes[$index]) ? (int) $sizes[$index] : 0;
                if ($size > NCX_CP_API_Apple_Pay_Domain::MAX_BYTES) {
                    WC_Admin_Settings::add_error(
                        sprintf(
                            /* translators: %d: maximum bytes */
                            __('Association file is too large (maximum %d bytes).', 'ncx-cp-api'),
                            NCX_CP_API_Apple_Pay_Domain::MAX_BYTES
                        )
                    );
                    $has_error = true;
                    continue;
                }

                $tmp_name = isset($tmp_names[$index]) && is_scalar($tmp_names[$index])
                    ? (string) $tmp_names[$index]
                    : '';
                if ('' === $tmp_name || !is_uploaded_file($tmp_name)) {
                    WC_Admin_Settings::add_error(__('Association file upload could not be verified.', 'ncx-cp-api'));
                    $has_error = true;
                    continue;
                }

                $raw = file_get_contents($tmp_name);
                if (false === $raw) {
                    WC_Admin_Settings::add_error(__('Association file could not be read.', 'ncx-cp-api'));
                    $has_error = true;
                    continue;
                }

                $normalized = NCX_CP_API_Apple_Pay_Domain::normalize_incoming($raw);
                if (!$normalized['ok']) {
                    WC_Admin_Settings::add_error($normalized['error']);
                    $has_error = true;
                    continue;
                }
                $files[$filename] = $normalized['body'];
            }
        }

        if (is_string($value) && '' !== trim($value)) {
            $normalized = NCX_CP_API_Apple_Pay_Domain::normalize_incoming($value);
            if (!$normalized['ok']) {
                WC_Admin_Settings::add_error($normalized['error']);
                $has_error = true;
            } else {
                $files[NCX_CP_API_Apple_Pay_Domain::FILENAME] = $normalized['body'];
            }
        }

        if (count($files) > NCX_CP_API_Apple_Pay_Domain::MAX_FILES) {
            WC_Admin_Settings::add_error(
                sprintf(
                    /* translators: %d: maximum file count */
                    __('Too many .well-known files (maximum %d).', 'ncx-cp-api'),
                    NCX_CP_API_Apple_Pay_Domain::MAX_FILES
                )
            );
            $has_error = true;
        }

        $total_bytes = array_sum(array_map('strlen', $files));
        if ($total_bytes > NCX_CP_API_Apple_Pay_Domain::MAX_TOTAL_BYTES) {
            WC_Admin_Settings::add_error(
                sprintf(
                    /* translators: %d: maximum total bytes */
                    __('Association files exceed the combined limit of %d bytes.', 'ncx-cp-api'),
                    NCX_CP_API_Apple_Pay_Domain::MAX_TOTAL_BYTES
                )
            );
            $has_error = true;
        }

        if ($has_error) {
            return $existing;
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Admin-only: fetch the shop's well-known Apple Pay file (fixed URL, no client URL).
     */
    public function ajax_apple_pay_domain_check(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ncx-cp-api')]);
            return;
        }

        if (!check_ajax_referer('ncx_cp_apple_pay_domain_check', 'security', false)) {
            wp_send_json_error(['message' => __('Security check failed. Reload this settings page and try again.', 'ncx-cp-api')]);
            return;
        }

        $result = NCX_CP_API_Apple_Pay_Domain::check_public_url();
        if (empty($result['ok'])) {
            wp_send_json_error($result);
            return;
        }

        wp_send_json_success($result);
    }

    /**
     * Settings UI for hosting the Apple Pay domain association file.
     */
    public function generate_apple_pay_domain_association_html(string $key, array $data): string {
        $field_key = $this->get_field_key($key);
        $files = NCX_CP_API_Apple_Pay_Domain::normalize_stored_value($this->get_option($key, []));
        $write = get_transient('ncx_cp_apple_pay_domain_write');
        if (is_array($write)) {
            delete_transient('ncx_cp_apple_pay_domain_write');
        } else {
            $write = null;
        }

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label>
            </th>
            <td class="forminp">
                <p class="description">
                    <?php esc_html_e('Upload association files to host beneath /.well-known/. Filenames must be simple basenames with no extension, .json, or .txt. Existing unmanaged files on disk are never overwritten or deleted.', 'ncx-cp-api'); ?>
                </p>
                <?php if (NCX_CP_API_Apple_Pay_Domain::wordpress_not_at_domain_root()) : ?>
                    <p class="description">
                        <?php esc_html_e('WordPress is not installed at the domain root. Apple checks the hostname root, so you may need a web-server alias for /.well-known/.', 'ncx-cp-api'); ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($files)) : ?>
                    <p><strong><?php esc_html_e('Hosted files', 'ncx-cp-api'); ?></strong></p>
                    <?php foreach ($files as $filename => $body) : ?>
                        <?php $fp = NCX_CP_API_Apple_Pay_Domain::body_fingerprint($body); ?>
                        <div style="margin:0 0 12px;padding:10px;border:1px solid #dcdcde;background:#fff;">
                            <strong><?php echo esc_html($filename); ?></strong>
                            <span>
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: 1: byte length, 2: sha256 prefix */
                                        __('— %1$d bytes, SHA-256 prefix %2$s', 'ncx-cp-api'),
                                        $fp['length'],
                                        $fp['sha256_12']
                                    )
                                );
                                ?>
                            </span>
                            <?php foreach (NCX_CP_API_Apple_Pay_Domain::public_urls($filename) as $url) : ?>
                                <code style="display:block;word-break:break-all;"><?php echo esc_html($url); ?></code>
                            <?php endforeach; ?>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($field_key); ?>_clear[]" value="<?php echo esc_attr($filename); ?>" />
                                <?php esc_html_e('Remove this hosted file', 'ncx-cp-api'); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p><?php esc_html_e('No association files are currently stored.', 'ncx-cp-api'); ?></p>
                <?php endif; ?>
                <?php if (is_array($write) && !empty($write['message'])) : ?>
                    <p class="description"><?php echo esc_html((string) $write['message']); ?></p>
                <?php endif; ?>
                <p>
                    <label for="<?php echo esc_attr($field_key); ?>_upload"><?php esc_html_e('Upload one or more files', 'ncx-cp-api'); ?></label><br />
                    <input type="file" id="<?php echo esc_attr($field_key); ?>_upload" name="<?php echo esc_attr($field_key); ?>_upload[]" multiple />
                </p>
                <p>
                    <label for="<?php echo esc_attr($field_key); ?>"><?php esc_html_e('Or paste Apple association file contents', 'ncx-cp-api'); ?></label><br />
                    <textarea
                        id="<?php echo esc_attr($field_key); ?>"
                        name="<?php echo esc_attr($field_key); ?>"
                        rows="6"
                        class="large-text"
                        placeholder="<?php echo esc_attr(__('Paste to replace apple-developer-merchantid-domain-association', 'ncx-cp-api')); ?>"
                    ></textarea>
                </p>
                <p>
                    <button type="button" class="button button-secondary" id="ncx-cp-apple-pay-domain-check">
                        <?php esc_html_e('Check Apple association URL', 'ncx-cp-api'); ?>
                    </button>
                    <span class="spinner" style="float:none;margin-top:0;"></span>
                </p>
                <pre id="ncx-cp-apple-pay-domain-check-results" style="display:none;max-width:100%;white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;margin-top:8px;"></pre>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders the connectivity test button on the gateway settings screen.
     */
    public function generate_opp_connectivity_test_html(string $key, array $data): string {
        $field_key = $this->get_field_key($key);
        // get_description_html() reads desc_tip unconditionally, so the key has
        // to exist even though this field never uses a tooltip.
        $defaults  = [
            'title'       => '',
            'description' => '',
            'desc_tip'    => false,
        ];
        $data = wp_parse_args($data, $defaults);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label>
            </th>
            <td class="forminp" id="<?php echo esc_attr($field_key); ?>">
                <?php echo wp_kses_post($this->get_description_html($data)); ?>
                <p>
                    <button type="button" class="button button-secondary" id="ncx-cp-opp-connectivity-test">
                        <?php esc_html_e('Run connectivity test', 'ncx-cp-api'); ?>
                    </button>
                    <span class="spinner" style="float:none;margin-top:0;"></span>
                </p>
                <pre id="ncx-cp-opp-connectivity-results" style="display:none;max-width:100%;white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;margin-top:8px;"></pre>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Loads the admin script for the connectivity test button.
     */
    public function maybe_enqueue_admin_assets(string $hook_suffix): void {
        if ('woocommerce_page_wc-settings' !== $hook_suffix) {
            return;
        }

        if (!isset($_GET['section']) || 'ncx_cp_api' !== sanitize_text_field(wp_unslash($_GET['section']))) {
            return;
        }

        // wp_add_inline_script() appends rather than replaces, so a second gateway
        // instance hooking admin_enqueue_scripts would print the settings-rebuild
        // script twice and nest each card inside the one built by the first pass.
        static $enqueued = false;
        if ($enqueued) {
            return;
        }
        $enqueued = true;

        wp_register_script('ncx-cp-api-admin', false, ['jquery'], NCX_CP_API::VERSION, true);
        wp_enqueue_script('ncx-cp-api-admin');
        // Versioned by file time as well as plugin version, so a stylesheet edit
        // between releases is not served from the browser cache.
        $admin_css_path = dirname(__DIR__) . '/assets/css/admin-settings.css';
        $admin_css_time = file_exists($admin_css_path) ? (string) filemtime($admin_css_path) : '';

        wp_enqueue_style(
            'ncx-cp-api-admin-settings',
            plugins_url('../assets/css/admin-settings.css', __FILE__),
            [],
            '' === $admin_css_time ? NCX_CP_API::VERSION : NCX_CP_API::VERSION . '.' . $admin_css_time
        );
        wp_localize_script(
            'ncx-cp-api-admin',
            'ncxCpAdmin',
            [
                'ajaxUrl'           => admin_url('admin-ajax.php'),
                'connectivityNonce' => wp_create_nonce('ncx_cp_opp_connectivity_test'),
                'domainCheckNonce'  => wp_create_nonce('ncx_cp_apple_pay_domain_check'),
                'setupLabel'        => __('Setup', 'ncx-cp-api'),
                'checkoutLabel'     => __('Checkout', 'ncx-cp-api'),
                'supportLabel'      => __('Support', 'ncx-cp-api'),
                'tabsAriaLabel'     => __('Payment settings sections', 'ncx-cp-api'),
                'savedCardsLabel'   => __('Saved cards', 'ncx-cp-api'),
                'savedCardsUrl'     => class_exists('NCX_CP_API_Saved_Cards_Admin') ? NCX_CP_API_Saved_Cards_Admin::page_url() : '',
                'cardPaymentsTitle' => __('Card payments', 'ncx-cp-api'),
                'cardPaymentsHelp'  => __('Turn payments on and connect the account that receives your funds.', 'ncx-cp-api'),
                'applePayTitle'     => __('Apple Pay', 'ncx-cp-api'),
                'applePayHelp'      => __('Offer Apple Pay and manage the required domain files.', 'ncx-cp-api'),
                'googlePayTitle'    => __('Google Pay', 'ncx-cp-api'),
                'googlePayHelp'     => __('Offer Google Pay and set the Google merchant identifier.', 'ncx-cp-api'),
                'customerViewTitle' => __('What customers see', 'ncx-cp-api'),
                'customerViewHelp'  => __('Keep the checkout wording clear and choose a style that suits your store.', 'ncx-cp-api'),
                'paymentOptionsTitle' => __('Payment options', 'ncx-cp-api'),
                'paymentOptionsHelp'  => __('Recommended: keep the current defaults unless your store needs something different.', 'ncx-cp-api'),
                'supportIntro'         => __('Troubleshooting tools are kept here so everyday payment setup stays simple.', 'ncx-cp-api'),
                'technicalTitle'      => __('Technical diagnostics', 'ncx-cp-api'),
                'technicalHelp'       => __('Open only when Nochex support asks you to use them.', 'ncx-cp-api'),
            ]
        );
        wp_add_inline_script(
            'ncx-cp-api-admin',
            'jQuery(function($){
                // Rebuilding the settings table is not repeatable: the second pass
                // finds the fields already inside a card and nests a new card there.
                if(window.ncxCpSettingsUiBooted){return;}
                window.ncxCpSettingsUiBooted=true;

                var fieldPrefix="woocommerce_ncx_cp_api_";
                var setupKeys=["enabled","test_mode","live_entity_id","live_access_token","enable_apple_pay","apple_pay_display_name","apple_pay_domain_association","enable_google_pay","google_pay_display_name","google_pay_merchant_id"];
                var checkoutKeys=["title","show_description","description","minimum_payment_amount","checkout_theme","inline_note_text","inline_border_radius","enable_tokenization","enable_apple_pay_product_page","enable_google_pay_product_page","enable_dupe_check"];
                var supportKeys=["enable_console_log","enable_server_log","log_levels","provider_session_rate_limit","opp_entity_in_query","opp_connectivity_test","enable_apple_pay_log","enable_google_pay_log"];
                var appleSetupKeys=["apple_pay_display_name","apple_pay_domain_association"];
                var googleSetupKeys=["google_pay_display_name","google_pay_merchant_id"];
                var liveKeys=["live_entity_id","live_access_token"];
                var allKeys=setupKeys.concat(checkoutKeys,supportKeys);
                var activeTab="setup";

                function fieldRow(key){
                    return $("#"+fieldPrefix+key).closest("tr");
                }
                function setRows(keys,visible){
                    $.each(keys,function(_,key){fieldRow(key).toggle(!!visible);});
                }
                function hideHeading(id){
                    var $marker=$("#"+id);
                    var $row=$marker.closest("tr");
                    if($row.length){$row.hide();return;}
                    var $heading=$marker.closest("h2,h3");
                    $heading.next("p").hide();
                    $heading.hide();
                }
                function readActiveTab(){
                    var stored="";
                    try{stored=window.sessionStorage.getItem("ncxCpSettingsTab")||"";}catch(e){}
                    return $.inArray(stored,["setup","checkout","support"])!==-1?stored:"setup";
                }
                function writeActiveTab(tab){
                    try{window.sessionStorage.setItem("ncxCpSettingsTab",tab);}catch(e){}
                }
                function addSettingsTabs(){
                    var $before=fieldRow("enabled");
                    if(!$before.length||$("#ncx-cp-settings-tabs").length){return;}
                    var tabs=[
                        {id:"setup",label:ncxCpAdmin.setupLabel},
                        {id:"checkout",label:ncxCpAdmin.checkoutLabel},
                        {id:"support",label:ncxCpAdmin.supportLabel}
                    ];
                    var $tablist=$("<div>",{id:"ncx-cp-settings-tabs","class":"ncx-cp-settings-tablist","aria-label":ncxCpAdmin.tabsAriaLabel});
                    $.each(tabs,function(_,tab){
                        $tablist.append($("<button>",{
                            type:"button",
                            "class":"ncx-cp-settings-tab",
                            "data-ncx-tab":tab.id,
                            "aria-pressed":"false"
                        }).text(tab.label));
                    });
                    if(ncxCpAdmin.savedCardsUrl){
                        $tablist.append($("<a>",{
                            "class":"ncx-cp-settings-tab ncx-cp-settings-tab-link",
                            href:ncxCpAdmin.savedCardsUrl
                        }).text(ncxCpAdmin.savedCardsLabel));
                    }
                    var $tabsRow=$("<tr>",{"class":"ncx-cp-settings-tabs"}).append($("<th>",{colspan:2}).append($tablist));
                    $before.before($tabsRow);
                    $tablist.on("click","button.ncx-cp-settings-tab",function(){
                        activeTab=String($(this).data("ncx-tab")||"setup");
                        writeActiveTab(activeTab);
                        applySettingsVisibility();
                    });
                }
                function moveRows($target,keys){
                    $.each(keys,function(_,key){
                        var $row=fieldRow(key);
                        if($row.length){
                            $row.addClass("ncx-cp-settings-field").detach().appendTo($target);
                        }
                    });
                }
                function addSettingsCard(id,tab,title,description,keys){
                    if($("#"+id+"-title").length){return;}
                    var $first=fieldRow(keys[0]);
                    if(!$first.length){return;}
                    var $body=$("<tbody>");
                    var $table=$("<table>",{"class":"form-table","role":"presentation"}).append($body);
                    var $title=$("<h2>",{"class":"ncx-cp-card-title",id:id+"-title"}).text(title);
                    var $header=$("<header>",{"class":"ncx-cp-card-header"})
                        .append($title)
                        .append($("<p>",{"class":"ncx-cp-card-description"}).text(description));
                    var $card=$("<section>",{"class":"ncx-cp-panel-card","aria-labelledby":id+"-title"})
                        .append($header)
                        .append($("<div>",{"class":"ncx-cp-card-content"}).append($table));
                    var $shell=$("<tr>",{"class":"ncx-cp-card-shell","data-ncx-panel":tab})
                        .append($("<td>",{colspan:2}).append($card));
                    $first.before($shell);
                    moveRows($body,keys);
                }
                function addSupportDetails(keys){
                    if($(".ncx-cp-support-details").length){return;}
                    var $first=fieldRow(keys[0]);
                    if(!$first.length){return;}
                    var $body=$("<tbody>");
                    var $summaryCopy=$("<span>",{"class":"ncx-cp-support-summary-copy"})
                        .append($("<strong>").text(ncxCpAdmin.technicalTitle))
                        .append($("<span>").text(ncxCpAdmin.technicalHelp));
                    var $details=$("<details>",{"class":"ncx-cp-support-details"})
                        .append($("<summary>").append($summaryCopy))
                        .append($("<div>",{"class":"ncx-cp-support-content"})
                            .append($("<table>",{"class":"form-table","role":"presentation"}).append($body)));
                    var $shell=$("<tr>",{"class":"ncx-cp-card-shell","data-ncx-panel":"support"})
                        .append($("<td>",{colspan:2})
                            .append($("<p>",{"class":"ncx-cp-support-intro"}).text(ncxCpAdmin.supportIntro))
                            .append($details));
                    $first.before($shell);
                    moveRows($body,keys);
                }
                function buildSettingsCards(){
                    var $sourceTables=$();
                    $.each(allKeys,function(_,key){
                        $sourceTables=$sourceTables.add(fieldRow(key).closest("table"));
                    });
                    addSettingsCard(
                        "ncx-cp-card-payments",
                        "setup",
                        ncxCpAdmin.cardPaymentsTitle,
                        ncxCpAdmin.cardPaymentsHelp,
                        ["enabled","test_mode","live_entity_id","live_access_token"]
                    );
                    addSettingsCard(
                        "ncx-cp-card-apple-pay",
                        "setup",
                        ncxCpAdmin.applePayTitle,
                        ncxCpAdmin.applePayHelp,
                        ["enable_apple_pay","apple_pay_display_name","apple_pay_domain_association"]
                    );
                    addSettingsCard(
                        "ncx-cp-card-google-pay",
                        "setup",
                        ncxCpAdmin.googlePayTitle,
                        ncxCpAdmin.googlePayHelp,
                        ["enable_google_pay","google_pay_display_name","google_pay_merchant_id"]
                    );
                    addSettingsCard(
                        "ncx-cp-card-customer-view",
                        "checkout",
                        ncxCpAdmin.customerViewTitle,
                        ncxCpAdmin.customerViewHelp,
                        ["title","show_description","description","checkout_theme","inline_note_text","inline_border_radius"]
                    );
                    addSettingsCard(
                        "ncx-cp-card-payment-options",
                        "checkout",
                        ncxCpAdmin.paymentOptionsTitle,
                        ncxCpAdmin.paymentOptionsHelp,
                        ["minimum_payment_amount","enable_tokenization","enable_apple_pay_product_page","enable_google_pay_product_page","enable_dupe_check"]
                    );
                    addSupportDetails(supportKeys);
                    $sourceTables.each(function(){
                        var $table=$(this);
                        if(!$table.children("tbody").children("tr").length){
                            $table.addClass("ncx-cp-source-table-empty");
                        }
                    });
                }
                function applySettingsVisibility(){
                    var live=$("#"+fieldPrefix+"test_mode").val()==="no";
                    var apple=$("#"+fieldPrefix+"enable_apple_pay").is(":checked");
                    var google=$("#"+fieldPrefix+"enable_google_pay").is(":checked");
                    var serverLog=$("#"+fieldPrefix+"enable_server_log").is(":checked");
                    var showDescription=$("#"+fieldPrefix+"show_description").is(":checked");

                    $(".ncx-cp-card-shell").hide()
                        .filter("[data-ncx-panel="+activeTab+"]").show();
                    setRows(allKeys,true);
                    setRows(liveKeys,live);
                    setRows(appleSetupKeys,apple);
                    setRows(googleSetupKeys,google);
                    fieldRow("enable_apple_pay_product_page").toggle(apple);
                    fieldRow("enable_apple_pay_log").toggle(apple);
                    fieldRow("enable_google_pay_product_page").toggle(google);
                    fieldRow("enable_google_pay_log").toggle(google);
                    fieldRow("log_levels").toggle(serverLog);
                    fieldRow("description").toggle(showDescription);
                    $.each(appleSetupKeys,function(_,key){fieldRow(key).toggleClass("ncx-cp-apple-setup-row",apple);});
                    $("button.ncx-cp-settings-tab").each(function(){
                        var selected=String($(this).data("ncx-tab"))===activeTab;
                        $(this).toggleClass("is-active",selected).attr("aria-pressed",selected?"true":"false");
                    });
                }

                if(typeof ncxCpAdmin!=="undefined"){
                    activeTab=readActiveTab();
                    $("#mainform").addClass("ncx-cp-settings-admin");
                    hideHeading("ncx-cp-heading-account");
                    hideHeading("ncx-cp-heading-appearance");
                    hideHeading("ncx-cp-heading-advanced");
                    hideHeading("ncx-cp-heading-support");
                    addSettingsTabs();
                    buildSettingsCards();
                    $("#"+fieldPrefix+"test_mode,#"+fieldPrefix+"enable_apple_pay,#"+fieldPrefix+"enable_google_pay,#"+fieldPrefix+"enable_server_log,#"+fieldPrefix+"show_description").on("change",applySettingsVisibility);
                    applySettingsVisibility();
                }

                function bindAdminTest($btn,$out,action,nonce){
                    if(!$btn.length||typeof ncxCpAdmin==="undefined"){return;}
                    var $spinner=$btn.siblings(".spinner");
                    $btn.on("click",function(){
                        $btn.prop("disabled",true);
                        $spinner.addClass("is-active");
                        $out.hide().text("");
                        $.ajax({
                            url: ncxCpAdmin.ajaxUrl,
                            type: "POST",
                            dataType: "json",
                            data: { action: action, security: nonce }
                        }).done(function(resp){
                            if(resp&&resp.data){
                                $out.text(JSON.stringify(resp.data,null,2)).show();
                            }else{
                                $out.text("Test failed").show();
                            }
                        }).fail(function(xhr){
                            var detail=xhr.responseText||"";
                            if(detail==="0"){
                                detail="Admin AJAX action not registered. Confirm both ncx-cp-api.php and the gateway file are updated and the plugin is active.";
                            }
                            $out.text("HTTP "+xhr.status+": "+detail).show();
                        }).always(function(){
                            $btn.prop("disabled",false);
                            $spinner.removeClass("is-active");
                        });
                    });
                }
                bindAdminTest($("#ncx-cp-opp-connectivity-test"),$("#ncx-cp-opp-connectivity-results"),"ncx_cp_opp_connectivity_test",ncxCpAdmin.connectivityNonce);
                bindAdminTest($("#ncx-cp-apple-pay-domain-check"),$("#ncx-cp-apple-pay-domain-check-results"),"ncx_cp_apple_pay_domain_check",ncxCpAdmin.domainCheckNonce);
            });',
            'after'
        );
    }

    private function redact_opp_request_url(string $url): string {
        return (string) preg_replace_callback(
            '/([?&]entityId=)([^&]+)/',
            static function (array $matches): string {
                $value = urldecode($matches[2]);
                return $matches[1] . '***' . substr($value, -8);
            },
            $url
        );
    }

    private function redact_opp_request_headers(array $headers): array {
        $out = $headers;
        foreach (['Authorization', 'authorization'] as $key) {
            if (!isset($out[$key])) {
                continue;
            }
            $value = (string) $out[$key];
            if (0 === stripos($value, 'Bearer ')) {
                $token = substr($value, 7);
                $out[$key] = 'Bearer ' . ('' !== $token ? substr($token, 0, 6) . '…' : '');
            } else {
                $out[$key] = '***';
            }
        }
        return $out;
    }

    private function encode_opp_log_body(array $body): string {
        $encoded = http_build_query($this->redact_opp_log_body($body), '', '&', PHP_QUERY_RFC3986);
        return mb_substr($encoded, 0, 2000);
    }

    /**
     * Redact sensitive values before writing OPP request bodies to logs.
     */
    private function redact_opp_log_body(array $body): array {
        $out = $body;
        if (isset($out['entityId'])) {
            $out['entityId'] = '***' . substr((string) $out['entityId'], -8);
        }
        foreach ($out as $key => $value) {
            if (preg_match('/^registrations\[\d+\]\.id$/', (string) $key)) {
                $out[$key] = is_string($value) ? substr($value, 0, 8) . '…' : '***';
            }
        }
        return $out;
    }

    /**
     * Safe credential summary for diagnostics (never log full token).
     */
    private function summarize_opp_credentials(array $credentials, ?bool $force_test): array {
        $token  = (string) ($credentials['access_token'] ?? '');
        $entity = (string) ($credentials['entity_id'] ?? '');
        $use_test = is_null($force_test) ? (bool) $this->test_mode : (bool) $force_test;

        return [
            'use_test'            => $use_test,
            'region_host'         => $this->get_region_host($force_test),
            'entity_id_suffix'    => strlen($entity) >= 8 ? substr($entity, -8) : $entity,
            'entity_id_length'    => strlen($entity),
            'access_token_length' => strlen($token),
            'access_token_prefix' => '' !== $token ? substr($token, 0, 6) . '…' : '',
            'credentials_present' => $this->credentials_present($credentials),
        ];
    }

    private function flatten_http_headers($headers): array {
        if (is_array($headers)) {
            return $headers;
        }
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            return $headers->getAll();
        }
        return [];
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
