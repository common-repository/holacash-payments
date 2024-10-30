<?php // phpcs:ignore
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// if uninstall not called from WordPress exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Only remove ALL product and page data if WC_REMOVE_ALL_DATA constant is set to true in user's
 * wp-config.php. This is to prevent data loss when deleting the plugin from the backend
 * and to ensure only the site owner can perform this action.
 */
if ( defined( 'WC_REMOVE_ALL_DATA' ) && true === WC_REMOVE_ALL_DATA ) {
	// Delete options.
	delete_option( 'woocommerce_hola_cash_wc_gateway_settings' );
	delete_option( 'wc_hola_cash_wc_gateway_show_changed_keys_notice' );
	delete_option( 'wc_hola_cash_wc_gateway_show_keys_notice' );
	delete_option( 'wc_hola_cash_wc_gateway_show_sca_notice' );
	delete_option( 'wc_hola_cash_wc_gateway_show_ssl_notice' );
	delete_option( 'wc_hola_cash_wc_gateway_show_style_notice' );
	delete_option( 'wc_hola_cash_wc_gateway_version' );
	delete_option( 'wc_hola_cash_wc_gateway_wh_last_error' );
	delete_option( 'wc_hola_cash_wc_gateway_wh_last_failure_at' );
	delete_option( 'wc_hola_cash_wc_gateway_wh_last_success_at' );
	delete_option( 'wc_hola_cash_wc_gateway_wh_monitor_began_at' );
	delete_option( 'wc_hola_cash_wc_gateway_wh_test_last_error' );
	delete_option( 'wc_hola_cash_wc_gateway_wh_test_last_failure_at' );
	delete_option( 'wc_hola_cash_wc_gateway_wh_test_last_success_at' );
	delete_option( 'wc_hola_cash_wc_gateway_wh_test_monitor_began_at' );
}
