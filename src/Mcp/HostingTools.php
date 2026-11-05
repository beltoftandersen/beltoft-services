<?php
/**
 * The MCP tools of WordPress hosting.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Hosting\HostingBackups;
use BeltoftServices\Hosting\HostingDriver;
use BeltoftServices\Hosting\Sites;

defined( 'ABSPATH' ) || exit;

/**
 * Plan 16.6, on the levels the MCP already has, over the dashboard's own routes (HostingRoutes), so the owner check,
 * the plan's ceilings and the hourly limits are the same ones:
 *
 * - Read: hosting_info (sites, domains, ceilings, what an external domain must point at), hosting_usage (stats and the
 *   storage share), list_hosting_backups (the backups and the safety point).
 * - Actions: add_site_domain, set_php_version, clear_site_cache, create_hosting_backup.
 * - Full, each with the customer's confirmation and an e-mail afterwards: restore_hosting_backup (the whole account,
 *   one site's files or one site's database, after a safety point), delete_site.
 *
 * Not here, on purpose or for later: reading logs (the agent has no log operation yet), the SFTP password tools and the
 * WordPress sign-in link (a secret that needs the Secrets machinery and an agent job that is not built), and ordering
 * hosting (the purchase flow of the cart). Nothing here shows a password or a sign-in link.
 */
final class HostingTools {

	/** Read tools. */
	public const READS = array( 'hosting_info', 'hosting_usage', 'list_hosting_backups' );

	/** Everyday action tools. */
	public const ACTS = array( 'add_site_domain', 'set_php_version', 'clear_site_cache', 'create_hosting_backup' );

	/** Full access tools, each with a confirmation. */
	public const FULL = array( 'restore_hosting_backup', 'delete_site' );

	/**
	 * A hosting account of the customer, or the refusal.
	 *
	 * @param int $id Service id.
	 * @return \BeltoftServices\Resource|\WP_Error
	 */
	public static function account( int $id ) {
		$r = Tools::owned( $id, true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		return HostingDriver::ID === $r->get( 'driver' ) ? $r : new \WP_Error( 'beltoft_services_tool', __( 'That service is not a hosting account.', 'beltoft-services' ) );
	}

	/**
	 * Runs a read tool.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments, checked against the schema.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function read( string $name, array $args ) {
		$id = (int) $args['id'];

		switch ( $name ) {
			case 'hosting_info':
				return Tools::route( 'GET', '/resources/' . $id . '/hosting', array(), null, $id, 'hosting read' );

			case 'hosting_usage':
				return Tools::route( 'GET', '/resources/' . $id . '/hosting/usage', array(), null, $id, 'hosting usage read' );
		}

		$list = Tools::route( 'GET', '/resources/' . $id . '/hosting/backups', array(), null, $id, 'hosting backups read' );

		if ( $list instanceof \WP_Error ) {
			return $list;
		}

		// A backup is named by the volume id the restore tool takes; times are Unix times.
		return $list;
	}

	/**
	 * Runs an action tool.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments, checked against the schema.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function act( string $name, array $args ) {
		$id   = (int) $args['id'];
		$site = isset( $args['site'] ) ? rawurlencode( (string) $args['site'] ) : '';

		switch ( $name ) {
			case 'add_site_domain':
				$done = Tools::route( 'POST', '/resources/' . $id . '/hosting/sites/' . $site . '/domains', array(), array( 'host' => (string) $args['domain'] ), $id, 'site domain added' );

				return $done instanceof \WP_Error ? $done : array( 'added' => (string) $args['domain'], 'kind' => (string) $done['kind'], 'state' => (string) $done['state'], 'point_at' => (array) ( $done['point_at'] ?? array() ) );

			case 'set_php_version':
				$body = array( 'php' => (string) $args['php'] );

				foreach ( array( 'memory_mb', 'upload_mb' ) as $key ) {
					if ( isset( $args[ $key ] ) ) {
						$body[ $key ] = (int) $args[ $key ];
					}
				}

				$done = Tools::route( 'POST', '/resources/' . $id . '/hosting/sites/' . $site . '/php', array(), $body, $id, 'site PHP version changed' );

				return $done instanceof \WP_Error ? $done : (array) $done;

			case 'clear_site_cache':
				$done = Tools::route( 'POST', '/resources/' . $id . '/hosting/sites/' . $site . '/cache', array(), array(), $id, 'site cache cleared' );

				return $done instanceof \WP_Error ? $done : array( 'cleared' => (string) $args['site'] );
		}

		$done = Tools::route( 'POST', '/resources/' . $id . '/hosting/backups', array(), array(), $id, 'hosting backup started' );

		return $done instanceof \WP_Error ? $done : array( 'started' => true, 'message' => 'The backup runs in the background. list_hosting_backups shows it when it is done.' );
	}

	/**
	 * The summary of a full tool: the checks of the dashboard, and what the call does in plain words.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary( string $name, array $args ) {
		$r = self::account( (int) $args['id'] );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$label = Irreversible::name( $r );

		if ( 'delete_site' === $name ) {
			$slug  = (string) $args['site'];
			$sites = Sites::for_resource( $r->id() );

			if ( null === Sites::find( $r->id(), $slug ) ) {
				return new \WP_Error( 'beltoft_services_tool', __( 'That website does not exist.', 'beltoft-services' ) );
			}

			if ( count( $sites ) < 2 ) {
				return new \WP_Error( 'beltoft_services_tool', __( 'The last website of an account cannot be removed.', 'beltoft-services' ) );
			}

			return array(
				'text'  => sprintf(
					/* translators: 1: website name, 2: name of the service. */
					__( 'Delete the website %1$s of %2$s: its files and its database are deleted for good and cannot be brought back, and its addresses stop working. Backups that already exist are kept until they expire.', 'beltoft-services' ),
					$slug,
					$label
				),
				'state' => $r->id() . '|delete_site|' . $slug,
			);
		}

		$scope  = (string) $args['scope'];
		$volid  = (string) $args['backup'];
		$backup = null;

		foreach ( HostingBackups::stored( $r ) as $b ) {
			if ( $b['volid'] === $volid ) {
				$backup = $b;
			}
		}

		if ( null === $backup ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'That backup is not in the list. Use list_hosting_backups.', 'beltoft-services' ) );
		}

		if ( 'account' !== $scope && null === Sites::find( $r->id(), (string) ( $args['site'] ?? '' ) ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'Name the website to restore.', 'beltoft-services' ) );
		}

		$what = array(
			'account'  => __( 'the whole hosting account: every website file and every database', 'beltoft-services' ),
			'files'    => __( 'the files of one website', 'beltoft-services' ),
			'database' => __( 'the database of one website', 'beltoft-services' ),
		)[ $scope ] ?? '';

		return array(
			'text'  => sprintf(
				/* translators: 1: what is restored, 2: name of the service, 3: backup date. */
				__( 'Restore %1$s of %2$s from the backup of %3$s. What was changed since then is replaced. A safety copy is made first and the customer can undo the restore in the dashboard for 24 hours. The restore runs in the background and takes a few minutes, longer for a whole account.', 'beltoft-services' ),
				$what,
				$label,
				wp_date( 'j F Y, H:i', (int) $backup['ctime'] )
			),
			'state' => $r->id() . '|restore_hosting_backup|' . $scope . '|' . $volid . '|' . (string) ( $args['site'] ?? '' ),
		);
	}

	/**
	 * Runs a full tool. The confirmation was accepted before this.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args ) {
		$id = (int) $args['id'];

		if ( 'delete_site' === $name ) {
			$done = Tools::route( 'DELETE', '/resources/' . $id . '/hosting/sites/' . rawurlencode( (string) $args['site'] ), array( 'confirm' => (string) $args['site'] ), null, $id, 'website deleted' );

			return $done instanceof \WP_Error ? $done : array( 'deleted' => (string) $done['removed'], 'message' => 'The website and its database were deleted.' );
		}

		$done = Tools::route(
			'POST',
			'/resources/' . $id . '/hosting/backups/restore',
			array(),
			array( 'scope' => (string) $args['scope'], 'volid' => (string) $args['backup'], 'slug' => (string) ( $args['site'] ?? '' ) ),
			$id,
			'hosting restore requested'
		);

		return $done instanceof \WP_Error ? $done : array( 'requested' => true, 'message' => 'The restore runs in the background. A safety copy was made first; the customer can undo it in the dashboard for 24 hours, and is told by e-mail when it has finished.' );
	}
}
