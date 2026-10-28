<?php
/**
 * Registers and sends the customer service emails, and runs the daily usage check.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

use BeltoftLicenses\Import;
use BeltoftLicenses\Install;
use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * The emails are WooCommerce emails (woocommerce_email_classes). They are sent from jobs only:
 *
 * - ready: the resource reached ready and the customer was not told so last;
 * - paused: the resource was paused after it had been ready (never for a service that never
 *   existed);
 * - deleted: the resource was deleted after the customer was told about it (an email, or
 *   the deletion warning), unless a new service follows (a replacement on an active license);
 * - deletion warning: Retention's beltoft_services_deletion_warning;
 * - provision delay: a provision ended failed, once per resource (meta `delay_notified`), with
 *   no technical detail; the admin alert and the held IP are not touched;
 * - limit exceeded: the daily usage check (Action Scheduler, beltoft_services_usage) finds an
 *   HTTP service over its limit, once per new count.
 *
 * Meta `notified` records the last state the customer was told about. A failing email never
 * breaks the job that triggered it: every send catches Throwable and logs it.
 */
final class Emails {

	public const IDS = array( 'beltoft_services_ready', 'beltoft_services_paused', 'beltoft_services_deletion_warning', 'beltoft_services_deleted', 'beltoft_services_limit_exceeded', 'beltoft_services_provision_delay', 'beltoft_services_domain_action_needed', 'beltoft_services_monitor_alert' );

	public const USAGE_HOOK = 'beltoft_services_usage';
	public const USAGE_CRON = '0 6 * * *';
	public const BATCH      = 50;

	/**
	 * The email classes, keyed as WooCommerce keys them.
	 */
	private const CLASSES = array(
		'BeltoftServices_Ready'           => Ready::class,
		'BeltoftServices_Paused'          => Paused::class,
		'BeltoftServices_DeletionWarning' => DeletionWarning::class,
		'BeltoftServices_Deleted'         => Deleted::class,
		'BeltoftServices_LimitExceeded'   => LimitExceeded::class,
		'BeltoftServices_ProvisionDelay'  => ProvisionDelay::class,
		'BeltoftServices_DomainActionNeeded' => DomainActionNeeded::class,
		'BeltoftServices_MonitorAlert' => MonitorAlert::class,
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'add_classes' ) );
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'on_state_changed' ), 10, 3 );
		add_action( Retention::WARNING_HOOK, array( __CLASS__, 'on_deletion_warning' ), 10, 2 );
		add_action( self::USAGE_HOOK, array( __CLASS__, 'usage' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Adds the emails to WooCommerce's.
	 *
	 * @param mixed $emails Emails so far.
	 * @return mixed
	 */
	public static function add_classes( $emails ) {
		if ( ! is_array( $emails ) ) {
			return $emails;
		}

		foreach ( self::CLASSES as $key => $class ) {
			$emails[ $key ] = new $class();
		}

		return $emails;
	}

	/**
	 * One of the emails, from WooCommerce's mailer.
	 *
	 * @param string $id Email id.
	 */
	public static function get( string $id ): ?ServiceEmail {
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( $email instanceof ServiceEmail && $email->id === $id ) {
				return $email;
			}
		}

		return null;
	}

	/**
	 * Sends an email, never throwing. Nothing is sent while beltoft-licenses imports.
	 *
	 * @param string               $id   Email id.
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $data Values for the email.
	 * @return bool Whether it was sent.
	 */
	public static function send( string $id, Resource $r, array $data = array() ): bool {
		try {
			if ( Import::is_running() || $r->folded_into() > 0 ) {
				return false;
			}

			$email = self::get( $id );

			return $email ? $email->trigger( $r, $data ) : false;
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'Beltoft Services: email %s for resource %d failed: %s', $id, $r->id(), $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a failed email must not stop the job; this is its only record.
			return false;
		}
	}

	/**
	 * beltoft_services_state_changed: ready, paused and deleted emails.
	 *
	 * @param mixed  $r   Resource.
	 * @param string $old Previous state.
	 * @param string $new New state.
	 */
	public static function on_state_changed( $r, $old, $new ): void {
		try {
			if ( ! $r instanceof Resource || Import::is_running() || $r->folded_into() > 0 ) {
				return;
			}

			if ( 'failed' === $new && self::delay_applies( $r ) ) {
				self::delay( $r );
				return;
			}

			$told = (string) $r->get( 'notified' );

			if ( 'ready' === $new && 'ready' !== $told ) {
				$r->set( array( 'notified' => 'ready' ) );
				self::send( 'beltoft_services_ready', $r );
			} elseif ( 'paused' === $new && 'paused' !== $told && ( 'ready' === $told || 'ready' === $old ) ) {
				$r->set( array( 'notified' => 'paused' ) );
				self::send( 'beltoft_services_paused', $r );
			} elseif ( 'deleted' === $new && ( '' !== $told || null !== $r->get( 'warned_at' ) ) && ! self::replaced( $r ) ) {
				// The resource is closed by now; its row stays as history.
				$r->set( array( 'notified' => 'deleted' ) );
				self::send( 'beltoft_services_deleted', $r );
			}
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'Beltoft Services: service email failed: %s', $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a failed email must not stop the job; this is its only record.
		}
	}

	/**
	 * Did setting the service up fail so that the customer is told of a delay? Only a server,
	 * domain or DNS hosting (driver kind vm, domain or dns) whose provision ended failed; a
	 * failed pause or deletion is not the customer's business.
	 *
	 * @param Resource $r Resource.
	 */
	public static function delay_applies( Resource $r ): bool {
		if ( ! $r->is_valid() || 'failed' !== $r->get( 'state' ) || 'provision' !== $r->get( 'failed_op' ) ) {
			return false;
		}

		$driver = Registry::get( $r->get( 'driver' ) );

		return $driver && in_array( $driver->kind(), array( 'vm', 'domain', 'dns' ), true );
	}

	/**
	 * Tells the customer of the delay once. The record is stored first, so a send that fails or
	 * is repeated by a retry never mails twice; an admin's retry and a later failure stay quiet.
	 *
	 * @param Resource $r Resource.
	 */
	private static function delay( Resource $r ): void {
		if ( $r->get( 'delay_notified' ) ) {
			return;
		}

		$stored = $r->locked(
			static function () use ( $r ): bool {
				return ! $r->get( 'delay_notified' ) && $r->set( array( 'delay_notified' => time() ) );
			}
		);

		if ( true === $stored ) {
			self::send( 'beltoft_services_provision_delay', $r );
		}
	}

	/**
	 * Does a new service follow this deletion (asked for, on an active license)?
	 *
	 * @param Resource $r Resource.
	 */
	private static function replaced( Resource $r ): bool {
		if ( ! $r->get( 'replace_after_delete' ) ) {
			return false;
		}

		$license = $r->license();

		return $license && Licenses::STATUS_ACTIVE === Licenses::effective_status( $license );
	}

	/**
	 * beltoft_services_deletion_warning: the deletion warning.
	 *
	 * @param mixed $r          Resource.
	 * @param mixed $deletes_at When the service will be deleted.
	 */
	public static function on_deletion_warning( $r, $deletes_at ): void {
		if ( $r instanceof Resource ) {
			self::send( 'beltoft_services_deletion_warning', $r, array( 'deletes_at' => (int) $deletes_at ) );
		}
	}

	/**
	 * Schedules the daily usage check, once.
	 */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::USAGE_HOOK, array(), Jobs::GROUP ) ) {
			as_schedule_cron_action( time(), self::USAGE_CRON, self::USAGE_HOOK, array(), Jobs::GROUP );
		}
	}

	/**
	 * Removes the daily usage check (plugin deactivation).
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::USAGE_HOOK, array(), Jobs::GROUP );
		}
	}

	/**
	 * The daily usage check: asks every ready HTTP service for its usage (HttpDriver::status(),
	 * which makes no request without a usage endpoint) and emails the customer once per new
	 * count over the limit. A count back within the limit is forgotten, so a later overage is
	 * sent again. Runs only as an Action Scheduler job, never during a page render, and does
	 * nothing while "Pause all driver activity" is on.
	 */
	public static function usage(): void {
		// The kill switch stops every request to services; the schedule stays.
		if ( \BeltoftServices\Settings::get( 'pause_all' ) ) {
			return;
		}

		$driver = Registry::get( 'http' );

		if ( ! $driver ) {
			return;
		}

		$last = 0;

		do {
			$ids  = self::batch( $driver->kind(), $last );
			$full = self::BATCH === count( $ids );

			foreach ( $ids as $id ) {
				$last = $id;
				$r    = Resource::find( $id );

				if ( ! $r || ! $r->is_valid() || ! $r->is_live() || 'http' !== $r->get( 'driver' ) || 'ready' !== $r->get( 'state' ) ) {
					continue;
				}

				try {
					$usage = $driver->status( $r );
				} catch ( \Throwable $e ) {
					$usage = null;
				}

				if ( is_array( $usage ) && isset( $usage['used'], $usage['limit'] ) ) {
					self::check_limit( $r, (int) $usage['used'], (int) $usage['limit'] );
				}
			}
		} while ( $full );
	}

	/**
	 * Emails the customer when a count over the limit is new (meta `limit_notified`), stored
	 * under the resource lock first. When the lock is busy, the next daily run decides.
	 *
	 * @param Resource $r     Resource.
	 * @param int      $used  Used.
	 * @param int      $limit Allowed.
	 */
	private static function check_limit( Resource $r, int $used, int $limit ): void {
		$over = $limit > 0 && $used > $limit;

		$new = $r->locked(
			static function () use ( $r, $used, $over ): bool {
				$told = $r->get( 'limit_notified' );

				if ( ! $over ) {
					if ( null !== $told && '' !== $told ) {
						$r->set( array( 'limit_notified' => '' ) );
					}
					return false;
				}

				if ( is_numeric( $told ) && (int) $told === $used ) {
					return false;
				}

				return $r->set( array( 'limit_notified' => $used ) );
			}
		);

		if ( true === $new ) {
			self::send(
				'beltoft_services_limit_exceeded',
				$r,
				array(
					'used'  => $used,
					'limit' => $limit,
				)
			);
		}
	}

	/**
	 * The next live resources of a kind after an id.
	 *
	 * @param string $kind  Activation kind.
	 * @param int    $after Last id seen.
	 * @return int[]
	 */
	private static function batch( string $kind, int $after ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE kind = %s AND deactivated_at IS NULL AND id > %d ORDER BY id ASC LIMIT %d', Install::tables()['activations'], $kind, $after, self::BATCH ) );

		return array_map( 'intval', (array) $rows );
	}
}
