<?php
/**
 * The name of the shop that runs this plugin, for customer-facing text.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * The site name, decoded for display, filterable.
 */
final class Brand {

	/**
	 * The shop's name.
	 *
	 * @return string
	 */
	public static function name(): string {
		return (string) apply_filters( 'beltoft_services_brand_name', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	}
}
