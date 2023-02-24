<?php

/**
 * WC Dependency Checker
 *
 */
class MVX_Payex_Checkout_Gateway_Dependencies {

    private static $active_plugins;

    static function init() {
        self::$active_plugins = (array) get_option('active_plugins', array());
        if (is_multisite())
            self::$active_plugins = array_merge(self::$active_plugins, get_site_option('active_sitewide_plugins', array()));
    }

    public static function woocommerce_active_check() {
        if (!self::$active_plugins) {
            self::init();
        }
        return in_array('woocommerce/woocommerce.php', self::$active_plugins) || array_key_exists('woocommerce/woocommerce.php', self::$active_plugins);
    }
    
    public static function mvx_active_check() {
        if (!self::$active_plugins) {
            self::init();
        }
        return in_array('dc-woocommerce-multi-vendor/dc_product_vendor.php', self::$active_plugins) || array_key_exists('dc-woocommerce-multi-vendor/dc_product_vendor.php', self::$active_plugins);
    }
    public static function others_payex_plugin_active_check() {
        if (!self::$active_plugins) {
            self::init();
        }
        //TODO not sure need to change to what directory for payex
        return in_array('woo-payex/woo-payex.php', self::$active_plugins) || array_key_exists('woo-payex/woo-payex.php', self::$active_plugins);
    }
}