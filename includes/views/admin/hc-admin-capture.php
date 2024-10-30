<?php
/**
 * Available args:
 * $order_id - Integer - WooCommerce Order ID
 *
 * @package Holacash
 */

defined( 'ABSPATH' ) || die( 'No Script Kiddies Please' );
$total_captured = get_post_meta( $order_id, 'hc_total_captured', true );
$woo_order      = wc_get_order( $order_id );

if ( $total_captured ) :
	?>
	<table class='wc-order-totals'>
		<tbody>
			<tr>
				<td><?php echo esc_html__( 'Captured Total', 'woocommerce-gateway-holacash' ); ?> </td>
				<td> <?php echo wc_price( $total_captured, array( 'currency' => $woo_order->get_currency() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			</tr>
		</tbody>
	</table>
<?php endif; ?>


<table class="wc-order-totals" id='hc_capture_container' style='display:none'>
	<tbody>
		<tr>
			<td class="label">
				<label for="capture_amount">
				<?php echo wc_help_tip( __( 'Capture upto the order amount', 'woocommerce-gateway-holacash' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Capture amount', 'woocommerce-gateway-holacash' ); ?>:
				</label>
			</td>
			<td class="total">
				<input type="text" id="capture_amount" name="capture_amount" class="wc_input_price" />
				<div class="clear"></div>
			</td>
		</tr>
		<tr>
			<td colspan="2"><button><?php echo esc_html__( 'Capture', 'woocommerce-gateway-holacash' ); ?></button></td>
		</tr>
	</tbody>
</table>

<?php
wp_register_script(
	'hc-capture-js',
	HOLACASH_WC_URL . '/includes/assets/admin-capture-amount.js',
	array( 'jquery' ),
	'1.0.5',
	true
);
wp_localize_script(
	'hc-capture-js',
	'hc_capture',
	array(
		'order_id' => $order_id,
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'action'   => 'hc_amount_capture',
		'nonce'    => wp_create_nonce( 'hc-capture' ),
	)
);
wp_enqueue_script( 'hc-capture-js' );
wp_enqueue_script( 'hc-capture-js' );
