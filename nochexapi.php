<?php

/*
Plugin Name: Nochex On-Page API
Plugin URI: https://support.nochex.com/
Description: Accept Payments in Woocommerce with Nochex On-Page API.
Version: 3
Author: Nochex Ltd
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'NOCHEXAPI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Currently plugin version.
 */
//define( 'nochexapi_VERSION', '5.2.0' );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-nochexapi-constants.php';

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-nochexapi-activator.php
 */
function activate_nochexapi() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-nochexapi-activator.php';
	nochexapi_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-nochexapi-deactivator.php
 */
function deactivate_nochexapi() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-nochexapi-deactivator.php';
	nochexapi_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_nochexapi' );
register_deactivation_hook( __FILE__, 'deactivate_nochexapi' );

/**
 * WooCommerce not activated admin notice
 *
 * @since    5.2.0
 */
function nochexapi_install_wc_notice(){
	?>
	<div class="error">
		<p><?php _e( 'Nochex for WooCommerce is enabled but not effective. It requires WooCommerce in order to work.', 'nochexapi' ); ?></p>
	</div>
	<?php
}

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    5.2.0
 */
function run_nochexapi() {

	$plugin = new nochexapi();
	$plugin->run();

}

/**
 * Check if WooCommerce is activated
 *
 * @since    5.2.0
 */
function run_nochexapi_init(){
	if ( function_exists( 'WC' ) ) {
		/**
         * The core plugin class that is used to define internationalization,
         * admin-specific hooks, and public-facing site hooks.
         */
        require plugin_dir_path( __FILE__ ) . 'includes/class-nochexapi.php';
		run_nochexapi();
	}
	else{
		add_action( 'admin_notices', 'nochexapi_install_wc_notice' );
	}
}
add_action('plugins_loaded','run_nochexapi_init');
