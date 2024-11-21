<?php
use Nochexapi\WC_Nochexapi_Constants AS Nochexapi_CONSTANTS;

class Nochexapi_Activator {

    /** 
     * Short Description. (use period)
     *
     * Long Description.
     *
     * @since    5.2.0
     */
    public static function activate() {
        self::tpcp_gateway_cardsv2_activation();
        self::tpcp_gateway_cardsv2_db_tran_tbl();
        self::tpcp_gateway_cardsv2_db_cbk_tbl();
        self::tpcp_gateway_cronjob_tbl();
    }

    public static function tpcp_gateway_cardsv2_activation() {
        $e2e = get_option( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'gateway_cardsv2_e2e' );
        if($e2e === false || empty($e2e)){
            add_option( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'gateway_cardsv2_e2e', '-1' );
        } else if((int)$e2e == 0){
            update_option( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'gateway_cardsv2_e2e', '-1' );
        }
        add_option( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'gateway_cardsv2_iframe', '0' );
        add_option( Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'gateway_cardsv2_cbk', '0' );
        return $e2e;
    }

    public static function tpcp_gateway_cardsv2_db_tran_tbl() {
        global $wpdb;
        global $charset_collate;
        $tblname = $wpdb->prefix . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'tnxtbl';
        $sql = "CREATE TABLE IF NOT EXISTS `" . $tblname . "` ( ";
        $sql .= "`cart_hash` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,";
        $sql .= "`order_key` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,";
        $sql .= "`post_id` int(20) unsigned NOT NULL,";
        $sql .= "`platform_base` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL,";
        $sql .= "`entity_id` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,";
        $sql .= "`checkout_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,";
        $sql .= "`checkout_creation` datetime DEFAULT current_timestamp(),";
        $sql .= "`checkout_code` varchar(11) COLLATE utf8mb4_unicode_ci DEFAULT NULL,";
        $sql .= "`uuid` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,";
        $sql .= "`uuid_code` varchar(11) COLLATE utf8mb4_unicode_ci DEFAULT NULL,";
        $sql .= "`checkout_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,";
        $sql .= "`result_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,";
        $sql .= "`webhook_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,";
        $sql .= "`status` int(1) unsigned DEFAULT NULL,";
        $sql .= "PRIMARY KEY (`post_id`),";
        $sql .= "UNIQUE KEY `cart_hash` (`cart_hash`,`order_key`,`post_id`),";
        $sql .= "KEY `checkout_id` (`checkout_id`),";
        $sql .= "KEY `uuid` (`uuid`),";
        $sql .= "KEY `status` (`status`),";
        $sql .= "KEY `post_id` (`post_id`),";
        $sql .= "KEY `platformBase` (`platform_base`),";
        $sql .= "KEY `checkout_creation` (`checkout_creation`)";
        $sql .= ") ". $charset_collate . ";";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        return true;
    }

    public static function tpcp_gateway_cardsv2_db_cbk_tbl() {
        global $wpdb;
        global $charset_collate;
        $tblname = $wpdb->prefix . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'cbktbl';
        $sql = "CREATE TABLE IF NOT EXISTS `" . $tblname . "` ( ";
        $sql .= "`arn` varchar(26) COLLATE utf8mb4_unicode_ci NOT NULL,";
        $sql .= "`cbk_type` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL,";
        $sql .= "`uuid` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,";
        $sql .= "`post_id` int(20) unsigned NOT NULL,";
        $sql .= "`cbk_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,";
        $sql .= "`status` int(1) unsigned DEFAULT NULL,";
        $sql .= "PRIMARY KEY (`arn`,`cbk_type`),";
        $sql .= "KEY `uuid` (`uuid`),";
        $sql .= "KEY `status` (`status`),";
        $sql .= "KEY `post_id` (`post_id`)";
        $sql .= ") ". $charset_collate . ";";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        return true;
    }

    public static function tpcp_gateway_cronjob_tbl() {
        global $wpdb;
        global $charset_collate;
        $tblname = $wpdb->prefix . Nochexapi_CONSTANTS::GLOBAL_PREFIX . 'cronjob_tbl';
        $sql = "CREATE TABLE IF NOT EXISTS `" . $tblname . "` ( ";
        $sql .= "`uuid` INT(11) NOT NULL AUTO_INCREMENT,";
        $sql .= "`orderid` INT(20) NOT NULL,";
        $sql .= "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,";
        $sql .= "`checked` tinyint NOT NULL DEFAULT 0,";
        $sql .= "PRIMARY KEY (`uuid`),";
        $sql .= "KEY `orderid` (`orderid`)";
        $sql .= ") ". $charset_collate . ";";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        return true;
    }
}
