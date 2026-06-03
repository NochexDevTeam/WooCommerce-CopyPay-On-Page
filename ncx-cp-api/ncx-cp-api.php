<?php
/**
 * Plugin Name: Nochex CopyandPay On Page
 * Description: COPYandPAY integration utilities plus a WooCommerce payment gateway.
 * Version: 4
 * Author: Nochex
 * License: GPL-2.0-or-later
 * Text Domain: ncx-cp-api
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.6
 */

if (!defined('ABSPATH')) {
    exit;
}

final class NCX_CP_API {
    /**
     * WooCommerce stores gateway settings under this key in wp_options.
     * Read-only – the gateway itself manages persistence via process_admin_options().
     */
    private const WC_OPTION_KEY = 'woocommerce_ncx_cp_api_settings';
    private const BLOCK_HANDLE = 'ncx-cp-api-block';
    public const VERSION = '46';

    private string $plugin_dir;
    private string $plugin_url;

    // Boots the plugin by wiring shortcodes, blocks, and the frame renderer.
    public function __construct() {
        $this->plugin_dir = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);

        add_shortcode('ncx_cp_widget', [$this, 'render_widget_shortcode']);
        add_action('init', [$this, 'register_block']);
    }

    // Provides a shortcode for embedding the CopyAndPay widget with attributes.
    public function render_widget_shortcode(array $atts = []): string {
        $atts = shortcode_atts([
            'checkout_id' => '',
            'brands' => 'VISA MASTER',
        ], $atts, 'ncx_cp_widget');

        return self::render_widget_markup($atts['checkout_id'], $atts['brands']);
    }

    // Registers the Gutenberg block that renders the CopyAndPay widget.
    public function register_block(): void {
        if (!function_exists('register_block_type')) {
            return;
        }

        wp_register_script(
            self::BLOCK_HANDLE,
            $this->plugin_url . 'assets/js/block.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-i18n'],
            self::VERSION,
            true
        );

        register_block_type(
            'ncx/copyandpay-widget',
            [
                'editor_script' => self::BLOCK_HANDLE,
                'render_callback' => [$this, 'render_block'],
                'attributes' => [
                    'checkoutId' => [
                        'type' => 'string',
                        'default' => '',
                    ],
                    'brands' => [
                        'type' => 'string',
                        'default' => 'VISA MASTER',
                    ],
                ],
                'supports' => [
                    'align' => false,
                ],
            ]
        );
    }

    // Server-renders the block output by delegating to the widget markup helper.
    public function render_block(array $attributes): string {
        $checkout_id = $attributes['checkoutId'] ?? '';
        $brands = $attributes['brands'] ?? 'VISA MASTER';

        return self::render_widget_markup($checkout_id, $brands);
    }

    // Outputs the COPYandPAY script include plus the payment form scaffold.
    public static function render_widget_markup(string $checkout_id, string $brands, ?string $region_host = null): string {
        if ('' === trim($checkout_id)) {
            return '<div class="ncx-cp-widget-error">' . esc_html__('Provide a checkout identifier to load the COPYandPAY widget.', 'ncx-cp-api') . '</div>';
        }

        if (null === $region_host || '' === $region_host) {
            $settings = self::get_settings();
            $region_host = self::build_region_host($settings);
        }

        ob_start();
        ?>
        <script src="<?php echo esc_url($region_host . '/v1/paymentWidgets.js?checkoutId=' . rawurlencode($checkout_id)); ?>"></script>
        <form action="<?php echo esc_url($region_host . '/v1/checkouts/' . rawurlencode($checkout_id) . '/payment'); ?>" class="paymentWidgets" data-brands="<?php echo esc_attr($brands); ?>">
        </form>
        <?php
        return trim(ob_get_clean());
    }

    // ── Static helpers (read from WooCommerce gateway settings) ───────

    // Fetches the saved WooCommerce gateway settings safely as an array.
    public static function get_settings(): array {
        $options = get_option(self::WC_OPTION_KEY, []);
        return is_array($options) ? $options : [];
    }

    // Determines the correct OPP endpoint host based on region and mode.
    public static function build_region_host(array $options): string {
        $region = $options['region'] ?? 'eu';
        $is_test = ($options['test_mode'] ?? 'yes') === 'yes';
        $suffix = $is_test ? '-test' : '-prod';

        return sprintf('https://%s%s.oppwa.com', $region, $suffix);
    }

    // Hardcoded test credentials (same as nochexapi).
    private const TEST_ENTITY_ID    = '8ac7a4ca7843f17d017844faa85f0829';
    private const TEST_ACCESS_TOKEN = 'OGFjN2E0Y2E3ODQzZjE3ZDAxNzg0NGY4MTFjNjA4MjR8V2hFMlB4WHdFcA';

    // Resolves the credentials array for the current mode, falling back to test values.
    public static function get_active_credentials(): array {
        $settings = self::get_settings();
        $is_test = ($settings['test_mode'] ?? 'yes') === 'yes';

        if ($is_test) {
            return [
                'entity_id'    => self::TEST_ENTITY_ID,
                'access_token' => self::TEST_ACCESS_TOKEN,
            ];
        }

        return [
            'entity_id'    => $settings['live_entity_id'] ?? '',
            'access_token' => $settings['live_access_token'] ?? '',
        ];
    }

    // Indicates whether console logging is enabled in the plugin settings.
    public static function is_console_logging_enabled(): bool {
        $settings = self::get_settings();
        return ($settings['enable_console_log'] ?? 'no') === 'yes';
    }

    // Checks if server-side logging should run for the requested severity level.
    public static function is_server_logging_enabled(string $level): bool {
        $settings = self::get_settings();
        if (($settings['enable_server_log'] ?? 'no') !== 'yes') {
            return false;
        }

        $levels = (array) ($settings['log_levels'] ?? ['error', 'warning', 'info']);
        return in_array(strtolower($level), $levels, true);
    }

}

new NCX_CP_API();

add_action('plugins_loaded', static function () {
    load_plugin_textdomain('ncx-cp-api', false, dirname(plugin_basename(__FILE__)) . '/languages');

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-ncx-cp-api-gateway.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-ncx-cp-api-saved-cards-admin.php';
    new NCX_CP_API_Saved_Cards_Admin();

    add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
        $gateways[] = 'NCX_CP_API_Gateway';
        return $gateways;
    });

    // Register AJAX actions early so handlers work even if WooCommerce has not
    // instantiated payment gateways before admin-ajax.php executes.
    $gateway_ajax = static function (string $method) {
        return static function () use ($method) {
            if (function_exists('WC') && WC()->payment_gateways()) {
                $gateways = WC()->payment_gateways()->payment_gateways();
                if (isset($gateways['ncx_cp_api']) && $gateways['ncx_cp_api'] instanceof NCX_CP_API_Gateway && method_exists($gateways['ncx_cp_api'], $method)) {
                    $gateways['ncx_cp_api']->{$method}();
                    return;
                }
            }
            wp_send_json_error(['message' => 'Gateway not available']);
        };
    };

    add_action('wp_ajax_ncx_cp_request_checkout_id',        $gateway_ajax('ajax_request_checkout_id'));
    add_action('wp_ajax_nopriv_ncx_cp_request_checkout_id', $gateway_ajax('ajax_request_checkout_id'));
});

// Declare HPOS (High-Performance Order Storage) compatibility.
add_action('before_woocommerce_init', static function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Register the WooCommerce Blocks payment method integration.
add_action('woocommerce_blocks_loaded', static function () {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-ncx-cp-api-blocks.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        static function ($registry) {
            $registry->register(new NCX_CP_API_Blocks());
        }
    );
});
