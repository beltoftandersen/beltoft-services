<?php
/** The domain emails: transferred and registered. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class DomainEmailsTest extends TestCase {
	/** @var array<int, array<string, mixed>> */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
	}

	public function tear_down(): void {
		$flag = new \ReflectionProperty( \BeltoftLicenses\Import::class, 'running' );
		$flag->setAccessible( true );
		$flag->setValue( null, false );
		parent::tear_down();
	}

	private function domain( string $mode, string $driver = 'domain' ): Resource {
		$product = $this->service_product( $driver, array(), array( 'slug' => $driver . '-mail-test' ) );
		$user    = self::factory()->user->create( array( 'first_name' => 'Dana' ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$drv     = 'dns' === $driver ? new DnsDriver() : new DomainDriver();
		$r       = Resource::create( $license, $drv, array(), array( 'domain' => 'mail-example.net', 'mode' => $mode, 'state' => 'provisioning', 'expires_at' => '2027-10-26 00:00:00', 'auth' => 'AUTH-CODE-NEVER-IN-MAIL', 'remote_id' => '123' ) );
		$this->assertInstanceOf( Resource::class, $r );
		return Resource::find( $r->id() );
	}

	private function ready( Resource $r ): void {
		Emails::on_state_changed( Resource::find( $r->id() ), 'provisioning', 'ready' );
	}

	public function test_a_finished_transfer_sends_the_transferred_email_once_instead_of_ready(): void {
		$r = $this->domain( 'transfer' );
		$this->assertTrue( $r->set( array( 'dns_external' => true ) ) );
		$this->ready( $r );
		$this->ready( $r );
		$this->assertCount( 1, $this->mails );
		$mail = $this->mails[0];
		$this->assertSame( 'Your domain mail-example.net is now with us', $mail['subject'] );
		$this->assertStringContainsString( 'Dana', $mail['message'] );
		$this->assertStringContainsString( wp_date( \BeltoftServices\Emails\ServiceEmail::DATE, strtotime( '2027-10-26 UTC' ) ), $mail['message'], 'The renewal date.' );
		$this->assertStringContainsString( esc_url( \BeltoftServices\Account\Domains::url( $r->license() ) ), $mail['message'], 'It links to the domain\'s own page in My Account.' );
		$this->assertStringContainsString( 'Your DNS stays where it is now. Your name servers do not change.', $mail['message'] );
		$this->assertStringNotContainsString( 'uses our name servers', $mail['message'] );
		$this->assertStringNotContainsString( 'Your DNS stays as it is.', $mail['message'] );
		$this->assertStringNotContainsString( 'AUTH-CODE-NEVER-IN-MAIL', $mail['subject'] . $mail['message'] );
		$this->assertStringNotContainsString( ' is ready', $mail['subject'], 'Not the generic ready mail.' );
	}

	public function test_a_transfer_of_a_domain_our_dns_already_served_says_the_dns_stays(): void {
		$r    = $this->domain( 'transfer' );
		$user = (int) $r->license()['user_id'];
		$claim = \BeltoftServices\Domains\DnsClaims::create( $user, 'mail-example.net' );
		$this->assertTrue( \BeltoftServices\Domains\DnsAssignments::reserve( $user, (int) $claim['id'], 'mail-example.net' ) );
		$this->assertTrue( \BeltoftServices\Domains\DnsAssignments::activate( 'mail-example.net', $user, (int) $r->license()['id'], $r->id() ) );
		$this->ready( $r );
		$this->assertStringContainsString( 'Your DNS stays as it is.', $this->mails[0]['message'] );
		$this->assertStringNotContainsString( 'uses our name servers', $this->mails[0]['message'] );
	}

	public function test_a_registration_sends_its_own_email(): void {
		$r = $this->domain( 'register' );
		$this->ready( $r );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'Your domain mail-example.net is registered', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'ns1.example.net, ns2.example.net', $this->mails[0]['message'] );
		$this->assertStringContainsString( esc_url( \BeltoftServices\Account\Domains::url( $r->license() ) ), $this->mails[0]['message'], 'The email links to the domain\'s page.' );
	}

	public function test_dns_hosting_keeps_the_generic_ready_email(): void {
		$r = $this->domain( 'dns', 'dns' );
		$this->ready( $r );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'is ready', $this->mails[0]['subject'] );
	}

	public function test_nothing_is_sent_while_importing(): void {
		$flag = new \ReflectionProperty( \BeltoftLicenses\Import::class, 'running' );
		$flag->setAccessible( true );
		$flag->setValue( null, true );
		$this->ready( $this->domain( 'transfer' ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_the_plain_version_has_the_same_content(): void {
		foreach ( array( 'transfer' => 'beltoft_services_domain_transferred', 'register' => 'beltoft_services_domain_registered' ) as $mode => $id ) {
			$r                 = $this->domain( $mode );
			$email             = Emails::get( $id );
			$email->email_type = 'plain';
			$this->mails       = array();
			$this->assertTrue( $email->trigger( $r ) );
			$text = (string) $this->mails[0]['message'];
			$this->assertStringContainsString( 'mail-example.net', $text );
			$this->assertStringContainsString( wc_get_account_endpoint_url( 'domains' ), $text );
			$this->assertStringContainsString( 'Next renewal:', $text );
			$this->assertStringNotContainsString( '<p>', $text );
			$this->assertStringNotContainsString( 'AUTH-CODE-NEVER-IN-MAIL', $text );
			$this->assertStringNotContainsString( '—', $text );
		}
	}
}
