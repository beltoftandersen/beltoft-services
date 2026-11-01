<?php
/** The owner does not want the middle dot (·) anywhere customers or admins read. @package BeltoftServices */

namespace BeltoftServices\Tests;

final class NoMiddleDotTest extends TestCase {
	public function test_no_middle_dot_in_code_templates_or_assets(): void {
		$hits = array();
		foreach ( array( 'src', 'templates', 'assets' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( BELTOFT_SERVICES_PATH . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( ! preg_match( '/\.(php|js|css|html)$/', $file->getFilename() ) || str_contains( $file->getFilename(), '.min.' ) ) {
					continue;
				}
				$code = (string) file_get_contents( $file->getPathname() );
				if ( str_contains( $code, "\u{00B7}" ) || stripos( $code, '&middot;' ) !== false || str_contains( $code, '&#183;' ) ) {
					$hits[] = substr( $file->getPathname(), strlen( BELTOFT_SERVICES_PATH ) );
				}
			}
		}
		$this->assertSame( array(), $hits );
	}
}
