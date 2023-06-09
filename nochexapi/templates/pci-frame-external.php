<?php
$wp=false; 
$site_root_directory=substr(__DIR__,0,strpos(__DIR__,"wp-content"));
define('WP_USE_THEMES', false);
global $wp, $wp_query, $wp_the_query, $wp_rewrite, $wp_did_header;
require_once $site_root_directory.'wp-load.php';
if(!is_object($wp)){
    exit('global core object load error');
}
include_once dirname(__FILE__).'/pci-frame-templatev3.php';