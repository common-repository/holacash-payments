<?php
/**
 * Miscellaneous functions
 *
 * @package Holacash
 */

namespace HOLACASH_WC;

defined( 'ABSPATH' ) || die( 'No Script Kiddies Please' );

/**
 * Define static variables to be accessed all
 */
class Hola_Cash_WC_Constants {
	/**
	 * Tells whether test mode is on or off
	 *
	 * @var boolean
	 */
	private static $is_test = '';

	/**
	 * Test api base url
	 *
	 * @var boolean
	 */
	private static $test_api_url = 'https://sandbox.api.holacash.mx/v2';

	/**
	 * Test widget base url
	 *
	 * @var boolean
	 */
	private static $test_widget_base_url = 'https://widget.connect.sandbox.holacash.mx';

	/**
	 * Live api base url
	 *
	 * @var string
	 */
	private static $live_api_url = 'https://live.api.holacash.mx/v2';

	/**
	 * Live widget base url
	 *
	 * @var string
	 */
	private static $live_widget_base_url = 'https://widget.connect.holacash.mx';

	/**
	 * Widget version requires in api
	 *
	 * @var string
	 */
	public static $widget_version = 'plugin.woocommerce/1.0';

	/**
	 * Define test mode name
	 *
	 * @var int
	 */
	public static $test_mode_name = 'sandbox';

	/**
	 * API timeout
	 *
	 * @var int
	 */
	const API_TIMEOUT = 60;


	/**
	 * Define Order Status already attached with charge
	 *
	 * @var int
	 */
	const ORDER_ALREADY_ASSOCIATED_WITH_CHARGE = 'order_already_associated_with_charge';

	/**
	 * Set test mode
	 */
	public static function set_test_mode() {
		if ( '' === self::$is_test ) {
			$options       = new Hola_Cash_WC_Gateway();
			self::$is_test = $options->test_mode;

			// Check if the environment variable has been set, if `getenv` is available on the system.
			if ( function_exists( 'getenv' ) ) {
				$test_api_url = getenv( 'HOLACASH_API_URL' );
				if ( false !== $test_api_url ) {
					self::$test_api_url = $test_api_url;
				}

				$test_widget_base_url = getenv( 'HOLACASH_WIDGET_URL' );
				if ( false !== $test_widget_base_url ) {
					self::$test_widget_base_url = $test_widget_base_url;
				}
			}
		}
	}

	/**
	 * Get secret key.
	 *
	 * @return string
	 */
	public static function get_api_base_url() {
		self::set_test_mode();

		if ( self::$is_test ) {
			return self::$test_api_url;
		} else {
			return self::$live_api_url;
		}
	}

	/**
	 * Get secret key.
	 *
	 * @return string
	 */
	public static function get_widget_base_url() {
		self::set_test_mode();
		if ( self::$is_test ) {
			return self::$test_widget_base_url;
		} else {
			return self::$live_widget_base_url;
		}
	}

	/**
	 * Get secret key.
	 *
	 * @return string
	 */
	public static function get_test_mode_name() {
		$test_mode_name = getenv( 'HOLACASH_PLUGIN_MODE' );
		if ( false !== $test_mode_name ) {
			self::$test_mode_name = $test_mode_name;
		}
		return self::$test_mode_name;
	}
}
