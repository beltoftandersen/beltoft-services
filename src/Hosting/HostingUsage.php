<?php
/**
 * Usage of the hosting accounts: container stats, database size, storage limits, PHP end of support.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One Action Scheduler job, hourly (plan 15.2, 7.1, 5.2). For every ready hosting account:
 *
 * - container stats from the node (cpu, memory, disk) and the database sizes from the agent (`db.size`), kept in the
 *   resource meta `usage` with the time;
 * - the storage rule (plan 7.1): files plus databases count toward the plan storage. At 90 percent one warning email;
 *   over 110 percent a second email that names the 7 days; over 110 percent for 7 days the database user loses its
 *   write grants (the agent's `db.readonly`, the sites still show) until usage is back at 100 percent, when the
 *   grants return and the customer is told;
 * - the agent's loader states (`agent.health`), shown in the account when a site's loader file is refused;
 * - PHP end of support (plan 5.2): emails 90 and 30 days before the end date of a version a site runs, and at the end
 *   date the site goes to the default version, with a 7 day rollback marker. (The safety snapshot before that switch
 *   comes with the backups; until then the marker only records the old version.)
 *
 * Every step keeps the last known value when a call fails, never throws and sends each email once.
 */
final class HostingUsage {

	use Runs;

	public const HOOK = 'beltoft_services_hosting_usage';
	public const CRON = '7 * * * *';

	public const WARN_PERCENT    = 90;
	public const OVER_PERCENT    = 110;
	public const RESTORE_PERCENT = 100;
	public const GRACE_DAYS      = 7;

	/**
	 * Hooks the job and its hourly schedule.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the hourly run once.
	 */
	public static function schedule(): void {
		Jobs::ensure_cron( self::HOOK, self::CRON );
	}

	/**
	 * The job: every live hosting account.
	 */
	public static function run(): void {
		if ( Settings::get( 'pause_all' ) ) {
			return;
		}

		foreach ( Resource::live_ids( HostingDriver::KIND ) as $id ) {
			$r = Resource::find( (int) $id );

			if ( $r && $r->accepts_actions() && HostingDriver::ID === $r->get( 'driver' ) ) {
				( new self( $r ) )->sample();
			}
		}
	}

	/**
	 * @param Resource $r Hosting resource.
	 */
	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * One account: all the steps, each on its own.
	 */
	public function sample(): void {
		if ( null === Records::for_resource( $this->r->id() ) || null !== $this->connect() ) {
			return;
		}

		foreach ( array( 'stats', 'databases', 'storage', 'loaders', 'php' ) as $step ) {
			try {
				$this->{'step_' . $step}();
			} catch ( \Throwable $e ) {
				unset( $e ); // The last known value stays until the next run.
			}

			$this->r = Resource::find( $this->r->id() ) ?? $this->r;
		}
	}

	/**
	 * The usage meta, merged.
	 *
	 * @param array<string, mixed> $values Values.
	 */
	private function remember( array $values ): bool {
		$usage = array_merge( (array) $this->r->get( 'usage' ), $values, array( 'at' => Jobs::now() ) );

		return $this->r->set( array( 'usage' => $usage ) );
	}

	/**
	 * Container stats: GET /nodes/{node}/lxc/{vmid}/status/current.
	 */
	private function step_stats(): void {
		$record = (array) Records::for_resource( $this->r->id() );
		$v      = $this->verified( $record, 'usage' );

		if ( $v instanceof StepResult ) {
			return;
		}

		$now = $this->client->request( 'GET', $this->lxc( $v['node'] ) . '/status/current' );

		if ( ! is_array( $now ) ) {
			return;
		}

		$this->remember(
			array(
				'status'    => (string) ( $now['status'] ?? '' ),
				'cpu'       => round( (float) ( $now['cpu'] ?? 0 ), 4 ),
				'mem_mb'    => (int) round( (int) ( $now['mem'] ?? 0 ) / 1048576 ),
				'disk_mb'   => (int) round( (int) ( $now['disk'] ?? 0 ) / 1048576 ),
			)
		);
	}

	/**
	 * Database sizes: the agent's db.size, in bytes per database.
	 */
	private function step_databases(): void {
		$result = $this->agent_call( 'usage_dbsize', 'db.size', array( 'account' => Config::account( $this->r->id() ) ) );

		if ( $result instanceof StepResult || ! is_array( $result['sizes'] ?? null ) ) {
			return;
		}

		$sizes = array();

		foreach ( $result['sizes'] as $name => $bytes ) {
			$sizes[ (string) $name ] = max( 0, (int) $bytes );
		}

		$this->remember(
			array(
				'db_bytes' => $sizes,
				'db_mb'    => (int) round( array_sum( $sizes ) / 1048576 ),
			)
		);
	}

	/**
	 * Storage used as a share of the plan: files plus databases.
	 *
	 * @param Resource $r Hosting resource.
	 * @return float|null Percent, or null when there is no reading yet.
	 */
	public static function storage_percent( Resource $r ): ?float {
		$usage = (array) $r->get( 'usage' );
		$plan  = Plan::typed( (array) $r->get( 'plan' ) );

		if ( ! isset( $usage['disk_mb'], $usage['db_mb'] ) || (int) $plan['disk_gb'] <= 0 ) {
			return null;
		}

		return ( (int) $usage['disk_mb'] + (int) $usage['db_mb'] ) / ( (int) $plan['disk_gb'] * 1024 ) * 100;
	}

	/**
	 * The storage rule of plan 7.1.
	 */
	private function step_storage(): void {
		$percent = self::storage_percent( $this->r );

		if ( null === $percent ) {
			return;
		}

		$state = array_merge( array( 'warned' => 0, 'over_since' => 0, 'over_told' => 0, 'restricted' => 0 ), (array) $this->r->get( 'storage' ) );
		$now   = Jobs::now();
		$plan  = Plan::typed( (array) $this->r->get( 'plan' ) );
		$gb    = (int) round( ( ( (int) ( (array) $this->r->get( 'usage' ) )['disk_mb'] ) + (int) ( (array) $this->r->get( 'usage' ) )['db_mb'] ) / 1024 );
		$data  = array( 'percent' => (int) round( $percent ), 'used' => $gb, 'limit' => (int) $plan['disk_gb'] );

		if ( $percent >= self::WARN_PERCENT ) {
			if ( ! $state['warned'] && Emails::send( 'beltoft_services_hosting_notice', $this->r, array_merge( $data, array( 'hosting_notice' => 'storage_warning' ) ) ) ) {
				$state['warned'] = $now;
			}
		} elseif ( $percent < self::WARN_PERCENT - 5 ) {
			$state['warned'] = 0; // Back below: a later climb warns again.
		}

		if ( $percent > self::OVER_PERCENT ) {
			if ( ! $state['over_since'] ) {
				$state['over_since'] = $now;
			}

			if ( ! $state['over_told'] && Emails::send( 'beltoft_services_hosting_notice', $this->r, array_merge( $data, array( 'hosting_notice' => 'storage_over', 'days' => self::GRACE_DAYS ) ) ) ) {
				$state['over_told'] = $now;
			}

			if ( ! $state['restricted'] && $now - (int) $state['over_since'] >= self::GRACE_DAYS * DAY_IN_SECONDS && $state['over_told'] ) {
				$done = $this->agent_call( 'usage_readonly_on', 'db.readonly', array( 'account' => Config::account( $this->r->id() ), 'enabled' => true ) );

				if ( ! $done instanceof StepResult ) {
					$state['restricted'] = $now;
					Emails::send( 'beltoft_services_hosting_notice', $this->r, array_merge( $data, array( 'hosting_notice' => 'storage_restricted' ) ) );
				}
			}
		} elseif ( $percent <= self::RESTORE_PERCENT ) {
			if ( $state['restricted'] ) {
				$done = $this->agent_call( 'usage_readonly_off', 'db.readonly', array( 'account' => Config::account( $this->r->id() ), 'enabled' => false ) );

				if ( ! $done instanceof StepResult ) {
					$state['restricted'] = 0;
					Emails::send( 'beltoft_services_hosting_notice', $this->r, array_merge( $data, array( 'hosting_notice' => 'storage_restored' ) ) );
				}
			}

			if ( ! $state['restricted'] ) {
				$state['over_since'] = 0;
				$state['over_told']  = 0;
			}
		} elseif ( ! $state['restricted'] ) {
			// Between 100 and 110: the clock does not run and the 7 days start over on the next climb.
			$state['over_since'] = 0;
			$state['over_told']  = 0;
		}

		$this->r->set( array( 'storage' => $state ) );
	}

	/**
	 * The agent's loader states (agent.health): kept for the account page.
	 */
	private function step_loaders(): void {
		$result = $this->agent_call( 'usage_health', 'agent.health', array() );

		if ( $result instanceof StepResult ) {
			return;
		}

		$loaders = array();

		foreach ( (array) ( $result['loaders'] ?? array() ) as $key => $loader ) {
			if ( is_array( $loader ) && isset( $loader['state'] ) && 1 === preg_match( '#^\d+/[a-z0-9-]{1,32}$#D', (string) $key ) ) {
				$loaders[ (string) $key ] = array(
					'state' => mb_substr( sanitize_text_field( (string) $loader['state'] ), 0, 60 ),
					'ts'    => (int) ( $loader['ts'] ?? 0 ),
				);
			}
		}

		$meta = array( 'loaders' => $loaders );
		$vmid = (int) ( Records::for_resource( $this->r->id() )['vmid'] ?? 0 );
		$slot = ( (array) ( $result['backup_slots'] ?? array() ) )[ (string) $vmid ] ?? null;

		// The nightly slot the agent chose ("HH:MM", UTC), kept for HostingBackups::slot_minute().
		if ( is_array( $slot ) && 1 === preg_match( '/^(\d{2}):(\d{2})$/D', (string) ( $slot['at'] ?? '' ), $m ) ) {
			$meta['backup_slot'] = (int) $m[1] * 60 + (int) $m[2];
		}

		$this->r->set( $meta );
	}

	/**
	 * The loader problems of an account in words, for the account page: a refused or failed loader file stops the
	 * sign-in link and the website mail of that site.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<string, string> Site slug => state.
	 */
	public static function loader_problems( Resource $r ): array {
		$out  = array();
		$vmid = (int) ( Records::for_resource( $r->id() )['vmid'] ?? 0 );

		foreach ( (array) $r->get( 'loaders' ) as $key => $loader ) {
			$state = (string) ( $loader['state'] ?? '' );

			if ( 1 === preg_match( '#^' . $vmid . '/([a-z0-9-]+)$#D', (string) $key, $m ) && ( 'error' === $state || 0 === strpos( $state, 'refused' ) ) ) {
				$out[ $m[1] ] = $state;
			}
		}

		return $out;
	}

	/**
	 * PHP end of support: notices 90 and 30 days before, and the switch at the end date.
	 */
	private function step_php(): void {
		$now      = Jobs::now();
		$notified = (array) $this->r->get( 'php_notified' );
		$default  = Config::get( 'php_default' );
		$record   = (array) Records::for_resource( $this->r->id() );

		foreach ( Sites::for_resource( $this->r->id() ) as $site ) {
			$end = Config::php_end( (string) $site['php'] );

			if ( null === $end || (string) $site['php'] === $default ) {
				continue;
			}

			$days = (int) ceil( ( $end - $now ) / DAY_IN_SECONDS );
			$key  = $site['php'] . ':' . $site['slug'];

			foreach ( array( 90, 30 ) as $mark ) {
				if ( $days <= $mark && $days > 0 && empty( $notified[ $key ][ $mark ] ) ) {
					if ( Emails::send( 'beltoft_services_hosting_notice', $this->r, array( 'hosting_notice' => 'php_ending', 'days' => $days, 'php' => $site['php'], 'site' => $site['slug'], 'php_default' => $default, 'php_end' => wp_date( 'j F Y', $end ) ) ) ) {
						$notified[ $key ][ $mark ] = $now;
					}
				}
			}

			if ( $days <= 0 && empty( $notified[ $key ]['switched'] ) ) {
				// A safety point first (a snapshot and a fresh dump, or the customer's own of the last 24 hours). Without
				// one the site is not switched: the next run tries again, and the admin is told when it cannot be made.
				$safe = ( new HostingRestore( $this->r ) )->make_safety( 'php' );

				if ( $safe instanceof StepResult ) {
					if ( $safe->is_failed() ) {
						\BeltoftServices\Alerts::admin( $this->r, 'The PHP version of ' . $site['slug'] . ' was not switched: no safety point could be made (' . $this->scrub( $safe->error(), 160 ) . ')' );
					}

					continue;
				}

				$done = $this->agent_call( 'usage_php_' . $site['slug'], 'site.php', array( 'vmid' => (int) $record['vmid'], 'slug' => $site['slug'], 'php' => $default ) );

				if ( ! $done instanceof StepResult ) {
					Sites::update( $this->r->id(), $site['slug'], array( 'php' => $default ) );
					$notified[ $key ]['switched'] = $now;
					$rollback                     = (array) $this->r->get( 'php_rollback' );
					$rollback[ $site['slug'] ]    = array( 'from' => $site['php'], 'until' => $now + self::GRACE_DAYS * DAY_IN_SECONDS );
					$this->r->set( array( 'php_rollback' => $rollback ) );
					Emails::send( 'beltoft_services_hosting_notice', $this->r, array( 'hosting_notice' => 'php_switched', 'php' => $site['php'], 'site' => $site['slug'], 'php_default' => $default, 'days' => self::GRACE_DAYS ) );
				}
			}
		}

		$this->r->set( array( 'php_notified' => $notified ) );

		// A rollback marker that ran out is forgotten.
		$rollback = array_filter( (array) $this->r->get( 'php_rollback' ), static fn( $m ) => is_array( $m ) && (int) ( $m['until'] ?? 0 ) > $now );

		if ( count( $rollback ) !== count( (array) $this->r->get( 'php_rollback' ) ) ) {
			$this->r->set( array( 'php_rollback' => $rollback ) );
		}
	}
}
