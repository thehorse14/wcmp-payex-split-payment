<?php

const PAYEX_AUTH_CODE_SUCCESS = '00';
const PAYEX_AUTH_CODE_PENDING = '09';
const PAYEX_AUTH_CODE_PENDING_2 = '99';
const DIRECT_DEBIT = 'Direct Debit';
const AUTO_DEBIT = 'Auto Debit';
const DIRECT_DEBIT_AUTHORIZATION = 'Mandate - Authorization';
const DIRECT_DEBIT_APPROVAL = 'Mandate - Approval';
const AUTO_DEBIT_AUTHORIZATION = 'Auto Debit - Authorization';

add_action('plugins_loaded', 'woocommerce_payex_init', 0);

function woocommerce_payex_init()
{
    if (!class_exists('WC_Payment_Gateway'))
    {
        return;
    }

    class WC_Payex extends WC_Payment_Gateway
    {
        const API_URL = 'https://api.payex.io/';
        const API_URL_SANDBOX = 'https://sandbox-payexapi.azurewebsites.net/';
        const API_GET_TOKEN_PATH = 'api/Auth/Token';
        const API_PAYMENT_FORM = 'api/v1/PaymentIntents';
        const API_MANDATE_FORM = 'api/v1/Mandates';
        const API_COLLECTIONS = 'api/v1/Mandates/Collections';
        const API_CHARGES = 'api/v1/Transactions/Charges';
        const API_QUERY = 'api/v1/Transactions';
        const HOOK_NAME = 'payex_hook';

        protected $visibleSettings = array(
            'enabled',
            'title',
            'description',
            'email',
            'secret_key',
            'testmode',
            'button'
        );

        public $form_fields = array();

        public $supports = array(
            'products'
        );

        /**
         * Can be set to true if you want payment fields
         * to show on the checkout (if doing a direct integration).
         * @var boolean
         */
        public $has_fields = false;

        /**
         * Unique ID for the gateway
         * @var string
         */
        public $id = 'payex';

        /**
         * Title of the payment method shown on the admin page.
         * @var string
         */
        public $method_title = 'Payex';


        /**
         * Description of the payment method shown on the admin page.
         * @var  string
         */
        public $method_description = 'Accept Online Banking, Cards, EWallets, Instalments, and Subscription payments using Payex';

        /**
         * Icon URL, set in constructor
         * @var string
         */
        public $icon;

        /**
         * TODO: Remove usage of $this->msg
         */
        protected $msg = array(
            'message'   =>  '',
            'class'     =>  '',
        );

        /**
         * Return Wordpress plugin settings
         * @param  string $key setting key
         * @return mixed setting value
         */
        public function getSetting($key)
        {
            return $this->get_option($key);
        }

        /**
         * @param boolean $hooks Whether or not to
         *                       setup the hooks on
         *                       calling the constructor
         */
        public function __construct($hooks = true)
        {
            $this->icon =  "https://payexpublic.blob.core.windows.net/storage/payex_woocommerce.jpg";

            $this->init_form_fields();
            $this->init_settings();

            if ($hooks)
            {
                $this->initHooks();
            }

            $this->title = $this->getSetting('title');
        }

        protected function initHooks()
        {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
            add_action('woocommerce_thankyou_' . $this->id, array(&$this, 'redirect'));
            add_action('woocommerce_api_wc_payex_gateway', array(&$this, 'webhook'));
            if (class_exists('WC_Subscriptions_Order'))
            {
                add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2);
                add_action('woocommerce_subscription_failing_payment_method_updated_' . $this->id, array( $this, 'update_failing_payment_method' ), 10, 2);
                add_action('wcs_resubscribe_order_created', array( $this, 'delete_resubscribe_meta' ), 10);
                add_action('wcs_renewal_order_created', array( $this, 'delete_renewal_meta' ), 10);
            }
        }

        //Woocommerce payment page
        public function init_form_fields()
        {
            $defaultFormFields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'label' => 'Enable Payex Payment Gateway',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no',
                ) ,
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout',
                    'default' => 'Payex',
                ) ,
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout',
                    'default' => 'Pay via Payex using Online Banking, Cards, EWallets and Instalments',
                ) ,
                'button' => array(
                    'title' => 'Order Button Text',
                    'type' => 'text',
                    'description' => 'This controls the order button text which the user sees during checkout',
                    'default' => 'Pay via Payex',
                ) ,
                'testmode' => array(
                    'title' => 'Sandbox Environment',
                    'label' => 'Enable sandbox environment',
                    'type' => 'checkbox',
                    'description' => 'Test our payment gateway in the sandbox environment using the sandbox Secret and the same email address',
                    'default' => 'no',
                    'desc_tip' => true,
                ) ,
                'email' => array(
                    'title' => 'Payex Email',
                    'type' => 'text',
                    'description' => 'This email where by you used to sign up and login to Payex Portal',
                    'default' => null,
                ) ,
                'secret_key' => array(
                    'title' => 'Payex Secret',
                    'type' => 'password',
                    'description' => 'This secret should be used when you are ready to go live. Obtain the secret from Payex Portal',
                ) ,
            );

            foreach ($defaultFormFields as $key => $value)
            {
                if (in_array($key, $this->visibleSettings, true))
                {
                    $this->form_fields[$key] = $value;
                }
            }
        }

        public function admin_options()
        {
            echo '<h3>'.__('Payex Payment Gateway', $this->id) . '</h3>';
            echo '<p>'.__('Accept Online Banking, Cards, EWallets, Instalments and Subscriptions using Payex Payment Gateway (https://www.payex.io/)') . '</p>';
            echo '<table class="form-table">';

            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }

        public function get_description()
        {
            return $this->getSetting('description');
        }

        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id)
        {
            global $woocommerce, $MVX;

            // we need it to get any order details.
            $order = wc_get_order($order_id);
            $url = self::API_URL;

            if ($this->get_option('testmode') === 'yes')
            {
                $url = self::API_URL_SANDBOX;
            }

            $token = $this->get_payex_token($url);

            if ($token)
            {
                // generate payex payment link.
                $split_list = array();
                if (MVX_Payex_Checkout_Gateway_Dependencies::mvx_active_check()) {
                    $split_list = $this->create_transaction_from_order($order_id);
                }
                if (class_exists('WC_Subscriptions_Order') && WC_Subscriptions_Order::order_contains_subscription($order_id))
                {
                    $payment_link = $this->get_payex_mandate_link($url, $order, WC()->api_request_url(get_class($this)) , $token);
                }
                else
                {
                    $payment_link = $this->get_payex_payment_link($url, $order, WC()->api_request_url(get_class($this)) , $token, $split_list);
                }
                
                wp_schedule_single_event( time() + (10 * MINUTE_IN_SECONDS), 'woocommerce_query_payex_payment_status', array( $order_id ) );

                // Redirect to checkout page on Payex.
                return array(
                    'result' => 'success',
                    'redirect' => $payment_link,
                );
            }
            else
            {
                wc_add_notice('Payment gateway is temporary down, we are checking on it, please try again later.', 'error');
                return;
            }
        }

                /**
         * Webhook
         */
        public function webhook()
        {
            $verified = $this->verify_payex_response($_POST); // phpcs:ignore
            if ($verified && isset($_POST['reference_number']) && 
                (isset($_POST['auth_code']) || isset($_POST['approval_status']) || isset($_POST['collection_status'])))
            { 
                // phpcs:ignore
                if (isset($_POST['collection_status']))
                {
                    $order = wc_get_order(sanitize_text_field(wp_unslash($_POST['collection_reference_number']))); // phpcs:ignore
                    $response_code = sanitize_text_field(wp_unslash($_POST['collection_status'])); // phpcs:ignore
                }
                else if (isset($_POST['approval_status']))
                {
                    $order = wc_get_order(sanitize_text_field(wp_unslash($_POST['reference_number']))); // phpcs:ignore
                    $response_code = sanitize_text_field(wp_unslash($_POST['approval_status'])); // phpcs:ignore
                }
                else
                {
                    $order = wc_get_order(sanitize_text_field(wp_unslash($_POST['reference_number']))); // phpcs:ignore
                    $response_code = sanitize_text_field(wp_unslash($_POST['auth_code'])); // phpcs:ignore
                }

                $txn_id = sanitize_text_field(wp_unslash($_POST['txn_id']));
                $txn_type = sanitize_text_field(wp_unslash($_POST['txn_type']));
                $mandate_number = sanitize_text_field(wp_unslash($_POST['mandate_reference_number']));

                $this->complete_payment($order, $txn_id, $mandate_number, $txn_type, $response_code);
            }
        }

        /**
         * Redirect page
         */
        public function redirect($order_id)
        {
            $updated = $this->query_payex_payment_status($order_id);
            if (!$updated)
                wp_schedule_single_event( time() + (3 * MINUTE_IN_SECONDS), 'woocommerce_query_payex_payment_status', array($order_id) );
        }

        /**
         * Generate Payment form link to allow users to Pay
         *
         * @param  string      $url             Payex API URL.
         * @param  string      $order           Customer order.
         * @param  string      $callback_url    Callback URL when customer completed payment.
         * @param  string|null $token           Payex token.
         * @return string
         */
        private function get_payex_payment_link($url, $order, $callback_url, $token = null, $split_list)
        {
            $order_data = $order->get_data();
            $order_items = $order->get_items();
            $accept_url = $this->get_return_url($order);
            $reject_url = $order->get_checkout_payment_url();
            if (!$token)
            {
                $token = $this->getToken() ['token'];
            }

            $items = array();

            foreach ($order_items as $item_id => $item)
            {
                // order item data as an array
                $item_data = $item->get_data();
                array_push($items, $item_data);
            }

            if ($token)
            {
                $body = wp_json_encode(array(
                    array(
                        "amount" => round($order_data['total'] * 100, 0) ,
                        "currency" => $order_data['currency'],
                        "customer_id" => $order_data['customer_id'],
                        "description" => 'Payment for Order Reference:' . $order_data['order_key'],
                        "reference_number" => $order_data['id'],
                        "customer_name" => $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'],
                        "contact_number" => $order_data['billing']['phone'],
                        "email" => $order_data['billing']['email'],
                        "address" => $order_data['billing']['company'] . ' ' . $order_data['billing']['address_1'] . ',' . $order_data['billing']['address_2'],
                        "postcode" => $order_data['billing']['postcode'],
                        "city" => $order_data['billing']['city'],
                        "state" => $order_data['billing']['state'],
                        "country" => $order_data['billing']['country'],
                        "shipping_name" => $order_data['shipping']['first_name'] . ' ' . $order_data['shipping']['last_name'],
                        "shipping_address" => $order_data['shipping']['company'] . ' ' . $order_data['shipping']['address_1'] . ',' . $order_data['shipping']['address_2'],
                        "shipping_postcode" => $order_data['shipping']['postcode'],
                        "shipping_city" => $order_data['shipping']['city'],
                        "shipping_state" => $order_data['shipping']['state'],
                        "shipping_country" => $order_data['shipping']['country'],
                        "return_url" => $accept_url,
                        "accept_url" => $accept_url,
                        "reject_url" => $reject_url,
                        "callback_url" => $callback_url,
                        "items" => $items,
                        "source" => "wordpress",
                        "splits" => $split_list
                    )
                ));

                $request = wp_remote_post($url . self::API_PAYMENT_FORM, array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $token,
                    ) ,
                    'cookies' => array() ,
                    'body' => $body
                ));

                if (is_wp_error($request) || 200 !== wp_remote_retrieve_response_code($request))
                {
                    error_log(print_r($request, true));
                }
                else
                {
                    $response = wp_remote_retrieve_body($request);
                    $response = json_decode($response, true);
                    if ($response['status'] == '99' || count($response['result']) == 0) error_log(print_r($request, true));
                    return $response['result'][0]['url'];
                }
            }

            return false;
        }

        public function create_transaction_from_order($order_id) {
            $receivers = array();
            
            if(MVX_Payex_Checkout_Gateway_Dependencies::mvx_active_check()) {
                $suborders_list = get_mvx_suborders( $order_id ); 
                $count = 1;
                if( $suborders_list ) {
                    foreach( $suborders_list as $suborder ) {
                        $vendor = get_mvx_vendor( get_post_field( 'post_author', $suborder->get_id() ) );
                        $vendor_payment_method = get_user_meta( $vendor->id, '_vendor_payment_mode', true );
                        $vendor_payex_email = get_the_author_meta( 'user_email', $vendor->id ); 
                        $vendor_payment_method_check = $vendor_payment_method == 'payex' ? true : false;
                        $payex_enabled = apply_filters('mvx_payex_enabled', $vendor_payment_method_check);
                        $vendor_order = mvx_get_order( $suborder->get_id() );
                        $vendor_commission = round( $vendor_order->get_commission_total( 'edit' ), 2 );
                        
                        if ($vendor_commission > 0) {
                            $receiver = new stdClass(); 
                            $receiver->amount = round($vendor_commission * 100, 0);
                            $receiver->split_type = "abs";
                            $receiver->destination = $vendor_payex_email;
                            $receiver->type = "platform";
                            $receiver->description = "Vendor commission fees";
                            array_push($receivers, $receiver);
                        }
                    }
                }
            }
            return $receivers;
        }

        /**
         * Generate Mandate form link to allow users to Pay
         *
         * @param  string      $url             Payex API URL.
         * @param  string      $order           Customer order.
         * @param  string      $callback_url    Callback URL when customer completed payment.
         * @param  string|null $token           Payex token.
         * @return string
         */
        private function get_payex_mandate_link($url, $order, $callback_url, $token = null)
        {
            $order_data = $order->get_data();
            $order_items = $order->get_items();
            $accept_url = $this->get_return_url($order);
            $reject_url = $order->get_checkout_payment_url();

            if (!$token)
            {
                $token = $this->getToken() ['token'];
            }

            $items = array();
            $metadata = array();
            $autoDebit = false;
            $cutoff = date('Y-m-d', strtotime(date('Y-m-d')."+2weekdays"));

            foreach ($order_items as $item_id => $item)
            {
                // order item data as an array
                $item_data = $item->get_data();
                array_push($items, $item_data);

                $product_id = $item_data['product_id'];
                if (WC_Subscriptions_Product::is_subscription($product_id))
                {
                    $metadata[$product_id] = array(
                        "price" => get_post_meta($product_id, '_subscription_price', true),
                        "sign_up_fee" => get_post_meta($product_id, '_subscription_sign_up_fee', true),
                        "period" => get_post_meta($product_id, '_subscription_period', true),
                        "interval" => get_post_meta($product_id, '_subscription_period_interval', true),
                        "length" => get_post_meta($product_id, '_subscription_length', true),
                        "trial_period" => get_post_meta($product_id, '_subscription_trial_period', true),
                        "trial_length" => get_post_meta($product_id, '_subscription_trial_length', true),
                        "sync_date" => get_post_meta($product_id, '_subscription_payment_sync_date', true),
                        "type" => 'product'
                    );
                }
            }

            $subscriptions = wcs_get_subscriptions_for_order($order->get_id());

            foreach ($subscriptions as $subscription) 
            {
                $subscription_id = $subscription->get_id();
                $next = get_post_meta($subscription_id, '_schedule_next_payment', true);
                if (date('Y-m-d', strtotime($next)) < $cutoff) $autoDebit = true;
                $metadata[$subscription_id] = array(
                    "next" => $next,
                    "type" => 'subscription'
                );
            }

            $initial_payment = WC_Subscriptions_Order::get_total_initial_payment($order);
            $amount = WC_Subscriptions_Order::get_recurring_total($order);

            if ($initial_payment > 0 || $autoDebit) $debit_type = "AD";

            if ($token)
            {
                $body = wp_json_encode(array(
                    array(
                        "max_amount" => max(3000000, round($amount * 100, 0)) ,
                        "initial_amount" => round($initial_payment * 100, 0) ,
                        "currency" => $order_data['currency'],
                        "customer_id" => $order_data['customer_id'],
                        "purpose" => 'Payment for Order Reference:' . $order_data['order_key'],
                        "merchant_reference_number" => $order_data['id'],
                        "frequency" => 'DL',
                        "effective_date" => date("Ymd"),
                        "max_frequency" => 999,
                        "debit_type" => $debit_type,
                        "auto" => false,
                        "customer_name" => $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'],
                        "contact_number" => $order_data['billing']['phone'],
                        "email" => $order_data['billing']['email'],
                        "address" => $order_data['billing']['company'] . ' ' . $order_data['billing']['address_1'] . ',' . $order_data['billing']['address_2'],
                        "postcode" => $order_data['billing']['postcode'],
                        "city" => $order_data['billing']['city'],
                        "state" => $order_data['billing']['state'],
                        "country" => $order_data['billing']['country'],
                        "shipping_name" => $order_data['shipping']['first_name'] . ' ' . $order_data['shipping']['last_name'],
                        "shipping_address" => $order_data['shipping']['company'] . ' ' . $order_data['shipping']['address_1'] . ',' . $order_data['shipping']['address_2'],
                        "shipping_postcode" => $order_data['shipping']['postcode'],
                        "shipping_city" => $order_data['shipping']['city'],
                        "shipping_state" => $order_data['shipping']['state'],
                        "shipping_country" => $order_data['shipping']['country'],
                        "return_url" => $accept_url,
                        "accept_url" => $accept_url,
                        "reject_url" => $reject_url,
                        "callback_url" => $callback_url,
                        "items" => $items,
                        "metadata" => $metadata,
                        "source" => "wordpress"
                    )
                ));

                $request = wp_remote_post($url . self::API_MANDATE_FORM, array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $token,
                    ) ,
                    'cookies' => array() ,
                    'body' => $body
                ));

                if (is_wp_error($request) || 200 !== wp_remote_retrieve_response_code($request))
                {
                    error_log(print_r($request, true));
                }
                else
                {
                    $response = wp_remote_retrieve_body($request);
                    $response = json_decode($response, true);
                    if ($response['status'] == '99' || count($response['result']) == 0) error_log(print_r($request, true));
                    return $response['result'][0]['url'];
                }
            }

            return false;
        }

        /**
         * process_subscription_payment function.
         * @param mixed $order
         * @param int $amount (default: 0)
         */
        public function process_subscription_payment($amount_to_charge, $renewal_order) 
        {
            $url = self::API_URL;

            if ($this->get_option('testmode') === 'yes')
            {
                $url = self::API_URL_SANDBOX;
            }

            $token = $this->get_payex_token($url);

            if ($token)
            {
                $order_id = $renewal_order->get_id();
                $subscription_id = get_post_meta($order_id, '_subscription_renewal', true);
                $subscription_order = wc_get_order($subscription_id);
                $parent_id = $subscription_order->get_parent_id();
                $mandate_number = get_post_meta($parent_id, 'payex_mandate_number', true);
                $txn_type = get_post_meta($parent_id, 'payex_txn_type', true);

                $body = wp_json_encode(array(
                    array(
                        "reference_number" => $mandate_number,
                        "collection_reference_number" => $order_id,
                        "amount" => round($amount_to_charge * 100, 0) ,
                        "collection_date" => date("Ymd")
                    )
                ));

                $request = wp_remote_post($url . self::API_COLLECTIONS, array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $token,
                    ) ,
                    'cookies' => array() ,
                    'body' => $body
                ));

                if (is_wp_error($request) || 200 !== wp_remote_retrieve_response_code($request))
                {
                    $renewal_order->update_status('failed', 'Invalid Request');
                    error_log(print_r($request, true));
                }
                else
                {
                    $response = wp_remote_retrieve_body($request);
                    $response = json_decode($response, true);
                    if ($response['status'] == '99' || count($response['result']) == 0 || (count($response['result']) != 0 && $response['result'][0]['status'] == '99'))
                    {
                        $error = $response['message'];
                        if (count($response['result']) != 0) $error = $response['result'][0]['error'];
                        $renewal_order->update_status('failed', $error);
                        error_log(print_r($error, true));
                    }

                    $collection_number = $response['result'][0]['collection_number'];

                    update_post_meta($order_id, 'payex_collection_number', $collection_number);

                    // if auto debit, charge immediately
                    if ($txn_type != DIRECT_DEBIT)
                    {
                        $renewal_order->add_order_note( 'Auto Debit charge initiated', false );

                        $request = wp_remote_post($url . self::API_CHARGES, array(
                            'method' => 'POST',
                            'timeout' => 45,
                            'headers' => array(
                                'Content-Type' => 'application/json',
                                'Authorization' => 'Bearer ' . $token,
                            ) ,
                            'cookies' => array() ,
                            'body' => wp_json_encode(array(
                                'collection_number' => $collection_number
                            ))
                        ));

                        $response = wp_remote_retrieve_body($request);
                        $response = json_decode($response, true);

                        $this->complete_payment(
                            $renewal_order, 
                            $response['txn_id'], 
                            $response['mandate_reference_number'], 
                            $response['txn_type'], 
                            $response['auth_code']
                        );
                    }
                    else
                    {
                        $renewal_order->add_order_note( 'Direct Debit charge initiated, pending bank approval. Please do not make any changes to avoid duplicate charges', false );
                    }
                }
            }
            else
            {
                $renewal_order->update_status('failed', 'Invalid Token');
                error_log(print_r($request, true));
            }
        }

        /**
         * scheduled_subscription_payment function.
         *
         * This function is called when renewal order is triggered.
         *
         * @param $amount_to_charge float The amount to charge.
         * @param $renewal_order WC_Order A WC_Order object created to record the renewal payment.
         */
        public function scheduled_subscription_payment($amount_to_charge, $renewal_order)
        {
            $this->process_subscription_payment( $amount_to_charge, $renewal_order );
        }

        /**
         * @param int $resubscribe_order The order created for the customer to resubscribe to the old expired/cancelled subscription
         */
        public function delete_resubscribe_meta( $resubscribe_order ) 
        {
            $this->delete_renewal_meta( $resubscribe_order );
        }

        /**
         * @param int $resubscribe_order The order created for the customer to resubscribe to the old expired/cancelled subscription
         */
        public function delete_renewal_meta( $renewal_order ) 
        {
            return $renewal_order;
        }

        /**
         * an automatic renewal payment which previously failed.
         *
         * @access public
         * @param WC_Subscription $subscription The subscription for which the failing payment method relates.
         * @param WC_Order $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
         * @return void
         */
        public function update_failing_payment_method( $subscription, $renewal_order ) 
        {
        }

        /**
         * Get Payex Token
         *
         * @param   string $url  Payex API Url.
         * @return bool|mixed
         */
        private function get_payex_token($url)
        {
            $email = $this->get_option('email');
            $secret = $this->get_option('secret_key');

            $request = wp_remote_post($url . self::API_GET_TOKEN_PATH, array(
                'method' => 'POST',
                'timeout' => 45,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($email . ':' . $secret) ,
                ) ,
                'cookies' => array() ,
            ));

            if (is_wp_error($request) || 200 !== wp_remote_retrieve_response_code($request))
            {
                error_log(print_r($request, true));
            }
            else
            {
                $response = wp_remote_retrieve_body($request);
                $response = json_decode($response, true);
                return $response['token'];
            }
            return false;
        }

        /**
         * Verify Response
         *
         * Used to verify response data integrity
         * Signature: implode all returned data pipe separated then hash with sha512
         *
         * @param  array $response  Payex response after checkout.
         * @return bool
         */
        public function verify_payex_response($response)
        {
            if (isset($response['signature']) && isset($response['txn_id']))
            {
                ksort($response); // sort array keys ascending order.
                $host_signature = sanitize_text_field(wp_unslash($response['signature']));
                $signature = $this->get_option('secret_key') . '|' . sanitize_text_field(wp_unslash($response['txn_id'])); // append secret key infront.
                $hash = hash('sha512', $signature);

                if ($hash == $host_signature)
                {
                    return true;
                }
            }
            return false;
        }
        
        /*
         * Check Payment Status if status still pending
         *
         * @param  string      $order           Customer order.
         * @return bool
         */
        public function query_payex_payment_status($order_id)
        {
            $order = wc_get_order($order_id);
            
            if (!$order->is_paid())
            {
                $url = self::API_URL;
                
                if ($this->get_option('testmode') === 'yes')
                {
                    $url = self::API_URL_SANDBOX;
                }
                
                $token = $this->get_payex_token($url);
                
                if ($token)
                {
                    $request = wp_remote_get($url . self::API_QUERY . '?status=sales&reference_number=' . $order_id, array(
                        'method' => 'GET',
                        'timeout' => 45,
                        'headers' => array(
                            'Content-Type' => 'application/json',
                            'Authorization' => 'Bearer ' . $token,
                        ),
                    ));
                    
                    if (is_wp_error($request) || 200 !== wp_remote_retrieve_response_code($request))
                    {
                        $order->update_status('failed', 'Invalid Request');
                        error_log(print_r($request, true));
                    }
                    else
                    {
                        $response = wp_remote_retrieve_body($request);
                        $response = json_decode($response, true);
                    
                        if ($response['status'] == '00' && count($response['result']) > 0)
                        {
                            $txn_id = $response['result'][0]['txn_id'];
                            $mandate_number = $response['result'][0]['mandate_reference_number'];
                            $txn_type = $response['result'][0]['txn_type'];
                            $response_code = $response['result'][0]['auth_code'];
                            $this->complete_payment($order, $txn_id, $mandate_number, $txn_type, $response_code);
                            return true;
                        }
                    }
                }
                return false;
            }
            return true;
        }

        /**
         * Generate Payment form link to allow users to Pay
         *
         * @param  string      $order           Customer order.
         * @param  string      $response        Payex response.
         * @param  string      $response_code   Payex response code.
         */
        private function complete_payment($order, $txn_id, $mandate_number, $txn_type, $response_code)
        {
            // verify the payment is successful.
            if (PAYEX_AUTH_CODE_SUCCESS == $response_code)
            {
                if ($txn_type == DIRECT_DEBIT_AUTHORIZATION || $txn_type == DIRECT_DEBIT_APPROVAL)
                {
                    update_post_meta($order->get_id() , 'payex_txn_type', DIRECT_DEBIT);
                }
                else if ($txn_type == AUTO_DEBIT_AUTHORIZATION)
                {
                    update_post_meta($order->get_id() , 'payex_txn_type', AUTO_DEBIT);
                }
                else
                {
                    update_post_meta($order->get_id() , 'payex_txn_type', $txn_type);
                }
                
                if ($mandate_number) update_post_meta($order->get_id(), 'payex_mandate_number', $mandate_number);
                
                if (!$order->is_paid())
                {
                    // only mark order as completed if the order was not paid before.
                    $order->payment_complete($txn_id);
                    wc_reduce_stock_levels($order->get_id());
                    $order->add_order_note( 'Payment completed via Payex (Txn ID: '.$txn_id.')', false );
                }
                
                if (class_exists('WC_Subscriptions_Order') && WC_Subscriptions_Order::order_contains_subscription($order->get_id()))
                {
                    WC_Subscriptions_Manager::activate_subscriptions_for_order($order);
                }

                if ($txn_type == DIRECT_DEBIT_AUTHORIZATION)
                {
                    $order->add_order_note( 'Mandate ('.$mandate_number.') authorized by customer, pending bank approval', false );
                }
                else if ($txn_type == DIRECT_DEBIT_APPROVAL)
                {
                    $order->add_order_note( 'Mandate ('.$mandate_number.') approved by bank', false );
                }
                else if ($txn_type == DIRECT_DEBIT)
                {
                    $order->add_order_note( 'Direct Debit collection approved by bank', false );
                }
            }
            else 
            {
                $order->add_order_note( 'Payex Payment failed with Response Code: ' . $response_code, false );
            }
        }
    }

    /*
    * Check Payment Status if status still pending
    *
    * @param  string      $order           Customer order.
    */
    function query_payex_payment_status($order_id, $attempts = 0)
    {
        if ($attempts <= 10) 
        {
            $gateway = new WC_PAYEX_GATEWAY();
            $updated = $gateway->query_payex_payment_status($order_id);
            if (!$updated)
                wp_schedule_single_event( time() + (30 * MINUTE_IN_SECONDS), 'woocommerce_query_payex_payment_status', array($order_id, ++$attempts) );
        }
    }


    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_payex_gateway($methods)
    {
        $methods[] = 'WC_Payex';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_payex_gateway' );
}

// This is set to a priority of 10
function payex_webhook_init()
{
    $verified = $this->verify_payex_response($_POST); // phpcs:ignore
    if ($verified && isset($_POST['reference_number']) && 
        (isset($_POST['auth_code']) || isset($_POST['approval_status']) || isset($_POST['collection_status'])))
    { 
        // phpcs:ignore
        if (isset($_POST['collection_status']))
        {
            $order = wc_get_order(sanitize_text_field(wp_unslash($_POST['collection_reference_number']))); // phpcs:ignore
            $response_code = sanitize_text_field(wp_unslash($_POST['collection_status'])); // phpcs:ignore
        }
        else if (isset($_POST['approval_status']))
        {
            $order = wc_get_order(sanitize_text_field(wp_unslash($_POST['reference_number']))); // phpcs:ignore
            $response_code = sanitize_text_field(wp_unslash($_POST['approval_status'])); // phpcs:ignore
        }
        else
        {
            $order = wc_get_order(sanitize_text_field(wp_unslash($_POST['reference_number']))); // phpcs:ignore
            $response_code = sanitize_text_field(wp_unslash($_POST['auth_code'])); // phpcs:ignore
        }

        $txn_id = sanitize_text_field(wp_unslash($_POST['txn_id']));
        $txn_type = sanitize_text_field(wp_unslash($_POST['txn_type']));
        $mandate_number = sanitize_text_field(wp_unslash($_POST['mandate_reference_number']));

        $this->complete_payment($order, $txn_id, $mandate_number, $txn_type, $response_code);
    }
}
