<?php
/** What a customer or a job does to a certificate order after it was built. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftServices\Alerts;
use BeltoftServices\Domains\Clients;
use BeltoftServices\Domains\LiveClient;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class Operations {
	/** The word typed to cancel. */
	public const CONFIRM_CANCEL = 'CANCEL';

	/** Days before the end of the certificate at which a missing replacement is reported. */
	public const EXPIRY_WARNING_DAYS = 10;

	/**
	 * Is this resource a built certificate whose order the registrar knows?
	 *
	 * @param Resource $r Resource.
	 */
	public static function built( Resource $r ): bool {
		return '' !== $r->get( 'remote_id' ) && in_array( 'deliver', (array) $r->get( 'steps' ), true ) && ! $r->get( 'ssl_cancelled' );
	}

	/**
	 * May the order still be cancelled for a refund: before it was issued, or inside the refund window after the issue?
	 *
	 * @param Resource $r Resource.
	 */
	public static function cancellable( Resource $r ): bool {
		if ( '' === $r->get( 'remote_id' ) || $r->get( 'ssl_cancelled' ) || in_array( (string) $r->get( 'ssl_status' ), array( 'FAI', 'REJ', 'EXP' ), true ) ) {
			return false;
		}

		// A paid renewal ends the window: the refund the registrar gives is for the first year, not for what was renewed after.
		if ( array() !== (array) $r->get( 'renewed_orders' ) ) {
			return false;
		}

		// The window counts from the FIRST issue and never moves: a reissue or the authority's own reissue must not reopen it.
		$issued = (int) $r->get( 'ssl_first_issued_at' );

		return 0 === $issued || time() < $issued + SslSettings::refund_days() * DAY_IN_SECONDS;
	}

	/**
	 * Actions a customer may ask for now.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string> Action => label.
	 */
	public static function offered( Resource $r ): array {
		$actions = array();
		$status  = (string) $r->get( 'ssl_status' );

		if ( self::built( $r ) && 'ACT' === $status ) {
			$actions['ssl_reissue'] = __( 'Reissue the certificate', 'beltoft-services' );
		}

		if ( '' !== $r->get( 'remote_id' ) && 'REQ' === $status && ! $r->get( 'ssl_cancelled' ) ) {
			$actions['ssl_change_validation'] = __( 'Change the validation method', 'beltoft-services' );

			if ( 'email' === (string) $r->get( 'ssl_validation' ) ) {
				$actions['ssl_resend_email'] = __( 'Send the approval email again', 'beltoft-services' );
			}
		}

		if ( self::cancellable( $r ) ) {
			$actions['ssl_cancel'] = __( 'Cancel the certificate', 'beltoft-services' );
		}

		return $actions;
	}

	/**
	 * Runs an action.
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public static function run( Resource $r, string $action, array $args ): StepResult {
		switch ( $action ) {
			case 'ssl_reissue':
				return self::reissue( $r, $args );
			case 'ssl_resend_email':
				return self::resend( $r );
			case 'ssl_change_validation':
				return self::change_validation( $r, $args );
			case 'ssl_cancel':
				return self::cancel( $r, $args );
			case 'renew':
				return SslRenewals::renew( $r, (string) ( $args['order'] ?? '' ) );
			case 'refresh':
				return self::refresh( $r );
		}

		return StepResult::fail( 'Unknown certificate action.' );
	}

	/**
	 * Maps a registrar error of a customer action: temporary retries, anything else fails and tells staff.
	 *
	 * @param Resource  $r     Resource.
	 * @param \WP_Error $error Error.
	 * @param string    $what  What was being done.
	 */
	private static function failure( Resource $r, \WP_Error $error, string $what ): StepResult {
		if ( LiveClient::is_transient( $error ) ) {
			return StepResult::retry( 300, 'The registrar is temporarily unavailable.' );
		}

		$data = $error->get_error_data();
		$desc = is_array( $data ) ? mb_substr( sanitize_text_field( (string) ( $data['desc'] ?? '' ) ), 0, 200 ) : '';
		Alerts::admin( $r, 'The registrar refused to ' . $what . ': ' . $error->get_error_code() . ( '' !== $desc ? ' (' . $desc . ')' : '' ) );

		return StepResult::fail( 'The certificate authority did not accept this request.' );
	}

	/**
	 * Reissues the certificate with a new request: the customer's own, or a new key made here. Free for the life of the order.
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $args csr (optional).
	 */
	public static function reissue( Resource $r, array $args ): StepResult {
		if ( ! self::built( $r ) || 'ACT' !== (string) $r->get( 'ssl_status' ) ) {
			return StepResult::fail( 'There is no active certificate to reissue.' );
		}

		$log = array_values( array_filter( array_map( 'intval', (array) $r->get( 'ssl_reissues' ) ), static fn( $t ) => $t > time() - DAY_IN_SECONDS ) );

		if ( count( $log ) >= SslSettings::reissues_per_day() ) {
			return StepResult::fail( 'This certificate was reissued too often today.' );
		}

		$name  = (string) $r->get( 'ssl_name' );
		$level = SslCatalog::level( (int) ( ( (array) $r->get( 'plan' ) )['ssl_product'] ?? 0 ) );
		$level = '' !== $level ? $level : (string) ( ( (array) $r->get( 'plan' ) )['ssl_level'] ?? 'dv' );
		$csr   = isset( $args['csr'] ) && is_string( $args['csr'] ) ? trim( $args['csr'] ) : '';
		$keep  = array();

		if ( '' !== $csr ) {
			$read = Csr::check( $csr, $name, $level );

			if ( is_wp_error( $read ) ) {
				return StepResult::fail( $read->get_error_message() );
			}

			$keep = array( 'ssl_key_mode' => 'own', Keys::META_KEY => '', Keys::META_UNTIL => 0 );
		} else {
			$org = Provision::organization( $r, $level );

			if ( $org instanceof StepResult ) {
				return $org;
			}

			$made = Csr::generate( $name, $org );

			if ( is_wp_error( $made ) ) {
				return StepResult::retry( 300, $made->get_error_message() );
			}

			$stored = Keys::meta( $made['key'], ( SslSettings::wait_days() + Keys::REVEAL_DAYS ) * DAY_IN_SECONDS );

			if ( null === $stored ) {
				return StepResult::fail( 'The encryption key is not set, so no private key can be stored.' );
			}

			$csr  = $made['csr'];
			$keep = array_merge( $stored, array( 'ssl_key_mode' => 'generate' ) );
		}

		$log[] = time();
		$id    = (string) $r->get( 'remote_id' );
		$body  = array(
			'id'                        => (int) $id,
			'csr'                       => $csr,
			'software_id'               => 'linux',
			'signature_hash_algorithm'  => 'sha2',
			'domain_validation_methods' => array( array( 'host_name' => $name, 'method' => Validation::registrar_method( $r ) ) ),
		);

		if ( 'email' === (string) $r->get( 'ssl_validation' ) ) {
			$body['approver_email'] = (string) $r->get( 'ssl_approver' );
		}

		// The new request and its key are saved before the registrar is asked, so a crash cannot lose the key.
		if ( ! $r->set( array_merge( $keep, array( 'ssl_csr_next' => $csr, 'ssl_reissues' => $log ) ) ) ) {
			return StepResult::retry( 60, 'The new request could not be saved.' );
		}

		$sent = Clients::registrar()->request( 'POST', '/ssl/orders/' . $id . '/reissue', $body );

		if ( is_wp_error( $sent ) ) {
			return self::failure( $r, $sent, 'reissue the certificate' );
		}

		$after = array( 'ssl_reissue_pending' => true, 'ssl_csr' => $csr, 'ssl_csr_next' => null, 'ssl_action_sent' => null, 'ssl_validation_rows' => array() );

		// Saved here, not only by the caller: the refresh that is queued below compares the new certificate with this request.
		if ( ! $r->set( $after ) ) {
			return StepResult::retry( 60, 'The reissue could not be saved.' );
		}

		Jobs::queue_act( $r->id(), 'refresh', array(), 120 );

		return StepResult::done( $after, 'SSL certificate reissue requested' );
	}

	/**
	 * Asks the authority to send the approval mail again.
	 *
	 * @param Resource $r Resource.
	 */
	public static function resend( Resource $r ): StepResult {
		$id   = (string) $r->get( 'remote_id' );
		$sent = Clients::registrar()->request( 'POST', '/ssl/orders/' . $id . '/approver-email/resend', array( 'id' => (int) $id ) );

		return is_wp_error( $sent ) ? self::failure( $r, $sent, 'send the approval email again' ) : StepResult::done( array(), 'SSL approval email sent again' );
	}

	/**
	 * Changes how the domain is validated, while the order waits for it.
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $args method (auto, dns, file or email) and approver (a mailbox name, for email).
	 */
	public static function change_validation( Resource $r, array $args ): StepResult {
		$method   = isset( $args['method'] ) ? (string) $args['method'] : '';
		$wildcard = '*.' === substr( (string) $r->get( 'ssl_name' ), 0, 2 );

		if ( ! in_array( $method, Validation::METHODS, true ) || ( $wildcard && 'file' === $method ) || 'REQ' !== (string) $r->get( 'ssl_status' ) ) {
			return StepResult::fail( 'This validation method cannot be used now.' );
		}

		$base     = (string) $r->get( 'ssl_domain' );
		$approver = 'email' === $method ? SslNames::approver( (string) ( $args['approver'] ?? '' ), $base ) : '';

		if ( 'email' === $method && null === $approver ) {
			return StepResult::fail( 'Choose one of the approval addresses.' );
		}

		if ( 'auto' === $method && null === SslNames::zone_for( $base, (int) ( $r->license()['user_id'] ?? 0 ) ) ) {
			return StepResult::fail( 'Automatic validation needs a domain on our name servers.' );
		}

		$next = array( 'ssl_validation' => $method, 'ssl_approver' => (string) $approver, 'ssl_action_sent' => null, 'ssl_validation_rows' => array() );

		if ( ! $r->set( $next ) ) {
			return StepResult::retry( 60, 'The validation method could not be saved.' );
		}

		$fresh = Resource::find( $r->id() ) ?? $r;
		$id    = (string) $r->get( 'remote_id' );
		$body  = array( 'domain_validation_methods' => array( array( 'host_name' => (string) $r->get( 'ssl_name' ), 'method' => Validation::registrar_method( $fresh ) ) ) );

		if ( 'email' === $method ) {
			$body['approver_email'] = $approver;
		}

		$sent = Clients::registrar()->request( 'PUT', '/ssl/orders/' . $id, $body );

		if ( is_wp_error( $sent ) ) {
			return self::failure( $r, $sent, 'change the validation method' );
		}

		$detail = Clients::registrar()->request( 'GET', '/ssl/orders/' . $id );
		$rows   = is_wp_error( $detail ) ? StepResult::retry( 300, 'The validation data could not be read yet.' ) : Validation::start( $fresh, $detail );

		return $rows->is_done() ? StepResult::done( $rows->meta(), 'SSL validation method changed' ) : StepResult::done( $next, 'SSL validation method changed' );
	}

	/**
	 * Cancels the registrar order and the subscription; refunding the customer is staff's decision in version one.
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $args confirm.
	 */
	public static function cancel( Resource $r, array $args ): StepResult {
		if ( self::CONFIRM_CANCEL !== ( $args['confirm'] ?? '' ) ) {
			return StepResult::fail( 'The cancellation was not confirmed.' );
		}

		if ( ! self::cancellable( $r ) ) {
			return StepResult::fail( 'This certificate can no longer be cancelled.' );
		}

		$id   = (string) $r->get( 'remote_id' );
		$sent = Clients::registrar()->request( 'POST', '/ssl/orders/' . $id . '/cancel', array( 'id' => (int) $id ) );

		if ( is_wp_error( $sent ) ) {
			return self::failure( $r, $sent, 'cancel the certificate order' );
		}

		$r->set( array( 'ssl_cancelled' => true, 'ssl_cancelled_at' => time(), 'ssl_status' => 'REJ' ) );
		Keys::purge( $r );
		$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( (int) ( $r->license()['subscription_id'] ?? 0 ) ) : false;

		if ( $sub && ! $sub->has_status( 'cancelled' ) ) {
			$sub->update_status( 'cancelled', 'The customer cancelled the SSL certificate inside the refund window.' );
		}

		Alerts::admin( $r, 'A customer cancelled a certificate inside the refund window. Refund order ' . (int) ( $r->license()['order_id'] ?? 0 ) . '.' );

		return StepResult::done( array( 'ssl_cancelled' => true ), 'SSL certificate cancelled by the customer' );
	}

	/**
	 * Reads the order and brings the stored certificate up to date: the certificate of a reissue the customer asked for, one the
	 * authority issued by itself (its certificates now last under 200 days), or a new expiry. A certificate that is about to end
	 * with no newer one at the registrar is reported.
	 *
	 * @param Resource $r Resource.
	 */
	public static function refresh( Resource $r ): StepResult {
		$id = (string) $r->get( 'remote_id' );

		if ( '' === $id || $r->get( 'ssl_cancelled' ) || ! in_array( 'deliver', (array) $r->get( 'steps' ), true ) ) {
			return StepResult::done();
		}

		$detail = Clients::registrar()->request( 'GET', '/ssl/orders/' . $id );

		if ( is_wp_error( $detail ) ) {
			return StepResult::retry( 3600, 'The registrar order could not be read.' );
		}

		$status  = (string) ( $detail['status'] ?? '' );
		$pending = (bool) $r->get( 'ssl_reissue_pending' );
		$info    = '' !== trim( (string) ( $detail['certificate'] ?? '' ) ) ? Csr::certificate( (string) $detail['certificate'] ) : null;
		$meta    = array();
		$event   = '';

		if ( in_array( $status, array( 'FAI', 'REJ', 'EXP' ), true ) && $status !== (string) $r->get( 'ssl_status' ) ) {
			$meta['ssl_status'] = $status;
			$event              = 'EXP' === $status ? 'SSL certificate order expired' : 'SSL certificate order ended at the registrar (' . $status . ')';

			if ( 'EXP' !== $status ) {
				Alerts::admin( $r, 'A certificate order ended at the registrar without the customer asking (' . $status . ').' );
			}
		}

		$expires = (string) ( $detail['expiration_date'] ?? '' );

		if ( '' !== $expires && $expires !== (string) $r->get( 'ssl_order_expires' ) && 'ACT' === $status && ! $pending ) {
			$meta['ssl_order_expires'] = $expires;
		}

		$newer = $info && ! is_wp_error( $info ) && $info['serial'] !== (string) $r->get( 'ssl_serial' ) && $info['to'] >= (int) strtotime( (string) $r->get( 'ssl_valid_to' ) . ' UTC' );

		if ( 'ACT' === $status && $newer ) {
			$stored = Provision::store_certificate( $r, $detail );

			if ( $stored->is_done() ) {
				$meta  = array_merge( $meta, $stored->meta(), array( 'ssl_reissue_pending' => false, 'ssl_expiry_alerted' => null ) );
				$event = $pending ? 'SSL certificate reissued at the customer\'s request' : 'SSL certificate reissued by the certificate authority';

				if ( $pending ) {
					Keys::start_window( $r );
				}

				$r->set( $meta );
				Emails::send( 'beltoft_services_ssl_reissued', Resource::find( $r->id() ) ?? $r, array( 'ssl_by_customer' => $pending ) );

				return StepResult::done( array(), $event );
			}

			return $stored;
		}

		if ( $pending && 'REQ' === $status ) {
			if ( array() === (array) $r->get( 'ssl_validation_rows' ) ) {
				$rows = Validation::start( $r, $detail );

				if ( $rows->is_done() ) {
					$meta = array_merge( $meta, $rows->meta() );
				}
			}

			Jobs::queue_act( $r->id(), 'refresh', array(), 600 );
		} elseif ( $pending && 'ACT' === $status ) {
			// Validated, the new certificate is not in the order yet.
			Jobs::queue_act( $r->id(), 'refresh', array(), 300 );
		}

		$valid_to = (int) strtotime( (string) $r->get( 'ssl_valid_to' ) . ' UTC' );

		if ( ! $pending && 'ACT' === $status && $valid_to > 0 && $valid_to - time() < self::EXPIRY_WARNING_DAYS * DAY_IN_SECONDS && ! $r->get( 'ssl_expiry_alerted' ) ) {
			$meta['ssl_expiry_alerted'] = true;
			Alerts::admin( $r, 'A certificate ends within ' . self::EXPIRY_WARNING_DAYS . ' days and the registrar order holds no newer one.' );
			$r->set( $meta );
			Emails::send( 'beltoft_services_ssl_expiring', Resource::find( $r->id() ) ?? $r );

			return StepResult::done( array(), 'SSL certificate is about to end and has no replacement yet' );
		}

		return StepResult::done( $meta, $event );
	}

	/**
	 * Cancels what can still be cancelled for a refund when the service is deleted, and wipes the key.
	 *
	 * @param Resource $r Resource.
	 */
	public static function delete( Resource $r ): StepResult {
		Keys::purge( $r );

		if ( '' === $r->get( 'remote_id' ) || $r->get( 'ssl_cancelled' ) || ! self::cancellable( $r ) ) {
			return StepResult::done();
		}

		$id   = (string) $r->get( 'remote_id' );
		$sent = Clients::registrar()->request( 'POST', '/ssl/orders/' . $id . '/cancel', array( 'id' => (int) $id ) );

		if ( is_wp_error( $sent ) ) {
			return LiveClient::is_transient( $sent ) ? StepResult::retry( 3600, 'The registrar order could not be cancelled yet.' ) : StepResult::done();
		}

		return StepResult::done( array( 'ssl_cancelled' => true, 'ssl_status' => 'REJ' ) );
	}
}
