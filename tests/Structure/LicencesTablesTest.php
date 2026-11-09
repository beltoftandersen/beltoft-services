<?php
/**
 * Only Resource reads the beltoft-licenses tables.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * `Install::tables()` names the beltoft-licenses tables. Selecting activations (live ids, meta
 * lookups) is Resource's job, so a change in licences' storage breaks one class. Files that still
 * need the tables for something Resource does not offer are listed with the reason; the list only shrinks.
 */
class LicencesTablesTest extends TestCase {

	/**
	 * File => why it reads or writes a licences table itself.
	 *
	 * @return array<string, string>
	 */
	private static function allowed(): array {
		return array(
			'IntroCoupon.php'          => 'selects licenses by product slug and reads user and email; Licenses has no lookup by slug',
			'Account/Overview.php'     => 'reads the events table for the activity list; licences has no events API',
			'Usage/Meter.php'          => 'sums usage over licenses and activations in one joined query',
			'Usage/Account.php'        => 'writes licenses.source = usage; Licenses::update() does not allow source (fix in licences first)',
		);
	}

	public function test_licences_tables_are_named_in_resource_only(): void {
		$root  = dirname( __DIR__, 2 ) . '/src';
		$found = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$rel = substr( $file->getPathname(), strlen( $root ) + 1 );

			if ( 'Resource.php' === $rel ) {
				continue;
			}

			if ( preg_match( '/\bInstall::tables\s*\(/', (string) file_get_contents( $file->getPathname() ) ) ) {
				$found[] = $rel;
			}
		}

		sort( $found );

		$allowed = array_keys( self::allowed() );
		sort( $allowed );

		$this->assertSame( array(), array_values( array_diff( $found, $allowed ) ), 'New direct use of the licences tables: add a Resource method instead.' );
		$this->assertSame( array(), array_values( array_diff( $allowed, $found ) ), 'Stale entry: the file no longer uses the licences tables, remove it from the list.' );
	}
}
