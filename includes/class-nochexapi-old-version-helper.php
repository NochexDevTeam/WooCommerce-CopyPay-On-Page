<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
 
class WC_Payment_Gateway_Nochexapi_Old_helper extends WC_Payment_Gateway {

    public function __construct() {
 
		$this->id = 'wc_cardsv2';
		$this->init_settings();
    }

    public function getSettings(){
        return $this->settings;
    }
} //end class
