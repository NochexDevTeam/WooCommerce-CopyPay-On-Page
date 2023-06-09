<?php

use Nochexapi\WC_Nochexapi_Constants AS Nochexapi_CONSTANTS;

class Nochexapi {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    5.2.0
	 * @access   protected
	 * @var      Nochexapi_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    5.2.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    5.2.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    5.2.0
	 */
	public function __construct() {
        $this->version     = Nochexapi_CONSTANTS::VERSION;
		
		$this->plugin_name = 'nochexapi';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Nochexapi_Loader. Orchestrates the hooks of the plugin.
	 * - Nochexapi_i18n. Defines internationalization functionality.
	 * - Nochexapi_Admin. Defines all hooks for the admin area.
	 * - Nochexapi_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    5.2.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-nochexapi-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-nochexapi-i18n.php';

		/**
		 * The class payment gateway totalprocessing
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-nochexapi-old-version-helper.php';

		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-nochexapi-gateway.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-nochexapi-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-nochexapi-public.php';

		$this->loader = new Nochexapi_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Nochexapi_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    5.2.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Nochexapi_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'nochexapi' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    5.2.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Nochexapi_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

	} 

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    5.2.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Nochexapi_Public( $this->get_plugin_name(), $this->get_version() );
        $plugin_public->tpcp_pci_frame_initv2();

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

        // add cronjob action for dupe check
		$this->loader->add_action( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'dupe_payment_validation', $plugin_public, 'tp_payment_dupe_check' );
		$this->loader->add_action( 'init', $plugin_public, 'tp_payment_dupe_check_cronstarter_activation' );
		$this->loader->add_filter( 'cron_schedules', $plugin_public, 'tp_payment_dupe_check_cron_schedules_10min' );

		$this->loader->add_filter( 'woocommerce_payment_gateways', $plugin_public, 'add_new_woocommerce_payment_gateways' );
        
        $this->loader->add_filter( 'sgo_js_minify_exclude', $plugin_public, 'exclude_from_siteground_script_minification' );

        $NochexapiObj      = new WC_Payment_Gateway_Nochexapi();
        $NochexapiObj->run( Nochexapi_CONSTANTS::getPluginRootPath() );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    5.2.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     5.2.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     5.2.0
	 * @return    Nochexapi_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     5.2.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
