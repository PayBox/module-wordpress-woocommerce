<?php
/**
 * Plugin Name: WooCommerce PayBox Gateway
 * Description: Receive payments using the PayBox payments provider.
 * Author: PayBox
 * Author URI: https://paybox.money/
 * Version: 5.5.1
 * WC tested up to: 6.0
 * WC requires at least: 2.6
 *
 * Copyright (c) 2014-2021 WooCommerce
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) ) {
	require_once( 'paybox/woo-includes/woo-functions.php' );
}

/**
 * Initialize the gateway.
 * @since 1.0.0
 */
function woocommerce_paybox_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	define( 'WC_GATEWAY_PAYBOX_VERSION', '5.5' );

	require_once( plugin_basename( 'paybox/includes/class-wc-gateway-paybox.php' ) );
	load_plugin_textdomain( 'woocommerce-gateway-paybox', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ) );
	add_filter( 'woocommerce_payment_gateways', 'woocommerce_paybox_add_gateway' );
}

add_action( 'plugins_loaded', 'woocommerce_paybox_init', 0 );

function woocommerce_paybox_plugin_links( $links ) {
	$settings_url = add_query_arg(
		array(
			'page' => 'wc-settings',
			'tab' => 'checkout',
			'section' => 'wc_gateway_paybox',
		),
		admin_url( 'admin.php' )
	);

	$plugin_links = array(
		'<a href="' . esc_url( $settings_url ) . '">' . __( 'Настройки', 'woocommerce-gateway-paybox' ) . '</a>',
		'<a href="https://paybox.money/ru_ru/developers">' . __( 'Документы', 'woocommerce-gateway-paybox' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'woocommerce_paybox_plugin_links' );


/**
 * Add the gateway to WooCommerce
 * @since 1.0.0
 */
function woocommerce_paybox_add_gateway( $methods ) {
	$methods[] = 'WC_Gateway_PayBox';
	return $methods;
}
