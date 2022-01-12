<?php
/**
 * PayBox Payment Gateway
 *
 * Provides a PayBox Payment Gateway.
 *
 * @class  woocommerce_paybox
 * @package WooCommerce
 * @category Payment Gateways
 * @author PayBox
 */
class WC_Gateway_PayBox extends WC_Payment_Gateway {

    /**
     * Version
     *
     * @var string
     */
    public $version;

    /**
     * @access protected
     * @var array $data_to_send
     */
    protected $data_to_send = array();

    /**
     * Constructor
     */
    public function __construct() {
        $this->version = WC_GATEWAY_PAYBOX_VERSION;
        $this->id = 'paybox';
        $this->method_title       = __( 'PayBox', 'woocommerce-gateway-paybox' );
        $this->method_description = sprintf( __( 'PayBox works by sending the user to %1$sPayBox%2$s to enter their payment information.', 'woocommerce-gateway-paybox' ), '<a href="https://paybox.money/">', '</a>' );
        $this->icon               = WP_PLUGIN_URL . '/' . plugin_basename( dirname( dirname( __FILE__ ) ) ) . '/assets/images/icon.png';
        $this->debug_email        = get_option('admin_email');
        $this->available_countries  = array('KZ', 'RU', 'KG');
        $this->available_currencies = (array)apply_filters('woocommerce_gateway_paybox_available_currencies', array( 'KZT', 'RUR', 'RUB', 'USD', 'EUR', 'KGS', 'UZS' ) );

        $this->supports = array(
            'products',
            'pre-orders',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change'
        );
        $this->init_form_fields();
        $this->init_settings();

        if ( ! is_admin() ) {
            $this->setup_constants();
        }

        // Setup default merchant data.
        $this->merchant_id      = $this->get_option( 'merchant_id' );
        $this->merchant_key     = $this->get_option( 'merchant_key' );
        $this->pass_phrase      = $this->get_option( 'pass_phrase' );
        $this->title            = $this->get_option( 'title' );
        $this->response_url        = add_query_arg( 'wc-api', 'WC_Gateway_PayBox', home_url( '/' ) );
        $this->send_debug_email = 'yes' === $this->get_option( 'send_debug_email' );
        $this->description      = $this->get_option('description');
        $this->enabled          = $this->is_valid_for_use() ? 'yes': 'no'; // Check if the base currency supports this gateway.
        $this->enable_logging   = 'yes' === $this->get_option( 'enable_logging' );

        // Setup the test data, if in test mode.
        if ( 'yes' === $this->get_option( 'testmode' ) ) {
            $this->test_mode = true;
            $this->add_testmode_admin_settings_notice();
        } else {
            $this->send_debug_email = false;
        }

        add_action( 'woocommerce_api_wc_gateway_paybox', array( $this, 'check_itn_response' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_receipt_paybox', array( $this, 'receipt_page' ) );
        add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
        add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_subscription_listener' ) );
        add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array( $this, 'process_pre_order_payments' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );

        if(!empty($_REQUEST['pg_order_id'])) {
            if(isset($_REQUEST['pg_result'])) {
                $order = wc_get_order( $_REQUEST['pg_order_id'] );
                if($_REQUEST['pg_result'] == 1) {
                    if ($order->get_status() == 'pending' || $order->get_status() == 'on-hold') {
                        $order->update_status('processing', __( 'PayBox Order payment success', 'woocommerce-gateway-paybox' ));
                    }
                    $orderId = (!empty($order_id))
                        ? $order_id
                        : (!empty(self::get_order_prop( $order, 'id' ))
                            ? self::get_order_prop( $order, 'id' )
                            : (!empty($order->get_order_number())
                                ? $order->get_order_number()
                                : 0)
                        );
                    header('Location:/checkout/order-received/'. $orderId);
                } else {
                    if ($order->get_status() == 'pending' || $order->get_status() == 'on-hold') {
                        $order->update_status( 'failed', __( 'PayBox Order payment failed', 'woocommerce-gateway-paybox' ));
                    }
                    header('Location:/');
                }
            }
        }
    }

    /**
     * Initialise Gateway Settings Form Fields
     *
     * @since 1.0.0
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __( 'Enable/Disable', 'woocommerce-gateway-paybox' ),
                'label'       => __( 'Enable PayBox', 'woocommerce-gateway-paybox' ),
                'type'        => 'checkbox',
                'description' => __( 'This controls whether or not this gateway is enabled within WooCommerce.', 'woocommerce-gateway-paybox' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'title' => array(
                'title'       => __( 'Title', 'woocommerce-gateway-paybox' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-paybox' ),
                'default'     => __( 'PayBox', 'woocommerce-gateway-paybox' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'woocommerce-gateway-paybox' ),
                'type'        => 'text',
                'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-gateway-paybox' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'testmode' => array(
                'title'       => __( 'PayBox Test Mode', 'woocommerce-gateway-paybox' ),
                'type'        => 'checkbox',
                'description' => __( 'Place the payment gateway in development mode.', 'woocommerce-gateway-paybox' ),
                'default'     => 'yes',
            ),
            'merchant_id' => array(
                'title'       => __( 'Merchant ID', 'woocommerce-gateway-paybox' ),
                'type'        => 'text',
                'description' => __( '* Required. This is the merchant ID, received from PayBox.', 'woocommerce-gateway-paybox' ),
                'default'     => '',
            ),
            'merchant_key' => array(
                'title'       => __( 'Merchant Key', 'woocommerce-gateway-paybox' ),
                'type'        => 'text',
                'description' => __( '* Required. This is the merchant key, received from PayBox.', 'woocommerce-gateway-paybox' ),
                'default'     => '',
            ),
            'ofd' => array(
                'title'       => __( 'OFD', 'woocommerce-gateway-paybox' ),
                'type'        => 'checkbox',
                'description' => __( 'Enable generation of fiscal documents', 'woocommerce-gateway-paybox' ),
                'default'     => ''
            ),
            'tax' => array(
                'title'       => __( 'Type tax', 'woocommerce-gateway-paybox' ),
                'type'        => 'text',
                'default'     => ''
            ),
        );
    }

    /**
     * add_testmode_admin_settings_notice()
     * Add a notice to the merchant_key and merchant_id fields when in test mode.
     *
     * @since 1.0.0
     */
    public function add_testmode_admin_settings_notice() {
        $this->form_fields['merchant_id']['description']  .= ' <strong>' . __( 'Sandbox Merchant ID currently in use', 'woocommerce-gateway-paybox' ) . ' ( ' . esc_html( $this->merchant_id ) . ' ).</strong>';
        $this->form_fields['merchant_key']['description'] .= ' <strong>' . __( 'Sandbox Merchant Key currently in use', 'woocommerce-gateway-paybox' ) . ' ( ' . esc_html( $this->merchant_key ) . ' ).</strong>';
    }

    /**
     * is_valid_for_use()
     *
     * Check if this gateway is enabled and available in the base currency being traded with.
     *
     * @since 1.0.0
     * @return bool
     */
    public function is_valid_for_use() {
        $is_available          = false;
        $is_available_currency = in_array( get_woocommerce_currency(), $this->available_currencies );

        if ( $is_available_currency && $this->merchant_id && $this->merchant_key ) {
            $is_available = true;
        }

        return $is_available;
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     *
     * @since 1.0.0
     */
    public function admin_options() {
        if ( in_array( get_woocommerce_currency(), $this->available_currencies ) ) {
            ?>

            <h3><?php echo ( ! empty( $this->method_title ) ) ? $this->method_title : __( 'Settings', 'woocommerce' ) ; ?></h3>

            <?php echo ( ! empty( $this->method_description ) ) ? wpautop( $this->method_description ) : ''; ?>

            <script type="application/javascript">
                jQuery(document).ready(function (){
                    if (jQuery('input[name="woocommerce_paybox_ofd"]').is(':checked')){
                        jQuery('input[name="woocommerce_paybox_tax"]').prop( "disabled", false );
                    }
                    else{
                        jQuery('input[name="woocommerce_paybox_tax"]').prop( "disabled", true );
                    }

                    jQuery('input[name="woocommerce_paybox_ofd"]').change(function (){
                        if (this.checked){
                            jQuery('input[name="woocommerce_paybox_tax"]').prop( "disabled", false );
                        }
                        else{
                            jQuery('input[name="woocommerce_paybox_tax"]').prop( "disabled", true );
                        }
                    })
                })
            </script>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table><?php
        } else {
            ?>
            <h3><?php _e( 'PayBox', 'woocommerce-gateway-paybox' ); ?></h3>
            <div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woocommerce-gateway-paybox' ); ?></strong> <?php /* translators: 1: a href link 2: closing href */ echo sprintf( __( 'Choose KZT, RUR, USD, EUR or KGS as your store currency in %1$sGeneral Settings%2$s to enable the PayBox Gateway.', 'woocommerce-gateway-paybox' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">', '</a>' ); ?></p></div>
            <?php
        }
    }

    /**
     * Generate the PayBox button link.
     *
     * @since 1.0.0
     */
    public function generate_PayBox_form( $order_id ) {
        $order = wc_get_order( $order_id );

        // Construct variables for post
        $orderId = (!empty($order_id))
            ? $order_id
            : (!empty(self::get_order_prop( $order, 'id' ))
                ? self::get_order_prop( $order, 'id' )
                : (!empty($order->get_order_number())
                    ? $order->get_order_number()
                    : 0)
            );
        //TODO: Понять в чем баг с $order->get_cancel_order_url()

        if (method_exists($order, 'get_currency')) {
            $currency = $order->get_currency();
        }
        else {
            $currency = $order->get_order_currency();
        }

        $this->data_to_send = array(
            'pg_amount'         => (int)$order->get_total(),
            'pg_description'    => sprintf( __( 'New order from %s', 'woocommerce-gateway-paybox' ), get_bloginfo( 'name' ) ),
            'pg_encoding'       => 'UTF-8',
            'pg_currency'       => $currency,
            'pg_user_ip'        => $_SERVER['REMOTE_ADDR'],
            'pg_lifetime'       => 86400,
            'pg_merchant_id'    => $this->merchant_id,
            'pg_order_id'       => $orderId,
            'pg_result_url'     => $this->response_url,
            'pg_request_method' => 'GET',
            'pg_salt'           => rand(21, 43433),
            'pg_success_url'    => get_site_url().'/checkout/order-received/',
            'pg_failure_url'	=> get_site_url(),
            'pg_user_phone'     => self::get_order_prop( $order, 'billing_phone' ),
            'pg_user_contact_email' => self::get_order_prop( $order, 'billing_email' )
        );
        $this->data_to_send['pg_testing_mode'] = ('yes' === $this->get_option( 'testmode' )) ? 1 : 0;

        if ('yes' === $this->get_option('ofd')) {
            $order = wc_get_order($order_id);
            $tax_type = $this->get_option('tax');

            foreach ($order->get_items() as $item_id => $item){
                $this->data_to_send['pg_receipt_positions'][] = [
                    'count' => $order->get_item_meta($item_id, '_qty', true),
                    'name' => $item['name'],
                    'price' => $order->get_item_meta($item_id, '_line_total', true),
                    'tax_type' => $tax_type
                ];
            }
        }

        $sign_data = $this->prepare_request_data($this->data_to_send);

        $url = 'payment.php';
        ksort($sign_data);
        array_unshift($sign_data, $url);
        array_push($sign_data, $this->merchant_key);
        $str = implode(';', $sign_data);
        $this->data_to_send['pg_sig'] = md5($str);
        $query = http_build_query($this->data_to_send);
        $this->url = 'https://api.paybox.money/' . $url;


        // add subscription parameters
        if ( $this->order_contains_subscription( $order_id ) ) {
            // 2 == ad-hoc subscription type see PayBox API docs
            $this->data_to_send['subscription_type'] = '2';
        }

        if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
            $subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );
            // For renewal orders that have subscriptions with renewal flag,
            // we will create a new subscription in PayBox and link it to the existing ones in WC.
            // The old subscriptions in PayBox will be cancelled once we handle the itn request.
            if ( count ( $subscriptions ) > 0 && $this->_has_renewal_flag( reset( $subscriptions ) ) ) {
                // 2 == ad-hoc subscription type see PayBox API docs
                $this->data_to_send['subscription_type'] = '2';
            }
        }

        // pre-order: add the subscription type for pre order that require tokenization
        // at this point we assume that the order pre order fee and that
        // we should only charge that on the order. The rest will be charged later.
        if ( $this->order_contains_pre_order( $order_id )
            && $this->order_requires_payment_tokenization( $order_id ) ) {
            $this->data_to_send['amount']            = $this->get_pre_order_fee( $order_id );
            $this->data_to_send['subscription_type'] = '2';
        }

        return '<form action="' . esc_url( $this->url ) . '" method="post" id="PayBox_payment_form">
                ' . implode( '', $this->get_input($this->data_to_send) ) . '
                <input type="submit" class="button-alt" id="submit_PayBox_payment_form" value="' . __( 'Pay via PayBox', 'woocommerce-gateway-paybox' ) . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __( 'Cancel order &amp; restore cart', 'woocommerce-gateway-paybox' ) . '</a>
                <script type="text/javascript">
                    jQuery(function(){
                        jQuery("body").block(
                            {
                                message: "' . __( 'Thank you for your order. We are now redirecting you to PayBox to make payment.', 'woocommerce-gateway-paybox' ) . '",
                                overlayCSS:
                                {
                                    background: "#fff",
                                    opacity: 0.6
                                },
                                css: {
                                    padding:        20,
                                    textAlign:      "center",
                                    color:          "#555",
                                    border:         "3px solid #aaa",
                                    backgroundColor:"#fff",
                                    cursor:         "wait"
                                }
                            });
                        jQuery( "#submit_PayBox_payment_form" ).click();
                    });
                </script>
            </form>';
    }

    /**
     * @param $data
     * @param string $parent_input_name
     * @return array
     */
    public function get_input($data, $parent_input_name = ''){
        $result = array();
        foreach ($data as $field_name => $field_value) {
            $name = $field_name;

            if ('' !== $parent_input_name){
                $name = $parent_input_name.'['.$field_name.']';
            }

            if (is_array($field_value)) {
                $result[] = implode("\n", $this->get_input($field_value, (string) $name));
            }
            else {
                $result[] = '<input type="hidden" name="'.$name.'" value="' . esc_attr($field_value) . '" />';
            }
        }

        return $result;
    }

    /**
     * Process the payment and return the result.
     *
     * @since 1.0.0
     */
    public function process_payment( $order_id ) {

        if ( $this->order_contains_pre_order( $order_id )
            && $this->order_requires_payment_tokenization( $order_id )
            && ! $this->cart_contains_pre_order_fee() ) {
            throw new Exception( 'PayBox does not support transactions without any upfront costs or fees. Please select another gateway' );
        }

        $order = wc_get_order( $order_id );
        return array(
            'result'      => 'success',
            'redirect'     => $order->get_checkout_payment_url( true ),
        );
    }

    /**
     * Reciept page.
     *
     * Display text and a button to direct the user to PayBox.
     *
     * @since 1.0.0
     */
    public function receipt_page( $order ) {
        echo '<p>' . __( 'Thank you for your order, please click the button below to pay with PayBox.', 'woocommerce-gateway-paybox' ) . '</p>';
        echo $this->generate_PayBox_form( $order );
    }

    /**
     * Check PayBox ITN response.
     *
     * @since 1.0.0
     */
    public function check_itn_response() {
        $this->handle_itn_request( stripslashes_deep( $_POST ) );

        // Notify PayBox that information has been received
        header( 'HTTP/1.0 200 OK' );
        flush();
    }

    /**
     * Check PayBox ITN validity.
     *
     * @param array $data
     * @since 1.0.0
     */
    public function handle_itn_request( $data ) {
        $this->log( PHP_EOL
            . '----------'
            . PHP_EOL . 'PayBox ITN call received'
            . PHP_EOL . '----------'
        );
        $this->log( 'Get posted data' );
        $this->log( 'PayBox Data: ' . print_r( $data, true ) );

        $PayBox_error  = false;
        $PayBox_done   = false;
        $debug_email    = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
        $session_id     = $data['custom_str1'];
        $vendor_name    = get_bloginfo( 'name' );
        $vendor_url     = home_url( '/' );
        $order_id       = absint( $data['custom_str3'] );
        $order_key      = wc_clean( $session_id );
        $order          = wc_get_order( $order_id );
        $original_order = $order;

        if ( false === $data ) {
            $PayBox_error  = true;
            $PayBox_error_message = PF_ERR_BAD_ACCESS;
        }

        // Verify security signature
        if ( ! $PayBox_error && ! $PayBox_done ) {
            $this->log( 'Verify security signature' );
            $signature = md5( $this->_generate_parameter_string( $data, false, false ) ); // false not to sort data
            // If signature different, log for debugging
            if ( ! $this->validate_signature( $data, $signature ) ) {
                $PayBox_error         = true;
                $PayBox_error_message = PF_ERR_INVALID_SIGNATURE;
            }
        }

        // Verify source IP (If not in debug mode)
        if ( ! $PayBox_error && ! $PayBox_done
            && $this->get_option( 'testmode' ) != 'yes' ) {
            $this->log( 'Verify source IP' );

            if ( ! $this->is_valid_ip( $_SERVER['REMOTE_ADDR'] ) ) {
                $PayBox_error  = true;
                $PayBox_error_message = PF_ERR_BAD_SOURCE_IP;
            }
        }

        // Verify data received
        if ( ! $PayBox_error ) {
            $this->log( 'Verify data received' );
            $validation_data = $data;
            unset( $validation_data['signature'] );
            $has_valid_response_data = $this->validate_response_data( $validation_data );

            if ( ! $has_valid_response_data ) {
                $PayBox_error = true;
                $PayBox_error_message = PF_ERR_BAD_ACCESS;
            }
        }

        // Check data against internal order
        if ( ! $PayBox_error && ! $PayBox_done ) {
            $this->log( 'Check data against internal order' );

            // Check order amount
            if ( ! $this->amounts_equal( $data['amount_gross'], self::get_order_prop( $order, 'order_total' ) )
                && ! $this->order_contains_pre_order( $order_id )
                && ! $this->order_contains_subscription( $order_id ) ) {
                $PayBox_error  = true;
                $PayBox_error_message = PF_ERR_AMOUNT_MISMATCH;
            } elseif ( strcasecmp( $data['custom_str1'], self::get_order_prop( $order, 'order_key' ) ) != 0 ) {
                // Check session ID
                $PayBox_error  = true;
                $PayBox_error_message = PF_ERR_SESSIONID_MISMATCH;
            }
        }

        // alter order object to be the renewal order if
        // the ITN request comes as a result of a renewal submission request
        $description = json_decode( $data['item_description'] );

        if ( ! empty( $description->renewal_order_id ) ) {
            $order = wc_get_order( $description->renewal_order_id );
        }

        // Get internal order and verify it hasn't already been processed
        if ( ! $PayBox_error && ! $PayBox_done ) {
            $this->log_order_details( $order );

            // Check if order has already been processed
            if ( 'completed' === self::get_order_prop( $order, 'status' ) ) {
                $this->log( 'Order has already been processed' );
                $PayBox_done = true;
            }
        }

        // If an error occurred
        if ( $PayBox_error ) {
            $this->log( 'Error occurred: ' . $PayBox_error_message );

            if ( $this->send_debug_email ) {
                $this->log( 'Sending email notification' );

                // Send an email
                $subject = 'PayBox ITN error: ' . $PayBox_error_message;
                $body =
                    "Hi,\n\n" .
                    "An invalid PayBox transaction on your website requires attention\n" .
                    "------------------------------------------------------------\n" .
                    'Site: ' . $vendor_name . ' (' . $vendor_url . ")\n" .
                    'Remote IP Address: ' . $_SERVER['REMOTE_ADDR'] . "\n" .
                    'Remote host name: ' . gethostbyaddr( $_SERVER['REMOTE_ADDR'] ) . "\n" .
                    'Purchase ID: ' . self::get_order_prop( $order, 'id' ) . "\n" .
                    'User ID: ' . self::get_order_prop( $order, 'user_id' ) . "\n";
                if ( isset( $data['pf_payment_id'] ) ) {
                    $body .= 'PayBox Transaction ID: ' . esc_html( $data['pf_payment_id'] ) . "\n";
                }
                if ( isset( $data['payment_status'] ) ) {
                    $body .= 'PayBox Payment Status: ' . esc_html( $data['payment_status'] ) . "\n";
                }

                $body .= "\nError: " . $PayBox_error_message . "\n";

                switch ( $PayBox_error_message ) {
                    case PF_ERR_AMOUNT_MISMATCH:
                        $body .=
                            'Value received : ' . esc_html( $data['amount_gross'] ) . "\n"
                            . 'Value should be: ' . self::get_order_prop( $order, 'order_total' );
                        break;

                    case PF_ERR_ORDER_ID_MISMATCH:
                        $body .=
                            'Value received : ' . esc_html( $data['custom_str3'] ) . "\n"
                            . 'Value should be: ' . self::get_order_prop( $order, 'id' );
                        break;

                    case PF_ERR_SESSIONID_MISMATCH:
                        $body .=
                            'Value received : ' . esc_html( $data['custom_str1'] ) . "\n"
                            . 'Value should be: ' . self::get_order_prop( $order, 'id' );
                        break;

                    // For all other errors there is no need to add additional information
                    default:
                        break;
                }

                wp_mail( $debug_email, $subject, $body );
            } // End if().
        } elseif ( ! $PayBox_done ) {

            $this->log( 'Check status and update order' );

            if ( self::get_order_prop( $original_order, 'order_key' ) !== $order_key ) {
                $this->log( 'Order key does not match' );
                exit;
            }

            $status = strtolower( $data['payment_status'] );

            $subscriptions = array();
            if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) && function_exists( 'wcs_get_subscriptions_for_order' ) ) {
                $subscriptions = array_merge(
                    wcs_get_subscriptions_for_renewal_order( $order_id ),
                    wcs_get_subscriptions_for_order( $order_id )
                );
            }

            if ( 'complete' !== $status && 'cancelled' !== $status ) {
                foreach ( $subscriptions as $subscription ) {
                    $this->_set_renewal_flag( $subscription );
                }
            }

            if ( 'complete' === $status ) {
                $this->handle_itn_payment_complete( $data, $order, $subscriptions );
            } elseif ( 'failed' === $status ) {
                $this->handle_itn_payment_failed( $data, $order );
            } elseif ( 'pending' === $status ) {
                $this->handle_itn_payment_pending( $data, $order );
            } elseif ( 'cancelled' === $status ) {
                $this->handle_itn_payment_cancelled( $data, $order, $subscriptions );
            }
        } // End if().

        $this->log( PHP_EOL
            . '----------'
            . PHP_EOL . 'End ITN call'
            . PHP_EOL . '----------'
        );

    }

    /**
     * Handle logging the order details.
     *
     * @since 1.4.5
     */
    public function log_order_details( $order ) {
        if ( version_compare( WC_VERSION,'3.0.0', '<' ) ) {
            $customer_id = get_post_meta( $order->get_id(), '_customer_user', true );
        } else {
            $customer_id = $order->get_user_id();
        }

        $details = "Order Details:"
            . PHP_EOL . 'customer id:' . $customer_id
            . PHP_EOL . 'order id:   ' . $order->get_id()
            . PHP_EOL . 'parent id:  ' . $order->get_parent_id()
            . PHP_EOL . 'status:     ' . $order->get_status()
            . PHP_EOL . 'total:      ' . $order->get_total()
            . PHP_EOL . 'currency:   ' . $order->get_currency()
            . PHP_EOL . 'key:        ' . $order->get_order_key()
            . "";

        $this->log( $details );
    }

    /**
     * This function mainly responds to ITN cancel requests initiated on PayBox, but also acts
     * just in case they are not cancelled.
     * @version 1.4.3 Subscriptions flag
     *
     * @param array $data should be from the Gatewy ITN callback.
     * @param WC_Order $order
     */
    public function handle_itn_payment_cancelled( $data, $order, $subscriptions ) {

        remove_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_subscription_listener' ) );
        foreach ( $subscriptions as $subscription ) {
            if ( 'cancelled' !== $subscription->get_status() ) {
                $subscription->update_status( 'cancelled', __( 'Merchant cancelled subscription on PayBox.' , 'woocommerce-gateway-paybox' ) );
                $this->_delete_subscription_token( $subscription );
            }
        }
        add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_subscription_listener' ) );
    }

    /**
     * This function handles payment complete request by PayBox.
     * @version 1.4.3 Subscriptions flag
     *
     * @param array $data should be from the Gatewy ITN callback.
     * @param WC_Order $order
     */
    public function handle_itn_payment_complete( $data, $order, $subscriptions ) {
        $this->log( '- Complete' );
        $order->add_order_note( __( 'ITN payment completed', 'woocommerce-gateway-paybox' ) );
        $order_id = self::get_order_prop( $order, 'id' );

        // Store token for future subscription deductions.
        if ( count( $subscriptions ) > 0 && isset( $data['token'] ) ) {
            if ( $this->_has_renewal_flag( reset( $subscriptions ) ) ) {
                // renewal flag is set to true, so we need to cancel previous token since we will create a new one
                $this->log( 'Cancel previous subscriptions with token ' . $this->_get_subscription_token( reset( $subscriptions ) ) );

                // only request API cancel token for the first subscription since all of them are using the same token
                $this->cancel_subscription_listener( reset( $subscriptions ) );
            }

            $token = sanitize_text_field( $data['token'] );
            foreach ( $subscriptions as $subscription ) {
                $this->_delete_renewal_flag( $subscription );
                $this->_set_subscription_token( $token, $subscription );
            }
        }

        // the same mechanism (adhoc token) is used to capture payment later
        if ( $this->order_contains_pre_order( $order_id )
            && $this->order_requires_payment_tokenization( $order_id ) ) {

            $token = sanitize_text_field( $data['token'] );
            $is_pre_order_fee_paid = get_post_meta( $order_id, '_pre_order_fee_paid', true ) === 'yes';

            if ( ! $is_pre_order_fee_paid ) {
                /* translators: 1: gross amount 2: payment id */
                $order->add_order_note( sprintf( __( 'PayBox pre-order fee paid: R %1$s (%2$s)', 'woocommerce-gateway-paybox' ), $data['amount_gross'], $data['pf_payment_id'] ) );
                $this->_set_pre_order_token( $token, $order );
                // set order to pre-ordered
                WC_Pre_Orders_Order::mark_order_as_pre_ordered( $order );
                update_post_meta( $order_id, '_pre_order_fee_paid', 'yes' );
                WC()->cart->empty_cart();
            } else {
                /* translators: 1: gross amount 2: payment id */
                $order->add_order_note( sprintf( __( 'PayBox pre-order product line total paid: R %1$s (%2$s)', 'woocommerce-gateway-paybox' ), $data['amount_gross'], $data['pf_payment_id'] ) );
                $order->payment_complete();
                $this->cancel_pre_order_subscription( $token );
            }
        } else {
            $order->payment_complete();
        }

        $debug_email   = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
        $vendor_name    = get_bloginfo( 'name' );
        $vendor_url     = home_url( '/' );
        if ( $this->send_debug_email ) {
            $subject = 'PayBox ITN on your site';
            $body =
                "Hi,\n\n"
                . "A PayBox transaction has been completed on your website\n"
                . "------------------------------------------------------------\n"
                . 'Site: ' . $vendor_name . ' (' . $vendor_url . ")\n"
                . 'Purchase ID: ' . esc_html( $data['m_payment_id'] ) . "\n"
                . 'PayBox Transaction ID: ' . esc_html( $data['pf_payment_id'] ) . "\n"
                . 'PayBox Payment Status: ' . esc_html( $data['payment_status'] ) . "\n"
                . 'Order Status Code: ' . self::get_order_prop( $order, 'status' );
            wp_mail( $debug_email, $subject, $body );
        }
    }

    /**
     * @param $data
     * @param $order
     */
    public function handle_itn_payment_failed( $data, $order ) {
        $this->log( '- Failed' );
        /* translators: 1: payment status */
        $order->update_status( 'failed', sprintf( __( 'Payment %s via ITN.', 'woocommerce-gateway-paybox' ), strtolower( sanitize_text_field( $data['payment_status'] ) ) ) );
        $debug_email   = $this->get_option( 'debug_email', get_option( 'admin_email' ) );
        $vendor_name    = get_bloginfo( 'name' );
        $vendor_url     = home_url( '/' );

        if ( $this->send_debug_email ) {
            $subject = 'PayBox ITN Transaction on your site';
            $body =
                "Hi,\n\n" .
                "A failed PayBox transaction on your website requires attention\n" .
                "------------------------------------------------------------\n" .
                'Site: ' . $vendor_name . ' (' . $vendor_url . ")\n" .
                'Purchase ID: ' . self::get_order_prop( $order, 'id' ) . "\n" .
                'User ID: ' . self::get_order_prop( $order, 'user_id' ) . "\n" .
                'PayBox Transaction ID: ' . esc_html( $data['pf_payment_id'] ) . "\n" .
                'PayBox Payment Status: ' . esc_html( $data['payment_status'] );
            wp_mail( $debug_email, $subject, $body );
        }
    }

    /**
     * @since 1.4.0 introduced
     * @param $data
     * @param $order
     */
    public function handle_itn_payment_pending( $data, $order ) {
        $this->log( '- Pending' );
        // Need to wait for "Completed" before processing
        /* translators: 1: payment status */
        $order->update_status( 'on-hold', sprintf( __( 'Payment %s via ITN.', 'woocommerce-gateway-paybox' ), strtolower( sanitize_text_field( $data['payment_status'] ) ) ) );
    }

    /**
     * @param string $order_id
     * @return double
     */
    public function get_pre_order_fee( $order_id ) {
        foreach ( wc_get_order( $order_id )->get_fees() as $fee ) {
            if ( is_array( $fee ) && 'Pre-Order Fee' == $fee['name'] ) {
                return doubleval( $fee['line_total'] ) + doubleval( $fee['line_tax'] );
            }
        }
    }
    /**
     * @param string $order_id
     * @return bool
     */
    public function order_contains_pre_order( $order_id ) {
        if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
            return WC_Pre_Orders_Order::order_contains_pre_order( $order_id );
        }
        return false;
    }

    /**
     * @param string $order_id
     *
     * @return bool
     */
    public function order_requires_payment_tokenization( $order_id ) {
        if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
            return WC_Pre_Orders_Order::order_requires_payment_tokenization( $order_id );
        }
        return false;
    }

    /**
     * @return bool
     */
    public function cart_contains_pre_order_fee() {
        if ( class_exists( 'WC_Pre_Orders_Cart' ) ) {
            return WC_Pre_Orders_Cart::cart_contains_pre_order_fee();
        }
        return false;
    }
    /**
     * Store the PayBox subscription token
     *
     * @param string $token
     * @param WC_Subscription $subscription
     */
    protected function _set_subscription_token( $token, $subscription ) {
        update_post_meta( self::get_order_prop( $subscription, 'id' ), '_PayBox_subscription_token', $token );
    }

    /**
     * Retrieve the PayBox subscription token for a given order id.
     *
     * @param WC_Subscription $subscription
     * @return mixed
     */
    protected function _get_subscription_token( $subscription ) {
        return get_post_meta( self::get_order_prop( $subscription, 'id' ), '_PayBox_subscription_token', true );
    }

    /**
     * Retrieve the PayBox subscription token for a given order id.
     *
     * @param WC_Subscription $subscription
     * @return mixed
     */
    protected function _delete_subscription_token( $subscription ) {
        return delete_post_meta( self::get_order_prop( $subscription, 'id' ), '_PayBox_subscription_token' );
    }

    /**
     * Store the PayBox renewal flag
     * @since 1.4.3
     *
     * @param string $token
     * @param WC_Subscription $subscription
     */
    protected function _set_renewal_flag( $subscription ) {
        if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
            update_post_meta( self::get_order_prop( $subscription, 'id' ), '_PayBox_renewal_flag', 'true' );
        } else {
            $subscription->update_meta_data( '_PayBox_renewal_flag', 'true' );
            $subscription->save_meta_data();
        }
    }

    /**
     * Retrieve the PayBox renewal flag for a given order id.
     * @since 1.4.3
     *
     * @param WC_Subscription $subscription
     * @return bool
     */
    protected function _has_renewal_flag( $subscription ) {
        if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
            return 'true' === get_post_meta( self::get_order_prop( $subscription, 'id' ), '_PayBox_renewal_flag', true );
        } else {
            return 'true' === $subscription->get_meta( '_PayBox_renewal_flag', true );
        }
    }

    /**
     * Retrieve the PayBox renewal flag for a given order id.
     * @since 1.4.3
     *
     * @param WC_Subscription $subscription
     * @return mixed
     */
    protected function _delete_renewal_flag( $subscription ) {
        if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
            return delete_post_meta( self::get_order_prop( $subscription, 'id' ), '_PayBox_renewal_flag' );
        } else {
            $subscription->delete_meta_data( '_PayBox_renewal_flag' );
            $subscription->save_meta_data();
        }
    }

    /**
     * Store the PayBox pre_order_token token
     *
     * @param string $token
     * @param WC_Order$order
     */
    protected function _set_pre_order_token( $token, $order ) {
        update_post_meta( self::get_order_prop( $order, 'id' ), '_PayBox_pre_order_token', $token );
    }

    /**
     * Retrieve the PayBox pre-order token for a given order id.
     *
     * @param WC_Order $order
     * @return mixed
     */
    protected function _get_pre_order_token( $order ) {
        return get_post_meta( self::get_order_prop( $order, 'id' ), '_PayBox_pre_order_token', true );
    }

    /**
     * Wrapper function for wcs_order_contains_subscription
     *
     * @param WC_Order $order
     * @return bool
     */
    public function order_contains_subscription( $order ) {
        if ( ! function_exists( 'wcs_order_contains_subscription' ) ) {
            return false;
        }
        return wcs_order_contains_subscription( $order );
    }

    /**
     * @param $amount_to_charge
     * @param WC_Order $renewal_order
     */
    public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {

        $subscription = wcs_get_subscription( get_post_meta( self::get_order_prop( $renewal_order, 'id' ), '_subscription_renewal', true ) );
        $this->log( 'Attempting to renew subscription from renewal order ' . self::get_order_prop( $renewal_order, 'id' ) );

        if ( empty( $subscription ) ) {
            $this->log( 'Subscription from renewal order was not found.' );
            return;
        }

        $response = $this->submit_subscription_payment( $subscription, $amount_to_charge );

        if ( is_wp_error( $response ) ) {
            /* translators: 1: error code 2: error message */
            $renewal_order->update_status( 'failed', sprintf( __( 'PayBox Subscription renewal transaction failed (%1$s:%2$s)', 'woocommerce-gateway-paybox' ), $response->get_error_code() ,$response->get_error_message() ) );
        }
        // Payment will be completion will be capture only when the ITN callback is sent to $this->handle_itn_request().
        $renewal_order->add_order_note( __( 'PayBox Subscription renewal transaction submitted.', 'woocommerce-gateway-paybox' ) );

    }

    /**
     * Get a name for the subscription item. For multiple
     * item only Subscription $date will be returned.
     *
     * For subscriptions with no items Site/Blog name will be returned.
     *
     * @param WC_Subscription $subscription
     * @return string
     */
    public function get_subscription_name( $subscription ) {

        if ( $subscription->get_item_count() > 1 ) {
            return $subscription->get_date_to_display( 'start' );
        } else {
            $items = $subscription->get_items();

            if ( empty( $items ) ) {
                return get_bloginfo( 'name' );
            }

            $item = array_shift( $items );
            return $item['name'];
        }
    }


    /**
     * Responds to Subscriptions extension cancellation event.
     *
     * @since 1.4.0 introduced.
     * @param WC_Subscription $subscription
     */
    public function cancel_subscription_listener( $subscription ) {
        $token = $this->_get_subscription_token( $subscription );
        if ( empty( $token ) ) {
            return;
        }
        $this->api_request( 'cancel', $token, array(), 'PUT' );
    }

    /**
     * @since 1.4.0
     * @param string $token
     *
     * @return bool|WP_Error
     */
    public function cancel_pre_order_subscription( $token ) {
        return $this->api_request( 'cancel', $token, array(), 'PUT' );
    }

    /**
     * @since 1.4.0 introduced.
     * @param      $api_data
     * @param bool $sort_data_before_merge? default true.
     * @param bool $skip_empty_values Should key value pairs be ignored when generating signature?  Default true.
     *
     * @return string
     */
    protected function _generate_parameter_string( $api_data, $sort_data_before_merge = true, $skip_empty_values = true ) {

        // if sorting is required the passphrase should be added in before sort.
        if ( ! empty( $this->pass_phrase ) && $sort_data_before_merge ) {
            $api_data['passphrase'] = $this->pass_phrase;
        }

        if ( $sort_data_before_merge ) {
            ksort( $api_data );
        }

        // concatenate the array key value pairs.
        $parameter_string = '';
        foreach ( $api_data as $key => $val ) {

            if ( $skip_empty_values && empty( $val ) ) {
                continue;
            }

            if ( 'signature' !== $key ) {
                $val = urlencode( $val );
                $parameter_string .= "$key=$val&";
            }
        }
        // when not sorting passphrase should be added to the end before md5
        if ( $sort_data_before_merge ) {
            $parameter_string = rtrim( $parameter_string, '&' );
        } elseif ( ! empty( $this->pass_phrase ) ) {
            $parameter_string .= 'passphrase=' . urlencode( $this->pass_phrase );
        } else {
            $parameter_string = rtrim( $parameter_string, '&' );
        }

        return $parameter_string;
    }
    /**
     * Setup constants.
     *
     * Setup common values and messages used by the PayBox gateway.
     *
     * @since 1.0.0
     */
    public function setup_constants() {
        // Create user agent string.
        define( 'PF_SOFTWARE_NAME', 'WooCommerce' );
        define( 'PF_SOFTWARE_VER', WC_VERSION );
        define( 'PF_MODULE_NAME', 'WooCommerce-paybox-Free' );
        define( 'PF_MODULE_VER', $this->version );

        // Features
        // - PHP
        $pf_features = 'PHP ' . phpversion() . ';';

        // - cURL
        if ( in_array( 'curl', get_loaded_extensions() ) ) {
            define( 'PF_CURL', '' );
            $pf_version = curl_version();
            $pf_features .= ' curl ' . $pf_version['version'] . ';';
        } else {
            $pf_features .= ' nocurl;';
        }

        // Create user agrent
        define( 'PF_USER_AGENT', PF_SOFTWARE_NAME . '/' . PF_SOFTWARE_VER . ' (' . trim( $pf_features ) . ') ' . PF_MODULE_NAME . '/' . PF_MODULE_VER );

        // General Defines
        define( 'PF_TIMEOUT', 15 );
        define( 'PF_EPSILON', 0.01 );

        // Messages
        // Error
        define( 'PF_ERR_AMOUNT_MISMATCH', __( 'Amount mismatch', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_BAD_ACCESS', __( 'Bad access of page', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_BAD_SOURCE_IP', __( 'Bad source IP address', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_CONNECT_FAILED', __( 'Failed to connect to PayBox', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_INVALID_SIGNATURE', __( 'Security signature mismatch', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_MERCHANT_ID_MISMATCH', __( 'Merchant ID mismatch', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_NO_SESSION', __( 'No saved session found for ITN transaction', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_ORDER_ID_MISSING_URL', __( 'Order ID not present in URL', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_ORDER_ID_MISMATCH', __( 'Order ID mismatch', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_ORDER_INVALID', __( 'This order ID is invalid', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_ORDER_NUMBER_MISMATCH', __( 'Order Number mismatch', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_ORDER_PROCESSED', __( 'This order has already been processed', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_PDT_FAIL', __( 'PDT query failed', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_PDT_TOKEN_MISSING', __( 'PDT token not present in URL', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_SESSIONID_MISMATCH', __( 'Session ID mismatch', 'woocommerce-gateway-paybox' ) );
        define( 'PF_ERR_UNKNOWN', __( 'Unkown error occurred', 'woocommerce-gateway-paybox' ) );

        // General
        define( 'PF_MSG_OK', __( 'Payment was successful', 'woocommerce-gateway-paybox' ) );
        define( 'PF_MSG_FAILED', __( 'Payment has failed', 'woocommerce-gateway-paybox' ) );
        define( 'PF_MSG_PENDING', __( 'The payment is pending. Please note, you will receive another Instant Transaction Notification when the payment status changes to "Completed", or "Failed"', 'woocommerce-gateway-paybox' ) );

        do_action( 'woocommerce_gateway_PayBox_setup_constants' );
    }

    /**
     * Log system processes.
     * @since 1.0.0
     */
    public function log( $message ) {
        if ( 'yes' === $this->get_option( 'testmode' ) || $this->enable_logging ) {
            if ( empty( $this->logger ) ) {
                $this->logger = new WC_Logger();
            }
            $this->logger->add( 'PayBox', $message );
        }
    }

    /**
     * validate_signature()
     *
     * Validate the signature against the returned data.
     *
     * @param array $data
     * @param string $signature
     * @since 1.0.0
     * @return string
     */
    public function validate_signature( $data, $signature ) {
        $result = $data['signature'] === $signature;
        $this->log( 'Signature = ' . ( $result ? 'valid' : 'invalid' ) );
        return $result;
    }

    /**
     * Validate the IP address to make sure it's coming from PayBox.
     *
     * @param array $source_ip
     * @since 1.0.0
     * @return bool
     */
    public function is_valid_ip( $source_ip ) {
        // Variable initialization
        $valid_hosts = array(
            'www.PayBox.co.za',
            'sandbox.PayBox.co.za',
            'w1w.PayBox.co.za',
            'w2w.PayBox.co.za',
        );

        $valid_ips = array();

        foreach ( $valid_hosts as $pf_hostname ) {
            $ips = gethostbynamel( $pf_hostname );

            if ( false !== $ips ) {
                $valid_ips = array_merge( $valid_ips, $ips );
            }
        }

        // Remove duplicates
        $valid_ips = array_unique( $valid_ips );

        $this->log( "Valid IPs:\n" . print_r( $valid_ips, true ) );
        $is_valid_ip = in_array( $source_ip, $valid_ips );
        return apply_filters( 'woocommerce_gateway_PayBox_is_valid_ip', $is_valid_ip, $source_ip );
    }

    /**
     * validate_response_data()
     *
     * @param array $post_data
     * @param string $proxy Address of proxy to use or NULL if no proxy.
     * @since 1.0.0
     * @return bool
     */
    public function validate_response_data( $post_data, $proxy = null ) {
        $this->log( 'Host = ' . $this->validate_url );
        $this->log( 'Params = ' . print_r( $post_data, true ) );

        if ( ! is_array( $post_data ) ) {
            return false;
        }

        $response = wp_remote_post( $this->validate_url, array(
            'body'       => $post_data,
            'timeout'    => 70,
            'user-agent' => PF_USER_AGENT,
        ));

        if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
            $this->log( "Response error:\n" . print_r( $response, true ) );
            return false;
        }

        parse_str( $response['body'], $parsed_response );

        $response = $parsed_response;

        $this->log( "Response:\n" . print_r( $response, true ) );

        // Interpret Response
        if ( is_array( $response ) && in_array( 'VALID', array_keys( $response ) ) ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * amounts_equal()
     *
     * Checks to see whether the given amounts are equal using a proper floating
     * point comparison with an Epsilon which ensures that insignificant decimal
     * places are ignored in the comparison.
     *
     * eg. 100.00 is equal to 100.0001
     *
     * @param $amount1 Float 1st amount for comparison
     * @param $amount2 Float 2nd amount for comparison
     * @since 1.0.0
     * @return bool
     */
    public function amounts_equal( $amount1, $amount2 ) {
        return ! ( abs( floatval( $amount1 ) - floatval( $amount2 ) ) > PF_EPSILON );
    }

    /**
     * Get order property with compatibility check on order getter introduced
     * in WC 3.0.
     *
     * @since 1.4.1
     *
     * @param WC_Order $order Order object.
     * @param string   $prop  Property name.
     *
     * @return mixed Property value
     */
    public static function get_order_prop( $order, $prop ) {
        switch ( $prop ) {
            case 'order_total':
                $getter = array( $order, 'get_total' );
                break;
            default:
                $getter = array( $order, 'get_' . $prop );
                break;
        }

        return is_callable( $getter ) ? call_user_func( $getter ) : $order->{ $prop };
    }

    /**
     *  Show possible admin notices
     *
     */
    public function admin_notices() {
        if('yes' == $this->get_option( 'enabled' )) {
            if(empty($this->merchant_id)) {
                echo '<div class="error paybox-passphrase-message"><p>'
                    . __( 'PayBox requires a Merchant ID to work.', 'woocommerce-gateway-paybox' )
                    . '</p></div>';
            }
            if(empty($this->merchant_key)) {
                echo '<div class="error paybox-passphrase-message"><p>'
                    . __( 'PayBox required a Merchant Key to work.', 'woocommerce-gateway-paybox' )
                    . '</p></div>';
            }
        }
    }

    /**
     * @param $data
     * @param string $parent_name
     * @return array|string[]
     */
    private function prepare_request_data($data, $parent_name = '') {
        if (!is_array($data)) return $data;

        $result = array();
        $i = 0;

        foreach ( $data as $key => $val ) {
            $i++;
            $name = $parent_name . ((string) $key) . sprintf('%03d', $i);

            if (is_array($val) ) {
                $result = array_merge($result, $this->prepare_request_data($val, $name));
                continue;
            }

            $result += array($name => (string)$val);
        }

        return $result;
    }
}
