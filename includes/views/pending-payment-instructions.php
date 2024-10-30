<?php
/**
 * Available args
 * $payment_instructions - Additional data available in charge details
 *
 * @package HolaCash
 */

if ( isset( $payment_instructions['bank_code'] ) ) :
	// It's supposed to be a bank transfer.
	?>
<table>
	<tr>
		<th><?php echo esc_html__( 'Transaction ID', 'woocommerce-gateway-holacash' ); ?></th>
		<td><?php echo wp_kses_post( $payment_instructions['cash_transaction'] ); ?></td>
	</tr>
	<tr>
		<th><?php echo esc_html__( 'Transaction CLABE', 'woocommerce-gateway-holacash' ); ?></th>
		<td><?php echo wp_kses_post( $payment_instructions['transaction_clabe'] ); ?></td>
	</tr>
	<tr>
		<th><?php echo esc_html__( 'Bank Name', 'woocommerce-gateway-holacash' ); ?></th>
		<td><?php echo wp_kses_post( $payment_instructions['bank_name'] ); ?></td>
	</tr>
	<tr>
		<th><?php echo esc_html__( 'Expiry', 'woocommerce-gateway-holacash' ); ?></th>
		<td><?php echo wp_kses_post( $payment_instructions['payment_expiry_formatted'] ); ?></td>
	</tr>
</table>
	<?php
elseif ( isset( $payment_instructions['payment_network'] ) ) :
		// It's supposed to be a store payment.
	?>
<table>
	<tr>
		<th><?php echo esc_html__( 'Transaction ID', 'woocommerce-gateway-holacash' ); ?></th>
		<td><?php echo wp_kses_post( $payment_instructions['cash_transaction'] ); ?></td>
	</tr>
	<tr>
		<th><?php echo esc_html__( 'Bar Code', 'woocommerce-gateway-holacash' ); ?></th>
		<td><img src='<?php echo wp_kses_post( $payment_instructions['reference_url'] ); ?>' alt='transaction bar code'/></td>
	</tr>
	<tr>
		<th><?php echo esc_html__( 'Transaction Reference', 'woocommerce-gateway-holacash' ); ?></th>
		<td><?php echo wp_kses_post( $payment_instructions['reference_number'] ); ?></td>
	</tr>
	<tr>
		<th><?php echo esc_html__( 'Expiry', 'woocommerce-gateway-holacash' ); ?></th>
		<td><?php echo wp_kses_post( $payment_instructions['payment_expiry_formatted'] ); ?></td>
	</tr>
</table>
	<?php
endif;
?>
<h4><?php echo esc_html__( 'Instructions: ', 'woocommerce-gateway-holacash' ); ?></h4>
<?php foreach ( $payment_instructions['payment_instructions'] as $instruction ) : ?>
	<p><?php echo wp_kses_post( $instruction['value'] ); ?></p>
<?php endforeach; ?>
