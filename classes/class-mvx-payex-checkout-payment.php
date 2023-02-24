<?php
// require_once __DIR__.'/../includes/payex-webhook.php';
// require_once __DIR__.'/../payex-sdk/Payex.php';
// require_once ABSPATH . 'wp-admin/includes/plugin.php';

// use Payex\Api\Api;
// use Payex\Api\Errors;

add_action('plugins_loaded', 'woocommerce_payex_init', 0);
add_action('admin_post_nopriv_rzp_wc_webhook', 'payex_webhook_init', 10);

function woocommerce_payex_init()
{
    if (!class_exists('WC_Payment_Gateway'))
    {
        return;
    }

    class WC_Payex extends WC_Payment_Gateway
    {
        // This one stores the WooCommerce Order Id
        const SESSION_KEY                    = 'payex_wc_order_id';
        const PAYEX_PAYMENT_ID            = 'payex_payment_id';
        const PAYEX_ORDER_ID              = 'payex_order_id';
        const PAYEX_SIGNATURE             = 'payex_signature';
        const PAYEX_WC_FORM_SUBMIT        = 'payex_wc_form_submit';

        const INR                            = 'INR';
        const CAPTURE                        = 'capture';
        const AUTHORIZE                      = 'authorize';
        const WC_ORDER_ID                    = 'woocommerce_order_id';

        const DEFAULT_LABEL                  = 'OnlineBanking/Cards/EWallets/Instalments/Subscription';
        const DEFAULT_DESCRIPTION            = 'Accept Online Banking, Cards, EWallets, Instalments, and Subscription payments using Payex';
        const DEFAULT_SUCCESS_MESSAGE        = 'Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon.';

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
            'products',
            'refunds'
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

        protected function getCustomOrdercreationMessage()
        {
            $message =  $this->getSetting('order_success_message');
            if (isset($message) === false)
            {
                $message = STATIC::DEFAULT_SUCCESS_MESSAGE;
            }
            return $message;
        }

        /**
         * @param boolean $hooks Whether or not to
         *                       setup the hooks on
         *                       calling the constructor
         */
        public function __construct($hooks = true)
        {
            $this->icon =  "https://cdn.razorpay.com/static/assets/logo/payment.svg"; //TODO

            $this->init_form_fields();
            $this->init_settings();

            // TODO: This is hacky, find a better way to do this
            // See mergeSettingsWithParentPlugin() in subscriptions for more details.
            if ($hooks)
            {
                $this->initHooks();
            }

            $this->title = $this->getSetting('title');
        }

        protected function initHooks()
        {
            add_action('init', array(&$this, 'check_payex_response'));

            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

            add_action('woocommerce_api_' . $this->id, array($this, 'check_payex_response'));

            $cb = array($this, 'process_admin_options');

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
            {
                add_action("woocommerce_update_options_payment_gateways_{$this->id}", $cb);
            }
            else
            {
                add_action('woocommerce_update_options_payment_gateways', $cb);
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
         * Receipt Page
         * @param string $orderId WC Order Id
         **/
        function receipt_page($orderId)
        {
            echo $this->generate_payex_form($orderId);
        }

        /**
         * Returns key to use in session for storing Payex order Id
         * @param  string $orderId Payex Order Id
         * @return string Session Key
         */
        protected function getOrderSessionKey($orderId)
        {
            return self::PAYEX_ORDER_ID . $orderId;
        }

        /**
         * Given a order Id, find the associated
         * Payex Order from the session and verify
         * that is is still correct. If not found
         * (or incorrect), create a new Payex Order
         *
         * @param  string $orderId Order Id
         * @return mixed Payex Order Id or Exception
         */
        protected function createOrGetPayexOrderId($orderId)
        {
            global $woocommerce;

            $sessionKey = $this->getOrderSessionKey($orderId);

            $create = false;

            try
            {
                $payexOrderId = $woocommerce->session->get($sessionKey);

                // If we don't have an Order
                // or the if the order is present in session but doesn't match what we have saved
                if (($payexOrderId === null) or
                    (($payexOrderId and ($this->verifyOrderAmount($payexOrderId, $orderId)) === false)))
                {
                    $create = true;
                }
                else
                {
                    return $payexOrderId;
                }
            }
            // Order doesn't exist or verification failed
            // So try creating one
            catch (Exception $e)
            {
                $create = true;
            }

            if ($create)
            {
                try
                {
                    return $this->createPayexOrderId($orderId, $sessionKey);
                }
                // For the bad request errors, it's safe to show the message to the customer.
                catch (Errors\BadRequestError $e)
                {
                    return $e;
                }
                // For any other exceptions, we make sure that the error message
                // does not propagate to the front-end.
                catch (Exception $e)
                {
                    return new Exception("Payment failed");
                }
            }
        }

        /**
         * Returns redirect URL post payment processing
         * @return string redirect URL
         */
        private function getRedirectUrl()
        {
            return add_query_arg( 'wc-api', $this->id, trailingslashit( get_home_url() ) );
        }

        /**
         * Specific payment parameters to be passed to checkout
         * for payment processing
         * @param  string $orderId WC Order Id
         * @return array payment params
         */
        protected function getPayexPaymentParams($orderId)
        {
            $payexOrderId = $this->createOrGetPayexOrderId($orderId);

            if ($payexOrderId === null)
            {
                throw new Exception('PAYEX ERROR: Payex API could not be reached');
            }
            else if ($payexOrderId instanceof Exception)
            {
                $message = $payexOrderId->getMessage();

                throw new Exception("PAYEX ERROR: Order creation failed with the message: '$message'.");
            }

            return [
                'order_id'  =>  $payexOrderId
            ];
        }

        /**
         * Generate payex button link
         * @param string $orderId WC Order Id
         **/
        public function generate_payex_form($orderId)
        {
            $order = new WC_Order($orderId);

            try
            {
                $params = $this->getPayexPaymentParams($orderId);
            }
            catch (Exception $e)
            {
                return $e->getMessage();
            }

            $checkoutArgs = $this->getCheckoutArguments($order, $params);

            $html = '<p>'.__('Thank you for your order, please click the button below to pay with Payex.', $this->id).'</p>';

            $html .= $this->generateOrderForm($checkoutArgs);

            return $html;
        }

        /**
         * default parameters passed to checkout
         * @param  WC_Order $order WC Order
         * @return array checkout params
         */
        private function getDefaultCheckoutArguments($order)
        {
            global $MVX_Payex_Checkout_Gateway;
            $callbackUrl = $this->getRedirectUrl();

            $orderId = $order->get_order_number();

            $productinfo = "Order $orderId";
            return array(
                'key'          => $this->getSetting('key_id'),
                'name'         => get_bloginfo('name'),
                'currency'     => self::INR,
                'description'  => $productinfo,
                'notes'        => array(
                    'woocommerce_order_id' => $orderId
                ),
                'callback_url' => $callbackUrl,
                'prefill'      => $this->getCustomerInfo($order),
                '_'            => array(
                    'integration'                   => 'woocommerce',
                    'integration_version'           => $MVX_Payex_Checkout_Gateway->version,
                    'integration_parent_version'    => WOOCOMMERCE_VERSION,
                ),
            );
        }

        /**
         * @param  WC_Order $order
         * @return string currency
         */
        private function getOrderCurrency($order)
        {
            if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>='))
            {
                return $order->get_currency();
            }

            return $order->get_order_currency();
        }

        /**
         * Returns array of checkout params
         */
        private function getCheckoutArguments($order, $params)
        {
            $args = $this->getDefaultCheckoutArguments($order);

            $currency = $this->getOrderCurrency($order);

            // The list of valid currencies is at https://payex.freshdesk.com/support/solutions/articles/11000065530-what-currencies-does-payex-support-

            $args = array_merge($args, $params);

            return $args;
        }

        public function getCustomerInfo($order)
        {
            if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>='))
            {
                $args = array(
                    'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email'   => $order->get_billing_email(),
                    'contact' => $order->get_billing_phone(),
                );
            }
            else
            {
                $args = array(
                    'name'    => $order->billing_first_name . ' ' . $order->billing_last_name,
                    'email'   => $order->billing_email,
                    'contact' => $order->billing_phone,
                );
            }

            return $args;
        }

        public function create_transaction_from_order($orderId = '') {
            $receivers = array();
            if(MVX_Payex_Checkout_Gateway_Dependencies::mvx_active_check()) {
                $suborders_list = get_mvx_suborders( $orderId ); 
                if( $suborders_list ) {
                    foreach( $suborders_list as $suborder ) {
                        $vendor = get_mvx_vendor( get_post_field( 'post_author', $suborder->get_id() ) );
                        $vendor_payment_method = get_user_meta( $vendor->id, '_vendor_payment_mode', true );
                        $vendor_payex_account_id = get_user_meta( $vendor->id, '_vendor_payex_account_id', true );
                        $vendor_payment_method_check = $vendor_payment_method == 'payex' ? true : false;
                        $payex_enabled = apply_filters('mvx_payex_enabled', $vendor_payment_method_check);
                        if ( $payex_enabled && $vendor_payex_account_id ) {
                            $vendor_order = mvx_get_order( $suborder->get_id() );
                            $vendor_commission = round( $vendor_order->get_commission_total( 'edit' ), 2 );
                            $commission_id = get_post_meta($suborder->get_id(), '_commission_id', true) ? get_post_meta($suborder->get_id(), '_commission_id') : array();
                            if ($vendor_commission > 0 && $commission_id) {
                                $receivers[$vendor->id] = $commission_id;
                            }
                        }
                    }
                }
            }
            return $receivers;
        }

        private function getOrderCreationData($orderId)
        {
            $order = new WC_Order($orderId);

            $data = array(
                'receipt'         => $orderId,
                'amount'          => (int) round($order->get_total() * 100),
                'currency'        => $this->getOrderCurrency($order),
                'payment_capture' => ($this->getSetting('payment_action') === self::AUTHORIZE) ? 0 : 1,
                'app_offer'       => ($order->get_discount_total() > 0) ? 1 : 0,
                'notes'           => array(
                    self::WC_ORDER_ID  => (string) $orderId,
                ),
            );

            if (MVX_Payex_Checkout_Gateway_Dependencies::mvx_active_check()) {
                $is_split = get_mvx_vendor_settings('is_split', 'payment_payex');
                if (!empty($is_split)) {
                    $payment_distribution_list = $this->generate_payment_distribution_list($orderId);
                    if( isset( $payment_distribution_list['transfers'] ) && !empty( $payment_distribution_list['transfers'] ) && count( $payment_distribution_list['transfers'] ) > 0 ) {
                        $data['transfers'] = $payment_distribution_list['transfers'];
                    }
                }
            }
            
            return $data;
        }

        public function generate_payment_distribution_list($order) {
            $args = array();
            $receivers = array();
            $total_vendor_commission = 0;
            if(MVX_Payex_Checkout_Gateway_Dependencies::mvx_active_check()) {
                $suborders_list = get_mvx_suborders( $order ); 
                if( $suborders_list ) {
                    foreach( $suborders_list as $suborder ) {
                        $vendor = get_mvx_vendor( get_post_field( 'post_author', $suborder->get_id() ) );
                        $vendor_payment_method = get_user_meta( $vendor->id, '_vendor_payment_mode', true );
                        $vendor_payex_account_id = get_user_meta( $vendor->id, '_vendor_payex_account_id', true );
                        $vendor_payment_method_check = $vendor_payment_method == 'payex' ? true : false;
                        $payex_enabled = apply_filters('mvx_payex_enabled', $vendor_payment_method_check);
                        if ( $payex_enabled && $vendor_payex_account_id ) {
                            $vendor_order = mvx_get_order( $suborder->get_id() );
                            $vendor_commission = round( $vendor_order->get_commission_total( 'edit' ), 2 );
                            if ($vendor_commission > 0) {
                                $receivers[] = array(
                                    'account'       => $vendor_payex_account_id,
                                    'amount'        => (float) $vendor_commission * 100,
                                    'currency'      => get_woocommerce_currency(),
                                );
                            }
                        }
                    }
                }
            }
            $args['transfers'] = $receivers;
            return $args;
        }

        /**
         * Gets the Order Key from the Order
         * for all WC versions that we suport
         */
        protected function getOrderKey($order)
        {
            $orderKey = null;

            if (version_compare(WOOCOMMERCE_VERSION, '3.0.0', '>='))
            {
                return $order->get_order_key();
            }

            return $order->order_key;
        }

        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id)
        {
            global $woocommerce;

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
                if (class_exists('WC_Subscriptions_Order') && WC_Subscriptions_Order::order_contains_subscription($order_id))
                {
                    $payment_link = $this->get_payex_mandate_link($url, $order, WC()->api_request_url(get_class($this)) , $token);
                }
                else
                {
                    $payment_link = $this->get_payex_payment_link($url, $order, WC()->api_request_url(get_class($this)) , $token);
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
         * Check for valid payex server callback
         **/
        function check_payex_response()
        {
            global $woocommerce;

            $orderId = $woocommerce->session->get(self::SESSION_KEY);
            $order = new WC_Order($orderId);

            //
            // If the order has already been paid for
            // redirect user to success page
            //
            if ($order->needs_payment() === false)
            {
                $this->redirectUser($order);
            }

            $payexPaymentId = null;

            if ($orderId  and !empty($_POST[self::PAYEX_PAYMENT_ID]))
            {
                $error = "";
                $success = false;

                try
                {
                    $this->verifySignature($orderId);
                    $success = true;
                    $payexPaymentId = sanitize_text_field($_POST[self::PAYEX_PAYMENT_ID]);
                }
                catch (Errors\SignatureVerificationError $e)
                {
                    $error = 'WOOCOMMERCE_ERROR: Payment to Payex Failed. ' . $e->getMessage();
                }
            }
            else
            {
                if($_POST[self::PAYEX_WC_FORM_SUBMIT] ==1)
                {
                    $success = false;
                    $error = 'Customer cancelled the payment';
                }
                else
                {
                    $success = false;
                    $error = "Payment Failed.";
                }

                $this->handleErrorCase($order);
                $this->updateOrder($order, $success, $error, $payexPaymentId, null);

                wp_redirect(wc_get_checkout_url());
                exit;
            }

            $this->updateOrder($order, $success, $error, $payexPaymentId, null);

            $this->redirectUser($order);
        }

        protected function redirectUser($order)
        {
            $redirectUrl = $this->get_return_url($order);

            wp_redirect($redirectUrl);
            exit;
        }

        protected function verifySignature($orderId)
        {
            global $woocommerce;

            $api = $this->getPayexApiInstance();

            $attributes = array(
                self::PAYEX_PAYMENT_ID => $_POST[self::PAYEX_PAYMENT_ID],
                self::PAYEX_SIGNATURE  => $_POST[self::PAYEX_SIGNATURE],
            );

            $sessionKey = $this->getOrderSessionKey($orderId);
            $attributes[self::PAYEX_ORDER_ID] = $woocommerce->session->get($sessionKey);

            $api->utility->verifyPaymentSignature($attributes);
        }

        protected function getErrorMessage($orderId)
        {
            // We don't have a proper order id
            if ($orderId !== null)
            {
                $message = 'An error occured while processing this payment';
            }
            if (isset($_POST['error']) === true)
            {
                $error = $_POST['error'];

                $description = htmlentities($error['description']);
                $code = htmlentities($error['code']);

                $message = 'An error occured. Description : ' . $description . '. Code : ' . $code;

                if (isset($error['field']) === true)
                {
                    $fieldError = htmlentities($error['field']);
                    $message .= 'Field : ' . $fieldError;
                }
            }
            else
            {
                $message = 'An error occured. Please contact administrator for assistance';
            }

            return $message;
        }

        /**
         * Modifies existing order and handles success case
         *
         * @param $success, & $order
         */
        public function updateOrder(& $order, $success, $errorMessage, $payexPaymentId, $virtualAccountId = null, $webhook = false)
        {
            global $woocommerce;

            $orderId = $order->get_order_number();

            if (($success === true) and ($order->needs_payment() === true))
            {
                $this->msg['message'] = $this->getCustomOrdercreationMessage() . "&nbsp; Order Id: $orderId";
                $this->msg['class'] = 'success';

                $order->payment_complete($payexPaymentId);
                $order->add_order_note("Payex payment successful <br/>Payex Id: $payexPaymentId");

                if($virtualAccountId != null)
                {
                    $order->add_order_note("Virtual Account Id: $virtualAccountId");
                }

                if (isset($woocommerce->cart) === true)
                {
                    $woocommerce->cart->empty_cart();
                }
            }
            else
            {
                $this->msg['class'] = 'error';
                $this->msg['message'] = $errorMessage;

                if ($payexPaymentId)
                {
                    $order->add_order_note("Payment Failed. Please check Payex Dashboard. <br/> Payex Id: $payexPaymentId");
                }

                $order->add_order_note("Transaction Failed: $errorMessage<br/>");
                $order->update_status('failed');
            }

            if ($webhook === false)
            {
                $this->add_notice($this->msg['message'], $this->msg['class']);
            }
        }

        protected function handleErrorCase(& $order)
        {
            $orderId = $order->get_order_number();

            $this->msg['class'] = 'error';
            $this->msg['message'] = $this->getErrorMessage($orderId);
        }

        /**
         * Add a woocommerce notification message
         *
         * @param string $message Notification message
         * @param string $type Notification type, default = notice
         */
        protected function add_notice($message, $type = 'notice')
        {
            global $woocommerce;
            $type = in_array($type, array('notice','error','success'), true) ? $type : 'notice';
            // Check for existence of new notification api. Else use previous add_error
            if (function_exists('wc_add_notice'))
            {
                wc_add_notice($message, $type);
            }
            else
            {
                // Retrocompatibility WooCommerce < 2.1
                switch ($type)
                {
                    case "error" :
                        $woocommerce->add_error($message);
                        break;
                    default :
                        $woocommerce->add_message($message);
                        break;
                }
            }
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
        private function get_payex_payment_link($url, $order, $callback_url, $token = null)
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
                        "source" => "wordpress"
                        //need to add list of split object
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
