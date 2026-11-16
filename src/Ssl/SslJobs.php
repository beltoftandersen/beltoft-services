<?php
/** The daily certificate jobs. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Once a day every built certificate is read at the registrar (Operations::refresh), so a certificate the authority reissued
 * by itself is found and delivered, a new expiry is kept and a certificate about to end without a replacement is reported.
 * The same job wipes private keys whose days have passed.
 */
final class SslJobs {
	public const HOOK = 'beltoft_services_ssl_refresh_all';
	public const CRON = '35 5 * * *';

	/** Resources queued per run, so one run stays short. */
	public const LIMIT = 200;

	/** Hooks. */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		Jobs::recurring( array( __CLASS__, 'schedule' ) );
	}

	/** Schedules the daily job, once. */
	public static function schedule(): void {
		Jobs::ensure_cron( self::HOOK, self::CRON, array(), HOUR_IN_SECONDS );
	}

	/** Removes the daily job (plugin deactivation). */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), Jobs::GROUP );
		}
	}

	/** Where the last run stopped, so the next one goes on from there. */
	public const CURSOR_OPTION = 'beltoft_services_ssl_refresh_cursor';

	/**
	 * Queues a refresh for each built certificate and wipes old keys. A run queues at most LIMIT of them and the next run goes
	 * on after the last one, so with more certificates than that every one is still read.
	 *
	 * @return int Refreshes queued.
	 */
	public static function run(): int {
		Keys::sweep();

		$queued = 0;
		$limit  = max( 1, (int) apply_filters( 'beltoft_services_ssl_refresh_limit', self::LIMIT ) );
		$after  = (int) get_option( self::CURSOR_OPTION, 0 );
		$last   = 0;

		foreach ( Resource::live_ids( SslDriver::KIND, $after ) as $id ) {
			$r = Resource::find( $id );

			if ( $r && SslDriver::ID === $r->get( 'driver' ) && Operations::built( $r ) ) {
				Jobs::queue_act( $r->id(), 'refresh' );

				if ( ++$queued >= $limit ) {
					$last = (int) $id;
					break;
				}
			}
		}

		// Stopped at the limit: the next run starts after this one. Reached the end: it starts from the first again.
		update_option( self::CURSOR_OPTION, $last, false );

		return $queued;
	}
}
