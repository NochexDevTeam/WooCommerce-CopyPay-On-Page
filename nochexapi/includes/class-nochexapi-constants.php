<?php
/**
 * TP Cards constants.
 */
namespace Nochexapi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} 

class WC_Nochexapi_Constants {
    const VERSION                          = "4.8.3";
    const DEBUG                            = false;
    /*const GLOBAL_PREFIX                    = "tpcpv3_";
    const GATEWAY_ID                       = "wc_tp_cardsv3";*/
	
    const GLOBAL_PREFIX                    = "nochexapi_";
    const GATEWAY_ID                       = "nochexapi";
    const GATEWAY_TITLE                    = "Nochex API";
    const GATEWAY_DESCRIPTION              = "Checkout with Credit/Debit Card.";

    public static function getPluginFileData( $var ){
        $data  = get_plugin_data( self::getPluginRootPath() . "/nochexapi.php", false, false );
        return ($data[$var] ?? '');
    }

    public static function getPlatformBaseURL( $isLive ){
        return ( (bool)$isLive === true ) ? 'oppwa.com' : 'test.oppwa.com';
    }

    public static function getExternalFrameURL(){
        return plugin_dir_url( __DIR__ ) . 'templates/pci-frame-external.php?frame=1';
    }

    public static function getPluginRootPath(){
        return plugin_dir_path( dirname( __FILE__ ) );
    }

    public static function getPluginBaseName(){
        return NOCHEXAPI_PLUGIN_BASENAME;
    }
    
    public static function getFilePaths( $dir, $outsideRoot = 0, $appendDir = false ){
        if( $outsideRoot === 3 ){
            if( $appendDir ){
                return dirname( __DIR__ ) . '/' . $dir;
            }
            return dirname( __DIR__ );
        }
        $docRoot         = $_SERVER['DOCUMENT_ROOT'];
        $docRoot         = rtrim( $docRoot, '/' );
        $docRootExpl     = explode( '/', $docRoot );
        if( $outsideRoot === 1 ){
            array_pop( $docRootExpl );
        }
        $docBase         = implode( '/', $docRootExpl );
        if( $appendDir ){
            return $docBase . '/' . $dir;
        }
        return $docBase;
    }
}
