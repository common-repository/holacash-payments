<?php
/**
 * Admin setting for HolaCash
 *
 * @package HolaCash
 */

defined( 'ABSPATH' ) || die( 'No Script Kiddies Please' );

?>
<h3><?php esc_html__( 'Hola.Cash options', 'woocommerce-gateway-holacash' ); ?></h3>
<table class="form-table">
	<?php $this->generate_settings_html(); ?>
</table>
<div class='hola-cash-instructions'>
	<h4>Instructions: </h4>
	<p>Set the Webhook url to : <?php echo esc_url( admin_url( 'admin-ajax.php?action=hola_cash_wc_listen' ) ); ?></p>
</div>
