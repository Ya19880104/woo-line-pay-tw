<?php

require_once __DIR__ . '/trait-wc-gateway-linepay-recovery.php';

/**
 * LINEPay Payment Gateway
 *
 * @class WC_Gateway_LINEPay
 * @extends WC_Payment_Gateway
 * @version 3.0.0
 * @author LINEPay
 */
class WC_Gateway_LINEPay extends WC_Payment_Gateway {

	use WC_Gateway_LINEPay_Recovery;

	protected $linepay_currency_scales;
	protected $linepay_supported_currencies;
	protected $linepay_supported_order_statuses;
	protected $linepay_lang_cd;
	protected $enable_sandbox;
	protected $linepay_evn_status;
	protected $linepay_payment_type;
	protected $linepay_payment_action;
	protected $linepay_shipping_enabled;
	protected $linepay_log_info;
	protected $linepay_channel_info;
	protected $linepay_refundable_statuses;
	protected $linepay_is_valid;
	protected $linepay_logo;

	/**
	 * The logger object
	 *
	 * @var WC_Gateway_LINEpay_Logger
	 */
	private static $logger;


	/**
	 * LINEPay Gateway
	 */
	public function __construct() {

		// Define the default status of LINEPay Gateway.
		$this->init_gateway_data();

		// Define information to be supported by LINEPay Gateway.
		$this->init_gateway_supports();

		// Define form field to show in admin setting.
		$this->init_form_fields();

		// Extract data registered in form field.
		$this->init_merchant_data();

		$this->init_linepay_logo();

		$this->init_settings();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		static::$logger = WC_Gateway_LINEPay_Logger::get_instance( $this->linepay_log_info );

		// exception class.
		include_once( 'class-wc-gateway-linepay-exception.php' );
	}

	/**
	 * Initialize the default state of the Gateway.
	 */
	private function init_gateway_data() {
		$this->id                 = WC_Gateway_LINEPay_Const::ID;
		$this->title              = $this->get_option( 'title' );
		$this->description        = $this->get_option( 'description' );
		$this->has_fields         = false;
		$this->order_button_text  = __( 'Pay with LINE Pay', 'woocommerce-gateway-linepay' );
		$this->method_title       = WC_Gateway_LINEPay_Const::TITLE;
		$this->method_description = WC_Gateway_LINEPay_Const::DESC;
	}

	/**
	 * Initialize the information to be supported by LINEPay Gateway.
	 * -Purchase or refund
	 * -Supported countries
	 * -Support currency
	 * -Information on refund status of manager and buyer
	 */
	private function init_gateway_supports() {
		// Support refund function.
		$this->supports = array(
			'products',
			'refunds',
		);

		// Currency scale information.
		$this->linepay_currency_scales = array(
			'TWD' => 0,
		);

		// LINE Pay supported currency.
		$this->linepay_supported_currencies     = array( 'TWD' );
		$this->linepay_supported_order_statuses = array(
			WC_Gateway_LINEPay_Const::USER_STATUS_ADMIN    => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ),
			// The customer's refund information is set to be available only when the product is not received.
			WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER => array( 'processing', /* 'pending', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' */ )
		);
	}

	/**
	 * Form fields Initialize the information.
	 *
	 * @see WC_Settings_API::init_form_fields()
	 * @see WC_Gateway_LINEPay_Settings->get_form_fields()
	 */
	public function init_form_fields() {

		add_action( 'woocommerce_admin_field_media', array( $this, 'add_custom_logo_field'), 10, 1 );

		include_once( 'class-wc-gateway-linepay-settings.php');

		$settings          = new WC_Gateway_LINEPay_Settings( $this );
		$this->form_fields = $settings->get_form_fields();
	}

	/**
	 * Initialize the information registered in form fields to the fields of LINEPay Gateway.
	 * Fields newly defined for LINEPay are separated by prefixing with linepay_.
	 */
	protected function init_merchant_data() {

		$this->enabled                = wc_string_to_bool( $this->get_option( 'enabled' ) );
		$this->linepay_lang_cd        = $this->get_option( 'lang_cd' );
		$this->enable_sandbox         = wc_string_to_bool( $this->get_option( 'sandbox' ) );
		$this->linepay_evn_status     = ( $this->enable_sandbox ) ? WC_Gateway_LINEPay_Const::ENV_SANDBOX : WC_Gateway_LINEPay_Const::ENV_REAL;
		$this->linepay_payment_type   = $this->get_option( 'payment_type' );
		$this->linepay_payment_action = $this->get_option( 'payment_action' );

		$this->linepay_shipping_enabled = false;

		$this->linepay_log_info = array(
			'enabled' => wc_string_to_bool( $this->get_option( 'log_enabled' ) ),
			'level'   => $this->get_option( 'log_level', WC_Gateway_LINEPay_Logger::LOG_LEVEL_NONE ),
		);

		$this->linepay_channel_info = array(
			WC_Gateway_LINEPay_Const::ENV_REAL => array(
				'channel_id'     => $this->get_option( 'channel_id' ),
				'channel_secret' => $this->get_option( 'channel_secret' ),
			),
			WC_Gateway_LINEPay_Const::ENV_SANDBOX => array(
				'channel_id'     => $this->get_option( 'sandbox_channel_id' ),
				'channel_secret' => $this->get_option( 'sandbox_channel_secret' ),
			),
		);

		$this->linepay_refundable_statuses = array(
			WC_Gateway_LINEPay_Const::USER_STATUS_ADMIN    => $this->get_option( 'admin_refund' ),
			WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER => $this->get_option( 'customer_refund' ),
		);

		// LINEPay Gateway Check whether it is used.
		$this->linepay_is_valid = $this->is_valid_for_use();
		if ( is_wp_error( $this->linepay_is_valid ) ) {
			$this->enabled = false;
		}
	}

	/**
	 * When paying with LINE Pay, the logo image is returned.
	 * If no custom logo is specified, the default logo is returned.
	 */
	protected function init_linepay_logo() {

		$this->linepay_logo = $this->get_option( 'custom_logo' );

		// If custom logo is not registered, use default logo image.
		if ( ! $this->linepay_logo ) {
			$this->linepay_logo = $this->get_linepay_logo_path( $this->get_option( 'general_logo_size' ) );
			$this->linepay_logo = plugins_url( $this->linepay_logo, plugin_dir_path( __FILE__ ) );
		}

		$this->icon = $this->linepay_logo;

		add_filter( 'woocommerce_gateway_icon', array( $this, 'set_transparent_icon' ), 10, 2 );
	}


	/**
	 * Provides order statuses information for each requester.
	 *
	 * @see wc_get_order_statuses()
	 * @param string $requester => const:WC_Gateway_LINEPay_Const::USER_STATUS_ADMIN|CUSTOMER.
	 * @return array
	 */
	function wc_get_order_statuses( $requester ) {

		$all_order_statuses = wc_get_order_statuses();
		$new_order_statuses = array();
		$order_statuses = $this->linepay_supported_order_statuses[ $requester ];

		foreach ( $order_statuses as $value ) {
			$new_key                        = 'wc-' . $value;
			$new_order_statuses[ $new_key ] = $all_order_statuses[ $new_key ];
		}

		return $new_order_statuses;
	}

	/**
	 * Override the Admin option.
	 */
	public function admin_options() {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_media();

		wp_register_script( 'logo-uploader', untrailingslashit( plugins_url( '../', __FILE__ ) ) . WC_Gateway_LINEPay_Const::RESOURCE_JS_LOGO_UPLOADER, array(), '1.0.0', true );
		wp_enqueue_script( 'logo-uploader' );

?>
		<h3><?php echo ( ! empty( $this->method_title ) ) ? $this->method_title : __( 'Settings', 'woocommerce' ); ?></h3>

		<?php if ( is_wp_error( $this->linepay_is_valid ) ) : ?>
			<div class="updated woocommerce-message">
				<div class="squeezer">
					<h4><?php echo $this->linepay_is_valid->get_error_message(); ?></h4>
				</div>
			</div>
		<?php endif; ?>

		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table>
	<?php
	}

	/**
	 * Process payments and return results.
	 * To pay with LINE Pay, reserve-api is first called.
	 * Override the parent process_payment function.
	 * return the success and redirect in an array. e.g:
	 * return array(
	 *    'result'   => 'success',
	 *    'redirect' => $this->get_return_url( $order )
	 * );
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$lock = $this->acquire_payment_lock( $order_id );
		if ( ! $lock ) {
			wc_add_notice( __( 'LINE Pay is checking your payment. Please try again shortly.', 'woocommerce-gateway-linepay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		try {
			$order = wc_get_order( $order_id );
			if ( ! $order || $this->id !== $order->get_payment_method() || ! $order->needs_payment() || $order->get_date_paid() ) {
				return array( 'result' => 'failure' );
			}
			$previous = (string) $order->get_meta( '_linepay_reserved_transaction_id' );
			if ( $this->valid_transaction_id( $previous ) ) {
				$reservation = $order->get_meta( '_linepay_reservation' );
				$status = $this->reservation_matches_channel( $reservation ) ? $this->request_payment_status( $previous ) : '';
				$payment_url = $order->get_meta( '_linepay_payment_url' );
				if ( '0000' === $status && $payment_url && is_array( $reservation ) &&
					isset( $reservation['amount'], $reservation['currency'] ) && $reservation['amount'] === $this->payment_amount( $order ) &&
					$reservation['currency'] === $order->get_currency() ) {
					return array( 'result' => 'success', 'redirect' => $payment_url );
				}
				if ( ! $this->previous_request_has_ended( $order, $previous, $status, $reservation ) ) {
					wc_add_notice( __( 'A LINE Pay payment is already pending for this order. Finish that payment in LINE Pay or check again shortly.', 'woocommerce-gateway-linepay' ), 'error' );
					return array( 'result' => 'failure' );
				}
			}
			return $this->process_payment_reserve( $order );
		} finally {
			$this->release_payment_lock( $lock );
		}
	}

	private function previous_request_has_ended( $order, $transaction_id, $status, $reservation ) {
		if ( ! in_array( $status, array( '0121', '0122', '1159' ), true ) ) {
			return false;
		}
		$amount = isset( $reservation['amount'] ) ? $reservation['amount'] : $this->payment_amount( $order );
		return 'none' === $this->inspect_payment_details( $order, $transaction_id, $amount, false );
	}

	/**
	 * Process the refund and return the result.
	 *
	 * This method is called only when the administrator processes it.
	 * When the administrator requests a refund, the process_refund() method is called through WC_AJAX::refund_line_items().
	 *
	 * The woocommerce_delete_shop_order_transients action
	 * This action occurs immediately before woocommerce completes the refund process when a manager requests a refund and gives a json response.
	 *
	 * @see WC_AJAX::refund_line_items()
	 * @see	woocommerce::action - woocommerce_delete_shop_order_transients
	 * @param int $order_id
	 * @param float $amount
	 * @param string $reason
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return $this->process_refund_request( WC_Gateway_LINEPay_Const::USER_STATUS_ADMIN, $order_id, $amount, $reason );
	}


	/**
	 * Call LINE Pay's reserve-api and return the result.
	 * Change the order status according to the api call result.
	 *
	 * Request successful
	 * -post-meta fixes
	 *
	 * Request failed
	 * -fix order_status
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	private function process_payment_reserve( $order ) {
		$order_id = $order->get_id();
		$currency = $order->get_currency();
		$amount = $this->payment_amount( $order );
		if ( '' === $amount ) {
			wc_add_notice( __( 'LINE Pay requires a positive whole-dollar TWD amount.', 'woocommerce-gateway-linepay' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$capture = $this->is_captured();
		$state = wp_generate_password( 32, false, false );
		$callback_url = add_query_arg(
			array( 'order_id' => $order_id, 'linepay_state' => $state ),
			home_url( WC_Gateway_LINEPay_Const::URI_CALLBACK_HANDLER )
		);

		$body = array(
			'orderId' => (string) $order_id,
			'amount' => (int) $amount,
			'currency' => $currency,
			'packages' => $this->get_product_info( $order ),
			'redirectUrls' => array(
				'confirmUrl' => esc_url_raw( add_query_arg( 'request_type', WC_Gateway_LINEPay_Const::REQUEST_TYPE_CONFIRM, $callback_url ) ),
				'confirmUrlType' => WC_Gateway_LINEPay_Const::CONFIRM_URLTYPE_CLIENT,
				'cancelUrl' => esc_url_raw( add_query_arg( 'request_type', WC_Gateway_LINEPay_Const::REQUEST_TYPE_CANCEL, $callback_url ) ),
			),
			'options' => array(
				'payment' => array(
					'payType' => strtoupper( $this->linepay_payment_type ),
					'capture' => $capture,
				),
			),
		);
		$branch_name = trim( $this->get_option( 'branch_name', '' ) );
		if ( '' !== $branch_name ) {
			$body['options']['extra'] = array( 'branchName' => $branch_name );
		}

		$url = $this->get_request_url( WC_Gateway_LINEPay_Const::REQUEST_TYPE_RESERVE );
		$info = static::execute( $this, $url, $body );
		$payment_url = isset( $info->paymentUrl->web ) && is_string( $info->paymentUrl->web ) ? esc_url_raw( $info->paymentUrl->web, array( 'https' ) ) : '';
		if ( ! isset( $info->transactionId ) || ! $this->valid_transaction_id( $info->transactionId ) || ! $payment_url ) {
			static::$logger->error( 'process_payment_reserve', is_wp_error( $info ) ? $info->get_error_message() : 'Missing LINE Pay transaction or payment URL.' );
			wc_add_notice( __( 'Unable to process payment request. Please try again.', 'woocommerce-gateway-linepay' ), 'error' );
			if ( WC()->session ) {
				WC()->session->set( 'order_awaiting_payment', false );
			}
			$order->update_status( 'failed' );
			return array( 'result' => 'failure', 'redirect' => wc_get_cart_url() );
		}

		$transaction_id = (string) $info->transactionId;
		$channel = $this->get_channel_info();
		$order->update_meta_data( '_linepay_payment_status', WC_Gateway_LINEPay_Const::PAYMENT_STATUS_RESERVED );
		$order->update_meta_data( '_linepay_reserved_transaction_id', $transaction_id );
		$order->update_meta_data( '_linepay_refund_info', array() );
		$order->update_meta_data( '_linepay_callback_state', $state );
		$order->update_meta_data( '_linepay_payment_url', $payment_url );
		$order->update_meta_data( '_linepay_reservation', array(
			'amount'   => $amount,
			'currency' => $currency,
			'capture'  => $capture,
			'host'     => $this->get_request_host(),
			'channel'  => (string) $channel['channel_id'],
		) );
		$order->delete_meta_data( '_linepay_confirm_started' );
		$order->delete_meta_data( '_linepay_recovery_stopped' );
		$order->save_meta_data();

		$this->schedule_recovery( $order, $transaction_id );
		return array( 'result' => 'success', 'redirect' => $payment_url );
	}

	/**
	 * Handle the customer return separately from the background job's session-free workflow.
	 */
	public function process_payment_confirm( $order_id, $transaction_id = '', $state = '' ) {
		$target = wc_get_checkout_url();
		$lock = $this->acquire_payment_lock( $order_id );
		try {
			$order = wc_get_order( $order_id );
			if ( $order && $this->id === $order->get_payment_method() && $this->valid_transaction_id( $transaction_id ) &&
				(string) $order->get_meta( '_linepay_reserved_transaction_id' ) === $transaction_id ) {
				$expected_state = (string) $order->get_meta( '_linepay_callback_state' );
				$trusted = '' !== $expected_state && hash_equals( $expected_state, $state );
				$legacy = '' === $expected_state;
				if ( $trusted || ( $legacy && current_user_can( 'view_order', $order_id ) ) ) {
					$target = $this->get_return_url( $order );
				}
				if ( $lock && ( $trusted || $legacy ) && $this->can_recover_payment( $order, $transaction_id ) ) {
					$result = $this->reconcile_payment( $order, $transaction_id, 'browser' );
					if ( in_array( $result, array( 'review', 'authorized', 'captured_review' ), true ) ) {
						$order->update_meta_data( '_linepay_recovery_stopped', $result );
						$order->save_meta_data();
						$this->add_recovery_note( $order, $result );
					}
					$order = wc_get_order( $order_id );
				}
				if ( ( $trusted || $legacy ) && $order->is_paid() && WC()->session && WC()->cart &&
					(int) WC()->session->get( 'order_awaiting_payment' ) === $order_id ) {
					WC()->cart->empty_cart();
				}
			}
		} finally {
			if ( $lock ) {
				$this->release_payment_lock( $lock );
			}
		}
		wp_safe_redirect( $target );
		exit;
	}

	public function process_payment_cancel( $order_id, $transaction_id = '', $state = '' ) {
		$lock = $this->acquire_payment_lock( $order_id );
		if ( $lock ) {
			try {
				$order = wc_get_order( $order_id );
				if ( $order && $this->id === $order->get_payment_method() ) {
					$expected_state = (string) $order->get_meta( '_linepay_callback_state' );
					$reserved_id = (string) $order->get_meta( '_linepay_reserved_transaction_id' );
					$valid_state = '' !== $expected_state && hash_equals( $expected_state, $state );
					$legacy = '' === $expected_state && $reserved_id === $transaction_id;
					if ( ( $valid_state || $legacy ) && $this->valid_transaction_id( $reserved_id ) &&
						$this->can_recover_payment( $order, $reserved_id ) && ! $order->get_meta( '_linepay_confirm_started' ) &&
						$this->reservation_matches_channel( $order->get_meta( '_linepay_reservation' ) ) &&
						'0121' === $this->request_payment_status( $reserved_id ) ) {
						$order->update_meta_data( '_linepay_payment_status', WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CANCELLED );
						$order->update_status( 'cancelled' );
						if ( WC()->session && (int) WC()->session->get( 'order_awaiting_payment' ) === $order_id ) {
							WC()->session->set( 'order_awaiting_payment', false );
						}
						wc_add_notice( __( 'Payment canceled.', 'woocommerce-gateway-linepay' ), 'notice' );
					}
				}
			} finally {
				$this->release_payment_lock( $lock );
			}
		}
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Request LINEPay's refund-api and return the result.
	 *
	 * @param string $requester => const:WC_Gateway_LINEPay_Const::USER_STATUS_ADMIN|CUSTOMER
	 * @param int $order_id
	 * @param string $refund_amount => wc_format_decimal()
	 * @param string $reason
	 * @return boolean(true) |WP_Error
	 */
	function process_refund_request ( $requester, $order_id, $refund_amount, $reason = '') {

		$order             = wc_get_order( $order_id );
		if ( false === $order) {

			return new WP_Error( 'process_refund_request', sprintf( __( 'Unable to find order #%s', 'woocommerce-gateway-linepay' ), $order_id ), array(
				'requester'     => $requester,
				'order_id'      => $order_id,
				'refund_amount' => $std_refund_amount,
			));
		}

		$std_amount = $this->get_standardized( $order->get_total(), $order->get_currency() );
		$std_refund_amount = $this->get_standardized( $refund_amount );
		$transaction_id = $order->get_transaction_id();
		$order_id       = $order->get_id();
		$order_status   = $order->get_status();

		// If the requester is in a non-refundable state.
		if ( ! in_array( 'wc-' . $order_status, $this->linepay_refundable_statuses[ $requester ] ) ) {

			return new WP_Error(
				'process_refund_request',
				__( 'Unable to refund order due to its current status.', 'woocommerce-gateway-linepay' ),
				array(
					'requester'      => $requester,
					'order_id'       => $order_id,
					'transaction_id' => $transaction_id,
					'order_status'   => $order_status,
				)
			);
		}

		// Customer standard verification.
		if ( WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER === $requester ) {

			$std_balanced_amount = $this->get_standardized( $order->get_meta( '_linepay_transaction_balanced_amount' ) , $order->get_currency() );
			// Only the transaction itself can be canceled. No refund is possible if the refund amount is different from the total.
			if ( $std_amount !== $std_balanced_amount ) {

				return new WP_Error(
					'process_refund_request',
					__( 'Refund amount does not match total purchase amount.', 'woocommerce-gateway-linepay' ),
					array(
						'requester'      => $requester,
						'order_id'       => $order_id,
						'transaction_id' => $transaction_id,
						'order_status'   => $order_status,
						'amount'         => $std_amount,
						'refund_amount'  => $std_refund_amount,
					)
				);
			}
		}

		$result = $this->request_refund_api( $order, $transaction_id, $std_refund_amount, $requester );

		// When the customer successfully refunds, the order status is canceled.
		if ( $result && $requester === WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER ) {
			$order->update_status( 'cancelled' );
		}

		return $result;
	}

	/**
	 * Call the refund API and store the information DB according to the result.
	 * 1. Save refund information in the form of serialized array
	 * 2. After refund, the balance of the transaction amount is stored in string form.
	 *
	 * @param WC_Order $order_id
	 * @param string $transaction_id
	 * @param number|string $refund_amount
	 * @param string $requestrer
	 * @return boolean(true)|WP_Error
	 */
	private function request_refund_api( $order, $transaction_id, $refund_amount, $requester = null ) {

		$order_id          = $order->get_id();
		$std_refund_amount = $this->get_standardized( $refund_amount );

		$url  = $this->get_request_url( WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND, array( 'transaction_id' => $transaction_id ) );
		$body = array( 'refundAmount' => $std_refund_amount );
		$info = static::execute( $this, $url, $body );

		// On request failure.
		if ( is_wp_error( $info ) ) {
			static::$logger->error( 'request_refund_api', $info );
			return $info;
		}

		// Save refund transaction information.
		$refund_info                               = (array) maybe_unserialize( $order->get_meta( '_linepay_refund_info' ) );
		$refund_info[ $info->refundTransactionId ] = array(
			'requester' => $requester,
			'date'      => $info->refundTransactionDate,
		);
		$order->update_meta_data( '_linepay_refund_info', $refund_info );

		// Amount balance revision.
		$balanced_amount		= $order->get_meta( '_linepay_transaction_balanced_amount' );
		$new_balanced_amount	= $this->get_standardized( floatval( $balanced_amount ) - floatval( $std_refund_amount ), $order->get_currency() );
		$order->update_meta_data( '_linepay_transaction_balanced_amount', $new_balanced_amount );
		$order->save_meta_data();

		return true;
	}


	/**
	 * Returns whether the currency is supported.
	 *
	 * @param	string $currency
	 * @return	boolean
	 */
	private function is_supported_currency( $currency ) {
		return in_array( $currency, $this->linepay_supported_currencies );
	}

	/**
	 * Returns HOST information that matches the environmental information of LINEPay Gateway.
	 *
	 * @return string
	 */
	private function get_request_host() {
		$host = '';

		switch ( $this->linepay_evn_status ) {
			case WC_Gateway_LINEPay_Const::ENV_SANDBOX:
				$host = WC_Gateway_LINEPay_Const::HOST_SANDBOX;
				break;

			case WC_Gateway_LINEPay_Const::ENV_REAL:
			default:
				$host = WC_Gateway_LINEPay_Const::HOST_REAL;
				break;
		}

		return $host;
	}

	/**
	 * Returns the uri that matches the request type.
	 * If the uri contains variables, it is combined with args to create a new uri.
	 *
	 * @param string $type	=> const:WC_Gateway_LINEPay_Const::REQUEST_TYPE_RESERVE|CONFIRM|CANCEL|REFUND
	 * @param array $args
	 * @return string
	 */
	private function get_request_uri( $type, $args ) {
		$uri = '';

		switch ($type) {
			case WC_Gateway_LINEPay_Const::REQUEST_TYPE_RESERVE:
				$uri = WC_Gateway_LINEPay_Const::URI_RESERVE;
				break;
			case WC_Gateway_LINEPay_Const::REQUEST_TYPE_CONFIRM:
				$uri = WC_Gateway_LINEPay_Const::URI_CONFIRM;
				break;
			case WC_Gateway_LINEPay_Const::REQUEST_TYPE_DETAILS:
				$uri = WC_Gateway_LINEPay_Const::URI_DETAILS;
				break;
			case WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND:
				$uri = WC_Gateway_LINEPay_Const::URI_REFUND;
				break;
		}

		$new_uri = $uri;
		foreach ( $args as $key => $value ) {
			$new_uri = str_replace( '{' . $key . '}', $value, $new_uri );
		}

		return $new_uri;
	}

	/**
	 * Whether to capture or not is returned according to the payment type of LINEPay.
	 *
	 * @return boolean
	 */
	private function is_captured() {

		switch ( $this->linepay_payment_action ) {
			case WC_Gateway_LINEPay_Const::PAYMENT_ACTION_AUTH_CAPTURE:
				return true;

			case WC_Gateway_LINEPay_Const::PAYMENT_ACTION_AUTH:
			default:
				return false;
		}
	}

	/**
	 * Returns whether LINEPay Gateway can be used.
	 * -Accepted currency
	 * -Input channel information
	 *
	 * @return boolean|WP_Error
	 */
	private function is_valid_for_use() {

		// Return if not already used.
		if ( ! $this->enabled ) {
			return false;
		}

		// Accepted Currency.
		$cur_currency = get_woocommerce_currency();
		if ( ! $this->is_supported_currency( $cur_currency ) ) {
			return new WP_Error( 'linepay_not_supported_currency', sprintf( '[%s] ' . __( 'Unsupported currency.', 'woocommerce-gateway-linepay' ), $cur_currency ), $cur_currency );
		}

		// Channel information by usage environment.
		$channel_info = $this->get_channel_info();
		if ( empty( $channel_info['channel_id'] ) || empty( $channel_info['channel_secret'] ) ) {

			return new WP_Error( 'linepay_empty_channel_info', sprintf( '[%s] ' . __( 'You have not entered your channel information.', 'woocommerce-gateway-linepay' ), $this->linepay_evn_status ) );
		}

		return true;
	}


	/**
	 * Returns channel information that matches the environment information of LINEPay Gateway.
	 *
	 * @return array
	 */
	private function get_channel_info() {
		return $this->linepay_channel_info[ $this->linepay_evn_status ];
	}

	/**
	 * Returns the URL that matches the request type.
	 *
	 * @param string $type	=> const:WC_Gateway_LINEPay_Const::REQUEST_TYPE_RESERVE|CONFIRM|CANCEL|REFUND
	 * @param array $args
	 * @return string
	 */
	private function get_request_url( $type, $args = array() ) {
		$host = $this->get_request_host();
		$uri  = $this->get_request_uri( $type, $args );

		return $host . $uri;
	}

	public function line_write_log( $log ) {
		if ( is_array( $log ) || is_object( $log ) ) {
			error_log( print_r( $log, true ) );
		} else {
			error_log( $log );
		}
	}

	/**
	 * Returns the array to be transferred to reserve-api based on the order information.
	 * The array contains productName and productImageUrl.
	 *
	 * productName
	 * -1: Name of the first item
	 * -2 or more: first item name + remaining items
	 *
	 * productImageUrl
	 * -URL information of the first item
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	private function get_product_info( $order ) {
		$packages     = array();
		$items        = $order->get_items();
		$order_amount = 0;

		// item_lines.
		if ( count( $items ) > 0 ) {

			$products     = array();
			$total_amount = 0;


			$first_item = $items[ array_key_first( $items ) ];
			$wc_product = wc_get_product( $first_item->get_product_id() );
			$order_name = $wc_product->get_name();

			if ( count( $items ) > 1 ) {
				$order_name = $order_name . '等共' . $order->get_item_count() . '個商品';
			}

			$product = array(
				'id'       => $first_item->get_product_id(),
				'name'     => sanitize_text_field( $order_name ),
				'quantity' => 1,
				'price'    => $order->get_total(),
			);

			// 取第一個商品的圖案.
			$thumbnail_image_urls = wp_get_attachment_image_src( get_post_thumbnail_id( $first_item->get_product_id() ) );

			if ( isset( $thumbnail_image_urls[0] ) ) {
				$product['imageUrl'] = $thumbnail_image_urls[0];
			}

			array_push( $products, $product );

			array_push(
				$packages,
				array(
					'id'       => 'WC-ITEMS||' . $order->get_id(),
					'name'     => sanitize_text_field( 'WC_ITEMS' ),
					'amount'   => $this->get_standardized( $order->get_total() ),
					'products' => $products,
				)
			);
		} //end items.


		return $packages;
	}


	/**
	 * Returns the default logo image address of LINE Pay that fits the entered logo size.
	 *
	 * @param String $general_logo_size The logo size.
	 * @return String $logo_path
	 */
	private function get_linepay_logo_path( $general_logo_size) {
		return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_PREFIX . $this->get_linepay_logo($general_logo_size);
	}


	/**
	 * Returns the name of the default logo image of LinePay that fits the entered logo size.
	 *
	 * @param String $general_logo_size The logo size.
	 * @return String $img_name
	 */
	private function get_linepay_logo( $general_logo_size ) {

		switch ( $general_logo_size ) {

			case '1':
				return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_1;
			case '2':
				return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_2;
			case '3':
				return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_3;
			case '4':
				return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_4;
			case '6':
				return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_6;
			case '5':
			default:
				return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_5;
		}
	}

	/**
	 * When paying with LINE Pay, the background color of the logo image is changed to transparent.
	 *
	 * @param String $icon_tag
	 * @param String $id
	 * @return String $icon_tag
	 */
	public function set_transparent_icon( $icon_tag, $id ) {

		if ( WC_Gateway_LINEPay_Const::ID !== $id ) {
			return $icon_tag;
		}

		return str_replace( '/>', 'style="background:transparent;"', $icon_tag );
	}

	/**
	 * Returns the scale of the received currency code
	 * Use BaseCurrencyCode when there is no information received
	 *
	 * @param string $currency_code The currency_code.
	 * @return number
	 */
	private function get_currency_scale( $currency_code = null ) {

		if ( null === $currency_code ) {
			$currency_code = get_woocommerce_currency();
		}

		$currency_code = strtoupper( $currency_code );

		if ( isset( $this->linepay_currency_scales[ $currency_code ] ) ) {
			return $this->linepay_currency_scales[ $currency_code ];
		} else {
			// Scale of unset currency is set to 0.
			return 0;
		}
	}

	/**
	 * Returns the number_format for the currency
	 *
	 * @param number|string $amount
	 * @param string $currency
	 * @return string
	 */
	private function get_standardized( $amount, $currency = null ) {
		$scale = $this->get_currency_scale( $currency );

		if ( is_string( $amount ) ) {
			$amount = floatval( $amount );
		}

		return number_format( $amount, $scale, '.', '' );
	}


	/**
	 * Sends a request based on the transmitted information and returns the result.
	 * When requesting LINEPay, create a header to be used in common.
	 *
	 * @param WC_Payment_Gateway $linepay_gateway
	 * @param string $url
	 * @param array $body
	 * @param int $timeout
	 * @return mixed|WP_Error Return info object or WP_Error
	 */
	private static function execute( $linepay_gateway, $url, $body = null, $timeout = 20, $method = 'POST' ) {

		$channel_info = $linepay_gateway->get_channel_info();

		$request_time = wp_generate_uuid4();

		$request_body = '';
		if ( ! is_null( $body ) ) {
			if ( is_array( $body ) ) {
				$request_body = wp_json_encode( $body );
			} else {
				$request_body = $body;
			}
		}

		$headers = array(
			'content-type'               => 'application/json; charset=UTF-8',
			'X-LINE-ChannelId'           => $channel_info['channel_id'],
			'X-LINE-Authorization-Nonce' => $request_time,
			'X-LINE-Authorization'       => static::generate_signature( $channel_info['channel_secret'], $url, $request_body, $request_time ),
		);

		$request_args = array(
			'httpversion' => '1.1',
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => $headers,
		);

		if ( is_array( $body ) ) {
			$request_args = array_merge( $request_args, array( 'body' => $request_body ) );
		}

		if ( 'POST' === $method ) {
			$response = wp_remote_post( $url, $request_args );
		} elseif ( 'GET' === $method ) {
			$response = wp_remote_get( $url, $request_args );
		}


		// static::$logger->error( '[response] http_response_not_success', 'http response code is ' . $http_status . json_encode( $response) );

		// maybe timeout
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_status = (int) $response['response']['code'];
		if ( 200 !== $http_status ) {

			return new WP_Error(
				'[request] http_response_not_success',
				'http response code is ' . $http_status,
				array(
					'url' => $url,
				)
			);
		}

		$response_body       = static::json_custom_decode( wp_remote_retrieve_body( $response ) );
		if ( ! is_object( $response_body ) || ! isset( $response_body->returnCode ) || ! is_string( $response_body->returnCode ) ) {
			return new WP_Error( 'linepay_invalid_response', 'Invalid LINE Pay response.' );
		}
		$linepay_return_code = $response_body->returnCode;

		if ( '0000' !== $linepay_return_code) {
			return new WP_Error( '[request] linepay_response_failure', 'linepay return code is ' . $linepay_return_code, $response_body );
		}

		return isset( $response_body->info ) ? $response_body->info : null;
	}

	/**
	 * Hange large integer to json's string format.
	 *
	 * @param String $json The json string.
	 * @return mixed
	 */
	private static function json_custom_decode( $json ) {
		if ( version_compare( PHP_VERSION, '5.4.0', '>=' ) ) {
			return json_decode( $json, false, 512, JSON_BIGINT_AS_STRING );
		} else {
			return json_decode( preg_replace( '/:\s?(\d{14,})/', ': "${1}"', $json ) );
		}
	}

	/**
	* Generate signature
	*
	* @param [type] $channel_secret
	* @param [type] $url
	* @param [type] $request_body
	* @param [type] $nonce
	* @return void
	*/
	private static function generate_signature( $channel_secret, $url, $request_body, $nonce ) {
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		$data     = $channel_secret . $url_path . $request_body . $nonce;
		return base64_encode( hash_hmac( WC_Gateway_LINEPay_Const::AUTH_ALGRO, $data, $channel_secret, true ) );
	}
}
