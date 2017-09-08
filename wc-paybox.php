<?php 

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once('PG_Signature.php');

/**
  Plugin Name: Paybox Payment Gateway
  Plugin URI: https://github.com/PayBox/module-wordpress-woocommerce
  Description: Provides a Paybox Payment Gateway.
  Version: 1.0.1
  Author: PayBox
 */


/* Add a custom payment class to WC
  ------------------------------------------------------------ */
add_action('plugins_loaded', 'woocommerce_paybox', 0);
function woocommerce_paybox(){
	if (!class_exists('WC_Payment_Gateway'))
		return; // if the WC payment gateway class is not available, do nothing
	if(class_exists('WC_Paybox'))
		return;
	
class WC_Paybox extends WC_Payment_Gateway{
	public function __construct(){
		
		$plugin_dir = plugin_dir_url(__FILE__);

		global $woocommerce;

		$this->id = 'paybox';
		$this->icon = apply_filters('woocommerce_paybox_icon', ''.$plugin_dir.'paybox.png');
		$this->has_fields = false;

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->title = $this->get_option('title');
		$this->merchant_id = $this->get_option('merchant_id');
		$this->secret_key = $this->get_option('secret_key');
		$this->lifetime = $this->get_option('lifetime');
		$this->testmode = $this->get_option('testmode');

		$this->description = $this->get_option('description');
		$this->instructions = $this->get_option('instructions');

		// Logs
		if ($this->debug == 'yes'){
			$this->log = $woocommerce->logger();
		}

		// Actions
		add_action('woocommerce_receipt_paybox', array($this, 'receipt_page'));

		// Save options
		add_action( 'woocommerce_update_options_payment_gateways_paybox', array( $this, 'process_admin_options' ) );

		// Payment listener/API hook
		add_action('woocommerce_api_wc_paybox', array($this, 'check_assistant_response'));

		if (!$this->is_valid_for_use()){
			$this->enabled = false;
		}
	}
	
	/**
	 * Check if this gateway is enabled and available in the user's country
	 */
	function is_valid_for_use(){
		if (!in_array(get_option('woocommerce_currency'), array('RUB', 'EUR', 'USD', 'RUR', 'KZT')))
			return false;

		return true;
	}
	
	/**
	* Admin Panel Options 
	* - Options for bits like 'title' and availability on a country-by-country basis
	*
	* @since 0.1
	**/
	public function admin_options() {
		?>
		<h3><?php _e('Paybox', 'woocommerce'); ?></h3>
		<p><?php _e('Настройка приема электронных платежей через PayBox.', 'woocommerce'); ?></p>

	  <?php if ( $this->is_valid_for_use() ) : ?>

		<table class="form-table">

		<?php    	
    			// Generate the HTML For the settings form.
    			$this->generate_settings_html();
    ?>
    </table><!--/.form-table-->
    		
    <?php else : ?>
		<div class="inline error"><p><strong><?php _e('Шлюз отключен', 'woocommerce'); ?></strong>: <?php _e('PayBox не поддерживает валюты Вашего магазина.', 'woocommerce' ); ?></p></div>
		<?php
			endif;

    } // End admin_options()

  /**
  * Initialise Gateway Settings Form Fields
  *
  * @access public
  * @return void
  */
	function init_form_fields(){
		$this->form_fields = array(
				'enabled' => array(
					'title' => __('Включить/Выключить', 'woocommerce'),
					'type' => 'checkbox',
					'label' => __('Включен', 'woocommerce'),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __('Название', 'woocommerce'),
					'type' => 'text', 
					'description' => __( 'Это название, которое пользователь видит во время проверки.', 'woocommerce' ), 
					'default' => __('PayBox', 'woocommerce')
				),
				'merchant_id' => array(
					'title' => __('Номер магазина', 'woocommerce'),
					'type' => 'text',
					'description' => __('Пожалуйста введите Номер магазина', 'woocommerce'),
					'default' => ''
				),
				'secret_key' => array(
					'title' => __('Секретный ключ', 'woocommerce'),
					'type' => 'text',
					'description' => __('Секретный ключ для взаимодействия по API.', 'woocommerce'),
					'default' => ''
				),
				'lifetime' => array(
					'title' => __('Время жизни счета', 'woocommerce'),
					'type' => 'text',
					'description' => __('Считается в минутах. Максимальное значение 7 дней', 'woocommerce'),
					'default' => '1440'
				),
				'payment_system_name' => array(
					'title' => __('Платежная система', 'woocommerce'),
					'type' => 'text',
					'description' => __('Заполняется только в случае, когда выбор ПС происходит на стороне магазина', 'woocommerce'),
					'default' => ''
				),
				'testmode' => array(
					'title' => __('Тестовый режим', 'woocommerce'),
					'type' => 'checkbox', 
					'label' => __('Включен', 'woocommerce'),
					'description' => __('Тестовый режим используется для проверки взаимодействия.', 'woocommerce'),
					'default' => 'no'
				),
				'description' => array(
					'title' => __( 'Description', 'woocommerce' ),
					'type' => 'textarea',
					'description' => __( 'Описанием метода оплаты которое клиент будет видеть на вашем сайте.', 'woocommerce' ),
					'default' => 'Оплата с помощью PayBox.'
				),
				'instructions' => array(
					'title' => __( 'Instructions', 'woocommerce' ),
					'type' => 'textarea',
					'description' => __( 'Инструкции, которые будут добавлены на страницу благодарностей.', 'woocommerce' ),
					'default' => 'Оплата с помощью PayBox.'
				)
			);
	}

	/**
	* Дополнительная информация в форме выбора способа оплаты
	**/
	function payment_fields(){
		if ($this->description){
			echo wpautop(wptexturize($this->description));
		}
	}

	/**
	 * Process the payment and return the result
	 **/
	function process_payment($order_id){
		$order = new WC_Order($order_id);

		return array(
			'result' => 'success',
			'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
		);
	}
	
	/**
	* Форма оплаты
	**/
	function receipt_page($order_id){
		echo '<p>'.__('Спасибо за Ваш заказ, пожалуйста, нажмите кнопку ниже, чтобы заплатить.', 'woocommerce').'</p>';
		$strReuestUrl = 'http://'.$_SERVER['HTTP_HOST'].'/index.php?wc-api=wc_paybox';
		$strCurrency = get_woocommerce_currency();
        if ($strCurrency == 'RUR') $strCurrency = 'RUB';
		
        $order = new WC_Order( $order_id );
		$arrCartItems = $order->get_items();
		$strDescription = '';
		foreach($arrCartItems as $arrItem){
			$strDescription .= $arrItem['name'];
			if($arrItem['qty'] > 1)
				$strDescription .= '*'.$arrItem['qty']."; ";
			else
				$strDescription .= "; ";
		}
				
        $arrFields = array(
			'pg_merchant_id'		=> $this->merchant_id,
			'pg_order_id'			=> $order_id,
			'pg_currency'			=> $strCurrency,
			'pg_amount'				=> number_format($order->order_total, 2, '.', ''),
			'pg_user_phone'			=> $order->billing_phone,
			'pg_user_email'			=> $order->billing_email,
			'pg_user_contact_email'	=> $order->billing_email,
			'pg_lifetime'			=> ($this->lifetime)?$this->lifetime*60:0,
			'pg_testing_mode'		=> ($this->testmode == 'yes')?1:0,
			'pg_description'		=> $strDescription,
			'pg_user_ip'			=> $_SERVER['REMOTE_ADDR'],
			'pg_language'			=> 'ru',
			'pg_check_url'			=> $strReuestUrl."&type=check",
			'pg_result_url'			=> $strReuestUrl."&type=result",
			'pg_request_method'		=> 'POST',
			'pg_success_url'		=> $strReuestUrl."&type=success",
			'pg_failure_url'		=> $strReuestUrl."&type=failed",
			'pg_salt'				=> rand(21,43433), // Параметры безопасности сообщения. Необходима генерация pg_salt и подписи сообщения.
		);
		
		if(!empty($this->payment_system_name))
			$arrFields['payment_system_name'] = $this->payment_system_name;
		
		$arrFields['pg_sig'] = PG_Signature::make('payment.php', $arrFields, $this->secret_key);
		
        foreach ($arrFields as $strFieldName => $strFieldValue) {
            $args_array[] = '<input type="hidden" name="'.esc_attr($strFieldName).'" value="'.esc_attr($strFieldValue).'" />';
        }

		echo '<form action="'.esc_url("https://paybox.kz/payment.php").'" method="POST" id="paybox_payment_form">'."\n".
            implode("\n", $args_array).
            '<input type="submit" class="button alt" id="submit_paybox_payment_form" value="'.__('Оплатить', 'woocommerce').'" />'. 
//			'<a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Отказаться от оплаты & вернуться в корзину', 'woocommerce').'</a>'."\n".
            '</form>
			<script type="text/javascript">
			setTimeout(function () {
				document.getElementById("paybox_payment_form").submit();
			}, 1000);
			</script>';
	}
	
	/**
	* Check Response
	**/
	function check_assistant_response(){
		global $woocommerce;
		
		if(!empty($_POST))
			$arrRequest = $_POST;
		else
			$arrRequest = $_GET;
		
		$thisScriptName = PG_Signature::getOurScriptName();
		if (empty($arrRequest['pg_sig']) || !PG_Signature::check($arrRequest['pg_sig'], $thisScriptName, $arrRequest, $this->secret_key))
			die("Wrong signature");

		$objOrder = new WC_Order($arrRequest['pg_order_id']);

		$arrResponse = array();
		$aGoodCheckStatuses = array('pending','processing');
		$aGoodResultStatuses = array('pending','processing','completed');
		
		switch($_GET['type']){
			case 'check':
				$bCheckResult = 1;			
				if(empty($objOrder) || !in_array($objOrder->status, $aGoodCheckStatuses)){
					$bCheckResult = 0;
					$error_desc = 'Order status '.$objOrder->status.' or deleted order';
				}
				if(intval($objOrder->order_total) != intval($arrRequest['pg_amount'])){
					$bCheckResult = 0;
					$error_desc = 'Wrong amount';
				}

				$arrResponse['pg_salt']              = $arrRequest['pg_salt']; 
				$arrResponse['pg_status']            = $bCheckResult ? 'ok' : 'error';
				$arrResponse['pg_error_description'] = $bCheckResult ?  ""  : $error_desc;
				$arrResponse['pg_sig']				 = PG_Signature::make($thisScriptName, $arrResponse, $this->secret_key);

				$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
				$objResponse->addChild('pg_salt', $arrResponse['pg_salt']);
				$objResponse->addChild('pg_status', $arrResponse['pg_status']);
				$objResponse->addChild('pg_error_description', $arrResponse['pg_error_description']);
				$objResponse->addChild('pg_sig', $arrResponse['pg_sig']);
				break;
				
			case 'result':	
				if(intval($objOrder->order_total) != intval($arrRequest['pg_amount'])){
					$strResponseDescription = 'Wrong amount';
					if($arrRequest['pg_can_reject'] == 1)
						$strResponseStatus = 'rejected';
					else
						$strResponseStatus = 'error';
				}
				elseif((empty($objOrder) || !in_array($objOrder->status, $aGoodResultStatuses)) && 
						!($arrRequest['pg_result'] == 0 && $objOrder->status == 'failed')){
					$strResponseDescription = 'Order status '.$objOrder->status.' or deleted order';
					if($arrRequest['pg_can_reject'] == 1)
						$strResponseStatus = 'rejected';
					else
						$strResponseStatus = 'error';
				} else {
					$strResponseStatus = 'ok';
					$strResponseDescription = "Request cleared";
					if ($arrRequest['pg_result'] == 1){
						$objOrder->update_status('completed', __('Платеж успешно оплачен', 'woocommerce'));
						WC()->cart->empty_cart();
					}
					else{
						$objOrder->update_status('failed', __('Платеж не оплачен', 'woocommerce'));
						WC()->cart->empty_cart();
					}
				}

				$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
				$objResponse->addChild('pg_salt', $arrRequest['pg_salt']);
				$objResponse->addChild('pg_status', $strResponseStatus);
				$objResponse->addChild('pg_description', $strResponseDescription);
				$objResponse->addChild('pg_sig', PG_Signature::makeXML($thisScriptName, $objResponse, $this->secret_key));
				
				break;
			case 'success':
				wp_redirect( $this->get_return_url( $objOrder ) );
				break;
			case 'failed':
				wp_redirect($objOrder->get_cancel_order_url());
				break;
			default :
				die('wrong type');
		}
		
		header("Content-type: text/xml");
		echo $objResponse->asXML();
		die();
	}

}

/**
 * Add the gateway to WooCommerce
 **/
function add_paybox_gateway($methods){
	$methods[] = 'WC_Paybox';
	return $methods;
}

add_filter('woocommerce_payment_gateways', 'add_paybox_gateway');
}
?>
