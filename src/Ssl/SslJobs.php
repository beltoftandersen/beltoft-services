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
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
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

	/**
	 * Queues a refresh for each built certificate and wipes old keys.
	 *
	 * @return int Refreshes queued.
	 */
	public static function run(): int {
		Keys::sweep();

		$queued = 0;

		foreach ( Resource::live_ids( SslDriver::KIND ) as $id ) {
			$r = Resource::find( $id );

			if ( $r && SslDriver::ID === $r->get( 'driver' ) && Operations::built( $r ) ) {
				Jobs::queue_act( $r->id(), 'refresh' );

				if ( ++$queued >= self::LIMIT ) {
					break;
				}
			}
		}

		return $queued;
	}
}
