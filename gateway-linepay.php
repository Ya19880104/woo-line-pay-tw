<?php
/**
 * Plugin Name: WooCommerce LINE Pay Gateway for Taiwan
 * Plugin URI: https://pay.line.me
 * Description: LINE Pay Gateway for Taiwan
 * Author: LINE Pay
 * Author URI: https://pay.line.me
 * Version: 1.1.2
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * WC requires at least: 3.0.0
 * WC tested up to: 3.0.x, 3.2.x, 3.6.x
 *
 * Copyright (c) 2015 LINEPay
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Plugin updates
 * Localization
 */
load_plugin_textdomain( 'woocommerce-gateway-linepay', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) . '/languages' ) );

/**
 *
 * WC_Gateway_LINEPay_Handler
 * 1. woocommerce WC_Gateway_LINEPay register
 * 2. Added to be refundable on the User Account tab
 * 3. Handle callback requests
 *
 * @class WC_Gateway_LINEPay_Handler
 * @version 1.0.0
 * @author LINEPay
 */
class WC_Gateway_LINEPay_Handler {

	/**
	 * The logger object
	 *
	 * @var WC_Gateway_LINEpay_Logger
	 */
	private static $logger;
	private $linepay_settings;

	/**
	 * Constructor function
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init_wc_gateway_linepay_handler' ) );
	}

	/**
	 * WC_Gateway_LINEPay_Handler Initialize
	 */
	public function init_wc_gateway_linepay_handler() {

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		include_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-gateway-linepay-const.php';
		include_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-gateway-linepay-logger.php';
		include_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-gateway-linepay.php';

		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
		add_filter( 'woocommerce_my_account_my_orders_title', array( $this, 'append_script_for_refund_action' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'change_customer_order_action' ), 10, 2 );
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'handle_callback' ) );
		add_action( WC_Gateway_LINEPay::RECOVERY_HOOK, array( $this, 'recover_payment' ), 10, 3 );
		add_filter( 'woocommerce_order_actions', array( $this, 'add_reconcile_order_action' ), 10, 2 );
		add_action( 'woocommerce_order_action_linepay_reconcile_payment', array( $this, 'reconcile_order_payment' ) );
		add_action( 'woocommerce_refund_created', array( $this, 'finish_admin_refund' ), 10, 2 );

		// linepay setting.
		$this->linepay_settings = (array) get_option( 'woocommerce_' . WC_Gateway_LINEPay_Const::ID . '_settings', array() );

		// logger.
		$linepay_log_info = array(
			'enabled' => ! empty( $this->linepay_settings['log_enabled'] ) && wc_string_to_bool( $this->linepay_settings['log_enabled'] ),
			'level'   => ! empty( $this->linepay_settings['log_level'] ) ? $this->linepay_settings['log_level'] : WC_Gateway_LINEPay_Logger::LOG_LEVEL_NONE,
		);

		static::$logger = WC_Gateway_LINEPay_Logger::get_instance( $linepay_log_info );
	}

	/**
	 * WooCommerce LINEPay payment provider
	 *
	 * @see woocommerce::filter - woocommerce_payment_gateways
	 * @param array $methods Payment methods.
	 * @return array
	 */
	public function add_gateway( $methods ) {
		$methods[] = 'WC_Gateway_LINEPay';
		return $methods;
	}

	public function recover_payment( $order_id, $transaction_id, $attempt ) {
		$gateway = new WC_Gateway_LINEPay();
		$gateway->recover_payment( $order_id, $transaction_id, $attempt );
	}

	public function add_reconcile_order_action( $actions, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			global $theorder;
			$order = $theorder;
		}
		if ( $order instanceof WC_Order && WC_Gateway_LINEPay_Const::ID === $order->get_payment_method() &&
			$order->get_meta( '_linepay_reserved_transaction_id' ) && current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			$actions['linepay_reconcile_payment'] = __( 'LINE Pay 付款對帳', 'woocommerce-gateway-linepay' );
		}
		return $actions;
	}

	public function reconcile_order_payment( $order ) {
		if ( $order instanceof WC_Order && current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			$gateway = new WC_Gateway_LINEPay();
			$gateway->reconcile_order_payment( $order->get_id() );
		}
	}

	public function finish_admin_refund( $refund_id, $args ) {
		if ( empty( $args['refund_payment'] ) || empty( $args['order_id'] ) ) {
			return;
		}
		$order_id = absint( $args['order_id'] );
		add_action( 'shutdown', function () use ( $refund_id, $order_id ) {
			$refund = WC_Gateway_LINEPay::fresh_order( $refund_id );
			$order = WC_Gateway_LINEPay::fresh_order( $order_id );
			if ( ! $refund instanceof WC_Order_Refund || ! $order instanceof WC_Order ||
				! method_exists( $refund, 'get_refunded_payment' ) ||
				(int) $refund->get_parent_id() !== $order_id || ! $refund->get_refunded_payment() ||
				WC_Gateway_LINEPay_Const::ID !== $order->get_payment_method() ||
				'review' !== $order->get_meta( '_linepay_refund_pending' ) ) {
				return;
			}
			$order->delete_meta_data( '_linepay_refund_pending' );
			$order->save_meta_data();
		}, PHP_INT_MAX );
	}

	/**
	 * Dispatch the client return from LINE Pay or an authenticated customer refund.
	 */
	public function handle_callback() {
		$order_id = absint( $this->callback_parameter( 'order_id' ) );
		$request_type = $this->callback_parameter( 'request_type' );
		if ( ! $order_id ) {
			return;
		}

		$gateway = new WC_Gateway_LINEPay();
		switch ( $request_type ) {
			case WC_Gateway_LINEPay_Const::REQUEST_TYPE_CONFIRM:
				if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ||
					(string) $order_id !== $this->callback_parameter( 'orderId' ) ) {
					return;
				}
				$gateway->process_payment_confirm( $order_id, $this->callback_parameter( 'transactionId' ), $this->callback_parameter( 'linepay_state' ) );
				break;
			case WC_Gateway_LINEPay_Const::REQUEST_TYPE_CANCEL:
				if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ||
					( '' !== $this->callback_parameter( 'orderId' ) && (string) $order_id !== $this->callback_parameter( 'orderId' ) ) ) {
					return;
				}
				$gateway->process_payment_cancel( $order_id, $this->callback_parameter( 'transactionId' ), $this->callback_parameter( 'linepay_state' ) );
				break;
			case WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND:
				if ( ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) || ! wp_verify_nonce( $this->callback_parameter( '_wpnonce' ), 'linepay_refund_' . $order_id ) ) {
					wp_send_json_error( array( 'info' => __( 'Invalid refund request.', 'woocommerce-gateway-linepay' ) ), 403 );
				}
				$result = $gateway->process_customer_refund( $order_id );
				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'info' => $result->get_error_message() ), 400 );
				}
				wp_send_json_success( array( 'info' => 'fully_refunded' ) );
				break;
		}
	}

	private function callback_parameter( $name ) {
		$source = '_wpnonce' === $name ? $_POST : $_GET;
		return isset( $source[ $name ] ) && is_string( $source[ $name ] ) ? wp_unslash( $source[ $name ] ) : '';
	}

	/**
	 * Register a script file to help consumers with refund processing and a script to contain language information to be used internally.
	 * I used the woocommerce_my_account_my_orders_title filter to register only the first time when my account is loaded.
	 * Therefore, no change is made to the title.
	 *
	 * @see woocommerce::filter - woocommerce_my_account_my_orders_title
	 * @param String $title
	 * @return String
	 */
	public function append_script_for_refund_action( $title ) {
		$handle = 'wc-gateway-linepay-customer-refund-action';
		wp_register_script( $handle, untrailingslashit( plugins_url( '/', __FILE__ ) ) . WC_Gateway_LINEPay_Const::RESOURCE_JS_CUSTOMER_REFUND_ACTION, array( 'jquery' ), '1.1.2', true );
		wp_localize_script( $handle, 'linepayRefundText', array(
			'process_refund' => __( 'Processing refund...', 'woocommerce-gateway-linepay' ),
			'request_refund' => __( 'Request refund for order {order_id}', 'woocommerce-gateway-linepay' ),
			'cancel'         => __( 'Cancel', 'woocommerce-gateway-linepay' ),
		) );
		wp_enqueue_script( $handle );
		return $title;
	}


	/**
	 *
	 * Add a user's refund action for each order in my account.
	 * You can change the user's refund status in the admin setting.
	 * Actions that can be re-purchased or canceled when Linepay payment fails.
	 *
	 * @see woocommerce:filter - woocommerce_my_account_my_orders_actions
	 * @param array $actions
	 * @param WC_Order $order
	 * @return array
	 */
	public function change_customer_order_action( $actions, $order ) {
		$order_status = $order->get_status();
		if ( WC_Gateway_LINEPay_Const::ID !== $order->get_payment_method() ) {
			return $actions;
		}
		if ( 'failed' === $order_status ) {
			unset( $actions['cancel'] );
		}
		if ( $order->get_refunds() || $order->get_meta( '_linepay_refund_pending' ) ) {
			return $actions;
		}
		$refundable = isset( $this->linepay_settings['customer_refund'] ) ? (array) $this->linepay_settings['customer_refund'] : array();
		if ( in_array( 'wc-' . $order_status, $refundable, true ) ) {
			$actions['cancel'] = array(
				'url'  => esc_url_raw( add_query_arg(
					array(
						'request_type'  => WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND,
						'order_id'      => $order->get_id(),
						'_wpnonce'      => wp_create_nonce( 'linepay_refund_' . $order->get_id() ),
					),
					home_url( WC_Gateway_LINEPay_Const::URI_CALLBACK_HANDLER )
				) ),
				'name' => __( 'Cancel', 'woocommerce-gateway-linepay' ),
			);
		}

		return $actions;
	}
}

$GLOBALS['wc_gateway_linepay_handler'] = new WC_Gateway_LINEPay_Handler();
