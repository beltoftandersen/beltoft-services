<?php
/**
 * The names that code outside this plugin calls stay public.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * Site themes and add-on plugins call a small set of this plugin's classes directly. Nothing inside the plugin
 * may call some of them, so they look like dead code. tests/fixtures/external-api.php lists every such name;
 * removing, renaming, moving or hiding one fails here. A class that moves keeps its old name working (the
 * autoloader's alias map) until every caller has been changed, and the list is then updated in the same commit.
 */
class ExternalApiTest extends TestCase {

	public function test_every_name_called_from_outside_exists_and_is_public(): void {
		$missing = array();

		foreach ( require dirname( __DIR__ ) . '/fixtures/external-api.php' as $class => $members ) {
			$fqcn = 'BeltoftServices\\' . $class;

			if ( ! class_exists( $fqcn ) && ! interface_exists( $fqcn ) ) {
				$missing[] = $class;
				continue;
			}

			foreach ( $members as $member ) {
				if ( '()' === substr( $member, -2 ) ) {
					$name = substr( $member, 0, -2 );

					if ( ! method_exists( $fqcn, $name ) || ! ( new \ReflectionMethod( $fqcn, $name ) )->isPublic() ) {
						$missing[] = $class . '::' . $member;
					}
				} elseif ( ! defined( $fqcn . '::' . $member ) || ! ( new \ReflectionClassConstant( $fqcn, $member ) )->isPublic() ) {
					$missing[] = $class . '::' . $member;
				}
			}
		}

		$this->assertSame( array(), $missing, 'Names called from outside the plugin that are gone or no longer public.' );
	}
}
