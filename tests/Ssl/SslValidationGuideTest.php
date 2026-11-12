<?php
/** The validation record of a certificate is drawn by the shared DNS guide. @package BeltoftServices */
namespace BeltoftServices\Tests\Ssl;

use BeltoftServices\Resource;
use BeltoftServices\Ssl\Panels;

require_once __DIR__ . '/SslTestCase.php';

class SslValidationGuideTest extends SslTestCase {

	private function drawn( Resource $r, array $rows ): string {
		$method = new \ReflectionMethod( Panels::class, 'validation' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( null, $r, $rows );

		return (string) ob_get_clean();
	}

	public function test_a_cname_to_add_by_hand_is_one_guide_without_a_status_or_a_check(): void {
		$r = $this->ssl_resource( array( 'meta' => array( 'ssl_validation' => 'dns', 'ssl_name' => 'www.example.com', 'ssl_domain' => 'example.com' ) ) );

		$html = $this->drawn( $r, array( array( 'type' => 'dns', 'domain' => 'example.com', 'name' => '_abc123.example.com', 'value' => 'abc123.validation.authority.example', 'status' => 'manual' ) ) );

		$this->assertStringContainsString( 'Validate example.com for the certificate', $html );
		$this->assertStringContainsString( 'The certificate authority issues the certificate after it finds this record. Nothing is issued until then.', $html );
		$this->assertStringContainsString( 'data-dns-copy="_abc123"', $html );
		$this->assertStringContainsString( 'data-dns-copy="abc123.validation.authority.example"', $html );
		$this->assertStringContainsString( 'Leave the record in place after the certificate is issued.', $html );
		$this->assertStringNotContainsString( 'Check now', $html, 'The authority looks for the record, not us.' );
		$this->assertStringNotContainsString( 'beltoft-dnsg__state', $html, 'No status: nothing of ours looks for it.' );
	}

	public function test_a_file_to_put_on_the_website_keeps_its_own_table(): void {
		$r    = $this->ssl_resource( array( 'meta' => array( 'ssl_validation' => 'file', 'ssl_name' => 'example.com', 'ssl_domain' => 'example.com' ) ) );
		$html = $this->drawn( $r, array( array( 'type' => 'file', 'domain' => 'example.com', 'name' => 'http://example.com/.well-known/pki-validation/x.txt', 'value' => 'content', 'status' => 'manual' ) ) );

		$this->assertStringContainsString( 'Put a file with this content on your website, at this address:', $html );
		$this->assertStringNotContainsString( 'beltoft-dnsg', $html );
	}
}
