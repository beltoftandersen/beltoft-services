<?php
/**
 * Per-service rules of a counted service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage\Counted;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A service can carry a free rate for good: resource meta `counted_free_rate` = yes, set by staff (for example for a customer who
 * moves from a fixed plan). Its events are free (`free_reason` rate), never count towards the price steps, and show as free in
 * the customer's list.
 */
final class Rule {

	public const META_FREE = 'counted_free_rate';

	/**
	 * Is this service's rate 0?
	 *
	 * @param Resource $r Resource.
	 */
	public static function free_rate( Resource $r ): bool {
		$free = 'yes' === (string) $r->get( self::META_FREE );

		return $free;
	}
}
