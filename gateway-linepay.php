<?php
/**
 * Plugin Name: WooCommerce LINE Pay Gateway for Taiwan
 * Plugin URI: https://pay.line.me
 * Description: LINE Pay Gateway for Taiwan
 * Author: LINE Pay
 * Author URI: https://pay.line.me
 * Version: 1.1.1
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
				$gateway->process_payment_confirm( $order_id, $this->callback_parameter( 'transactionId' ), $this->callback_parameter( 'linepay_state' ) );
				break;
			case WC_Gateway_LINEPay_Const::REQUEST_TYPE_CANCEL:
				$gateway->process_payment_cancel( $order_id, $this->callback_parameter( 'transactionId' ), $this->callback_parameter( 'linepay_state' ) );
				break;
			case WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND:
				$order = wc_get_order( $order_id );
				if ( $order && WC_Gateway_LINEPay_Const::ID === $order->get_payment_method()
					&& WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CONFIRMED === $order->get_meta( '_linepay_payment_status' )
					&& get_current_user_id() && (int) $order->get_customer_id() === get_current_user_id()
					&& wp_verify_nonce( $this->callback_parameter( '_wpnonce' ), 'linepay_refund_' . $order_id ) ) {
					$this->process_refund_by_customer( $gateway, $order_id );
				}
				break;
		}
	}

	private function callback_parameter( $name ) {
		return isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? wp_unslash( $_GET[ $name ] ) : '';
	}

	/**
	 * Process the user's refund request.
	 *
	 * If the user requests a refund, WC_AJAX::refund_line_items() is executed when the administrator requests.
	 * Since it cannot be called first, define a new method to handle it.
	 *
	 * Create a refund order to return the quantity, total amount, tax, and request a refund.
	 *
	 * @see	WC_AJAX::refund_line_items()
	 * @param WC_Gateway_LINEPay $linepay_gateway
	 * @param int $order_id
	 * @throws Exception
	 */
	public function process_refund_by_customer( $linepay_gateway, $order_id ) {
		$order         = wc_get_order( $order_id );
		$refund_amount = wc_format_decimal( $this->callback_parameter( 'cancel_amount' ) );
		$refund_reason = sanitize_text_field( $this->callback_parameter( 'reason' ) );


		$line_items       = array();
		$items            = $order->get_items();
		$shipping_methods = $order->get_shipping_methods();

		// items.
		foreach ( $items as $item_id => $item ) {
			$line_tax_data          = unserialize( $item['line_tax_data'] );
			$line_item              = array(
				'qty'          => $item['qty'],
				'refund_total' => wc_format_decimal( $item['line_total'] ),
				'refund_tax'   => $line_tax_data['total'],
			);
			$line_items[ $item_id ] = $line_item;
		}

		// shipping.
		foreach ( $shipping_methods as $shipping_id => $shipping ) {
			$line_item                  = array(
				'refund_total' => wc_format_decimal( $shipping['cost'] ),
				'refund_tax'   => unserialize( $shipping['taxes'] ),
			);
			$line_items[ $shipping_id ] = $line_item;
		}

		$refund = null;
		try {
			$refund = wc_create_refund(
				array(
					'amount'     => $refund_amount,
					'reason'     => $refund_reason,
					'order_id'   => $order_id,
					'line_items' => $line_items,
				)
			);

			if ( is_wp_error( $refund ) ) {
				throw new Exception( $refund->get_error_message() );
			}

			// Refund processing
			$result = $linepay_gateway->process_refund_request( WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER, $order_id, $refund_amount, $refund_reason );

			if ( is_wp_error( $result ) || ! $result ) {
				static::$logger->error( 'process_refund_request_by_customer', $result );

				throw new Exception( $result->get_error_message() );
			}

			// Item quantity return.
			foreach ( $items as $item_id => $item ) {
				$qty      = $item['qty'];
				$_product = $order->get_product_from_item( $item );

				if ( $_product && $_product->exists() && $_product->managing_stock() ) {
					$old_stock = wc_stock_amount( $_product->stock );

					$new_quantity = wc_update_product_stock( $_product, $qty, 'increase', true );

					$order->add_order_note( sprintf( __( 'Item #%s stock increased from %s to %s.', 'woocommerce' ), $order_item['product_id'], $old_stock, $new_quantity ) );

					do_action( 'woocommerce_restock_refunded_item', $_product->id, $old_stock, $new_quantity, $order );
				}
			}

			wc_delete_shop_order_transients( $order_id );

			wc_add_notice( __( 'Refund complete.', 'woocommerce_gateway_linepay' ) );
			wp_send_json_success( array( 'info' => 'fully_refunded' ) );

		} catch ( Exception $e ) {

			if ( $refund && is_a( $refund, 'WC_Order_Refund' ) ) {
				wp_delete_post( $refund->id, true );
			}

			wc_add_wp_error_notices( new WP_Error( 'process_refund_by_customer', __( 'Unable to process refund. Please try again.', 'woocommerce_gateway_linepay' ) ) );
			wp_send_json_error( array( 'info' => $e->getMessage() ) );
		}

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

		// Registration of consumer refund processing script.
		wp_register_script( 'wc-gateway-linepay-customer-refund-action', untrailingslashit( plugins_url( '/', __FILE__ ) ) . WC_Gateway_LINEPay_Const::RESOURCE_JS_CUSTOMER_REFUND_ACTION );
		wp_enqueue_script( 'wc-gateway-linepay-customer-refund-action' );

		// Register language information to be used in script.
		$lang_process_refund = __( 'Processing refund...', 'woocommerce-gateway-linepay' );
		$lang_request_refund = __( 'Request refund for order {order_id}', 'woocommerce-gateway-linepay' );
		$lang_cancel         = __( 'Cancel', 'woocommerce-gateway-linepay' );

		$lang_script = '<script>
					function linepay_lang_pack() {
						return { \'process_refund\': \'' . $lang_process_refund . '\',
								\'request_refund\':\'' . $lang_request_refund . '\',
								\'cancel\':\'' . $lang_cancel . '\'
							};
					}
				</script>';
		echo $lang_script;

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
		$refundable = isset( $this->linepay_settings['customer_refund'] ) ? (array) $this->linepay_settings['customer_refund'] : array();
		if ( in_array( 'wc-' . $order_status, $refundable, true ) ) {
			$actions['cancel'] = array(
				'url'  => esc_url_raw( add_query_arg(
					array(
						'request_type'  => WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND,
						'order_id'      => $order->get_id(),
						'cancel_amount' => $order->get_total(),
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
