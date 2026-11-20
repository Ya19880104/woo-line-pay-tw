<?php
/**
 * Run locally: php tests/linepay-regression.php [plugin-directory]
 * All orders, HTTP requests, options and scheduled tasks live in memory.
 */
if ( 'cli' !== PHP_SAPI ) { exit; }
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ );
define( 'DB_NAME', 'test-db' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ORDER_ID', 31415 );
define( 'TRANSACTION', '2026112100000000001' );
define( 'OTHER_TRANSACTION', '2026112100000000002' );

class WP_Error {
	public function __construct( private $code = '', private $message = '', private $data = null ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message( $code = '' ) { return $this->message; }
	public function get_error_data( $code = '' ) { return $this->data; }
}

class WC_Payment_Gateway {
	public function get_option( $key, $default = '' ) {
		return $GLOBALS['gateway_settings'][ $key ] ?? $this->form_fields[ $key ]['default'] ?? $default;
	}

	public function init_settings() {}
	public function get_return_url( $order = null ) { return 'https://test.invalid/return'; }
}

class Test_WPDB {
	public $prefix = 'wp_';
	public $base_prefix = 'wp_';
	public $options = 'wp_options';
	public $locks = array();
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function get_var( $call ) {
		list( $statement, $args ) = $call;
		$key = $args[0];
		if ( str_contains( $statement, 'GET_LOCK(' ) ) {
			if ( isset( $this->locks[ $key ] ) || $GLOBALS['force_busy_lock'] ) { return '0'; }
			$this->locks[ $key ] = true;
			return '1';
		}
		if ( str_contains( $statement, 'RELEASE_LOCK(' ) ) {
			unset( $this->locks[ $key ] );
			return '1';
		}
		throw new RuntimeException( 'Unexpected database command.' );
	}
}
class WC_Order {
	public $meta = array();
	public $payment_count = 0;
	public $transaction = '';
	public $paid_at = null;
	public $status = 'pending';
	public $method = 'linepay';
	public $total = '150';
	public $currency = 'TWD';
	public $notes = array();
	public $refunds = array();
	public function get_id() { return ORDER_ID; }
	public function get_payment_method( $context = 'view' ) { return $this->method; }
	public function get_total( $context = 'view' ) { return $this->total; }
	public function get_currency( $context = 'view' ) { return $this->currency; }
	public function get_status( $context = 'view' ) { return $this->status; }
	public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
	public function is_paid() { return $this->has_status( array( 'processing', 'completed' ) ); }
	public function needs_payment() { return $this->has_status( array( 'pending', 'failed' ) ); }
	public function get_date_paid( $context = 'view' ) { return $this->paid_at; }
	public function get_transaction_id( $context = 'view' ) { return $this->transaction; }
	public function set_transaction_id( $id ) { $this->transaction = $id; }
	public function get_total_refunded() { return '0'; }
	public function get_refunds() { return $this->refunds; }
	public function get_customer_id() { return 17; }
	public function get_items() { return array(); }
	public function get_order_key() { return 'test-order-key'; }
	public function key_is_valid( $key ) { return $key === $this->get_order_key(); }
	public function get_meta( $key, $single = true, $context = 'view' ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function add_meta_data( $key, $value, $unique = false ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function save_meta_data() {}
	public function read_meta_data( $force = false ) {}
	public function save() { return ORDER_ID; }
	public function set_status( $status, $note = '' ) { $this->update_status( $status, $note ); }
	public function update_status( $status, $note = '' ) { $this->status = $status; $this->notes[] = $note; return true; }
	public function add_order_note( $note ) { $this->notes[] = $note; }

	public function payment_complete( $id ) {
		++$this->payment_count;
		if ( ! $this->has_status( array( 'pending', 'failed', 'on-hold', 'cancelled' ) ) ) {
			return true;
		}
		$this->transaction = (string) $id;
		$this->paid_at = time();
		$this->status = 'processing';
		return true;
	}
}

function __( $message, $domain = '' ) { return $message; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_current_user_id() { return $GLOBALS['user_id'] ?? 0; }
function maybe_unserialize( $value ) { return $value; }
function is_serialized( $value ) { return is_string( $value ) && preg_match( '/^a:[0-9]+:/', $value ); }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'test-nonce'; }
class Test_Json extends RuntimeException {
	public $status;
	public function __construct( $message, $status ) { parent::__construct( $message ); $this->status = $status; }
}
function wp_send_json_error( $payload, $status = 200 ) { throw new Test_Json( $payload['info'], $status ); }
function wp_send_json_success( $payload, $status = 200 ) { throw new Test_Json( 'success', $status ); }
class WC_Order_Refund {
	public $payment_refunded = false;
	public $parent_id = ORDER_ID;
	public function get_parent_id() { return $this->parent_id; }
	public function get_refunded_payment() { return $this->payment_refunded; }
	public function set_refunded_payment( $value ) { $this->payment_refunded = $value; }
	public function save() { return true; }
}
function wc_create_refund( $args ) {
	$GLOBALS['local_refund_calls']++;
	if ( $GLOBALS['throw_local_refund'] ) { throw new RuntimeException( 'Local refund storage failed' ); }
	if ( $GLOBALS['fail_local_refund'] ) { return new WP_Error( 'local_refund_failed', 'Could not record Woo refund' ); }
	$refund = new WC_Order_Refund();
	$GLOBALS['order']->refunds[] = $refund;
	$GLOBALS['order']->status = 'refunded';
	return $refund;
}
function wp_json_encode( $data ) { return json_encode( $data, JSON_THROW_ON_ERROR ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( $value ); }
function esc_url_raw( $value ) { return $value; }
function wp_generate_uuid4() { return sprintf( 'badcafe0-0000-4000-8000-%012d', ++$GLOBALS['nonce_counter'] ); }
function wp_generate_password( $length = 12, $special = true, $extra = false ) { return substr( wp_generate_uuid4(), -$length ); }
function get_current_blog_id() { return 1; }
function current_time( $type, $gmt = false ) { return time(); }
function wp_get_environment_type() { return 'local'; }
function load_plugin_textdomain( ...$args ) {}
function plugin_basename( $file ) { return basename( $file ); }
function plugin_dir_path( $file ) { return dirname( $file ) . DIRECTORY_SEPARATOR; }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function home_url( $path = '' ) { return 'https://test.invalid' . $path; }
function add_query_arg( $args, $url_or_value, $url = null ) {
	if ( ! is_array( $args ) ) {
		$args = array( $args => $url_or_value );
	} else {
		$url = $url_or_value;
	}
	return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $args );
}
function add_filter( ...$args ) { add_action( ...$args ); }
function add_action( $hook, $callback, $priority = 10, $accepted = 1 ) { $GLOBALS['hooks'][ $hook ][] = array( $callback, $accepted ); }
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['hooks'][ $hook ] ?? array() as list( $callback, $accepted ) ) {
		$callback( ...array_slice( $args, 0, $accepted ) );
	}
}
function apply_filters( $hook, $value, ...$args ) { return $value; }
function did_action( $hook ) { return 1; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $key, $GLOBALS['options'] ) ) { return false; }
	$GLOBALS['options'][ $key ] = $value;
	return true;
}
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function wp_using_ext_object_cache() { return false; }
function get_transient( $key ) { return get_option( $key ); }
function set_transient( $key, $value, $expiration = 0 ) { return update_option( $key, $value ); }
function delete_transient( $key ) { return delete_option( $key ); }
function wc_get_order( $id ) { return (int) $id === ORDER_ID ? $GLOBALS['order'] : ( $GLOBALS['refund_by_id'][ (int) $id ] ?? false ); }
function get_post_meta( $id, $key, $single = false ) { return wc_get_order( $id )->get_meta( $key, $single ); }
function update_post_meta( $id, $key, $value ) { wc_get_order( $id )->update_meta_data( $key, $value ); return true; }
function add_post_meta( $id, $key, $value, $unique = false ) { wc_get_order( $id )->add_meta_data( $key, $value, $unique ); return true; }
function delete_post_meta( $id, $key ) { wc_get_order( $id )->delete_meta_data( $key ); return true; }
function wc_get_order_statuses() {
	$statuses = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
	return array_combine( array_map( static fn ( $status ) => 'wc-' . $status, $statuses ), $statuses );
}
function wc_get_is_paid_statuses() { return array( 'processing', 'completed' ); }
function wc_get_log_file_path( $handle ) { return $handle; }
function wc_get_cart_url() { return 'https://test.invalid/cart'; }
function wc_get_checkout_url() { return 'https://test.invalid/checkout'; }
function current_user_can( $capability, ...$args ) { return false; }
function wp_safe_redirect( $target, $status = 302, $source = 'WordPress' ) { throw new Test_Redirect( $target ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }

class Test_Redirect extends RuntimeException {}
function get_woocommerce_currency() { return 'TWD'; }
function get_bloginfo( $value ) { return 'Local test shop'; }
function get_blog_option( $site, $key, $default = false ) { return get_option( $key, $default ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_sanitize_redirect( $value ) { return $value; }
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) { return 0; }
function wp_clear_scheduled_hook( $hook, $args = array() ) { return 0; }
function wc_string_to_bool( $value ) { return in_array( $value, array( 'yes', '1', 1, true ), true ); }
function wc_format_decimal( $amount, $dp = false, $trim_zeros = false ) { return number_format( (float) $amount, $dp === false ? 2 : $dp, '.', '' ); }
function wc_add_wp_error_notices( $error ) { throw new RuntimeException( 'Unexpected checkout notice: ' . $error->get_error_message() ); }
function wc_add_notice( $notice, $type = 'success' ) { $GLOBALS['notices'][] = array( $notice, $type ); }
class Test_Session {
	public function get( $key ) { return $GLOBALS['session_order_id'] ?? null; }
	public function set( $key, $value ) { $GLOBALS['session_order_id'] = $value; }
}
function WC() {
	if ( $GLOBALS['browser_request'] ) { return $GLOBALS['browser_wc'] ?: (object) array( 'session' => $GLOBALS['session_order_id'] ? new Test_Session() : null, 'cart' => null ); }
	throw new RuntimeException( 'Background recovery unexpectedly attempted to access the customer session.' );
}

function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
	$GLOBALS['scheduled'][] = array( 'timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'group' => $group );
	return count( $GLOBALS['scheduled'] );
}

function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['scheduled'] as $action ) {
		if ( $hook === $action['hook'] && ( null === $args || $args === $action['args'] ) && $group === $action['group'] ) {
			return true;
		}
	}
	return false;
}

function as_next_scheduled_action( $hook, $args = null, $group = '' ) { return as_has_scheduled_action( $hook, $args, $group ); }
function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) { as_schedule_single_action( $timestamp, $hook, $args ); return true; }
function wp_next_scheduled( $hook, $args = array() ) { return as_next_scheduled_action( $hook, $args ); }

function wp_remote_retrieve_body( $result ) { return $result['body'] ?? ''; }
function wp_remote_retrieve_response_code( $result ) { return $result['response']['code'] ?? ''; }
function wp_remote_post( $url, $options ) { return mock_http_request( 'POST', $url, $options ); }
function wp_remote_get( $url, $options ) { return mock_http_request( 'GET', $url, $options ); }
function wp_remote_request( $url, $options ) { return mock_http_request( $options['method'] ?? 'GET', $url, $options ); }

function mock_http_request( $method, $url, $options ) {
	$path = parse_url( $url, PHP_URL_PATH );
	$query = parse_url( $url, PHP_URL_QUERY ) ?? '';
	$body = $options['body'] ?? '';
	$nonce = $options['headers']['X-LINE-Authorization-Nonce'] ?? '';
	$signature = base64_encode( hash_hmac(
		'sha256',
		'test-signing-value' . $path . ( 'GET' === $method ? $query : $body ) . $nonce,
		'test-signing-value',
		true
	) );
	same( $signature, $options['headers']['X-LINE-Authorization'] ?? '', 'Correct LINE Pay v3 signature' );
	$key = match ( true ) {
		'GET' === $method && $path === '/v3/payments' => 'details',
		'GET' === $method && $path === '/v3/payments/requests/' . TRANSACTION . '/check' => 'check',
		'POST' === $method && $path === '/v3/payments/' . TRANSACTION . '/refund' => 'refund',
		'POST' === $method && $path === '/v3/payments/' . TRANSACTION . '/confirm' => 'confirm',
		'POST' === $method && $path === '/v3/payments/request' => 'reserve',
		default => throw new RuntimeException( 'Unexpected HTTP request: ' . $method . ' ' . $url ),
	};

	if ( 'details' === $key ) {
		parse_str( $query, $params );
		same( TRANSACTION, $params['transactionId'], 'Exact payment details transaction ID' );
	}

	if ( 'refund' === $key ) {
		$decoded = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
		same( $GLOBALS['expected_refund_amount'] ?? 150, $decoded['refundAmount'], 'LINE Pay refund sends an integer' );
	}
	if ( 'confirm' === $key ) {
		$decoded = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
		same( 150, $decoded['amount'], 'Confirm sends reserved amount as a JSON number' );
		same( 'TWD', $decoded['currency'], 'Confirm checks reserved currency' );
		if ( $options['timeout'] < 40 ) { throw new RuntimeException( 'Confirm timeout must be at least 40 seconds.' ); }
	}

	$GLOBALS['requests'][] = $key;
	if ( empty( $GLOBALS['responses'][ $key ] ) ) {
		throw new RuntimeException( 'Unexpected or repeated ' . $key . ' call.' );
	}
	$result = array_shift( $GLOBALS['responses'][ $key ] );
	if ( $result instanceof WP_Error ) { return $result; }
	$body = is_string( $result ) ? $result : json_encode( $result, JSON_THROW_ON_ERROR );
	// Emit the transaction as a large JSON integer to exercise exact decoding.
	$body = str_replace( '"' . TRANSACTION . '"', TRANSACTION, $body );
	return array( 'response' => array( 'code' => 200 ), 'body' => $body );
}

function same( $expected, $actual, $description ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $description . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function reset_scenario( $status = 'pending', $payment_state = 'reserved' ) {
	$GLOBALS['wpdb'] = new Test_WPDB();
	$GLOBALS['force_busy_lock'] = false;
	$GLOBALS['browser_request'] = false;
	$GLOBALS['browser_wc'] = null;
	$GLOBALS['session_order_id'] = null;
	$GLOBALS['notices'] = array();
	$GLOBALS['order'] = new WC_Order();
	$GLOBALS['refund_by_id'] = array();
	unset( $GLOBALS['hooks']['shutdown'] );
	$GLOBALS['order']->status = $status;
	$GLOBALS['order']->meta = array(
		'_order_total' => '150',
		'_linepay_reserved_transaction_id' => TRANSACTION,
		'_linepay_payment_status' => $payment_state,
		'_linepay_reservation' => array(
			'amount' => '150',
			'currency' => 'TWD',
			'capture' => true,
			'host' => 'https://api-pay.line.me',
			'channel' => 'test-channel',
		),
	);
	$GLOBALS['responses'] = array();
	$GLOBALS['requests'] = array();
	$GLOBALS['scheduled'] = array();
	$GLOBALS['options'] = array();
	$GLOBALS['user_id'] = 17;
	$GLOBALS['local_refund_calls'] = 0;
	$GLOBALS['fail_local_refund'] = false;
	$GLOBALS['throw_local_refund'] = false;
	$GLOBALS['expected_refund_amount'] = 150;
	$GLOBALS['order']->transaction = TRANSACTION;
	$GLOBALS['order']->meta['_linepay_transaction_balanced_amount'] = '150';
}

function response( $code, $info = null ) {
	$data = array( 'returnCode' => $code, 'returnMessage' => 'Fixture' );
	if ( null !== $info ) { $data['info'] = $info; }
	return $data;
}

function record( $changes = array() ) {
	return array_replace( array(
		'orderId' => (string) ORDER_ID,
		'transactionId' => TRANSACTION,
		'currency' => 'TWD',
		'transactionType' => 'PAYMENT',
		'payStatus' => 'CAPTURE',
		'payInfo' => array( array( 'method' => 'BALANCE', 'amount' => 120 ), array( 'method' => 'POINT', 'amount' => 30 ) ),
	), $changes );
}

function did_pay( $status = 'processing' ) {
	$order = $GLOBALS['order'];
	same( 1, $order->payment_count, 'Exactly one WooCommerce completion' );
	same( $status, $order->status, 'WooCommerce order status' );
	same( TRANSACTION, $order->transaction, 'WooCommerce keeps full transaction ID' );
	same( 'confirmed', $order->get_meta( '_linepay_payment_status' ), 'LINE Pay status finalized' );
	same( '150', (string) $order->get_meta( '_linepay_transaction_balanced_amount' ), 'Amount available for refunds' );
}

function did_not_pay( $status = 'pending' ) {
	$order = $GLOBALS['order'];
	same( 0, $order->payment_count, 'Order completion not invoked' );
	same( $status, $order->status, 'WooCommerce order status retained' );
}

$directory = isset( $argv[1] ) ? $argv[1] : dirname( __DIR__ );
reset_scenario();
$GLOBALS['nonce_counter'] = 0;
$GLOBALS['gateway_settings'] = array(
	'enabled' => 'yes',
	'payment_action' => 'authorization/capture',
	'channel_id' => 'test-channel',
	'channel_secret' => 'test-signing-value',
	'custom_logo' => 'https://test.invalid/logo',
	'log_enabled' => 'no',
	'customer_refund' => array( 'wc-processing' ),
	'admin_refund' => array( 'wc-processing' ),
);
$GLOBALS['options']['woocommerce_linepay_settings'] = $GLOBALS['gateway_settings'];
require $directory . '/gateway-linepay.php';
do_action( 'plugins_loaded' );
$gateway = new WC_Gateway_LINEPay();

$tests = array();
$tests['successful checkout records the reservation and schedules recovery'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['order']->meta = array( '_order_total' => '150' );
	$GLOBALS['responses']['reserve'] = array( response( '0000', array(
		'transactionId' => TRANSACTION,
		'paymentUrl' => array( 'web' => 'https://test.invalid/linepay' ),
	) ) );
	$result = $gateway->process_payment( ORDER_ID );
	same( array( 'result' => 'success', 'redirect' => 'https://test.invalid/linepay' ), $result, 'Checkout redirects to LINE Pay' );
	same( TRANSACTION, $GLOBALS['order']->get_meta( '_linepay_reserved_transaction_id' ), 'Request stores exact LINE Pay transaction ID' );
	$snapshot = $GLOBALS['order']->get_meta( '_linepay_reservation' );
	same( '150', (string) $snapshot['amount'], 'Original payable amount saved' );
	same( 'TWD', $snapshot['currency'], 'Original currency saved' );
	same( true, $snapshot['capture'], 'Original capture mode saved' );
	same( count( $gateway::RECOVERY_DELAYS ), count( $GLOBALS['scheduled'] ), 'Checkout schedules bounded delayed checks' );
	$action = array_shift( $GLOBALS['scheduled'] );
	same( true, $action['timestamp'] > time(), 'First lookup is delayed' );

	$GLOBALS['responses']['details'] = array( response( '0000', array( record() ) ) );
	$GLOBALS['responses']['check'] = array( response( '0123' ) );
	do_action( $action['hook'], ...array_values( $action['args'] ) );
	did_pay();
};
$tests['repeated checkout resumes its original payment URL and creates no new charge'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['order']->meta = array();
	$GLOBALS['order']->total = '150.00';
	$web = 'https://test.invalid/linepay';
	$GLOBALS['responses']['reserve'] = array( response( '0000', array(
		'transactionId' => TRANSACTION,
		'paymentUrl' => array( 'web' => $web ),
	) ) );
	$GLOBALS['responses']['check'] = array( response( '0000' ) );
	$expected = array( 'result' => 'success', 'redirect' => $web );
	same( $expected, $gateway->process_payment( ORDER_ID ), 'Initial checkout accepts TWD formatted with zero cents' );
	$actions = $GLOBALS['scheduled'];
	same( $expected, $gateway->process_payment( ORDER_ID ), 'Retry returns original hosted payment URL' );
	same( array( 'reserve', 'check' ), $GLOBALS['requests'], 'Only one transaction issued' );
	same( $actions, $GLOBALS['scheduled'], 'Retry adds no delayed jobs for the same transaction' );
	same( array(), $GLOBALS['notices'], 'Retry shows no error notice' );
};

$tests['a fractional TWD total is rejected before checkout sends a reservation'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['order']->meta = array();
	$GLOBALS['order']->total = '150.49';
	same( 'failure', $gateway->process_payment( ORDER_ID )['result'], 'Fractional TWD is rejected' );
	same( array(), $GLOBALS['requests'], 'Fractional amount sends no remote requests' );
	same( array(), $GLOBALS['scheduled'], 'Fractional amount enqueues no recovery' );
	same( 'error', $GLOBALS['notices'][0][1], 'Buyer sees checkout validation error' );
};

$tests['a fractional edit to the order total does not match the original whole TWD amount'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['order']->total = '150.49';
	$GLOBALS['responses']['check'] = array( response( '0110' ) );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_not_pay();
	same( array(), $GLOBALS['requests'], 'A fractional order edit neither queries nor confirms an existing transaction' );
};

$tests['on-hold after lost Confirm is completed from CAPTURE once'] = static function () use ( $gateway ) {
	reset_scenario( 'on-hold', 'failed' );
	$GLOBALS['responses']['details'] = array( response( '0000', array( record() ) ) );
	$GLOBALS['responses']['check'] = array( response( '0123' ) );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_pay();
	$requests = $GLOBALS['requests'];
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_pay();
	same( $requests, $GLOBALS['requests'], 'Duplicate recovery performs no remote calls' );
};

$tests['valid browser return reconciles through the same gateway workflow'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['browser_request'] = true;
	$GLOBALS['order']->update_meta_data( '_linepay_callback_state', 'expected-state' );
	$confirmed = record();
	unset( $confirmed['currency'], $confirmed['payStatus'], $confirmed['transactionType'] );
	$GLOBALS['responses']['confirm'] = array( response( '0000', $confirmed ) );
	$_GET = array(
		'order_id' => (string) ORDER_ID,
		'orderId' => (string) ORDER_ID,
		'transactionId' => TRANSACTION,
		'linepay_state' => 'expected-state',
		'request_type' => 'confirm',
	);
	$_SERVER['REQUEST_METHOD'] = 'GET';
	try {
		do_action( 'woocommerce_api_wc_gateway_linepay_handler' );
		throw new RuntimeException( 'Callback did not redirect' );
	} catch ( Test_Redirect $redirect ) {
		same( 'https://test.invalid/return', $redirect->getMessage(), 'Customer redirected to order receipt' );
	} finally {
		$_GET = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}
	did_pay();
	same( array( 'confirm' ), $GLOBALS['requests'], 'Trusted browser callback made one Confirm request' );
};

$tests['paid order callback preserves a newer shopping cart'] = static function () {
	reset_scenario( 'processing', 'confirmed' );
	$GLOBALS['order']->transaction = TRANSACTION;
	$GLOBALS['order']->paid_at = time();
	$GLOBALS['order']->update_meta_data( '_linepay_callback_state', 'expected-state' );
	$GLOBALS['browser_request'] = true;
	$session = new class() { public function get( $key ) { return ORDER_ID; } };
	$cart = new class() {
		public $items = array( 'new shopping-cart item' );
		public function empty_cart() { $this->items = array(); }
	};
	$GLOBALS['browser_wc'] = (object) array( 'session' => $session, 'cart' => $cart );
	$_GET = array(
		'order_id' => (string) ORDER_ID,
		'orderId' => (string) ORDER_ID,
		'transactionId' => TRANSACTION,
		'linepay_state' => 'expected-state',
		'request_type' => 'confirm',
	);
	$_SERVER['REQUEST_METHOD'] = 'GET';
	try {
		do_action( 'woocommerce_api_wc_gateway_linepay_handler' );
		throw new RuntimeException( 'Paid callback did not redirect' );
	} catch ( Test_Redirect $redirect ) {
		same( 'https://test.invalid/return', $redirect->getMessage(), 'Paid order returns to receipt' );
	} finally {
		$_GET = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}
	same( array( 'new shopping-cart item' ), $cart->items, 'New cart items remain after paid return' );
};

$tests['browser return with invalid state never calls Confirm'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['browser_request'] = true;
	$GLOBALS['order']->update_meta_data( '_linepay_callback_state', 'expected-state' );
	$_GET = array(
		'order_id' => (string) ORDER_ID,
		'orderId' => (string) ORDER_ID,
		'transactionId' => TRANSACTION,
		'linepay_state' => 'wrong-state',
		'request_type' => 'confirm',
	);
	$_SERVER['REQUEST_METHOD'] = 'GET';
	try {
		do_action( 'woocommerce_api_wc_gateway_linepay_handler' );
		throw new RuntimeException( 'Callback did not redirect' );
	} catch ( Test_Redirect $redirect ) {
		same( 'https://test.invalid/checkout', $redirect->getMessage(), 'Untrusted callback redirected without disclosing order receipt' );
	} finally {
		$_GET = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}
	did_not_pay();
	same( array(), $GLOBALS['requests'], 'Untrusted browser requests never query or confirm payment' );
};

$tests['a stale transaction cannot alter the active order'] = static function () use ( $gateway ) {
	reset_scenario();
	$gateway->recover_payment( ORDER_ID, OTHER_TRANSACTION );
	did_not_pay();
	same( array(), $GLOBALS['requests'], 'Stale job does not query or confirm' );
};

$tests['already-paid order keeps its status even with old LINE Pay metadata'] = static function () use ( $gateway ) {
	reset_scenario( 'completed' );
	$GLOBALS['order']->transaction = TRANSACTION;
	$GLOBALS['order']->paid_at = time();
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_not_pay( 'completed' );
	same( array(), $GLOBALS['requests'], 'No remote request for completed order' );
};

$tests['a lost callback after 0110 performs Confirm once'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['responses']['details'] = array( response( '1150' ), response( '0000', array( record() ) ) );
	$GLOBALS['responses']['check'] = array( response( '0110' ) );
	$confirmed = record();
	unset( $confirmed['currency'], $confirmed['payStatus'], $confirmed['transactionType'] );
	$GLOBALS['responses']['confirm'] = array( response( '0000', $confirmed ) );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_pay();
	same( 1, count( array_keys( $GLOBALS['requests'], 'confirm', true ) ), 'One Confirm call after buyer authentication' );
};

$tests['completed lookup handles official documentation shape without payStatus'] = static function () use ( $gateway ) {
	reset_scenario();
	$detail = record();
	unset( $detail['payStatus'] );
	$GLOBALS['responses']['check'] = array( response( '0123' ) );
	$GLOBALS['responses']['details'] = array( response( '0000', array( $detail ) ) );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_pay();
	same( array( 'check', 'details' ), $GLOBALS['requests'], 'Completed status still checks details' );
};

$tests['an authorization-only reservation cannot be marked paid from a success code'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['order']->meta['_linepay_reservation']['capture'] = false;
	$detail = record( array( 'payStatus' => 'AUTHORIZATION', 'authorizationExpireDate' => '2026-11-26T01:00:00Z' ) );
	$GLOBALS['responses']['check'] = array( response( '0123' ) );
	$GLOBALS['responses']['details'] = array( response( '0000', array( $detail ) ) );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_not_pay();
};

$tests['an ambiguous Confirm timeout can reconcile through captured details'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['responses']['details'] = array( response( '0000', array( record() ) ) );
	$GLOBALS['responses']['check'] = array( response( '0110' ) );
	$GLOBALS['responses']['confirm'] = array( new WP_Error( 'http_request_failed', 'Fixture read timeout' ) );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_pay();
	same( 1, count( array_keys( $GLOBALS['requests'], 'confirm', true ) ), 'Ambiguous Confirm never immediately repeats' );
};

$tests['waiting for authentication leaves pending and allows later checks'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['responses']['details'] = array( response( '1150' ) );
	$GLOBALS['responses']['check'] = array( response( '0000' ) );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_not_pay();
	same( false, in_array( 'confirm', $GLOBALS['requests'], true ), 'Waiting must not call Confirm' );
	same( '', $GLOBALS['order']->get_meta( '_linepay_recovery_stopped' ), 'Previously queued later checks remain enabled' );
};

foreach ( array(
	'amount' => array( 'payInfo' => array( array( 'amount' => 149 ) ) ),
	'currency' => array( 'currency' => 'USD' ),
	'merchant order ID' => array( 'orderId' => '10' ),
	'transaction ID' => array( 'transactionId' => OTHER_TRANSACTION ),
	'authorization only' => array( 'payStatus' => 'AUTHORIZATION' ),
	'refunded transaction' => array( 'refundList' => array( array( 'refundAmount' => -150, 'transactionType' => 'REFUND' ) ) ),
) as $label => $change ) {
	$tests[ 'captured response with incompatible ' . $label . ' does not complete' ] = static function () use ( $gateway, $change ) {
		reset_scenario();
		$GLOBALS['responses']['details'] = array( response( '0000', array( record( $change ) ) ) );
		$GLOBALS['responses']['check'] = array( response( '0123' ) );
		$gateway->recover_payment( ORDER_ID, TRANSACTION );
		did_not_pay();
		same( false, in_array( 'confirm', $GLOBALS['requests'], true ), 'Never Confirm as follow-up to reported completed transaction' );
	};
}

$tests['already cancelled WooCommerce order is never resumed automatically'] = static function () use ( $gateway ) {
	reset_scenario( 'cancelled' );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_not_pay( 'cancelled' );
	same( array(), $GLOBALS['requests'], 'Cancelled Woo order must not query or Confirm' );
};

$tests['payment from another WooCommerce gateway is ignored'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['order']->method = 'bacs';
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_not_pay();
	same( array(), $GLOBALS['requests'], 'No LINE Pay API calls after WooCommerce gateway switch' );
};

$tests['changed order total must not confirm the old amount'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['order']->total = '175';
	$GLOBALS['responses']['details'] = array( response( '1150' ) );
	$GLOBALS['responses']['check'] = array( response( '0110' ) );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_not_pay();
	same( false, in_array( 'confirm', $GLOBALS['requests'], true ), 'Edited order must not confirm an old payment' );
};

$tests['the worker locates CAPTURE on retry after a previous Confirm was ambiguous'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['order']->update_meta_data( '_linepay_confirm_started', time() - 300 );
	$GLOBALS['responses']['check'] = array( response( '0110' ) );
	$GLOBALS['responses']['details'] = array( response( '0000', array( record() ) ) );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_pay();
	same( array( 'check', 'details' ), $GLOBALS['requests'], 'Previous uncertain Confirm is queried before repeating' );
};

$tests['recent ambiguous Confirm is never retried immediately'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['order']->update_meta_data( '_linepay_confirm_started', time() );
	$GLOBALS['responses']['check'] = array( response( '0110' ) );
	$GLOBALS['responses']['details'] = array( response( '1150' ) );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_not_pay();
	same( array( 'check', 'details' ), $GLOBALS['requests'], 'No double Confirm during settlement visibility delay' );
};

$tests['expired buyer authentication stops later background checks'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['responses']['check'] = array( response( '0121' ) );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	$gateway->recover_payment( ORDER_ID, TRANSACTION, 1 );
	did_not_pay();
	same( array( 'check' ), $GLOBALS['requests'], 'No further requests after terminal expiration' );
};

$tests['a different merchant channel must not receive the saved transaction'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['order']->meta['_linepay_reservation']['channel'] = 'other-account';
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_not_pay();
	same( array(), $GLOBALS['requests'], 'No calls for a different LINE Pay account' );
};

$tests['concurrent worker lock contention does not call LINE Pay'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['force_busy_lock'] = true;
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_not_pay();
	same( array(), $GLOBALS['requests'], 'Contending worker skipped remote calls' );
};

$tests['existing paid timestamp on an on-hold order prevents duplicate fulfillment'] = static function () use ( $gateway ) {
	reset_scenario( 'on-hold', 'failed' );
	$GLOBALS['order']->transaction = TRANSACTION;
	$GLOBALS['order']->paid_at = time();
	$GLOBALS['responses']['details'] = array( response( '0000', array( record() ) ) );
	$GLOBALS['responses']['check'] = array( response( '0123' ) );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_not_pay( 'on-hold' );
};

$tests['manual reconciliation only queries an existing transaction before completing it'] = static function () use ( $gateway ) {
	reset_scenario( 'on-hold', 'failed' );
	unset( $GLOBALS['order']->meta['_linepay_reservation'] );
	$GLOBALS['responses']['check'] = array( response( '0123' ) );
	$GLOBALS['responses']['details'] = array( response( '0000', array( record() ) ) );
	$gateway->reconcile_order_payment( ORDER_ID );
	did_pay();
	same( array( 'details' ), $GLOBALS['requests'], 'Only read-only LINE Pay operations for a legacy order' );
};

$tests['manual reconciliation of already-paid on-hold order adds evidence without completing twice'] = static function () use ( $gateway ) {
	reset_scenario( 'on-hold', 'failed' );
	unset( $GLOBALS['order']->meta['_linepay_reservation'] );
	$GLOBALS['order']->paid_at = time();
	$GLOBALS['order']->transaction = TRANSACTION;
	$GLOBALS['responses']['check'] = array( response( '0123' ) );
	$GLOBALS['responses']['details'] = array( response( '0000', array( record() ) ) );
	$gateway->reconcile_order_payment( ORDER_ID );
	did_not_pay( 'on-hold' );
	same( 1, count( $GLOBALS['order']->notes ), 'Manual action records order evidence' );
	same( array( 'details' ), $GLOBALS['requests'], 'Manual action did not call Confirm for previously paid order' );
};

$tests['matching legacy order without a reservation snapshot cannot auto-Confirm'] = static function () use ( $gateway ) {
	reset_scenario();
	unset( $GLOBALS['order']->meta['_linepay_reservation'] );
	$GLOBALS['responses']['details'] = array( response( '1150' ) );
	$GLOBALS['responses']['check'] = array( response( '0110' ) );
	$gateway->recover_payment( ORDER_ID, TRANSACTION );
	did_not_pay();
	same( false, in_array( 'confirm', $GLOBALS['requests'], true ), 'No automatic Confirm without frozen reservation metadata' );
};


$tests['customer refund rejects an unrelated user'] = static function () use ( $gateway ) {
	reset_scenario( 'processing', 'confirmed' );
	$GLOBALS['user_id'] = 99;
	$result = $gateway->process_customer_refund( ORDER_ID );
	same( true, is_wp_error( $result ), 'Only the order owner can request the refund' );
	same( array(), $GLOBALS['requests'], 'No LINE Pay call without ownership' );
};

$tests['provider refund error keeps order paid and blocks retry'] = static function () use ( $gateway ) {
	reset_scenario( 'processing', 'confirmed' );
	$GLOBALS['responses']['refund'] = array( new WP_Error( 'http_timeout', 'provider outcome unknown' ) );
	$result = $gateway->process_customer_refund( ORDER_ID );
	same( true, is_wp_error( $result ), 'Unknown provider result is an error' );
	same( 'processing', $GLOBALS['order']->status, 'A provider error never cancels the paid order' );
	same( 0, $GLOBALS['local_refund_calls'], 'No Woo refund is created before provider success' );
	same( 'review', $GLOBALS['order']->get_meta( '_linepay_refund_pending' ), 'Uncertain result blocks another refund' );
	$again = $gateway->process_customer_refund( ORDER_ID );
	same( true, is_wp_error( $again ), 'Retry is blocked pending review' );
	same( array( 'refund' ), $GLOBALS['requests'], 'No duplicate provider call' );
};

$tests['replayed refund transaction ID is held for review'] = static function () use ( $gateway ) {
	reset_scenario( 'processing', 'confirmed' );
	$GLOBALS['order']->meta['_linepay_refund_info'] = array( OTHER_TRANSACTION => array( 'requester' => 'admin' ) );
	$GLOBALS['responses']['refund'] = array( response( '0000', array(
		'refundTransactionId' => OTHER_TRANSACTION,
		'refundTransactionDate' => '2026-11-21T00:00:00Z',
	) ) );
	$result = $gateway->process_customer_refund( ORDER_ID );
	same( true, is_wp_error( $result ), 'Duplicate provider refund response is rejected' );
	same( 'review', $GLOBALS['order']->get_meta( '_linepay_refund_pending' ), 'Merchant review marker remains' );
	same( '150', $GLOBALS['order']->get_meta( '_linepay_transaction_balanced_amount' ), 'No duplicate balance deduction' );
	same( 0, $GLOBALS['local_refund_calls'], 'No duplicate Woo refund record' );
};

$tests['customer refund records provider and Woo refund once'] = static function () use ( $gateway ) {
	reset_scenario( 'processing', 'confirmed' );
	$GLOBALS['responses']['refund'] = array( response( '0000', array(
		'refundTransactionId' => OTHER_TRANSACTION,
		'refundTransactionDate' => '2026-11-21T00:00:00Z',
	) ) );
	$result = $gateway->process_customer_refund( ORDER_ID );
	same( true, $result, 'Customer refund succeeds' );
	same( 'refunded', $GLOBALS['order']->status, 'WooCommerce records refund status' );
	same( 1, $GLOBALS['local_refund_calls'], 'One Woo refund created' );
	same( true, $GLOBALS['order']->refunds[0]->payment_refunded, 'Woo refund marked as paid by provider' );
	same( '0', $GLOBALS['order']->get_meta( '_linepay_transaction_balanced_amount' ), 'Provider balance reduced once' );
	same( '', $GLOBALS['order']->get_meta( '_linepay_refund_pending' ), 'Successful recording clears pending marker' );
	same( true, is_wp_error( $gateway->process_customer_refund( ORDER_ID ) ), 'Repeated customer refund is rejected' );
	same( array( 'refund' ), $GLOBALS['requests'], 'Provider refund sent once' );
};

$tests['Woo refund failure leaves a review marker'] = static function () use ( $gateway ) {
	reset_scenario( 'processing', 'confirmed' );
	$GLOBALS['fail_local_refund'] = true;
	$GLOBALS['responses']['refund'] = array( response( '0000', array(
		'refundTransactionId' => OTHER_TRANSACTION,
		'refundTransactionDate' => '2026-11-21T00:00:00Z',
	) ) );
	$result = $gateway->process_customer_refund( ORDER_ID );
	same( true, is_wp_error( $result ), 'Woo failure is reported' );
	same( 'review', $GLOBALS['order']->get_meta( '_linepay_refund_pending' ), 'Review marker prevents a second provider refund' );
	same( 'processing', $GLOBALS['order']->status, 'Existing order status retained for review' );
};

$tests['Woo refund exception leaves a review marker and note'] = static function () use ( $gateway ) {
	reset_scenario( 'processing', 'confirmed' );
	$GLOBALS['throw_local_refund'] = true;
	$GLOBALS['responses']['refund'] = array( response( '0000', array(
		'refundTransactionId' => OTHER_TRANSACTION,
		'refundTransactionDate' => '2026-11-21T00:00:00Z',
	) ) );
	$result = $gateway->process_customer_refund( ORDER_ID );
	same( true, is_wp_error( $result ), 'Woo exception returns safe error' );
	same( 'review', $GLOBALS['order']->get_meta( '_linepay_refund_pending' ), 'Provider refund remains blocked from retry' );
	same( 2, count( $GLOBALS['order']->notes ), 'Merchant receives provider and review notes' );
};

$tests['administrator refund error cannot cancel a paid order'] = static function () use ( $gateway ) {
	reset_scenario( 'processing', 'confirmed' );
	$GLOBALS['responses']['refund'] = array( new WP_Error( 'http_timeout', 'provider outcome unknown' ) );
	$result = $gateway->process_refund( ORDER_ID, '150' );
	same( true, is_wp_error( $result ), 'Admin refund error returned to WooCommerce' );
	same( 'processing', $GLOBALS['order']->status, 'Paid order is not canceled on a provider error' );
	same( 'review', $GLOBALS['order']->get_meta( '_linepay_refund_pending' ), 'Merchant review required' );
};

$tests['administrator refund clears guard only after Woo records it'] = static function () use ( $gateway ) {
	reset_scenario( 'processing', 'confirmed' );
	$GLOBALS['expected_refund_amount'] = 50;
	$GLOBALS['responses']['refund'] = array( response( '0000', array(
		'refundTransactionId' => OTHER_TRANSACTION,
		'refundTransactionDate' => '2026-11-21T00:00:00Z',
	) ) );
	same( true, $gateway->process_refund( ORDER_ID, '50' ), 'Provider accepted partial admin refund' );
	same( 'review', $GLOBALS['order']->get_meta( '_linepay_refund_pending' ), 'Guard remains until local recording succeeds' );
	do_action( 'woocommerce_refund_created', 101, array( 'refund_payment' => false, 'order_id' => ORDER_ID ) );
	same( 'review', $GLOBALS['order']->get_meta( '_linepay_refund_pending' ), 'Manual Woo refund does not clear guard' );
	$refund = new WC_Order_Refund();
	$refund->payment_refunded = true;
	$GLOBALS['refund_by_id'][102] = $refund;
	do_action( 'woocommerce_refund_created', 102, array( 'refund_payment' => true, 'order_id' => ORDER_ID ) );
	same( 'review', $GLOBALS['order']->get_meta( '_linepay_refund_pending' ), 'Guard remains until Woo finishes its hooks' );
	do_action( 'shutdown' );
	same( '', $GLOBALS['order']->get_meta( '_linepay_refund_pending' ), 'Successful Woo provider refund clears guard' );
};

$tests['deleted Woo refund keeps the provider review guard'] = static function () use ( $gateway ) {
	reset_scenario( 'processing', 'confirmed' );
	$GLOBALS['responses']['refund'] = array( response( '0000', array(
		'refundTransactionId' => OTHER_TRANSACTION,
		'refundTransactionDate' => '2026-11-21T00:00:00Z',
	) ) );
	same( true, $gateway->process_refund( ORDER_ID, '150' ), 'Provider accepted admin refund' );
	$GLOBALS['refund_by_id'][103] = new WC_Order_Refund();
	do_action( 'woocommerce_refund_created', 103, array( 'refund_payment' => true, 'order_id' => ORDER_ID ) );
	unset( $GLOBALS['refund_by_id'][103] );
	do_action( 'shutdown' );
	same( 'review', $GLOBALS['order']->get_meta( '_linepay_refund_pending' ), 'Deleted local refund leaves guard' );
};

$tests['refund callback rejects GET before contacting LINE Pay'] = static function () {
	reset_scenario( 'processing', 'confirmed' );
	$_GET = array( 'order_id' => (string) ORDER_ID, 'request_type' => 'refund' );
	$_SERVER['REQUEST_METHOD'] = 'GET';
	try {
		do_action( 'woocommerce_api_wc_gateway_linepay_handler' );
		throw new RuntimeException( 'Refund GET was accepted' );
	} catch ( Test_Json $response ) {
		same( 403, $response->status, 'GET refund is rejected' );
	} finally {
		$_GET = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}
	same( array(), $GLOBALS['requests'], 'No provider request from a GET' );
};

$tests['refund callback accepts form POST nonce for the order owner'] = static function () {
	reset_scenario( 'processing', 'confirmed' );
	$GLOBALS['responses']['refund'] = array( response( '0000', array(
		'refundTransactionId' => OTHER_TRANSACTION,
		'refundTransactionDate' => '2026-11-21T00:00:00Z',
	) ) );
	$_GET = array( 'order_id' => (string) ORDER_ID, 'request_type' => 'refund' );
	parse_str( '_wpnonce=test-nonce', $_POST );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	try {
		do_action( 'woocommerce_api_wc_gateway_linepay_handler' );
		throw new RuntimeException( 'Refund POST did not return success' );
	} catch ( Test_Json $response ) {
		same( 200, $response->status, 'Form POST with nonce accepted' );
		same( 'success', $response->getMessage(), 'Successful refund JSON returned' );
	} finally {
		$_GET = array();
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}
	same( array( 'refund' ), $GLOBALS['requests'], 'One provider refund request' );
};

$tests['refund callback rejects a forged POST nonce'] = static function () {
	reset_scenario( 'processing', 'confirmed' );
	$_GET = array( 'order_id' => (string) ORDER_ID, 'request_type' => 'refund' );
	$_POST = array( '_wpnonce' => 'wrong-nonce' );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	try {
		do_action( 'woocommerce_api_wc_gateway_linepay_handler' );
		throw new RuntimeException( 'Forged nonce was accepted' );
	} catch ( Test_Json $response ) {
		same( 403, $response->status, 'Forged nonce is rejected' );
	} finally {
		$_GET = array();
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}
	same( array(), $GLOBALS['requests'], 'No provider call with forged nonce' );
};

$tests['payment callback rejects POST even with a valid state'] = static function () {
	reset_scenario();
	$GLOBALS['order']->update_meta_data( '_linepay_callback_state', 'expected-state' );
	$_GET = array( 'order_id' => (string) ORDER_ID, 'orderId' => (string) ORDER_ID,
		'transactionId' => TRANSACTION, 'linepay_state' => 'expected-state', 'request_type' => 'confirm' );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	try { do_action( 'woocommerce_api_wc_gateway_linepay_handler' ); }
	finally { $_GET = array(); unset( $_SERVER['REQUEST_METHOD'] ); }
	did_not_pay();
	same( array(), $GLOBALS['requests'], 'Unexpected HTTP method sends no provider call' );
};

$tests['payment callback rejects a different provider order ID'] = static function () {
	reset_scenario();
	$GLOBALS['order']->update_meta_data( '_linepay_callback_state', 'expected-state' );
	$_GET = array( 'order_id' => (string) ORDER_ID, 'orderId' => '99999',
		'transactionId' => TRANSACTION, 'linepay_state' => 'expected-state', 'request_type' => 'confirm' );
	$_SERVER['REQUEST_METHOD'] = 'GET';
	try { do_action( 'woocommerce_api_wc_gateway_linepay_handler' ); }
	finally { $_GET = array(); unset( $_SERVER['REQUEST_METHOD'] ); }
	did_not_pay();
	same( array(), $GLOBALS['requests'], 'Mismatched order ID sends no provider call' );
};

$tests['legacy payment callback without buyer context is rejected'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['browser_request'] = true;
	$GLOBALS['user_id'] = 0;
	try {
		$gateway->process_payment_confirm( ORDER_ID, TRANSACTION, '' );
		throw new RuntimeException( 'Legacy callback did not redirect' );
	} catch ( Test_Redirect $redirect ) {
		same( 'https://test.invalid/checkout', $redirect->getMessage(), 'Untrusted legacy return goes to checkout' );
	}
	did_not_pay();
	same( array(), $GLOBALS['requests'], 'Untrusted legacy callback cannot call Confirm' );
};

$tests['legacy payment callback rejects another logged-in customer'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['browser_request'] = true;
	$GLOBALS['user_id'] = 99;
	try {
		$gateway->process_payment_confirm( ORDER_ID, TRANSACTION, '' );
		throw new RuntimeException( 'Legacy callback did not redirect' );
	} catch ( Test_Redirect $redirect ) {
		same( 'https://test.invalid/checkout', $redirect->getMessage(), 'Other customer cannot see receipt' );
	}
	did_not_pay();
	same( array(), $GLOBALS['requests'], 'Other customer cannot call Confirm' );
};

$tests['legacy payment callback accepts the matching buyer session'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['browser_request'] = true;
	$GLOBALS['user_id'] = 0;
	$GLOBALS['session_order_id'] = ORDER_ID;
	$confirmed = record();
	unset( $confirmed['currency'], $confirmed['payStatus'], $confirmed['transactionType'] );
	$GLOBALS['responses']['confirm'] = array( response( '0000', $confirmed ) );
	try {
		$gateway->process_payment_confirm( ORDER_ID, TRANSACTION, '' );
		throw new RuntimeException( 'Legacy callback did not redirect' );
	} catch ( Test_Redirect $redirect ) {
		same( 'https://test.invalid/return', $redirect->getMessage(), 'Buyer returns to receipt' );
	}
	did_pay();
	same( array( 'confirm' ), $GLOBALS['requests'], 'Authorized legacy callback calls Confirm once' );
};

$tests['cancel callback requires the reserved transaction ID'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['browser_request'] = true;
	$GLOBALS['order']->update_meta_data( '_linepay_callback_state', 'expected-state' );
	try {
		$gateway->process_payment_cancel( ORDER_ID, OTHER_TRANSACTION, 'expected-state' );
		throw new RuntimeException( 'Cancel callback did not redirect' );
	} catch ( Test_Redirect $redirect ) {
		same( 'https://test.invalid/cart', $redirect->getMessage(), 'Cancel returns to cart' );
	}
	did_not_pay();
	same( array(), $GLOBALS['requests'], 'Mismatched cancel transaction sends no provider call' );
};

$tests['cancel callback accepts missing optional transaction ID with valid state'] = static function () use ( $gateway ) {
	reset_scenario();
	$GLOBALS['browser_request'] = true;
	$GLOBALS['order']->update_meta_data( '_linepay_callback_state', 'expected-state' );
	$GLOBALS['responses']['check'] = array( response( '0121' ) );
	try {
		$gateway->process_payment_cancel( ORDER_ID, '', 'expected-state' );
		throw new RuntimeException( 'Cancel callback did not redirect' );
	} catch ( Test_Redirect $redirect ) {
		same( 'https://test.invalid/cart', $redirect->getMessage(), 'Cancel returns to cart' );
	}
	did_not_pay( 'cancelled' );
	same( array( 'check' ), $GLOBALS['requests'], 'Stored transaction was verified with LINE Pay' );
};

$failures = 0;
foreach ( $tests as $name => $test ) {
	try {
		$test();
		echo 'PASS ' . $name . PHP_EOL;
	} catch ( Throwable $error ) {
		++$failures;
		echo 'FAIL ' . $name . ' (' . $error->getMessage() . ')' . PHP_EOL;
	}
}
echo ( count( $tests ) - $failures ) . '/' . count( $tests ) . ' passed' . PHP_EOL;
exit( $failures ? 1 : 0 );
