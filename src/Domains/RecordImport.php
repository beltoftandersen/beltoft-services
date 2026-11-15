<?php
/**
 * Brings the records of a zone file into a domain's DNS zone: a preview, then a merge.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

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

	/** Resource meta that holds a queued import: the sets still to write and how far the job is. Cleared when the job ends. */
	public const JOB = 'dns_import_job';

	/** Error codes of Records::change() that mean this set can never be written (the others are tried again). */
	private const FINAL = array( 'bad_record', 'cname_conflict', 'too_many_records', 'records_off', 'exists', 'stale', 'not_ours' );

	/**
	 * Queues the writing of previewed sets (the shared action `dns_import`). The sets are kept in the resource's meta, not in the job's
	 * arguments, so the job can go on where it stopped.
	 *
	 * @param Resource $r    Resource.
	 * @param array    $sets Sets from plan().
	 * @return true|\WP_Error
	 */
	public static function queue( Resource $r, array $sets ) {
		$todo      = array();
		$unchanged = 0;

		foreach ( $sets as $set ) {
			if ( 'same' === $set['status'] ) {
				++$unchanged;
				continue;
			}

			$todo[] = array( 'name' => (string) $set['name'], 'type' => (string) $set['type'], 'status' => (string) $set['status'], 'rows' => array_values( (array) $set['rows'] ) );
		}

		return Jobs::start_action(
			$r,
			'dns_import',
			__( 'Adding DNS records', 'beltoft-services' ),
			array(),
			static fn() => $r->set( array( self::JOB => array( 'sets' => $todo, 'next' => 0, 'unchanged' => $unchanged, 'added' => 0, 'replaced' => 0, 'failed' => array() ) ) )
				? true
				: new \WP_Error( 'failed', __( 'This was not done. Nothing was changed.', 'beltoft-services' ), array( 'status' => 500 ) )
		);
	}

	/**
	 * The job of `dns_import` (Actions::run): writes the queued sets one at a time through Records::change (the zone owner, with its lock
	 * and checks), keeping its place after every set. A set that can never be written is reported and the others still go in; a set that
	 * failed for a passing reason is tried again with the whole job. A replay starts at the set it had reached, and a set that was written
	 * already is written again as it is, which changes nothing. The report is the outcome (Jobs::TEXT).
	 *
	 * @param Resource $r Resource.
	 */
	public static function run_job( Resource $r ): StepResult {
		$job  = $r->get( self::JOB );
		$zone = (string) $r->get( 'domain' );

		if ( ! is_array( $job ) || ! isset( $job['sets'] ) ) {
			// Replayed after it ended: nothing is left and nothing is lost.
			return StepResult::done();
		}

		$sets = (array) $job['sets'];

		for ( $i = (int) $job['next']; $i < count( $sets ); $i++ ) {
			$set  = (array) $sets[ $i ];
			$rows = array_map( static fn( $row ) => array_diff_key( (array) $row, array( 'name' => 1, 'type' => 1 ) ), (array) $set['rows'] );
			$done = Records::change( $zone, (string) $set['name'], (string) $set['type'], $rows );

			if ( is_wp_error( $done ) ) {
				if ( ! in_array( $done->get_error_code(), self::FINAL, true ) ) {
					return StepResult::retry( 60, 'DNS records could not be saved: ' . $done->get_error_code() );
				}

				$job['failed'][] = array( 'name' => (string) $set['name'], 'type' => (string) $set['type'], 'reason' => $done->get_error_message() );
			} else {
				'new' === $set['status'] ? ++$job['added'] : ++$job['replaced'];
			}

			$job['next'] = $i + 1;

			if ( ! $r->set( array( self::JOB => $job ) ) ) {
				return StepResult::retry( 60, 'The import could not be saved.' );
			}
		}

		$failed = (array) $job['failed'];
		$meta   = array( self::JOB => null );

		if ( ! $failed && ( (int) $job['added'] + (int) $job['replaced'] + (int) $job['unchanged'] ) > 0 ) {
			// A zone made for a transfer (or for the move to our DNS) notes that the customer brought their records.
			$meta['dns_imported_at'] = time();
		}

		$text = self::report( $job );

		return $failed ? StepResult::fail( 'Some DNS records were not added.', $meta + array( Jobs::TEXT => $text ) ) : StepResult::done( $meta + array( Jobs::TEXT => $text ) );
	}

	/**
	 * The report of an import, for the customer.
	 *
	 * @param array<string, mixed> $job The job state.
	 */
	public static function report( array $job ): string {
		/* translators: 1: number of record sets added, 2: number replaced. */
		$text = sprintf( __( 'The records were imported: %1$d added, %2$d replaced.', 'beltoft-services' ), (int) $job['added'], (int) $job['replaced'] );

		if ( empty( $job['failed'] ) ) {
			return $text;
		}

		$list = array_map( static fn( $f ) => $f['name'] . ' ' . $f['type'] . ' (' . $f['reason'] . ')', array_slice( (array) $job['failed'], 0, 5 ) );

		/* translators: 1: the part about what was added, 2: the records that were not added, with the reason of each. */
		return sprintf( __( '%1$s Not added: %2$s', 'beltoft-services' ), $text, implode( ', ', $list ) );
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
