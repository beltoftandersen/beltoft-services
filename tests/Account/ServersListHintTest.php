<?php
/** The hint under a memory bar keeps a technical term whole. @package BeltoftServices */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Tests\TestCase;

final class ServersListHintTest extends TestCase {
	public function test_the_agent_term_is_one_nowrap_span_and_the_rest_is_escaped(): void {
		$rows = array(
			array(
				'id'      => 1,
				'url'     => 'https://example.test/s/',
				'name'    => 'srv',
				'details' => '',
				'state'   => 'Running',
				'tone'    => 'ok',
				'note'    => '',
				'bars'    => array(
					array( 'label' => 'Memory', 'percent' => null, 'key' => 'mem', 'state' => 'no_agent', 'text' => '–', 'sub' => 'Memory needs the guest agent (qemu-guest-agent) <b>x</b> running.' ),
				),
			),
		);

		ob_start();
		wc_get_template( 'account/servers-list.php', array( 'rows' => $rows ), 'beltoft-services/', BELTOFT_SERVICES_PATH . 'templates/' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '(<span class="bs-term">qemu-guest-agent</span>)', $html );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $html, 'Nothing else becomes markup.' );
	}

	public function test_a_bar_has_no_hover_title_but_an_accessible_name(): void {
		$rows = array(
			array(
				'id' => 1, 'url' => 'https://example.test/s/', 'name' => 'srv', 'details' => '', 'state' => 'Running', 'tone' => 'ok', 'note' => '',
				'bars' => array( array( 'label' => 'Memory', 'percent' => 42, 'key' => 'mem', 'state' => 'ok' ) ),
			),
		);

		ob_start();
		wc_get_template( 'account/servers-list.php', array( 'rows' => $rows ), 'beltoft-services/', BELTOFT_SERVICES_PATH . 'templates/' );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'title=', $html );
		$this->assertStringContainsString( 'role="img" aria-label="Memory 42%"', $html );
	}
}
