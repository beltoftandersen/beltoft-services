<?php
/**
 * Shared by the four groups of hosting account actions.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Hosting\Config;
use BeltoftServices\Hosting\EdgeMap;
use BeltoftServices\Hosting\Plan;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\Runs;
use BeltoftServices\Hosting\SiteDns;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The parts every group of hosting account actions shares: the site lock, the agent job, the readiness check, the
 * edge map, error words and the plan ceilings. Each group class uses this trait and
 * is built from the same Resource by the HostingActions facade.
 */
trait Base {

	use Runs;

	/**
	 * The plan, typed.
	 *
	 * @var array<string, int|string|bool>
	 */
	private $plan = array();

	/**
	 * @param Resource $r Hosting resource.
	 */
	public function __construct( Resource $r ) {
		$this->r    = $r;
		$this->plan = Plan::typed( (array) $r->get( 'plan' ) );
	}

	/**
	 * Runs a change of the account's websites while holding the account's site lock, so two requests (a double click,
	 * two tabs, the sweep) never change the same account at once.
	 *
	 * @param callable $change The change.
	 * @return mixed|\WP_Error The change's result, or busy.
	 */
	private function locked( callable $change ) {
		list( $taken, $result ) = \BeltoftServices\Locks::with( \BeltoftServices\Locks::name( 'hosting-site', $this->r->id() ), (int) apply_filters( 'beltoft_services_hosting_lock_wait', 10 ), $change );

		return $taken ? $result : $this->error( 'busy', __( 'Your hosting is being changed. Try again in a moment.', 'beltoft-services' ) );
	}

	/**
	 * One agent job as a result or a customer error.
	 *
	 * @param string               $name Job name.
	 * @param string               $op   Operation.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function call( string $name, string $op, array $args ) {
		$done = $this->agent_call( $name, $op, $args );

		// A live agent answers queued first and finishes in a second or two: look again (same job id) for a few seconds before asking the customer to retry.
		/**
		 * Seconds a customer action waits for the agent's job.
		 *
		 * @param int $seconds Seconds.
		 */
		$until = microtime( true ) + (int) apply_filters( 'beltoft_services_hosting_action_wait', 8 );

		while ( $done instanceof StepResult && $done->is_poll() && microtime( true ) < $until ) {
			usleep( 400000 );
			$done = $this->agent_call( $name, $op, $args );
		}

		if ( ! $done instanceof StepResult ) {
			return $done;
		}

		return $done->is_poll()
			? $this->error( 'busy', __( 'The server is busy. Try again in a moment.', 'beltoft-services' ) )
			: $this->error( 'failed', __( 'That did not work. Try again in a moment, or contact support if it keeps happening.', 'beltoft-services' ) );
	}

	/**
	 * Is the account ready for a change? Connects to the node on the first call.
	 *
	 * @return true|\WP_Error
	 */
	private function ready() {
		$record = Records::for_resource( $this->r->id() );

		if ( 'ready' !== $this->r->get( 'state' ) || 'ready' !== $this->r->get( 'desired' ) || ! in_array( 'ready', (array) $this->r->get( 'steps' ), true ) || ! $record ) {
			return $this->error( 'not_ready', __( 'Your hosting is not ready for changes.', 'beltoft-services' ) );
		}

		if ( null === $this->client ) {
			$err = $this->connect();

			if ( null !== $err ) {
				return $this->error( 'failed', __( 'Your hosting cannot be changed right now.', 'beltoft-services' ) );
			}
		}

		return true;
	}

	/**
	 * A job on one existing site.
	 *
	 * @param string                    $slug Site.
	 * @param string                    $op   Operation.
	 * @param string                    $name Job name.
	 * @param array<string, mixed>|null $out  What to return, or null for the agent's result.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function site_job( string $slug, string $op, string $name, ?array $out ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( null === Sites::find( $this->r->id(), $slug ) ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$done   = $this->call( 'site_' . $name . '_' . $slug, $op, array( 'vmid' => (int) $record['vmid'], 'slug' => $slug ) );

		return is_wp_error( $done ) ? $done : ( null === $out ? $done : $out );
	}

	/**
	 * The edge map of the account, sent again.
	 *
	 * @return true|\WP_Error
	 */
	private function edge() {
		$done = $this->call( 'edge_update', 'edge.apply', array( 'account' => Config::account( $this->r->id() ), 'routes' => EdgeMap::routes( $this->r->id(), false ) ) );

		return is_wp_error( $done ) ? $done : true;
	}

	/**
	 * A customer error.
	 *
	 * @param string $code    Short code.
	 * @param string $message Words for the customer.
	 */
	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}

	/**
	 * Customer words for a plain refusal of SiteDns (the reasons are already in plain English; they are kept short).
	 *
	 * @param string $reason Reason.
	 */
	private function customer_text( string $reason ): string {
		return mb_substr( sanitize_text_field( $reason ), 0, 200 );
	}

	/**
	 * The ceilings of the plan, for the forms.
	 *
	 * @return array<string, mixed>
	 */
	public function limits(): array {
		$sites = Sites::for_resource( $this->r->id() );
		$used  = 0;

		foreach ( $sites as $s ) {
			$used += (int) $s['workers'];
		}

		return array(
			'sites'         => (int) $this->plan['sites'],
			'sites_used'    => count( $sites ),
			'php_workers'   => (int) $this->plan['php_workers'],
			'workers_used'  => $used,
			'php_memory_mb' => (int) $this->plan['php_memory_mb'],
			'upload_mb'     => (int) $this->plan['upload_mb'],
			'php_versions'  => Config::php_versions(),
			'staging'       => (string) $this->plan['staging'],
			'staging_used'  => count( \BeltoftServices\Hosting\SiteStaging::copies( $this->r->id() ) ),
		);
	}

	/**
	 * Workers given to the sites now.
	 */
	private function workers_used(): int {
		$n = 0;

		foreach ( Sites::for_resource( $this->r->id() ) as $s ) {
			$n += (int) $s['workers'];
		}

		return $n;
	}

	/**
	 * Takes workers from the sites that have the most (down to 2 each) until the deficit is covered.
	 *
	 * @param int    $deficit Workers over the ceiling.
	 * @param string $except  The new site, which is left alone.
	 * @param int    $vmid    Container id.
	 * @return true|\WP_Error
	 */
	private function make_room( int $deficit, string $except, int $vmid ) {
		while ( $deficit > 0 ) {
			$donor = null;

			foreach ( Sites::for_resource( $this->r->id() ) as $s ) {
				if ( $s['slug'] !== $except && $s['workers'] > 2 && ( null === $donor || $s['workers'] > $donor['workers'] ) ) {
					$donor = $s;
				}
			}

			if ( null === $donor ) {
				return true; // Every site is at its minimum: the ceiling is a maximum, the minimum of 2 wins.
			}

			$new  = max( 2, $donor['workers'] - $deficit );
			$done = $this->call( 'site_workers_' . $donor['slug'], 'site.workers', array( 'vmid' => $vmid, 'slug' => $donor['slug'], 'workers' => $new ) );

			if ( is_wp_error( $done ) ) {
				return $done;
			}

			Sites::update( $this->r->id(), $donor['slug'], array( 'workers' => $new ) );
			$deficit -= $donor['workers'] - $new;
		}

		return true;
	}
}
