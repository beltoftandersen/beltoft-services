<?php
/** Public domain details for the service ready email. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Emails\DomainSecurity;
use BeltoftServices\Emails\ServiceEmail;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class EmailDetails {
	public static function register(): void {
		add_filter( 'beltoft_services_email_details', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'beltoft_services_email_values', array( __CLASS__, 'values' ), 10, 3 );
	}
	public static function details( $details, $email_id, $r ) {
		if ( ! is_array( $details ) || 'beltoft_services_ready' !== $email_id || ! $r instanceof Resource || ! in_array( $r->get( 'driver' ), array( 'domain', 'dns' ), true ) ) { return $details; }
		$details[ __( 'Domain', 'beltoft-services' ) ] = (string) $r->get( 'domain' );
		// DNS hosting: the email's own text lists the name servers with what to do with them.
		if ( 'domain' === $r->get( 'driver' ) ) { $details[ __( 'Name servers', 'beltoft-services' ) ] = implode( ', ', Zone::nameservers() ); }
		return $details;
	}

	/**
	 * The domain and DNS values of a service email (beltoft_services_email_values): every key for every email, with
	 * its empty default.
	 *
	 * @param mixed $values Values so far.
	 * @param mixed $r      Resource.
	 * @param mixed $data   Trigger data.
	 * @return mixed
	 */
	public static function values( $values, $r, $data ) {
		if ( ! is_array( $values ) || ! $r instanceof Resource ) {
			return $values;
		}

		$data      = is_array( $data ) ? $data : array();
		$driver    = (string) $r->get( 'driver' );
		$license   = $r->license();
		$is_domain = 'domain' === $driver;
		$is_dns    = 'dns' === $driver;

		if ( $is_domain || $is_dns ) {
			$values['domain_name'] = HostName::display( (string) $r->get( 'domain' ) );
		}

		if ( $license && ( $is_domain || $is_dns ) ) {
			$values['account_url'] = self::domains_url( $r, $license );
		}

		$values['domain_action_reason']  = in_array( $data['domain_action_reason'] ?? '', array( 'registrant_check', 'transfer_approval', 'move_dns' ), true ) ? $data['domain_action_reason'] : 'transfer_approval';
		$values['transfer_fix_reason']   = in_array( $data['transfer_fix_reason'] ?? '', array( 'auth_refused', 'locked' ), true ) ? $data['transfer_fix_reason'] : 'locked';
		$values['domain_security_event'] = in_array( $data['domain_event'] ?? '', DomainSecurity::EVENTS, true ) ? $data['domain_event'] : 'unlocked';
		$values['dns_stays']             = $is_domain && Zone::served_for( strtolower( (string) $r->get( 'domain' ) ), (int) ( $license['user_id'] ?? 0 ) );
		$values['domain_renews']         = self::domain_renews( $r );
		$values['registrar_name']        = $is_domain ? TransferDns::registrar_name( (string) $r->get( 'domain' ) ) : '';
		$values['domains_url']           = self::domains_url( $r, $license );
		$values['dns_external']          = $is_domain && (bool) $r->get( 'dns_external' );
		$values['dns_choice']            = $is_domain && 'transfer' === $r->get( 'mode' ) ? TransferDns::choice( $r ) : '';
		$values['our_nameservers']       = $is_domain ? Zone::nameservers() : array();
		$values['nameservers_shown']     = $is_domain && 'custom' !== $r->get( 'ns_mode' ) && ! $r->get( 'dns_external' ) ? Zone::nameservers() : array();
		$values['dns_only']              = $is_dns;
		$values['nameservers']           = $is_dns ? Zone::nameservers() : array();

		return $values;
	}

	/**
	 * Where a domain email sends the customer: the domain's own page, or the list when there is no license or the service is deleted.
	 *
	 * @param Resource                  $r       Resource.
	 * @param array<string, mixed>|null $license License row.
	 */
	private static function domains_url( Resource $r, $license ): string {
		// A deleted service has no page: its email sends to the list.
		if ( is_array( $license ) && ! empty( $license['id'] ) && in_array( $r->get( 'driver' ), array( 'domain', 'dns' ), true ) && ! in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted' ), true ) ) {
			return \BeltoftServices\Account\Domains::url( $license );
		}

		return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'domains' ) : '';
	}

	/**
	 * The next renewal date of a domain, formatted, or ''. A prepaid transfer year counts from the
	 * prepaid expiry, as the billing dates do.
	 *
	 * @param Resource $r Resource.
	 */
	private static function domain_renews( Resource $r ): string {
		if ( 'domain' !== $r->get( 'driver' ) ) {
			return '';
		}

		$expiry = (string) ( $r->get( 'prepaid_expiry' ) ?: $r->get( 'expires_at' ) );
		$time   = '' !== $expiry ? strtotime( $expiry . ' UTC' ) : false;

		return $time ? wp_date( ServiceEmail::DATE, $time ) : '';
	}
}
