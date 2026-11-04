<?php
/**
 * The Delete block hands its word and scope to the script, which checks the word before it asks. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Usage\CustomerDelete;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Markup of the Delete block and the script it loads.
 */
final class DeleteStatusTest extends UsageTestCase {

	public function test_the_block_names_the_word_and_the_scope_and_has_no_notice_spot(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$r    = $this->usage_vm( $v['u-None'], $user, 5 );

		$beltoft_services_delete_id = $r->id();
		ob_start();
		include BELTOFT_SERVICES_PATH . 'templates/account/delete.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-delete-word="' . esc_attr( CustomerDelete::word( $r ) ) . '"', $html );
		$this->assertStringContainsString( 'data-bs-scope="' . $r->id() . '"', $html );
		$this->assertStringContainsString( 'data-delete-ask=', $html );
		$this->assertStringNotContainsString( 'data-delete-notice', $html );
	}

	public function test_the_script_depends_on_the_status_script_and_has_the_typed_word_line(): void {
		wp_dequeue_script( CustomerDelete::HANDLE );
		wp_deregister_script( CustomerDelete::HANDLE );
		CustomerDelete::assets();

		$this->assertContains( \BeltoftServices\Status::HANDLE, wp_scripts()->registered[ CustomerDelete::HANDLE ]->deps );
		$this->assertStringContainsString( 'Type %s to confirm the deletion.', (string) wp_scripts()->get_data( CustomerDelete::HANDLE, 'data' ) );
	}
}
