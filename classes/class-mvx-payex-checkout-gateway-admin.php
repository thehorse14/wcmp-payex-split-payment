<?php

class MVX_Payex_Checkout_Gateway_Admin {

    public function __construct() {
        add_filter( 'automatic_payment_method', array( $this, 'admin_payex_payment_mode'), 20);
        add_filter( 'mvx_vendor_payment_mode', array( $this, 'vendor_payex_payment_mode' ), 20);
        add_filter("settings_vendors_payment_tab_options", array( $this, 'mvx_setting_payex_account_id' ), 90, 2 );
        add_action( 'settings_page_payment_payex_tab_init', array( &$this, 'payment_payex_init' ), 10, 2 );
        add_filter('mvx_tabsection_payment', array( $this, 'mvx_tabsection_payment_payex' ) );
        add_filter('mvx_vendor_user_fields', array( $this, 'mvx_vendor_user_fields_for_payex' ), 10, 2 );
        add_action('mvx_after_vendor_billing', array($this, 'mvx_after_vendor_billing_for_payex'));
    }

    public function mvx_after_vendor_billing_for_payex() {
        global $MVX;
        $user_array = $MVX->user->get_vendor_fields( get_current_user_id() );
        ?>
        <div class="payment-gateway payment-gateway-payex <?php echo apply_filters('mvx_vendor_paypal_email_container_class', ''); ?>">
            <div class="form-group">
                <label for="vendor_payex_account_id" class="control-label col-sm-3 col-md-3"><?php esc_html_e('Payex Account Id', 'mvx-payex-checkout-gateway'); ?></label>
                <div class="col-md-6 col-sm-9">
                    <input id="vendor_payex_account_id" class="form-control" type="text" name="vendor_payex_account_id" value="<?php echo isset($user_array['vendor_payex_account_id']['value']) ? $user_array['vendor_payex_account_id']['value'] : ''; ?>"  placeholder="<?php esc_attr_e('Payex Account Id', 'mvx-payex-checkout-gateway'); ?>">
                </div>
            </div>
        </div>
        <?php
    }

    public function mvx_vendor_user_fields_for_payex($fields, $vendor_id) {
        $vendor = get_mvx_vendor($vendor_id);
        $fields["vendor_payex_account_id"] = array(
            'label' => __('Payex Route Account Id', 'mvx-payex-checkout-gateway'),
            'type' => 'text',
            'value' => $vendor->payex_account_id,
            'class' => "user-profile-fields regular-text"
        );
        return $fields;
    }

    public function admin_payex_payment_mode( $arg ) {
        unset($arg['payex_block']);
        $admin_payment_mode_select = array_merge( $arg, array( 'payex' => __('Payex', 'mvx-payex-checkout-gateway') ) );
        return $admin_payment_mode_select;
    }

    public function vendor_payex_payment_mode($payment_mode) {
        if (mvx_is_module_active('payex')) {
            $payment_mode['payex'] = __('Payex', 'mvx-payex-checkout-gateway');
        }
        return $payment_mode;
    }

    public function mvx_setting_payex_account_id( $payment_tab_options, $vendor_obj ) {
        $payment_tab_options['vendor_payex_account_id'] = array('label' => __('Account Number', 'mvx-payex-checkout-gateway'), 'type' => 'text', 'id' => 'vendor_payex_account_id', 'label_for' => 'vendor_payex_account_id', 'name' => 'vendor_payex_account_id', 'value' => $vendor_obj->payex_account_id, 'wrapper_class' => 'payment-gateway-payex payment-gateway');
        return $payment_tab_options;
    }

    public function payment_payex_init( $tab, $subsection ) {
        global $MVX_Payex_Checkout_Gateway;
        require_once $MVX_Payex_Checkout_Gateway->plugin_path . 'admin/class-mvx-settings-payment-payex.php';
        new MVX_Settings_Payment_Payex( $tab, $subsection );
    }

    public function mvx_tabsection_payment_payex($tabsection_payment) {
        if ( 'Enable' === get_mvx_vendor_settings( 'payment_method_payex', 'payment' ) ) {
            $tabsection_payment['payex'] = array( 'title' => __( 'Payex', 'mvx-payex-checkout-gateway' ), 'icon' => 'dashicons-admin-settings' );
        }
        return $tabsection_payment;
    }
}