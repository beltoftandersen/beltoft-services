<?php
/** Queue a one-time app install through the guest agent. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class AppInstall {
	/** Called only by the post-start provision step, under the resource lock. */
	public static function queue( Resource $resource, Client $client, array $cluster, array $plan ): array|StepResult {
		$id = (string) $resource->get( 'app_id' );
		if ( '' === $id ) {
			return array();
		}
		$version_id = (string) $resource->get( 'app_version' );
		$why = Apps::check( $cluster, $plan, $id, $version_id );
		if ( null !== $why ) {
			return StepResult::fail( 'app: ' . $why, array( 'app_state' => 'failed' ) );
		}
		$record = VmRecords::for_resource( $resource->id() );
		$ip = IpPool::for_resource( $resource->id() );
		if ( null === $record || null === $ip ) {
			return StepResult::fail( 'app: VM record or address missing' );
		}
		$app = Apps::version( $id, $version_id );
		$request = (string) $resource->get( 'app_request' );
		if ( '' === $request ) {
			$request = wp_generate_uuid4();
			if ( ! $resource->set( array( 'app_request' => $request, 'app_state' => 'pending' ) ) ) {
				return StepResult::retry( 0, 'app: request id could not be stored' );
			}
		}
		$job = array(
			'v' => 1,
			'op' => 'install',
			'request' => $request,
			'app' => $id,
			'version' => $version_id,
			'recipe' => (int) $app['recipe'],
			'url' => $app['url'],
			'sha256' => $app['sha256'],
			'domain' => (string) $resource->get( 'app_domain' ),
			'ip' => $ip['address'],
			'params' => array(),
			'key_only' => '' !== (string) $resource->get( 'ssh_key' ),
		);
		$base = '/nodes/' . rawurlencode( $record['node'] ) . '/qemu/' . $record['vmid'];
		$reply = $client->request( 'POST', $base . '/agent/file-write', array( 'file' => Apps::JOB, 'content' => wp_json_encode( $job ) ) );
		if ( is_wp_error( $reply ) ) {
			$error_data = (array) $reply->get_error_data();
			$status = (int) ( $error_data['status'] ?? 0 );
			if ( 403 === $status ) {
				return StepResult::fail( 'app: guest file-write permission denied', array( 'app_state' => 'failed' ) );
			}
			$until = (int) $resource->get( 'app_queue_until' );
			$until = $until ?: time() + 600;
			if ( time() >= $until ) {
				return StepResult::fail( 'app: guest agent did not accept the install job', array( 'app_state' => 'failed' ) );
			}
			return StepResult::wait( 10, 'app: waiting for guest bootstrap', $until, array( 'app_queue_until' => $until ) );
		}
		AppStatus::schedule( $resource->id(), 15 );
		return array(
			'app_state' => 'installing',
			'app_recipe' => (int) $app['recipe'],
			'app_upstream' => (string) $app['upstream'],
			'app_started_at' => time(),
			'app_queue_until' => null,
		);
	}
}
