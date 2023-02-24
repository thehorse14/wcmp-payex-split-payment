<?php

class MVX_Payex_Checkout_Gateway {
    public $plugin_url;
    public $plugin_path;
    public $version;
    public $token;
    public $text_domain;
    private $file;
    public $license;
    public $connect_payex;
    public $payex_admin;

    public function __construct($file) {
        $this->file = $file;
        $this->plugin_url = trailingslashit(plugins_url('', $plugin = $file));
        $this->plugin_path = trailingslashit(dirname($file));
        $this->token = MVX_PAYEX_CHECKOUT_GATEWAY_PLUGIN_TOKEN;
        $this->text_domain = MVX_PAYEX_CHECKOUT_GATEWAY_TEXT_DOMAIN;
        $this->version = MVX_PAYEX_CHECKOUT_GATEWAY_PLUGIN_VERSION;

        require_once $this->plugin_path . 'classes/class-mvx-payex-checkout-payment.php';        
        add_action('init', array(&$this, 'init'), 0);
        add_filter('mvx_multi_tab_array_list', array($this, 'mvx_multi_tab_array_list_for_payex'));
        add_filter('mvx_settings_fields_details', array($this, 'mvx_settings_fields_details_for_payex'));
    }

    public function mvx_multi_tab_array_list_for_payex($tab_link) {
        $tab_link['marketplace-payments'][] = array(
                'tablabel'      =>  __('Payex', 'mvx-payex-checkout-gateway'),
                'apiurl'        =>  'mvx_module/v1/save_dashpages',
                'description'   =>  __('Connect to vendors payex account and make hassle-free transfers as scheduled.', 'mvx-payex-checkout-gateway'),
                'icon'          =>  'icon-tab-stripe-connect',
                'submenu'       =>  'payment',
                'modulename'     =>  'payment-payex'
            );
        return $tab_link;
    }

    public function mvx_settings_fields_details_for_payex($settings_fileds) {
        $settings_fileds_report = [
            [
                'key'       => 'email',
                'type'      => 'text',
                'label'     => __('Email', 'mvx-pro'),
                'placeholder'   => __('Email', 'mvx-pro'),
                'database_value' => '',
            ],
            [
                'key'       => 'secret_key',
                'type'      => 'password',
                'label'     => __('Secret Key', 'mvx-pro'),
                'placeholder'   => __('Secret Key', 'mvx-pro'),
                'database_value' => '',
            ],
            [
                'key'    => 'is_split',
                'label'   => __( "Enable Split Payment", 'mvx-pro' ),
                'class'     => 'mvx-toggle-checkbox',
                'type'    => 'checkbox',
                'options' => array(
                    array(
                        'key'=> "is_split",
                        'label'=> '',
                        'value'=> "is_split"
                    ),
                ),
                'database_value' => array(),
            ],
        ];
        $settings_fileds['payment-payex'] = $settings_fileds_report;
        return $settings_fileds;
    }

    /**
     * initilize plugin on WP init
     */
    function init() {
        // Init Text Domain
        $this->load_plugin_textdomain();

        if (class_exists('MVX')) {
            require_once $this->plugin_path . 'classes/class-mvx-gateway-payex.php';
            $this->connect_payex = new MVX_Gateway_Payex();

            require_once $this->plugin_path . 'classes/class-mvx-payex-checkout-gateway-admin.php';
            $this->payex_admin = new MVX_Payex_Checkout_Gateway_Admin();

            add_filter('mvx_payment_gateways', array(&$this, 'add_mvx_payex_payment_gateway'));
        }
    }

    public function add_mvx_payex_payment_gateway($load_gateways) {
        $load_gateways[] = 'MVX_Gateway_Payex';
        return $load_gateways;
    }

    /**
     * Load Localisation files.
     *
     * Note: the first-loaded translation file overrides any following ones if the same translation is present
     *
     * @access public
     * @return void
     */
    public function load_plugin_textdomain() {
        $locale = is_admin() && function_exists('get_user_locale') ? get_user_locale() : get_locale();
        $locale = apply_filters('plugin_locale', $locale, 'mvx-payex-checkout-gateway');
        load_textdomain('mvx-payex-checkout-gateway', WP_LANG_DIR . '/mvx-payex-checkout-gateway/mvx-payex-checkout-gateway-' . $locale . '.mo');
        load_plugin_textdomain('mvx-payex-checkout-gateway', false, plugin_basename(dirname(dirname(__FILE__))) . '/languages');
    }
}