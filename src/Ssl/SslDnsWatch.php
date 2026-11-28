<?php
/**
 * What a certificate tells the DNS watch.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Ssl;

use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Domains\DnsWatch;
use BeltoftServices\Domains\DnsWatchFeature;
use BeltoftServices\Domains\DnsWatchJobs;
use BeltoftServices\Domains\HostName;
use BeltoftServices\Domains\PublicDns;
use BeltoftServices\Domains\ZoneWriter;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The certificate's part of the shared DNS watch (Domains\DnsWatch). The authority checks the domain, not us; what we add is a look at
 * the validation CNAME of the orders that use one (method `auto` or `dns`; `file` and `email` have no record to find):
 *
 * - while the order waits for validation: the short look, the reminders on the shared days, the status word and the found / not found
 *   yet in the validation box. The end of the wait is the cancel of the order (Provision::waiting, `ssl_wait_days`): the watch ends with
 *   it (filter beltoft_services_dns_watch_wait_days) and sends no timeout mail.
 * - after the certificate is issued: the daily look. The authority issues a certificate for about 199 days and reuses a validation for
 *   about 198 days (Sectigo, 2026), so every automatic reissue needs the record again. A loss is confirmed, the customer is told that the
 *   certificate keeps working and the next renewal waits, and the record is told again when it is back. Nothing is ever ordered,
 *   reissued, renewed or cancelled because of DNS.
 * - a record in a zone of ours (method auto) that went missing is written again, quietly; a foreign value at the name is a conflict.
 *
 * One order can hold several CNAMEs (a certificate for several names). The watch keeps them as one row with key `cname` and the
 * records as `items`; the row is ok when every record is found. State is meta under `ssl_dns`; `ssl_dns_sig` is the fingerprint of the
 * records being watched, so new records (a reissue with a new request) start the wait again.
 */
final class SslDnsWatch implements DnsWatchFeature {

	public const ID = 'ssl';

	/** The prefix of the watch's meta. */
	public const PREFIX = 'ssl_dns';

	/** Stored status values of the engine, kept as they are. */
	private const DEAD = array( 'FAI', 'REJ', 'EXP' );

	/**
	 * Registers the feature with the watch and binds its wait to the order's wait.
	 */
	public static function register(): void {
		add_filter(
			'beltoft_services_dns_watches',
			static function ( $features ) {
				$features              = is_array( $features ) ? $features : array();
				$features[ self::ID ]  = new self();

				return $features;
			}
		);
		add_filter(
			'beltoft_services_dns_watch_wait_days',
			static fn( $days, $feature ) => self::ID === $feature ? SslSettings::wait_days() : $days,
			10,
			2
		);
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'SSL certificate';
	}

	public function noun(): string {
		return 'certificate validation DNS';
	}

	public function kind(): string {
		return SslDriver::KIND;
	}

	public function prefix(): string {
		return self::PREFIX;
	}

	public function rows_key(): string {
		return 'ssl_dns_rows';
	}

	public function mode_key(): string {
		return 'ssl_dns_mode';
	}

	public function hook( string $job ): string {
		return 'beltoft_services_dns_watch_' . $job . '_' . self::ID;
	}

	// -- Which orders --------------------------------------------------------------------------------

	/**
	 * The CNAME records of the order's validation, as the registrar named them.
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array{name: string, value: string}>
	 */
	public static function records( Resource $r ): array {
		$out = array();

		foreach ( (array) $r->get( 'ssl_validation_rows' ) as $row ) {
			if ( is_array( $row ) && 'CNAME' === strtoupper( (string) ( $row['type'] ?? '' ) ) && ! empty( $row['name'] ) && ! empty( $row['value'] ) ) {
				$out[] = array( 'name' => strtolower( rtrim( (string) $row['name'], '.' ) ), 'value' => strtolower( rtrim( (string) $row['value'], '.' ) ) );
			}
		}

		return $out;
	}

	public function owns( Resource $r ): bool {
		return SslDriver::ID === $r->get( 'driver' ) && ! Provision::is_smime( $r ) && array() !== self::records( $r );
	}

	public function active( Resource $r ): bool {
		return $r->is_live()
			&& ! in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted', 'failed' ), true )
			&& ! $r->get( 'ssl_cancelled' )
			&& '' !== (string) $r->get( 'remote_id' )
			&& ! in_array( (string) $r->get( 'ssl_status' ), self::DEAD, true )
			&& $this->owns( $r );
	}

	public function shows_status( Resource $r ): bool {
		return $this->active( $r );
	}

	public function before_look( Resource $r ): bool {
		return $this->active( $r );
	}

	public function domain( Resource $r ): string {
		$domain = (string) $r->get( 'ssl_domain' );

		return '' !== $domain ? $domain : ltrim( (string) $r->get( 'ssl_name' ), '*.' );
	}

	public function zone_user( Resource $r ): int {
		return (int) ( $r->license()['user_id'] ?? 0 );
	}

	/**
	 * Are all the records in zones of ours and were they published by us (method auto)? Only then are they written again.
	 *
	 * @param Resource $r Resource.
	 */
	public function on_our_servers( Resource $r ): bool {
		if ( true !== $r->get( 'ssl_validation_auto' ) ) {
			return false;
		}

		$user = $this->zone_user( $r );

		foreach ( self::records( $r ) as $record ) {
			if ( null === SslNames::zone_for( $record['name'], $user ) ) {
				return false;
			}
		}

		return true;
	}

	public function needed(): array {
		return array( 'cname' );
	}

	public function required( string $key ): bool {
		return 'cname' === $key;
	}

	public function status_in( string $status ): string {
		return $status;
	}

	public function status_out( string $status ): string {
		return $status;
	}

	public function mails( string $event, Resource $r ): bool {
		unset( $r );

		// The order's own end of the wait is the cancel; a certificate gets no "we stopped looking" mail.
		return 'timeout' !== $event;
	}

	// -- Rows ------------------------------------------------------------------------------------------

	public function rows( Resource $r ): array {
		$records = self::records( $r );

		if ( array() === $records ) {
			return array();
		}

		$stored = (array) $r->get( $this->rows_key() );
		$old    = is_array( $stored[0] ?? null ) ? $stored[0] : array();
		$items  = array();

		foreach ( $records as $record ) {
			$item = $record + array( 'status' => 'missing', 'seen' => array() );

			foreach ( (array) ( $old['items'] ?? array() ) as $was ) {
				if ( is_array( $was ) && ( $was['name'] ?? '' ) === $record['name'] && ( $was['value'] ?? '' ) === $record['value'] ) {
					$item['status'] = (string) ( $was['status'] ?? 'missing' );
					$item['seen']   = array_values( array_filter( (array) ( $was['seen'] ?? array() ), 'is_string' ) );
				}
			}

			$items[] = $item;
		}

		return array(
			array(
				'key'      => 'cname',
				'name'     => $records[0]['name'],
				'type'     => 'CNAME',
				'ttl'      => Validation::TTL,
				'priority' => 0,
				'value'    => $records[0]['value'],
				'status'   => (string) ( $old['status'] ?? 'missing' ),
				'reason'   => (string) ( $old['reason'] ?? '' ),
				'seen'     => array_values( array_filter( (array) ( $old['seen'] ?? array() ), 'is_string' ) ),
				'items'    => $items,
				'rid'      => $r->id(),
			),
		);
	}

	public function read_row( array $row, string $domain ): ?array {
		unset( $domain );

		$items  = array();
		$status = 'ok';
		$seen   = array();

		foreach ( (array) ( $row['items'] ?? array() ) as $item ) {
			$found = PublicDns::lookup( (string) $item['name'], 'CNAME' );

			// A lookup that failed learns nothing about the record.
			if ( is_wp_error( $found ) ) {
				return null;
			}

			$values = array_map( static fn( $v ) => strtolower( rtrim( trim( (string) $v ), '.' ) ), $found );

			if ( in_array( (string) $item['value'], $values, true ) ) {
				$item['status'] = 'ok';
				$item['seen']   = array();
			} elseif ( array() !== $values ) {
				$item['status'] = 'conflict';
				$item['seen']   = array_slice( array_map( static fn( $v ) => substr( $v, 0, 400 ), $values ), 0, 3 );
			} else {
				$item['status'] = 'missing';
				$item['seen']   = array();
			}

			if ( 'ok' !== $item['status'] && ( 'ok' === $status || 'conflict' === $item['status'] ) ) {
				$status = $item['status'];
				$seen   = $item['seen'];
			}

			$items[] = $item;
		}

		$row['items']  = $items;
		$row['status'] = $status;
		$row['reason'] = 'conflict' === $status ? 'name_taken' : '';
		$row['seen']   = $seen;

		return $row;
	}

	/**
	 * Writes the records again in the customer's zones where they are missing and free (method auto only). Never over a foreign value.
	 *
	 * @param int                  $user_id Customer.
	 * @param string               $domain  Domain.
	 * @param array<string, mixed> $row     Row.
	 * @return array{0: string, 1: string, 2: bool}|\WP_Error
	 */
	public function publish( int $user_id, string $domain, array $row ) {
		unset( $domain );

		$wrote  = false;
		$status = 'ok';
		$reason = '';

		foreach ( (array) ( $row['items'] ?? array() ) as $item ) {
			$zone = SslNames::zone_for( (string) $item['name'], $user_id );

			if ( null === $zone ) {
				return new \WP_Error( 'not_ours', 'Not a zone of ours.' );
			}

			$relative = $item['name'] === $zone ? '@' : substr( (string) $item['name'], 0, -strlen( $zone ) - 1 );
			$result   = ZoneWriter::write( $user_id, $zone, array( array( 'name' => $relative, 'type' => 'CNAME', 'ttl' => Validation::TTL, 'value' => $item['value'] ) ), ZoneWriter::cname_policy( (string) $item['value'] ) );

			if ( is_wp_error( $result ) ) {
				return new \WP_Error( 'transient', 'The DNS zone is being changed.' );
			}

			if ( 'ok' !== $result['status'] ) {
				$status = 'conflict';
				$reason = in_array( $result['reason'], array( 'not_ours', 'invalid', 'no_zone', 'paused' ), true ) ? $result['reason'] : 'name_taken';
				continue;
			}

			if ( ! empty( $result['wrote'] ) ) {
				$wrote = true;
				$this->remember( (int) ( $row['rid'] ?? 0 ), $item );
			}
		}

		return array( $status, $reason, $wrote );
	}

	/**
	 * A record we wrote again is ours, so deleting the service takes it away (Validation::remove_published).
	 *
	 * @param int                  $id   Resource id.
	 * @param array<string, mixed> $item Record.
	 */
	private function remember( int $id, array $item ): void {
		$r = Resource::find( $id );

		if ( ! $r ) {
			return;
		}

		$r->locked(
			static function () use ( $id, $item ): bool {
				$fresh = Resource::find( $id );

				if ( ! $fresh ) {
					return false;
				}

				$list = array_values( array_filter( (array) $fresh->get( 'ssl_written' ), 'is_array' ) );

				foreach ( $list as $one ) {
					if ( ( $one['name'] ?? '' ) === $item['name'] && ( $one['value'] ?? '' ) === $item['value'] ) {
						return true;
					}
				}

				$list[] = array( 'name' => (string) $item['name'], 'value' => (string) $item['value'] );

				return $fresh->set( array( 'ssl_written' => $list ) );
			}
		);
	}

	public function missing_rows( Resource $r, bool $needed_only = false ): array {
		unset( $needed_only );

		$out = array();

		foreach ( $this->rows( $r ) as $row ) {
			foreach ( (array) $row['items'] as $item ) {
				if ( 'ok' !== ( 'ok' === $row['status'] ? 'ok' : $item['status'] ) ) {
					$out[] = array( 'key' => 'cname', 'name' => $item['name'], 'type' => 'CNAME', 'value' => $item['value'], 'priority' => 0, 'status' => $item['status'] );
				}
			}
		}

		return $out;
	}

	public function partial_state(): string {
		return '';
	}

	public function partial_word(): string {
		return '';
	}

	public function partial_applies( Resource $r, array $ok_keys ): bool {
		unset( $r, $ok_keys );

		return false;
	}

	public function held( Resource $r ): bool {
		unset( $r );

		return false;
	}

	// -- Words -------------------------------------------------------------------------------------------

	public function copy( string $event, Resource $r ): array {
		$license = $r->license();
		$effects = array(
			'reminder' => __( 'The certificate is issued after the certificate authority finds this record. Nothing is issued until then.', 'beltoft-services' ),
			'lost'     => __( 'Your certificate keeps working. Its next renewal or reissue waits until the record is back, and the certificate ends if the record is still missing then.', 'beltoft-services' ),
			'back'     => __( 'The record is found again, so the certificate can be renewed and reissued as usual.', 'beltoft-services' ),
			'timeout'  => '',
		);

		return array(
			/* translators: %s: domain name. */
			'dns_subject'   => sprintf( __( 'SSL certificate for %s', 'beltoft-services' ), HostName::display( $this->domain( $r ) ) ),
			'dns_effect'    => $effects[ $event ] ?? '',
			'dns_where'     => __( 'the certificate page', 'beltoft-services' ),
			'dns_link'      => $license ? ServiceArea::get( Ssl::class )->url( $license ) : '',
			'dns_open_text' => __( 'Open the certificate page', 'beltoft-services' ),
		);
	}

	public function alert_lines( string $event, Resource $r, array $ctx = array() ): array {
		unset( $r, $ctx );

		switch ( $event ) {
			case 'lost':
				return array( 'The validation CNAME of the certificate is gone. The certificate keeps working; its next renewal or reissue waits for the record. Nothing is ordered, reissued or cancelled by this. The customer was emailed.' );
			case 'no_dns':
				return array( 'The validation CNAME of the certificate is still missing. Nothing is ordered, reissued or cancelled by this.' );
		}

		return array();
	}

	public function on_event( string $event, Resource $r, array $ctx = array() ): void {
		unset( $event, $r, $ctx );
	}

	// -- Starting and keeping the watch in step with the order ------------------------------------------------

	/**
	 * Has the order a certificate now that matches its request (not waiting for a reissue)?
	 *
	 * @param Resource $r Resource.
	 */
	private static function issued( Resource $r ): bool {
		return '' !== trim( (string) $r->get( 'ssl_cert' ) ) && ! $r->get( 'ssl_reissue_pending' ) && ! $r->get( 'ssl_authority_reissue_at' );
	}

	/**
	 * Brings the watch in step with the order: starts it when the order has records to find, starts the wait again when the records
	 * changed (a reissue with a new request gives a new value), and takes a wait that the issue ended as found. Idempotent and cheap;
	 * called wherever the order is read (the wait, the daily read).
	 *
	 * @param Resource $r Resource.
	 */
	public static function sync( Resource $r ): void {
		$f = DnsWatch::feature( self::ID );

		if ( ! $f instanceof self || ! $f->active( $r ) ) {
			return;
		}

		$id      = $r->id();
		$records = self::records( $r );
		$sig     = md5( (string) wp_json_encode( $records ) );
		$known   = (string) $r->get( 'ssl_dns_sig' );
		$status  = (string) $r->get( 'ssl_dns' );
		$issued  = self::issued( $r );
		$now     = time();
		$items   = static fn( string $state ): array => array_map( static fn( $x ) => $x + array( 'status' => $state, 'seen' => array() ), $records );
		$row     = static fn( string $state ): array => array( array_merge( (array) $f->rows( $r )[0], array( 'status' => $state, 'reason' => '', 'seen' => array(), 'items' => $items( $state ) ) ) );
		$meta    = null;
		$look    = false;

		if ( '' === $status && '' === $known ) {
			// First sight. An order that already has its certificate is taken as found (the authority found the record, or there would be
			// none); the first daily look reads it for real. One that waits for validation starts the wait.
			$meta = $issued
				? array( 'ssl_dns' => 'ok', 'ssl_dns_since' => $now, 'ssl_dns_rows' => $row( 'ok' ), 'ssl_dns_mode' => $f->on_our_servers( $r ) ? 'ours' : 'external' )
				: array( 'ssl_dns' => 'waiting', 'ssl_dns_since' => $now, 'ssl_dns_checked' => null, 'ssl_dns_reminded' => 0, 'ssl_dns_rows' => array() );
			$look = ! $issued;
		} elseif ( $sig !== $known ) {
			// New records to find: the wait starts again, as for a new order.
			// Every mark of the old records goes (the engine's one list), so no loss, alert or mail mark of them survives.
			$meta = array_merge(
				array_fill_keys( DnsWatch::reset_marks( self::PREFIX ), 0 ),
				array(
					'ssl_dns'           => 'waiting',
					'ssl_dns_since'     => $now,
					'ssl_dns_checked'   => null,
					'ssl_dns_rows'      => array(),
					'ssl_dns_lost_keys' => array(),
				)
			);
			$look = true;
		} elseif ( 'waiting' === $status && $issued && 0 === (int) $r->get( 'ssl_dns_lost_pending' ) && 0 === (int) $r->get( 'ssl_dns_lost_at' ) ) {
			// Issued while we were still looking: the authority found the record before we did. No reminder is owed any more.
			$meta = array( 'ssl_dns' => 'ok', 'ssl_dns_rows' => $row( 'ok' ), 'ssl_dns_mode' => $f->on_our_servers( $r ) ? 'ours' : 'external' );
		}

		if ( null === $meta ) {
			return;
		}

		$meta['ssl_dns_sig'] = $sig;

		$stored = $r->locked(
			static function () use ( $id, $meta, $sig, $known ): bool {
				$fresh = Resource::find( $id );

				return $fresh && (string) $fresh->get( 'ssl_dns_sig' ) === $known && $fresh->set( $meta );
			}
		);

		if ( true === $stored && $look ) {
			DnsWatchJobs::look( $f, $id, 0 );
		}
	}

	/**
	 * The status of one record of the order for the validation box: ok, missing, conflict, or none when the watch has not looked.
	 *
	 * @param Resource $r    Resource.
	 * @param string   $name Record name.
	 */
	public static function status_of( Resource $r, string $name ): string {
		$stored = (array) $r->get( 'ssl_dns_rows' );
		$row    = is_array( $stored[0] ?? null ) ? $stored[0] : array();

		if ( array() === $row || '' === (string) $r->get( 'ssl_dns' ) || 0 === (int) $r->get( 'ssl_dns_checked' ) && 'ok' !== (string) $r->get( 'ssl_dns' ) ) {
			return 'none';
		}

		if ( 'ok' === ( $row['status'] ?? '' ) ) {
			return 'ok';
		}

		$name = strtolower( rtrim( $name, '.' ) );

		foreach ( (array) ( $row['items'] ?? array() ) as $item ) {
			if ( is_array( $item ) && ( $item['name'] ?? '' ) === $name ) {
				return in_array( (string) ( $item['status'] ?? '' ), array( 'ok', 'conflict' ), true ) ? (string) $item['status'] : 'missing';
			}
		}

		return 'none';
	}
}
