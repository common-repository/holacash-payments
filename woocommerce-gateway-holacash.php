<?php
/**
 * Plugin Name:          HolaCash Payments
 * Plugin URI:           https://wordpress.org/plugins/holacash-payments/
 * Description:          Receive payments using hola.cash's checkout widget
 * Version:              1.0.11
 * Requires at least:    5.2
 * Tested up to:         6.0.2
 * WC requires at least: 5.6.2
 * WC tested upt to:     6.9.4
 * Requires PHP:         7.4
 * Author:               HolaCash
 * Author URI:           https://www.hola.cash/
 * Text Domain:          woocommerce-gateway-holacash
 * Domain Path:          /languages
 *
 * @package HolaCash
 */

defined( 'ABSPATH' ) || die( 'No Script Kiddies Please' );

// Let's define our plugin constants here.
defined( 'HOLACASH_WC_FILE' ) || define( 'HOLACASH_WC_FILE', __FILE__ );

defined( 'HOLACASH_WC_DIR' ) || define( 'HOLACASH_WC_DIR', untrailingslashit( dirname( HOLACASH_WC_FILE ) ) );

defined( 'HOLACASH_WC_URL' ) || define( 'HOLACASH_WC_URL', plugin_dir_url( HOLACASH_WC_FILE ) );

// Read the version number from the main plugin file then set it to a variable.
$plugin_data = get_file_data( __FILE__, array(
	'Version' => 'Version'
) );

defined( 'HOLACASH_WC_PLUGIN_VERSION' ) || define( 'HOLACASH_WC_PLUGIN_VERSION', $plugin_data['Version'] );

require_once HOLACASH_WC_DIR . '/includes/class-payments.php';

global $holacash_wc;

if ( class_exists( '\HOLACASH_WC\Payments' ) ) {
	$holacash_wc = new \HOLACASH_WC\Payments();
}

/**
 * Return Global object
 */
function hola_cash_wc() {
	return $GLOBALS['holacash_wc'];
}

add_action( 'init', 'holacash_load_textdomain' );

/**
 * Loads translations for static texts
 */
function holacash_load_textdomain() {
	load_plugin_textdomain( 'woocommerce-gateway-holacash', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
