<?php
/**
 * The anti-spoofing ipfilter ipsets of a server firewall (ipfilter-net0 and its private twin).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Part of Firewall (used by it only): shares its client, write budget and result classification.
 */
trait FirewallIpfilter {

	/**
	 * Makes the ipfilter-net sets hold the server's own address (and the private one on net1) and read them back;
	 * ipfilter is only turned on when they are right, because a wrong set cuts all outgoing traffic.
	 *
	 * @param string               $base Firewall path.
	 * @param array<string, mixed> $v    Verified VM.
	 * @return bool|StepResult True when the sets are right.
	 */
	private function ipfilter_sets( string $base, array $v ) {
		$sets = self::expected_ipsets( $this->r, self::has_private_nic( $v ) );

		if ( null === $sets ) {
			return false;
		}

		$list = $this->read_list( $base . '/ipset' );

		if ( is_wp_error( $list ) ) {
			return $this->stop( $list );
		}

		$names = array_column( $list, 'name' );

		foreach ( $sets as $name => $address ) {
			if ( ! in_array( $name, $names, true ) ) {
				$answer = $this->write( 'POST', $base . '/ipset', array( 'name' => $name ) );

				if ( is_wp_error( $answer ) ) {
					return $this->stop( $answer );
				}
			}

			$entries = $this->read_list( $base . '/ipset/' . $name );

			if ( is_wp_error( $entries ) ) {
				return $this->stop( $entries );
			}

			$has = false;

			foreach ( $entries as $entry ) {
				$cidr = (string) ( $entry['cidr'] ?? '' );

				if ( $cidr === $address || $cidr === $address . '/32' ) {
					$has = true;
					continue;
				}

				$answer = $this->write( 'DELETE', $base . '/ipset/' . $name . '/' . $cidr );

				if ( is_wp_error( $answer ) ) {
					return $this->stop( $answer );
				}
			}

			if ( ! $has ) {
				$answer = $this->write( 'POST', $base . '/ipset/' . $name, array( 'cidr' => $address ) );

				if ( is_wp_error( $answer ) ) {
					return $this->stop( $answer );
				}
			}

			$entries = $this->read_list( $base . '/ipset/' . $name );

			if ( is_wp_error( $entries ) ) {
				return $this->stop( $entries );
			}

			$cidrs = array_map( static fn( $e ) => (string) ( $e['cidr'] ?? '' ), $entries );

			if ( array( $address ) !== $cidrs && array( $address . '/32' ) !== $cidrs ) {
				return false;
			}
		}

		// A private NIC that is gone leaves no allowed private address behind.
		if ( ! isset( $sets['ipfilter-net1'] ) && in_array( 'ipfilter-net1', $names, true ) ) {
			$stale = $this->drop_ipset( $base, 'ipfilter-net1' );

			if ( $stale instanceof StepResult ) {
				return $stale;
			}
		}

		// A public NIC that is gone (a database that gave its public IP back) leaves no allowed address behind.
		if ( ! isset( $sets['ipfilter-net0'] ) && in_array( 'ipfilter-net0', $names, true ) ) {
			$stale = $this->drop_ipset( $base, 'ipfilter-net0' );

			if ( $stale instanceof StepResult ) {
				return $stale;
			}
		}

		return true;
	}

	/**
	 * The ipfilter sets a server should have: the public address in ipfilter-net0 when its profile has a public
	 * address (null while that address is not known), and the private one in ipfilter-net1 when it has a private NIC.
	 * A server with neither gets no filter (null). A server whose profile has no public address (a managed
	 * database) is filtered on net1 alone.
	 *
	 * @param Resource $r           Resource.
	 * @param bool     $private_nic Whether the VM has a private NIC (net1).
	 * @return array<string, string>|null Set name => address.
	 */
	public static function expected_ipsets( Resource $r, bool $private_nic ): ?array {
		$sets = array();

		if ( Profiles::for_resource( $r )->uses_public_ip() ) {
			$ip = (string) $r->get( 'ip' );

			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return null;
			}

			$sets['ipfilter-net0'] = $ip;
		}

		if ( $private_nic ) {
			$private = PrivateNets::address_for_resource( $r->id() );

			if ( null === $private ) {
				return null;
			}

			$sets['ipfilter-net1'] = $private;
		}

		return $sets ? $sets : null;
	}

	/**
	 * Empties and deletes one ipset (Proxmox deletes only an empty set).
	 *
	 * @param string $base Firewall path.
	 * @param string $name Set name.
	 * @return true|StepResult
	 */
	private function drop_ipset( string $base, string $name ) {
		$entries = $this->read_list( $base . '/ipset/' . $name );

		if ( is_wp_error( $entries ) ) {
			return $this->stop( $entries );
		}

		foreach ( $entries as $entry ) {
			$answer = $this->write( 'DELETE', $base . '/ipset/' . $name . '/' . (string) ( $entry['cidr'] ?? '' ) );

			if ( is_wp_error( $answer ) ) {
				return $this->stop( $answer );
			}
		}

		$answer = $this->write( 'DELETE', $base . '/ipset/' . $name );

		return is_wp_error( $answer ) ? $this->stop( $answer ) : true;
	}

	/**
	 * Keeps the ipfilter sets right after the private NIC came or went, for a server whose filter is already on
	 * (the filter off, or the cluster flag off: nothing to do). The private address is in the set before the server
	 * starts with the card; after a detach the set is gone.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return true|StepResult
	 */
	public function ensure_ipfilter( array $record, array $v ) {
		if ( empty( $this->cluster['fw_ipfilter'] ) ) {
			return true;
		}

		$base    = self::base( $record, $v );
		$options = $this->read_list( $base . '/options' );

		if ( is_wp_error( $options ) ) {
			return $this->stop( $options );
		}

		if ( 1 !== (int) ( $options['ipfilter'] ?? 0 ) ) {
			return true;
		}

		$ready = $this->ipfilter_sets( $base, $v );

		if ( $ready instanceof StepResult ) {
			return $ready;
		}

		return true === $ready ? true : StepResult::retry( 0, 'firewall: the ipfilter sets could not be confirmed' );
	}
}
