<?php
/**
 * Main Class file of Payments
 *
 * @package HolaCash
 */

namespace HOLACASH_WC;

defined( 'ABSPATH' ) || die( 'No Script Kiddies Please' );

/**
 * Payments Main Class
 */
class Payments {


	/**
	 * Construct Payments Main Class.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init_gateway' ) );
		add_action( 'wp_head', array( $this, 'hola_cash_css' ) );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'handle_order_number_custom_query_var' ), 10, 2 );
		add_action( 'woocommerce_before_thankyou', array( $this, 'update_order_followup_authentication_action' ), 10, 1 );
		add_filter( 'plugin_action_links_' . plugin_basename( HOLACASH_WC_FILE ), array( $this, 'hc_settings_link' ), 10, 1 );
		add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'add_capture_btn' ), 10, 1 );
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'amount_capture_html' ), 10, 1 );
		add_action( 'wp_ajax_hc_amount_capture', array( $this, 'capture_pending_amount' ) );
		add_action( 'woocommerce_create_refund', array( $this, 'prepare_refund_object_to_have_data' ), 10, 2 );
		add_action( 'woocommerce_thankyou', array( $this, 'render_payment_instructions' ), 10, 1 );
		add_action( 'add_meta_boxes', array( $this, 'payment_details_metabox' ), 10 );
		add_action( 'woocommerce_view_order', array( $this, 'render_payment_instructions' ), 10, 1 );
		add_action( 'wp_footer', array( $this, 'enqueue_holacash_scripts' ), 10, 0 );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'update_holacash_order' ), 10, 0 );
	}

	/**
	 * Update Holacash order
	 */
	public function update_holacash_order() {
		$hc_api  = new Hola_Cash_WC_API();
		$updated = $hc_api->update_session_order();
	}

	/**
	 * Enqueue necessary script
	 */
	public function enqueue_holacash_scripts() {
		if ( ! is_checkout() ) {
			return;
		}
		$holacash_gateway = new Hola_Cash_WC_Gateway();

		if ( ! $holacash_gateway->enabled ) {
			return;
		}

		$widget_base_url = \HOLACASH_WC\Hola_Cash_WC_Constants::get_widget_base_url();
		wp_enqueue_script( 'holacash-connect', "{$widget_base_url}/connect.min.js", array(), '1.0.5', true );

		$hola_api = new \HOLACASH_WC\Hola_Cash_WC_API();

		wp_register_script( 'woocommerce-gateway-holacash', HOLACASH_WC_URL . '/includes/assets/hola-cash-gateway.js', array( 'jquery' ), '1.0.11', true );
		$localized_data = array(
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'public_key'           => $holacash_gateway->public_api_key,
			'widget_version'       => \HOLACASH_WC\Hola_Cash_WC_Constants::$widget_version,
			'initial_total'        => ( WC()->cart->total ) * 100,
			'order_id'             => $hola_api->create_order(),
			'create_order'         => $hola_api->get_endpoint( 'create_order' ),
			'order_body'           => holacash_order_initial_request_data(),
			/* translators: %s is replaced with String Name */
			'purchase_description' => apply_filters( 'hola_cash_purchase_title', sprintf( __( 'Purchase on %s', 'woocommerce-gateway-holacash' ), get_bloginfo( 'name' ) ) ),
		);
		wp_localize_script( 'woocommerce-gateway-holacash', 'holacashwc', $localized_data );
		wp_enqueue_script( 'woocommerce-gateway-holacash' );
	}


	/**
	 * Adds a payment detail metabox Order Page in admin dashboard.
	 */
	public function payment_details_metabox() {
		global $post;
		$order_id = $post->ID;
		if ( get_post_meta( $order_id, 'hola_pending_charge_additional_data', true ) ) {
			add_meta_box( 'woo-holacash-payment-instruction', __( 'Payment Instructions', 'woocommerce-gateway-holacash' ), array( $this, 'render_payment_instructions' ), 'shop_order', 'normal' );
		}
	}

	/**
	 * Load Payment widget
	 *
	 * @param int $order_id Order ID.
	 */
	public function render_payment_instructions( $order_id ) {
		if ( is_object( $order_id ) ) {
			// It's post object.
			$order_id = $order_id->ID;
		}

		$payment_instructions = get_post_meta( $order_id, 'hola_pending_charge_additional_data', true );
		if ( ! empty( $payment_instructions ) ) {
			include_once HOLACASH_WC_DIR . '/includes/views/pending-payment-instructions.php';
		}
	}

	/**
	 * Use : Will make $refund object available in process_refund function
	 *
	 * @param object $refund WC_Refund object.
	 * @param array  $args   Args provided for creating Refund object.
	 */
	public function prepare_refund_object_to_have_data( $refund, $args ) {
		global $temp_refund_obj;
		$temp_refund_obj = $refund;
	}

	/**
	 * Captures already created charge
	 */
	public function capture_pending_amount() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'hc-capture' ) ) {
			// nonce failed.
			wp_send_json(
				array(
					'status'  => false,
					'message' => 'nonce verification failed',
				)
			);
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
			$amount   = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;
			$order    = wc_get_order( $order_id );
			if ( empty( $order ) ) {
				wp_send_json(
					array(
						'status'  => false,
						'message' => 'Couldn\'t found the order',
					)
				);
			}
			// lock the order to prevent any update from webhook.
			$this->lock_the_order( $order_id );
			$api      = new Hola_Cash_WC_API();
			$captured = $api->capture( $order_id, $amount );
			if ( is_wp_error( $captured ) ) {
				$this->unlock_the_order( $order_id );
				wp_send_json(
					array(
						'status'  => false,
						'message' => $captured->get_error_message(),
					)
				);
			}

			$total_captured         = floatval( get_post_meta( $order_id, 'hc_total_captured', true ) );
			$total_captured        += $amount;
			$capture_transaction_id = get_post_meta( $order_id, 'hc_pending_capture_charge_id', true );
			update_post_meta( $order_id, 'holacash_charge_id', $capture_transaction_id );
			update_post_meta( $order_id, 'hc_total_captured', $total_captured );

			// Update Order Totals.
			$order->set_total( $total_captured );

			$total_captured_history                                        = get_post_meta( $order_id, 'hc_total_captured_history', true );
			$total_captured_history                                        = ! is_array( $total_captured_history ) ? array() : $total_captured_history;
			$total_captured_history[ $captured['capture_transaction_id'] ] = $amount;
			update_post_meta( $order_id, 'hc_total_captured_history', $total_captured_history );
			$order->payment_complete( $captured['capture_transaction_id'] );
			// Webhook can now update the status,let's unlock the order.
			$this->unlock_the_order( $order_id );
			wp_send_json(
				array(
					'status' => true,
				)
			);
		}
	}

	/**
	 * Check if order is locked to perform any action.
	 *
	 * @param int $order_id WooCommerce Order ID.
	 */
	public function is_order_locked( $order_id ) {
		return get_transient( "hola_cash_woo_order_locked_{$order_id}" );

	}

	/**
	 * Lock the order to perform any action.
	 *
	 * @param int $order_id WooCommerce Order ID.
	 */
	public function lock_the_order( $order_id ) {
		set_transient( "hola_cash_woo_order_locked_{$order_id}", 60 );
	}

	/**
	 * Unlock locked to perform any action.
	 *
	 * @param int $order_id WooCommerce Order ID.
	 */
	public function unlock_the_order( $order_id ) {
		delete_transient( "hola_cash_woo_order_locked_{$order_id}" );
	}

	/**
	 * Check if order payment has pending capture status.
	 *
	 * @param  int $order_id WooCommerce Order ID.
	 * @return bool
	 */
	public function is_pending_capture( $order_id ) {
		$transaction_id = get_post_meta( $order_id, 'hc_pending_capture_charge_id', true );
		return ! empty( $transaction_id );
	}

	/**
	 * Add setting link on plugin page.
	 *
	 * @param int $links Default links array.
	 */
	public function hc_settings_link( $links ) {
		$url     = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=hola_cash_wc_gateway' );
		$links[] = "<a href='{$url}'>" . __( 'Settings' ) . '</a>';
		return $links;
	}

	/**
	 * Add capture button on order page in admin dashboard.
	 *
	 * @param object $order WC_Order object.
	 */
	public function add_capture_btn( $order ) {
		if ( $this->is_pending_capture( $order->get_id() ) ) :
			?>
			<button type='button' class='button capture-pending-charge'><?php esc_html_e( 'Capture', 'woocommerce-gateway-holacash' ); ?></button>;
			<?php
		endif;
	}

	/**
	 * Renders html for capture charge on order page in admin dashboard.
	 *
	 * @param int $order_id WooCommerce Order ID.
	 */
	public function amount_capture_html( $order_id ) {
		if ( $this->is_pending_capture( $order_id ) ) :

			include_once HOLACASH_WC_DIR . '/includes/views/admin/hc-admin-capture.php';
		endif;
	}

	/**
	 * Check and update Followup Authentication status.
	 *
	 * @param int $order_id WooCommerce Order ID.
	 */
	public function update_order_followup_authentication_action( $order_id ) {
		$charge_id = get_post_meta( $order_id, 'holacash_pending_charge_id', true );

		if ( empty( $charge_id ) ) {
			return;
		}
		$order         = wc_get_order( $order_id );
		$api           = new Hola_Cash_WC_API();
		$charge_status = $api->get_charge_status( $charge_id );

		if ( 'captured' === $charge_status || 'completed' === $charge_status ) {
			$order->payment_complete( $charge_id );
			update_post_meta( $order_id, 'holacash_charge_id', $charge_id );
			delete_post_meta( $order_id, 'holacash_pending_charge_id' );
		} elseif ( 'pending_capture' === $charge_status ) {
			$order->add_order_note( "Recent transaction status fetched to Pending capture with charge id {$charge_id}" );
			$order->update_status( 'pending', 'Authorization was successful' );
			update_post_meta( $order_id, 'hc_pending_capture_charge_id', $charge_id );
			delete_post_meta( $order_id, 'holacash_pending_charge_id' );
		} elseif ( 'failed' === $charge_status || 'cancelled' === $charge_status ) {
			$order->update_status( 'failed', 'Payment failed at authentication step' );
			delete_post_meta( $order_id, 'holacash_pending_charge_id' );
			update_post_meta( $order_id, 'holacash_failed_charge_id', $charge_id );
			add_filter( 'woocommerce_order_has_status', array( $this, 'return_failed' ) );
		}
	}

	/**
	 * Filter function for temporary failed status
	 */
	public function return_failed() {
		return 'failed';
	}

	/**
	 * Gives ability to put holacash_charge_id in wc_get_orders function
	 *
	 * @param object $query      WP_Query.
	 * @param array  $query_vars query vars present to search.
	 */
	public function handle_order_number_custom_query_var( $query, $query_vars ) {
		if ( ! empty( $query_vars['holacash_charge_id'] ) ) {
			$query['meta_query'][] = array(
				'key'   => 'holacash_charge_id',
				'value' => esc_attr( $query_vars['holacash_charge_id'] ),
			);
		}

		if ( ! empty( $query_vars['holacash_order_id'] ) ) {
			$query['meta_query'][] = array(
				'key'   => 'holacash_order_id',
				'value' => esc_attr( $query_vars['holacash_order_id'] ),
			);
		}

		return $query;
	}

	/**
	 * Checks for WooCommerce and add required files and hooks
	 */
	public function init_gateway() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			// WooCommerc is the core dependency of the plugin.
			return;
		}
		include_once HOLACASH_WC_DIR . '/includes/functions.php';
		include_once HOLACASH_WC_DIR . '/includes/class-exception.php';
		include_once HOLACASH_WC_DIR . '/includes/class-hola-cash-wc-constants.php';
		include_once HOLACASH_WC_DIR . '/includes/class-hola-cash-wc-gateway.php';
		include_once HOLACASH_WC_DIR . '/includes/class-hola-cash-wc-api.php';
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ), 10, 1 );
		add_action( 'wp_ajax_nopriv_hola_cash_wc_listen', array( $this, 'process_webhook' ) );
		add_action( 'wp_ajax_hola_cash_wc_listen', array( $this, 'process_webhook' ) );
	}

	/**
	 * Main listener function for webhook
	 */
	public function process_webhook() {
		$api = new Hola_Cash_WC_API();
		$api->process_webhook();
		wp_send_json( array() );
	}

	/**
	 * Register HolaCash Gateway.
	 *
	 * @param array $gateways Array of pre registered gateways.
	 */
	public function add_gateway( $gateways ) {
		$gateways[] = '\HOLACASH_WC\Hola_Cash_WC_Gateway';
		return $gateways;
	}

	/**
	 * Enqueue Styling for HolaCash
	 */
	public function hola_cash_css() {
		wp_enqueue_style( 'hola-cash-css', HOLACASH_WC_URL . '/includes/assets/hola-cash-styles.css', array(), '1.0.1' );
	}
}
