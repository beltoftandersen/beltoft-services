<?php
/** The account style sheets keep to one type scale and one set of colours. @package BeltoftServices */

namespace BeltoftServices\Tests;

final class AccountCssScaleTest extends TestCase {
	/** Sheets that only My Account pages load. */
	private const FILES = array( 'account', 'hosting', 'app-tab', 'console', 'db', 'dns-claim', 'firewall', 'mail', 'metrics', 'network-tab', 'ssl' );

	/** @return array<string,string> */
	private function sheets(): array {
		$out = array();
		foreach ( self::FILES as $name ) {
			$out[ $name ] = (string) file_get_contents( BELTOFT_SERVICES_PATH . "assets/$name.css" );
		}
		return $out;
	}

	public function test_font_sizes_are_the_scale(): void {
		$bad = array();
		foreach ( $this->sheets() as $name => $css ) {
			preg_match_all( '/font-size\s*:\s*([^;}]+)/', $css, $m );
			foreach ( $m[1] as $size ) {
				$size = trim( $size );
				if ( ! preg_match( '/^(14px|16px|20px|40px|inherit|var\(--bs-fs-(caption|small|body|heading|title)\)|max\(16px, var\(--bs-fs-body\)\))$/', $size ) ) {
					$bad[] = "$name: $size";
				}
			}
			preg_match_all( '/[^-]font\s*:\s*(\d+px)/', $css, $f );
			foreach ( $f[1] as $size ) {
				if ( ! in_array( $size, array( '14px', '16px', '20px', '40px' ), true ) ) {
					$bad[] = "$name: font $size";
				}
			}
		}
		$this->assertSame( array(), $bad );
	}

	public function test_token_sizes_are_the_scale(): void {
		$css = $this->sheets()['account'];
		preg_match_all( '/--bs-fs-[a-z]+\s*:\s*(\d+px)/', $css, $m );
		$this->assertCount( 5, $m[1] );
		foreach ( $m[1] as $size ) {
			$this->assertContains( $size, array( '14px', '16px', '20px', '40px' ) );
		}
	}

	public function test_colours_are_written_only_in_the_token_block(): void {
		$sheets = $this->sheets();
		$css    = $sheets['account'];
		$start  = strpos( $css, ':root {' );
		$end    = strpos( $css, "\n}\n", $start ) + 3;
		$this->assertSame( 0, $start > 0 ? preg_match_all( '/#[0-9a-fA-F]{3,8}\b/', substr( $css, 0, $start ) ) : 0, 'no colour before the token block' );
		$sheets['account'] = substr( $css, $end );
		$bad               = array();
		foreach ( $sheets as $name => $body ) {
			if ( preg_match_all( '/#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?)\(/', $body, $m ) ) {
				$bad[ $name ] = $m[0];
			}
		}
		$this->assertSame( array(), $bad );
		$this->assertSame( 0, preg_match( '/var\(--(?:navy|ink-soft|ink-mid|rule|bg-warm|bg-card|green)\b/', implode( '', $sheets ) ), 'site palette variables are not used directly' );
	}

	public function test_no_new_important_beyond_the_known_ones(): void {
		$total = 0;
		foreach ( $this->sheets() as $css ) {
			$total += substr_count( $css, '!important' );
		}
		$this->assertLessThanOrEqual( 51, $total );
	}
}
