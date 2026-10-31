<?php
/**
 * Shared setup for the mail tests. Documentation names only: nothing here reaches a network.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Mail\FakeMailcowClient;
use BeltoftServices\Mail\MailDomain;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

abstract class MailTestCase extends TestCase {

	/** Plan of a "Team" like product. */
	protected const PLAN = array( 'mailboxes' => 5, 'quota_mb' => 10240, 'aliases' => 50, 'domains' => 3 );

	protected FakeMailcowClient $mailcow;

	/** @var array<int, array<string, mixed>> Mails sent. */
	protected array $mails = array();

	/** @var array<string, array<int, string>|\WP_Error> Public DNS answers by "TYPE name". */
	protected array $dns = array();

	/** @var array<string, array<string, mixed>> ProofResolver results by call. */
	protected array $proof = array();

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( str_repeat( 'k', 32 ) );
		add_filter( 'beltoft_services_key', static fn() => $key );
		delete_option( FakeDns::OPTION );
		delete_option( FakeMailcowClient::OPTION );

		$this->mailcow = new FakeMailcowClient();
		add_filter( 'beltoft_services_mail_client', fn() => $this->mailcow );

		$this->settings(
			array(
				'pause_all'             => 0,
				'domain_nameservers'    => 'ns1.example.net,ns2.example.net',
				'mail_host'             => 'mail.example.net',
				'mail_spf_include'      => '_spf.mail.example.net',
				'mail_dmarc_rua'        => 'dmarc@example.net',
				'mail_reserved_domains' => "reserved.example\nowner.example",
				'mail_rl_minute'        => 30,
				'mail_rl_day'           => 500,
				'admin_email'           => 'christian.beltoft@gmail.com',
			)
		);

		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $args ) {
				$this->mails[] = $args;
				return true;
			},
			10,
			2
		);

		$this->dns   = array();
		$this->proof = array(
			'delegation' => array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => array( 'ns1.host.example' ), 'ips' => array(), 'ds' => null, 'ours' => false ),
			'check_txt'  => array( 'status' => 'missing', 'servers' => array(), 'reason' => 'not_found' ),
		);
		add_filter(
			'beltoft_services_dns_proof_result',
			function ( $pre, $call ) {
				return $this->proof[ $call ] ?? $pre;
			},
			10,
			2
		);
		add_filter(
			'beltoft_services_mail_dns_lookup',
			function ( $pre, $name, $type ) {
				return $this->dns[ $type . ' ' . $name ] ?? array();
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_mail_client' );
		remove_all_filters( 'beltoft_services_dns_proof_result' );
		remove_all_filters( 'beltoft_services_mail_dns_lookup' );
		remove_all_filters( 'pre_wp_mail' );
		delete_option( FakeDns::OPTION );
		delete_option( FakeMailcowClient::OPTION );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A mail resource for a domain, not yet provisioned.
	 *
	 * @param string               $domain Domain.
	 * @param array<string, mixed> $meta   Meta over the defaults.
	 * @param array<string, mixed> $plan   Plan over the Team plan.
	 * @param int                  $user   Customer (0: a new one).
	 */
	protected function mail_resource( string $domain, array $meta = array(), array $plan = array(), int $user = 0 ): Resource {
		$product = $this->service_product( 'mail', array_merge( self::PLAN, $plan ), array( 'slug' => 'mail-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false ) + ( $user > 0 ? array( 'user_id' => $user ) : array() ) );
		$r       = Resource::create( $license, Registry::get( 'mail' ), Product::plan( $product->get_id() ), array_merge( array( MailDomain::META_DOMAIN => $domain ), $meta ) );
		$this->assertInstanceOf( Resource::class, $r );

		return $r;
	}

	/**
	 * A provisioned, ready mail resource (external domain, proof found).
	 *
	 * @param array<string, mixed> $plan Plan over the Team plan.
	 */
	protected function ready_mail( string $domain = 'customer.example', array $plan = array(), int $user = 0 ): Resource {
		$this->proof['check_txt'] = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
		$r      = $this->mail_resource( $domain, array(), $plan, $user );
		$result = Registry::get( 'mail' )->provision( $r );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertTrue( $r->set( array_merge( $result->meta(), array( 'state' => 'ready', 'desired' => 'ready' ) ) ) );

		return Resource::find( $r->id() );
	}

	/**
	 * A ready registered domain of ours owned by a user, with its zone in FakeDns.
	 *
	 * @param array<int, array<string, mixed>> $rows Existing records, editor rows.
	 */
	protected function our_zone( int $user, string $name, array $rows = array() ): Resource {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => $name, 'mode' => 'register', 'zone_ready' => true ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'state' => 'ready' ) );
		$this->assertTrue( DnsClients::dns()->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) ) );

		if ( $rows ) {
			$this->assertTrue( \BeltoftServices\Domains\Records::replace( $name, $rows ) );
		}

		return Resource::find( $r->id() );
	}

	/**
	 * The record rows of a zone, as editor rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function zone_rows( string $zone ): array {
		return \BeltoftServices\Domains\Records::to_rows( $zone, DnsClients::dns()->zone( $zone )['rrsets'] );
	}
}
