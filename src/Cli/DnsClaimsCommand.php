<?php
/**
 * WP-CLI: staff tools for verified DNS hosting.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\Domains\Clients;
use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\Delegation;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Manage DNS hosting assignments and claims. Nothing here prints a claim's token.
 */
final class DnsClaimsCommand {

	private const FIELDS = array( 'domain', 'user_id', 'status', 'order_id', 'license_id', 'resource_id', 'updated_at' );

	/**
	 * Lists the assignments.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : reserved, active or release_pending. Default: all.
	 *
	 * [--format=<format>]
	 * : table, json, csv. Default table.
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$statuses = isset( $assoc_args['status'] ) ? array( (string) $assoc_args['status'] ) : array( 'reserved', 'active', 'release_pending' );
		$items    = array();

		foreach ( $statuses as $status ) {
			foreach ( DnsAssignments::for_status( $status ) as $row ) {
				$items[] = array_intersect_key( $row, array_flip( self::FIELDS ) );
			}
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, self::FIELDS );
	}

	/**
	 * Shows the assignment of a domain, the customer's claims for it and the live delegation.
	 *
	 * ## OPTIONS
	 *
	 * <domain>
	 * : The domain.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function show( array $args, array $assoc_args ): void {
		$domain = self::domain( $args );
		$row    = DnsAssignments::get( $domain );

		if ( ! $row ) {
			\WP_CLI::log( 'No assignment.' );
		} else {
			\WP_CLI::log( self::describe( $row ) );

			foreach ( DnsClaims::for_user( (int) $row['user_id'] ) as $claim ) {
				if ( strtolower( (string) $claim['domain'] ) === $domain ) {
					\WP_CLI::log( sprintf( 'Claim %d: %s, verified %s, expires %s', $claim['id'], $claim['status'], $claim['verified_at'] ?? '-', $claim['expires_at'] ) );
				}
			}
		}

		$result = Delegation::lookup( $domain );
		\WP_CLI::log( sprintf( 'Delegation: %s (%s), ours %s, parent DS %s, servers %s', $result['status'], $result['reason'], $result['ours'] ? 'yes' : 'no', null === $result['ds'] ? 'unknown' : ( $result['ds'] ? 'yes' : 'no' ), implode( ', ', $result['ns'] ) ) );
	}

	/**
	 * Staff override for a reviewed domain: reserves it for a customer for 7 days.
	 *
	 * ## OPTIONS
	 *
	 * <domain>
	 * : The domain.
	 *
	 * --user=<id>
	 * : The customer.
	 *
	 * [--yes]
	 * : Do it.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function assign( array $args, array $assoc_args ): void {
		$domain  = self::domain( $args );
		$user_id = (int) ( $assoc_args['user'] ?? 0 );

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			\WP_CLI::error( 'Give an existing customer with --user.' );
		}

		$row = DnsAssignments::get( $domain );
		\WP_CLI::log( $row ? 'Current: ' . self::describe( $row ) : 'Current: no assignment.' );
		$live = self::live_resources( $domain );

		if ( $live ) {
			\WP_CLI::error( 'A live service has this domain (resource ' . implode( ', ', $live ) . '). Nothing changed.' );
		}

		if ( empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( sprintf( 'Add --yes to reserve %s for user %d for 7 days.', $domain, $user_id ) );
		}

		$assigned = DnsAssignments::assign_reserved( $domain, $user_id );

		if ( is_wp_error( $assigned ) ) {
			\WP_CLI::error( $assigned->get_error_message() );
		}

		do_action( 'beltoft_services_dns_event', 'assign', $domain, $user_id );
		\WP_CLI::success( sprintf( 'Reserved %s for user %d for 7 days.', $domain, $user_id ) );
	}

	/**
	 * Deletes the assignment of a domain when no live service has it.
	 *
	 * ## OPTIONS
	 *
	 * <domain>
	 * : The domain.
	 *
	 * [--yes]
	 * : Do it.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function release( array $args, array $assoc_args ): void {
		$domain = self::domain( $args );
		$row    = DnsAssignments::get( $domain );

		if ( ! $row ) {
			\WP_CLI::error( 'No assignment for this domain.' );
		}

		\WP_CLI::log( 'Current: ' . self::describe( $row ) );
		$live = self::live_resources( $domain );

		if ( $live ) {
			\WP_CLI::error( 'A live service has this domain (resource ' . implode( ', ', $live ) . '). Nothing changed.' );
		}

		if ( empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( 'Add --yes to delete this assignment.' );
		}

		DnsAssignments::release( $domain );
		do_action( 'beltoft_services_dns_event', 'release', $domain, (int) $row['user_id'] );
		\WP_CLI::success( sprintf( 'Released %s.', $domain ) );
	}

	/**
	 * Approves an existing zone for a DNS hosting service that waits for staff review. The import step
	 * then replaces the zone's records with the customer's draft, so look at the zone first.
	 *
	 * ## OPTIONS
	 *
	 * <license-id>
	 * : The license of the DNS hosting service.
	 *
	 * [--yes]
	 * : Do it.
	 *
	 * @subcommand approve-zone
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function approve_zone( array $args, array $assoc_args ): void {
		$r = Resource::for_license( (int) ( $args[0] ?? 0 ) );

		if ( ! $r || 'dns' !== $r->get( 'driver' ) ) {
			\WP_CLI::error( 'This license has no DNS hosting service.' );
		}

		$domain  = strtolower( (string) $r->get( 'domain' ) );
		$user_id = (int) ( $r->license()['user_id'] ?? 0 );
		$row     = DnsAssignments::get( $domain );

		if ( ! $row || (int) $row['user_id'] !== $user_id ) {
			\WP_CLI::error( 'The assignment for this domain does not belong to the license holder. Nothing changed.' );
		}

		$zone = Clients::dns()->zone( $domain );

		if ( is_wp_error( $zone ) ) {
			\WP_CLI::error( 'The zone could not be read.' );
		}

		$count = 0;

		foreach ( is_array( $zone ) ? (array) ( $zone['rrsets'] ?? array() ) : array() as $set ) {
			$count += count( (array) ( $set['records'] ?? array() ) );
		}

		\WP_CLI::log( sprintf( 'Domain %s, %s.', $domain, is_array( $zone ) ? sprintf( 'existing zone with %d record(s)', $count ) : 'no zone yet' ) );

		if ( empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( 'Add --yes to approve. The import step then replaces the zone\'s records with the customer\'s draft.' );
		}

		$stored = $r->locked(
			static fn(): bool => $r->set(
				array(
					'zone_claimed'    => true,
					'op'              => '',
					'attempts'        => 0,
					'next_attempt_at' => null,
					'alerted'         => '',
				)
			)
		);

		if ( ! $stored ) {
			\WP_CLI::error( 'The service could not be updated.' );
		}

		Jobs::enqueue( $r->id() );
		do_action( 'beltoft_services_dns_event', 'approve_zone', $domain, $user_id );
		\WP_CLI::success( sprintf( 'Approved the zone of %s; the service is queued.', $domain ) );
	}

	/**
	 * The normalised domain argument.
	 *
	 * @param array<int, string> $args Positional arguments.
	 */
	private static function domain( array $args ): string {
		$domain = strtolower( rtrim( trim( (string) ( $args[0] ?? '' ) ), '.' ) );

		if ( '' === $domain ) {
			\WP_CLI::error( 'Give a domain.' );
		}

		return $domain;
	}

	/**
	 * One line about an assignment.
	 *
	 * @param array<string, mixed> $row Assignment.
	 */
	private static function describe( array $row ): string {
		return sprintf( '%s: %s, user %d, order %s, license %s, resource %s, updated %s UTC', $row['domain'], $row['status'], (int) $row['user_id'], $row['order_id'] ?? '-', $row['license_id'] ?? '-', $row['resource_id'] ?? '-', $row['updated_at'] );
	}

	/**
	 * Ids of the live DNS hosting and domain services that have the domain.
	 *
	 * @param string $domain Domain.
	 * @return int[]
	 */
	private static function live_resources( string $domain ): array {
		$found = array();

		foreach ( array( 'dns', 'domain' ) as $kind ) {
			foreach ( Resource::live_ids( $kind ) as $id ) {
				$other = Resource::find( $id );

				if ( $other && strtolower( (string) $other->get( 'domain' ) ) === $domain ) {
					$found[] = $id;
				}
			}
		}

		return $found;
	}
}
