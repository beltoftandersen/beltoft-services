<?php
/**
 * Optional driver contract: a sign-in link into the service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A driver whose service a customer can open from My Account. The account page links to the
 * route GET beltoft-services/v1/resources/<id>/open, which checks ownership and only then asks
 * the driver for the URL, so a short-lived sign-in token is made at click time and never sits
 * in a rendered page. open_url() must not call the service.
 */
interface Opens {

	/**
	 * The URL to send the customer to now, or null when the service cannot be opened.
	 *
	 * @param Resource $r Resource (ready, owned by the current user).
	 * @return string|null
	 */
	public function open_url( Resource $r ): ?string;
}
