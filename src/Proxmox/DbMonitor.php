<?php
/**
 * Monitoring alerts of a managed database, from the guest's health file.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Events;
use BeltoftServices\Alerts;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs from the power job (every 15 minutes) for a running database server: one bounded read of health.json through
 * the guest agent (DbGuest::health), then apply(). Each alert fires once per episode and re-arms when the cause is
 * gone. The state lives in meta `dbmon` (key => time sent), written under the resource lock:
 *
 *  - down: `ok` false (or the file unreadable or older than STALE) for DOWN_AFTER seconds: customer and admin;
 *  - disk: disk_percent at DISK_AT or more: customer and admin, re-arms below DISK_REARM;
 *  - dump: no nightly dump for DUMP_MAX_AGE: admin;
 *  - cert: the certificate ends within CERT_DAYS days: admin;
 *  - bans: bans at or above the setting monitor_db_bans: admin.
 *
 * A server that is restoring, building or changing is not read, so a restore never alerts. Admin mails carry no secret.
 */
final class DbMonitor {

	public const META = 'dbmon';

	/** Seconds `ok` must stay false before the alert. */
	public const DOWN_AFTER = 600;

	/** A health file older than this counts as not ok (the guest writes it every 5 minutes). */
	public const STALE = 1200;

	public const DISK_AT    = 85;
	public const DISK_REARM = 80;

	/** Seconds without a nightly dump before the admin is told. */
	public const DUMP_MAX_AGE = 36 * HOUR_IN_SECONDS;

	public const CERT_DAYS = 30;

	public const BANS_DEFAULT = 50;

	/**
	 * Settings key of the bans threshold.
	 */
	public const BANS_SETTING = 'monitor_db_bans';

	/**
	 * Is the database in a state where a reading means something?
	 *
	 * @param Resource $r Resource.
	 */
	public static function watched( Resource $r ): bool {
		return DbActions::is_db( $r )
			&& 'ready' === $r->get( 'state' )
			&& 'ready' === $r->get( 'desired' )
			&& 'ready' === $r->get( 'db_state' )
			&& ! $r->get( 'task' )
			&& '' === (string) $r->get( 'op' )
			&& ! $r->get( 'plan_dirty' )
			&& ! $r->get( 'was_running' )
			&& ! is_array( $r->get( 'restore' ) );
	}

	/**
	 * One round for one running database server: read, remember, alert.
	 *
	 * @param Resource $r      Resource.
	 * @param Client   $client Cluster client.
	 * @param string   $node   Node.
	 * @param int      $vmid   VMID.
	 * @param string   $power  running or stopped (the power job's reading).
	 * @param int|null $now    Time.
	 */
	public static function check( Resource $r, Client $client, string $node, int $vmid, string $power, ?int $now = null ): void {
		if ( 'running' !== $power || ! self::watched( $r ) ) {
			return;
		}

		self::apply( $r, DbGuest::health( $client, $node, $vmid ), $now );
	}

	/**
	 * The bans threshold.
	 */
	public static function bans_limit(): int {
		$limit = (int) Settings::get( self::BANS_SETTING );

		return $limit >= 1 ? $limit : self::BANS_DEFAULT;
	}

	/**
	 * Applies one reading (null: the file could not be read or was refused).
	 *
	 * @param Resource                  $r      Resource.
	 * @param array<string, mixed>|null $health DbGuest::health().
	 * @param int|null                  $now    Time.
	 */
	public static function apply( Resource $r, ?array $health, ?int $now = null ): void {
		$now = $now ?? time();

		$r->locked(
			static function () use ( $r, $health, $now ): bool {
				if ( ! self::watched( $r ) ) {
					return false;
				}

				$state = (array) $r->get( self::META );
				$meta  = array();
				$text  = array();

				if ( null !== $health ) {
					$meta['db_health_at'] = $now;
					$meta['db_last_dump'] = (int) ( $health['last_dump'] ?? 0 );
				}

				self::down( $r, $health, $now, $state, $text );

				if ( null !== $health ) {
					self::disk( $r, $health, $now, $state, $text );
					self::dump( $r, $health, $now, $state, $text );
					self::cert( $health, $now, $state, $text );
					self::bans( $health, $now, $state, $text );
				}

				$meta[ self::META ] = $state;

				if ( ! $r->set( $meta ) ) {
					return false;
				}

				foreach ( $text as $line ) {
					self::tell_admin( $r, $line );
				}

				return true;
			}
		);
	}

	/**
	 * Down: a reading that is not ok, for DOWN_AFTER seconds since the first one.
	 *
	 * @param Resource                  $r      Resource.
	 * @param array<string, mixed>|null $health Reading.
	 * @param int                       $now    Time.
	 * @param array<string, mixed>      $state  State (changed).
	 * @param array<int, string>        $text   Admin lines (added to).
	 */
	private static function down( Resource $r, ?array $health, int $now, array &$state, array &$text ): void {
		$bad = null === $health || true !== $health['ok'] || $now - (int) $health['updated'] > self::STALE;

		if ( ! $bad ) {
			unset( $state['down_since'], $state['down_admin'], $state['down_customer'] );

			return;
		}

		$since = (int) ( $state['down_since'] ?? 0 );

		if ( $since <= 0 ) {
			$state['down_since'] = $now;

			return;
		}

		if ( $now - $since < self::DOWN_AFTER ) {
			return;
		}

		if ( empty( $state['down_admin'] ) ) {
			$state['down_admin'] = $now;
			$text[]              = 'The database does not answer its health check (since ' . gmdate( 'Y-m-d H:i', $since ) . ' UTC).';
		}

		if ( empty( $state['down_customer'] ) && 'on' === Settings::get( 'monitor_alerts' ) && Emails::send( Monitor::EMAIL_ID, $r, array( 'reason' => 'db_down' ) ) ) {
			$state['down_customer'] = $now;
			Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', 'Monitoring email: database not answering' );
		}
	}

	/**
	 * Disk: 85 percent or more; re-arms below 80.
	 *
	 * @param Resource             $r      Resource.
	 * @param array<string, mixed> $health Reading.
	 * @param int                  $now    Time.
	 * @param array<string, mixed> $state  State (changed).
	 * @param array<int, string>   $text   Admin lines (added to).
	 */
	private static function disk( Resource $r, array $health, int $now, array &$state, array &$text ): void {
		$percent = $health['disk_percent'];

		if ( null === $percent ) {
			return;
		}

		if ( $percent < self::DISK_REARM ) {
			unset( $state['disk_admin'], $state['disk_customer'] );

			return;
		}

		if ( $percent < self::DISK_AT ) {
			return;
		}

		if ( empty( $state['disk_admin'] ) ) {
			$state['disk_admin'] = $now;
			$text[]              = sprintf( 'The data disk is %d percent full.', (int) floor( $percent ) );
		}

		if ( empty( $state['disk_customer'] ) && 'on' === Settings::get( 'monitor_alerts' ) && Monitor::usage_wanted( $r ) && Emails::send( Monitor::EMAIL_ID, $r, array( 'reason' => 'db_disk', 'disk_percent' => (int) floor( $percent ) ) ) ) {
			$state['disk_customer'] = $now;
			Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', 'Monitoring email: database disk nearly full' );
		}
	}

	/**
	 * Nightly dump: the newest of the last dump and the time the database became ready is older than 36 hours.
	 *
	 * @param Resource             $r      Resource.
	 * @param array<string, mixed> $health Reading.
	 * @param int                  $now    Time.
	 * @param array<string, mixed> $state  State (changed).
	 * @param array<int, string>   $text   Admin lines (added to).
	 */
	private static function dump( Resource $r, array $health, int $now, array &$state, array &$text ): void {
		$last = max( (int) ( $health['last_dump'] ?? 0 ), (int) $r->get( 'db_ready_at' ) );

		if ( $last <= 0 ) {
			return;
		}

		if ( $now - $last <= self::DUMP_MAX_AGE ) {
			unset( $state['dump_admin'] );

			return;
		}

		if ( empty( $state['dump_admin'] ) ) {
			$state['dump_admin'] = $now;
			$text[]              = 'No nightly dump for more than 36 hours (the last one: ' . ( (int) ( $health['last_dump'] ?? 0 ) > 0 ? gmdate( 'Y-m-d H:i', (int) $health['last_dump'] ) . ' UTC' : 'none' ) . ').';
		}
	}

	/**
	 * Certificate: ends within 30 days (or has ended).
	 *
	 * @param array<string, mixed> $health Reading.
	 * @param int                  $now    Time.
	 * @param array<string, mixed> $state  State (changed).
	 * @param array<int, string>   $text   Admin lines (added to).
	 */
	private static function cert( array $health, int $now, array &$state, array &$text ): void {
		$ends = (int) ( $health['cert_expires'] ?? 0 );

		if ( $ends <= 0 ) {
			return;
		}

		if ( $ends - $now > self::CERT_DAYS * DAY_IN_SECONDS ) {
			unset( $state['cert_admin'] );

			return;
		}

		if ( empty( $state['cert_admin'] ) ) {
			$state['cert_admin'] = $now;
			$text[]              = 'The server certificate ends ' . gmdate( 'Y-m-d', $ends ) . ' UTC (within ' . self::CERT_DAYS . ' days).';
		}
	}

	/**
	 * Bans: the guest's ban count at or above the setting.
	 *
	 * @param array<string, mixed> $health Reading.
	 * @param int                  $now    Time.
	 * @param array<string, mixed> $state  State (changed).
	 * @param array<int, string>   $text   Admin lines (added to).
	 */
	private static function bans( array $health, int $now, array &$state, array &$text ): void {
		$bans  = $health['bans'];
		$limit = self::bans_limit();

		if ( null === $bans ) {
			return;
		}

		if ( $bans < $limit ) {
			unset( $state['bans_admin'] );

			return;
		}

		if ( empty( $state['bans_admin'] ) ) {
			$state['bans_admin'] = $now;
			$text[]              = sprintf( 'Many addresses are banned for failed logins (%1$d, the limit is %2$d).', $bans, $limit );
		}
	}

	/**
	 * One mail to the alert address: what it is about and which service, never a secret.
	 *
	 * @param Resource $r    Resource.
	 * @param string   $text What happened.
	 */
	private static function tell_admin( Resource $r, string $text ): void {
		$to = (string) Settings::get( 'admin_email' );

		if ( ! is_email( $to ) ) {
			return;
		}

		$license = $r->license();
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$key     = (string) ( $license['license_key'] ?? '' );
		$lines   = array(
			sprintf( 'License: %d (%s)', (int) ( $license['id'] ?? 0 ), $key ),
			sprintf( 'Resource: %d', $r->id() ),
			sprintf( 'Driver: %s', $r->get( 'driver' ) ),
			$text,
		);

		wp_mail( $to, sprintf( '[%s] Database alert: %s', $site, Alerts::mask( $key ) ), implode( "\n", $lines ) . "\n" );
	}
}
