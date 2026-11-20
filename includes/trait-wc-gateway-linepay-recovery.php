<?php

defined( 'ABSPATH' ) || exit;

/**
 * Reconcile LINE Pay v3 transactions through the same path in web and background requests.
 */
trait WC_Gateway_LINEPay_Recovery {

	private function acquire_payment_lock( $order_id ) {
		global $wpdb;
		$name = 'wc_linepay_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $order_id );
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ? $name : '';
	}

	private function release_payment_lock( $name ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	private function schedule_recovery( $order, $transaction_id ) {
		$now = time();
		$queued = false;
		foreach ( self::RECOVERY_DELAYS as $attempt => $delay ) {
			$args = array( $order->get_id(), $transaction_id, $attempt );
			if ( function_exists( 'as_schedule_single_action' ) && did_action( 'action_scheduler_init' ) ) {
				if ( as_schedule_single_action( $now + $delay, self::RECOVERY_HOOK, $args, self::RECOVERY_GROUP ) ) {
					$queued = true;
					continue;
				}
			}
			if ( wp_next_scheduled( self::RECOVERY_HOOK, $args ) || wp_schedule_single_event( $now + $delay, self::RECOVERY_HOOK, $args ) ) {
				$queued = true;
			}
		}

		if ( ! $queued ) {
			$order->add_order_note( __( 'LINE Pay could not schedule automatic payment checks. Use the LINE Pay order action to check manually.', 'woocommerce-gateway-linepay' ) );
		}
	}

	public function recover_payment( $order_id, $transaction_id, $attempt = 0 ) {
		if ( ! $this->valid_transaction_id( $transaction_id ) ) {
			return;
		}
		$lock = $this->acquire_payment_lock( $order_id );
		if ( ! $lock ) {
			if ( (int) $attempt === count( self::RECOVERY_DELAYS ) - 1 ) {
				wp_schedule_single_event( time() + 60, self::RECOVERY_HOOK, array( $order_id, $transaction_id, count( self::RECOVERY_DELAYS ) ) );
			}
			return;
		}

		try {
			$order = wc_get_order( $order_id );
			if ( ! $this->can_recover_payment( $order, $transaction_id ) || ! is_array( $order->get_meta( '_linepay_reservation' ) ) || $order->get_meta( '_linepay_recovery_stopped' ) ) {
				return;
			}
			$result = $this->reconcile_payment( $order, $transaction_id, 'scheduled' );
			if ( in_array( $result, array( 'terminal', 'review', 'authorized', 'captured_review' ), true ) ) {
				$order->update_meta_data( '_linepay_recovery_stopped', $result );
				$order->save_meta_data();
			}
			if ( in_array( $result, array( 'review', 'authorized', 'captured_review' ), true ) || ( 'waiting' === $result && (int) $attempt >= count( self::RECOVERY_DELAYS ) - 1 ) ) {
				$this->add_recovery_note( $order, $result );
			}
		} finally {
			$this->release_payment_lock( $lock );
		}
	}

	public function reconcile_order_payment( $order_id ) {
		$lock = $this->acquire_payment_lock( $order_id );
		if ( ! $lock ) {
			$order = wc_get_order( $order_id );
			if ( $order && $this->id === $order->get_payment_method() ) {
				$order->add_order_note( __( 'LINE Pay is already checking this order. Try again shortly.', 'woocommerce-gateway-linepay' ) );
			}
			return;
		}

		try {
			$order = wc_get_order( $order_id );
			if ( ! $order || $this->id !== $order->get_payment_method() ) {
				return;
			}
			$transaction_id = (string) $order->get_meta( '_linepay_reserved_transaction_id' );
			if ( ! $this->valid_transaction_id( $transaction_id ) ) {
				$this->add_recovery_note( $order, 'review' );
				return;
			}
			$result = $this->reconcile_payment( $order, $transaction_id, 'manual' );
			$this->add_recovery_note( $order, $result );
		} finally {
			$this->release_payment_lock( $lock );
		}
	}

	private function valid_transaction_id( $transaction_id ) {
		return ( is_string( $transaction_id ) || is_int( $transaction_id ) ) && preg_match( '/\A[0-9]{1,20}\z/', (string) $transaction_id );
	}

	private function can_recover_payment( $order, $transaction_id ) {
		return $order && $this->id === $order->get_payment_method()
			&& (string) $transaction_id === (string) $order->get_meta( '_linepay_reserved_transaction_id' )
			&& $order->has_status( array( 'pending', 'on-hold', 'failed' ) )
			&& ! $order->is_paid() && ! $order->get_date_paid()
			&& ( '' === (string) $order->get_transaction_id() || (string) $transaction_id === (string) $order->get_transaction_id() )
			&& ! $order->get_refunds()
			&& ! in_array( $order->get_meta( '_linepay_payment_status' ), array( WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CANCELLED, WC_Gateway_LINEPay_Const::PAYMENT_STATUS_REFUNDED ), true );
	}

	private function payment_amount( $order ) {
		$total = $order->get_total();
		// Taiwan LINE Pay accepts whole TWD amounts.
		if ( 'TWD' !== $order->get_currency() || ! is_numeric( $total ) || $total <= 0 || $total > PHP_INT_MAX ) {
			return '';
		}
		return floor( (float) $total ) === (float) $total ? (string) (int) $total : '';
	}

	private function reservation_matches_channel( $reservation ) {
		$channel = $this->get_channel_info();
		return ! empty( $channel['channel_id'] ) && ! empty( $channel['channel_secret'] )
			&& ( ! is_array( $reservation ) || (
				isset( $reservation['host'], $reservation['channel'] ) &&
				$this->get_request_host() === $reservation['host'] &&
				(string) $channel['channel_id'] === $reservation['channel']
			) );
	}

	private function reconcile_payment( $order, $transaction_id, $mode ) {
		$reservation = $order->get_meta( '_linepay_reservation' );
		$amount = $this->payment_amount( $order );
		if ( '' === $amount || ! $this->reservation_matches_channel( $reservation ) ) {
			return 'review';
		}
		if ( is_array( $reservation ) && (
			! isset( $reservation['currency'], $reservation['amount'], $reservation['capture'] ) ||
			$order->get_currency() !== $reservation['currency'] || $amount !== $reservation['amount']
		) ) {
			return 'review';
		}

		$known_auto_capture = is_array( $reservation ) && true === $reservation['capture'];
		$capture = is_array( $reservation ) ? $known_auto_capture : $this->is_captured();
		$can_complete = $this->can_recover_payment( $order, $transaction_id );
		if ( 'manual' === $mode ) {
			$status = $known_auto_capture ? $this->request_payment_status( $transaction_id ) : '';
			$detail = $this->inspect_payment_details( $order, $transaction_id, $amount, $known_auto_capture && '0123' === $status );
			if ( 'captured' !== $detail ) {
				return 'none' === $detail ? 'waiting' : $detail;
			}
			if ( $can_complete ) {
				return $this->complete_recovered_payment( $order, $transaction_id, $amount );
			}
			return 'captured_review';
		}

		if ( ! $can_complete || WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CONFIRMED === $order->get_meta( '_linepay_payment_status' ) ) {
			return 'terminal';
		}

		$started = (int) $order->get_meta( '_linepay_confirm_started' );
		if ( 'browser' === $mode && ! $started && is_array( $reservation ) ) {
			return $this->confirm_reserved_payment( $order, $transaction_id, $amount, $capture );
		}

		$status = $this->request_payment_status( $transaction_id );
		if ( ! $started && '0000' === $status ) {
			return 'pending_authentication';
		}
		if ( ! $started && in_array( $status, array( '0121', '0122' ), true ) ) {
			return 'terminal';
		}

		if ( $started || '0110' !== $status ) {
			$detail = $this->inspect_payment_details( $order, $transaction_id, $amount, $known_auto_capture && '0123' === $status );
			if ( 'captured' === $detail ) {
				return $this->complete_recovered_payment( $order, $transaction_id, $amount );
			}
			if ( in_array( $detail, array( 'review', 'authorized' ), true ) ) {
				return $detail;
			}
			// A lost Confirm may have committed. Retry it only after a fresh affirmative
			// status, a definitive absence of details, and a delay for settlement visibility.
			if ( 'none' !== $detail || ( $started && time() - $started < 90 ) ) {
				return 'waiting';
			}
			if ( in_array( $status, array( '0121', '0122' ), true ) ) {
				return 'terminal';
			}
		}

		if ( '0110' === $status ) {
			return $this->confirm_reserved_payment( $order, $transaction_id, $amount, $capture );
		}
		return 'waiting';
	}

	private function request_payment_status( $transaction_id ) {
		$url = $this->get_request_host() . '/v3/payments/requests/' . $transaction_id . '/check';
		$result = static::execute( $this, $url, null, 20, 'GET' );
		return is_wp_error( $result ) ? $this->linepay_error_code( $result ) : '0000';
	}

	private function linepay_error_code( $error ) {
		$data = $error->get_error_data();
		return is_object( $data ) && isset( $data->returnCode ) ? $data->returnCode : '';
	}

	private function confirm_reserved_payment( $order, $transaction_id, $amount, $capture ) {
		$order->update_meta_data( '_linepay_confirm_started', time() );
		$order->save_meta_data();
		$url = $this->get_request_url( WC_Gateway_LINEPay_Const::REQUEST_TYPE_CONFIRM, array( 'transaction_id' => $transaction_id ) );
		$info = static::execute( $this, $url, array( 'amount' => (int) $amount, 'currency' => $order->get_currency() ), 40 );

		if ( $this->payment_details_match( $info, $order, $transaction_id, $amount ) ) {
			if ( ! $capture || isset( $info->authorizationExpireDate ) || ( isset( $info->payStatus ) && 'AUTHORIZATION' === $info->payStatus ) ) {
				return 'authorized';
			}
			if ( ! isset( $info->payStatus ) || 'CAPTURE' === $info->payStatus ) {
				return $this->complete_recovered_payment( $order, $transaction_id, $amount );
			}
		}

		// Confirm timed out, returned an error, or supplied incomplete evidence.
		$detail = $this->inspect_payment_details( $order, $transaction_id, $amount, false );
		if ( 'captured' === $detail ) {
			return $this->complete_recovered_payment( $order, $transaction_id, $amount );
		}
		return in_array( $detail, array( 'review', 'authorized' ), true ) ? $detail : 'waiting';
	}

	private function inspect_payment_details( $order, $transaction_id, $amount, $infer_capture ) {
		$url = $this->get_request_url( WC_Gateway_LINEPay_Const::REQUEST_TYPE_DETAILS, array( 'transaction_id' => $transaction_id ) );
		$details = static::execute( $this, $url, wp_parse_url( $url, PHP_URL_QUERY ), 20, 'GET' );
		if ( is_wp_error( $details ) ) {
			return '1150' === $this->linepay_error_code( $details ) ? 'none' : 'waiting';
		}
		if ( ! is_array( $details ) ) {
			return 'waiting';
		}
		if ( array() === $details ) {
			return 'none';
		}

		$matching_detail = null;
		foreach ( $details as $detail ) {
			if ( ! is_object( $detail ) || ! isset( $detail->transactionId ) || ! $this->valid_transaction_id( $detail->transactionId ) || (string) $detail->transactionId !== (string) $transaction_id ) {
				continue;
			}
			if ( $matching_detail || ! isset( $detail->currency ) || ! $this->payment_details_match( $detail, $order, $transaction_id, $amount ) ) {
				return 'review';
			}
			if ( ! empty( $detail->refundList ) || ( isset( $detail->transactionType ) && 'PAYMENT' !== $detail->transactionType ) ) {
				return 'review';
			}
			$matching_detail = $detail;
		}

		if ( ! $matching_detail ) {
			return 'review';
		}
		if ( ! isset( $matching_detail->payStatus ) ) {
			if ( $infer_capture && isset( $matching_detail->transactionType ) && 'PAYMENT' === $matching_detail->transactionType ) {
				return 'captured';
			}
			return 'waiting';
		}
		if ( 'CAPTURE' === $matching_detail->payStatus ) {
			return 'captured';
		}
		return 'AUTHORIZATION' === $matching_detail->payStatus ? 'authorized' : 'review';
	}

	private function payment_details_match( $info, $order, $transaction_id, $amount ) {
		if ( ! is_object( $info ) || ! isset( $info->orderId, $info->transactionId, $info->payInfo )
			|| ! $this->valid_transaction_id( $info->transactionId ) || (string) $transaction_id !== (string) $info->transactionId
			|| ( ! is_string( $info->orderId ) && ! is_int( $info->orderId ) ) || (string) $order->get_id() !== (string) $info->orderId || ( isset( $info->currency ) && $order->get_currency() !== $info->currency )
			|| ! is_array( $info->payInfo ) || ! $info->payInfo ) {
			return false;
		}

		$total = 0;
		foreach ( $info->payInfo as $source ) {
			if ( ! is_object( $source ) || ! isset( $source->amount ) || ! is_numeric( $source->amount )
				|| $source->amount < 0 || $source->amount > (int) $amount || floor( (float) $source->amount ) !== (float) $source->amount ) {
				return false;
			}
			if ( (int) $source->amount > (int) $amount - $total ) {
				return false;
			}
			$total += (int) $source->amount;
		}
		return $total === (int) $amount && $total > 0;
	}

	private function complete_recovered_payment( $order, $transaction_id, $amount ) {
		// Admin or a WooCommerce integration may have changed the order while the API was responding.
		$order = $this->fresh_order( $order->get_id() );
		if ( ! $this->can_recover_payment( $order, $transaction_id )
			|| $amount !== $this->payment_amount( $order ) ) {
			return 'captured_review';
		}
		$order->update_meta_data( '_linepay_transaction_balanced_amount', $amount );
		$order->update_meta_data( '_linepay_payment_status', WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CONFIRMED );
		if ( $order->payment_complete( (string) $transaction_id ) && $order->get_date_paid() ) {
			return 'paid';
		}
		return 'captured_review';
	}

	public static function fresh_order( $order_id ) {
		if ( class_exists( 'Automattic\\WooCommerce\\Caches\\OrderCache' ) ) {
			$cache = wc_get_container()->get( 'Automattic\\WooCommerce\\Caches\\OrderCache' );
			$cache->remove( $order_id );
		}
		wp_cache_delete( $order_id, 'posts' );
		wp_cache_delete( $order_id, 'post_meta' );
		return wc_get_order( $order_id );
	}

	private function add_recovery_note( $order, $result ) {
		$messages = array(
			'paid'            => __( 'LINE Pay verified the capture and recorded the payment on this order.', 'woocommerce-gateway-linepay' ),
			'captured_review' => __( 'LINE Pay verified the capture. Review the existing WooCommerce payment date, status and fulfillment before changing this order.', 'woocommerce-gateway-linepay' ),
			'authorized'      => __( 'LINE Pay shows authorization only. Check capture status in the merchant center.', 'woocommerce-gateway-linepay' ),
			'review'          => __( 'LINE Pay could not reconcile the transaction with this order. Check the transaction, amount, currency, channel and any refunds in the merchant center.', 'woocommerce-gateway-linepay' ),
			'waiting'         => __( 'LINE Pay has not returned verifiable capture details. Check again or review the payment in the merchant center.', 'woocommerce-gateway-linepay' ),
		);
		if ( isset( $messages[ $result ] ) ) {
			$order->add_order_note( $messages[ $result ] );
		}
	}
}
