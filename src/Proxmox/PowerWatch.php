<?php
/**
 * What the page is told about the power of a server or a database after a power action.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Part of the status route. A shutdown or start whose job ended well is only claimed once the VM shows the wanted power:
 * for WINDOW seconds after the job the VM is read live (one call per status request) while it does not, and the
 * answer says `power_pending`; after the window it says `power_failed` and the page does not claim the result. The
 * power read is kept for the page (Operations::status keeps it), so a reload shows the same thing.
 */
final class PowerWatch {

	/** Seconds after the job that the VM is waited for. */
	public const WINDOW = 120;

	/** The power each action leads to. */
	private const WANTED = array(
		'start'    => 'running',
		'shutdown' => 'stopped',
	);

	/**
	 * Is the service a VM of ours?
	 *
	 * @param Resource $r Resource.
	 */
	private static function vm( Resource $r ): bool {
		return in_array( (string) $r->get( 'driver' ), array( ProxmoxDriver::ID, DbDriver::ID ), true );
	}

	/**
	 * Power, power_pending and power_failed for the status reply; empty for a service that is not a VM.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>
	 */
	public static function view( Resource $r ): array {
		if ( ! self::vm( $r ) ) {
			return array();
		}

		$cached = Operations::cached_status( $r->id() );
		$power  = null === $cached ? '' : (string) $cached['power'];
		$out    = array(
			'power'         => $power,
			'power_pending' => false,
			'power_failed'  => false,
		);
		$last   = $r->get( Jobs::RESULT );
		$want   = is_array( $last ) ? ( self::WANTED[ (string) ( $last['action'] ?? '' ) ] ?? '' ) : '';
		$age    = is_array( $last ) ? time() - (int) ( $last['at'] ?? 0 ) : PHP_INT_MAX;

		if ( '' === $want || empty( $last['ok'] ) || $age > self::WINDOW * 3 ) {
			return $out;
		}

		if ( $want !== $power && $age <= self::WINDOW ) {
			$live  = ( new Operations( $r ) )->status();
			$power = null === $live ? $power : (string) $live['power'];

			$out['power'] = $power;
		}

		if ( $want === $power ) {
			return $out;
		}

		$out['power_pending'] = $age <= self::WINDOW;
		$out['power_failed']  = ! $out['power_pending'];

		return $out;
	}
}
