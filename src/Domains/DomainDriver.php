<?php
/** Domain registration and transfer lifecycle. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Alerts;
use BeltoftServices\Crypto;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\InternalActions;
use BeltoftServices\Drivers\NeedsBilling;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Drivers\StopsOnDelete;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class DomainDriver implements Driver, StopsOnDelete, InternalActions, NeedsBilling {
	public function id(): string { return 'domain'; }
	public function label(): string { return __( 'Domain name', 'beltoft-services' ); }
	public function kind(): string { return 'domain'; }
	public function unit_label( int $used, int $max ): string {
		/* translators: %d: number of domains. */
		return sprintf( _n( '%d domain', '%d domains', $max, 'beltoft-services' ), $used );
	}
	public function plan_fields(): array { return array(); }
	public function internal_actions(): array { return array( 'renew', 'transfer_renew' ); }

	public function provision( Resource $r ): StepResult {
		$name = (string) $r->get( 'domain' );
		$mode = (string) $r->get( 'mode' );
		if ( is_wp_error( Search::normalise( $name ) ) || ! in_array( $mode, array( 'register', 'transfer' ), true ) ) {
			return StepResult::fail( 'The order did not contain a valid domain.' );
		}
		$nameservers = Zone::nameservers();
		if ( count( $nameservers ) < 2 ) { return StepResult::retry( 3600, 'Configure at least two domain name servers.' ); }
		$steps = $r->get( 'steps' );
		if ( ! in_array( 'contact', $steps, true ) ) {
			$license = $r->license();
			$order = wc_get_order( (int) ( $license['order_id'] ?? 0 ) );
			if ( ! $order ) { return StepResult::retry( 3600, 'The domain order is unavailable.' ); }
			$handle = Contacts::handle_for( (int) ( $license['user_id'] ?? 0 ), $order );
			if ( is_wp_error( $handle ) ) { return $this->error( $r, $handle, $steps ); }
			$steps[] = 'contact';
			if ( ! $r->set( array( 'contact_handle' => $handle, 'steps' => $steps ) ) ) { return StepResult::retry( 3600, 'Registrant contact could not be saved.' ); }
		}
		if ( ! in_array( 'order', $steps, true ) ) {
			if ( ! in_array( 'zone_prepared', $steps, true ) ) {
				$prepared = Zone::prepare( $r );
				if ( ! $prepared->is_done() ) { return $prepared; }
				$steps[] = 'zone_prepared';
				if ( ! $r->set( array_merge( $prepared->meta(), array( 'steps' => $steps ) ) ) ) { return StepResult::retry( 3600, 'DNS status could not be saved.' ); }
			}
			$client = Clients::registrar();
			$found = $client->request( 'GET', '/domains', array(), array( 'full_name' => $name, 'limit' => 10 ) );
			if ( is_wp_error( $found ) ) { return StepResult::retry( 3600, 'The registrar could not check for an earlier order.' ); }
			$existing = null;
			foreach ( (array) ( $found['results'] ?? array() ) as $item ) {
				if ( ! is_array( $item ) || empty( $item['id'] ) ) { continue; }
				$full = $item['full_name'] ?? ( ( $item['domain']['name'] ?? '' ) . '.' . ( $item['domain']['extension'] ?? '' ) );
				if ( strtolower( (string) $full ) === $name ) { $existing = $item; break; }
			}
			if ( $existing && isset( $existing['owner_handle'] ) && $existing['owner_handle'] !== $r->get( 'contact_handle' ) ) {
				return StepResult::fail( 'This domain is held by a different registrant.' );
			}
			$ordered = $existing;
			if ( ! $ordered ) {
				$parts = explode( '.', $name, 2 );
				$body = array(
					'domain' => array( 'name' => $parts[0], 'extension' => $parts[1] ),
					'period' => 1,
					'owner_handle' => (string) $r->get( 'contact_handle' ),
					'admin_handle' => (string) $r->get( 'contact_handle' ),
					'tech_handle' => (string) $r->get( 'contact_handle' ),
					'billing_handle' => (string) $r->get( 'contact_handle' ),
					'ns_group' => '',
					'name_servers' => array_map( static fn( $host ) => array( 'name' => $host ), $nameservers ),
					'autorenew' => 'off',
					'is_dnssec_enabled' => false,
				);
				if ( 'transfer' === $mode ) {
					$auth = Crypto::decrypt( (string) $r->get( 'auth' ) );
					if ( null === $auth || '' === $auth ) { return StepResult::fail( 'The transfer code is unavailable. Contact support.' ); }
					$body['auth_code'] = $auth;
				}
				$ordered = $client->request( 'POST', 'transfer' === $mode ? '/domains/transfer' : '/domains', $body );
				if ( is_wp_error( $ordered ) ) { return $this->error( $r, $ordered, $steps ); }
			}
			$remote_id = (string) ( $ordered['id'] ?? '' );
			if ( '' === $remote_id || ! ctype_digit( $remote_id ) ) { return StepResult::retry( 3600, 'Registrar did not return a domain id.' ); }
			$steps[] = 'order';
			$meta = array( 'remote_id' => $remote_id, 'steps' => $steps, 'ordered_at' => time() );
			if ( 'transfer' === $mode ) { $meta['transfer_expiry_before'] = (string) ( $ordered['expiration_date'] ?? '' ); }
			if ( ! $r->set( $meta ) ) { return StepResult::retry( 3600, 'The registrar order id could not be saved.' ); }
		}
		if ( ! in_array( 'active', $steps, true ) ) {
			$detail = Clients::registrar()->request( 'GET', '/domains/' . $r->get( 'remote_id' ) );
			if ( is_wp_error( $detail ) ) { return $this->error( $r, $detail, $steps ); }
			if ( 'ACT' !== ( $detail['status'] ?? '' ) ) {
				$since = (int) ( $r->get( 'pending_since' ) ?: time() );
				if ( time() - $since > 14 * DAY_IN_SECONDS ) { return StepResult::fail( 'The registrar did not complete this domain within 14 days.' ); }
				$reason = 'transfer' === $mode ? 'transfer_approval' : ( str_ends_with( $name, '.dk' ) ? 'registrant_check' : '' );
				if ( '' !== $reason && $reason !== $r->get( 'action_needed_sent' ) ) {
					$r->set( array( 'action_needed_sent' => $reason ) );
					Emails::send( 'beltoft_services_domain_action_needed', $r, array( 'domain_action_reason' => $reason ) );
				}
				return StepResult::wait( 3600, 'The registry is still processing this domain.', $since + 14 * DAY_IN_SECONDS, array( 'pending_since' => $since ) );
			}
			$expiry = (string) ( $detail['expiration_date'] ?? '' );
			$steps[] = 'active';
			if ( ! $r->set( array( 'steps' => $steps, 'expires_at' => $expiry, 'registrar_status' => 'ACT' ) ) ) { return StepResult::retry( 3600, 'Domain status could not be saved.' ); }
		}
		if ( 'transfer' === $mode && ! in_array( 'transfer_year', $steps, true ) ) {
			$year = Renewals::transfer_year( $r );
			if ( ! $year->is_done() ) { return $year; }
			$steps[] = 'transfer_year';
			if ( ! $r->set( array_merge( $year->meta(), array( 'steps' => $steps ) ) ) ) { return StepResult::retry( 3600, 'Transfer renewal status could not be saved.' ); }
		}
		if ( ! in_array( 'zone', $steps, true ) ) {
			$zone = Zone::ensure( $r );
			if ( ! $zone->is_done() ) { return $zone; }
			$steps[] = 'zone';
			if ( ! $r->set( array_merge( $zone->meta(), array( 'steps' => $steps ) ) ) ) { return StepResult::retry( 3600, 'DNS status could not be saved.' ); }
		}
		if ( ! in_array( 'dates', $steps, true ) ) {
			$dates = Renewals::set_dates( $r );
			if ( ! $dates->is_done() ) { return $dates; }
			$steps[] = 'dates';
			if ( ! $r->set( array_merge( $dates->meta(), array( 'steps' => $steps ) ) ) ) { return StepResult::retry( 3600, 'Billing dates could not be saved.' ); }
		}
		if ( 'register' === $mode && ! in_array( 'lock', $steps, true ) ) {
			// A new domain starts locked against transfer, as at other registrars; a refusal must not fail a finished order.
			$locked = Clients::registrar()->request( 'PUT', '/domains/' . $r->get( 'remote_id' ), array( 'is_locked' => true ) );
			$steps[] = 'lock';
			if ( ! $r->set( array( 'steps' => $steps, 'is_locked' => ! is_wp_error( $locked ) ) ) ) { return StepResult::retry( 3600, 'Transfer lock could not be saved.' ); }
		}
		return StepResult::done( array( 'steps' => $steps, 'remote_id' => $r->get( 'remote_id' ), 'expires_at' => $r->get( 'expires_at' ) ), 'Domain active' );
	}

	private function error( Resource $r, \WP_Error $error, array $steps ): StepResult {
		$code = $error->get_error_code();
		if ( 'op_920' === $code ) {
			if ( ! $r->get( 'balance_alerted' ) ) { Alerts::admin( $r, 'Registrar balance is too low.' ); }
			return StepResult::retry( 3600, 'Waiting for registrar account funds.', array( 'steps' => $steps, 'balance_alerted' => true ) );
		}
		if ( 'contact_incomplete' === $code ) {
			// Fixable: the billing details on the order can be completed, and the next attempt reads them again.
			if ( ! $r->get( 'billing_alerted' ) ) { Alerts::admin( $r, 'The order has incomplete billing details; add them to the order.' ); }
			return StepResult::retry( 3600, 'Waiting for complete billing details.', array( 'steps' => $steps, 'billing_alerted' => true ) );
		}
		if ( 'transport' === $code || 'bad_response' === $code || preg_match( '/^http_5/', $code ) ) { return StepResult::retry( 3600, 'The registrar is temporarily unavailable.', array( 'steps' => $steps ) ); }
		$data = $error->get_error_data();
		$desc = is_array( $data ) ? (string) ( $data['desc'] ?? '' ) : '';
		$why  = $code . ( '' !== $desc ? ' (' . $desc . ')' : '' );
		Alerts::admin( $r, 'Registrar rejected the domain order: ' . $why );
		return StepResult::fail( 'This domain order needs staff attention. Registrar answer: ' . $why, array( 'steps' => $steps ) );
	}

	public function pause( Resource $r ): StepResult { return StepResult::done(); }
	public function resume( Resource $r ): StepResult { return StepResult::done(); }
	public function update_plan( Resource $r ): StepResult { return StepResult::done(); }
	public function delete( Resource $r ): StepResult { return Zone::remove( $r ); }
	public function status( Resource $r ): ?array { return array( 'domain' => $r->get( 'domain' ), 'status' => $r->get( 'registrar_status' ), 'expires_at' => $r->get( 'expires_at' ) ); }
	public function customer_actions( Resource $r ): array { return Actions::offered( $r ); }
	public function act( Resource $r, string $action, array $args ): StepResult {
		if ( 'renew' === $action ) { return Renewals::renew( $r, (string) ( $args['order'] ?? '' ) ); }
		if ( 'transfer_renew' === $action ) { return Renewals::transfer_year( $r ); }
		return Actions::run( $r, $action, $args );
	}
	public function render_account( Resource $r ): void { Panels::account( $r ); }
	public function render_admin( Resource $r ): void { Panels::admin( $r ); }
}
