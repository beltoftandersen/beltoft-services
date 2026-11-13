<?php
/**
 * An error or a confirmation in My Account is shown once, in the status bar at the top, never repeated next to its field.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\Flash;
use BeltoftServices\GiftCredit\Page;
use BeltoftServices\Status;
use BeltoftServices\Tests\TestCase;

class SingleMessageTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create() );
		wc_clear_notices();
	}

	public function tear_down(): void {
		wc_clear_notices();
		wp_set_current_user( 0 );
		wp_dequeue_script( Status::HANDLE );
		parent::tear_down();
	}

	private function js( string $name ): string {
		return (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/' . $name );
	}

	public function test_a_refused_credit_code_is_in_the_page_once(): void {
		if ( ! class_exists( '\Bgca\Plugin' ) || ! \Bgca\Plugin::requirements_met() ) {
			$this->markTestSkipped( 'The gift card plugin and its add-on are not loaded.' );
		}

		$this->settings( array( 'gift_credit' => 1 ) );
		wc_add_notice( 'That code is not valid.', 'error', array( 'beltoft_field' => 'code' ) );

		$html = Page::html();

		$this->assertSame( 1, substr_count( $html, 'That code is not valid.' ) );
		$this->assertStringNotContainsString( 'woocommerce-error', $html );
	}

	public function test_a_notice_nobody_printed_goes_to_the_status_bar_once_and_is_cleared(): void {
		set_current_screen( 'front' );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'woocommerce_myaccount_page_id', $page );
		$this->go_to( get_permalink( $page ) );
		wp_enqueue_script( Status::HANDLE );
		wc_add_notice( 'This page expired. Reload it and try again.', 'error' );
		wc_add_notice( 'Auto-renew is off.', 'success' );

		ob_start();
		Flash::print();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, 'This page expired. Reload it and try again.' ) );
		$this->assertStringContainsString( '<p hidden data-bs-flash="fail">This page expired. Reload it and try again.</p>', $html );
		$this->assertStringContainsString( '<p hidden data-bs-flash="done">Auto-renew is off.</p>', $html );
		$this->assertSame( array(), wc_get_notices() );

		ob_start();
		Flash::print();
		$this->assertSame( '', (string) ob_get_clean(), 'Nothing is told twice.' );
	}

	public function test_the_status_script_marks_a_failed_field_but_prints_no_text_next_to_it(): void {
		$source = $this->js( 'status.js' );

		$this->assertStringNotContainsString( 'bs-field-error', $source );
		$this->assertStringContainsString( "setAttribute( 'aria-invalid', 'true' )", $source );
		$this->assertStringNotContainsString( 'insertBefore( node', $source );
	}

	public function test_no_script_of_the_account_area_prints_a_second_copy_of_a_message(): void {
		foreach ( glob( BELTOFT_SERVICES_PATH . 'assets/*.js' ) as $file ) {
			$name = basename( $file );

			if ( false !== strpos( $file, '/novnc/' ) ) {
				continue;
			}

			$source = (string) file_get_contents( $file );

			$this->assertStringNotContainsString( 'bs-field-error', $source, $name );

			if ( 'status.js' !== $name ) {
				$this->assertStringNotContainsString( "querySelectorAll( '[data-bs-flash]'", $source, $name . ' leaves the flash lines to status.js.' );
				$this->assertStringNotContainsString( "querySelectorAll( '.beltoft-services-credit [data-bs-flash]'", $source, $name );
			}
		}
	}

	public function test_the_hosting_panel_keeps_no_message_line_of_its_own(): void {
		$this->assertStringNotContainsString( 'beltoft-hosting-message', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'src/Hosting/HostingAccount.php' ) );
		$this->assertStringNotContainsString( 'line.textContent', $this->js( 'hosting.js' ) );
		$this->assertStringContainsString( 'S.fail( null, message )', $this->js( 'hosting.js' ) );
	}

	public function test_a_picker_error_line_is_taken_out_of_the_page_and_told_in_the_bar(): void {
		$source = $this->js( 'picker.js' );

		$this->assertStringContainsString( 'line.parentNode.removeChild( line )', $source );
		$this->assertStringContainsString( 'window.beltoftStatus.fail( null, last.text, last.field )', $source );
	}

	public function test_the_api_tokens_page_prints_its_message_once(): void {
		$template = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'templates/account/api-tokens.php' );

		$this->assertSame( 1, substr_count( $template, "esc_html( \$result['message'] )" ) );
	}
}
