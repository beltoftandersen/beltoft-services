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
			// The extension's rules are tested on the registrant behind the handle that is sent, not on what the order says now.
			$eligible = Contacts::eligible( $name, Contacts::details_of( (int) ( $license['user_id'] ?? 0 ), $handle ) );
			if ( is_wp_error( $eligible ) ) {
				Alerts::admin( $r, $eligible->get_error_message() );
				return StepResult::fail( $eligible->get_error_message(), array( 'steps' => $steps ) );
			}
			$steps[] = 'contact';
			if ( ! $r->set( array( 'contact_handle' => $handle, 'steps' => $steps ) ) ) { return StepResult::retry( 3600, 'Registrant contact could not be saved.' ); }
		}
		if ( ! in_array( 'order', $steps, true ) ) {
			if ( ! in_array( 'zone_prepared', $steps, true ) && 'transfer' === $mode && self::keeps_dns( $r ) ) {
				// Our DNS does not serve this domain: it keeps the name servers it has and no zone is made here.
				$kept = $this->current_nameservers( $r );
				if ( ! $kept->is_done() ) { return $kept; }
				$steps[] = 'zone_prepared';
				if ( ! $r->set( array_merge( $kept->meta(), array( 'steps' => $steps ) ) ) ) { return StepResult::retry( 3600, 'DNS status could not be saved.' ); }
			}
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
			// Fail closed: a domain already in our account continues only when it names this order's registrant (a crash-resume).
			if ( $existing && ( ! isset( $existing['owner_handle'] ) || ! is_string( $existing['owner_handle'] ) || '' === $existing['owner_handle'] || $existing['owner_handle'] !== $r->get( 'contact_handle' ) ) ) {
				Alerts::admin( $r, 'This domain is held by a different registrant.' );
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
					'name_servers' => array_map( static fn( $host ) => array( 'name' => $host ), self::is_external( $r ) ? self::stored_nameservers( $r ) : $nameservers ),
					'autorenew' => 'off',
					'is_dnssec_enabled' => false,
				);
				if ( str_ends_with( $name, '.dk' ) ) {
					// The registry requires the registrant's acceptance of Punktum dk's terms with every .dk order, and the registrar fails an order without it.
					$order = wc_get_order( (int) ( $r->license()['order_id'] ?? 0 ) );
					if ( ! $order || ! $order->get_meta( '_beltoft_services_dk_agreement' ) ) { return StepResult::fail( 'The .dk agreement was not accepted on this order.', array( 'steps' => $steps ) ); }
					$body['additional_data'] = array( 'dk_acceptance' => 1 );
				}
				if ( 'transfer' === $mode ) {
					$auth = Crypto::decrypt( (string) $r->get( 'auth' ) );
					if ( null === $auth || '' === $auth ) { return StepResult::fail( 'The transfer code is unavailable. Contact support.' ); }
					$body['auth_code'] = $auth;
				}
				// Money is spent here: only when the paid line covers the registrar's cost. A resumed order (found above) never gets here.
				$blocked = SpendGuard::guard( $r, $name, 'transfer' === $mode ? 'transfer' : 'create', SpendGuard::item_of_resource( $r ) );
				if ( $blocked ) { return $blocked; }
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
		if ( ! in_array( 'zone', $steps, true ) && self::is_external( $r ) ) {
			// The DNS stays where it was: no zone, no DNSSEC and no DS here.
			$steps[] = 'zone';
			if ( ! $r->set( array( 'steps' => $steps, 'dns_external' => true ) ) ) { return StepResult::retry( 3600, 'DNS status could not be saved.' ); }
		}
		if ( ! in_array( 'zone', $steps, true ) ) {
			$user = (int) ( $r->license()['user_id'] ?? 0 );
			$held = DnsSchema::ready() ? DnsAssignments::get( $name ) : null;
			if ( null !== $held && (int) $held['user_id'] !== $user ) {
				if ( ! $r->get( 'zone_conflict_alerted' ) ) { Alerts::admin( $r, 'Domain zone belongs to a different customer.' ); $r->set( array( 'zone_conflict_alerted' => true ) ); }
				return StepResult::retry( 3600, 'A domain zone is assigned to another customer.' );
			}
			$zone = Zone::ensure( $r );
			if ( ! $zone->is_done() ) { return $zone; }
			// The same customer's DNS-only service held this name: this domain takes over the zone and the DNS-only subscription ends.
			if ( null !== $held && 'active' === $held['status'] && (int) $held['resource_id'] !== $r->id() ) {
				$adopted = $this->adopt( $r, $held, $user );
				if ( ! $adopted ) { return StepResult::retry( 3600, 'The DNS hosting could not be handed over.' ); }
			}
			// The cancelled DNS-only service switched the records off; they are the domain's now and must answer.
			if ( $r->get( 'adopt_enable' ) ) {
				$on = Records::set_disabled( $name, false );
				if ( is_wp_error( $on ) && 'zone_missing' !== $on->get_error_code() ) { return StepResult::retry( 3600, 'The DNS records could not be switched on.' ); }
				$r->set( array( 'adopt_enable' => false ) );
			}
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

	/** The name servers stored before a transfer order, as a list of host names. */
	public static function stored_nameservers( Resource $r ): array {
		$stored = $r->get( 'transfer_ns' );
		return is_array( $stored ) ? array_values( array_filter( array_map( 'strval', $stored ) ) ) : array();
	}

	/** A transfer that keeps the domain's current name servers: stored before the order, so a retry reuses them. */
	public static function is_external( Resource $r ): bool {
		return 'transfer' === $r->get( 'mode' ) && array() !== self::stored_nameservers( $r );
	}

	/** Does this transfer leave the DNS where it is? Not when our DNS already serves the domain for this customer. */
	private static function keeps_dns( Resource $r ): bool {
		if ( array() !== self::stored_nameservers( $r ) ) { return true; }
		return ! Zone::served_for( strtolower( (string) $r->get( 'domain' ) ), (int) ( $r->license()['user_id'] ?? 0 ) );
	}

	/** Reads the delegation at the parent zone and stores it. Our own name servers are never a fallback. */
	private function current_nameservers( Resource $r ): StepResult {
		$name = (string) $r->get( 'domain' );
		if ( array() !== self::stored_nameservers( $r ) ) { return StepResult::done(); }
		$owner = Zone::owner( $r );
		if ( 'other' === $owner ) {
			if ( ! $r->get( 'zone_conflict_alerted' ) ) { Alerts::admin( $r, 'Domain zone belongs to a different customer.' ); $r->set( array( 'zone_conflict_alerted' => true ) ); }
			return StepResult::retry( 3600, 'A domain zone is assigned to another customer.' );
		}
		if ( 'domain' === $owner ) { return StepResult::fail( 'This domain is already registered here.' ); }
		$found  = Delegation::lookup( $name );
		$status = (string) ( $found['status'] ?? '' );
		$hosts  = array();
		$invalid = false;
		foreach ( (array) ( $found['ns'] ?? array() ) as $host ) {
			$host = strtolower( rtrim( trim( (string) $host ), '.' ) );
			if ( '' === $host || is_wp_error( Search::normalise( $host ) ) ) { $invalid = true; continue; }
			$hosts[ $host ] = $host;
		}
		$hosts = array_values( $hosts );
		$ours  = ! empty( $found['ours'] ) || array() !== array_intersect( $hosts, Zone::nameservers() );
		if ( $ours ) {
			if ( ! $r->get( 'transfer_ns_alerted' ) ) { Alerts::admin( $r, 'The domain is delegated to our name servers but no DNS service of this customer serves it. Staff review needed before the transfer.' ); $r->set( array( 'transfer_ns_alerted' => true ) ); }
			return StepResult::retry( 3600, 'The current name servers include our own. Staff review needed before the transfer.' );
		}
		if ( 'ok' === $status && ( $invalid || count( $hosts ) < 2 ) ) {
			// A partial list could break the domain: never sent.
			if ( ! $r->get( 'transfer_ns_alerted' ) ) { Alerts::admin( $r, 'The current name servers of the domain are not usable for a transfer (fewer than two, or an invalid name). Staff review needed.' ); $r->set( array( 'transfer_ns_alerted' => true ) ); }
			return StepResult::retry( 3600, 'The delegated name servers are fewer than two or include an invalid name. Staff review needed before the transfer.' );
		}
		if ( 'ok' !== $status || array() === $hosts || count( $hosts ) > ProofResolver::MAX_NS ) {
			return StepResult::retry( 3600, 'The current name servers of the domain could not be read (' . ( '' !== (string) ( $found['reason'] ?? '' ) ? (string) $found['reason'] : 'none found' ) . '). Trying again later.' );
		}
		return StepResult::done( array( 'transfer_ns' => $hosts ) );
	}

	/** Moves the assignment to this domain resource and ends the DNS-only subscription when it holds nothing else. The cancel comes first, so a failed move is retried with the old row still in place. */
	private function adopt( Resource $r, array $held, int $user ): bool {
		$old = Resource::find( (int) $held['resource_id'] );
		if ( $old && 'dns' === $old->get( 'driver' ) && function_exists( 'wcs_get_subscription' ) ) {
			$subscription = wcs_get_subscription( (int) ( $old->license()['subscription_id'] ?? 0 ) );
			if ( $subscription && ! $subscription->has_status( 'cancelled' ) ) {
				if ( $this->is_dns_line_of( $subscription, $old, (string) $held['domain'] ) ) {
					// Marked first: the cancel ends the license, and the ending of a folded service is silent and leaves the zone alone.
					if ( ! $old->set( array( 'folded_into' => $r->id() ) ) ) { return false; }
					$subscription->update_meta_data( DnsCancel::FOLDED_META, $r->id() );
					$subscription->save();
					$subscription->update_status( 'cancelled', sprintf( 'DNS hosting for %s moved into the domain registration. No further charge.', (string) $held['domain'] ) );
				} else {
					// Other DNS lines of this subscription may still be in use: leave it for staff.
					$subscription->add_order_note( 'The domain ' . $held['domain'] . ' is now registered here. This subscription has other lines or a line that could not be tied to this domain, so it was not cancelled.' );
					Alerts::admin( $r, 'A DNS-only subscription with several lines was not cancelled after its domain was registered here: subscription ' . $subscription->get_id() . '.' );
				}
			}
		}
		if ( ! DnsAssignments::move_to( (string) $held['domain'], $user, (int) ( $r->license()['id'] ?? 0 ), $r->id() ) ) { return false; }
		return $r->set( array( 'adopt_enable' => true ) );
	}

	/** The subscription holds exactly one line and it is the DNS-only line for this domain (by its domain choice or by the old service's license). */
	private function is_dns_line_of( $subscription, Resource $old, string $domain ): bool {
		$items = $subscription->get_items();
		if ( 1 !== count( $items ) ) { return false; }
		$item = reset( $items );
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) { return false; }
		$line = $item->get_meta( '_beltoft_services_domain', true );
		if ( is_array( $line ) && 'dns' === ( $line['mode'] ?? '' ) && strtolower( (string) ( $line['domain'] ?? '' ) ) === strtolower( $domain ) ) { return true; }
		$license_id = (int) ( $old->license()['id'] ?? 0 );
		return $license_id > 0 && (int) $item->get_meta( '_beltoft_services_domain_license', true ) === $license_id;
	}

	/** What staff read when the registrar could not be used for now: the code and HTTP status, and its short explanation. Never a URL, header or secret. */
	private static function unavailable_message( \WP_Error $error ): string {
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : array();
		$code   = 'transport' === $error->get_error_code() && '' !== (string) ( $data['transport_code'] ?? '' ) ? (string) $data['transport_code'] : (string) $error->get_error_code();
		$status = (int) ( $data['status'] ?? 0 );
		$desc   = mb_substr( sanitize_text_field( (string) ( $data['desc'] ?? '' ) ), 0, 200 );
		$detail = $code . ( $status > 0 ? ', HTTP ' . $status : '' ) . ( '' !== $desc ? ': ' . $desc : '' );
		return 'The registrar is temporarily unavailable (' . $detail . ').';
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
		$data = $error->get_error_data();
		$desc = is_array( $data ) ? (string) ( $data['desc'] ?? '' ) : '';
		if ( LiveClient::is_transient( $error ) ) { return StepResult::retry( 3600, self::unavailable_message( $error ), array( 'steps' => $steps ) ); }
		$why  = $code . ( '' !== $desc ? ' (' . $desc . ')' : '' );
		Alerts::admin( $r, 'Registrar rejected the domain order: ' . $why );
		return StepResult::fail( 'This domain order needs staff attention. Registrar answer: ' . $why, array( 'steps' => $steps ) );
	}

	public function pause( Resource $r ): StepResult { return StepResult::done(); }
	public function resume( Resource $r ): StepResult { return StepResult::done(); }
	public function update_plan( Resource $r ): StepResult { return StepResult::done(); }
	public function delete( Resource $r ): StepResult {
		$owner = Zone::owner( $r );
		$removed = Zone::remove( $r );
		if ( $removed->is_done() && ! in_array( $owner, array( 'domain', 'dns', 'other' ), true ) ) { DnsAssignments::release_pending_for( $r ); }
		return $removed;
	}
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
