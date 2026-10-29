<?php
/**
 * The VM panels: what the customer and the admin see.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Alerts;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders ProxmoxDriver::render_account() and render_admin(). Neither makes a Proxmox call: the
 * power state is the one kept by the last status refresh, everything else is resource meta and
 * this plugin's tables. The customer panel never holds the password.
 */
final class Panels {

	/**
	 * Script handle of the panel's VM script.
	 */
	public const HANDLE = 'beltoft-services-vm';

	/**
	 * Hooks the script.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Enqueues the VM script after the generic panel script.
	 *
	 * @param mixed $parent Handle of the generic panel script.
	 */
	public static function assets( $parent ): void {
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/vm.js', BELTOFT_SERVICES_FILE ), array( is_string( $parent ) ? $parent : '' ), \BeltoftServices\Assets::version( 'assets/vm.js' ), true );
		wp_enqueue_script( 'beltoft-services-metrics', plugins_url( 'assets/metrics.js', BELTOFT_SERVICES_FILE ), array( is_string( $parent ) ? $parent : '' ), (string) filemtime( BELTOFT_SERVICES_PATH . 'assets/metrics.js' ), true );
		wp_enqueue_style( 'beltoft-services-metrics', plugins_url( 'assets/metrics.css', BELTOFT_SERVICES_FILE ), array(), (string) filemtime( BELTOFT_SERVICES_PATH . 'assets/metrics.css' ) );
		wp_localize_script( 'beltoft-services-metrics', 'beltoftServicesMetrics', array( 'strings' => array(
			'loading' => __( 'Loading usage…', 'beltoft-services' ),
			'empty' => __( 'No data yet.', 'beltoft-services' ),
			'unavailable' => __( 'Usage is unavailable. Please try again later.', 'beltoft-services' ),
			'cpu' => __( 'CPU', 'beltoft-services' ),
			'memory' => __( 'Used incl. cache', 'beltoft-services' ),
			'allocated' => __( 'Allocated', 'beltoft-services' ),
			'in' => __( 'In', 'beltoft-services' ),
			'out' => __( 'Out', 'beltoft-services' ),
			'read' => __( 'Read', 'beltoft-services' ),
			'write' => __( 'Write', 'beltoft-services' ),
			'graph' => __( 'usage graph', 'beltoft-services' ),
			'noReading' => __( 'No reading', 'beltoft-services' ),
		) ) );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesVm',
			array(
				'strings' => array(
					'queued'    => __( 'Your request is on its way. This can take a minute.', 'beltoft-services' ),
					'failed'    => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'expired'   => __( 'Your session has expired. Please reload the page.', 'beltoft-services' ),
					'tooMany'   => __( 'Too many requests. Please wait a while and try again.', 'beltoft-services' ),
					'working'   => __( 'Working…', 'beltoft-services' ),
					'gone'      => __( 'The password has already been shown or is no longer available.', 'beltoft-services' ),
					'snapshotDelete' => __( 'Deleting a snapshot cannot be undone.', 'beltoft-services' ),
					'deleteTitle'    => __( 'Delete this snapshot?', 'beltoft-services' ),
					'deleteYes'      => __( 'Delete the snapshot', 'beltoft-services' ),
					/* translators: %s: name of the snapshot */
					'deleteStart'    => __( 'Deleting snapshot %s.', 'beltoft-services' ),
					/* translators: %s: name of the snapshot */
					'deleteDone'     => __( 'Snapshot %s is deleted.', 'beltoft-services' ),
					/* translators: %s: name of the snapshot */
					'rollbackStart'  => __( 'Going back to snapshot %s. The server is off for a moment.', 'beltoft-services' ),
					/* translators: %s: name of the snapshot */
					'rollbackDone'   => __( 'Back on snapshot %s.', 'beltoft-services' ),
					'restoreStart'   => __( 'Restoring your server from the backup. The server is off while it is restored.', 'beltoft-services' ),
					'restoreDone'    => __( 'Your server is restored from the backup.', 'beltoft-services' ),
					'reinstallStart' => __( 'Reinstalling your server. The old system is erased first.', 'beltoft-services' ),
					'reinstallDone'  => __( 'Your server is reinstalled.', 'beltoft-services' ),
					'rollbackDoneAnon' => __( 'Your server went back to the snapshot.', 'beltoft-services' ),
					'deleteDoneAnon' => __( 'The snapshot is deleted.', 'beltoft-services' ),
					'busy'           => __( 'Busy', 'beltoft-services' ),
					'running'        => __( 'The server is running.', 'beltoft-services' ),
					'newPassword'    => __( 'Use "Show password once" for your new password.', 'beltoft-services' ),
				),
			)
		);
	}

	/**
	 * The boot disk size to show, in GB: the larger of the size recorded after the last build or
	 * resize and the plan's. A disk is never shrunk and a plan never asks for less than the VM
	 * has (servers cannot be downgraded), so the larger one is the disk the VM has now, and a
	 * stale record can never hide an upgrade.
	 *
	 * @param Resource $r Resource.
	 */
	public static function disk_gb( Resource $r ): int {
		return max( (int) $r->get( 'disk_gb' ), (int) ( ( (array) $r->get( 'plan' ) )['disk_gb'] ?? 0 ) );
	}

	/**
	 * The customer's part of the panel.
	 *
	 * @param Resource $r Resource.
	 */
	public static function account( Resource $r ): void {
		$plan   = (array) $r->get( 'plan' );
		$record = VmRecords::for_resource( $r->id() );
		$ip     = (string) $r->get( 'ip' );
		$status = Operations::cached_status( $r->id() );
		$list   = null === $record ? array() : OsField::options_for( $record['cluster'] );
		$chosen = (int) $r->get( OsField::META );
		$disk = GuestDisk::cached( $r );
		$chosen = $chosen > 0 ? $chosen : (int) ( $plan['template'] ?? 0 );
		// A template that is no longer listed cannot be preselected: the cluster's default is.
		$select = isset( $list[ $chosen ] ) || null === $record ? $chosen : Clusters::default_template( $record['cluster'] );
		$state  = array(
			'ip'         => false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $ip : '',
			'plan'       => array(
				'cores'     => (int) ( $plan['cores'] ?? 0 ),
				'memory_mb' => (int) ( $plan['memory_mb'] ?? 0 ),
				'disk_gb'   => self::disk_gb( $r ),
			),
			'busy'       => (bool) \BeltoftServices\Progress::build( $r )['active'],
			'power'      => null === $status ? '' : (string) $status['power'],
			'os_label'   => (string) ( $list[ $chosen ] ?? '' ),
			'os_options' => $list,
			'os_current' => $select,
			'has_key'    => Actions::has_key( $r ),
			'can_reveal' => Access::revealable( $r ),
			'forms'      => $r->accepts_actions(),
			'ssh_user'   => Provision::LOGIN_USER,
			'backups'    => Backups::panel( $r ),
			'metrics'    => 'ready' === $r->get( 'state' ) && $r->shows_status(),
			'disk_used'  => $disk['used'] ?? null,
			'disk_total' => $disk['total'] ?? null,
			'extra_disks' => DiskPanels::server( $r ),
			'snapshots'  => self::snapshots( $r, $r->accepts_actions() ),
			'resource_id' => $r->id(),
			'server_name' => ServerName::of( $r ),
			'can_rename'  => $r->accepts_actions() && true !== $r->get( 'linked' ),
			'hostname_pending' => is_string( $r->get( ServerName::PENDING ) ) ? (string) $r->get( ServerName::PENDING ) : '',
			'snapshot_warnings' => array(
				'reinstall' => Snapshots::reinstall_warning( $r ),
				'restore'   => Snapshots::restore_warning( $r ),
			),
		);

		( static function ( array $vars ): void {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables, a fixed list built above.
			extract( $vars, EXTR_SKIP );
			include BELTOFT_SERVICES_PATH . 'templates/account/vm.php';
		} )( $state );
	}

	/**
	 * What the card's Snapshots block shows, or null when the block is absent (no snapshots and none can be taken). Dates are formatted here; the template only prints.
	 *
	 * @param Resource $r     Resource.
	 * @param bool     $forms Whether the card accepts actions now.
	 * @return array<string, mixed>|null
	 */
	public static function snapshots( Resource $r, bool $forms = true ): ?array {
		$panel = Snapshots::panel( $r );

		// Absent only when there are no snapshots and none can be taken.
		if ( null === $panel ) {
			return null;
		}

		// full: every action. working: the server is busy or not accepting actions, the list only.
		// delete: not available any more, the customer may still remove what exists. readonly: an adopted machine, the list only.
		$working = ! $forms || ! Snapshots::idle( $r ) || $panel['rolling'] || array() !== (array) $r->get( Snapshots::OP );

		// Paused or on hold with no job running: nothing is in flight, the actions just are not offered.
		$inactive = ! $panel['rolling'] && array() === (array) $r->get( Snapshots::OP ) && ! $r->get( 'plan_dirty' ) && ! is_array( $r->get( 'restore' ) ) && ! $r->get( 'wipe' )
			&& ( $r->on_hold() || ( 'paused' === $r->get( 'state' ) && 'paused' === $r->get( 'desired' ) ) );

		if ( true === $r->get( 'linked' ) ) {
			$panel['mode'] = 'readonly';
		} elseif ( $inactive ) {
			$panel['mode'] = 'inactive';
		} else {
			$panel['mode'] = $working ? 'working' : ( $panel['enabled'] ? 'full' : 'delete' );
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		foreach ( $panel['entries'] as $i => $entry ) {
			$panel['entries'][ $i ]['taken']  = $entry['taken_at'] > 0 ? wp_date( $format, $entry['taken_at'] ) : '';
			$panel['entries'][ $i ]['keeps']  = $entry['expires_at'] > 0 ? wp_date( $format, $entry['expires_at'] ) : '';
		}

		$panel['full']      = $panel['used'] >= $panel['slots'];
		$panel['max_label'] = Snapshots::MAX_LABEL;
		$panel['included']  = min( $panel['slots'], max( 0, (int) Settings::get( 'snapshot_free_slots' ) ) );
		$panel['added']     = $panel['slots'] - $panel['included'];
		$panel['free_days'] = max( 1, (int) Settings::get( 'snapshot_free_days' ) );
		$panel['paid_days'] = max( 1, (int) Settings::get( 'snapshot_paid_days' ) );
		$panel['buy']       = 'full' === $panel['mode'] ? self::snapshot_packs( $r ) : array();

		if ( $panel['added'] > 0 ) {
			$panel['paid_days'] = Snapshots::days_for( $r );
		}

		return $panel;
	}

	/**
	 * The packs of snapshot slots the server could add now, from the published snapshot product: slots, price and
	 * the link to the cart. Empty when the site has no such product or the server cannot take one.
	 *
	 * @param Resource $r Server resource.
	 * @return array<int, array{slots: int, url: string, price: string, days: int}>
	 */
	private static function snapshot_packs( Resource $r ): array {
		$out = array();

		foreach ( SnapshotCart::packs() as $variation ) {
			$url = SnapshotCart::add_url( $r, $variation );

			if ( '' === $url ) {
				continue;
			}

			$days  = (int) ( Product::plan( $variation->get_parent_id(), $variation->get_id() )['snapshot_days'] ?? 0 );
			$out[] = array(
				'slots' => SnapshotCart::slots_of( $variation ),
				'url'   => $url,
				'price' => wp_strip_all_tags( wc_price( wc_get_price_excluding_tax( $variation ) ) ) . self::per( $variation ),
				'days'  => $days > 0 ? $days : max( 1, (int) Settings::get( 'snapshot_paid_days' ) ),
			);
		}

		return $out;
	}

	/**
	 * " a month" for a subscription billed every month, and likewise for the other periods; '' for other intervals.
	 *
	 * @param \WC_Product $product Subscription product.
	 */
	private static function per( \WC_Product $product ): string {
		if ( ! class_exists( '\\WC_Subscriptions_Product' ) || 1 !== (int) \WC_Subscriptions_Product::get_interval( $product ) ) {
			return '';
		}

		$labels = array(
			'day'   => __( 'a day', 'beltoft-services' ),
			'week'  => __( 'a week', 'beltoft-services' ),
			'month' => __( 'a month', 'beltoft-services' ),
			'year'  => __( 'a year', 'beltoft-services' ),
		);
		$period = (string) \WC_Subscriptions_Product::get_period( $product );

		return isset( $labels[ $period ] ) ? ' ' . $labels[ $period ] : '';
	}

	/**
	 * One line for the admin: whether backups are on, the last good one, how many are kept, the last error.
	 *
	 * @param Resource $r Resource.
	 */
	private static function backups_line( Resource $r ): string {
		$enabled = Backups::enabled( $r );

		if ( ! $enabled && ! $r->get( 'backup_ok_at' ) && ! $r->get( 'backup_error' ) ) {
			return '';
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$ok     = (int) $r->get( 'backup_ok_at' );
		$kept   = $r->get( 'backup_list' );
		$parts  = array(
			$enabled
				/* translators: %d: days of backups kept */
				? sprintf( _n( 'daily, kept %d day', 'daily, kept %d days', Backups::days( $r ), 'beltoft-services' ), Backups::days( $r ) )
				: __( 'off', 'beltoft-services' ),
			$ok > 0
				/* translators: %s: date and time */
				? sprintf( __( 'last OK %s', 'beltoft-services' ), wp_date( $format, $ok ) )
				: __( 'no backup yet', 'beltoft-services' ),
		);

		if ( is_array( $kept ) ) {
			/* translators: %d: number of backups kept */
			$parts[] = sprintf( __( '%d kept', 'beltoft-services' ), count( $kept ) );
		}

		if ( '' !== (string) $r->get( 'backup_error' ) ) {
			/* translators: %s: error text */
			$parts[] = sprintf( __( 'last error: %s', 'beltoft-services' ), (string) $r->get( 'backup_error' ) );
		}

		return implode( ', ', $parts );
	}

	/**
	 * The rows with the snapshot rows after the backups row: count and slots, the oldest, the next expiry, the last
	 * problem and the operation in flight. None when the server has no snapshots and the customer switch is off.
	 *
	 * @param array<string, string|null> $rows Rows so far.
	 * @param Resource                   $r    Resource.
	 * @return array<string, string|null>
	 */
	private static function with_snapshots( array $rows, Resource $r ): array {
		if ( ! Snapshots::tracked( $r ) && ! Snapshots::switched_on() ) {
			return $rows;
		}

		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$entries = Snapshots::entries( $r );
		$taken   = array_filter( array_map( static fn( $e ) => (int) ( $e['taken_at'] ?? 0 ), $entries ) );
		$next    = SnapshotExpiry::next( $r );
		$op      = (array) $r->get( Snapshots::OP );
		$roll    = $r->get( Snapshots::ROLLBACK );
		$running = array();

		if ( array() !== $op ) {
			$running[] = (string) ( $op['op'] ?? '' ) . ' ' . (string) ( $op['phase'] ?? '' );
		}

		if ( is_array( $roll ) ) {
			$running[] = 'rollback ' . (string) ( $roll['phase'] ?? '' );
		}

		$new = array(
			__( 'Snapshots', 'beltoft-services' )        => sprintf(
				/* translators: 1: snapshots in use, 2: slots, 3: on or off */
				__( '%1$d of %2$d slots, %3$s', 'beltoft-services' ),
				count( $entries ),
				Snapshots::slots( $r ),
				Snapshots::enabled( $r ) ? __( 'on', 'beltoft-services' ) : __( 'off', 'beltoft-services' )
			),
			__( 'Oldest snapshot', 'beltoft-services' )  => $taken ? wp_date( $format, min( $taken ) ) : '',
			__( 'Next snapshot expiry', 'beltoft-services' ) => $next > 0 ? wp_date( $format, $next ) : '',
			__( 'Snapshot error', 'beltoft-services' )   => (string) $r->get( Snapshots::ERROR ),
			__( 'Snapshot running', 'beltoft-services' ) => trim( implode( ', ', $running ) ),
		);

		$out = array();

		foreach ( $rows as $label => $value ) {
			$out[ $label ] = $value;

			if ( __( 'Backups', 'beltoft-services' ) === $label ) {
				$out = array_merge( $out, $new );
			}
		}

		return $out;
	}

	/**
	 * The admin's part: where the VM is and what it is doing. No password, no Proxmox call.
	 *
	 * @param Resource $r Resource.
	 */
	public static function admin( Resource $r ): void {
		$record   = VmRecords::for_resource( $r->id() );
		$status   = Operations::cached_status( $r->id() );
		$problems = Alerts::problems();
		$rows     = array(
			__( 'Cluster', 'beltoft-services' )       => null === $record ? '' : $record['cluster'],
			__( 'Node', 'beltoft-services' )          => null === $record ? '' : $record['node'],
			__( 'VMID', 'beltoft-services' )          => null === $record ? '' : (string) $record['vmid'],
			__( 'IP address', 'beltoft-services' )    => (string) $r->get( 'ip' ),
			__( 'Ownership tag', 'beltoft-services' ) => Ownership::tag( $r ),
			__( 'Power', 'beltoft-services' )         => null === $status ? '' : (string) $status['power'],
			__( 'Size', 'beltoft-services' )          => sprintf(
				/* translators: 1: number of vCPUs, 2: memory in MB, 3: disk in GB */
				__( '%1$d vCPU, %2$d MB RAM, %3$d GB disk', 'beltoft-services' ),
				(int) ( ( (array) $r->get( 'plan' ) )['cores'] ?? 0 ),
				(int) ( ( (array) $r->get( 'plan' ) )['memory_mb'] ?? 0 ),
				self::disk_gb( $r )
			),
			__( 'Template', 'beltoft-services' )      => (string) ( (int) $r->get( OsField::META ) > 0 ? $r->get( OsField::META ) : ( (array) $r->get( 'plan' ) )['template'] ?? '' ),
			__( 'Backups', 'beltoft-services' )       => self::backups_line( $r ),
			__( 'Disk reading', 'beltoft-services' ) => $r->get( 'disk_read_at' ) ? sprintf( '%s / %s bytes at %s', (string) $r->get( 'disk_used' ), (string) $r->get( 'disk_total' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $r->get( 'disk_read_at' ) ) ) : '',
			__( 'Down since', 'beltoft-services' ) => $r->get( 'monitor_down_since' ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $r->get( 'monitor_down_since' ) ) : '',
			__( 'Down alert sent', 'beltoft-services' ) => $r->get( 'monitor_down_notified' ) ? __( 'Yes', 'beltoft-services' ) : __( 'No', 'beltoft-services' ),
			__( 'Disk alert sent', 'beltoft-services' ) => $r->get( 'monitor_disk_notified' ) ? __( 'Yes', 'beltoft-services' ) : __( 'No', 'beltoft-services' ),
			__( 'Waiting', 'beltoft-services' )       => '' !== (string) $r->get( 'waiting' ) ? sprintf( /* translators: %s: what the server waits for. */ __( 'Waiting: %s', 'beltoft-services' ), (string) $r->get( 'waiting' ) ) : '',
			__( 'Last error', 'beltoft-services' )    => (string) ( $problems[ $r->id() ]['message'] ?? $r->get( 'last_error' ) ),
		);

		$rows = self::with_snapshots( $rows, $r );

		echo '<h3>' . esc_html__( 'Virtual machine', 'beltoft-services' ) . '</h3><table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( '' === $value ? '—' : $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
