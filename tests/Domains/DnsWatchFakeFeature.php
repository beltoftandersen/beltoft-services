<?php
/**
 * A feature for the DNS watch tests: records a and b are needed, c is recommended. What is "found" is set by the test.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsWatchFeature;
use BeltoftServices\Resource;

final class DnsWatchFakeFeature implements DnsWatchFeature {

	/** @var string[] Keys public DNS "holds" right now. */
	public static array $present = array();

	/** @var bool A lookup that fails. */
	public static bool $failing = false;

	/** @var bool What before_look() answers. */
	public static bool $before = true;

	/** @var array<int, string> Events the feature was told, in order. */
	public static array $events = array();

	public function id(): string {
		return 'fake';
	}

	public function label(): string {
		return 'Fake';
	}

	public function noun(): string {
		return 'fake DNS';
	}

	public function kind(): string {
		return 'fake';
	}

	public function prefix(): string {
		return 'fk_dns';
	}

	public function rows_key(): string {
		return 'fk_records';
	}

	public function mode_key(): string {
		return 'fk_mode';
	}

	public function hook( string $job ): string {
		return 'beltoft_services_test_fake_' . $job;
	}

	public function shows_status( Resource $r ): bool {
		return true;
	}

	public function rows( Resource $r ): array {
		return (array) $r->get( 'fk_records' );
	}

	public function status_in( string $status ): string {
		return $status;
	}

	public function status_out( string $status ): string {
		return $status;
	}

	public function before_look( Resource $r ): bool {
		return self::$before;
	}

	public function mails( string $event, Resource $r ): bool {
		return true;
	}

	public function owns( Resource $r ): bool {
		return 'fake' === $r->get( 'driver' );
	}

	public function active( Resource $r ): bool {
		return 'ready' === (string) $r->get( 'state' );
	}

	public function domain( Resource $r ): string {
		return (string) $r->get( 'fk_domain' );
	}

	public function zone_user( Resource $r ): int {
		return (int) ( $r->license()['user_id'] ?? 0 );
	}

	public function on_our_servers( Resource $r ): bool {
		return false;
	}

	public function needed(): array {
		return array( 'a', 'b' );
	}

	public function required( string $key ): bool {
		return in_array( $key, $this->needed(), true );
	}

	public function read_row( array $row, string $domain ): ?array {
		if ( self::$failing ) {
			return null;
		}

		$row['status'] = in_array( $row['key'], self::$present, true ) ? 'ok' : 'missing';
		$row['reason'] = '';

		return $row;
	}

	public function publish( int $user_id, string $domain, array $row ) {
		return array( 'conflict', 'unplaced', false );
	}

	public function missing_rows( Resource $r, bool $needed_only = false ): array {
		$out = array();

		foreach ( (array) $r->get( 'fk_records' ) as $row ) {
			if ( 'ok' !== $row['status'] && ( ! $needed_only || $this->required( $row['key'] ) ) ) {
				$out[] = array( 'key' => $row['key'], 'name' => $row['name'] . '.' . $this->domain( $r ), 'type' => $row['type'], 'value' => $row['value'], 'priority' => 0, 'status' => $row['status'] );
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
		return false;
	}

	public function held( Resource $r ): bool {
		return false;
	}

	public function copy( string $event, Resource $r ): array {
		return array(
			'dns_subject'   => 'The fake service for ' . $this->domain( $r ),
			'dns_effect'    => 'The fake thing stops.',
			'dns_where'     => 'the fake area',
			'dns_link'      => 'https://example.net/fake/',
			'dns_open_text' => 'Open the fake area',
		);
	}

	public function alert_lines( string $event, Resource $r, array $ctx = array() ): array {
		return array( 'Fake line for ' . $event );
	}

	public function on_event( string $event, Resource $r, array $ctx = array() ): void {
		self::$events[] = $event;
	}
}
