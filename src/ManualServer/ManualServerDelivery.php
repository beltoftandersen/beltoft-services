<?php
/**
 * Delivery of a manual server: the staff mails and the "Mark ready" step.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftLicenses\Events;
use BeltoftServices\Alerts;
use BeltoftServices\Crypto;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Meta of a manual server: `manual_requested_at`, `manual_ready_at` (set by Mark ready, the signal the driver waits for),
 * `manual_root_password` (encrypted with Crypto, shown to the customer only behind the emailed code, never in a mail, log or the admin form), `manual_ips` (a list, shown to the customer), `manual_note` (plain text shown to the customer, never a password),
 * `manual_provider_number` (the supplier's server number, staff only), `manual_ready_by`, `manual_order_mailed`,
 * `manual_overdue_mailed`. Nothing here calls a supplier.
 */
final class ManualServerDelivery {
	public const REQUESTED_AT = 'manual_requested_at';
	public const READY_AT     = 'manual_ready_at';
	public const IPS          = 'manual_ips';
	public const NOTE         = 'manual_note';
	public const PROVIDER_NO  = 'manual_provider_number';
	public const PASSWORD     = 'manual_root_password';
	public const PASSWORD_MAX = 200;

	public const MAX_IPS  = 16;
	public const NOTE_MAX = 500;

	/**
	 * The name the server is called by: dedicated-<first 8 characters of the uuid>.
	 *
	 * @param Resource $r Resource.
	 */
	public static function default_name( Resource $r ): string {
		$uuid = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $r->get( 'uuid' ) ) );
		$uuid = is_string( $uuid ) && '' !== $uuid ? substr( $uuid, 0, 8 ) : (string) $r->id();

		return 'dedicated-' . $uuid;
	}

	/**
	 * The minutes the order promises delivery in (setting manual_server_promise_minutes, default 10).
	 */
	public static function promise_minutes(): int {
		return max( 1, (int) Settings::get( 'manual_server_promise_minutes' ) );
	}

	/**
	 * The stored addresses.
	 *
	 * @param Resource $r Resource.
	 * @return string[]
	 */
	public static function ips( Resource $r ): array {
		$ips = $r->get( self::IPS );

		return is_array( $ips ) ? array_values( array_filter( array_map( 'strval', $ips ) ) ) : array();
	}

	/**
	 * The decrypted root password, or null when none is set. Only the reveal route calls this.
	 *
	 * @param Resource $r Resource.
	 */
	public static function root_password( Resource $r ): ?string {
		$stored = (string) $r->get( self::PASSWORD );

		return '' === $stored ? null : Crypto::decrypt( $stored );
	}

	/**
	 * Is a root password stored (the admin form and the card only ever learn this)?
	 *
	 * @param Resource $r Resource.
	 */
	public static function has_root_password( Resource $r ): bool {
		return '' !== (string) $r->get( self::PASSWORD );
	}

	/**
	 * Addresses from a text of one or more addresses (comma, space or line separated), checked one by one.
	 *
	 * @param string $text Text.
	 * @return string[]|\WP_Error
	 */
	public static function parse_ips( string $text ) {
		$parts = preg_split( '/[\s,;]+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();

		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			if ( false === filter_var( $part, FILTER_VALIDATE_IP ) ) {
				return new \WP_Error( 'bad_ip', __( 'One of the addresses is not a valid IP address.', 'beltoft-services' ) );
			}

			$out[ strtolower( $part ) ] = strtolower( $part );
		}

		if ( count( $out ) > self::MAX_IPS ) {
			return new \WP_Error( 'bad_ip', __( 'Too many addresses.', 'beltoft-services' ) );
		}

		return array_values( $out );
	}

	/**
	 * Stores the delivery details. With $deliver the server is delivered: the reconciler is queued and moves it to ready
	 * (which starts billing and mails the customer). Without it only the details of a delivered server change.
	 *
	 * @param Resource $r        Resource.
	 * @param string   $ips      Addresses, one or more.
	 * @param string   $note     Login note for the customer (never a password).
	 * @param string   $provider The supplier's server number (staff only).
	 * @param string   $who      Admin login, for the history.
	 * @param bool     $deliver  True for Mark ready.
	 * @param string   $password Root password, '' keeps the stored one. Stored encrypted, never echoed.
	 * @return true|\WP_Error
	 */
	public static function save( Resource $r, string $ips, string $note, string $provider, string $who, bool $deliver, string $password = '' ) {
		if ( ManualServerDriver::ID !== $r->get( 'driver' ) || ! $r->is_valid() ) {
			return new \WP_Error( 'not_manual', __( 'This is not a dedicated server.', 'beltoft-services' ) );
		}

		$list = self::parse_ips( $ips );

		if ( is_wp_error( $list ) ) {
			return $list;
		}

		if ( $deliver && array() === $list ) {
			return new \WP_Error( 'no_ip', __( 'Give at least one IP address.', 'beltoft-services' ) );
		}

		$provider = trim( $provider );

		if ( '' !== $provider && 1 !== preg_match( '/^[A-Za-z0-9 _.#\/-]{1,40}$/D', $provider ) ) {
			return new \WP_Error( 'bad_provider', __( 'The supplier server number may hold letters, digits and . _ - # / only.', 'beltoft-services' ) );
		}

		if ( '' !== $password && ( strlen( $password ) > self::PASSWORD_MAX || 1 === preg_match( '/[\x00-\x1f\x7f]/', $password ) ) ) {
			return new \WP_Error( 'bad_password', __( 'The root password is too long or holds a control character.', 'beltoft-services' ) );
		}

		if ( '' !== $password && ! Crypto::available() ) {
			return new \WP_Error( 'no_key', __( 'The encryption key is missing, so the password cannot be stored.', 'beltoft-services' ) );
		}

		$note = trim( mb_substr( sanitize_textarea_field( $note ), 0, self::NOTE_MAX ) );
		$meta = array(
			self::IPS         => $list,
			self::NOTE        => $note,
			self::PROVIDER_NO => $provider,
		);

		if ( '' !== $password ) {
			$meta[ self::PASSWORD ] = Crypto::encrypt( $password );
		}

		$why  = '';

		$stored = $r->locked(
			static function () use ( $r, $deliver, $who, $meta, &$why ): bool {
				$delivered = (int) $r->get( self::READY_AT ) > 0;
				$state     = (string) $r->get( 'state' );

				if ( 'deleted' === $state || 'deleting' === $state || 'deleted' === $r->get( 'desired' ) ) {
					$why = 'gone';

					return false;
				}

				if ( $deliver && $delivered ) {
					$why = 'delivered';

					return false;
				}

				if ( ! $deliver && ! $delivered ) {
					$why = 'not_delivered';

					return false;
				}

				if ( $deliver ) {
					$waiting = in_array( $state, array( 'requested', 'provisioning' ), true ) || ( 'failed' === $state && 'provision' === $r->get( 'failed_op' ) );

					if ( ! $waiting ) {
						$why = 'not_waiting';

						return false;
					}

					$meta[ self::READY_AT ] = time();
					$meta['manual_ready_by'] = $who;
				}

				return $r->set( $meta );
			}
		);

		if ( null === $stored ) {
			return new \WP_Error( 'busy', __( 'The server is busy. Try again in a moment.', 'beltoft-services' ) );
		}

		if ( ! $stored ) {
			$messages = array(
				'gone'          => __( 'The server is being deleted or is deleted.', 'beltoft-services' ),
				'delivered'     => __( 'The server was delivered already. Change its details instead.', 'beltoft-services' ),
				'not_delivered' => __( 'The server is not delivered yet. Mark it ready first.', 'beltoft-services' ),
				'not_waiting'   => __( 'The server is not waiting for delivery.', 'beltoft-services' ),
			);

			return new \WP_Error( '' !== $why ? $why : 'not_stored', $messages[ $why ] ?? __( 'The details could not be stored.', 'beltoft-services' ) );
		}

		Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', ( $deliver ? 'Server delivered by ' : 'Server details changed by ' ) . $who );

		if ( $deliver ) {
			$r->reset_retries();
			Jobs::enqueue( $r->id() );
		}

		return true;
	}

	/**
	 * The lines about the order that every staff mail starts with: customer, plan, location and cost. Never a secret.
	 *
	 * @param Resource $r Resource.
	 * @return string[]
	 */
	private static function order_lines( Resource $r ): array {
		$license = $r->license();
		$plan    = (array) $r->get( 'plan' );
		$user    = get_userdata( (int) ( $license['user_id'] ?? 0 ) );
		$product = wc_get_product( (int) ( $license['variation_id'] ?? 0 ) );
		$line    = static function ( string $label, $value ): string {
			return is_scalar( $value ) && '' !== trim( (string) $value ) ? $label . ': ' . trim( (string) $value ) : '';
		};

		$lines = array(
			$line( 'Order', $license['order_id'] ?? '' ),
			$line( 'Customer', $user ? $user->user_email : '' ),
			$line( 'Server', $r->get( 'server_name' ) ),
			$line( 'Plan', $product ? $product->get_name() : '' ),
			$line( 'Processor', $plan['server_cpu'] ?? '' ),
			$line( 'Memory', $plan['server_memory'] ?? '' ),
			$line( 'Storage', $plan['server_storage'] ?? '' ),
			$line( 'Supplier product', $plan['server_ref'] ?? '' ),
			$line( 'Supplier location', $plan['server_location_code'] ?? '' ),
			$line( 'Country shown', $plan['server_location'] ?? '' ),
			$line( 'Cost per month, EUR without VAT', $plan['cost_monthly'] ?? '' ),
			$line( 'Cost of setup, EUR without VAT', $plan['cost_setup'] ?? '' ),
			$line( 'Supplier server number', $r->get( self::PROVIDER_NO ) ),
			$line( 'Addresses', implode( ', ', self::ips( $r ) ) ),
			'Open the service: ' . \BeltoftLicenses\Admin\Screen::url( array( 'license' => (int) ( $license['id'] ?? 0 ) ) ),
		);

		return array_values( array_filter( $lines ) );
	}

	/**
	 * Mails staff the order, once.
	 *
	 * @param Resource $r Resource.
	 */
	public static function order_mail( Resource $r ): void {
		if ( (int) $r->get( 'manual_order_mailed' ) > 0 ) {
			return;
		}

		$lines = array_merge(
			array(
				'A dedicated server was paid for and automatic ordering is OFF (Settings, Dedicated servers). Buy it at the supplier by hand, then press Mark ready on the service with the address.',
				sprintf( 'It is promised within %d minutes of the payment.', self::promise_minutes() ),
				'Nothing is ordered by the plugin.',
				'',
			),
			self::order_lines( $r )
		);

		if ( Alerts::notify( sprintf( 'Dedicated server to set up (service %d)', $r->id() ), $lines, $r ) ) {
			$r->set( array( 'manual_order_mailed' => time() ) );
		}
	}

	/**
	 * Mails staff once when the delivery promise has passed.
	 *
	 * @param Resource $r Resource.
	 */
	public static function overdue_mail( Resource $r ): void {
		$since = (int) $r->get( self::REQUESTED_AT );

		if ( $since < 1 || (int) $r->get( 'manual_overdue_mailed' ) > 0 || time() - $since < self::promise_minutes() * MINUTE_IN_SECONDS ) {
			return;
		}

		$lines = array_merge( array( sprintf( 'This server is not delivered %d minutes after the order. The customer was promised that.', self::promise_minutes() ), '' ), self::order_lines( $r ) );

		if ( Alerts::notify( sprintf( 'Dedicated server is late (service %d)', $r->id() ), $lines, $r ) ) {
			$r->set( array( 'manual_overdue_mailed' => time() ) );
		}
	}

	/**
	 * Tells staff what to do by hand: pause (switch off), resume (switch on), plan (changed) or cancel.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action pause, resume, plan or cancel.
	 */
	public static function staff_mail( Resource $r, string $action ): void {
		$delivered = (int) $r->get( self::READY_AT ) > 0;
		$what      = array(
			'pause'  => $delivered ? 'Switch the server off (power off in the supplier panel). The usage account is on hold (unpaid). The supplier still bills until it is cancelled.' : 'The service was put on hold before delivery. Do not set it up until it is resumed.',
			'resume' => $delivered ? 'Switch the server on again.' : 'The hold before delivery is over. Set it up and press Mark ready.',
			'plan'   => 'The plan of this service changed. Nothing was changed at the supplier.',
			'cancel' => $delivered
				? 'CANCEL the server at the supplier now. Billing to the customer has stopped. The supplier bills by the hour and cancels at once, and the data is gone at once.'
				: 'The service was cancelled before delivery. Do not buy the server. If one was bought, cancel it at the supplier. The setup fee was paid: refund it by hand if it is due.',
		);

		$subjects = array(
			'pause'  => 'Dedicated server on hold',
			'resume' => 'Dedicated server resumed',
			'plan'   => 'Dedicated server plan changed',
			'cancel' => 'Cancel a dedicated server at the supplier',
		);

		if ( ! isset( $what[ $action ] ) ) {
			return;
		}

		// A cancel is told once for ever; the others once an hour at most, so a retried job does not mail twice.
		$ttl = 'cancel' === $action ? 30 * DAY_IN_SECONDS : HOUR_IN_SECONDS;

		Alerts::notify( sprintf( '%s (service %d)', $subjects[ $action ], $r->id() ), array_merge( array( $what[ $action ], '' ), self::order_lines( $r ) ), $r, 'beltoft_services_manual_' . $action . '_' . $r->id(), array( 'ttl' => $ttl ) );
	}
}
