<?php
/**
 * WooCommerce Hola_Cash Exception Class
 *
 * Extends Exception to provide additional data
 *
 * @since 4.0.2
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
class Exception extends \Exception {


	/**
	 * String sanitized/localized error message.
	 *
	 * @var string
	 */
	protected $localized_message;

	/**
	 * Setup exception
	 *
	 * @since 4.0.2
	 * @param string $error_message     Full response.
	 * @param string $localized_message user-friendly translated error message.
	 */
	public function __construct( $error_message = '', $localized_message = '' ) {
		$this->localized_message = $localized_message;
		parent::__construct( $error_message );
	}

	/**
	 * Returns the localized message.
	 *
	 * @since  4.0.2
	 * @return string
	 */
	public function getLocalizedMessage() {
		return $this->localized_message;
	}
}
