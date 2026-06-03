<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin page listing all saved card tokens for the NCX gateway.
 * Accessible under WooCommerce > Saved Cards.
 */
final class NCX_CP_API_Saved_Cards_Admin {

    private const MENU_SLUG   = 'ncx-cp-saved-cards';
    private const GATEWAY_ID  = 'ncx_cp_api';
    private const DELETE_ACTION = 'ncx_cp_delete_token';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu'], 90);
        add_action('admin_init', [$this, 'handle_delete']);
    }

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('Saved Cards', 'ncx-cp-api'),
            __('Saved Cards', 'ncx-cp-api'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    public function handle_delete(): void {
        if (
            !isset($_GET['action'], $_GET['token_id'], $_GET['_wpnonce']) ||
            $_GET['action'] !== self::DELETE_ACTION
        ) {
            return;
        }

        $token_id = absint($_GET['token_id']);

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::DELETE_ACTION . '_' . $token_id)) {
            wp_die(__('Security check failed.', 'ncx-cp-api'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to do this.', 'ncx-cp-api'));
        }

        if (!function_exists('WC') || !WC()->payment_gateways()) {
            wp_die(__('WooCommerce is not available.', 'ncx-cp-api'));
        }

        // Ensure gateway hooks (including OPP deregistration) are registered before delete.
        WC()->payment_gateways()->payment_gateways();

        $token = WC_Payment_Tokens::get($token_id);
        if ($token && $token->get_gateway_id() === self::GATEWAY_ID) {
            WC_Payment_Tokens::delete($token_id);
            $redirect = add_query_arg([
                'page'    => self::MENU_SLUG,
                'deleted' => '1',
            ], admin_url('admin.php'));
        } else {
            $redirect = add_query_arg([
                'page'  => self::MENU_SLUG,
                'error' => 'not_found',
            ], admin_url('admin.php'));
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public function render_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'ncx-cp-api'));
        }

        global $wpdb;

        // Fetch all tokens for our gateway joined with user data.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.token_id, t.token, t.user_id, t.type, t.is_default,
                    tm_last4.meta_value   AS last4,
                    tm_type.meta_value    AS card_type,
                    tm_month.meta_value   AS expiry_month,
                    tm_year.meta_value    AS expiry_year,
                    tm_test.meta_value    AS is_test,
                    u.user_email, u.display_name
             FROM {$wpdb->prefix}woocommerce_payment_tokens t
             LEFT JOIN {$wpdb->prefix}woocommerce_payment_tokenmeta tm_last4
                 ON tm_last4.payment_token_id = t.token_id AND tm_last4.meta_key = 'last4'
             LEFT JOIN {$wpdb->prefix}woocommerce_payment_tokenmeta tm_type
                 ON tm_type.payment_token_id = t.token_id AND tm_type.meta_key = 'card_type'
             LEFT JOIN {$wpdb->prefix}woocommerce_payment_tokenmeta tm_month
                 ON tm_month.payment_token_id = t.token_id AND tm_month.meta_key = 'expiry_month'
             LEFT JOIN {$wpdb->prefix}woocommerce_payment_tokenmeta tm_year
                 ON tm_year.payment_token_id = t.token_id AND tm_year.meta_key = 'expiry_year'
             LEFT JOIN {$wpdb->prefix}woocommerce_payment_tokenmeta tm_test
                 ON tm_test.payment_token_id = t.token_id AND tm_test.meta_key = %s
             LEFT JOIN {$wpdb->users} u
                 ON u.ID = t.user_id
             WHERE t.gateway_id = %s
             ORDER BY t.user_id ASC, t.token_id ASC",
            'test',
            self::GATEWAY_ID
        ));

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Saved Cards', 'ncx-cp-api'); ?></h1>

            <?php if (isset($_GET['deleted']) && $_GET['deleted'] === '1'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Card deleted successfully.', 'ncx-cp-api'); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error'] === 'not_found'): ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Token not found or does not belong to this gateway.', 'ncx-cp-api'); ?></p></div>
            <?php endif; ?>

            <?php if (empty($rows)): ?>
                <p><?php esc_html_e('No saved cards found.', 'ncx-cp-api'); ?></p>
            <?php else: ?>
                <table class="widefat striped fixed">
                    <thead>
                        <tr>
                            <th style="width:50px;"><?php esc_html_e('ID', 'ncx-cp-api'); ?></th>
                            <th><?php esc_html_e('Customer', 'ncx-cp-api'); ?></th>
                            <th><?php esc_html_e('Card', 'ncx-cp-api'); ?></th>
                            <th style="width:100px;"><?php esc_html_e('Expires', 'ncx-cp-api'); ?></th>
                            <th style="width:80px;"><?php esc_html_e('Env', 'ncx-cp-api'); ?></th>
                            <th style="width:60px;"><?php esc_html_e('Default', 'ncx-cp-api'); ?></th>
                            <th style="width:100px;"><?php esc_html_e('Actions', 'ncx-cp-api'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row->token_id); ?></td>
                                <td>
                                    <?php
                                    echo esc_html($row->display_name ?: __('(unknown)', 'ncx-cp-api'));
                                    if ($row->user_email) {
                                        echo '<br><small>' . esc_html($row->user_email) . '</small>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $brand = $row->card_type ? ucfirst($row->card_type) : '—';
                                    $last4 = $row->last4 ?: '****';
                                    echo esc_html($brand . ' •••• ' . $last4);
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $month = $row->expiry_month ? str_pad($row->expiry_month, 2, '0', STR_PAD_LEFT) : '??';
                                    $year  = $row->expiry_year ?: '??';
                                    echo esc_html($month . '/' . $year);
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $env = ($row->is_test !== null) ? __('Test', 'ncx-cp-api') : __('Live', 'ncx-cp-api');
                                    $color = ($row->is_test !== null) ? '#dbeafe' : '#dcfce7';
                                    echo '<span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;background:' . esc_attr($color) . ';">' . esc_html($env) . '</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php echo $row->is_default ? '&#9733;' : '—'; ?>
                                </td>
                                <td>
                                    <?php
                                    $delete_url = wp_nonce_url(
                                        add_query_arg([
                                            'page'     => self::MENU_SLUG,
                                            'action'   => self::DELETE_ACTION,
                                            'token_id' => $row->token_id,
                                        ], admin_url('admin.php')),
                                        self::DELETE_ACTION . '_' . $row->token_id
                                    );
                                    ?>
                                    <a href="<?php echo esc_url($delete_url); ?>"
                                       class="button button-small"
                                       onclick="return confirm('<?php echo esc_js(__('Delete this saved card? This will also deregister it at the payment provider.', 'ncx-cp-api')); ?>');">
                                        <?php esc_html_e('Delete', 'ncx-cp-api'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description" style="margin-top:12px;">
                    <?php printf(
                        esc_html__('%d saved card(s) across all customers.', 'ncx-cp-api'),
                        count($rows)
                    ); ?>
                </p>
            <?php endif; ?>

            <hr style="margin-top:24px;">
            <p class="description">
                <?php esc_html_e('Customers can manage their own saved cards from My Account > Payment methods.', 'ncx-cp-api'); ?>
            </p>
        </div>
        <?php
    }
}
