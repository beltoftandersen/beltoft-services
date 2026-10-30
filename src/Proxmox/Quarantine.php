<?php
/**
 * The network quarantine of a server: the flag, the decision, the lift and the notices.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Events;
use BeltoftServices\Alerts;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A quarantined server has the resource meta `fw_quarantine` ({signal, at, counts, repeat, notified}). The firewall
 * helper (Firewall) reads the flag in every reconcile path, so the outgoing policy stays DROP until the flag is gone;
 * the only writes to Proxmox are Firewall's (jobs `fw_quarantine` and `fw_quarantine_lift`). The flag is set under
 * the resource lock when the signal is confirmed, before the firewall is written, and is cleared by the lift job only
 * after Proxmox shows the quarantine gone. Nothing lifts a quarantine by itself.
 *
 * Meta `fw_quarantine_log` keeps the times of the last quarantines: a second one within REPEAT_DAYS also mails the admin
 * "repeat". No breach hold is set: the owner decides.
 */
final class Quarantine {

	public const META  = 'fw_quarantine';
	public const LOG   = 'fw_quarantine_log';
	public const EMAIL = 'beltoft_services_quarantine';

	public const REPEAT_DAYS = 7;

	/**
	 * Is the server quarantined?
	 *
	 * @param Resource $r Resource.
	 */
	public static function active( Resource $r ): bool {
		return is_array( $r->get( self::META ) );
	}

	/**
	 * Marks the server quarantined and queues the firewall job. The caller holds the resource lock.
	 *
	 * @param Resource           $r       Resource.
	 * @param string             $signal  scan, syn or udp.
	 * @param array<string, int> $counts  The latest counts.
	 * @param int                $now     Timestamp.
	 * @return bool Whether it was started (false: already quarantined, or not stored).
	 */
	public static function begin( Resource $r, string $signal, array $counts, int $now ): bool {
		if ( self::active( $r ) ) {
			return false;
		}

		$log    = array_values( array_filter( array_map( 'intval', (array) $r->get( self::LOG ) ), static fn( $at ) => $at > 0 ) );
		$repeat = false;

		foreach ( $log as $at ) {
			if ( $now - $at < self::REPEAT_DAYS * DAY_IN_SECONDS ) {
				$repeat = true;
			}
		}

		$log[] = $now;
		$flag  = array(
			'signal'   => $signal,
			'at'       => $now,
			'counts'   => array_map( 'intval', $counts ),
			'repeat'   => $repeat,
			'notified' => false,
		);

		if ( ! $r->set( array( self::META => $flag, self::LOG => array_slice( $log, -10 ) ) ) ) {
			return false;
		}

		Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', 'Network quarantine started: ' . self::describe( $flag ) );
		Jobs::queue_act( $r->id(), 'fw_quarantine' );

		return true;
	}

	/**
	 * Asks for the lift (never automatic): queues the firewall job, which clears the flag when Proxmox shows the
	 * quarantine gone.
	 *
	 * @param Resource $r   Resource.
	 * @param string   $who Who asks, for the history.
	 * @return bool False when the server is not quarantined.
	 */
	public static function request_lift( Resource $r, string $who ): bool {
		$fresh = Resource::find( $r->id() );

		if ( ! $fresh || ! self::active( $fresh ) ) {
			return false;
		}

		Events::add( (int) ( $fresh->license()['id'] ?? 0 ), 'service', sprintf( 'Network quarantine lift requested by %s', $who ) );
		Jobs::queue_act( $fresh->id(), 'fw_quarantine_lift' );

		return true;
	}

	/**
	 * After the firewall shows the quarantine: the customer email, the admin mail (and the repeat mail), once.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed> The flag to store (notified).
	 */
	public static function notify( Resource $r ): array {
		$flag = (array) $r->get( self::META );

		if ( ! empty( $flag['notified'] ) ) {
			return $flag;
		}

		$text = self::describe( $flag );

		Emails::send(
			self::EMAIL,
			$r,
			array(
				'quarantine_signal' => (string) ( $flag['signal'] ?? '' ),
				'quarantine_counts' => (array) ( $flag['counts'] ?? array() ),
				'quarantine_at'     => (int) ( $flag['at'] ?? 0 ),
			)
		);
		Alerts::quarantine( $r, $text );

		if ( ! empty( $flag['repeat'] ) ) {
			Alerts::quarantine( $r, $text, true );
		}

		$flag['notified'] = true;

		return $flag;
	}

	/**
	 * The signal in one line, for the admin and the history.
	 *
	 * @param array<string, mixed> $flag Flag.
	 */
	public static function describe( array $flag ): string {
		$counts = (array) ( $flag['counts'] ?? array() );
		$names  = array(
			'scan' => 'scanning',
			'syn'  => 'SYN flood',
			'udp'  => 'UDP flood',
		);

		return sprintf(
			'%1$s (%2$s UTC): %3$d connections, %4$d destination addresses, %5$d destination ports, %6$d unanswered attempts, %7$d UDP destinations',
			$names[ $flag['signal'] ?? '' ] ?? 'unknown signal',
			gmdate( 'Y-m-d H:i', (int) ( $flag['at'] ?? 0 ) ),
			(int) ( $counts['entries'] ?? 0 ),
			(int) ( $counts['dsts'] ?? 0 ),
			(int) ( $counts['dports'] ?? 0 ),
			(int) ( $counts['unreplied'] ?? 0 ),
			(int) ( $counts['udp_dsts'] ?? 0 )
		);
	}
}
