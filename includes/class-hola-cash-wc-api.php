<?php
/**
 * Class for communication with Hola Cash API

 * @package HolaCash
 */

namespace HOLACASH_WC;

defined( 'ABSPATH' ) || die( 'No Script Kiddies Please' );

/**
 * Class to interact with Holacash API
 */
class Hola_Cash_WC_API {
	/**
	 * Public API Key

	 * @var $pubic_api_key
	 */
	private $public_api_key;
	/**
	 * Private API Key

	 * @var $private_api_key
	 */
	private $private_api_key;
	/**
	 * Tells whether sandbox mode is on or off

	 * @var $is_test
	 */
	private $is_test;

	/**
	 * Constructor Call
	 */
	public function __construct() {

		$hola_payment_method   = new Hola_Cash_WC_Gateway();
		$this->public_api_key  = $hola_payment_method->public_api_key;
		$this->private_api_key = $hola_payment_method->private_api_key;
		$this->is_test         = $hola_payment_method->test_mode;
		$this->webhook_key     = $hola_payment_method->webhook_key;
		$this->widget_version  = \HOLACASH_WC\Hola_Cash_WC_Constants::$widget_version;

	}

	/**
	 * Verifies received signature
	 *
	 * @param string $key Webhook key.
	 * @param string $payload payload.
	 * @param string $hola_cash_sign_header Header received in signature.
	 */
	public function validate_holacash_signature( $key, $payload, $hola_cash_sign_header ) {
		// Split sign header into the timestamp and signature components.
		$exploded         = explode( ',', $hola_cash_sign_header );
		$timestamp        = $exploded[0];
		$server_signature = $exploded[1];

		// To generate the string to sign you have to concat the timestamp, a dot and the JSON.
		// The JSON should be a single line without spaces (The default behaviour of stringify function).
		$string_to_sign = $timestamp . '.' . wp_json_encode( json_decode( $payload, true ), JSON_UNESCAPED_SLASHES );

		// The signature is done with HMAC_SHA256 algorithm and the key you can get from the portal (Exclusive for webhooks).
		// The digest is converted to a Hex string for debugging purposes. You can use the bytes digest for the compare.
		$client_signature = strtoupper( hash_hmac( 'sha256', $string_to_sign, $key ) );

		// Cryptographically compare the 2 signatures. In this case we use the timingSafeEqual function from the crypto lib in node.
		// You can use any crypto comparison. Avoid at any costs comparing strings.
		$signs_are_equal = hash_equals( $server_signature, $client_signature );

		return $signs_are_equal;
	}

	/**
	 * Helper function to log
	 *
	 * @param string $text Message to log.
	 * @param string $context context of the log.
	 */
	public function log( $text, $context = 'holacash-webhook' ) {
		$uploads_dir = wp_upload_dir();
		$logs_dir    = untrailingslashit( $uploads_dir['basedir'] ) . '/hola-logs';
		if ( ! file_exists( $welcome_dir ) && ! is_dir( $logs_dir ) ) {
			mkdir( $logs_dir, 0777, true );
		}
		$log_file = $logs_dir . '/' . gmdate( 'Y-m-d' ) . '.txt';

		$log_text = gmdate( 'h:i:s A ' ) . $text . "\n";
		Hola_Cash_WC_Gateway::log( $log_text, $context );
	}

	/**
	 * Returns value of single header key
	 *
	 * @param string $key - A key present in header.
	 */
	public function get_single_header_value( $key ) {
		if ( isset( $_SERVER[ "HTTP_{$key}" ] ) ) {
			$value = sanitize_text_field( wp_unslash( $_SERVER[ "HTTP_{$key}" ] ) );
			return $value;
		}
		return '';
	}

	/**
	 * Verifies if webhook request is valid
	 */
	public function is_valid_request() {
		$this->log( 'Start validating request' );

		$current_timestamp = microtime( true );
		$payload           = file_get_contents( 'php://input' );
		$this->log( 'payload ' . $payload );

		if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return new \WP_Error( 'unsupported_request_method', 'Only POST request is supported by this endpoint' );
		}
		$header_sign = $this->get_single_header_value( 'HOLACASH_SIGN' );
		if ( empty( $header_sign ) ) {
			return new \WP_Error( 'empty_sign', __( 'Request must need a signature', 'woocommerce-gateway-holacash' ) );
		}

		$this->log( "Signature received {$header_sign}" );

		// split header value in timestamp and signature.
		list( $timestamp, $signature ) = explode( ',', $header_sign );

		// get time difference.
		$delayed_time = $current_timestamp - $timestamp;

		$this->log( 'delayed_time: ' . $delayed_time );

		/**
		 * We are checking the this time difference (in microseconds) to identify,
		 * if we received request generated from server within 1 hour.
		 * if difference in time is less then 0 or greater then 3600 sec(1 hour) then request will be rejected.
		 *
		 * To describe if $delayed_time is
		 * - ( < 0 ) less then 0 sec means request is received before time it was generated on server
		 * - ( > 3600 ) more then an hour means we are receiving this request after an hour after it was initiated from server
		 *
		 * In both case we assume the webhook is tempered and webhook signature validation should fail
		 */
		if ( $delayed_time < 0 || $delayed_time > 3600 ) {
			return new \WP_Error( 'old_timestamp', 'Old timestamp' );
		}

		$this->log( "payload: {$payload} timestamp: {$timestamp} signature: {$signature}" );

		if ( empty( $signature ) || empty( $timestamp ) ) {
			return new \WP_Error( 'invalid_signature_string', __( 'Request contains an invalid signature string', 'woocommerce-gateway-holacash' ) );
		}

		$validating_string = "{$timestamp}.{$payload}";

		$this->log( "String to be converted {$validating_string}" );

		$expected_signature = strtoupper( hash_hmac( 'sha256', $validating_string, $this->webhook_key ) );

		$this->log( "Expected signatue in header {$expected_signature} using key {$this->webhook_key}" );

		if ( ! $this->validate_holacash_signature( $this->webhook_key, $payload, $header_sign ) ) {
			return new \WP_Error( 'signature_mismatch', __( "Sorry couldn't verify the signature" ) );
		}

		return true;

	}
	/**
	 * Find WC_Order using HolaCash Order ID
	 *
	 * @param string $holacash_order_id order id generated at holacash side and stored along with WC order.
	 *
	 * @return WC_Order|null return null or WC_Order object.
	 */
	private function get_wc_order_id( $holacash_order_id ) {
		$orders = wc_get_orders( array( 'holacash_order_id' => $holacash_order_id ) );
		if ( empty( $orders ) ) {
			$this->log( "No order found with holacash order id {$holacash_order_id}" );
			return;
		}

		return $orders[0];
	}

	/**
	 * Process refund webhook
	 *
	 * @param string $payload - Converted array from payload value present in the body.
	 */
	public function refund_from_webhook( $payload ) {

		global $holacash_wc;

		$charge_id         = $payload['charge_detail']['id'];
		$holacash_order_id = $payload['charge_detail']['charge']['purchase_details']['holacash_system_order_id'];
		$refund_id         = $payload['refund_detail']['refund_transaction_id'];

		$order = $this->get_wc_order_id( $holacash_order_id );
		if ( empty( $order ) ) {
			return;
		}

		$order_id = $order->get_id();
		if ( $holacash_wc->is_order_locked( $order_id ) ) {
			$this->log( "Received webhook for refund but {$order_id} is locked" );
			return;
		}

		$this->log( "Refund webhook for {$order_id}" );

		if ( $order->get_status() !== 'refunded' ) {
			$this->log( 'Order is not refunded will refund it' );
			$refund_history_ids = get_post_meta( $order_id, 'holacash_refund_history_mapping', true );
			if ( ! is_array( $refund_history_ids ) || ! isset( $refund_history_ids[ $refund_id ] ) ) {
				global $hc_refund_id;
				$hc_refund_id = $refund_id;

				$amount = floatval( $payload['refund_detail']['refunded_amount']['amount'] ) / 100;

				$refund = wc_create_refund(
					array(
						'amount'   => $amount,
						'reason'   => __( 'Refunded from merchant portal', 'woocommerce-gateway-holacash' ),
						'order_id' => $order_id,
					)
				);

			} else {
				$this->log( "Refund id {$refund_id} is already processed" );
				return;
			}
		} else {
			$this->log( 'Order is already refunded, will just maintatain the log' );
			$order->add_order_note( "Got webhook with id {$refund_id} but Order is already refunded" );
		}

		$this->log( 'Refund webhook completed' );
	}

	/**
	 * Listens to charge.succeded or capture.succeded event
	 * and update orders accordingly
	 *
	 * @param array  $charge_detail converted array from value of charge_detail.
	 * @param string $capture_transaction_id Capture transaction id.
	 * @param float  $amount Amount in Highest Currency Unit (eg: MXN) - available only in capture succeded.
	 */
	public function complete_order_from_webhook( $charge_detail, $capture_transaction_id = '', $amount = false ) {

		global $holacash_wc;

		$holacash_order_id = $charge_detail['charge']['purchase_details']['holacash_system_order_id'];
		$additional_data   = holacash_nameval_to_keyval( $charge_detail['status_details']['detail']['additional_details'] );

		$order = $this->get_wc_order_id( $holacash_order_id );
		if ( empty( $order ) ) {
			return;
		}

		$order_id = $order->get_id();
		if ( $holacash_wc->is_order_locked( $order->get_id() ) ) {
			$this->log( "{$order_id} is locked currently, we got status {$additional_data['charge_status']}" );
			return;
		}

		$charge_id = $charge_detail['id'];

		if ( 'captured' === $additional_data['charge_status'] ) {
			$capturing_history = get_post_meta( $order_id, 'hc_total_captured_history', true );
			if ( ! is_array( $capturing_history ) || ! isset( $capturing_history[ $capture_transaction_id ] ) ) {
				$capturing_history                            = ! is_array( $capturing_history ) ? array() : $capturing_history;
				$capturing_history[ $capture_transaction_id ] = $amount;

				$total_captured  = floatval( get_post_meta( $order_id, 'hc_total_captured', true ) );
				$total_captured += $amount;

				update_post_meta( $order_id, 'holacash_charge_id', $charge_id );
				update_post_meta( $order_id, 'hc_total_captured', $total_captured );
				update_post_meta( $order_id, 'hc_total_captured_history', $capturing_history );

				// Update Order Totals.
				if ( false !== $amount ) {
					$order->set_total( $total_captured );
				}

				$this->log( "Captured payment of order id {$order_id} with charge id {$charge_id} of amout {$amount}" );
				$order->payment_complete( $charge_id );
				update_post_meta( $order_id, 'holacash_charge_id', $charge_id );

			} else {
				$this->log( 'Order ' . $order->get_id() . 'status is already captured' );
			}
		} elseif ( 'pending_capture' === $additional_data['charge_status'] ) {

			update_post_meta( $order->get_id(), 'hc_pending_capture_charge_id', $charge_id );
			$order->add_order_note( "Webhook received: Authorization of order amount taken with charge id: {$charge_id}" );
			if ( 'pending' !== $order->get_status() ) {
				$order->update_status( 'pending', __( 'Webhook received for pending capture status', 'woocommerce-gateway-holacash' ) );
			}
		} else {
			// It's assumed to be only completed state.
			update_post_meta( $order_id, 'holacash_charge_id', $charge_id );

			if ( $order->get_status() !== 'completed' ) {
				$order->payment_complete( $charge_id );
			}
		}
	}

	/**
	 * Listens to charge.failed,charge.cancelled and capture.failed event
	 * and update order status accordingly
	 *
	 * @param array  $charge_detail  array of charge data.
	 * @param string $type enum('cancelled','failed').
	 * @param string $note Any note for failing order.
	 */
	public function fail_order_from_webhook( $charge_detail, $type, $note = '' ) {
		global $holacash_wc;
		$holacash_order_id = $charge_detail['charge']['purchase_details']['holacash_system_order_id'];
		$order             = $this->get_wc_order_id( $holacash_order_id );
		if ( empty( $order ) ) {
			return;
		}

		$order_id = $order->get_id();
		if ( $holacash_wc->is_order_locked( $order->get_id() ) ) {
			$this->log( "{$order_id} is locked currently, we got charge with  {$type} status" );
			return;
		}

		$charge_id_matched = $this->mapStoredChargeIdWithRequest($order_id, $charge_detail['id']);

		if ( $order->get_status() !== $type && $charge_id_matched) {
			$order->update_status( $type, $note );
		}
	}

	/**
	 * Listens to refund.failed event and update staus accordingly
	 *
	 * @param array $payload - converted array from value of payload in request body.
	 */
	public function refund_failed_webhook( $payload ) {
		$holacash_order_id     = $payload['charge_detail']['charge']['purchase_details']['holacash_system_order_id'];
		$refund_transaction_id = $payload['refund_detail']['refund_transaction_id'];

		$order = $this->get_wc_order_id( $holacash_order_id );
		if ( empty( $order ) ) {
			return;
		}

		$order_id = $order->get_id();

		$refund_history_ids = get_post_meta( $order_id, 'holacash_refund_history_mapping', true );
		$refund_history_ids = ! is_array( $refund_history_ids ) ? array() : $refund_history_ids;

		$this->log( "For order id {$order_id} we have these refunds history " . wp_json_encode( $refund_history_ids ) );

		if ( array_key_exists( $refund_transaction_id, $refund_history_ids ) ) {
			$this->log( "Refund found for the order {$order_id} with id {$refund_transaction_id}" );
			$woo_refund_id = absint( $refund_history_ids[ $refund_transaction_id ] );
			$this->log( "Woo Refund id is {$woo_refund_id}" );

			if ( $woo_refund_id && 'shop_order_refund' === get_post_type( $woo_refund_id ) ) {
				$this->log( "Refund id {$woo_refund_id} is of type shop_order_refund" );
			} else {
				$this->log( "Refund id {$woo_refund_id} type is invalid" );
				return;
			}

			$refund          = wc_get_order( absint( $woo_refund_id ) );
			$refund_order_id = $refund->get_parent_id();
			if ( $order_id !== $refund_order_id ) {
				$this->log( "Order id mismatched received {$order_id} saved {$refund_order_id}" );
			} else {
				$refund->delete( true );
				$order->update_status( 'processing', "Refund with transaction id {$refund_transaction_id} failed" );
				do_action( 'woocommerce_refund_deleted', $woo_refund_id, $order_id );
				$this->log( "Refund with id {$woo_refund_id} deleted" );
			}
		} else {
			$this->log( "No Refund found for the order {$order_id} with id {$refund_transaction_id}" );
		}
	}

	/**
	 * Listens to charge.pending event
	 *
	 * @param array $payload - converted array from value of payload available in request body.
	 */
	public function pending_order_from_webhook( $payload ) {
		global $holacash_wc;

		$holacash_order_id = $payload['charge']['purchase_details']['holacash_system_order_id'];
		$additional_data   = holacash_nameval_to_keyval( $payload['status_details']['detail']['additional_details'] );

		$order = $this->get_wc_order_id( $holacash_order_id );
		if ( empty( $order ) ) {
			return;
		}

		$order_id = $order->get_id();
		if ( $holacash_wc->is_order_locked( $order->get_id() ) ) {
			$this->log( "{$order_id} is locked currently, we got status pending" );
			return;
		}
		$charge_id_matched = $this->mapStoredChargeIdWithRequest($order_id, $payload['id']);
		if ( ($order->get_status() !== 'pending' || $order->get_status() !== 'on-hold') && $charge_id_matched) {
			// Order was not pending or hold.
			$order->update_status( 'pending', __( 'Receiving pending notification from webhook', 'woocommerce-gateway-holacash' ) );
			$this->log( 'Updated order to pending' );
		} else {
			$this->log( 'Order is already awaiting or pending' );
		}

	}

	/**
     * Map stored charge_id in wc order using HolaCash system order id and charge id 
	 * from webhook request payload
     *
     * @param  $wc_order_id
     * @param  $charge_id_from_payload
     * @return boolean
     */
    public function mapStoredChargeIdWithRequest($wc_order_id, $charge_id_from_payload)
    {
        $this->log( '<---mapStoredChargeIdWithRequest---->' );
		/**
         * This variable will be used to check if charge id available in WC order and webhook is matched or not
         * if true then we can process all webhooks
         * if false then only process charge success and capture success webhook events only
         */
        $charge_id_matched = false;

		$stored_transaction_ids = [
			get_post_meta( $wc_order_id, 'holacash_charge_id', true ), // previously successful transaction
			get_post_meta( $wc_order_id, 'holacash_pending_charge_id', true ), // previously pending transaction
			get_post_meta( $wc_order_id, 'holacash_failed_charge_id', true ), // previously failed transaction
			get_post_meta( $wc_order_id, 'hc_pending_capture_charge_id', true ) // previously pending_charge transaction
		];
		$this->log( 'storedTransactionIds => '. $stored_transaction_ids);
		

		// filter array for null or empty values 
		$stored_transaction_ids = array_values(array_filter($stored_transaction_ids));


		if (count($stored_transaction_ids) 
			&& !empty($charge_id_from_payload) 
			&& in_array($charge_id_from_payload, $stored_transaction_ids)) {
			$charge_id_matched = true;
		} else {
			$charge_id_matched = false;
		}
        
        // Log data for debugging
        $this->log("charge_id_matched => {$charge_id_matched}");
        
        return $charge_id_matched;
    }

	/**
	 * Listener function for webhook request
	 */
	public function process_webhook() {
		$this->log( '<---Webhook processing started---->' );
		$is_valid = $this->is_valid_request();
		if ( ! is_wp_error( $is_valid ) ) {
			$this->log( 'Request validated' );
			$body       = json_decode( file_get_contents( 'php://input' ), true );
			$event_type = sanitize_text_field( $body['event_type'] );
			$payload    = $body['payload'];
			$event_id   = $body['payload']['id'];
			switch ( $event_type ) {
				case 'charge.succeeded':
					$this->log( "charge.succeeded response received with id {$event_id}" );
					$this->complete_order_from_webhook( $payload );
					break;
				case 'charge.pending':
					$this->log( "charge.pending response received with id {$event_id}" );
					$this->pending_order_from_webhook( $payload );
					break;
				case 'charge.failed':
					$this->log( "charge.failed response received with id {$event_id}" );
					$this->fail_order_from_webhook( $payload, 'failed', 'Webhook received for charge failed' );
					break;
				case 'charge.cancelled':
					$this->log( "charge.cancelled response received with id {$event_id}" );
					$this->fail_order_from_webhook( $payload, 'failed', 'Webhook received for charge cancelled' );
					break;
				case 'capture.succeeded':
					$this->log( "capture.succeeded response received with id {$event_id}" );
					$this->complete_order_from_webhook( $payload['charge_detail'], $payload['capture_detail']['capture_transaction_id'], floatval( $payload['capture_detail']['captured_amount']['amount'] ) / 100 );
					break;
				case 'capture.failed':
					$this->log( "capture.failed response received with id {$event_id}" );
					$this->fail_order_from_webhook( $payload['charge_detail'], 'failed', 'Capturing Payment Failed Webhook received' );
					break;
				case 'refund.succeeded':
					$this->log( "refund.succeeded response received with id {$event_id}" );
					$this->refund_from_webhook( $payload );
					break;
				case 'refund.failed':
					$this->log( "refund.failed response received with id {$event_id}" );
					$this->refund_failed_webhook( $payload );
					break;
				default:
					$this->log( "Event type received is {$event_type}, we are not listening for this type of event with id {$event_id}" );
			}
		} else {
			$this->log( $is_valid->get_error_message() );
		}

		$this->log( '<----Webhook process ends---->' );

	}

	/**
	 * Capture a pre authorized charge
	 *
	 * @param  int   $order_id - WooCommerce order id.
	 * @param  float $amount   - Amount to capture.
	 * @return array|null|WP_Error.
	 */
	public function capture( $order_id, $amount ) {
		$amount                 = absint( $amount * 100 );
		$capture_transaction_id = get_post_meta( $order_id, 'hc_pending_capture_charge_id', true );
		if ( empty( $capture_transaction_id ) ) {
			return new \WP_Error( 'capture_transaction_id_not_fount', __( 'Couldn\'t find capture transaction id' ) );
		}

		$url      = $this->get_endpoint( 'capture_charge', array( 'charge_id' => $capture_transaction_id ) );
		$body     = array(
			'amount'        => $amount,
			'currency_code' => 'MXN',
		);
		$headers  = array(
			'X-Api-Client-Key'               => $this->private_api_key,
			'Content-Type'                   => 'application/json',
			'X-Cash-Checkout-Widget-Version' => $this->widget_version,
		);
		$response = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => \HOLACASH_WC\Hola_Cash_WC_Constants::API_TIMEOUT,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code     = $response['response']['code'];
		$res_body = json_decode( $response['body'], true );
		if ( 200 === $code ) {
			return $res_body;
		} else {
			return new \WP_Error( 'error_executing_api', $res_body['message'] );
		}

	}

	/**
	 * Generates a order ID from Hola.Cash and saves it in session
	 */
	public function create_order() {

		$saved_order_id = WC()
			->session
			->get( 'hc_order_id' );
		if ( ! empty( $saved_order_id ) ) {
			return array(
				'status'   => true,
				'order_id' => $saved_order_id,
			);
		}

		$order_body = holacash_order_initial_request_data();
		$url        = $this->get_endpoint( 'create_order' );
		$headers    = array(
			'X-Api-Client-Key'               => $this->public_api_key,
			'Content-Type'                   => 'application/json',
			'X-Cash-Checkout-Widget-Version' => $this->widget_version,
		);
		$response   = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $order_body ),
				'timeout' => \HOLACASH_WC\Hola_Cash_WC_Constants::API_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'Error while creating order: ' . $response->get_error_message(), 'holacash-create-order' );
			return array(
				'status'  => false,
				'message' => 'We are unable to connect to start the order process in holacash. please contact support.',
			);
		}
		$code = $response['response']['code'];
		if ( 200 === $code ) {

			$order_data = json_decode( $response['body'], true );
			WC()
				->session
				->set( 'hc_order_id', $order_data['order_information']['order_id'] );
			return array(
				'status'   => true,
				'order_id' => $order_data['order_information']['order_id'],
			);
		} else {
			$this->log( 'Error while creating order: ' . $response['body'], 'holacash-create-order' );
			$body    = json_decode( $response['body'], true );
			$message = $body['detail'];
			if ( is_array( $message ) ) {
				$message = $body['detail']['message'];
			}
			return array(
				'status'  => false,
				'message' => $message,
			);
		}
	}

	/**
	 * Fetches current status of charge
	 *
	 * @param string $charge_id Charge id generated while creating charge.
	 * @return string $response.
	 */
	public function get_charge_status( $charge_id ) {
		$url     = $this->get_endpoint( 'get_charge_status', array( 'holacash_charge_id' => $charge_id ) );
		$headers = array(
			'X-Api-Client-Key'               => $this->private_api_key,
			'Content-Type'                   => 'application/json',
			'X-Cash-Checkout-Widget-Version' => $this->widget_version,
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = $response['response']['code'];
		if ( 200 === $code ) {
			$body            = json_decode( $response['body'], true );
			$additional_data = holacash_nameval_to_keyval( $body['status_details']['detail']['additional_details'] );

			$status = $additional_data['current_status'];
			return $status;
		} else {
			return new WP_Error( 'holacash_fetch_err', $response['body'] );
		}
	}

	/**
	 * Returns endpoint for making api call
	 *
	 * @param string $name - Endpoint name.
	 * @param string $data - array of necessary path variables.
	 */
	public function get_endpoint( $name, $data = array() ) {
		$base_url = Hola_Cash_WC_Constants::get_api_base_url();
		switch ( $name ) {
			case 'create_order':
				return "{$base_url}/order";
			case 'merchant_checkout_widget_config':
				return "{$base_url}/merchant/setting/checkout-widget";
			case 'create_charge':
				return "{$base_url}/transaction/charge";
			case 'refund_order':
				return "{$base_url}/transaction/refund/{$data['holacash_charge_id']}";
			case 'get_charge_status':
				return "{$base_url}/transaction/charge/{$data['holacash_charge_id']}";
			case 'update_order':
				return "{$base_url}/order/{$data['holacash_order_id']}";
			case 'capture_charge':
				return "{$base_url}/transaction/capture/{$data['charge_id']}";
			default:
				return '';
		}
	}

	/**
	 * Returns merchant widget config
	 */
	public function get_transaction_config() {
		$url      = $this->get_endpoint( 'merchant_checkout_widget_config' );
		$headers  = array(
			'X-Api-Client-Key'               => $this->private_api_key,
			'X-Cash-Checkout-Widget-Version' => $this->widget_version,
		);
		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return json_decode( $response['body'] );
	}

	/**
	 * Fetches auto capture setting of merchant
	 */
	public function get_auto_capture_settings() {
		$url     = $this->get_endpoint( 'merchant_checkout_widget_config' );
		$headers = array(
			'X-Api-Client-Key'               => $this->public_api_key,
			'X-Cash-Checkout-Widget-Version' => $this->widget_version,
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => \HOLACASH_WC\Hola_Cash_WC_Constants::API_TIMEOUT,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['response']['code'] ) {
			return new \WP_Error( 'unable_to_fetch', $response['body'] );
		}
		$settings = json_decode( $response['body'], true );
		$configs  = array();

		if ( isset( $settings['configuration_details'] ) ) {
			foreach ( $settings['configuration_details'] as $config ) {
				$configs[ $config['name'] ] = $config['data'];
			}

			if ( isset( $configs['auto_capture'] ) ) {
				return $configs['auto_capture'];
			} else {
				return new \WP_Error( 'auto_capture_not_found', 'Couldn\'t locate auto capture value' );
			}
		} else {
			return new \WP_Error( 'error_in_getting_configs', wp_json_encode( $settings ) );
		}
	}

	/**
	 * Create charge
	 *
	 * @param string $holacash_order_id - String denoting order_id in holacash system.
	 * @param object $order  WC_Order object.
	 * @param array  $options include header options like anti-fraud metadata and user preferred language.
	 */
	public function create_charge( $holacash_order_id, $order, $options = [] ) {

		$autocapture = $this->get_auto_capture_settings();

		$antifraud_data = $options['antifraud_data'] ?? '';
		$userLanguage   = parseLanguage($options['userLanguage'] ?? '');

		if ( is_wp_error( $autocapture ) ) {
			return $autocapture;
		}

		$updated = $this->update_order_request( $holacash_order_id, holacash_filter_order_data_from_wc_order( $order ), $antifraud_data );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$charge_url = $this->get_endpoint( 'create_charge' );
		$headers    = array(
			'X-Api-Client-Key'               => $this->private_api_key,
			'Content-Type'                   => 'application/json',
			'X-Cash-Anti-Fraud-Metadata'     => $antifraud_data,
			'X-Cash-Checkout-Widget-Version' => $this->widget_version,
			'X-Cash-Preferred-Locale'        => $userLanguage
		);

		$woo_order_id = $order->get_id();
		$store_title  = get_bloginfo( 'title' );

		$body = array(
			'description'             => " WooCommerce - {$store_title} Order #{$woo_order_id} ",
			'amount_details'          => array(
				'amount'        => absint( $order->get_total() * 100 ),
				'currency_code' => 'MXN',
			),
			'processing_instructions' => array(
				'auto_capture'             => 'true' === $autocapture,
				'use_order_payment_detail' => true,
			),
			'purchase_details'        => array(
				'holacash_system_order_id' => (string)$holacash_order_id,
				'external_system_order_id' => (string)$woo_order_id,
			),
		);

		$additional_details = apply_filters( 'holacashwc_add_charge_additional_details', array() );

		if ( ! empty( $additional_details ) && is_array( $additional_details ) ) {
			$additional_details = holacash_data_stringify( $additional_details );
		}

		$is_store_pickup = false;
		$is_registered_client = false;

		foreach($additional_details as $key => $additional_detail) {
			if($additional_detail['name'] === "is_store_pickup") {
				$is_store_pickup = true;
				$additional_details[$key]['data'] = boolval($additional_details[$key]['data']);
			}
			if($additional_detail['name'] === "is_registered_client") {
				$is_registered_client = true;
				$additional_details[$key]['data'] = boolval($additional_details[$key]['data']);
			}
			if($additional_detail['name'] === "in_blacklist") {
				$additional_details[$key]['data'] = boolval($additional_details[$key]['data']);
			}
		}

		// check and add `is_store_pickup` value in charge additional details
		if(!$is_store_pickup) {
			$additional_details[] = array(
				"name" => "is_store_pickup",
				"data" => identify_store_pickup($order),
			);
		}

		// check and add `is_registered_client`, customer is logged in or not,
		// status for charge additional details
		if(!$is_registered_client) {
			$additional_details[] = array(
				"name" => "is_registered_client",
				"data" => is_user_logged_in(),
			);
		}


		if(!empty(HOLACASH_WC_PLUGIN_VERSION)) {
			// send plugin version in additional_details as required
			$additional_details[] = array(
				"name" => "plugin_version",
				"data" => HOLACASH_WC_PLUGIN_VERSION,
			);
		}


		// stringify array values before setting up to charge additional details
		$body['additional_details'] = array( 'details' => holacash_data_stringify( $additional_details ) );

		$response = wp_remote_post(
			$charge_url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => \HOLACASH_WC\Hola_Cash_WC_Constants::API_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return json_decode( wp_remote_retrieve_body($response), true );

	}

	/**
	 * Update session holacash order data
	 */
	public function update_session_order() {
		$saved_order_id = WC()
			->session
			->get( 'hc_order_id' );

		if ( empty( $saved_order_id ) ) {
			return;
		}
		$updated_data = holacash_order_initial_request_data();

		return $this->update_order_request( $saved_order_id, holacash_order_initial_request_data(), '' );
	}

	/**
	 * Update HolaCash Order request
	 *
	 * @param string $holacash_order_id Order ID generated by holacash system.
	 * @param object $order_data order request payload.
	 * @param string $antifraud_data base64 encoded string of meta array.
	 */
	public function update_order_request( $holacash_order_id, $order_data, $antifraud_data ) {
		$update_url = $this->get_endpoint(
			'update_order',
			array( 'holacash_order_id' => $holacash_order_id )
		);
		$headers    = array(
			'X-Api-Client-Key'               => $this->public_api_key,
			'X-Cash-Checkout-Widget-Version' => $this->widget_version,
			'Content-Type'                   => 'application/json',
		);

		if ( $antifraud_data ) {
			$headers['X-Cash-Anti-Fraud-Metadata'] = $antifraud_data;
		}

		$response = wp_remote_post(
			$update_url,
			array(
				'method'  => 'PATCH',
				'headers' => $headers,
				'body'    => wp_json_encode( $order_data ),
				'timeout' => \HOLACASH_WC\Hola_Cash_WC_Constants::API_TIMEOUT,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['response']['code'] ) {
			return new \WP_Error( 'unable_to_fetch', $response['body'] );
		}

		return true;

	}

	/**
	 * Give Refund using API
	 *
	 * @param string $holacash_order_id Holacash system order id.
	 * @param float  $amount Refunded amount.
	 */
	public function refund( $holacash_order_id, $amount ) {
		$refund_url = $this->get_endpoint(
			'refund_order',
			array( 'holacash_charge_id' => $holacash_order_id )
		);
		$headers    = array(
			'X-Api-Client-Key'               => $this->private_api_key,
			'Content-Type'                   => 'application/json',
			'X-Cash-Checkout-Widget-Version' => $this->widget_version,
		);

		$body = array(
			'amount'        => absint( $amount * 100 ), // Let's change the amount to its smaller unit.
			'currency_code' => 'MXN',
		);

		$response = wp_remote_post(
			$refund_url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => \HOLACASH_WC\Hola_Cash_WC_Constants::API_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['response']['code'] ) {
			return new \WP_Error( 'unable_to_fetch', $response['body'] );
		}

		return json_decode( $response['body'], true );
	}
}
