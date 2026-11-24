<?php
/**
 * Answers the admin screens' questions about hosting accounts.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The admin panel and the licence list ask through filters; this adds what is hosting's own.
 */
final class AdminBridge {

	/**
	 * Hooks the answers.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_panel_detail_rows', array( __CLASS__, 'detail_rows' ), 10, 2 );
		add_filter( 'beltoft_services_licence_column_names', array( __CLASS__, 'column_names' ), 10, 2 );
	}

	/**
	 * The container files version row of a hosting account.
	 *
	 * @param mixed $rows Rows so far, label => value.
	 * @param mixed $r    Resource.
	 * @return mixed
	 */
	public static function detail_rows( $rows, $r ) {
		if ( ! is_array( $rows ) || ! $r instanceof Resource || HostingDriver::ID !== $r->get( 'driver' ) ) {
			return $rows;
		}

		$rows[ __( 'Container files version', 'beltoft-services' ) ] = '' === (string) $r->get( 'runner_version' ) ? '' : sprintf( /* translators: 1: version, 2: template id. */ __( '%1$s (template %2$d)', 'beltoft-services' ), (string) $r->get( 'runner_version' ), (int) $r->get( 'runner_for' ) );

		return $rows;
	}

	/**
	 * The site addresses of the hosting accounts among the resources, the real ones before the preview.
	 *
	 * @param mixed $names     Names so far, resource id => names.
	 * @param mixed $resources Resources.
	 * @return mixed
	 */
	public static function column_names( $names, $resources ) {
		if ( ! is_array( $names ) || ! is_array( $resources ) ) {
			return $names;
		}

		$accounts = array();

		foreach ( $resources as $r ) {
			if ( $r instanceof Resource && HostingDriver::KIND === $r->kind() ) {
				$accounts[] = $r->id();
			}
		}

		foreach ( SiteDomains::for_resources( $accounts ) as $resource_id => $list ) {
			$non_preview = array_filter( $list, static fn( $n ) => 'preview' !== $n['kind'] );
			$use         = $non_preview ? $non_preview : $list;

			$names[ $resource_id ] = array_values( array_unique( array_map( static fn( $n ) => (string) $n['host'], $use ) ) );
		}

		return $names;
	}
}
