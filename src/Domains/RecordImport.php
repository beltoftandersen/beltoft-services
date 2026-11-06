<?php
/**
 * Brings the records of a zone file into a domain's DNS zone: a preview, then a merge.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * The parser is ZoneFile (also used by the DNS hosting claim). A set (name and type) in the file replaces the same set in the
 * zone; every other record of the zone stays. Rows are checked with Records::validate before anything is written.
 */
final class RecordImport {

	/**
	 * The texts of the import box (assets/domain.js sets them with textContent).
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			'label'    => __( 'Zone file', 'beltoft-services' ),
			'choose'   => __( 'Choose a zone file', 'beltoft-services' ),
			'paste'    => __( 'Or paste the text of the file', 'beltoft-services' ),
			'preview'  => __( 'Preview', 'beltoft-services' ),
			'add'      => __( 'Add these records', 'beltoft-services' ),
			'busy'     => __( 'Reading…', 'beltoft-services' ),
			'adding'   => __( 'Adding…', 'beltoft-services' ),
			'empty'    => __( 'Choose a file or paste its text first.', 'beltoft-services' ),
			'tooLarge' => __( 'This file is too large. A zone file can be at most 100 KB.', 'beltoft-services' ),
			'notText'  => __( 'This file is not a text zone file.', 'beltoft-services' ),
			'nothing'  => __( 'No records to add.', 'beltoft-services' ),
			'new'      => __( 'Will be added', 'beltoft-services' ),
			'replace'  => __( 'Will replace the records you have now', 'beltoft-services' ),
			'same'     => __( 'Already the same, no change', 'beltoft-services' ),
			'skipped'  => __( 'Skipped', 'beltoft-services' ),
			'was'      => __( 'Now', 'beltoft-services' ),
			'done'     => __( 'The records were imported.', 'beltoft-services' ),
			'failed'   => __( 'Not added', 'beltoft-services' ),
		);
	}

	/**
	 * What a zone file would do to a zone.
	 *
	 * @param string $zone Zone (the domain).
	 * @param string $text Zone file text.
	 * @param array  $have The zone's rows now (Records::to_rows), for the conflicts.
	 * @return array{sets: array<int, array<string, mixed>>, skipped: array<int, array{line: int, text: string, reason: string}>}|\WP_Error
	 */
	public static function plan( string $zone, string $text, array $have ) {
		$parsed = ZoneFile::parse( $text, $zone );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$skipped = (array) $parsed['skipped'];
		$groups  = array();

		foreach ( (array) $parsed['records'] as $row ) {
			$row  = array_intersect_key( (array) $row, array_flip( array( 'name', 'type', 'ttl', 'value', 'priority', 'weight', 'port', 'flag', 'tag' ) ) );
			$fqdn = Records::fqdn( $zone, (string) ( $row['name'] ?? '' ), (string) ( $row['type'] ?? '' ) );

			if ( is_wp_error( $fqdn ) ) {
				$skipped[] = array( 'line' => 0, 'text' => (string) ( $row['name'] ?? '' ) . ' ' . (string) ( $row['type'] ?? '' ), 'reason' => $fqdn->get_error_message() );
				continue;
			}

			$checked = Records::validate( $zone, array( $row ) );

			if ( is_wp_error( $checked ) ) {
				$skipped[] = array( 'line' => 0, 'text' => self::label( $row ), 'reason' => $checked->get_error_message() );
				continue;
			}

			$groups[ strtolower( $fqdn ) . '|' . strtoupper( (string) $row['type'] ) ][] = $row;
		}

		$existing = array();

		foreach ( $have as $old ) {
			$fqdn = Records::fqdn( $zone, (string) $old['name'], (string) $old['type'] );

			if ( ! is_wp_error( $fqdn ) ) {
				$existing[ strtolower( $fqdn ) . '|' . strtoupper( (string) $old['type'] ) ][] = self::shown( $old );
			}
		}

		$sets = array();

		foreach ( $groups as $key => $rows ) {
			$new = array_map( array( __CLASS__, 'shown' ), $rows );
			$old = $existing[ $key ] ?? array();
			$cmp = $new;
			$was = $old;
			sort( $cmp );
			sort( $was );

			$sets[] = array(
				'name'   => (string) $rows[0]['name'],
				'type'   => strtoupper( (string) $rows[0]['type'] ),
				'ttl'    => (int) ( $rows[0]['ttl'] ?? 3600 ),
				'values' => $new,
				'old'    => $old,
				'status' => ! $old ? 'new' : ( $cmp === $was ? 'same' : 'replace' ),
				'rows'   => $rows,
			);
		}

		return array( 'sets' => $sets, 'skipped' => array_values( $skipped ) );
	}

	/**
	 * Writes the sets that are new or different, one set at a time under the zone lock (Records::change). A set that cannot be written
	 * is reported, the others still go in.
	 *
	 * @param string $zone Zone.
	 * @param array  $sets Sets from plan().
	 * @return array{added: int, replaced: int, unchanged: int, failed: array<int, array{name: string, type: string, reason: string}>}
	 */
	public static function apply( string $zone, array $sets ): array {
		$out = array( 'added' => 0, 'replaced' => 0, 'unchanged' => 0, 'failed' => array() );

		foreach ( $sets as $set ) {
			if ( 'same' === $set['status'] ) {
				++$out['unchanged'];
				continue;
			}

			$rows = array_map( static fn( $row ) => array_diff_key( (array) $row, array( 'name' => 1, 'type' => 1 ) ), (array) $set['rows'] );
			$done = Records::change( $zone, (string) $set['name'], (string) $set['type'], $rows );

			if ( is_wp_error( $done ) ) {
				$out['failed'][] = array( 'name' => (string) $set['name'], 'type' => (string) $set['type'], 'reason' => $done->get_error_message() );
				continue;
			}

			'new' === $set['status'] ? ++$out['added'] : ++$out['replaced'];
		}

		return $out;
	}

	/**
	 * A row as one line of text.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function shown( array $row ): string {
		return trim( ( isset( $row['priority'] ) ? $row['priority'] . ' ' : '' ) . (string) ( $row['value'] ?? '' ) );
	}

	/**
	 * A row for a message.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function label( array $row ): string {
		return trim( (string) ( $row['name'] ?? '' ) . ' ' . (string) ( $row['type'] ?? '' ) . ' ' . self::shown( $row ) );
	}
}
