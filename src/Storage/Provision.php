<?php
/**
 * Building a storage box, step by step.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Crypto;
use BeltoftServices\Domains\SpendGuard;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Steps: password, create (guard, look up by our own name, buy), wait, access, protect, snapshots. Every run starts at the
 * top and skips what is finished: the box id and the steps are returned in the meta of every result, so a retry after any
 * failure finds the box by its id (or, when the id was lost, by our own name) and never buys a second one.
 */
final class Provision {

	/** Seconds a new box may take to become active. */
	private const WAIT_BUDGET = 900;

	/**
	 * The name of our box at the provider: from the resource's own uuid, so it is stable and never the customer's input.
	 *
	 * @param Resource $r Resource.
	 */
	public static function box_name( Resource $r ): string {
		$name = (string) $r->get( 'storage_name' );

		return '' !== $name ? $name : 'bs-' . substr( str_replace( '-', '', (string) $r->get( 'uuid' ) ), 0, 24 );
	}

	/**
	 * The plan values: box type and location.
	 *
	 * @param Resource $r Resource.
	 * @return array{0: string, 1: string} Type, location code.
	 */
	public static function plan( Resource $r ): array {
		$plan = (array) $r->get( 'plan' );

		// Germany only (owner decided 2026-11-17): the location is never a plan field.
		return array( strtolower( trim( (string) ( $plan['storage_type'] ?? '' ) ) ), StorageSettings::default_location() );
	}

	/**
	 * Runs the build.
	 *
	 * @param Resource $r Resource.
	 */
	public static function run( Resource $r ): StepResult {
		$client = StorageClients::for();
		$steps  = array_values( array_unique( array_map( 'strval', (array) $r->get( 'steps' ) ) ) );
		$meta   = array();
		$mark   = static function ( string $step ) use ( &$steps, &$meta ): void {
			if ( ! in_array( $step, $steps, true ) ) {
				$steps[] = $step;
			}

			$meta['steps'] = $steps;
		};

		list( $type, $location ) = self::plan( $r );

		if ( '' === $type ) {
			return StepResult::fail( 'The storage product has no box type.' );
		}

		// The password first: it is stored encrypted at once and never made twice.
		if ( '' === (string) $r->get( 'storage_password' ) ) {
			if ( ! Crypto::available() ) {
				return StepResult::fail( 'BELTOFT_SERVICES_KEY is missing, so the box password cannot be stored. Nothing was bought.' );
			}

			$meta['storage_password'] = Crypto::encrypt( Boxes::make_password() );
		}

		$mark( 'password' );

		$id = Boxes::id( $r );

		if ( '' === $id ) {
			$name = self::box_name( $r );
			$meta['storage_name'] = $name;
			$box = $client->find_box( $name );

			if ( is_wp_error( $box ) ) {
				return StepResult::retry( 60, Boxes::why( $box ), $meta, false );
			}

			if ( null === $box ) {
				// The caps are checked and the box bought under one global lock, and the box id is stored before the lock is
				// let go, so a parallel provision of another resource sees this box in its count.
				$password = Crypto::decrypt( (string) ( $meta['storage_password'] ?? $r->get( 'storage_password' ) ) );
				$item     = SpendGuard::item_of_resource( $r );
				list( $got, $made ) = \BeltoftServices\Locks::with(
					\BeltoftServices\Locks::name( 'storage-spend', 0 ),
					30,
					static function () use ( $r, $client, $type, $location, $name, $password, $item, $meta ) {
						$stop = StorageSpendGuard::guard( $r, $type, $item );

						if ( $stop ) {
							return StepResult::fail( $stop->error(), $meta );
						}

						if ( null === $password || '' === $password ) {
							return StepResult::fail( 'The stored box password cannot be read. Nothing was bought.', $meta );
						}

						$created = $client->create_box( $name, $type, $location, $password, array(), Boxes::default_access() );

						if ( is_wp_error( $created ) ) {
							return StepResult::retry( 300, Boxes::why( $created ), $meta, false );
						}

						StorageSpendGuard::count_created();
						$r->set( array( 'storage_box_id' => (string) $created['id'], 'storage_type' => $type, 'storage_name' => $name ) );

						return $created;
					}
				);

				if ( ! $got ) {
					return StepResult::retry( 30, 'Another storage purchase is running', $meta, false );
				}

				if ( $made instanceof StepResult ) {
					return $made;
				}

				$box = $made;
			}

			$id = (string) $box['id'];
			$meta += array(
				'storage_box_id'   => $id,
				'storage_type'     => $type,
				'storage_location' => $location,
			);
			$mark( 'guard' );
			$mark( 'create' );
		}

		$box = $client->get_box( $id );

		if ( is_wp_error( $box ) ) {
			return StepResult::retry( 60, Boxes::why( $box ), $meta, in_array( 'create', $steps, true ) );
		}

		if ( 'active' !== $box['status'] ) {
			return StepResult::poll( 5, 'Waiting for the storage to start', self::WAIT_BUDGET, $meta );
		}

		$mark( 'wait' );

		if ( ! in_array( 'access', $steps, true ) ) {
			$set = $client->set_access( $id, Boxes::default_access() );

			if ( is_wp_error( $set ) ) {
				return StepResult::retry( 60, Boxes::why( $set ), $meta, true );
			}

			$meta['storage_access'] = Boxes::default_access();
			$mark( 'access' );
		}

		if ( ! in_array( 'protect', $steps, true ) ) {
			$set = $client->set_protection( $id, true );

			if ( is_wp_error( $set ) ) {
				return StepResult::retry( 60, Boxes::why( $set ), $meta, true );
			}

			$mark( 'protect' );
		}

		if ( ! in_array( 'snapshots', $steps, true ) ) {
			$set = $client->set_snapshot_plan( $id, SnapshotPlan::default_plan() );

			if ( is_wp_error( $set ) ) {
				return StepResult::retry( 60, Boxes::why( $set ), $meta, true );
			}

			$meta['storage_snapshot_plan'] = SnapshotPlan::default_plan() ?? array();
			$mark( 'snapshots' );
		}

		if ( ! in_array( 'alias', $steps, true ) ) {
			$alias = HostAlias::publish( $r, (string) $box['host'] );

			if ( is_wp_error( $alias ) ) {
				return StepResult::retry( 300, Boxes::why( $alias ), $meta, true );
			}

			if ( '' !== $alias ) {
				$meta['storage_alias'] = $alias;
			}

			$mark( 'alias' );
		}

		$meta += array(
			'storage_username'       => (string) $box['username'],
			'storage_host'           => (string) $box['host'],
			'storage_size_bytes'     => (int) $box['size_bytes'],
			'storage_used_bytes'     => (int) $box['used_bytes'],
			'storage_snapshot_bytes' => (int) $box['snapshot_bytes'],
			'storage_checked_at'     => time(),
		);

		return StepResult::done( $meta, 'Storage box ready' );
	}
}
