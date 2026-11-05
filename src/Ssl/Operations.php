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
	 * The new key and request are kept APART (ssl_key_enc_next, ssl_csr_next) until the registrar has delivered a certificate
	 * for them: the stored key, which belongs to the certificate that works, is never overwritten before that. A refusal throws
	 * the pending request away; a temporary failure keeps it, and the retry sends the same request instead of making another.
	 * Only a reissue the registrar accepted counts against the daily limit.
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $args csr (optional).
	 */
	public static function reissue( Resource $r, array $args ): StepResult {
		if ( ! self::built( $r ) || 'ACT' !== (string) $r->get( 'ssl_status' ) ) {
			return StepResult::fail( 'There is no active certificate to reissue.' );
		}

		if ( $r->get( 'ssl_reissue_pending' ) ) {
			return StepResult::fail( 'A reissue of this certificate is already in progress.' );
		}

		$log = array_values( array_filter( array_map( 'intval', (array) $r->get( 'ssl_reissues' ) ), static fn( $t ) => $t > time() - DAY_IN_SECONDS ) );

		if ( count( $log ) >= SslSettings::reissues_per_day() ) {
			return StepResult::fail( 'This certificate was reissued too often today.' );
		}

		$name = (string) $r->get( 'ssl_name' );
		$csr  = (string) $r->get( 'ssl_csr_next' );

		// A request kept by an earlier attempt that did not get an answer is sent again as it is.
		if ( '' === $csr ) {
			$made = self::new_request( $r, $name, $args );

			if ( $made instanceof StepResult ) {
				return $made;
			}

			// Saved before the registrar is asked, so a crash cannot lose the key.
			if ( ! $r->set( $made ) ) {
				return StepResult::retry( 60, 'The new request could not be saved.' );
			}

			$csr = (string) $made['ssl_csr_next'];
		}

		$id   = (string) $r->get( 'remote_id' );
		$body = array(
			'id'                        => (int) $id,
			'csr'                       => $csr,
			'software_id'               => 'linux',
			'signature_hash_algorithm'  => 'sha2',
			'domain_validation_methods' => Validation::registrar_methods( $r ),
		);

		if ( 'email' === (string) $r->get( 'ssl_validation' ) ) {
			$body['approver_email'] = (string) $r->get( 'ssl_approver' );
		}

		$sent = Clients::registrar()->request( 'POST', '/ssl/orders/' . $id . '/reissue', $body );

		if ( is_wp_error( $sent ) ) {
			// A refusal means nothing was changed at the registrar: the request and its key are thrown away. A timeout keeps them.
			if ( ! LiveClient::is_transient( $sent ) ) {
				Keys::discard_pending( $r );
			}

			return self::failure( $r, $sent, 'reissue the certificate' );
		}

		$log[] = time();
		$after = array( 'ssl_reissue_pending' => true, 'ssl_reissue_at' => time(), 'ssl_reissues' => $log, 'ssl_action_sent' => null, 'ssl_validation_rows' => array() );

		// Saved here, not only by the caller: the refresh that is queued below compares the new certificate with the pending request.
		if ( ! $r->set( $after ) ) {
			return StepResult::retry( 60, 'The reissue could not be saved.' );
		}

		Jobs::queue_act( $r->id(), 'refresh', array(), 120 );

		return StepResult::done( $after, 'SSL certificate reissue requested' );
	}

	/**
	 * A new request for a reissue, as the meta that keeps it apart from the stored key.
	 *
	 * @param Resource             $r    Resource.
	 * @param string               $name Common name.
	 * @param array<string, mixed> $args csr (optional).
	 * @return array<string, mixed>|StepResult
	 */
	private static function new_request( Resource $r, string $name, array $args ) {
		$level = SslCatalog::level( (int) ( ( (array) $r->get( 'plan' ) )['ssl_product'] ?? 0 ) );
		$level = '' !== $level ? $level : (string) ( ( (array) $r->get( 'plan' ) )['ssl_level'] ?? 'dv' );
		$csr   = isset( $args['csr'] ) && is_string( $args['csr'] ) ? trim( $args['csr'] ) : '';
		// A reissue keeps the names of the order: the request covers exactly them.
		$names = array_column( SslNames::entries( $r ), 'name' );

		if ( '' !== $csr ) {
			$read = Csr::check( $csr, $name, $level, $names );

			return is_wp_error( $read ) ? StepResult::fail( $read->get_error_message() ) : array( 'ssl_csr_next' => $csr, 'ssl_key_mode_next' => 'own' );
		}

		$org = Provision::organization( $r, $level );

		if ( $org instanceof StepResult ) {
			return $org;
		}

		$made = Csr::generate( $name, $org, $names );

		if ( is_wp_error( $made ) ) {
			return StepResult::retry( 300, $made->get_error_message() );
		}

		$stored = Keys::meta( $made['key'], ( SslSettings::wait_days() + Keys::REVEAL_DAYS ) * DAY_IN_SECONDS );

		if ( null === $stored ) {
			return StepResult::fail( 'The encryption key is not set, so no private key can be stored.' );
		}

		return array( Keys::META_KEY_NEXT => $stored[ Keys::META_KEY ], Keys::META_UNTIL_NEXT => $stored[ Keys::META_UNTIL ], 'ssl_csr_next' => $made['csr'], 'ssl_key_mode_next' => 'generate' );
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
	 * Changes how the domain is validated, while the order waits for it. A certificate for several names changes the method of
	 * one name (`name`) or of every name; email is not offered for it.
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $args method (auto, dns, file or email), approver (a mailbox name, for email) and name (several names only).
	 */
	public static function change_validation( Resource $r, array $args ): StepResult {
		$method   = isset( $args['method'] ) ? (string) $args['method'] : '';
		$wildcard = '*.' === substr( (string) $r->get( 'ssl_name' ), 0, 2 );
		$entries  = SslNames::entries( $r );
		$multi    = count( $entries ) > 1;

		if ( ! in_array( $method, Validation::METHODS, true ) || ( $wildcard && 'file' === $method ) || ( $multi && 'email' === $method ) || 'REQ' !== (string) $r->get( 'ssl_status' ) ) {
			return StepResult::fail( 'This validation method cannot be used now.' );
		}

		$user     = (int) ( $r->license()['user_id'] ?? 0 );
		$target   = isset( $args['name'] ) ? strtolower( (string) $args['name'] ) : '';
		$base     = (string) $r->get( 'ssl_domain' );
		$approver = 'email' === $method ? SslNames::approver( (string) ( $args['approver'] ?? '' ), $base ) : '';

		if ( 'email' === $method && null === $approver ) {
			return StepResult::fail( 'Choose one of the approval addresses.' );
		}

		if ( $multi && '' !== $target && ! in_array( $target, array_column( $entries, 'name' ), true ) ) {
			return StepResult::fail( 'That name is not on this certificate.' );
		}

		foreach ( $entries as $i => $entry ) {
			if ( $multi && '' !== $target && $entry['name'] !== $target ) {
				continue;
			}

			if ( 'auto' === $method && null === SslNames::zone_for( $entry['base'], $user ) ) {
				return StepResult::fail( 'Automatic validation needs a domain on our name servers.' );
			}

			$entries[ $i ]['validation'] = $method;
		}

		$next = array( 'ssl_validation' => $entries[0]['validation'], 'ssl_approver' => (string) $approver, 'ssl_action_sent' => null, 'ssl_validation_rows' => array() );

		// Whenever the service keeps a name list (even of one name) the change lands there, the place entries() reads.
		if ( $multi || array() !== (array) $r->get( 'ssl_names' ) ) {
			$next['ssl_names'] = array_map( static fn( $e ) => array( 'name' => $e['name'], 'validation' => $e['validation'] ), $entries );
		}

		if ( ! $r->set( $next ) ) {
			return StepResult::retry( 60, 'The validation method could not be saved.' );
		}

		$fresh = Resource::find( $r->id() ) ?? $r;
		$id    = (string) $r->get( 'remote_id' );
		$body  = array( 'domain_validation_methods' => Validation::registrar_methods( $fresh ) );

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
			$next    = (string) $r->get( 'ssl_csr_next' );
			$wanted  = $pending && '' !== $next ? Csr::parse( $next ) : null;
			$promote = is_array( $wanted ) && trim( $wanted['public'] ) === trim( $info['public'] );
			// While a reissue is pending, a certificate for the OLD request is the authority's own reissue: stored, and the pending one goes on waiting.
			$stored = Provision::store_certificate( $r, $detail, $promote ? $next : null );

			if ( $stored->is_done() ) {
				$meta  = array_merge( $meta, $stored->meta(), array( 'ssl_expiry_alerted' => null ) );
				$event = $promote ? 'SSL certificate reissued at the customer\'s request' : 'SSL certificate reissued by the certificate authority';

				if ( $promote ) {
					$meta = array_merge( $meta, self::promotion( $r ) );
				}

				$r->set( $meta );
				Emails::send( 'beltoft_services_ssl_reissued', Resource::find( $r->id() ) ?? $r, array( 'ssl_by_customer' => $promote ) );

				return StepResult::done( array(), $event );
			}

			return $stored;
		}

		// A reissue that never came is given up, with its key, after the wait limit.
		if ( $pending && (int) $r->get( 'ssl_reissue_at' ) > 0 && time() > (int) $r->get( 'ssl_reissue_at' ) + SslSettings::wait_days() * DAY_IN_SECONDS ) {
			Keys::discard_pending( $r );
			Alerts::admin( $r, 'A customer reissue did not produce a certificate within ' . SslSettings::wait_days() . ' days and was given up. The earlier certificate still works.' );

			return StepResult::done( array(), 'SSL reissue given up: no certificate came in time' );
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
	 * The meta that makes the pending request and key the current ones, once a certificate for them is stored. The key's seven days
	 * start now.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>
	 */
	private static function promotion( Resource $r ): array {
		$generated = 'generate' === (string) $r->get( 'ssl_key_mode_next' );

		return array(
			'ssl_csr'               => (string) $r->get( 'ssl_csr_next' ),
			'ssl_csr_next'          => null,
			'ssl_key_mode'          => $generated ? 'generate' : 'own',
			'ssl_key_mode_next'     => null,
			Keys::META_KEY          => $generated ? (string) $r->get( Keys::META_KEY_NEXT ) : '',
			Keys::META_UNTIL        => $generated ? time() + Keys::REVEAL_DAYS * DAY_IN_SECONDS : 0,
			Keys::META_KEY_NEXT     => '',
			Keys::META_UNTIL_NEXT   => 0,
			'ssl_reissue_pending'   => false,
			'ssl_reissue_at'        => null,
		);
	}

	/**
	 * Cancels what can still be cancelled for a refund when the service is deleted, and wipes the key.
	 *
	 * @param Resource $r Resource.
	 */
	public static function delete( Resource $r ): StepResult {
		Keys::purge( $r );
		Validation::remove_published( $r );

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
