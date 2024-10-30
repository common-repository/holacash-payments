<?php
/**
 * Widget HTML Render
 *
 * @package HolaCash
 */

defined( 'ABSPATH' ) || die( 'No Script Kiddies Please' );

$public_api_key = $this->public_api_key;
if ( empty( $public_api_key ) ) :
		echo '<p>' . esc_html__( 'This method is unavailable currently', 'woocommerce-gateway-holacash' ) . '</p>';
else : ?>
	<!--Required HTML Elements to render Hola.Cash-->
	<div id="hola_cash_wc_wrapper">
		<div id="instant-holacash-checkout-window" style="height: 600px; width: 100%;" ></div>
	</div>
	<?php
endif;
