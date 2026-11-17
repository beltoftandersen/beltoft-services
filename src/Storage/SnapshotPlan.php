<?php
/**
 * The automatic snapshot plan of a box.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every day, week or month at a set hour, or off. The same checks serve the default of a new box and the customer's change.
 */
final class SnapshotPlan {

	public const EVERY = array( 'day', 'week', 'month' );

	/** The hour of the default plan (night time, UTC). */
	public const HOUR = 3;

	/** How many automatic snapshots the plan keeps by default. */
	public const KEEP = 7;

	/**
	 * The plan a new box gets from the setting, or null for none.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function default_plan(): ?array {
		$every = (string) Settings::get( 'storage_snapshot_default' );

		return in_array( $every, self::EVERY, true ) ? array( 'every' => $every, 'hour' => self::HOUR, 'minute' => 0, 'max' => self::KEEP ) : null;
	}

	/**
	 * A plan from what a customer sent, or null when it says off. A WP_Error when it cannot be read.
	 *
	 * @param mixed $every Day, week, month or off.
	 * @param mixed $hour  0 to 23.
	 * @return array<string, mixed>|null|\WP_Error
	 */
	public static function from_input( $every, $hour ) {
		$every = (string) $every;

		if ( 'off' === $every ) {
			return null;
		}

		if ( ! in_array( $every, self::EVERY, true ) || ! is_numeric( $hour ) || (int) $hour < 0 || (int) $hour > 23 ) {
			return new \WP_Error( 'invalid_plan', __( 'Choose every day, week or month, and an hour from 0 to 23.', 'beltoft-services' ) );
		}

		return array( 'every' => $every, 'hour' => (int) $hour, 'minute' => 0, 'max' => self::KEEP );
	}
}
