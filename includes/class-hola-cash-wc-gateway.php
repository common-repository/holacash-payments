<?php
/**
 * Class Hola_Cash_WC_Gateway file.
 *
 * @package woocommerce
 */

namespace HOLACASH_WC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hola.Cash Payment Gateway.
 *
 * Provides Hola.Cash payment gateway.
 *
 * @class   Hola_Cash_WC_Gateway
 * @extends WC_Payment_Gateway
 */
class Hola_Cash_WC_Gateway extends \WC_Payment_Gateway {

	/**
	 * Whether or not logging is enabled
	 *
	 * @var bool
	 */
	public static $log_enabled = false;

	/**
	 * Logger instance
	 *
	 * @var WC_Logger
	 */
	public static $log = false;

	/**
	 * Array of locales
	 *
	 * @var array
	 */
	public $locale;

	/**
	 * Test mode name
	 *
	 * @var string
	 */
	protected $test_mode_name;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {

		$this->id                 = 'hola_cash_wc_gateway';
		$this->icon               = apply_filters( 'hola_cash_icon', '' );
		$this->has_fields         = true;
		$this->method_title       = __( 'Hola.Cash', 'woocommerce-gateway-holacash' );
		$this->method_description = __( 'Card, cash & Transfer payments', 'woocommerce-gateway-holacash' );

		$this->test_mode_name = \HOLACASH_WC\Hola_Cash_WC_Constants::get_test_mode_name();

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Let's add support for refund.
		$this->supports = array(
			'products',
			'refunds',
		);

		// Define user set variables.
		$this->title           = $this->method_title;
		$this->description     = $this->method_description;
		$this->enabled         = $this->get_option( 'enabled' );
		$this->public_api_key  = $this->get_option( 'public_api_key' );
		$this->private_api_key = $this->get_option( 'private_api_key' );
		$this->test_mode       = $this->get_option( 'test_mode' ) === 'yes';
		$this->webhook_key     = $this->get_option( 'webhook_key' );
		$this->debug           = 'yes' === $this->get_option( 'debug', 'no' );
		self::$log_enabled     = $this->debug;

		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_filter( 'script_loader_tag', array( $this, 'holacash_script_loader_tag' ), 10, 2 );

		// Note: display error is in the parent class.
		add_action( 'admin_notices', array( $this, 'display_errors' ), 9999 );
	}

	/**
	 * Checks whether new keys are being entered when saving options.
	 */
	public function process_admin_options() {

		// Load all old values before the new settings get saved.
		$old_public_api_key  = $this->get_option( 'public_api_key' );
		$old_private_api_key = $this->get_option( 'private_api_key' );
		$old_webhook_key     = $this->get_option( 'webhook_key' );
		$old_test_mode       = $this->get_option( 'test_mode' );

		$this->init_settings();

		$post_data          = $this->get_post_data();
		$keep_old_test_mode = false;

		foreach ( $this->get_form_fields() as $key => $field ) {
			if ( 'title' !== $this->get_field_type( $field ) ) {
				try {
					$this->settings[ $key ] = $this->get_field_value( $key, $field, $post_data );
				} catch ( \Exception $e ) {
					if ( in_array( $key, array( 'public_api_key', 'private_api_key', 'webhook_key' ) ) && ! $keep_old_test_mode ) {
						$keep_old_test_mode = true;
					}
					$this->add_error( $e->getMessage() );
				}
			}
		}

		if ( $keep_old_test_mode ) {
			$this->settings['test_mode'] = $old_test_mode;
		}

		if ( empty( $this->settings['public_api_key'] )
			|| empty( $this->settings['private_api_key'] )
			|| empty( $this->settings['webhook_key'] ) ) {
			$this->settings['enabled'] = 'no';
		}

		$option_key = $this->get_option_key();

		do_action( 'woocommerce_update_option', array( 'id' => $option_key ) );
		return update_option( $option_key, apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );
	}

	/**
	 * Validate Public Key
	 *
	 * @param  string $key Field key.
	 * @param  string $value Posted Value.
	 * @return string
	 * @throws \Exception  Public API Key should be valid according to live or sandbox environment.
	 */
	public function validate_public_api_key_field( $key, $value ) {
		$value     = $this->validate_text_field( $key, $value );
		$mode_name = 'yes' === $this->settings['test_mode'] ? $this->test_mode_name : 'live';

		if ( ! empty( $value ) && ! preg_match( "/^pub_{$mode_name}_/", $value ) ) {
			/* translators: %1$s: plugin mode live|sandbox */
			throw new \Exception( sprintf( __( 'The "Public Key" should start with "pub_%1$s", enter the correct key.', 'woocommerce-gateway-holacash' ), $mode_name ) );
		}
		return $value;
	}

	/**
	 * Validate Secret Key
	 *
	 * @param  string $key Field key.
	 * @param  string $value Posted Value.
	 * @return string
	 * @throws \Exception  Private API Key should be valid according to live or sandbox environment.
	 */
	public function validate_private_api_key_field( $key, $value ) {
		$value     = $this->validate_text_field( $key, $value );
		$mode_name = 'yes' === $this->settings['test_mode'] ? $this->test_mode_name : 'live';

		if ( ! empty( $value ) && ! preg_match( "/^skt_{$mode_name}_/", $value ) ) {
			/* translators: %1$s: plugin mode live|sandbox */
			throw new \Exception( sprintf( __( 'The "Secret Key" should start with "skt_%1$s", enter the correct key.', 'woocommerce-gateway-holacash' ), $mode_name ) );
		}
		return $value;
	}

	/**
	 * Validate Webhook Key
	 *
	 * @param  string $key Field key.
	 * @param  string $value Posted Value.
	 * @return string
	 * @throws \Exception  Webhook Key should be valid according to live or sandbox environment.
	 */
	public function validate_webhook_key_field( $key, $value ) {
		$value     = $this->validate_text_field( $key, $value );
		$mode_name = 'yes' === $this->settings['test_mode'] ? $this->test_mode_name : 'live';

		if ( ! empty( $value ) && ! preg_match( "/^whk_{$mode_name}_/", $value ) ) {
			/* translators: %1$s: plugin mode live|sandbox */
			throw new \Exception( sprintf( __( 'The "Webhook Key" should start with "whk_%1$s", enter the correct key.', 'woocommerce-gateway-holacash' ), $mode_name ) );
		}
		return $value;
	}

	/**
	 * Modifies the script to add public key
	 *
	 * @param string $tag    Tag.
	 * @param string $handle ID of the script .
	 */
	public function holacash_script_loader_tag( $tag, $handle ) {
		if ( 'holacash-connect' === $handle ) {
			if ( strpos( $tag, 'data-public-key' ) === false ) {
				$tag = str_replace( '<script', "<script data-public-key='{$this->public_api_key}' ", $tag );
			}

			return str_replace( 'holacash-connect-js', 'holacash-connect', $tag );
		}
		return $tag;
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {

		$this->form_fields = array(
			'enabled'         => array(
				'title'       => __( 'Enable', 'woocommerce-gateway-holacash' ),
				'description' => __( 'To create an account send us a message and you we will respond as soon as possible', 'woocommerce-gateway-holacash' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Hola.Cash', 'woocommerce-gateway-holacash' ),
				'default'     => 'no',
			),

			'title'       => array(
				'title'       => __( 'Title', 'woocommerce-gateway-holacash' ),
				'description' => __( "To update payment method title in checkout page", 'woocommerce-gateway-holacash' ),
				'type'        => 'text',
				'default'     => 'Tarjetas de crédito / débito y más',
			),

			'test_mode'       => array(
				'title'       => __( 'Test Mode', 'woocommerce-gateway-holacash' ),
				'description' => __( "To create a trial account (  sandbox  ) <a href='https://developers.holacash.mx/access/en'>click here</a>", 'woocommerce-gateway-holacash' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Test Mode', 'woocommerce-gateway-holacash' ),
				'default'     => 'yes',
			),
			'public_api_key'  => array(
				'title'   => __( 'Public API Key', 'woocommerce-gateway-holacash' ),
				'type'    => 'text',
				'default' => '',
			),
			'private_api_key' => array(
				'title'   => __( 'Private API Key', 'woocommerce-gateway-holacash' ),
				'type'    => 'password',
				'default' => '',
			),
			'webhook_key'     => array(
				'title'       => __( 'Webhook Key', 'woocommerce-gateway-holacash' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'This can be configured in your hola.cash portal', 'woocommerce-gateway-holacash' ),
			),
			'debug'           => array(
				'title'       => __( 'Debug', 'woocommerce-gateway-holacash' ),
				/* translators: %1$s: woocommerce logs url */
				'description' => sprintf( __( 'Whether to log the event or not. <a href="%1$s">View Logs -></a>', 'woocommerce-gateway-holacash' ), esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Debug Mode', 'woocommerce-gateway-holacash' ),
				'default'     => 'no',
			),
		);

	}

	/**
	 * Get title of function
	 */
	public function get_title() {
		if ( is_checkout() ) {
			return apply_filters( 'holacashwc_method_title', $this->get_option('title') );
		}
		return apply_filters( 'holacashwc_method_title', $this->title );
	}

	/**
	 * Admin settings page.
	 */
	public function admin_options() {
		include_once HOLACASH_WC_DIR . '/includes/views/hola-cash-admin-settings.php';
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param  int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		global $holacash_wc;
		WC()
			->session
			->set( 'hc_order_id', '' );

		$holacash_wc->lock_the_order( $order_id );
		$order = wc_get_order( $order_id );

		$holacash_order_id = isset( $_POST['holacash_order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['holacash_order_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$antifraud_data = isset( $_POST['hc_uid'] ) ? sanitize_text_field( wp_unslash( $_POST['hc_uid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$userLanguage = isset( $_POST['hc_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['hc_lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		
		$hc_api  = new Hola_Cash_WC_API();
		$charged = $hc_api->create_charge( $holacash_order_id, $order, ["antifraud_data"=> $antifraud_data, "userLanguage" => $userLanguage] );
		update_post_meta( $order_id, 'holacash_order_id', $holacash_order_id );

		if ( is_wp_error( $charged ) ) {
			$holacash_wc->unlock_the_order( $order_id );
			return array(
				'result'   => 'failure',
				'redirect' => '',
			);
		}

		if($charged['detail'] && $charged['detail']['code'] === \HOLACASH_WC\Hola_Cash_WC_Constants::ORDER_ALREADY_ASSOCIATED_WITH_CHARGE) {
			return array(
				'result'   => 'success',
				'redirect' => wc_get_checkout_url()
			);
		}

		if ( array_key_exists( 'status_details', $charged ) ) {
			$status = $charged['status_details']['status'];
		} else {
			$status = $charged['status'];
		}

		switch ( $status ) {
			case 'success':
				$additional_data = holacash_nameval_to_keyval( $charged['status_details']['detail']['additional_details'] );
				$charge_id       = $charged['id'];
				if ( 'captured' === $additional_data['charge_status'] || 'completed' === $additional_data['charge_status'] ) {
					update_post_meta( $order_id, 'holacash_charge_id', $charge_id );
					$order->add_order_note( "Charge captured from Holacash with charge id {$charge_id}" );
					$order->payment_complete( $charge_id );
				} elseif ( 'pending_capture' === $additional_data['charge_status'] ) {
					update_post_meta( $order_id, 'hc_pending_capture_charge_id', $charge_id );
					$order->add_order_note( "Authorization of order amount taken with charge id: {$charge_id}" );
				} else {
					$holacash_wc->unlock_the_order( $order_id );
					exit();
				}

				break;

			case 'failure':
				$code = $charged['detail']['code'];
				$order->add_order_note( "{$charged['detail']['code']}: {$charged['message']}" );

				// TODO review this incomplete condition for failure and do appropriate action
				if ( 0 && \HOLACASH_WC\Hola_Cash_WC_Constants::ORDER_ALREADY_ASSOCIATED_WITH_CHARGE === $code ) {
					// order is already paid.
					$order->payment_complete();
				} else {
					// Fail the payment.
					$order->update_status( 'failed', $charged['message'] );
					$holacash_wc->unlock_the_order( $order_id );
					return array(
						'result'   => 'failure',
						'messages' => $charged['message'],
					);
				}

				break;

			case 'pending':
				$additional_data = holacash_nameval_to_keyval( $charged['detail']['additional_details'] );
				$charge_id       = $additional_data['cash_transaction'];
				update_post_meta( $order_id, 'holacash_pending_charge_id', $charge_id );

				if ( array_key_exists( 'redirect_url', $additional_data ) ) {
					$url = "#?type=action_required&return_url={$additional_data['redirect_url']}&post_return_url=" . add_query_arg( 'holacash_update', 1, $this->get_return_url( $order ) );
					$order->update_status( 'on-hold', __( 'Awaiting for user authentication', 'woocommerce-gateway-holacash' ) );
					$holacash_wc->unlock_the_order( $order_id );
					return array(
						'result'   => 'success',
						'redirect' => $url,
					);
				} else {
					update_post_meta( $order_id, 'hola_pending_charge_additional_data', $additional_data );
					$order->update_status( 'on-hold', "{$additional_data['action']}: " . __( 'Awaiting for user action', 'woocommerce-gateway-holacash' ) );
					$holacash_wc->unlock_the_order( $order_id );
					return array(
						'result'   => 'success',
						'redirect' => $this->get_return_url( $order ),
					);
				}

				break;

			default:
				$holacash_wc->unlock_the_order( $order_id );
				return array(
					'result'   => 'failure',
					'redirect' => '',
				);
		}

		WC()
			->cart
			->empty_cart();
		$holacash_wc->unlock_the_order( $order_id );
		// Return thankyou redirect.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);

	}

	/**
	 * Process refund from holacash
	 *
	 * @param int    $order_id Woocommerce order id.
	 * @param float  $amount   Refund amount.
	 * @param string $reason   Refund reason.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {

		$order = wc_get_order( $order_id );
		global $holacash_wc;

		if ( empty( $amount ) ) {
			$order->add_order_note( 'Amount can\'t be empty' );
			return false;
		}

		$order_total = $order->get_total();

		$holacash_charge_id = get_post_meta( $order_id, 'holacash_charge_id', true );
		$hc_api             = new Hola_Cash_WC_API();

		// Will lock the order for now.
		$holacash_wc->lock_the_order( $order_id );

		if ( ! isset( $GLOBALS['hc_refund_id'] ) ) {
			$refunded = $hc_api->refund( $holacash_charge_id, $amount );

			if ( is_wp_error( $refunded ) ) {
				$holacash_wc->unlock_the_order( $order_id );
				$order->add_order_note( 'Holacash Refund failed: ' . $refunded->get_error_message() );
				return false;
			}

			$refund_id = $refunded['refund_transaction_id'];
		} else {
			// It's a webhook call, that has been made from merchant dashboard.
			$refund_id = $hc_refund_id;
		}

		global $temp_refund_obj;

		update_post_meta( $temp_refund_obj->get_id(), 'holacash_refund_transaction_id', $refund_id );

		$order->add_order_note( "Refund of {$amount} completed with refund id {$refund_id} " );
		$refund_history_ids               = get_post_meta( $order_id, 'holacash_refund_history_mapping', true );
		$refund_history_ids               = ! is_array( $refund_history_ids ) ? array() : $refund_history_ids;
		$refund_history_ids[ $refund_id ] = $temp_refund_obj->get_id();
		update_post_meta( $order_id, 'holacash_refund_history_mapping', $refund_history_ids );

		// Now webhook can update the order.
		$holacash_wc->unlock_the_order( $order_id );

		return true;
	}

	/**
	 * Render Payment Fields using hola.cash connect.js
	 */
	public function payment_fields() {
		include_once HOLACASH_WC_DIR . '/includes/views/render-widget.php';
	}

	/**
	 * Logging method.
	 *
	 * @param string $message Log message.
	 * @param string $source  Source of log.
	 * @param string $level   Optional. Default 'info'. Possible values:
	 *                        emergency|alert|critical|error|warning|notice|info|debug.
	 */
	public static function log( $message, $source = 'hola_cash_wc_gateway', $level = 'info' ) {
		if ( self::$log_enabled ) {
			if ( empty( self::$log ) ) {
				self::$log = wc_get_logger();
			}
			self::$log->log( $level, $message, array( 'source' => $source ) );
		}
	}
}
