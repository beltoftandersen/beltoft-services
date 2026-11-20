<?php
/**
 * The mails about the IPv4 pool running low: the owner buys more addresses by hand.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Ipv4;

use BeltoftServices\Alerts;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Three reasons, each mailed at most once a day to `ipv4_alert_email` (the failure alert address when empty): the free
 * addresses fell to `ipv4_low_mark` ("low"), none is free ("empty"), and a customer met "no address is free" ("sold_out").
 * Nothing here buys anything: more addresses are bought by hand and added on the IP pool screen.
 */
final class Ipv4Alerts {

	/**
	 * Where the mails go.
	 */
	public static function to(): string {
		$to = (string) Settings::get( 'ipv4_alert_email' );

		return is_email( $to ) ? $to : Alerts::address( true );
	}

	/**
	 * The free count at which the "low" mail is sent.
	 */
	public static function low_mark(): int {
		return max( 0, (int) Settings::get( 'ipv4_low_mark' ) );
	}

	/**
	 * The pool changed: mail when it is low or empty. Called from IpPool after every write.
	 */
	public static function pool_changed(): void {
		/**
		 * Whether the pool mails are sent at all (default true).
		 *
		 * @param bool $on Whether they are on.
		 */
		if ( ! apply_filters( 'beltoft_services_ipv4_alerts', true ) ) {
			return;
		}

		$free = IpPool::free_now();

		if ( $free <= 0 ) {
			self::send( 'empty', 'No free IPv4 address is left', array( 'No free IPv4 address is left in the pool. IPv4 add-ons and new dedicated servers cannot be sold until more are added.' ) );
			return;
		}

		if ( $free <= self::low_mark() ) {
			/* The count in the subject is for the owner only. */
			self::send( 'low', sprintf( 'Only %d free IPv4 addresses are left', $free ), array( sprintf( 'Only %d free IPv4 addresses are left in the pool (mail at %d or fewer).', $free, self::low_mark() ) ) );
		}
	}

	/**
	 * A customer met "no address is free" in the checkout or on the dashboard.
	 */
	public static function sold_out(): void {
		self::send( 'sold_out', 'A customer met "no IPv4 address is free"', array( 'A customer wanted an IPv4 address and none was free (the addresses kept for new servers do not count).' ) );
	}

	/**
	 * Sends one mail unless the same reason was sent in the last day.
	 *
	 * @param string   $reason  low, empty or sold_out.
	 * @param string   $subject Subject.
	 * @param string[] $lines   First lines of the body.
	 */
	private static function send( string $reason, string $subject, array $lines ): void {
		$lines[] = 'Buy more addresses in Robot (RIPE reason "Additional IPv4 address for a customer VPS"), add them on the IP pool screen (Services, IP pool) and run host/ipv4-range.sh for the new range on the host.';

		Alerts::notify( $subject, $lines, null, 'beltoft_services_ipv4_alert_' . $reason, array( 'to' => self::to(), 'fallback' => true ) );
	}
}
