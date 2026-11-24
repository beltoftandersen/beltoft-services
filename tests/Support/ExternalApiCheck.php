<?php
/**
 * The check behind the lists of names that code outside the plugin calls (tests/fixtures/external-api.php, and the list of a feature).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Support;

/**
 * Shared by ExternalApiTest and the hosting list's test.
 */
trait ExternalApiCheck {

	/**
	 * The names of a list that are gone or no longer public.
	 *
	 * @param array<string, string[]> $list Class (without the BeltoftServices\ prefix) to members.
	 * @return string[]
	 */
	private function missing_names( array $list ): array {
		$missing = array();

		foreach ( $list as $class => $members ) {
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

		return $missing;
	}
}
