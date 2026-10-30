<?php
/**
 * One job for the database guest: queue it, follow its status, take the login it produces.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Crypto;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Used by the build (init) and by the customer's "New password" (reset_password). The password is stored
 * encrypted for a one-time reveal and never logged; the guest's file is emptied once it is stored.
 */
final class DbJob {

	/** Meta keys. */
	public const REVEAL = 'db_reveal';
	public const UNTIL  = 'db_reveal_until';

	/**
	 * Is $request the job whose login is stored and whose file is emptied.
	 */
	private static function stored( Resource $r, string $request ): bool {
		return $request === (string) $r->get( 'db_cred_request' );
	}

	/**
	 * Sends a job.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return true|\WP_Error
	 */
	public static function send( Client $client, array $record, array $job ) {
		return DbGuest::write( $client, (string) $record['node'], (int) $record['vmid'], DbGuest::JOB, $job );
	}

	/**
	 * Where the job stands: none (no status of ours yet), working, failed, credentials (ready, the login is not
	 * stored yet) or ready.
	 *
	 * @return array{state:string,step:string}
	 */
	public static function check( Resource $r, Client $client, array $record, string $request, bool $wants_login ): array {
		$node   = (string) $record['node'];
		$vmid   = (int) $record['vmid'];
		$status = DbGuest::status( $client, $node, $vmid, $request );

		if ( null === $status ) {
			return array( 'state' => 'none', 'step' => '' );
		}

		if ( 'ready' !== $status['state'] || ! $wants_login ) {
			return $status;
		}

		if ( ! self::stored( $r, $request ) ) {
			$login = DbGuest::credentials( $client, $node, $vmid, $request );

			if ( null === $login || ! Crypto::available() ) {
				return array( 'state' => 'credentials', 'step' => $status['step'] );
			}

			$saved = $r->set( array(
				self::REVEAL        => Crypto::encrypt( $login['password'] ),
				self::UNTIL         => time() + Access::REVEAL_DAYS * DAY_IN_SECONDS,
				'db_ca_pem'         => $login['ca_pem'],
				'db_fingerprint'    => $login['fingerprint'],
				'db_cred_request'   => $request,
				'db_cred_wiped'     => '',
			) );

			if ( ! $saved ) {
				return array( 'state' => 'credentials', 'step' => $status['step'] );
			}
		}

		if ( $request !== (string) $r->get( 'db_cred_wiped' ) ) {
			// The login is in the resource: the guest's copy is emptied. A failed write is tried again.
			if ( is_wp_error( DbGuest::write( $client, $node, $vmid, DbGuest::CREDENTIALS, array() ) ) || ! $r->set( array( 'db_cred_wiped' => $request ) ) ) {
				return array( 'state' => 'credentials', 'step' => $status['step'] );
			}
		}

		return $status;
	}
}
