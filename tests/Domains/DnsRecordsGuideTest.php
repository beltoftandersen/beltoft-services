<?php
/**
 * The shared DNS records guide: grouping, labels, Copy values, statuses and the collapsed state.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsRecordsGuide;
use BeltoftServices\Mail\MailDns;
use BeltoftServices\Tests\TestCase;

class DnsRecordsGuideTest extends TestCase {

	private const DKIM = 'v=DKIM1;k=rsa;t=s;s=email;p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAx3k4Jq1l0o9nYvHn8mN2m1Qp7yUe6rX0f8c2Z4sD5aGhVbT3wRjK9uLq1tPzE7oBnXcFvY2dHs6gMiA0kJlO5wSeUa8rTq4vZxCn3bNmP1yWdQfL9hGjK7tRsE2oXuVc5iBnA0pMlHzYw6DkJ3qFrTg8aS4eUvNxC1bOiPmL7yZhWdQkG2jRsE9tXuVf5nBaA3pMcHzYw0DkJ4qFrTg6aS8eUvNxC2bOiPmL1yZhWdQkG5jRsEwIDAQAB';

	/** @return array<string, mixed> */
	private function model( array $over = array() ): array {
		return array_merge(
			array(
				'id'        => 'mail',
				'domain'    => 'example.com',
				'title'     => 'Make email work on example.com',
				'why'       => 'Why it is needed.',
				'provider'  => '',
				'records'   => array(
					array( 'key' => 'mx', 'type' => 'MX', 'name' => 'example.com', 'value' => 'mail.example.net', 'priority' => 10, 'required' => true, 'purpose' => 'Sends email to us.', 'status' => 'missing' ),
					array( 'key' => 'dkim', 'type' => 'TXT', 'name' => 'dkim._domainkey.example.com', 'value' => self::DKIM, 'required' => true, 'purpose' => 'Signs email.', 'status' => 'ok' ),
					array( 'key' => 'dmarc', 'type' => 'TXT', 'name' => '_dmarc.example.com', 'value' => 'v=DMARC1; p=quarantine', 'required' => false, 'purpose' => 'Policy.', 'status' => 'wrong', 'seen' => array( str_repeat( 'x', 100 ) ) ),
				),
				'notes'     => array( 'A special case.' ),
				'done_text' => 'All needed records were found.',
				'check'     => array( 'attrs' => array( 'data-mail-dns-check' => '' ) ),
				'checked'   => 0,
				'interval'  => 600,
				'days'      => 14,
			),
			$over
		);
	}

	public function test_it_groups_needed_before_recommended_and_labels_the_names_for_a_provider(): void {
		$html = DnsRecordsGuide::html( $this->model() );

		$this->assertStringContainsString( 'Make email work on example.com', $html );
		$this->assertStringContainsString( 'Why it is needed.', $html );
		$this->assertLessThan( strpos( $html, 'Recommended' ), strpos( $html, 'Needed' ), 'Needed comes first.' );
		$this->assertSame( 1, substr_count( $html, '<h6 class="beltoft-dnsg__group">Needed</h6>' ) );
		$this->assertSame( 1, substr_count( $html, '<h6 class="beltoft-dnsg__group">Recommended</h6>' ) );
		$this->assertStringContainsString( 'data-dns-copy="@"', $html, 'The domain itself is @.' );
		$this->assertStringContainsString( 'data-dns-copy="dkim._domainkey"', $html, 'The part in front of the domain.' );
		$this->assertStringContainsString( 'Full name: dkim._domainkey.example.com', $html, 'The full name stays available.' );
		$this->assertStringContainsString( 'About the name @', $html, 'The @ note shows where an @ name is used.' );
		$this->assertStringContainsString( 'A special case.', $html );
		$this->assertStringContainsString( 'Priority', $html );
		$this->assertStringContainsString( 'Sends email to us.', $html );
		$this->assertSame( 'dkim._domainkey', DnsRecordsGuide::label( 'dkim._domainkey.example.com', 'example.com' ) );
		$this->assertSame( '@', DnsRecordsGuide::label( 'example.com', 'example.com' ) );
	}

	public function test_a_long_value_is_copied_whole_from_one_button(): void {
		$html = DnsRecordsGuide::html( $this->model() );

		$this->assertGreaterThan( 300, strlen( self::DKIM ) );
		$this->assertSame( 1, substr_count( $html, 'data-dns-copy="' . self::DKIM . '"' ), 'The whole value is on the Copy button, so nobody selects it by hand.' );
		$this->assertStringContainsString( '<code class="beltoft-dnsg__value">' . self::DKIM . '</code>', $html, 'And it is shown in full, wrapped in a box.' );
		$this->assertStringContainsString( 'aria-label="Copy value"', $html );
	}

	public function test_it_says_where_the_record_stands_in_words(): void {
		$this->assertSame( array( 'ok', 'Found' ), DnsRecordsGuide::state( array( 'status' => 'ok' ) ) );
		$this->assertSame( array( 'missing', 'Not found yet' ), DnsRecordsGuide::state( array( 'status' => 'missing' ) ) );
		$this->assertSame( array( 'unchecked', 'Not checked yet' ), DnsRecordsGuide::state( array( 'status' => 'unchecked' ) ) );
		$this->assertSame( array( 'wrong', 'Another value is there now' ), DnsRecordsGuide::state( array( 'status' => 'conflict' ) ) );
		$this->assertSame( array( 'wrong', 'Another value is there now: abc' ), DnsRecordsGuide::state( array( 'status' => 'wrong', 'seen' => array( 'abc' ) ) ) );
		$this->assertSame( array( 'wrong', 'Points to 203.0.113.9 now' ), DnsRecordsGuide::state( array( 'status' => 'wrong', 'seen' => array( '203.0.113.9' ), 'wrong_text' => 'Points to %s now' ) ) );

		$long = DnsRecordsGuide::state( array( 'status' => 'wrong', 'seen' => array( str_repeat( 'x', 100 ) ) ) );
		$this->assertSame( 'Another value is there now: ' . str_repeat( 'x', 60 ) . '...', $long[1], 'Only the first 60 characters of the other value.' );

		$html = DnsRecordsGuide::html( $this->model() );
		$this->assertStringContainsString( 'is-ok">Found', $html );
		$this->assertStringContainsString( 'is-missing">Not found yet', $html );
		$this->assertStringContainsString( 'is-wrong">Another value is there now: ' . str_repeat( 'x', 60 ), $html );
	}

	public function test_it_has_steps_and_a_check_with_the_real_interval(): void {
		$html = DnsRecordsGuide::html( $this->model( array( 'provider' => 'one.com', 'checked' => 1700000000 ) ) );

		$this->assertStringContainsString( 'Log in where the DNS of example.com is managed.', $html );
		$this->assertStringContainsString( 'For this domain it looks like one.com.', $html );
		$this->assertStringContainsString( 'Add the records below.', $html );
		$this->assertStringContainsString( 'Come back here and press Check now.', $html );
		$this->assertStringContainsString( '<button type="button" class="button woocommerce-button" data-mail-dns-check="">Check now</button>', $html );
		$this->assertStringContainsString( 'We also check by ourselves every 10 minutes for 14 days.', $html );
		$this->assertStringContainsString( 'Last checked', $html );

		$no_limit = DnsRecordsGuide::html( $this->model( array( 'days' => 0 ) ) );
		$this->assertStringContainsString( 'We also check by ourselves every 10 minutes.', $no_limit );

		$no_check = DnsRecordsGuide::html( $this->model( array( 'check' => null, 'interval' => 0 ) ) );
		$this->assertStringNotContainsString( 'Check now', $no_check );
		$this->assertStringNotContainsString( 'We also check', $no_check );
	}

	public function test_when_everything_needed_is_found_it_collapses_to_one_line_and_keeps_missing_recommended_ones(): void {
		$model                         = $this->model();
		$model['records'][0]['status'] = 'ok';

		$this->assertTrue( DnsRecordsGuide::done( $model ) );
		$html = DnsRecordsGuide::html( $model );

		$this->assertStringContainsString( 'data-state="done"', $html );
		$this->assertStringContainsString( 'All needed records were found.', $html );
		$this->assertStringNotContainsString( 'beltoft-dnsg__steps', $html );
		$this->assertStringNotContainsString( 'Needed', $html );
		$this->assertStringNotContainsString( 'Check now', $html, 'Nothing left to check for.' );
		$this->assertStringContainsString( 'Recommended', $html, 'A missing recommended record is still listed.' );
		$this->assertStringContainsString( 'data-dns-copy="_dmarc"', $html );
		$this->assertStringNotContainsString( 'data-dns-copy="dkim._domainkey"', $html, 'Found records are not listed again.' );

		$model['records'][2]['status'] = 'ok';
		$this->assertStringNotContainsString( 'Recommended', DnsRecordsGuide::html( $model ) );

		// No needed record at all is never "done".
		$this->assertFalse( DnsRecordsGuide::done( $this->model( array( 'records' => array( array( 'type' => 'A', 'name' => 'example.com', 'value' => '1.2.3.4', 'required' => false, 'status' => 'ok' ) ) ) ) ) );
	}

	public function test_a_feature_can_replace_the_steps_and_set_attributes_on_the_section(): void {
		$html = DnsRecordsGuide::html( $this->model( array( 'steps' => array( 'We add these for you.' ), 'attrs' => array( 'data-hosting-scope' => '' ), 'class' => 'extra' ) ) );

		$this->assertStringContainsString( '<ol class="beltoft-dnsg__steps"><li>We add these for you.</li></ol>', $html );
		$this->assertStringContainsString( ' data-hosting-scope ', $html . ' ' );
		$this->assertStringContainsString( 'class="beltoft-dnsg extra"', $html );
	}

	public function test_values_are_escaped(): void {
		$html = DnsRecordsGuide::html( $this->model( array( 'records' => array( array( 'type' => 'TXT', 'name' => 'example.com', 'value' => '"><script>alert(1)</script>', 'required' => true, 'status' => 'missing' ) ) ) ) );

		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_the_spf_merge_adds_our_include_before_the_all_and_leaves_what_it_cannot_work_out(): void {
		$this->settings( array( 'mail_spf_include' => '_spf.mail.example.net' ) );

		$this->assertSame( 'v=spf1 include:_spf.google.com include:_spf.mail.example.net ~all', MailDns::merge_spf( 'v=spf1 include:_spf.google.com ~all' ) );
		$this->assertSame( 'v=spf1 ip4:192.0.2.1 include:_spf.mail.example.net -all', MailDns::merge_spf( 'v=spf1 ip4:192.0.2.1 -all' ) );
		$this->assertSame( 'v=spf1 a include:_spf.mail.example.net', MailDns::merge_spf( 'v=spf1 a' ) );
		$this->assertSame( 'v=spf1 include:_spf.mail.example.net ~all', MailDns::merge_spf( 'v=spf1 include:_spf.mail.example.net ~all' ), 'Ours is there already.' );
		$this->assertSame( '', MailDns::merge_spf( 'v=spf1 redirect=_spf.other.example' ), 'A redirect cannot be merged.' );
		$this->assertSame( '', MailDns::merge_spf( 'google-site-verification=abc' ) );
	}
}
