<?php
/**
 * Miscellaneous functions
 *
 * @package Holacash
 */

defined( 'ABSPATH' ) || die( 'No Script Kiddies Please' );

/**
 * Returns formatted purchases in cart to pass in orders API
 */
function holacash_get_cart_items_array() {
	$purchases = array();
	foreach ( WC()
		->cart
		->get_cart() as $cart_item_key => $cart_item ) {

		$product = $cart_item['data'];

		$product_id  = $cart_item['product_id'];
		$quantity    = $cart_item['quantity'];
		$price       = WC()
			->cart
			->get_product_price( $product );
		$subtotal    = $cart_item['line_total'];
		$purchases[] = array(
			'item_total_amount' => array(
				'amount'        => absint( ( $subtotal ) * 100 ),
				'currency_code' => 'MXN',
			),
			'description'       => "{$product->get_title()}",
			'id'                => "{$product_id}",
			'unit_amount'       => array(
				'amount'        => absint( ( $product->get_price() ) * 100 ),
				'currency_code' => 'MXN',
			),
			'quantity'          => absint( $cart_item['quantity'] ),
		);
	}

	return apply_filters( 'hola_purchased_products_array', $purchases );
}

/**
 * Returns basic and required order data.
 *
 * @param float|null $amount order total amount.
 */
function holacash_order_initial_request_data( $amount = null ) {
	$description = apply_filters( 'hola_cash_purchase_title', 'Purchase Info Made On ' . get_bloginfo( 'name' ) );

	// if no amount value found then grab the value from cart.
	$amount = $amount ?? WC()->cart->total;

	$hola_order = array(
	'order_total_amount' => array(
		'amount'        => absint( $amount * 100 ),
		'currency_code' => 'MXN',
	),
	'description'        => "{$description}",
	'purchases'          => holacash_get_cart_items_array(),
	);

	return apply_filters( 'hola_before_configuration_order_array', $hola_order );

}

/**
 * Converts the additional data array of name value pair to associative array
 * of key=>value
 *
 * @param  array $data - array of array containing 'name' and 'value'.
 * @return array $keyval - associative array of 'name'=>'value'.
 */
function holacash_nameval_to_keyval( $data ) {

	$keyval = array();
	foreach ( $data as $pair ) {
		if ( isset( $pair['name'] ) && isset( $pair['data'] ) ) {
			$keyval[ $pair['name'] ] = $pair['data'];
		}
	}

	return $keyval;
}

/**
 * Remove Country code from Phone number
 */
function filter_country_code($country_code, $phone_number) {
	$calling_code = '';
	if( $country_code ){
		$calling_code = WC()->countries->get_country_calling_code( $country_code );
		$calling_code = is_array( $calling_code ) ? $calling_code[0] : $calling_code;
	}
	//remove country code
	$res = str_replace($calling_code,"",$phone_number);

	// filter non digit characters
	$res = substr(preg_replace('/[^0-9]+/', '', $res), -10);

	// check if phone number length is 10 digit
	if(strlen($res) === 10) return "$res";

	return false;
}

/**
 * Returns formatted order data from WC_Order
 *
 * @param object|bool $order  WC_Order|false object.
 * @return array  WooCommerce Order Data.
 */
function holacash_filter_order_data_from_wc_order( $order )
{
	$updated_data = array_merge(
		holacash_order_initial_request_data($order->get_total()),
		array(
		'consumer_details' => array(
				'contact' => array(
					'email'   => "{$order->get_billing_email()}",
				),
				'name'    => array(
					'first_name' => "{$order->get_billing_first_name()}",
				),
		),
		)
	);

	// send WC order_id in external_system_order_id
	$updated_data['external_system_order_id'] = (string)$order->get_id();

	$updated_data['billing_details'] = array(
		'address' => array(
			'address_line_1'      => "{$order->get_billing_address_1()}",
			'address_line_2'      => "{$order->get_billing_address_2()}",
			'locality'            => "{$order->get_billing_city()}",
			'region_name_or_code' => holacash_convert_region_code(holacash_convert_country_code($order->get_billing_country()), $order->get_billing_state()),
			'postal_code'         => "{$order->get_billing_postcode()}",
			'country_code'        => holacash_convert_country_code($order->get_billing_country()),
		),
		'contact' => array(
			'email'   => "{$order->get_billing_email()}",
		),
		'name'    => array(
			'first_name'      => "{$order->get_billing_first_name()}",
			'first_last_name' => "{$order->get_billing_last_name()}",
		),
	);

	if ( ! empty($order->get_shipping_country()) ) {
		$updated_data['shipping_details'] = array(
			'address' => array(
				'address_line_1'      => "{$order->get_shipping_address_1()}",
				'address_line_2'      => "{$order->get_shipping_address_2()}",
				'locality'            => "{$order->get_shipping_city()}",
				'region_name_or_code' => holacash_convert_region_code(holacash_convert_country_code($order->get_shipping_country()), $order->get_shipping_state()),
				'postal_code'         => "{$order->get_shipping_postcode()}",
				'country_code'        => holacash_convert_country_code($order->get_shipping_country()),
			),
			'contact' => array(
				'email'   => "{$order->get_billing_email()}",
			),
			'name'    => array(
				'first_name'      => "{$order->get_shipping_first_name()}",
				'first_last_name' => "{$order->get_shipping_last_name()}",
			),
		);
	} else {
		$updated_data['shipping_details'] = $updated_data['billing_details'];
	}

	$billing_phone = filter_country_code($order->get_billing_country(), $order->get_billing_phone());

	if($billing_phone) {
		$updated_data['consumer_details']['contact']['phone_1'] = $billing_phone;
		$updated_data['shipping_details']['contact']['phone_1'] = $billing_phone;
		$updated_data['billing_details']['contact']['phone_1'] = $billing_phone;
	}

	return apply_filters( 'before_update_holacash_order', $updated_data, $order );
}

/**
 * Converts the WooCommerce country codes to 3-letter ISO codes
 * https://developers.holacash.mx/api_english_prod/country_codes/en/snippet/
 *
 * @param  string $country WooCommerce's 2 letter country code.
 * @return string ISO 3-letter country code
 */
function holacash_convert_country_code( $country ) {
	$countries = include HOLACASH_WC_DIR . '/includes/i18n/countries.php';

	$iso_code = isset( $countries[ $country ] ) ? $countries[ $country ] : $country;
	return (empty($iso_code) || $iso_code == 'None') ? 'MEX' : $iso_code;

}

/**
 * Converts the WooCommerce region codes to 3-letter ISO codes mapped with HolaCash
 * https://developers.holacash.mx/api_english_prod/country_region_codes/en/snippet/
 * 
 * if no mapping found return the name as it is
 *
 * @param  string $country WooCommerce's 2 letter country code.
 * @return string ISO 3-letter country code
 */
function holacash_convert_region_code( $country, $region ) {
	$states =  include HOLACASH_WC_DIR . '/includes/i18n/states.php';
	$regions = isset( $states[ $country ] ) ? $states[ $country ] : [];

	$region_code = WC()->countries->get_states( $country )[$region];

	foreach($regions as $index => $data) {
		if($data['code'] && $data['code'] === $region){
			$region_code = $data['region_code'];
			break;
		}
		else if($data['region_code'] === $region){
			$region_code = $data['region_code'];
			break;
		}
	}
	return (empty($region_code) || $region_code == 'None') ? 'MEX' : $region_code;
}

/**
 * Stringify data or object's keys and values
 *
 * @param mixed $data data to convert in string.
 */
function holacash_data_stringify( $data ) {
	switch ( gettype( $data ) ) {
		case 'boolean':
			return $data ? 'true' : 'false';
		case 'NULL':
			return 'null';
		case 'object':
		case 'array':
			$expressions = array();
			foreach ( $data as $c_key => $c_value ) {
				$expressions[ holacash_data_stringify( $c_key ) ] = holacash_data_stringify( $c_value );
			}
			return $expressions;
		default:
			return "{$data}";
	}
}


/**
 * Identify StorePickup
 *
 * @param  mixed $order
 * @return boolean
 */
function identify_store_pickup($order)
{
	$formattedOrder = holacash_filter_order_data_from_wc_order($order);

	$name = $formattedOrder['shipping_details']['name'];

	// get shipping method object from order data
	$shipping_method = @array_shift($order->get_shipping_methods());

	// search keyword
	$searchword = 'Pickup';

	// search in following section
	$searchableArray = array_values($name);

	// get shipping method id like `local_pickup`
	$searchableArray[] = $shipping_method['method_id'];

	$arr = array_filter(
		$searchableArray, function ($var) use ($searchword) {
			return stripos($var, $searchword) !== false;
		}
	);

	return count($arr) > 0;
}


 /**
 * Parse language according to holacash api requirement
 *
 * @param  string  lang
 *
 * @return string  res
 */
function parseLanguage($lang)
{
	$res = 'es';
	if ($lang == 'en' || stripos($lang, 'en') !== false) {
		$res = 'en';
	} elseif (strlen($lang) === 2) {
		$res = $lang;
	}

	return $res;
}