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
		if ( ! in_array( 'claim', $steps, true ) ) {
			$owner = Zone::owner( $r );
			if ( 'other' === $owner ) {
				if ( ! $r->get( 'zone_conflict_alerted' ) ) { Alerts::admin( $r, 'DNS zone belongs to a different customer.' ); }
				return StepResult::fail( 'This domain is already hosted.', array( 'zone_conflict_alerted' => true ) );
			}
			if ( 'domain' === $owner ) { return StepResult::fail( 'This domain is already registered here.' ); }
			$zone = Clients::dns()->zone( $name );
			if ( is_wp_error( $zone ) ) { return StepResult::retry( 3600, 'DNS zone could not be checked.' ); }
			if ( null !== $zone && 'dns' !== $owner && ! $r->get( 'zone_claimed' ) ) { return StepResult::retry( 3600, 'An existing zone needs staff review.' ); }
			$steps[] = 'claim';
			if ( ! $r->set( array( 'steps' => $steps, 'zone_claimed' => true ) ) ) { return StepResult::retry( 3600, 'DNS claim could not be saved.' ); }
		}
		if ( ! in_array( 'zone', $steps, true ) ) {
			$zone = Zone::ensure( $r );
			if ( ! $zone->is_done() ) { return $zone; }
			$steps[] = 'zone';
			if ( ! $r->set( array_merge( $zone->meta(), array( 'steps' => $steps ) ) ) ) { return StepResult::retry( 3600, 'DNS status could not be saved.' ); }
		}
		if ( ! in_array( 'delegation', $steps, true ) ) {
			Delegation::refresh( $r );
			$steps[] = 'delegation';
			if ( ! $r->set( array( 'steps' => $steps ) ) ) { return StepResult::retry( 3600, 'Delegation status could not be saved.' ); }
		}
		return StepResult::done( array( 'steps' => $steps, 'domain' => $name ), 'DNS zone ready' );
	}

	public function pause( Resource $r ): StepResult { return StepResult::done(); }
	public function resume( Resource $r ): StepResult { return StepResult::done(); }
	public function update_plan( Resource $r ): StepResult { return StepResult::done(); }
	public function delete( Resource $r ): StepResult { return Zone::remove( $r ); }
	public function status( Resource $r ): ?array { return array( 'domain' => $r->get( 'domain' ), 'delegation' => $r->get( 'delegation' ) ); }
	public function customer_actions( Resource $r ): array { return Actions::offered( $r ); }
	public function act( Resource $r, string $action, array $args ): StepResult { return Actions::run( $r, $action, $args ); }
	public function render_account( Resource $r ): void { Panels::account( $r ); }
	public function render_admin( Resource $r ): void { Panels::admin( $r ); }
}
