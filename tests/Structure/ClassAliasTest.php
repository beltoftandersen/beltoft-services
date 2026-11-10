<?php
/**
 * The autoloader's alias map keeps old class names working.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * A moved class that outside code still calls is listed in BELTOFT_SERVICES_CLASS_ALIASES (old name => new name).
 */
class ClassAliasTest extends TestCase {

	public function test_every_alias_target_exists_and_the_old_name_resolves_to_it(): void {
		$this->assertIsArray( BELTOFT_SERVICES_CLASS_ALIASES );

		foreach ( BELTOFT_SERVICES_CLASS_ALIASES as $old => $new ) {
			$this->assertTrue( class_exists( $new ) || interface_exists( $new ) || trait_exists( $new ), $new );
			$this->assertTrue( class_exists( $old ) || interface_exists( $old ) || trait_exists( $old ), $old );
			$this->assertSame( ( new \ReflectionClass( $new ) )->getName(), ( new \ReflectionClass( $old ) )->getName(), $old );
		}
	}
}
