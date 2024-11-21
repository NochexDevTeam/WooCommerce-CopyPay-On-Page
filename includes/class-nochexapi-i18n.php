<?php

class Nochexapi_i18n {

 
	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    5.2.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'totalprocessing-card-payments-and-gateway-woocommerce',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}
}
