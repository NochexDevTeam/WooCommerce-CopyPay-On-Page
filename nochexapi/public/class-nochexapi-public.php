<?php
 

use Nochexapi\WC_Nochexapi_Constants AS NOCHEXAPI;
use WC_Payment_Gateway_Nochexapi AS NochexAPI_Cards;

class Nochexapi_Public {

	/**
	 *
	 * @since    5.2.0
	 * @access   private
	 * @var      string    $plugin_name
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    5.2.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    5.2.0
	 * @param      string    $plugin_name       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    5.2.0
	 */
	public function enqueue_styles() {

		/**
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Totalprocessing_Card_Payments_And_Gateway_Woocommerce_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Totalprocessing_Card_Payments_And_Gateway_Woocommerce_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( 
            $this->plugin_name, 
            plugin_dir_url( __FILE__ ) . 'css/nochexapi-public.css', 
            array(), 
            $this->version, 
            'all' 
        );

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    5.2.0
	 */
	public function enqueue_scripts() {

		/**
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Totalprocessing_Card_Payments_And_Gateway_Woocommerce_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Totalprocessing_Card_Payments_And_Gateway_Woocommerce_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, 
            plugin_dir_url( __FILE__ ) . 'js/nochexapi-public.js', 
            array( 'jquery' ), 
            $this->version, 
            false 
        );

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    5.2.0
	 */
	public function add_new_woocommerce_payment_gateways( $gateways ) {

		$gateways[] = 'WC_Payment_Gateway_Nochexapi';
	    return $gateways;

	}
    
    public function tpcp_pci_frame_initv2() {
        $this->tpcp_pci_frame_add_templatev2(
            'pci-frame-templatev3.php',
            esc_html__( 'PCI iFramev2', 'woocommerce-gateway-totalprocessing-cards-v3' )
        );
    
        add_filter('theme_page_templates', function ( array $templates ) {
            return array_merge( $templates, $this->tpcp_pci_frame_get_templatesv2() );
        });

        add_filter('template_include', function ( $template ) {
            if ( is_singular() ) {
                $assigned_template = get_post_meta( get_the_ID(), '_wp_page_template', true );
                    if ( $this->tpcp_pci_frame_get_templatev2( $assigned_template ) ) {
                        if ( file_exists( $assigned_template ) ) {
                            return $assigned_template;
                        }
                        $file = wp_normalize_path( plugin_dir_path( dirname( __FILE__ ) ) . '/templates/' . $assigned_template );
                        if ( file_exists( $file ) ) {
                            return $file;
                        }
                    }
                }
            return $template;
        });
    }

    public function tpcp_pci_frame_get_templatesv2() {
        return (array) apply_filters( 'tpcp_pci_frame_templatesv3', array() );
    }

    public function tpcp_pci_frame_get_templatev2( $file ) {
        $templates = $this->tpcp_pci_frame_get_templatesv2();
        return isset( $templates[ $file ] ) ? $templates[ $file ] : null;
    }

    public function tpcp_pci_frame_add_templatev2( $file, $label ) {
        add_filter(
            'tpcp_pci_frame_templatesv3',
            function ( array $templates ) use ( $file, $label ) {
                $templates[ $file ] = $label;
                return $templates;
            }
        );
    }

    public function tp_payment_dupe_check(){
        global $wpdb;
        $rootPath                       = NOCHEXAPI::getPluginRootPath() . '/log/cronjobcheck.txt';
        $successCodes                   = ['000.000.000', '000.100.110'];
        $logText                        = '';
        $NochexAPI_Cards               = new NochexAPI_Cards();
        $isDupeCheckSystemEnabled       = $NochexAPI_Cards->getDupeCheckSystemStatus();
        $logText                       .= 'isDupeCheckSystemEnabled: ' . $isDupeCheckSystemEnabled . "\n";
        if( $isDupeCheckSystemEnabled ){
            $logText                   .= "Dupe System is enabled\n";

            $wpdb->flush();
            $sqlQuery                   = "SELECT * FROM " . $wpdb->prefix . NOCHEXAPI::GLOBAL_PREFIX . "tnxtbl  
                                               WHERE status = 0 
                                                   AND checkout_creation > (NOW() - INTERVAL 30 MINUTE)";
            $orderTxns                  = $wpdb->get_results( $wpdb->prepare( $sqlQuery ) );
            $wpdb->flush();

            foreach( $orderTxns AS $singleorderTxn ){
                $order_id               = $singleorderTxn->post_id;
                $uuid                   = $singleorderTxn->uuid;
                $uuid_code              = $singleorderTxn->uuid_code;
                if( in_array( $uuid_code, $successCodes ) ){
                    continue;
                }
                $logText               .= "Checking orderid:" . $order_id . ", txno:" . $uuid . ", uuid_code:" . $uuid_code . "\n";
                $checkData              = $NochexAPI_Cards->VerifyPaymentStatusForOrderID( $order_id );
                $paymentData            = $checkData['payments'][0] ?? [];
                $checkedResultCode      = (string)$paymentData['result']['code'] ?? '';

                if( in_array( $checkedResultCode, $successCodes ) ){
                    $order              = wc_get_order( $order_id );
                    $transaction_id     = (string)$paymentData['id'];
                    $resultcode         = (string)$paymentData['result']['code'];
                    $description        = (string)$paymentData['result']['description'];
                    $paymentType        = (string)$paymentData['paymentType'];
                    $order->payment_complete( $transaction_id );
                    wc_reduce_stock_levels($order_id);
                    $order->add_order_note( 'Your order is paid! Thank you!', true );
                    $order->add_order_note( $resultcode, false );
                    $order->add_order_note( $description, false );
                    $order->add_meta_data( 'paymentType', $paymentType);
                    $wpdb->update( 
                        $wpdb->prefix . NOCHEXAPI::GLOBAL_PREFIX . 'tnxtbl',
                        [ 'status' => 2, 'uuid' => $transaction_id, 'uuid_code' => $resultcode ],
                        [ 'post_id' => $order_id ]
                    );
                    //WC()->cart->empty_cart();
                    $logText           .= "Successful orderid:" . $order_id . "\n";
                }
            }
        }
        @file_put_contents( $rootPath, $logText );
    }

    public function tp_payment_dupe_check_cronstarter_activation(){
        if( !wp_next_scheduled( NOCHEXAPI::GLOBAL_PREFIX . 'dupe_payment_validation' ) ) {  
	        wp_schedule_event( time(), 'every10minutes', NOCHEXAPI::GLOBAL_PREFIX . 'dupe_payment_validation' );  
	    }
    }

    public function tp_payment_dupe_check_cron_schedules_10min( $schedules ){
        $schedules['every10minutes'] = array(
	        'interval' => 10*60,
	        'display'  => __( 'Once Every 10 Minutes' )
        );
        return $schedules;
    }

    public function exclude_from_siteground_script_minification( $exclude_list ){
        $exclude_list[] = NOCHEXAPI::GLOBAL_PREFIX . 'tp_cards';
        return $exclude_list;
    }
}
