<?php
/**
 * The client of a VM record's cluster, built once for the classes that run actions on a VM.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Used by Actions, Backups, Operations and Snapshots, next to the Steps trait. The using class declares the
 * `$cluster` property (the cluster settings, token secret decrypted).
 */
trait Prepares {

	/**
	 * Builds the client of the record's cluster. Failures are permanent.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function prepare( array $record ): ?StepResult {
		$cluster = Clusters::get( (string) $record['cluster'] );

		if ( null === $cluster ) {
			return StepResult::fail( 'The cluster of this VM is not configured' );
		}

		$this->cluster = $cluster;
		$this->secrets = array( (string) $cluster['token_secret'] );

		$gate = Clients::gate( (string) $record['cluster'], $cluster, Clients::live_allowed() );

		if ( null !== $gate ) {
			return StepResult::retry( 0, $gate );
		}

		try {
			$this->client = Clients::for( $cluster );
		} catch ( \Throwable $e ) {
			unset( $e );

			return StepResult::fail( 'The Proxmox client could not be built for this cluster' );
		}

		$wait       = (int) Settings::get( 'task_wait' );
		$this->wait = $wait > 0 ? $wait : 25;

		return null;
	}
}
