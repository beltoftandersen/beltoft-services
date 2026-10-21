<?php
/**
 * WP-CLI commands.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Http\Connections;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Beltoft Services from the command line.
 */
final class Commands {

	/**
	 * Registers the commands when WP-CLI is running.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'beltoft-services', self::class );
		}
	}

	/**
	 * Links a license to a tenant that already exists on an HTTP service.
	 *
	 * Records the resource as ready and protected with the given remote id. It calls the
	 * service for nothing and provisions nothing. Right after linking, the plan is pushed to
	 * the service once (update_plan), so the tenant matches the product.
	 *
	 * ## OPTIONS
	 *
	 * <license-id>
	 * : The license id.
	 *
	 * --remote-id=<id>
	 * : The tenant's id at the service.
	 *
	 * [--dry-run]
	 * : Say what would be recorded, without writing or queuing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beltoft-services link-http 12 --remote-id=tenant-42 --dry-run
	 *     wp beltoft-services link-http 12 --remote-id=tenant-42
	 *
	 * @subcommand link-http
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function link_http( array $args, array $assoc_args ): void {
		$license = Licenses::find( (int) ( $args[0] ?? 0 ) );

		if ( ! $license ) {
			\WP_CLI::error( 'License not found.' );
		}

		$remote_id = trim( (string) ( $assoc_args['remote-id'] ?? '' ) );

		if ( '' === $remote_id ) {
			\WP_CLI::error( '--remote-id must not be empty.' );
		}

		$product_id = (int) $license['product_id'];
		$driver     = Product::driver_for( $product_id );

		if ( ! $driver || 'http' !== $driver->id() ) {
			\WP_CLI::error( "The license's product is not an HTTP service." );
		}

		$connection = Product::connection( $product_id );

		if ( '' === $connection || ! isset( Connections::all()[ $connection ] ) ) {
			\WP_CLI::error( '' === $connection ? "The license's product has no connection." : sprintf( 'The connection "%s" of the license\'s product does not exist.', $connection ) );
		}

		if ( Resource::for_license( (int) $license['id'] ) ) {
			\WP_CLI::error( 'The license already has a live resource.' );
		}

		$other = $this->linked_license( $driver->kind(), $connection, $remote_id );

		if ( $other > 0 ) {
			\WP_CLI::error( sprintf( 'Remote id %1$s is already linked to license %2$d.', $remote_id, $other ) );
		}

		$status = Licenses::effective_status( $license );

		// beltoft-licenses refuses to add a resource to a license that is not active, so linking
		// one is not possible; refuse early with the reason instead of a half-made link.
		if ( Licenses::STATUS_ACTIVE !== $status ) {
			\WP_CLI::error( sprintf( 'The license is %s; a resource can only be linked to an active license.', $status ) );
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			\WP_CLI::log( sprintf( 'Would record license %d as ready and protected with remote id %s, call the service for nothing, then one update_plan.', (int) $license['id'], $remote_id ) );
			return;
		}

		$variation_id = (int) ( $license['variation_id'] ?? 0 );

		$resource = Resource::create(
			$license,
			$driver,
			Product::plan( $product_id, $variation_id > 0 ? $variation_id : null ),
			array(
				'state'      => 'ready',
				'desired'    => 'ready',
				'remote_id'  => $remote_id,
				'protected'  => true,
				'plan_dirty' => true,
				'steps'      => array( 'provision' ),
				// The customer already has this service: later paused/deleted emails apply.
				'notified'   => 'ready',
			)
		);

		if ( is_wp_error( $resource ) ) {
			\WP_CLI::error( $resource->get_error_message() );
		}

		// A resource made by another request between the check and create() is returned as it is.
		if ( $resource->get( 'remote_id' ) !== $remote_id ) {
			\WP_CLI::error( 'The license got a resource in the meantime; nothing was linked.' );
		}

		Events::add( (int) $license['id'], 'resource_linked', sprintf( 'Linked to existing tenant %s by WP-CLI; the plan will be pushed once.', $remote_id ) );
		Jobs::enqueue( $resource->id() );

		\WP_CLI::success( sprintf( 'Linked license %d to remote id %s. The plan will be pushed once.', (int) $license['id'], $remote_id ) );
	}

	/**
	 * The license whose live resource already has this remote id on this connection, or 0.
	 *
	 * @param string $kind       Activation kind.
	 * @param string $connection Connection id.
	 * @param string $remote_id  Remote id.
	 */
	private function linked_license( string $kind, string $connection, string $remote_id ): int {
		foreach ( Resource::live_ids( $kind ) as $id ) {
			$resource = Resource::find( $id );

			if ( ! $resource || $resource->get( 'remote_id' ) !== $remote_id ) {
				continue;
			}

			$license = $resource->license();
			$used    = (string) $resource->get( 'connection' );
			$used    = '' !== $used ? $used : Product::connection( (int) ( $license['product_id'] ?? 0 ) );

			if ( $used === $connection ) {
				return (int) ( $license['id'] ?? 0 );
			}
		}

		return 0;
	}

	/**
	 * Shows a license's resource, without secrets.
	 *
	 * ## OPTIONS
	 *
	 * <license-id>
	 * : The license id.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp beltoft-services status 12 --format=json
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function status( array $args, array $assoc_args ): void {
		$resource = Resource::for_license( (int) ( $args[0] ?? 0 ) );

		if ( ! $resource ) {
			\WP_CLI::error( 'The license has no live resource.' );
		}

		$meta = array( 'activation_id' => $resource->id() );

		foreach ( array( 'uuid', 'driver', 'state', 'desired', 'remote_id', 'protected', 'plan_dirty', 'attempts', 'next_attempt_at', 'paused_at', 'warned_at', 'last_error', 'plan' ) as $key ) {
			$meta[ $key ] = $resource->get( $key );
		}

		$items = array();
		foreach ( $meta as $key => $value ) {
			$items[] = array(
				'field' => $key,
				'value' => self::show( $value ),
			);
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::log( (string) wp_json_encode( $meta ) );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * A value as text for the table.
	 *
	 * @param mixed $value Value.
	 */
	private static function show( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		if ( null === $value ) {
			return '';
		}

		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}
}
