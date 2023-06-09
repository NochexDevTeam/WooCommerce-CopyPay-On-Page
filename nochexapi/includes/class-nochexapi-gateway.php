<?php

use Nochexapi\WC_Nochexapi_Constants AS Nochexapi_CONSTANTS;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/nochexapi-helper-trait.php';

class WC_Payment_Gateway_Nochexapi extends WC_Payment_Gateway {
    use NochexapiHelperTrait;

    public function __construct() {

        $this->id                                = Nochexapi_CONSTANTS::GATEWAY_ID;
        $this->icon                              = '';
        $this->has_fields                        = true;
        $this->method_title                      = Nochexapi_CONSTANTS::GATEWAY_TITLE;
        $this->method_description                = Nochexapi_CONSTANTS::GATEWAY_DESCRIPTION;
        $this->init_form_fields();
        $this->init_settings();
        $this->title                             = $this->get_option( 'title' );
        $this->description                       = $this->get_option( 'description' );
        $this->enabled                           = $this->get_option( 'enabled' );
        $this->supports                          = $this->checkGatewaySupports( $this->get_option( 'createRegistration' ) );
        $this->jsLogging                         = ($this->get_option( 'jsLogging' ) === 'yes' ? true : false);
        $this->serversidedebug                   = ($this->get_option( 'serversidedebug' ) === 'yes' ? true : false);
        $this->logLevels                         = $this->get_option( 'logLevels' );
        $this->platformBase                      = $this->get_option( 'platformBase' );
        $this->entityId_test                     = "8ac7a4ca7843f17d017844faa85f0829"; //$this->get_option( 'entityId_test' );
        $this->accessToken_test                  = "OGFjN2E0Y2E3ODQzZjE3ZDAxNzg0NGY4MTFjNjA4MjR8V2hFMlB4WHdFcA"; //$this->get_option( 'accessToken_test' );
        $this->entityId                          = $this->get_option( 'entityId' );
        $this->accessToken                       = $this->get_option( 'accessToken' );
        $this->paymentType                       = "DB"; //$this->get_option( 'paymentType' );
        $this->createRegistration                = true; //($this->get_option( 'createRegistration' ) === 'yes' ? true : false);
        //$this->slickOneClick                     = ($this->get_option( 'slickOneClick' ) === 'yes' ? true : false);
        $this->slickOneClick                     = false;
        $this->includeCartData                   = true; //($this->get_option( 'includeCartData' ) === 'yes' ? true : false);
        $this->dupePaymentCheck                  = ($this->get_option( 'dupePaymentCheck' ) === 'yes' ? true : false);
        $this->end2end                           = get_option( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'gateway_cardsv2_e2e' );
        $this->iframePostId                      = (int)get_option( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'gateway_cardsv2_iframe' );
        $this->iframeForceSsl                    = false;
        $this->iframeJsRenderer                  = true;
        $this->secureCodeLogos                   = true;
        //$this->labelHex                          = $this->get_option( 'labelHex' );
        $this->externalWpFrame                   = false;
        $this->threeDv2                          = true; //($this->get_option( 'threeDv2' ) === 'yes' ? true : false);
        $this->threeDv2Params                    = ["ReqAuthMethod"]; //$this->get_option( 'threeDv2Params' );
        $this->transactionType3d                 = '01'; //$this->get_option( 'transactionType3d' );
        $this->useModalPayFrames                 = ($this->get_option( 'useModalPayFrames' ) === 'yes' ? true : false);
        $this->externalFrameUrl                  = Nochexapi_CONSTANTS::getExternalFrameURL();
        $this->legacyEndpoints                   = ($this->get_option( 'legacyEndpoints' ) === 'yes' ? true : false);
        $this->checkoutOrderCleanup              = true;
        //custom css
        $this->paymentBrands                     = ['VISA','MASTER']; //$this->get_option( 'paymentBrands' );
        //$this->paymentLogoCss                    = $this->get_option( 'paymentLogoCss' );
        //$this->customCss                         = $this->get_option( 'customCss' );
        $this->autoFocusFrameCcNo                = ($this->get_option( 'autoFocusFrameCcNo' ) === 'yes' ? true : false);
        $this->framePrimaryColor                 = $this->get_option( 'framePrimaryColor' );
        $this->frameAccentColor                  = $this->get_option( 'frameAccentColor' );
        $this->framewpwlControlBackground        = $this->get_option( 'framewpwlControlBackground' );
        $this->framewpwlControlFontColor         = $this->get_option( 'framewpwlControlFontColor' );
        $this->framewpwlControlBorderRadius      = $this->get_option( 'framewpwlControlBorderRadius' );
        $this->framewpwlControlBorderColor       = $this->get_option( 'framewpwlControlBorderColor' );
        $this->framewpwlControlBorderWidth       = $this->get_option( 'framewpwlControlBorderWidth' );
        $this->framewpwlControlMarginRight       = $this->get_option( 'framewpwlControlMarginRight' );
        //logFile location
        $this->logPath                           = Nochexapi_CONSTANTS::getFilePaths( 'logs', 3, true );
        // Initialise settings
        //Load funcs
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_api_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'gateway_cardsv2_e2e', array( $this, 'tpcp_gateway_cardsv2_e2e' ) );
        add_action( 'woocommerce_api_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'gateway_cardsv2_cbk', array( $this, 'tpcp_gateway_cardsv2_cbk' ) );
    }
    
    /*
     * Function to preparing of setting fields for different tabs
     */
    public function settings_fields( $merge = false ) {
        $settings                  = include dirname( __FILE__ ) . '/payment-gateway-setting-fields.php';
        
        if( $merge === true ){
            $retArr = [];
            foreach( $settings as $subSettings ){
                if( count( $subSettings ) > 0 ){
                    if( isset( $subSettings['fields'] ) ){
                        if( count( $subSettings['fields'] ) > 0 ){
                            $retArr = array_merge( $retArr, $subSettings['fields'] );
                        }
                    }
                }
            }
            return $retArr;
        }
        return $settings;
    }

    /*
     * Function to initialization of setting fields for different tabs
     */
    public function init_form_fields(){
        $this->form_fields = $this->settings_fields(true);
    }
    
    /*
     * Function to initialization of admin option fields
     */
    public function admin_options() {
        require_once Nochexapi_CONSTANTS::getPluginRootPath() . 'admin/partials/admin-options.php';
    }
    
    /*
     * Function to initialization of gateway icons
     */
    public function get_icon() {
        $icons_str = '';
		
        $icons_str .= '<img src="' . plugin_dir_url( __DIR__ ).'assets/img/nochexapilogo.png" class="nochexapi-payment-gateways-icon-img" alt="Nochexapi" style="width: 270px;max-width:inherit;max-height:inherit;height: auto;float:unset!important;padding-top: 0px;">'."\n";
		
        /*foreach($this->paymentBrands as $brand){
            if($this->secureCodeLogos === true){
                $icons_str .= '<img src="' . plugin_dir_url( __DIR__ ).'assets/img/'. $brand. '-3d.svg' . '" class="tp-payment-gateways-icon-img" alt="'.$brand.'">'."\n";
            } else {
                $icons_str .= '<img src="' . plugin_dir_url( __DIR__ ).'assets/img/'. $brand. '.svg' . '" class="tp-payment-gateways-icon-img" alt="'.$brand.'">'."\n";
            }
        }*/
		
				
        return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
    }
    /*
     * Function to initialization of setting fields for different tabs
     */
    public function get_threed_version_two_data() {
        
        global $wpdb;
        
        $threeDSecurefieldData = [];
        $params = [];
        $userId = false;
        $userRegDt = false;
        $dateNow = date_create(date('Y-m-d H:i:s'));
        
        if(count($this->threeDv2Params) === 0){
            return $params;
        }
        
        $loggedIn = is_user_logged_in();
        if($loggedIn){
            $userId = get_current_user_id();
            $userRegDt = wp_get_current_user()->user_registered;
        }
        
        if(in_array('ReqAuthMethod', $this->threeDv2Params)){
            if($loggedIn){
                $params["ReqAuthMethod"] = "02";
            } else {
                $params["ReqAuthMethod"] = "01";
            }
        }
        
        if(in_array('ReqAuthTimestamp', $this->threeDv2Params) && $userId){
            $userMeta = $wpdb->get_col(
                $wpdb->prepare( 
                    "SELECT meta_value FROM " . $wpdb->prefix . "usermeta WHERE meta_key = 'session_tokens' AND user_id = %d",
                    $userId
                )
            );
            if(is_array($userMeta)){
                $userArr = maybe_unserialize( $userMeta[0] );
                if(is_array($userArr)){
                    if(count($userArr) > 0){
                        foreach($userArr as $k => $v){
                            if(is_array($v)){
                                if(isset($v['login'])){
                                    if(is_integer($v['login'])){
                                        $params["ReqAuthTimestamp"] = date("YmdHi",$v['login']);
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        if(in_array('AccountAgeIndicator', $this->threeDv2Params)){
            if(!$loggedIn){
                $params["AccountAgeIndicator"] = "01";
            } else {
                $date1 = date_create($userRegDt);
                $diff = date_diff($date1,$dateNow);
                $dys = $diff->format("%a");
                $dys = (int)$dys;
                if($dys <= 0){
                    $params["AccountAgeIndicator"] = "02";
                } else if($dys >= 1 && $dys < 30) {
                    $params["AccountAgeIndicator"] = "03";
                } else if($dys >= 30 && $dys < 60) {
                    $params["AccountAgeIndicator"] = "04";
                } else if($dys >= 60) {
                    $params["AccountAgeIndicator"] = "05";
                }
                if(in_array('AccountDate', $this->threeDv2Params)){
                    $params["AccountDate"] = $date1->format("Ymd");
                }
                if(in_array('AccountPurchaseCount', $this->threeDv2Params)){
                    /*$dtFrom = '>' . date('Y-m-d', strtotime('-6 month'));
                    $paidOrdersArgs = array(
                        'type' => 'shop_order',
                        'status' => array('wc-processing', 'wc-completed'),
                        'limit' => 5,
                        'customer_id' => $userId,
                        'date_paid' => $dtFrom,
                        'return' => 'ids',
                    );*/
                    $orderCount = $this->getClientOrdersCountForLast6Months( $userId );// this is needed to lower risk while payment
                    $params["AccountPurchaseCount"] = intval($orderCount);
                    /*if(is_array($orderArray)){
                        $params["AccountPurchaseCount"] = count($orderArray);
                    }*/
                }
            }
        }
        
        if(in_array('TransactionType', $this->threeDv2Params)){
            $params["TransactionType"] = $this->transactionType3d;
        }
        
        foreach($params as $k => $v){
            $threeDSecurefieldData["customParameters[".$k."]"] = (string)$v;
        }
        
        return $threeDSecurefieldData;
    }
    
    public function check_plugin(){
        //check for checkout page only.
        if($this->enabled === 'yes'){
            return true;
        }
        return false;
    }

    public function derivePlatformBase(){
        if($this->legacyEndpoints === false){
            if($this->platformBase === 'oppwa.com'){
                return 'eu-prod.oppwa.com';
            } else {
                return 'eu-test.oppwa.com';
            }
        }
        return $this->platformBase;
    }
    
  public function run(){
       $plugin_basename = Nochexapi_CONSTANTS::getPluginBaseName();
        //run iFrame generate
		
        add_action( 'admin_notices', array( $this, 'tp_display_global_errors' ) );
        add_action( 'wp_ajax_'. Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'generateFrame', array( $this, 'generatePciFramePage' ) );
        add_action( 'wp_ajax_nopriv_'. Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'generateFrame', array( $this, 'generatePciFramePage' ) );

        add_action( 'wp_ajax_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'sync_saved_cards_tokens', array( $this, 'ajax_sync_saved_cards_tokens' ) );

        add_action( 'wp_ajax_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'forward_debugdata_to_tp_support', array( $this, 'ajax_forward_debugdata_to_tp_support' ) );

        //check plugin
        if($this->check_plugin()){
            add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts_cards' ), PHP_INT_MAX );
            add_action( 'wp_ajax_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'feLogger', array( $this, 'feLogger' ) );
            add_action( 'wp_ajax_nopriv_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'feLogger', array( $this, 'feLogger' ) );
            add_action( 'wp_ajax_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'requestRgCheckoutId', array( $this, 'genCheckoutId' ) );
            add_action( 'wp_ajax_nopriv_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'requestRgCheckoutId', array( $this, 'genCheckoutId' ) );
            add_action( 'wp_ajax_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'requestOrderCheckoutId', array( $this, 'genCheckoutIdOrder' ) );
            add_action( 'wp_ajax_nopriv_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'requestOrderCheckoutId', array( $this, 'genCheckoutIdOrder' ) );
            add_action( 'wp_ajax_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'tpOrderPay', array( $this, 'tpOrderPay' ) );
            add_action( 'wp_ajax_nopriv_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'tpOrderPay', array( $this, 'tpOrderPay' ) );
            add_action( 'wp_ajax_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'parseRgCheckout', array( $this, 'parseRgCheckout' ) );
            add_action( 'wp_ajax_nopriv_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'parseRgCheckout', array( $this, 'parseRgCheckout' ) );
            add_action( 'wp_ajax_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'validate_nochexapi_cardsv2_checkout', array( $this, 'lookupTransaction' ) );
            add_action( 'wp_ajax_nopriv_' . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'validate_nochexapi_cardsv2_checkout', array( $this, 'lookupTransaction' ) );
            add_action( 'init', array( $this, 'checkForVerifyTx' ) );
        }
        //hide iframe post ID
        if((int)$this->iframePostId > 0){
            add_action('pre_get_posts', array( $this, 'excludeFramePostId' ) );
            add_action('wp_head', array( $this, 'payment_style_inline_global' ) , 100);
        }
        add_action( 'wp', array( $this, '_init' ) );
        //filter hooks
        add_filter( 'plugin_action_links_' . $plugin_basename, array( $this, 'plugin_action_links' ) );
        /*if(is_user_logged_in()){
            if(current_user_can('administrator')){
                add_action( 'admin_menu', array( $this, 'tp_cards_cbk_menu' ) );
            }
        }*/
        add_action( 'woocommerce_payment_token_deleted', array( $this, 'tp_card_deregistration' ) , 10, 2 );
        add_filter( 'woocommerce_account_payment_methods_columns', array( $this, 'tpcards_account_payment_methods_columns' ) , 10, 1 );
        add_filter( 'woocommerce_payment_methods_list_item', array( $this, 'tpcards_payment_methods_list_item' ) , 10, 2 );
        add_action( 'woocommerce_account_payment_methods_column_field1', array( $this, 'tpcards_payment_methods_column_field1' ) );
        add_action( 'woocommerce_account_payment_methods_column_field2', array( $this, 'tpcards_payment_methods_column_field2' ) );
        add_action( 'woocommerce_after_checkout_form', array( $this, 'tpcards_formhtml' ), 10 );
    }
    
    /*public function tp_cards_cbk_menu(){
        add_menu_page( 'Test CBK Page', 'Test CBK', 'manage_options', 'wc_cardsv2_cbks', array( $this, 'tp_cards_cbk_page' ) );
    }
    
    public function tp_cards_cbk_page(){
        echo "<h1>CBK Report</h1>";
    }*/
    
    public function _init(){
        $this->generatePciFramePageOnMissing();
        $this->updateRequiredOldSettingsData();
        //$this->sync_saved_cards_tokens();
    }

    public function tpcards_formhtml(){
        echo '<div id="tp_alt_cnp_container"></div>';
    }
    
    public function tpcards_payment_methods_column_field1( $method ){
        if ( ! empty( $method['token_meta']['paymentBrand'] ) ) {
            echo '<img src="' . plugin_dir_url( __DIR__ ).'assets/img/'. $method['token_meta']['paymentBrand'] . '.svg"' . 'alt="'.$method['token_meta']['paymentBrand'].'">';
        }
    }
    
    public function tpcards_payment_methods_column_field2( $method ){
        if ( ! empty( $method['token_meta']['holder'] ) ) {
            echo ucwords($method['token_meta']['holder']);
        }
    }
    
    public function tpcards_account_payment_methods_columns( $array ) {
        $newArray = [
            'field1' => __( '&nbsp;', 'woocommerce' ),
            'field2' => __( 'Cardholder', 'woocommerce' ),
        ];
        foreach($array as $k => $v){
            $newArray[$k] = $v;
        }
        return $newArray; 
    }
    
    public function tpcards_payment_methods_list_item( $list_type_key, $payment_token ) { 
        $list_type_key['token_meta'] = [];
        $tokenMeta = $payment_token->get_meta_data();
        foreach($tokenMeta as $meta){
            if(in_array($meta->key,$this->arrStaticData('cardKeys'))){
                $list_type_key['token_meta'][$meta->key] = $meta->value;
            }
        }
        return $list_type_key;
    }
    
    public function tp_card_deregistration( $token_id, $token ){
        if($token){
            if($token->get_gateway_id() === $this->id){
                $platformBase = 'oppwa.com';
                $entityId = $this->entityId;
                $accessToken = $this->accessToken;
                if($token->meta_exists('test')){
                    $platformBase = 'test.oppwa.com';
                    $entityId = $this->entityId_test;
                    $accessToken = $this->accessToken_test;
                }
                $headers = [
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Authorization' => 'Bearer ' . $accessToken
                ];
                $url = 'https://'.$this->derivePlatformBase().'/v1/registrations/'.$token->get_token().'?entityId='.$entityId;
                $response = $this->prepareRemoteRequest($url,$headers,false,'DELETE');
                if($response){
                    if(is_object($response)){
                        if(isset($response->result->code)){
                            if((string)$response->result->code === '000.100.110' || (string)$response->result->code === '000.000.000'){
                                return true;
                            }
                        }
                    }
                }
            }
        }
        return false;
    }
    
    public function tpcards_remove_method( $available_gateways ){
        $gateway_id = $this->id;
        if(is_checkout()) {
            if(isset($available_gateways[$gateway_id])){
                unset($available_gateways[$gateway_id]);
            }
        }
        return $available_gateways;
    }
    
    public function excludeFramePostId($query){
        $iFramePostId = (int)$this->iframePostId;
        if(!is_admin()){
            if( $query->is_home() || $query->is_feed() ||  $query->is_search() || $query->is_archive() ) {
                $query->set('post__not_in', array($iFramePostId));
            }
        }
        return $query;
    }
    
    public function payment_style_inline_global() {
        echo '<style type="text/css">li.page-item-'.$this->iframePostId.' {display:none!important;}</style>';
    }
    
    public function plugin_action_links( $links ) {
        $plugin_links = array(
            '<a href="admin.php?page=wc-settings&tab=checkout&section='.$this->id.'">' . 'Settings' . '</a>',
        );
        return array_merge( $plugin_links, $links );
    }
    
    public function checkForVerifyTx(){
        if(isset($_REQUEST[Nochexapi_CONSTANTS::GLOBAL_PREFIX.'tpLookup']) && isset($_GET['resourcePath'])){

            if ( is_ajax() ) {
                return;
            }
            
            $url = wc_get_checkout_url();
            
            $paymentUrl = WC()->session->get( 'tp_paymentUrl' , false );
            if( $paymentUrl !== false ) {
                if(is_array($paymentUrl)){
                    if(isset($paymentUrl['payment_url'])){
                        $url = $paymentUrl['payment_url'];
                    }
                }
            }
            
            $id = (string)$_REQUEST['id'];
            $resourcePath = (string)$_REQUEST['resourcePath'];
            
            $this->writeLog('----- checkForVerifyTx -----','debug');
            $this->writeLog($_GET,'debug');

            if(isset($_REQUEST['platformBase'])){
                if(in_array((string)$_REQUEST['platformBase'], ['test.oppwa.com','oppwa.com'] , true)){
                    $platformBase = (string)$_REQUEST['platformBase'];
                } else {
                    $platformBase = $this->platformBase;
                }
            } else if(strpos($id, '.uat')) {
                $platformBase = 'test.oppwa.com';
            } else if(strpos($id, '.prod')) {
                $platformBase = 'oppwa.com';
            } else {
                $platformBase = $this->platformBase;
            }

            $responseObject = $this->requestTransactionStatus($platformBase,$id,$resourcePath);
            
            if(is_object($responseObject)){
                if(isset($responseObject->result)){
                    if(isset($responseObject->result->code)){
                        if((string)$responseObject->result->code === '100.150.203'){
                            if(isset($responseObject->registrationId)){
                                $id = (string)$responseObject->registrationId;
                                $responseObject = $this->requestTransactionStatus($platformBase,$id,false);
                            }
                        }
                    }
                }
            }
            
            $responseResult = $this->parseResponseData($responseObject);
            if(isset($responseResult['result'])){
                if($responseResult['result'] === 'success'){
                    $url = $responseResult['redirect'];
                }
            }
            wp_redirect( $url );
            exit;
        }
    }
    
    public function generatePciFramePage(){
        $iFramePostId = (int)$this->iframePostId;
        if( $iFramePostId > 0 ){
            $iFramePostData = get_post( $iFramePostId , 'ARRAY_A', 'raw' );
            if($iFramePostData === NULL || 'publish' !== get_post_status( $iFramePostData )){
                $iFramePostId = $this->createPciFramePage();
            }
        } else {
            $iFramePostId = $this->createPciFramePage();
        }
        if($iFramePostId){
            add_option( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'gateway_cardsv2_iframe_url', get_the_guid( (int)get_option( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'gateway_cardsv2_iframe' ) ) );
            wp_send_json_success( ["valid"=>true,"containerId"=>"statusTablesContainer","html"=>$this->generateStatusArray(true)] );
        }
        return false;
    }
    
    public function checkPageShouldRun(){
        global $wp;
        if(is_checkout() === true && empty( $wp->query_vars['order-received'] )){
            return true;
        }
        if(is_wc_endpoint_url( 'order-pay' )){
            return true;
        }
        if(is_add_payment_method_page()){
            return true;    
        }
        return false;
    }
    
    public function isSlickOneClick(){
        if($this->slickOneClick === true && $this->createRegistration === true && $this->useModalPayFrames === true){
            return true;
        }
        return false;
    }

    public function payment_scripts_cards() {
        if($this->checkPageShouldRun() === true){
            
            $tp_pluginVer = Nochexapi_CONSTANTS::VERSION;
            $prefix       = Nochexapi_CONSTANTS::GLOBAL_PREFIX;
            if($this->useModalPayFrames === true){
                if(is_add_payment_method_page()){
                    wp_register_script( $prefix . 'tp_cards', plugin_dir_url( dirname( __FILE__ ) ).'assets/js/nochexapi-cardsv2_registration.js', ['jquery','wp-util'], $tp_pluginVer );
                } else if(is_wc_endpoint_url( 'order-pay' )){
                    wp_register_script( $prefix . 'tp_cards', plugin_dir_url( dirname( __FILE__ ) ).'assets/js/nochexapi-cardsv2_orderpay.js', ['jquery','wp-util'], $tp_pluginVer );
                } else {
                    if($this->jsLogging === true){
                        wp_register_script( $prefix . 'tp_cards', plugin_dir_url( dirname( __FILE__ ) ).'assets/js/nochexapi-cardsv2_logging.js', ['jquery','wp-util'], $tp_pluginVer );
                    } else {
                        wp_register_script( $prefix . 'tp_cards', plugin_dir_url( dirname( __FILE__ ) ).'assets/js/nochexapi-cardsv2_logging_off.js', ['jquery','wp-util'], $tp_pluginVer );
                    }
                }
            } else {
                wp_register_script( $prefix . 'tp_cards', plugin_dir_url( dirname( __FILE__ ) ).'assets/js/nochexapi-cardsv2x.js' , ['jquery','wp-util'], $tp_pluginVer );
            }
            wp_register_script( $prefix . 'tp_fetch', 'https://cdn.jsdelivr.net/npm/whatwg-fetch@3.4.0/dist/fetch.umd.min.js' , ['jquery','wp-util'] , true );
            wp_register_script( $prefix . 'tp_swal', 'https://cdn.jsdelivr.net/npm/sweetalert2@10/dist/sweetalert2.all.min.js' , ['jquery','wp-util'] , true );
            wp_register_script( $prefix . 'tp_swal_poly1', 'https://cdnjs.cloudflare.com/ajax/libs/babel-polyfill/7.12.1/polyfill.min.js' , [] , true );
            wp_register_script( $prefix . 'tp_swal_poly2', 'https://cdn.jsdelivr.net/npm/promise-polyfill@8.1.3/dist/polyfill.js' , [] , true );

            wp_localize_script( $prefix . 'tp_cards', $prefix . 'CardVars', [
                "subTotalAmount" => (string)WC()->cart->get_total(null),
                "checkoutUrl" => urlencode(wc_get_checkout_url()),
                "pluginPrefix" => $prefix,
                "pluginId" => Nochexapi_CONSTANTS::GATEWAY_ID,
                "jsLogging" => $this->jsLogging,
                "pluginVer" => $tp_pluginVer,
                "adminUrl" => get_admin_url().'admin-ajax.php',
                "platformBase" => $this->derivePlatformBase(),
                "brands" => implode(' ',$this->paymentBrands),
                "assetsDir" => plugin_dir_url( dirname( __FILE__ ) ).'assets',
                "frameUrlEncoded" => urlencode( get_the_guid( (int)get_option( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'gateway_cardsv2_iframe' ) ) ),
                "createRegistration" => $this->createRegistration,
                "loggedIn" => is_user_logged_in(),
                "tpModal" => $this->useModalPayFrames,
                "shopperUrl" => add_query_arg( array( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'tpLookup' => '1' , 'platformBase' => $this->derivePlatformBase() ) , wc_get_checkout_url() ),
                "slickOneClick" => $this->isSlickOneClick(),
                "autoFocusFrameCcNo" => $this->autoFocusFrameCcNo,
                "feNonce" => wp_create_nonce('tp_feLogger'),
                "frameCss" => [
                    "framePrimaryColor" => $this->framePrimaryColor,
                    "frameAccentColor" => $this->frameAccentColor,
                    "framewpwlControlBackground" => $this->framewpwlControlBackground ?? 'transparent',
                    "framewpwlControlFontColor" => $this->framewpwlControlFontColor ?? 'inherit',
                    "framewpwlControlBorderRadius" => $this->framewpwlControlBorderRadius ?? 0,
                    "framewpwlControlBorderColor" => $this->framewpwlControlBorderColor ?? '#ffffff',
                    "framewpwlControlBorderWidth" => $this->framewpwlControlBorderWidth ?? 0,
                    "framewpwlControlMarginRight" => $this->framewpwlControlMarginRight ?? 12,
                ],
            ]);
            
            wp_enqueue_style( $prefix . 'cards_style', plugin_dir_url( dirname( __FILE__ ) ).'assets/css/cardsv2-style.css?ver=' . $tp_pluginVer , [] , null );

            $inlinecss = '#'.Nochexapi_CONSTANTS::GLOBAL_PREFIX.'iframe_container, #'.Nochexapi_CONSTANTS::GLOBAL_PREFIX.'iframe_container h1  {width: 100%;background-color:'.$this->framePrimaryColor.'!important;color:'.$this->frameAccentColor.'!important;}
                          .payment_method_'.Nochexapi_CONSTANTS::GATEWAY_ID.':before{border-bottom-color: '.$this->framePrimaryColor.'!important;}
                          li.payment_method_'.Nochexapi_CONSTANTS::GATEWAY_ID.' img{height:18px;display:inline;margin-left:0.35rem;padding-top:5px;border-radius:unset;}';
            wp_add_inline_style( $prefix . 'cards_style', $inlinecss );
            
            wp_enqueue_script($prefix . 'tp_fetch');
            wp_enqueue_script($prefix . 'tp_swal_poly2');
            wp_enqueue_script($prefix . 'tp_cards');

            $inlinescript = "function getnochexapiGlobalVariable(){
                return " . $prefix . "CardVars;
            }";
            wp_add_inline_script( $prefix . 'tp_cards', $inlinescript, 'before' );
            
            if($this->useModalPayFrames === true){
                wp_enqueue_script($prefix . 'tp_swal_poly1');
                wp_enqueue_script($prefix . 'tp_swal');
            }
        }
    }
    
    /*
     * This sends payload to generate checkoutid for checkout form payment gateway or create registration to create saved cards
     */
    public function createCheckoutArray($obj=true,$forceAmount=false,$registrationId=false, $additionalParameters = []){
        if($forceAmount !== false){
            $amount = $forceAmount;
        } else {
            $amount = WC()->cart->get_total(null);
        }
        if((float)$amount === 0){ return false; }
        $registrationIds=[];
        $payload = [
            'entityId'=> ($this->platformBase == 'oppwa.com' ? $this->entityId : $this->entityId_test),
            'paymentType'=> $this->paymentType,
            'amount' => number_format($amount, 2, '.', ''),
            'currency'=>get_woocommerce_currency(),
            'standingInstruction.mode'=>'INITIAL',
            'standingInstruction.type'=>'UNSCHEDULED',
            'standingInstruction.source'=>'CIT',
        ];
        if((float)$amount === -0.01){
            if(is_user_logged_in() !== true){
                return false;
            }
            unset($payload['paymentType']);
            unset($payload['amount']);
            unset($payload['currency']);
            $payload['createRegistration'] = 'true';
            $payload['customer.merchantCustomerId'] = get_current_user_id();
        } else {
            if($this->createRegistration === true && is_user_logged_in() === true){
                if($registrationId !== false){
                    $registrationIds = [$registrationId];
                } else {
                    $registrationIds = $this->retrieveUnlimitedTokens( get_current_user_id(), $this->id, $this->platformBase );
                }
                if(count($registrationIds) > 0){
                    foreach($registrationIds as $keyId => $rgId){
                        $payload['registrations['.$keyId.'].id']=$rgId;
                    }
                }
            }
        }

        $remoteUrl = 'https://'.$this->derivePlatformBase().'/v1/checkouts';
        
        $array = [
            'method' => 'POST',
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8','Authorization' => 'Bearer '.($this->platformBase === 'oppwa.com' ? $this->accessToken : $this->accessToken_test)],
            'body' => $payload
        ];
        
        /*if($this->proxyRequests === true){
            $array['headers']['Fwdurl'] = $remoteUrl;
            $remoteUrl = $this->proxyUrl;
        }*/
        
        $response = wp_remote_request( $remoteUrl , $array);
        
        $this->shouldLogResponseHandler($response, [$array, $payload]);
        
        $responseData = json_decode($response['body']);
        
        if($obj === true){
            return $responseData;
        } else if(isset($responseData->id)){
            if(isset($responseData->result->code)){
                if(in_array($responseData->result->code,['000.200.000','000.200.100','000.200.101'])){
                    $checkoutDataArray = [
                        "createRegistration"=>$this->createRegistration,
                        "loggedIn"=>is_user_logged_in(),
                        "registrationIds"=>$registrationIds,
                        "shopperResultUrl"=>urlencode(wc_get_checkout_url()),
                        "checkoutId"=>(string)$responseData->id,
                        "platformBase"=>$this->derivePlatformBase(),
                        "paymentBrands"=>implode(' ',$this->paymentBrands),
                        "autoFocusFrameCcNo"=>$this->autoFocusFrameCcNo,
                        //"labelHex"=>$this->labelHex,
                        "enforceJsLoader"=>$this->iframeJsRenderer,
                        "expiry"=>date("Y-m-d H:i:s", strtotime("+30 minutes"))
                    ];
                    WC()->session->set('tp_checkoutData', $checkoutDataArray);
                    return (string)$responseData->id;
                }
            }
        } else {
            WC()->session->set('tp_checkoutData', null);
        }
        return false;
    }
    
    public function genCheckoutId(){
        $this->writeLog( '----- genCheckoutId -----', 'debug' );
        $responseData = $this->createCheckoutArray(false,-0.01);

        if($responseData){
            wp_send_json_success($responseData);
        } else {
            wp_send_json_error();
        }
    }
    
    /*
     * This function is instantiated when checkout page loads or payment fragment refreshes
     */
    public function genCheckoutIdOrder(){
        $this->writeLog( '----- genCheckoutIdOrder -----', 'debug' );
        $responseData = $this->createCheckoutArray();

        if($responseData){
            if(isset($responseData->id)){
                if(isset($responseData->result->code)){
                    if(in_array($responseData->result->code,['000.200.000','000.200.100','000.200.101'])){
                        wp_send_json_success([
                            'uuid' => $responseData->id,
                            'frameurl' => $this->getTPiFrameURL(),
                        ]);
                    }
                }
            }
        }
        wp_send_json_error();
    }
    
    public function shouldLogResponseHandler($response,$array){
        if(is_array($response)){
            $obj = false;
            $responseData = [];
            if(isset($response['body'])){
                $obj = $this->checkValidJsonDecode($response['body']);
                if($obj){
                    $responseData = $obj;
                } else {
                    $responseData = $response['body'];
                }
            }
            if($obj){
                if(isset($obj->result->code)){
                    $levelData = $this->checkLogLevelViaOppwaResultCode($obj->result->code);
                    if(is_array($levelData)){
                        if(isset($levelData[1])){
                            $this->writeLog('--------shouldLogResponseHandler -------', 'debug');
                            $this->writeLog($array,$levelData[1]);
                            $this->writeLog($responseData,$levelData[1]);
                            return true;
                        }
                    }
                }
            }
            if(isset($response['response']['code'])){
                $http_code = (int)$response['response']['code'];
                switch ($http_code) {
                    case 200:
                        $level = 'debug';
                        break;
                    case 400:
                        $level = 'warning';
                        break;
                    case 401:
                        $level = 'emergency';
                        break;
                    case 403:
                        $level = 'emergency';
                        break;
                    case 404:
                        $level = 'critical';
                        break;
                    default:
                       $level = 'info';
                }
                
                $this->writeLog('--------shouldLogResponseHandler -------', 'debug');
                $this->writeLog($array,$level);
                $this->writeLog($responseData,$level);
            }
        }
        return true;
    }
    
    public function feLogger(){
        $errors = [];
        $postArr = sanitize_post($_POST);
        if(isset($postArr['fe_nonce'])){
            if(wp_verify_nonce($postArr['fe_nonce'],'tp_feLogger')){
                $array = wp_strip_all_tags($postArr['msg']);
                $level = 'error';
                if(isset($postArr['level'])){
                    $level = $postArr['level'];
                }
                $this->writeLog('------------feLogger---------','debug');
                $this->writeLog($array,'error');
                wp_send_json_success($postArr);
            } else {
                $errors[] = 'nonce validation failed';
            }
        } else {
            $errors[] = 'fe_nonce nonce not found';
        }
        wp_send_json_error($errors);
    }
    
    public function tpOrderPay(){
        global $woocommerce;
        $payment_method = wc_get_post_data_by_key('payment_method',false);
        $terms_field = wc_get_post_data_by_key('terms-field',false);
        $terms = wc_get_post_data_by_key('terms',false);
        $tp_payment_nonce = wc_get_post_data_by_key('tp_payment_nonce',false);
        $woocommerce_pay = wc_get_post_data_by_key('woocommerce_pay',false);
        $wp_http_referer = wc_get_post_data_by_key('_wp_http_referer',false);
        $registrationId = wc_get_post_data_by_key('registrationId',false);
        if((string)$registrationId === ''){
            $registrationId = false;
        }
        $postArr = [
            'payment_method' => $payment_method,
            'terms_field' => $terms_field,
            'terms' => $terms,
            'tp_payment_nonce' => $tp_payment_nonce,
            'woocommerce_pay' => $woocommerce_pay,
            'wp_http_referer' => $wp_http_referer
        ];
        $validate = $this->validatePayForOrder($postArr);
        wc_clear_notices();
        if($validate['result'] === true && isset($validate['order_id']) === true){
            //load order
            $order = wc_get_order( $validate['order_id'] );
            //$order->get_checkout_payment_url( $on_checkout = false );
            //get order_data
            $order_data = $order->get_data();
            //generate checkoutId
            $checkoutId = $this->createCheckoutArray(false,$order_data['amount'],$registrationId);
            $validate['checkoutId'] = $checkoutId;
            //add relevant data to update checkout
            $paymentUrl = $order->get_checkout_payment_url( false );
            $shopperUrl = add_query_arg( array( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'tpLookup' => '1' , 'platformBase' => $this->derivePlatformBase() ) , $paymentUrl );
            $additionalParameters = [
                "shopperResultUrl" => $shopperUrl,
                "customParameters[SHOPPER_payment_url]" => $paymentUrl
            ];
            //set payment_url session var
            WC()->session->set('tp_paymentUrl', ['payment_url' => $paymentUrl]);
            //set payload
            $payload = $this->prepareOrderDataForPayload($order_data,$additionalParameters);
            //update checkoutId
            $responseUpdate = $this->updateTransactionData($this->platformBase,$checkoutId,$payload);
            if($this->jsLogging === true){
                $validate['registrationId'] = $registrationId;
                $validate['payload'] = $payload;
                $validate['responseUpdate'] = $responseUpdate;
            }
            //verify update success
            if(isset($responseUpdate->result->code)){
                if($responseUpdate->result->code !== '000.200.101'){
                    wc_add_notice( $this->getOppwaCardMessageByCode( $responseUpdate->result->code ) . $responseUpdate->result->description, 'error');
                    $validate['refresh'] = true;
                } else {
                    $validate['result'] = 'success';
                    $validate['shopperUrl'] = $shopperUrl;
                    wp_send_json_success($validate);
                }
            } else {
                wc_add_notice('Could not update checkout', 'error');
                $validate['refresh'] = true;
            }
        }
        foreach($validate['errors'] as $notice){
            wc_add_notice($notice, 'error');
        }
        $validate['result'] = 'failure';
        wp_send_json_error($validate);
    }
    
    public function validatePayForOrder($postArr){
        $validation = [
            'result' => false,
            'refresh' => false,
            'errors' => []
        ];
        if(!is_array($postArr)){
            $validation['errors'][] = 'is not array';
        } else if(!isset($postArr['woocommerce_pay'])){
            $validation['errors'][] = 'is not woocommerce_pay';
        } else {
            if(!isset($postArr['payment_method'])){
            $validation['errors'][] = 'payment_method is not set';
            } 
            if(!isset($postArr['tp_payment_nonce'])){
                $validation['errors'][] = 'tp_payment_nonce is not set';
            }
            if(!isset($postArr['wp_http_referer'])){
                $validation['errors'][] = 'wp_http_referer is not set';
            }
            if($postArr['payment_method'] !== $this->id){
                $validation['errors'][] = 'payment_method not ' . $this->id;
            }
        }
        if(count($validation['errors']) > 0){
            return $validation;
        }
        if(!wp_verify_nonce($postArr['tp_payment_nonce'],'tp_payment_nonce')){
            $validation['errors'][] = 'nonce validation failed';
            return $validation;
        }
        if(isset($postArr['terms_field'])){
            if($postArr['terms_field'] === '1'){
                if(!isset($postArr['terms'])){
                    $validation['errors'][] = 'Please read and accept the terms and conditions to proceed with your order.';
                } else {
                    if($postArr['terms'] !== 'on'){
                        $validation['errors'][] = 'Please read and accept the terms and conditions to proceed with your order.';
                    }
                }
            }
        }
        if(count($validation['errors']) > 0){
            return $validation;
        }
        $parseUrl = parse_url($postArr['wp_http_referer']);
        if(!isset($parseUrl['query'])){
            $validation['errors'][] = 'no query args';
            return $validation;
        }
        parse_str($parseUrl['query'], $parseStr);
        if(!isset($parseStr['key'])){
            $validation['errors'][] = 'no key in query args';
            return $validation;
        }
        //$validation['order_key'] = $parseStr['key'];
        $order_id = wc_get_order_id_by_order_key( $parseStr['key'] );
        if($order_id === 0){
            $validation['errors'][] = 'no order found by key: ' . $parseStr['key'];
        } else {
            //$validation['order_id'] = $order_id;
            $order = wc_get_order( $order_id );
            if(!$order){
                $validation['errors'][] = 'could not load order_id: ' . $order_id;
            } else {
                $order_data = $order->get_data();
                //$validation['order_status'] = $order_data['status'];
                if($order_data['status'] !== 'pending'){
                    $validation['errors'][] = 'order is in an invalid status: ' . $order_data['status'];
                } else {
                    //set gateway as payment method
                    $order->set_payment_method( $this->id );
                    //save order
                    $orderId = $order->save();
                    //return success
                    $validation['result'] = true;
                    $validation['order_id'] = $orderId;
                }
            }
        }
        return $validation;
    }
    
    public function parseRgCheckout(){
        $actionUrl = wc_get_post_data_by_key('actionUrl',false);
        if($actionUrl){
            $urlArray = explode('oppwa.com',$actionUrl);
            if(count($urlArray) === 2){
                $resourcePath = $urlArray[1];
                $responseObject = $this->requestTransactionStatus($this->platformBase,false,$resourcePath);
                $success = $this->checkResponsePayload($responseObject);
                if($success !== false){
                    if($success === true){
                        $tokenStore = $this->storeRgToken($responseObject,false);
                        if($tokenStore){
                            $redirect = wc_get_account_endpoint_url('payment-methods');
                            if($this->jsLogging){
                                wp_send_json_success(['swalType'=>'success','message'=>'Card saved successfully!','redirect'=>$redirect,'responseObject'=>$responseObject]);
                            } else {
                                wp_send_json_success(['swalType'=>'success','message'=>'Card saved successfully!','redirect'=>$redirect]);
                            }
                        } else {
                            wp_send_json_error(['swalType'=>'error','message'=>'Token created: not saved!']);
                        }
                    } else {
                        wp_send_json_error(['swalType'=>'error','message'=>$success]);
                    }
                }
            }
        }
        wp_send_json_error(['swalType'=>'error','message'=>'other error']);
    }

    public function lookupTransaction(){
        global $wpdb;
        $this->writeLog('------------lookupTransaction initiated ---------','debug');
        $url = wc_get_checkout_url();
        $resourcePath = wc_get_post_data_by_key('resourcePath',false);
        $resourcePath = sanitize_text_field($resourcePath);
        $inittime               = time();
        $debugArray['time'][0]   = time();
        if($resourcePath){
            $this->writeLog('------------lookupTransaction requesting transaction status ---------','debug');
            $responseObject = $this->requestTransactionStatus($this->platformBase,false,$resourcePath);
            $this->writeLog('------------lookupTransaction requested transaction status ---------','debug');
            $this->writeLog($responseObject,'debug');
            $debugArray['time'][1]   = time();
            if(is_object($responseObject)){
                if(isset($responseObject->result)){
                    if(isset($responseObject->result->code)){
                        if((string)$responseObject->result->code === '100.150.203'){
                            if(isset($responseObject->registrationId)){
                                $id = (string)$responseObject->registrationId;
                                $this->writeLog('------------lookupTransaction requested transaction status again ---------','debug');
                                $responseObject = $this->requestTransactionStatus($this->platformBase,$id,false);
                                $this->writeLog($responseObject,'debug');
                                $debugArray['time'][2]   = time();
                            }
                        }
                    }
                }
                $responseResult = $this->parseResponseData($responseObject);
                $this->writeLog('------------lookupTransaction responseResult ---------','debug');
                $this->writeLog($responseResult,'debug');
                $this->writeLog('------------lookupTransaction responseObject ---------','debug');
                $this->writeLog($responseObject,'debug');
                $order_id = (int)$responseObject->merchantTransactionId;
                $payment_id   = $responseObject->id;
                $payment_code = $responseObject->result->code;
                //$order = wc_get_order( $order_id );
                $wpdb->update( $wpdb->prefix . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'tnxtbl', array('status'=>1, 'uuid'=>$payment_id, 'uuid_code'=>$payment_code), array('post_id'=>$order_id));
                $debugArray['time'][3]   = time();
                if(isset($responseResult['result'])){
                    if($responseResult['result'] === 'success'){
                        $url = $responseResult['redirect'];
                    }
                }
                
                $this->writeLog('------------lookupTransaction 1 ---------','debug');
                $this->writeLog($debugArray,'error');
                wp_send_json_success(['valid' => true, 'resourcePath'=> $resourcePath, 'responseObject' => $responseObject, "responseResult" => $responseResult, "url" => $url]);
            } else {
                $this->writeLog('------------lookupTransaction 2 ---------','debug');
                $this->writeLog($debugArray,'error');
                wp_send_json_error(['error' => 'responseObject','message' => 'is not object']);
            }
        }
                
        $this->writeLog('------------lookupTransaction 3 ---------','debug');
        $this->writeLog($debugArray,'error');
        wp_send_json_error(['error' => 'resourcePath error','message' => 'error']);
    }
    
    public function checkResponsePayload($responseObject){
        if(is_object($responseObject)){
            if(isset($responseObject->result->code)){
                if($this->platformBase === 'oppwa.com'){
                    $successCode = '000.000.000';
                } else {
                    $successCode = '000.100.110';
                }
                if((string)$responseObject->result->code === $successCode){
                    if((string)$responseObject->card->last4Digits === '1114' && $this->platformBase === 'test.oppwa.com'){
                        return (string)$responseObject->result->description . ", trans reference" . $responseObject->id;
                    }
                    return true;
                } else {
                    return (string)$responseObject->result->description . ", trans reference" . $responseObject->id;
                }
            }
        }
        return false;
    }
    
    public function requestTransactionStatus($platformBase,$id,$resourcePath=false){
        if($resourcePath !== false){
            $url="https://".$this->derivePlatformBase().$resourcePath;
        } else if(strlen($id) !== 32){
            $url="https://".$this->derivePlatformBase()."/v1/checkouts/$id/payment";
        } else {
            $url="https://".$this->derivePlatformBase()."/v1/query/$id";
        }
        $url.="?entityId=".($platformBase === 'oppwa.com' ? $this->entityId : $this->entityId_test);
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8','Authorization' => 'Bearer '.($platformBase === 'oppwa.com' ? $this->accessToken : $this->accessToken_test)];
        return $this->prepareRemoteRequest($url,$headers,false,'GET');
    }
    
    public function updateTransactionData($platformBase,$id,$payload){
        $url="https://".$this->derivePlatformBase()."/v1/checkouts/$id";
        $payload['entityId'] = $this->getEntityID();
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8','Authorization' => 'Bearer '.$this->getAccessToken()];//($platformBase === 'oppwa.com' ? $this->accessToken : $this->accessToken_test)];
        return $this->prepareRemoteRequest($url,$headers,$payload,'POST');
    }
    
    public function prepareRemoteRequest($url,$headers,$payload=false,$customRequest='POST'){

        $array = [
            'method' => $customRequest,
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => $headers
        ];
        if($payload !== false){
            $array['body']=$payload;
        }
        /*if($this->proxyRequests === true){
            $array['headers']['Fwdurl'] = $url;
            $url = $this->proxyUrl;
        }*/
        $this->writeLog('------------prepareRemoteRequest -----------','debug');
        $this->writeLog($url,'debug');
        
        $response = wp_remote_request( $url , $array);
        
        $this->shouldLogResponseHandler($response, $array);
        
        $responseData = json_decode($response['body']);        
        $this->writeLog( ['header' => $array, 'response' => $responseData], 'debug' );
        return $responseData;
    }
    
    public function storeRgToken($responseData,$returnNotes = true){
        $notes=[];
        if(isset($responseData->paymentType)){
            $registrationId=$responseData->registrationId;
        } else {
            $registrationId=$responseData->id;
        }
        $registrationIds = $this->retrieveUnlimitedTokens( get_current_user_id() , $this->id, $this->platformBase );
        if(!in_array($registrationId,$registrationIds)){
            $paymentBrandsAssoc = $this->arrStaticData('paymentBrandAssoc');
            if(is_array($paymentBrandsAssoc)){
                if(count($paymentBrandsAssoc) > 0){
                    $token = new WC_Payment_Token_CC();
                    $token->set_gateway_id( $this->id );
                    $token->set_user_id( get_current_user_id() );
                    $token->set_token( $registrationId );
                    $token->set_card_type( $paymentBrandsAssoc[$responseData->paymentBrand] );
                    $token->set_last4( $responseData->card->last4Digits );
                    $token->set_expiry_month( $responseData->card->expiryMonth );
                    $token->set_expiry_year( $responseData->card->expiryYear );
                    if($token->validate() === true){
                        $token->save();
                        if($this->platformBase === 'test.oppwa.com'){
                            $token->add_meta_data('test','1');
                        }
                        $token->add_meta_data('paymentBrand',$responseData->paymentBrand);
                        foreach($responseData->card as $cardKey => $cardVal){
                            if(in_array($cardKey,$this->arrStaticData('cardKeys'))){
                                if(is_object($cardVal) || is_array($cardVal)){
                                    foreach($cardVal as $cardSubKey => $cardSubVal){
                                        if(!empty($cardSubVal)){
                                            $token->add_meta_data($cardSubKey,$cardSubVal);
                                        }
                                    }
                                } else if(!empty($cardVal)){
                                    $token->add_meta_data($cardKey,$cardVal);
                                }
                            }
                        }
                        $token->save_meta_data();
                        if($returnNotes === false){
                            return true;
                        }
                        $notes[] = 'Payment card ' . $responseData->paymentBrand . ' ending ' . $responseData->card->last4Digits . ' stored.';
                    } else {
                        if($returnNotes === false){
                            return false;
                        }
                        $notes[] = 'Unfortunately we could not store your chosen payment card at this time.';
                    }
                }
            }
        }
        return $notes;
    }
    
    public function parseResponseData($responseData){
        
        global $woocommerce;
        $debugTimeArray[0] = time();
        $array = ['notices'=>[]];
        $this->writeLog('------parseResponseData ---------','debug');
        $this->writeLog($responseData,'debug');
        $paymentSuccess = false;
        if(isset($responseData->id)){
            $this->writeLog('------parseResponseData response id valid---------','debug');
            $transaction_id = (string)$responseData->id;
            if(isset($responseData->result->code)){
                $code = (string)$responseData->result->code;
                if($code === '000.000.000' || $code === '000.100.110'){
                    $paymentSuccess = true;
                }
            }
            if(isset($responseData->result->description)){
				if (str_contains($responseData->result->description, "blacklisted")) {
					$description = "<div class='alert alert-danger'><p class='lead'> This transaction has been declined!</p></div>";
				} else {
					$description = str_replace("'", "" , $responseData->result->description);
				}
                //$description = str_replace("'", "" , $responseData->result->description);
            }
            if(isset($responseData->merchantTransactionId)){
                $checks = true;
                $order_id = (int)$responseData->merchantTransactionId;
                $payment_result_code = $responseData->result->code ?? '';
                $debugTimeArray[1] = time();
                $order = wc_get_order( $order_id );
                $this->writeLog('------parseResponseData order_details---------','debug');
                $this->writeLog($order,'debug');
                $amount = number_format($order->get_total(), 2, '.', '');
                $order_data = $order->get_data();
                if($order_data['payment_method'] !== $this->id){
                    $checks = false;
                    $array['notices'][] = 'payment_method not tpcards';
                }
                if($order_data['status'] !== 'pending'){
                    $checks = false;
                    $array['notices'][] = 'status!pending';
                }
                if($amount !== number_format($responseData->customParameters->SHOPPER_amount,2,'.','')){
                    $checks = false;
                    $array['notices'][] = 'amount mismatch';
                }
                if($order_data['currency'] !== (string)$responseData->customParameters->SHOPPER_currency){
                    $checks = false;
                    $array['notices'][] = 'currency mismatch';
                }
                if($order_data['order_key'] !== (string)$responseData->customParameters->SHOPPER_order_key){
                    $checks = false;
                    $array['notices'][] = 'order_key mismatch';
                }
                if($order_data['cart_hash'] !== (string)$responseData->customParameters->SHOPPER_cart_hash){
                    $checks = false;
                    $array['notices'][] = 'Cart items/amount have changed, please retry paying for this order.';
                }
                if($checks){
                    if($paymentSuccess === true){
                        $this->writeLog('------parseResponseData payment is successful---------','debug');
                        //good to go..
                        $order->payment_complete( $transaction_id );
                        $this->writeLog('------parseResponseData set order with payment complete---------','debug');
                        $debugTimeArray[2] = time();
                        //if PA// capture
                        wc_reduce_stock_levels($order_id);
                        $debugTimeArray[3] = time();
                        $order->add_order_note( 'Your order is paid! Thank you!', true );
                        $order->add_order_note( $code, false );
                        $order->add_order_note( "Payment reference: " . $responseData->id, false );
                        $order->add_order_note( $description, false );
                        $order->add_meta_data('platformBase', $this->derivePlatformBase());
                        $order->add_meta_data('paymentType', (string)$responseData->paymentType);
                        $debugTimeArray[4] = time();
                        if($this->createRegistration === true && isset($responseData->registrationId)){
                            $tokenNotes = $this->storeRgToken($responseData);
                            foreach($tokenNotes as $tokenNote){
                                $order->add_order_note( $tokenNote, true );
                            }
                            $debugTimeArray[5] = time();
                        }
                        $order->save();
                        $this->writeLog('------parseResponseData order updated after all set of data---------','debug');
                        $debugTimeArray[6] = time();
                        $array['result'] = 'success';
                        $array['redirect'] = $this->get_return_url( $order );
                        $debugTimeArray[7] = time();
                        $woocommerce->cart->empty_cart();
                        $debugTimeArray[8] = time();
                        $this->writeLog('------------parseResponseData paymentsuccess ---------','debug');
                        $this->writeLog($debugTimeArray,'error');
                    } else {
                        $this->writeLog('------parseResponseData payment not success---------','debug');
                        $this->writeLog(['code' => $code, 'description' => $description],'debug');
                        $order->add_order_note( $code, false );
                        $order->add_order_note( $description, false );
                        $order->save();
                        //decline reason..
                        $array['notices'][] = 'Payment not completed: '.$description;
                        $array['error'] = $description;
                    }
                } else {
                    if( $paymentSuccess === true ){
                        $array['notices'][] = 'Payment is successful, but due to some error we can not process further. Please contact support with payment reference ' . $responseData->id;
                    }else{
                        $array['notices'][] = 'Payment failed';
                    }
                    $array['notices'][] = 'checks failed.';
                }
            } else {
                $array['notices'][] = 'No order_id found, please retry payment.';
                //perform reversal on $paymentSuccess && $responseData->id
                $this->writeLog('----------parseResponseData no order id found----------','debug');
                $this->writeLog($array['notices'],'debug');
                $this->writeLog($responseData,'debug');
            }
        } else {
            $array['notices'][] = 'No transaction id found, please retry payment.';
            $this->writeLog('----------parseResponseData no transaction id found------------','critical');
            $this->writeLog($array['notices'],'critical');
            $this->writeLog($responseData,'critical');
        }
        $this->writeLog($array,'debug');
        wc_clear_notices();
        foreach($array['notices'] as $notice){
            wc_add_notice( __($notice), 'error');
        }
        return $array;
    }
    
    protected function setCustomerAddressField( $field, $key, $data ) {
        $billing_value  = null;
        $shipping_value = null;
        if ( isset( $data[ "billing_{$field}" ] ) && is_callable( array( WC()->customer, "set_billing_{$field}" ) ) ) {
            $billing_value  = $data[ "billing_{$field}" ];
        }
        if ( isset( $data[ "shipping_{$field}" ] ) && is_callable( array( WC()->customer, "set_shipping_{$field}" ) ) ) {
            $shipping_value = $data[ "shipping_{$field}" ];
        }
        if ( ! is_null( $billing_value ) && is_callable( array( WC()->customer, "set_billing_{$field}" ) ) ) {
            WC()->customer->{"set_billing_{$field}"}( $billing_value );
        }
        if ( ! is_null( $shipping_value ) && is_callable( array( WC()->customer, "set_shipping_{$field}" ) ) ) {
            WC()->customer->{"set_shipping_{$field}"}( $shipping_value );
        }
    }
    
    protected function storeSessAddressVars($data){
        $address_fields=$this->arrStaticData('address_fields');
        //process new data
        array_walk( $address_fields, array( $this, 'setCustomerAddressField' ), $data );
        //save customer data
        WC()->customer->save();
    }
    
    protected function clearSessAddressVars(){
        //clear sess data
        $address_fields=$this->arrStaticData('address_fields');
        foreach($address_fields as $k => $field){
            if ( is_callable( array( WC()->customer, "set_billing_{$field}" ) ) ) {
                WC()->customer->{"set_billing_{$field}"}("");
            }
            if ( is_callable( array( WC()->customer, "set_shipping_{$field}" ) ) ) {
                WC()->customer->{"set_shipping_{$field}"}("");
            }
        }
        WC()->customer->save();
    }
    
    public function payment_fields(){
        if( $this->useModalPayFrames === false ):
        ?>
        <style type="text/css">li.payment_method_<?php echo Nochexapi_CONSTANTS::GATEWAY_ID;?> div.payment_box {padding: 0!important;}</style>
        <?php do_action( 'woocommerce_credit_card_form_start', Nochexapi_CONSTANTS::GATEWAY_ID );?>
        <div id="<?php echo Nochexapi_CONSTANTS::GLOBAL_PREFIX;?>cnpForm" style="width:100%;">
            <input id="<?php echo Nochexapi_CONSTANTS::GLOBAL_PREFIX;?>checkout_id" type="hidden" name="<?php echo Nochexapi_CONSTANTS::GLOBAL_PREFIX;?>checkout_id" value="">
            <div id="<?php echo Nochexapi_CONSTANTS::GLOBAL_PREFIX;?>iframe_container"><h1 style="text-align:center;font-size:24px;">Initializing....</h1></div>
        </div>
        <?php do_action( 'woocommerce_credit_card_form_end', Nochexapi_CONSTANTS::GATEWAY_ID );
        endif;
        if( $this->useModalPayFrames === true ):
            $rgArray = $this->retrieveUnlimitedTokens( get_current_user_id(), $this->id, $this->platformBase, true );
            ?>
            <style type="text/css">li.payment_method_<?php echo Nochexapi_CONSTANTS::GATEWAY_ID;?> div.payment_box {display:none!important;}</style>
            <?php if($this->slickOneClick === true && $this->createRegistration === true && is_user_logged_in() === true && is_add_payment_method_page() === false): ?>
            <script type="text/javascript">
                var rgArray = <?php echo json_encode($rgArray);?>;
                jQuery(function(){
                    wc_gateway_tp.genSlickOneClick(rgArray);
                });
            </script>
            <?php endif; ?>
        <?php
        endif;
        /*$showTestCards = true;
        
        if($showTestCards === true){
            if($this->platformBase === 'test.oppwa.com'){
                if(in_array('VISA',$this->paymentBrands)){
                    echo '<p><small><strong>v1,v2 Visa:</strong> <em>4711100000000000</em>, <em>4200000000000042</em></small></p>'."\n";
                }
                if(in_array('MASTER',$this->paymentBrands)){
                    echo '<p><small><strong>v1,v2 Master:</strong> <em>5285601704480326</em>, <em>5200000000000015</em></small></p>'."\n";
                }
                if(in_array('AMEX',$this->paymentBrands)){
                    echo '<p><small><strong>v1,v2 Amex:</strong> <em>341111597242513</em>, <em>343434343434343</em></small></p>'."\n";
                }
            }
        }*/
        
    }
    
    public function orderStatusHandler($status,$order){
        $array=[
            'pending'    => ['result'=>'success', 'redirect' => false, 'refresh' => false, 'reload' => false, 'pending'=>true, 'process' => ["order"=>true]],
            //'processing' => ['result'=>'success', 'redirect' => $this->get_return_url( $order ), 'refresh' => false, 'reload' => false],
            //'on-hold'    => ['result'=>'success', 'redirect' => $this->get_return_url( $order ), 'refresh' => false, 'reload' => false],
            //'completed'  => ['result'=>'success', 'redirect' => $this->get_return_url( $order ), 'refresh' => false, 'reload' => false],
            'cancelled'  => ['result'=>'failure', 'redirect' => false, 'refresh' => false, 'reload' => false, 'messages' => ['error' => ['This order has been cancelled. Please retry your order.']]],
            //'refunded'   => ['result'=>'success', 'redirect' => $this->get_return_url( $order ), 'refresh' => false, 'reload' => false, 'messages' => ['notice' => ['This order has been refunded.']]],
            'failed'     => ['result'=>'failure', 'redirect' => false, 'refresh' => false, 'reload' => true, 'messages' => ['error' => ['There was a problem creating your order, please try again.']]],
        ];
        if(array_key_exists($status, $array)){
            return $array[$status];
        }
        return $array['failed'];
    }
    
    public function process_payment($order_id){
        //first write to log if set.
        $this->writeLog('-----------process_payment POST-----------', 'debug');
        $this->writeLog($_POST,'debug');
        
        //check the order_id exists.
        $order = wc_get_order($order_id);
        if($order===false){
            $this->writeLog('-----------process_payment Order does not exists-----------', 'debug');
            wc_add_notice('There was a problem creating your order, please try again.', 'error');
            return;
        }
        $order_data = $order->get_data();
        $handler = $this->orderStatusHandler($order_data['status'],$order);
        //reject the failed, cancelled on-hold & success
        if(!isset($handler['pending'])){
            if(isset($handler['messages'])){
                $this->writeLog('-----------process_payment pending order has message issue------------','debug');
                $this->writeLog($handler['messages'],'warning');
                foreach($handler['messages'] as $noticeType => $noticeItems){
                    foreach($noticeItems as $notice){
                        wc_add_notice($notice, $noticeType);
                    }
                }
            }
            $this->writeLog('-----------process_payment if pending order does not have message----------','debug');
            $this->writeLog($handler,'warning');
            return $handler;
        }
        //pending orders!
        
        $handler['platformbase'] = $this->derivePlatformBase();
        
        $additionalParams = [];

        $shopperResultUrl = add_query_arg( array( 'fin' => '1' ) , $this->getTPiFrameURL() );
        if($this->useModalPayFrames === true){
            $shopperResultUrl = add_query_arg( array( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'tpLookup' => '1' , 'platformBase' => $this->platformBase ) , $paymentUrl );
        }
        $additionalParams['shopperResultUrl'] = $shopperResultUrl;
        $this->writeLog('-----------process_payment shopperResultUrl ----------','debug');
        $this->writeLog($additionalParams,'debug');

        //check for RG slick
        $registrationId = false;
        if(isset($_POST['registrationId'])){
            if(!empty($_POST['registrationId'])){
                $registrationId = sanitize_text_field($_POST['registrationId']);
            }
        }
        $handler['fullPost'] = $_POST;
        $updateCheckout = false;
        $checkoutId = false;
        $checkoutCode = '';
        $checkoutJson = '{}';
        if(isset($_POST[ Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'checkout_id' ])){
            if(!empty($_POST[ Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'checkout_id' ])){
                $checkoutId = sanitize_text_field($_POST[ Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'checkout_id' ]);
                $updateCheckout = true;
            }
        }
        if($checkoutId === false){
            $this->writeLog('-----------process_payment checkout id missing and create new checkout id-----------','debug');
            $checkoutId = $this->createCheckoutArray(false,false,$registrationId);
        }
        if($checkoutId !== false){
            $checkoutCode = '000.200.100';
            $handler['uuid'] = $checkoutId;
            $handler['pay_url'] = $order->get_checkout_payment_url( false );
            $this->writeLog('-----------process_payment checkout id not missing----------', 'debug');
            $this->writeLog($handler,'debug');
        } else {
            $this->writeLog('-----------process_payment still checkoutid missing-----------','debug');
            wc_add_notice($responseUpdate->result->description, 'error');
            $handler['refresh'] = true;
            return $handler;
        }
        
        //check for 3dv2 additional parameters
        if($this->threeDv2 === true){
            $threeDParams = $this->get_threed_version_two_data();
            if(is_array($threeDParams)){
                if(count($threeDParams) > 0){
                    $additionalParams = array_merge($additionalParams, $threeDParams);
                }
            }
        }
        
        //enforce non-checking checkoutId status
        $payload = $this->prepareOrderDataForPayload($order_data,$additionalParams);

        $checkoutJson = json_encode($payload);
        
        $this->writeLog('-----------process_payment after payload is prepared----------','debug');
        $this->writeLog($payload,'debug');
        
        if($this->jsLogging === true){
            $handler['payload'] = $payload;
        }
        
        $responseUpdate = $this->updateTransactionData($handler['platformbase'],$checkoutId,$payload);
        
        if($this->jsLogging === true){
            $handler['responseUpdate'] = $responseUpdate;
        }
        $this->writeLog('----------process_payment after transaction data updated--------','debug');
        $this->writeLog($responseUpdate,'debug');
        if(isset($responseUpdate->result->code)){
            $checkoutCode = $responseUpdate->result->code;
            if($responseUpdate->result->code !== '000.200.101'){
                wc_add_notice($responseUpdate->result->description, 'error');
                $handler['refresh'] = true;
            } else {
                if($updateCheckout === true){
                    $this->writeLog('-------process_payment successfully transaction data updated on successfull payment---------','debug');
                    $handler['execute'] = true;
                } else {
                    $handler['frameurl'] = $this->getTPiFrameURL();
                }
            }
        } else {
            $this->writeLog('-------process_payment Could not update checkout---------','debug');
            wc_add_notice('Could not update checkout', 'error');
            $handler['refresh']=true;
        }
        //end enforce
        //

        $handler['sqlRes'] = $this->insTxDbRecord($order_data,$this->platformBase,$checkoutId,$checkoutCode,$checkoutJson);
        
        $this->writeLog('---------process_payment insTxDbRecord called -----------','debug');
        $this->writeLog($handler,'debug');
        
        return $handler;
    }

    public function insTxDbRecord($order_data,$platformBase,$checkoutId,$checkoutCode,$checkoutJson){
        global $wpdb;
        $wpdb->flush();
        $pos = strpos($platformBase,'test');
        if($pos !== false){
            $env = 'test';
            $entityUuid = $this->entityId_test;
        } else {
            $env = 'prod';
            $entityUuid = $this->entityId;
        }
        $res = $wpdb->replace(
            $wpdb->prefix . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'tnxtbl', 
            array(
                'cart_hash' => $order_data['cart_hash'], 
                'order_key' => $order_data['order_key'],
                'post_id' => $order_data['id'],
                'platform_base' => $env,
                'entity_id' => $entityUuid,
                'checkout_id' => $checkoutId,
                'checkout_code' => $checkoutCode,
                'checkout_json' => $checkoutJson,
                'status' => 0,
            ), 
            array( 
                '%s', 
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
            )
        );
        if($res){
            //return $this->getCleanUpOrderIds($checkoutId,$order_data['id']);
            return true;
        }
        return false;
    }

    public function getCleanUpOrderIds($checkoutId,$order_id){
        global $wpdb;
        if($this->checkoutOrderCleanup === true){
            $wpdb->flush();
            $postids = $wpdb->get_col(
                $wpdb->prepare( 
                    "SELECT post_id FROM " . $wpdb->prefix . Nochexapi_CONSTANTS::GLOBAL_PREFIX . "tnxtbl WHERE checkout_id = %s AND post_id != %d AND status = 1",
                    $checkoutId,
                    $order_id
                )
            );
            $wpdb->flush();
            if($postids){
                if(is_array($postids)){
                    foreach($postids as $post_id){
                        $post_id = (int)$post_id;
                        $updtOrder = new WC_Order($post_id);
                        if (!empty($updtOrder)) {
                            $updtOrder->update_status( 'cancelled' , 'superseded by new order' );
                            $wpdb->update( 
                                $wpdb->prefix . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'tnxtbl',
                                array( 
                                    'status' => 0
                                ), 
                                array( 'post_id' => $post_id ), 
                                array(
                                    '%d'
                                ),
                                array( '%d' )
                            );
                        }
                    }
                }
                return $postids;
            }
        }
        return true;
    }
    
    public function prepareOrderDataForPayload($order_data,$additionalParams = []){
        global $wp_version;
        $payload = [
            "paymentType" => $this->paymentType,
            "amount" => $order_data['total'],
            "currency" => $order_data['currency'],
            "merchantTransactionId" => $order_data['id'],
            "customer.merchantCustomerId" => $order_data['customer_id'],
            "customParameters[SHOPPER_amount]" => $order_data['total'],
            "customParameters[SHOPPER_currency]" => $order_data['currency'],
            "customParameters[SHOPPER_order_key]" => $order_data['order_key'],
            "customParameters[SHOPPER_cart_hash]" => $order_data['cart_hash'],
            "customParameters[SHOPPER_platform]" => "WooCommerce",
            "customParameters[SHOPPER_version_data]" => "WPVER_" . $wp_version . "|" . "WCVER_" . WC_VERSION . "|" . "TPMOD_".$this->id,
            "customParameters[SHOPPER_plugin]" => Nochexapi_CONSTANTS::getPluginFileData( 'Version' ),
            "card.holder" => (string)($order_data['billing']['first_name'].' '. $order_data['billing']['last_name']),
            "customer.givenName" => $order_data['billing']['first_name'],
            "customer.surname" => $order_data['billing']['last_name'],
            "customer.email" => $order_data['billing']['email'],
            "customer.ip" => $order_data['customer_ip_address'],
            "customer.browserFingerprint.value" => $order_data['customer_user_agent']
        ];
        if(isset($order_data['billing']['phone'])){
            if(!empty($order_data['billing']['phone'])){
                $payload["customer.mobile"] = $order_data['billing']['phone'];
            }
        }
        if(isset($order_data['billing']['address_1'])){
            if(!empty($order_data['billing']['address_1'])){
                $payload["billing.street1"] = $order_data['billing']['address_1'];
            }
        }
        if(isset($order_data['billing']['address_2'])){
            if(!empty($order_data['billing']['address_2'])){
                $payload["billing.street2"] = $order_data['billing']['address_2'];
            }
        }
        if(isset($order_data['billing']['city'])){
            if(!empty($order_data['billing']['city'])){
                $payload["billing.city"] = $order_data['billing']['city'];
            }
        }
        if(isset($order_data['billing']['postcode'])){
            if(!empty($order_data['billing']['postcode'])){
                $payload["billing.postcode"] = $order_data['billing']['postcode'];
            }
        }
        if(isset($order_data['billing']['country'])){
            if(!empty($order_data['billing']['country'])){
                $payload["billing.country"] = $order_data['billing']['country'];
            }
        }
        if(isset($order_data['shipping']['address_1'])){
            if(!empty($order_data['shipping']['address_1'])){
                $payload["shipping.street1"] = $order_data['shipping']['address_1'];
            }
        }
        if(isset($order_data['shipping']['address_2'])){
            if(!empty($order_data['shipping']['address_2'])){
                $payload["shipping.street2"] = $order_data['shipping']['address_2'];
            }
        }
        if(isset($order_data['shipping']['city'])){
            if(!empty($order_data['shipping']['city'])){
                $payload["shipping.city"] = $order_data['shipping']['city'];
            }
        }
        if(isset($order_data['shipping']['postcode'])){
            if(!empty($order_data['shipping']['postcode'])){
                $payload["shipping.postcode"] = $order_data['shipping']['postcode'];
            }
        }
        if(isset($order_data['shipping']['country'])){
            if(!empty($order_data['shipping']['country'])){
                $payload["shipping.country"] = $order_data['shipping']['country'];
            }
        }
		$payload["cart.items[0].name"] = $this->getCartItemsOrderData($order_data['id']);
		
        
        return array_merge($payload, $additionalParams);
        //return $payload;
    }
    
    public function getCartItemsOrderData($order_id){
        $payload = [];
      /*  if($this->includeCartData !== true){
            return $payload;
        }*/
        $cartname = "";
        $order_id = (int)$order_id;
        $oObj = wc_get_order($order_id);
        foreach($oObj->get_items(['line_item']) as $oItemId => $oItem){
           /* $oProd = $oItem->get_product();
            $cartItem = [
                'name' => substr($oItem->get_name(),0,255),
                'merchantItemId' => $oItem->get_product_id(),
                'quantity' => (int)$oItem->get_quantity(),
                'type' => ($oProd->is_virtual() === true ? 'DIGITAL' : 'PHYSICAL'),
                'sku' => ($oProd->get_sku() === '' ? $oItem->get_product_id() : $oProd->get_sku()),
                'currency' => $oObj->get_currency(),
                'description' => substr(wp_strip_all_tags($oProd->get_description()),0,255),
                'price' => number_format((float)$oObj->get_item_subtotal($oItem), 2, '.', ''),
                'totalAmount' => number_format(((float)$oItem->get_total() + (float)$oObj->get_line_tax($oItem)), 2, '.', ''),
                'taxAmount' => number_format( (float)$oObj->get_item_tax($oItem), 2, '.', '' ),
                'totalTaxAmount' => number_format( (float)$oObj->get_line_tax($oItem), 2, '.', '' )
            ];
            $cartItems[] = $cartItem;*/
			
			$cartname .= $oItem->get_name() . " - ". $oItem->get_quantity() . " x ". $oItem->get_total();
        }
        /*foreach( $oObj->get_items('shipping') as $oItemId => $shipping_item_obj ){
            $cartItem = [
                'name' => 'Shipping',
                'merchantItemId' => $shipping_item_obj->get_method_id() .':'. $shipping_item_obj->get_instance_id(),
                'quantity' => 1,
                'type' => 'MIXED',
                'currency' => $oObj->get_currency(),
                'description' => substr($shipping_item_obj->get_method_title(),0,255),
                'price' => number_format( (float)$shipping_item_obj->get_total(), 2, '.', '' ),
                'shipping' => number_format( (float)$shipping_item_obj->get_total(), 2, '.', '' ),
                'shippingMethod' => substr($shipping_item_obj->get_name(),0,255),
                'totalAmount' => number_format( (float)$shipping_item_obj->get_total() + (float)$shipping_item_obj->get_total_tax(), 2, '.', '' ),
                'taxAmount' => number_format( (float)$shipping_item_obj->get_total_tax(), 2, '.', '' ),
                'totalTaxAmount' => number_format( (float)$shipping_item_obj->get_total_tax(), 2, '.', '' )
            ];
            $cartItems[] = $cartItem;
        }*/
        /*$n = 0;
        foreach($cartItems as $lineItemArray){
            foreach($lineItemArray as $k => $v){
                if(!empty($v) || $v !== ''){
                    if(in_array($k,$this->arrStaticData('forcePrecision2Dp'))){
                        $v = number_format($v, 2, '.', '');
                    }
                    $payload['cart.items['.$n.'].'.$k] = $v;
                }
            }
            $n++;
        }*/
        return $cartname;
    }
    
    public function validate_fields(){
        //wc_add_notice( 'validate_fields()' , 'error');
        if($this->checkoutOrderCleanup === true){
            $sess_order_id = absint( WC()->session->get( 'order_awaiting_payment' ) );
            $sess_cart_hash = WC()->cart->get_cart_hash();
            if($sess_order_id > 0){
                $sess_order = $sess_order_id ? wc_get_order( $sess_order_id ) : false;
                if($sess_order !== false){
                    if ( $sess_order->has_cart_hash( $sess_cart_hash ) && $sess_order->has_status( array( 'pending', 'failed' ) ) ) {
                        //wc_add_notice( 'good to go with matching cart hashes!' , 'success');
                    } else {
                        $sess_order->update_status( 'cancelled' , 'superseded by new order' );
                        //wc_add_notice( 'cancelling this order: ' . $sess_order_id , 'error');
                    }
                }
            }
        }
        return true;
    }
    
    public function process_refund( $order_id, $amount = null, $reason = '') {
        $order = new WC_Order($order_id);
        $order_data = $order->get_data();
        if($amount !== null){
            $amount = number_format($amount, 2, '.', '');
        } else {
            $amount = '0.00';
        }
        $id = $order->get_transaction_id();
        $platformBase = $order->get_meta('platformBase');
        $paymentType = $order->get_meta('paymentType');
        if(in_array($paymentType,['DB','CP','RB'])){
            $payload = ['paymentType'=>'RF','currency'=>$order_data['currency'],'amount'=>$amount];
        } else if(in_array($paymentType,['PA'])){
            $payload = ['paymentType'=>'RV','currency'=>$order_data['currency']];
        } else {
            return new WP_Error( 'Error', 'Original paymentType not recognised.' );
        }
        $payload['entityId'] = ($platformBase === 'oppwa.com' || $platformBase === 'eu-prod.oppwa.com' ? $this->entityId : $this->entityId_test);
        
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8','Authorization' => 'Bearer '.($platformBase === 'oppwa.com' || $platformBase === 'eu-prod.oppwa.com' ? $this->accessToken : $this->accessToken_test)];
        
        $result = $this->prepareRemoteRequest('https://'.$platformBase.'/v1/payments/'.$id ,$headers,$payload,'POST');
        
        if(!is_object($result)){
            return new WP_Error( 'Error', 'Transaction response error.' );
        }
        if($result->result->code == '000.000.000' || $result->result->code == '000.100.110'){
            return true;
        } else {
            return new WP_Error( 'Error', 'Transaction refused: '.$result->result->description );
        }
        return new WP_Error( 'Error', 'Transaction processing error' );
    }
    
    public function add_payment_method() {
        return array(
            'result'   => 'failure',
            'redirect' => wc_get_endpoint_url( 'payment-methods' ),
        );
    }
    
    public function sync_saved_cards_tokens() {
        $synced                  = get_option( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'synced_data', 'no' );
        
        if( $synced === 'yes' ){
            return;
        }

        $counter            = $this->process_sync_saved_cards_tokens();
        return;
    }
    
    public function ajax_sync_saved_cards_tokens() {
        $synced                  = get_option( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'synced_data', 'no' );
        $ForcedRequest           = $_REQUEST['forced_request'] ?? 'no';
        
        if( $synced === 'yes' ){
            return wp_send_json_success( [ "valid" => true, "containerId" => "statusTablesContainer", "records" => 0, "html" => $this->generateStatusArray(true) ] );
        }
        $response            = $this->process_sync_saved_cards_tokens();
        wp_send_json_success( $response );
    }
    
    public function ajax_forward_debugdata_to_tp_support() {
        $DebugFile           = $_REQUEST['filename'] ?? '';
        
        if( !empty( $DebugFile ) && file_exists( WC_LOG_DIR . $DebugFile ) ){
            //wp_mail( Nochexapi_CONSTANTS::DEBUG_CONTACT_EMAIL, "Debug file from " . get_bloginfo( 'name' ), "Please find attached debug file.", [], WC_LOG_DIR . $DebugFile );
            return wp_send_json_success( [ "valid" => true, "message" => "Debug data forwarded to TotalProcessing Support" ] );
        }

        wp_send_json_error( [ "valid" => false, "message" => "Something went wrong, Please try again." ] );
    }
    
    public function process_sync_saved_cards_tokens(){
        global $wpdb;
        $gateway_ids           = [ 'wc_cardsv2', 'totalprocessing'];
        
        $counter               = 0;
        $page                  = $_REQUEST['nextpage'] ?? 1;
        $tokens                = WC_Payment_Tokens::get_tokens( [ 'gateway_id' => 'wc_cardsv2', 'limit' => 100, 'page' => $page ] );
        $data                  = [];
    
        if( !is_array( $tokens ) || count( $tokens ) <= 0 ){
            update_option( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'synced_data', 'yes' );
            return [ "valid" => true, "containerId" => "statusTablesContainer", "html" => $this->generateStatusArray(true) ];
        }
        foreach( $tokens AS $dbtoken ){
            $tokenid   = $dbtoken->get_token();
            $is_exists = (int)$wpdb->get_var( "SELECT COUNT(1) FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE gateway_id='".$this->id."' AND token='".$tokenid."'" );
            if( $is_exists > 0 ){
                continue;
            }
    
            $token     = new WC_Payment_Token_CC();
            $token->set_token( $tokenid );
            $token->set_gateway_id( $this->id );
            $token->set_user_id( $dbtoken->get_user_id() );
            if( $token->is_default() ){
                $token->set_default( true );
            }
            $token->set_card_type( $dbtoken->get_meta( 'card_type' ) );
            $token->set_last4( $dbtoken->get_last4() );
            $token->set_expiry_month( $dbtoken->get_expiry_month() );
            $token->set_expiry_year( $dbtoken->get_expiry_year() );
    
            if($token->validate() === true){
                $token->save();
                if( $dbtoken->meta_exists( 'test' ) ){
                    $token->add_meta_data( 'test', '1' ); 
                }
                $token->add_meta_data( 'paymentBrand', $dbtoken->get_meta( 'paymentBrand' ) );
                $token->add_meta_data( 'holder', $dbtoken->get_meta( 'holder' ) );
                $token->add_meta_data( 'card_type', $dbtoken->get_meta( 'card_type' ) );
                $token->add_meta_data( 'expiry_month', $dbtoken->get_meta( 'expiry_month' ) );
                $token->add_meta_data( 'expiry_year', $dbtoken->get_meta( 'expiry_year' ) );
                $token->add_meta_data( 'last4', $dbtoken->get_meta( 'last4' ) );
                $token->add_meta_data( 'bin', $dbtoken->get_meta( 'bin' ) );
                $token->save_meta_data();
                $counter++;
            }
        }
    
        return [ "valid" => true, "containerId" => "tpsyncedcardstatus", "html" => $counter . " Saved cards are synced so far, please wait while syncing is in progress... Step: " . $page, 'nextpage' => ++$page, 'nextcall' => 1 ];
    }

    public function tp_display_global_errors(){
        if( !$this->isPluginFrameURLPublished() ):
            $url    = admin_url( "/admin.php?page=wc-settings&tab=checkout&section=".Nochexapi_CONSTANTS::GATEWAY_ID."&opt=status" );
        ?>
            <div class="error notice">
                <p>We've detected an issue with the Nochex iframe page - please <a href="<?php echo $url;?>">Click here</a> to check the status page and regenerate</p>
            </div>
        <?php
        endif;

        $optionname     = Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'synced_data';
        $optionvalue    = get_option( $optionname, 'no' );
        if( empty( $optionvalue ) || $optionvalue == 'no' ):
            $url    = admin_url( "/admin.php?page=wc-settings&tab=checkout&section=".Nochexapi_CONSTANTS::GATEWAY_ID."&opt=status" );
        ?>
            <div class="error notice">
                <p>There might be saved cards from your customers - please <a href="<?php echo $url;?>">Click here</a> to import saved cards from old plugin to latest</p>
            </div>
        <?php
        endif;
    }

    function VerifyPaymentStatusForOrderID( $OrderID ) {
        $url   = "https://".$this->derivePlatformBase()."/v1/query";
        $url  .= "?entityId=" . $this->getEntityID();
        $url  .= "&merchantTransactionId=" . (int)$OrderID;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [ 'Authorization:Bearer ' . $this->getAccessToken() ]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);// this should be set to true in production
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $responseData = curl_exec($ch);
        if(curl_errno($ch)) {
                return curl_error($ch);
        }
        curl_close($ch);
        return json_decode( $responseData, true );
    }
} //end class
