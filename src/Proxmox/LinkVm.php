<?php
/**
 * Adopts a machine that already exists (a customer's, running in production) into this plugin.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Behind `wp beltoft-services link-vm`. It changes bookkeeping and the machine's ownership tag, and
 * nothing else: no start, stop, resize, network or disk change. Calls, in this order:
 *
 *   1. read  GET /cluster/resources?type=vm   (exists on the node, a VM not a template, existing pool)
 *   2. read  GET /nodes/{node}/qemu/{vmid}/config   (description, digest, ipconfig0)
 *   3. write PUT the same path: `description` = existing description + a line with the tag, `digest` guards it
 *   4. write PUT /pools (or /pools/{poolid}) `vms` = vmid, only when the cluster has a pool and the machine is in none
 *      (a machine in another pool is never moved: no `allow-move`)
 *
 * A dry run makes no call at all and writes nothing: it only prints these. The record is made as ready
 * and protected, with `plan_dirty` false (an update_plan would shut the production machine down to
 * "match the plan") and `linked` true (the customer cannot reinstall or replace the key of an adopted machine).
 *
 * The address is a free address of the pool. A machine on another network (another bridge, an internal
 * address) is adopted with `outside_pool`: its address must then NOT be in the pool, no pool row is touched,
 * and nothing about its network is changed. IPv4 only.
 */
final class LinkVm {

	private const STEPS = array( 'ip', 'vmid', 'clone', 'configure', 'start', 'ready' );

	/**
	 * Does an adoption need the typed confirmation?
	 *
	 * @param array<string, mixed> $cluster Cluster as Clusters::get() returns it ('live' only when wp-config allows).
	 */
	public static function needs_confirmation( array $cluster ): bool {
		return 'live' === ( $cluster['client'] ?? 'fake' );
	}

	/**
	 * Checks, then (unless a dry run) links.
	 *
	 * @param int                  $license_id License id.
	 * @param array<string, mixed> $o          cluster, node, vmid, ip (strings, vmid int), outside_pool, dry_run, confirm, live (bool; default from the cluster).
	 * @return array{lines: string[], warnings: string[], linked: bool}|\WP_Error
	 */
	public static function run( int $license_id, array $o ) {
		$cluster_id = (string) ( $o['cluster'] ?? '' );
		$node       = (string) ( $o['node'] ?? '' );
		$vmid       = (int) ( $o['vmid'] ?? 0 );
		$ip         = (string) ( $o['ip'] ?? '' );
		$outside    = ! empty( $o['outside_pool'] );
		$dry        = ! empty( $o['dry_run'] );

		// 1. Checks that need no Proxmox call.
		$license = Licenses::find( $license_id );

		if ( ! $license ) {
			return self::error( 'License not found.' );
		}

		$driver = Product::driver_for( (int) $license['product_id'] );

		if ( ! $driver || 'proxmox' !== $driver->id() ) {
			return self::error( "The license's product is not a Proxmox service." );
		}

		$cluster = Clusters::get( $cluster_id );

		if ( null === $cluster ) {
			return self::error( sprintf( 'The cluster "%s" is not configured.', $cluster_id ) );
		}

		$variation_id = (int) ( $license['variation_id'] ?? 0 );
		$plan         = Product::plan( (int) $license['product_id'], $variation_id > 0 ? $variation_id : null );

		if ( (string) ( $plan['cluster'] ?? '' ) !== $cluster_id ) {
			return self::error( sprintf( 'The product builds on cluster "%1$s", not "%2$s".', (string) ( $plan['cluster'] ?? '' ), $cluster_id ) );
		}

		if ( '' === $node || ! preg_match( '/^[A-Za-z0-9.\-]+$/', $node ) ) {
			return self::error( '--node is not a valid node name.' );
		}

		if ( $vmid < (int) $cluster['vmid_min'] || $vmid > (int) $cluster['vmid_max'] ) {
			return self::error( sprintf( 'VMID %1$d is outside the cluster\'s customer range %2$d-%3$d.', $vmid, (int) $cluster['vmid_min'], (int) $cluster['vmid_max'] ) );
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return self::error( '--ip must be an IPv4 address.' );
		}

		if ( Resource::for_license( (int) $license['id'] ) ) {
			return self::error( 'The license already has a live resource.' );
		}

		$status = Licenses::effective_status( $license );

		if ( Licenses::STATUS_ACTIVE !== $status ) {
			return self::error( sprintf( 'The license is %s; a resource can only be linked to an active license.', $status ) );
		}

		$holder = VmRecords::for_cluster( $cluster_id )[ $vmid ] ?? 0;

		if ( $holder > 0 ) {
			return self::error( sprintf( 'VMID %1$d is already recorded for resource %2$d.', $vmid, $holder ) );
		}

		$row = IpPool::find( $ip );

		if ( $outside ) {
			if ( null !== $row ) {
				return self::error( sprintf( '%s is in the IP pool; leave out --outside-pool and it is assigned from there.', $ip ) );
			}
		} elseif ( null === $row || 'free' !== $row['state'] ) {
			return self::error( null === $row ? sprintf( '%s is not in the IP pool. For a machine on another network, add --outside-pool.', $ip ) : sprintf( '%1$s is %2$s in the IP pool, not free.', $ip, $row['state'] ) );
		}

		$live = isset( $o['live'] ) ? (bool) $o['live'] : self::needs_confirmation( $cluster );

		if ( ! $dry && $live && 'LINK:' . $vmid !== (string) ( $o['confirm'] ?? '' ) ) {
			return self::error( sprintf( 'This changes a machine on a live cluster. Add --confirm=LINK:%d to go ahead, or --dry-run to see the calls.', $vmid ) );
		}

		$base   = '/nodes/' . rawurlencode( $node ) . '/qemu/' . $vmid . '/config';
		$tag    = 'beltoft-services:' . ProxmoxSettings::site_tag() . ':';
		$pool   = (string) $cluster['pool'];
		$pools  = 'pools_id' === $cluster['pool_api'] ? '/pools/' . rawurlencode( $pool ) : '/pools';
		$pparam = 'pools_id' === $cluster['pool_api'] ? 'vms=' . $vmid : 'poolid=' . $pool . ' vms=' . $vmid;

		if ( $dry ) {
			$lines = array(
				'Dry run: nothing is read or written.',
				sprintf( 'Would record license %1$d as ready and protected, VMID %2$d on %3$s at %4$s, plan_dirty false.', (int) $license['id'], $vmid, $node, $ip ),
				'READ   GET /cluster/resources type=vm',
				'READ   GET ' . $base,
				sprintf( 'WRITE  PUT %s description=<existing description> + newline + %s<uuid of the new resource> digest=<digest read above>', $base, $tag ),
				'' !== $pool ? sprintf( 'WRITE  PUT %1$s %2$s   (skipped when the machine is already in a pool)', $pools, $pparam ) : 'No pool is set for the cluster: no pool call.',
			);

			return array(
				'lines'    => $lines,
				'warnings' => array(),
				'linked'   => false,
			);
		}

		// 2. Reads. Nothing is recorded before they agree.
		$client = Clients::for( $cluster );
		$list   = Ownership::listing( $client );

		if ( is_wp_error( $list ) ) {
			return self::error( 'Could not read the VM list: ' . $list->get_error_message() );
		}

		$entry = Ownership::entry( $list, $vmid );

		if ( null === $entry ) {
			return self::error( sprintf( 'VMID %d is not in the cluster listing.', $vmid ) );
		}

		if ( 'qemu' !== (string) ( $entry['type'] ?? 'qemu' ) || ! empty( $entry['template'] ) ) {
			return self::error( sprintf( 'VMID %d is not a virtual machine (it is a template or a container).', $vmid ) );
		}

		if ( (string) ( $entry['node'] ?? '' ) !== $node ) {
			return self::error( sprintf( 'VMID %1$d is on node "%2$s", not "%3$s".', $vmid, (string) ( $entry['node'] ?? '' ), $node ) );
		}

		$config = Ownership::config( $client, $node, $vmid );

		if ( is_wp_error( $config ) ) {
			return self::error( 'Could not read the VM config: ' . $config->get_error_message() );
		}

		$ipconfig = (string) ( $config['ipconfig0'] ?? '' );
		$warnings = array();

		if ( '' !== $ipconfig ) {
			$found = 1 === preg_match( '/(?:^|,)ip=([0-9.]+)(?:\/\d+)?(?:,|$)/', $ipconfig, $m ) ? $m[1] : '';

			if ( $found !== $ip ) {
				return self::error( sprintf( 'The VM\'s ipconfig0 (%1$s) does not give %2$s.', $ipconfig, $ip ) );
			}
		} else {
			$warnings[] = 'The VM has no ipconfig0, so its address could not be checked; make sure --ip is right.';
		}

		$description = (string) ( $config['description'] ?? '' );

		foreach ( (array) preg_split( '/\R/', $description ) as $line ) {
			if ( 0 === strpos( trim( (string) $line ), 'beltoft-services:' ) ) {
				return self::error( 'The VM already carries a beltoft-services tag; it may belong to another service.' );
			}
		}

		// 3. Bookkeeping, then the writes.
		$resource = Resource::create(
			$license,
			$driver,
			$plan,
			array(
				'state'      => 'ready',
				'desired'    => 'ready',
				'protected'  => true,
				'linked'     => true,
				'plan_dirty' => false,
				'steps'      => self::STEPS,
				'notified'   => 'ready',
				'ip'         => $ip,
				'node'       => $node,
				'vmid'       => $vmid,
				'vm'         => array(
					'cluster' => $cluster_id,
					'vmid'    => $vmid,
					'node'    => $node,
				),
			)
		);

		if ( is_wp_error( $resource ) ) {
			return self::error( $resource->get_error_message() );
		}

		if ( 'ready' !== $resource->get( 'state' ) || true !== $resource->get( 'linked' ) ) {
			return self::error( 'The license got a resource in the meantime; nothing was linked.' );
		}

		$problem = self::record( $resource, $cluster_id, $node, $vmid, $ip, $outside );

		if ( null !== $problem ) {
			return self::undo( $resource, $problem );
		}

		$tag_line = $tag . $resource->get( 'uuid' );
		$new      = '' === trim( $description ) ? $tag_line : rtrim( $description ) . "\n" . $tag_line;

		if ( strlen( $new ) > 8192 ) {
			return self::undo( $resource, 'The description would exceed 8192 characters.' );
		}

		$written = null;

		for ( $try = 1; $try <= 2; $try++ ) {
			$written = $client->request(
				'PUT',
				$base,
				array(
					'description' => $new,
					'digest'      => (string) ( $config['digest'] ?? '' ),
				)
			);

			if ( ! is_wp_error( $written ) || 2 === $try || 1 !== preg_match( '/digest|modified configuration/i', $written->get_error_message() ) ) {
				break;
			}

			// Somebody changed the config between our read and our write: read it again, once, and rebuild the text.
			$config = Ownership::config( $client, $node, $vmid );

			if ( is_wp_error( $config ) ) {
				return self::undo( $resource, 'Could not read the VM config again: ' . $config->get_error_message() );
			}

			$description = (string) ( $config['description'] ?? '' );
			$new         = '' === trim( $description ) ? $tag_line : rtrim( $description ) . "\n" . $tag_line;
		}

		if ( is_wp_error( $written ) ) {
			return self::undo( $resource, 'The tag could not be written: ' . $written->get_error_message() );
		}

		// 4. The pool: only a machine in no pool, never moved.
		$in_pool = (string) ( $entry['pool'] ?? '' );

		if ( '' !== $pool && '' === $in_pool ) {
			$answer = $client->request( 'PUT', $pools, 'pools_id' === $cluster['pool_api'] ? array( 'vms' => (string) $vmid ) : array( 'poolid' => $pool, 'vms' => (string) $vmid ) );

			if ( is_wp_error( $answer ) ) {
				$warnings[] = 'The tag is written, but the VM could not be added to the pool: ' . $answer->get_error_message();
			}
		} elseif ( '' !== $pool && $in_pool !== $pool ) {
			$warnings[] = sprintf( 'The VM is in pool "%s"; it was not moved.', $in_pool );
		}

		Events::add( (int) $license['id'], 'resource_linked', sprintf( 'Linked to existing VM %1$d on %2$s by WP-CLI (address %3$s).', $vmid, $cluster_id, $ip ) );

		return array(
			'lines'    => array( sprintf( 'Linked license %1$d to VM %2$d on %3$s. Nothing else was changed on the machine.', (int) $license['id'], $vmid, $cluster_id ) ),
			'warnings' => $warnings,
			'linked'   => true,
		);
	}

	/**
	 * The VM row, and the pool address (unless outside the pool).
	 *
	 * @param Resource $r          Resource.
	 * @param string   $cluster_id Cluster.
	 * @param string   $node       Node.
	 * @param int      $vmid       VMID.
	 * @param string   $ip         Address.
	 * @param bool     $outside    Address is not in the pool.
	 * @return string|null Problem, or null.
	 */
	private static function record( Resource $r, string $cluster_id, string $node, int $vmid, string $ip, bool $outside ): ?string {
		try {
			if ( ! VmRecords::adopt( $r->id(), $cluster_id, $node, $vmid ) ) {
				return sprintf( 'VMID %d could not be recorded (another service has it).', $vmid );
			}

			if ( $outside ) {
				return null;
			}

			$row = IpPool::find( $ip );

			if ( ! IpPool::take( $ip, $r->id() ) || null === $row || ! VmRecords::set_ip( $r->id(), $row['id'] ) ) {
				return sprintf( '%s could not be taken from the pool.', $ip );
			}
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}

		return null;
	}

	/**
	 * Takes the bookkeeping back after a failure: address, VM row, resource.
	 *
	 * @param Resource $r       Resource.
	 * @param string   $message Why.
	 * @return \WP_Error
	 */
	private static function undo( Resource $r, string $message ): \WP_Error {
		IpPool::release( $r->id() );
		VmRecords::remove( $r->id() );
		$r->close();

		return self::error( $message . ' Nothing was linked.' );
	}

	/**
	 * Error.
	 *
	 * @param string $message Message.
	 */
	private static function error( string $message ): \WP_Error {
		return new \WP_Error( 'link_vm', $message );
	}
}
