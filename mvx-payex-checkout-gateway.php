<?php
/**
 * Plugin Name: MVX Payex Split Payment
 * Plugin URI: https://wc-marketplace.com/addons/
 * Description: MVX Payex Split Checkout Gateway is a payment gateway for pay with woocommerce as well as split payment with MVX multivendor marketplace.
 * Author: Payex
 * Version: 1.0.1
 * Author URI: https://payex.io/
 * Text Domain: mvx-payex-checkout-gateway
 * Domain Path: /languages/
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly
}

if (!class_exists('MVX_Payex_Checkout_Gateway_Dependencies')) {
    require_once 'classes/class-mvx-payex-checkout-gateway-dependencies.php';
}

require_once 'mvx-payex-checkout-gateway-config.php';

if (!defined('MVX_PAYEX_CHECKOUT_GATEWAY_PLUGIN_TOKEN')) {
    exit;
}
if (!defined('MVX_PAYEX_CHECKOUT_GATEWAY_TEXT_DOMAIN')) {
    exit;
}

if(!MVX_Payex_Checkout_Gateway_Dependencies::woocommerce_active_check()){
    add_action('admin_notices', 'woocommerce_inactive_notice');
}

if(MVX_Payex_Checkout_Gateway_Dependencies::others_payex_plugin_active_check()){
    add_action('admin_notices', 'others_payex_plugin_inactive_notice');
}

if (!class_exists('MVX_Payex_Checkout_Gateway') && MVX_Payex_Checkout_Gateway_Dependencies::woocommerce_active_check() && !MVX_Payex_Checkout_Gateway_Dependencies::others_payex_plugin_active_check()) {
    require_once( 'classes/class-mvx-payex-checkout-gateway.php' );
    global $MVX_Payex_Checkout_Gateway;
    $MVX_Payex_Checkout_Gateway = new MVX_Payex_Checkout_Gateway(__FILE__);
    $GLOBALS['MVX_Payex_Checkout_Gateway'] = $MVX_Payex_Checkout_Gateway;
}
