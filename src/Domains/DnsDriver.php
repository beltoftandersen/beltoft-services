<?php
/** Subscription resource for DNS hosted here. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Drivers\StopsOnDelete;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class DnsDriver implements Driver, StopsOnDelete {
	public function id(): string { return 'dns'; }
	public function label(): string { return __( 'DNS hosting', 'beltoft-services' ); }
	public function kind(): string { return 'dns'; }
	public function unit_label( int $used, int $max ): string {
		/* translators: %d: number of DNS zones. */
		return sprintf( _n( '%d zone', '%d zones', $max, 'beltoft-services' ), $used );
	}
	public function plan_fields(): array { return array(); }

	public function provision( Resource $r ): StepResult {
		$name = (string) $r->get( 'domain' );
		if ( is_wp_error( Search::normalise( $name ) ) || 'dns' !== $r->get( 'mode' ) ) { return StepResult::fail( 'The order did not contain a valid domain.' ); }
		if ( count( Zone::nameservers() ) < 2 ) { return StepResult::retry( 3600, 'Configure at least two domain name servers.' ); }
		$steps = (array) $r->get( 'steps' );
		if ( ! in_array( 'assignment', $steps, true ) ) {
			$user = (int) ( $r->license()['user_id'] ?? 0 );
			if ( ! DnsSchema::ready() ) { return StepResult::retry( 3600, 'DNS hosting storage is not ready.' ); }
			if ( ! $this->holds_name( $r, $name, $user ) ) {
				if ( ! $r->get( 'assignment_alerted' ) ) { Alerts::admin( $r, 'DNS hosting was ordered without a verified domain assignment.' ); }
				return StepResult::fail( 'DNS hosting needs a verified domain.', array( 'assignment_alerted' => true ) );
			}
			if ( ! DnsAssignments::activate( $name, $user, (int) ( $r->license()['id'] ?? 0 ), $r->id() ) ) { return StepResult::retry( 3600, 'The domain assignment could not be saved.' ); }
			$steps[] = 'assignment';
			if ( ! $r->set( array( 'steps' => $steps ) ) ) { return StepResult::retry( 3600, 'The domain assignment could not be saved.' ); }
		}
		if ( ! in_array( 'claim', $steps, true ) ) {
			$owner = Zone::owner( $r );
			if ( 'other' === $owner ) {
				if ( ! $r->get( 'zone_conflict_alerted' ) ) { Alerts::admin( $r, 'DNS zone belongs to a different customer.' ); }
				return StepResult::fail( 'This domain is already hosted.', array( 'zone_conflict_alerted' => true ) );
			}
			if ( 'domain' === $owner ) { return StepResult::fail( 'This domain is already registered here.' ); }
			$zone = Clients::dns()->zone( $name );
			if ( is_wp_error( $zone ) ) { return StepResult::retry( 3600, 'DNS zone could not be checked.' ); }
			// The assignment proves the customer holds the name, not who made a zone that is already there: staff look at it first.
			if ( null !== $zone && ! $r->get( 'zone_claimed' ) ) { return StepResult::retry( 3600, 'An existing zone needs staff review.' ); }
			$steps[] = 'claim';
			if ( ! $r->set( array( 'steps' => $steps, 'zone_claimed' => true ) ) ) { return StepResult::retry( 3600, 'DNS claim could not be saved.' ); }
		}
		if ( ! in_array( 'zone', $steps, true ) ) {
			$zone = Zone::ensure( $r );
			if ( ! $zone->is_done() ) { return $zone; }
			$steps[] = 'zone';
			if ( ! $r->set( array_merge( $zone->meta(), array( 'steps' => $steps ) ) ) ) { return StepResult::retry( 3600, 'DNS status could not be saved.' ); }
		}
		if ( ! in_array( 'import', $steps, true ) ) {
			$meta = array();
			$claim = DnsClaims::get( (int) $r->get( 'claim_id' ) );
			$draft = is_array( $claim ) && is_array( $claim['draft_records'] ?? null ) ? $claim['draft_records'] : array();
			if ( $draft ) {
				$checked = Records::validate( $name, $draft );
				if ( is_wp_error( $checked ) ) {
					$meta['import_error'] = $checked->get_error_message();
				} else {
					$imported = Records::replace( $name, $draft );
					if ( is_wp_error( $imported ) ) { return StepResult::retry( 3600, 'The DNS records could not be imported.' ); }
				}
			}
			$steps[] = 'import';
			if ( ! $r->set( array_merge( $meta, array( 'steps' => $steps ) ) ) ) { return StepResult::retry( 3600, 'The import status could not be saved.' ); }
		}
		if ( ! in_array( 'delegation', $steps, true ) ) {
			Delegation::refresh( $r );
			$steps[] = 'delegation';
			if ( ! $r->set( array( 'steps' => $steps ) ) ) { return StepResult::retry( 3600, 'Delegation status could not be saved.' ); }
		}
		return StepResult::done( array( 'steps' => $steps, 'domain' => $name ), 'DNS zone ready' );
	}

	/** The claim is the customer's, for this domain, and the assignment is theirs and bound to this order or already active for this service. */
	private function holds_name( Resource $r, string $name, int $user ): bool {
		$claim_id = (int) $r->get( 'claim_id' );
		if ( $claim_id <= 0 || $user <= 0 ) { return false; }
		$claim = DnsClaims::get( $claim_id );
		if ( ! $claim || (int) $claim['user_id'] !== $user || $claim['domain'] !== $name ) { return false; }
		$row = DnsAssignments::get( $name );
		if ( ! $row || (int) $row['user_id'] !== $user || (int) $row['claim_id'] !== $claim_id ) { return false; }
		if ( 'active' === $row['status'] ) { return (int) $row['resource_id'] === $r->id(); }
		return 'reserved' === $row['status'] && (int) $row['order_id'] > 0 && (int) $row['order_id'] === (int) ( $r->license()['order_id'] ?? 0 );
	}

	/** This resource is the active holder of its name. Otherwise the zone is not its to change (another service of the same customer, a registered domain, or another customer). */
	private function holds_row( Resource $r ): bool {
		if ( ! DnsSchema::ready() ) { return false; }
		$row = DnsAssignments::get( (string) $r->get( 'domain' ) );
		return null !== $row && 'active' === $row['status'] && (int) $row['resource_id'] === $r->id() && (int) $row['user_id'] === (int) ( $r->license()['user_id'] ?? 0 );
	}

	public function pause( Resource $r ): StepResult {
		if ( ! DnsSchema::ready() ) { return StepResult::retry( 3600, 'DNS hosting storage is not ready.' ); }
		if ( ! $this->holds_row( $r ) || 'domain' === Zone::owner( $r ) ) { return StepResult::done(); }
		$off = Records::set_disabled( (string) $r->get( 'domain' ), true );
		if ( is_wp_error( $off ) ) {
			if ( 'zone_missing' === $off->get_error_code() ) { return StepResult::done(); }
			return StepResult::retry( 3600, 'The DNS records could not be switched off.' );
		}
		return StepResult::done( array( 'records_off' => true ) );
	}
	public function resume( Resource $r ): StepResult {
		if ( ! DnsSchema::ready() ) { return StepResult::retry( 3600, 'DNS hosting storage is not ready.' ); }
		if ( ! $this->holds_row( $r ) ) { return StepResult::done(); }
		$on = Records::set_disabled( (string) $r->get( 'domain' ), false );
		if ( is_wp_error( $on ) && 'zone_missing' !== $on->get_error_code() ) { return StepResult::retry( 3600, 'The DNS records could not be switched on.' ); }
		return StepResult::done( array( 'records_off' => false ) );
	}
	public function update_plan( Resource $r ): StepResult { return StepResult::done(); }
	public function delete( Resource $r ): StepResult {
		$owner = Zone::owner( $r );
		$removed = Zone::remove( $r );
		// The name stays locked to the customer until the release job sees that the parent no longer lists our name servers.
		// When the same customer's domain resource keeps the zone, it holds the assignment and nothing is released.
		if ( $removed->is_done() && ! in_array( $owner, array( 'domain', 'dns', 'other' ), true ) ) { DnsAssignments::release_pending_for( $r ); }
		return $removed;
	}
	public function status( Resource $r ): ?array { return array( 'domain' => $r->get( 'domain' ), 'delegation' => $r->get( 'delegation' ) ); }
	public function customer_actions( Resource $r ): array { return Actions::offered( $r ); }
	public function act( Resource $r, string $action, array $args ): StepResult { return Actions::run( $r, $action, $args ); }
	public function render_account( Resource $r ): void { Panels::account( $r ); }
	public function render_admin( Resource $r ): void { Panels::admin( $r ); }
}
