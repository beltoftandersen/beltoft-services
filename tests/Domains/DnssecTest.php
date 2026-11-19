<?php
/** The customer's DNSSEC switch: allowed extensions, order of work, key data, the emailed code. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Actions;
use BeltoftServices\Domains\Dnssec;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\TldFacts;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * Registrar fields are those of the Openprovider spec (domainUpdateDomainRequest, domainGetDomainResponseData, domainDnssecKey, tldTld).
 */
class DnssecTest extends TestCase {
	private const NAME = 'secure-example.com';

	private function table( array $com = array(), array $dk = array() ): void {
		$facts = array( 'dnssec' => array( 'allowed' => true, 'keys' => true, 'digests' => false, 'algs' => array( 8, 13 ), 'max' => 2 ), 'whois' => true );
		update_option( Prices::OPTION, array(
			'com' => array_replace_recursive( array( 'price' => 14, 'restricted' => false ) + $facts, $com ),
			'dk'  => array_replace_recursive( array( 'price' => 13, 'restricted' => false, 'dnssec' => array( 'allowed' => false, 'keys' => false, 'digests' => false, 'algs' => array(), 'max' => 0 ), 'whois' => false ), $dk ),
			'net' => array( 'price' => 12, 'restricted' => false ),
		) );
	}

	private function domain( string $name = self::NAME, array $meta = array() ): Resource {
		$license = $this->service_license( $this->service_product( 'domain' ), array( 'fire' => false ) );
		$r = Resource::create( $license, new DomainDriver(), array(), array_merge( array( 'state' => 'ready', 'driver' => 'domain', 'domain' => $name, 'remote_id' => '123' ), $meta ) );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	private function fakes(): array {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$op  = new FakeClient();
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		return array( $op, $dns );
	}

	private function served(): void {
		add_filter( 'beltoft_services_dns_query', static fn( $value, $server, $domain, $type ) => 6 === $type ? 1 : ( 48 === $type ? 1 : time() + 30 * DAY_IN_SECONDS ), 10, 4 );
	}

	private function fixed_key(): array {
		return array( 'flags' => '257', 'protocol' => '3', 'alg' => '13', 'pub_key' => 'mdsswUyr3DPW132mOi8V9xESWE8jTo0dxCjjnopKl+GqJxpVXckHAeF+KkxLbxILfDLUT0rAK9iUzy1L53eKGQ==' );
	}

	// -- What the registrar says an extension allows --------------------------------------------------

	public function test_the_facts_of_an_entry_are_read_from_the_tld_fields_and_an_unknown_entry_gives_nothing(): void {
		$facts = TldFacts::from_entry( array( 'name' => 'com', 'dnssec_allowed' => true, 'dnssec_keys_allowed' => true, 'dnssec_digests_allowed' => false, 'dnssec_algorithms' => array( 13, '8', 'x' ), 'dnssec_max_records_amount' => 4, 'is_private_whois_allowed' => true ) );
		$this->assertSame( array( 'allowed' => true, 'keys' => true, 'digests' => false, 'algs' => array( 13, 8 ), 'max' => 4 ), $facts['dnssec'] );
		$this->assertTrue( $facts['whois'] );
		$this->assertSame( array(), TldFacts::from_entry( array( 'name' => 'com' ) ), 'No field sent: unknown, never allowed.' );
		$known = TldFacts::from_entry( array( 'name' => 'com' ), true );
		$this->assertFalse( $known['dnssec']['allowed'] );
		$this->assertFalse( $known['whois'] );
	}

	public function test_the_price_sync_keeps_the_facts_of_the_list_and_nothing_is_offered_without_them(): void {
		$eur  = array( 'reseller' => array( 'price' => 9, 'currency' => 'EUR' ) );
		$op   = new FakeClient();
		$op->reply( 'GET', '/tlds', array( 'results' => array(
			array( 'name' => 'com', 'status' => 'ACT', 'prices' => array( 'create_price' => $eur, 'renew_price' => $eur ), 'dnssec_allowed' => true, 'dnssec_keys_allowed' => true, 'dnssec_algorithms' => array( 13 ), 'dnssec_max_records_amount' => 2, 'is_private_whois_allowed' => true ),
			array( 'name' => 'net', 'status' => 'ACT', 'prices' => array( 'create_price' => $eur, 'renew_price' => $eur ) ),
		) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		$this->assertSame( 2, Prices::sync() );
		$this->assertTrue( TldFacts::dnssec_allowed( 'a.com' ) );
		$this->assertTrue( TldFacts::whois_allowed( 'a.com' ) );
		$this->assertSame( array( 'algs' => array( 13 ), 'max' => 2 ), TldFacts::external_keys( 'a.com' ) );
		$this->assertFalse( TldFacts::dnssec_allowed( 'a.net' ), 'The list said nothing about .net.' );
		$this->assertFalse( TldFacts::whois_allowed( 'a.net' ) );
	}

	public function test_an_extension_the_list_did_not_describe_is_read_one_by_one_for_the_extensions_in_use(): void {
		$this->settings( array( 'domain_featured_tlds' => 'com' ) );
		$this->table();
		$table = get_option( Prices::OPTION );
		unset( $table['com']['dnssec'], $table['com']['whois'] );
		update_option( Prices::OPTION, $table );
		$this->domain( 'x-example.net' );
		$op = new FakeClient();
		$op->reply( 'GET', '/tlds/com', array( 'name' => 'com', 'dnssec_allowed' => true, 'dnssec_keys_allowed' => true, 'dnssec_algorithms' => array( 13 ), 'dnssec_max_records_amount' => 3, 'is_private_whois_allowed' => true ) );
		$op->reply( 'GET', '/tlds/net', array( 'name' => 'net' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		$this->assertSame( 2, TldFacts::refresh_missing() );
		$this->assertTrue( TldFacts::dnssec_allowed( 'a.com' ) );
		$this->assertSame( 3, TldFacts::external_keys( 'a.com' )['max'] );
		$this->assertFalse( TldFacts::dnssec_allowed( 'a.net' ), 'An answer without the fields means not allowed.' );
		$this->assertSame( 0, TldFacts::refresh_missing(), 'Known extensions are not asked again.' );
		$this->assertCount( 2, $op->calls() );
	}

	// -- Offered only where it is allowed ---------------------------------------------------------------

	public function test_nothing_is_offered_for_an_extension_without_dnssec(): void {
		$this->table();
		$r = $this->domain( 'no-dnssec.dk' );
		$this->assertSame( array(), Dnssec::offered( $r ) );
		$this->assertNull( Dnssec::view( $r ) );
		$this->assertArrayNotHasKey( 'dnssec_enable', Actions::offered( $r ) );
		$this->assertInstanceOf( \WP_Error::class, Actions::refusal( null, $r, 'dnssec_enable', array() ) );
		$this->assertSame( array(), Dnssec::offered( $this->domain( 'unknown.net' ) ), 'An extension with no stored facts offers nothing.' );
	}

	public function test_on_our_name_servers_the_switch_offers_on_or_off_by_the_state(): void {
		$this->table();
		$off = $this->domain();
		$this->assertSame( array( 'dnssec_enable' ), array_keys( Dnssec::offered( $off ) ) );
		$on = $this->domain( 'signed-example.com', array( 'dnssec_keys' => array( array( 'flags' => 257 ) ) ) );
		$this->assertSame( array( 'dnssec_disable' ), array_keys( Dnssec::offered( $on ) ) );
		$this->assertArrayHasKey( 'dnssec_disable', Actions::offered( $on ) );
		$busy = $this->domain( 'pending-example.com', array( 'state' => 'provisioning' ) );
		$this->assertSame( array(), Dnssec::offered( $busy ) );
	}

	public function test_on_other_name_servers_key_data_is_offered_only_with_keys_algorithms_and_room(): void {
		$this->table();
		$custom = $this->domain( self::NAME, array( 'ns_mode' => 'custom' ) );
		$this->assertSame( array( 'dnssec_key_add' ), array_keys( Dnssec::offered( $custom ) ) );
		$custom->set( array( 'registry_ds_keys' => array( array( 'flags' => 257, 'protocol' => 3, 'alg' => 13, 'pub_key' => 'AAAA' ) ) ) );
		$this->assertSame( array( 'dnssec_key_add', 'dnssec_key_remove' ), array_keys( Dnssec::offered( Resource::find( $custom->id() ) ) ) );
		foreach ( array( array( 'keys' => false ), array( 'algs' => array() ), array( 'max' => 0 ) ) as $none ) {
			$this->table();
			$rows = get_option( Prices::OPTION );
			$rows['com']['dnssec'] = array_merge( $rows['com']['dnssec'], $none );
			update_option( Prices::OPTION, $rows );
			$this->assertSame( array(), Dnssec::offered( Resource::find( $custom->id() ) ), 'Digest-only, no algorithm list or no room: nothing to type in.' );
		}
		$external = $this->domain( 'ext-example.com', array( 'dns_external' => true ) );
		$this->table();
		$this->assertArrayHasKey( 'dnssec_key_add', Dnssec::offered( $external ) );
	}

	// -- On: sign first, then the keys ---------------------------------------------------------------------

	public function test_turning_on_signs_the_zone_and_holds_the_keys_back_until_every_name_server_serves_them(): void {
		$this->table();
		list( $op, $dns ) = $this->fakes();
		$op->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		$lag = true;
		add_filter( 'beltoft_services_dns_query', static function ( $value, $server, $domain, $type ) use ( &$lag ) {
			if ( $lag && 'ns2.example.net' === $server && 48 === $type ) { return new \WP_Error( 'dns_missing', 'Not served yet.' ); }
			return 6 === $type ? 1 : ( 48 === $type ? 1 : time() + 30 * DAY_IN_SECONDS );
		}, 10, 4 );
		$r = $this->domain();
		$first = Actions::run( $r, 'dnssec_enable', array() );
		$this->assertTrue( $first->is_retry() );
		$this->assertSame( array( 'create_zone', self::NAME, array( 'ns1.example.net', 'ns2.example.net' ) ), $dns->calls()[0] );
		$this->assertSame( 'secure', $dns->calls()[1][0], 'The zone was signed first.' );
		$this->assertSame( array(), $op->calls(), 'The registry has no key yet: the zone is not served signed everywhere.' );
		$this->assertEmpty( Resource::find( $r->id() )->get( 'dnssec_keys' ) );
		$lag = false;
		$second = Actions::run( Resource::find( $r->id() ), 'dnssec_enable', array() );
		$this->assertTrue( $second->is_done() );
		$this->assertCount( 1, $op->calls() );
		$this->assertSame( 'PUT', $op->calls()[0]['method'] );
		$this->assertSame( true, $op->calls()[0]['body']['is_dnssec_enabled'] );
		$this->assertSame( 13, $op->calls()[0]['body']['dnssec_keys'][0]['alg'] );
		$this->assertNotEmpty( $second->meta()['dnssec_keys'] );
		$this->assertSame( 'signedDelegation', $second->meta()['registry_dnssec'] );
	}

	public function test_a_registry_that_refuses_the_keys_leaves_the_zone_signed_and_retries(): void {
		$this->table();
		list( $op ) = $this->fakes();
		$this->served();
		$op->reply( 'PUT', '/domains/123', new \WP_Error( 'http_500', 'down' ) );
		$result = Actions::run( $this->domain(), 'dnssec_enable', array() );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 'DNSSEC could not be published at the registry.', $result->error() );
	}

	// -- Off: the registry first, the zone last --------------------------------------------------------------

	private function signed(): Resource {
		return $this->domain( self::NAME, array( 'dnssec_keys' => array( array( 'flags' => 257, 'protocol' => 3, 'alg' => 13, 'pub_key' => 'AAAA' ) ) ) );
	}

	private function registry_clear_reply( FakeClient $op ): void {
		$op->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		$op->reply( 'GET', '/domains/123', array( 'id' => 123, 'dnssec' => 'unsigned', 'is_dnssec_enabled' => false, 'dnssec_keys' => array() ) );
	}

	public function test_turning_off_removes_the_keys_at_the_registry_and_keeps_the_zone_signed_while_the_parent_still_has_the_ds(): void {
		$this->table();
		list( $op, $dns ) = $this->fakes();
		$this->registry_clear_reply( $op );
		$ds = true;
		add_filter( 'beltoft_services_dns_proof_result', static function () use ( &$ds ) { return array( 'status' => 'ok', 'reason' => 'x', 'ds' => $ds, 'ours' => true ); } );
		add_filter( 'beltoft_services_dnssec_off_grace', '__return_zero' );
		$dns->create_zone( self::NAME, array( 'ns1.example.net' ) );
		$dns->secure( self::NAME );
		$r = $this->signed();

		$first = Actions::run( $r, 'dnssec_disable', array() );
		$this->assertFalse( $first->is_done() );
		$this->assertSame( 'Waiting for the DNSSEC record to be removed at the registry.', $first->error() );
		$this->assertSame( 'PUT', $op->calls()[0]['method'] );
		$this->assertSame( false, $op->calls()[0]['body']['is_dnssec_enabled'], 'Registry first: is_dnssec_enabled false.' );
		$this->assertSame( array(), $op->calls()[0]['body']['dnssec_keys'] );
		$this->assertTrue( $dns->zone( self::NAME )['secured'], 'The zone is still signed.' );
		$this->assertNotEmpty( Resource::find( $r->id() )->get( 'dnssec_keys' ), 'Still counted as on until the zone is unsigned.' );
		$this->assertGreaterThan( 0, (int) Resource::find( $r->id() )->get( 'dnssec_off_at' ) );

		// A replay does not send the removal again and does not touch the zone.
		$again = Actions::run( Resource::find( $r->id() ), 'dnssec_disable', array() );
		$this->assertFalse( $again->is_done() );
		$this->assertCount( 2, $op->calls(), 'PUT and the read back, once.' );
		$this->assertTrue( $dns->zone( self::NAME )['secured'] );

		$ds = false;
		$done = Actions::run( Resource::find( $r->id() ), 'dnssec_disable', array() );
		$this->assertTrue( $done->is_done() );
		$this->assertFalse( $dns->zone( self::NAME )['secured'], 'Unsigned last.' );
		$this->assertSame( 'unsecure', $dns->calls()[ count( $dns->calls() ) - 1 ][0] );
		$this->assertSame( array(), $done->meta()['dnssec_keys'] );
		$this->assertNull( $done->meta()['dnssec_off_at'] );
	}

	public function test_the_zone_stays_signed_for_a_grace_after_the_parent_dropped_the_ds(): void {
		$this->table();
		list( $op, $dns ) = $this->fakes();
		$this->registry_clear_reply( $op );
		add_filter( 'beltoft_services_dns_proof_result', static fn() => array( 'status' => 'ok', 'reason' => 'x', 'ds' => false, 'ours' => true ) );
		$dns->create_zone( self::NAME, array( 'ns1.example.net' ) );
		$dns->secure( self::NAME );
		$r = $this->signed();
		$wait = Actions::run( $r, 'dnssec_disable', array() );
		$this->assertFalse( $wait->is_done() );
		$this->assertSame( 'Waiting for resolvers to forget the old DNSSEC record.', $wait->error() );
		$this->assertTrue( $dns->zone( self::NAME )['secured'] );
		Resource::find( $r->id() )->set( array( 'dnssec_ds_gone_at' => time() - DAY_IN_SECONDS - 5 ) );
		$this->assertTrue( Actions::run( Resource::find( $r->id() ), 'dnssec_disable', array() )->is_done() );
		$this->assertFalse( $dns->zone( self::NAME )['secured'] );
	}

	public function test_a_registry_that_still_holds_the_keys_or_cannot_be_reached_never_lets_the_zone_be_unsigned(): void {
		$this->table();
		list( $op, $dns ) = $this->fakes();
		add_filter( 'beltoft_services_dnssec_off_grace', '__return_zero' );
		add_filter( 'beltoft_services_dns_proof_result', static fn() => array( 'status' => 'ok', 'reason' => 'x', 'ds' => false, 'ours' => true ) );
		$dns->create_zone( self::NAME, array( 'ns1.example.net' ) );
		$dns->secure( self::NAME );
		$r = $this->signed();

		// The removal is refused.
		$op->reply( 'PUT', '/domains/123', new \WP_Error( 'http_500', 'down' ) );
		$this->assertTrue( Actions::run( $r, 'dnssec_disable', array() )->is_retry() );
		// The removal is accepted but the registry still lists the key.
		$op->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		$op->reply( 'GET', '/domains/123', array( 'dnssec' => 'signedDelegation', 'dnssec_keys' => array( array( 'alg' => 13 ) ) ) );
		$still = Actions::run( Resource::find( $r->id() ), 'dnssec_disable', array() );
		$this->assertTrue( $still->is_retry() );
		$this->assertSame( 'The registry still holds the DNSSEC keys.', $still->error() );
		// The read back fails.
		$op->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		$op->reply( 'GET', '/domains/123', new \WP_Error( 'http_500', 'down' ) );
		$this->assertTrue( Actions::run( Resource::find( $r->id() ), 'dnssec_disable', array() )->is_retry() );

		$this->assertTrue( $dns->zone( self::NAME )['secured'] );
		$this->assertNotContains( 'unsecure', array_column( $dns->calls(), 0 ) );
		$this->assertSame( 0, (int) Resource::find( $r->id() )->get( 'dnssec_off_at' ) );
	}

	public function test_a_dns_server_that_will_not_unsign_leaves_the_keys_on_record_and_retries(): void {
		$this->table();
		list( $op ) = $this->fakes();
		$this->registry_clear_reply( $op );
		add_filter( 'beltoft_services_dnssec_off_grace', '__return_zero' );
		add_filter( 'beltoft_services_dns_proof_result', static fn() => array( 'status' => 'ok', 'reason' => 'x', 'ds' => false, 'ours' => true ) );
		$dns = new class() extends FakeDns {
			public function unsecure( string $zone ): bool|\WP_Error { return new \WP_Error( 'still_signed', 'still' ); }
		};
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$r = $this->signed();
		$result = Actions::run( $r, 'dnssec_disable', array() );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 'DNSSEC signing could not be turned off.', $result->error() );
		$this->assertNotEmpty( Resource::find( $r->id() )->get( 'dnssec_keys' ) );
	}

	// -- Other name servers: key data -----------------------------------------------------------------

	public function test_a_typed_key_is_checked_against_the_algorithms_flags_protocol_and_key_text(): void {
		$this->table();
		$custom = $this->domain( self::NAME, array( 'ns_mode' => 'custom' ) );
		$ok = $this->fixed_key();
		$this->assertTrue( Dnssec::validate( $custom, 'dnssec_key_add', $ok ) );
		foreach ( array(
			'alg 7 is not in the extension list' => array( 'alg' => '7' ),
			'alg is text'                        => array( 'alg' => 'x' ),
			'flags 1'                            => array( 'flags' => '1' ),
			'protocol 2'                         => array( 'protocol' => '2' ),
			'empty key'                          => array( 'pub_key' => '' ),
			'key with a dash'                    => array( 'pub_key' => 'ab-cd' ),
			'key too long'                       => array( 'pub_key' => str_repeat( 'A', 1025 ) ),
		) as $why => $bad ) {
			$this->assertInstanceOf( \WP_Error::class, Dnssec::validate( $custom, 'dnssec_key_add', array_merge( $ok, $bad ) ), $why );
		}
		$this->assertTrue( Dnssec::validate( $custom, 'dnssec_key_add', array_merge( $ok, array( 'pub_key' => "mdss wUyr\n3DPW==" ) ) ), 'Spaces and line breaks in a pasted key are dropped.' );
	}

	public function test_adding_a_key_sends_the_whole_list_and_the_registry_is_the_truth(): void {
		$this->table();
		list( $op ) = $this->fakes();
		$existing = array( 'flags' => 257, 'protocol' => 3, 'alg' => 8, 'pub_key' => 'EXISTINGKEY' );
		$op->reply( 'GET', '/domains/123', array( 'id' => 123, 'dnssec' => 'signedDelegation', 'dnssec_keys' => array( $existing ) ) );
		$op->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		$custom = $this->domain( self::NAME, array( 'ns_mode' => 'custom' ) );
		$custom->set( array( 'registry_ds_keys' => array( $existing ) ) );
		$result = Actions::run( Resource::find( $custom->id() ), 'dnssec_key_add', $this->fixed_key() );
		$this->assertTrue( $result->is_done() );
		$put = $op->calls()[1];
		$this->assertSame( true, $put['body']['is_dnssec_enabled'] );
		$this->assertCount( 2, $put['body']['dnssec_keys'] );
		$this->assertSame( $existing, $put['body']['dnssec_keys'][0] );
		$this->assertSame( array( 'alg' => 13, 'flags' => 257, 'protocol' => 3, 'pub_key' => preg_replace( '/\s+/', '', $this->fixed_key()['pub_key'] ) ), $put['body']['dnssec_keys'][1] );
		$this->assertCount( 2, $result->meta()['registry_ds_keys'] );
	}

	public function test_a_key_that_is_already_set_and_a_full_extension_are_refused_before_anything_is_sent(): void {
		$this->table();
		$custom = $this->domain( self::NAME, array( 'ns_mode' => 'custom' ) );
		$key    = array( 'flags' => 257, 'protocol' => 3, 'alg' => 13, 'pub_key' => preg_replace( '/\s+/', '', $this->fixed_key()['pub_key'] ) );
		$custom->set( array( 'registry_ds_keys' => array( $key ) ) );
		$this->assertSame( 'dnssec_key_exists', Dnssec::validate( Resource::find( $custom->id() ), 'dnssec_key_add', $this->fixed_key() )->get_error_code() );
		$custom->set( array( 'registry_ds_keys' => array( $key, array( 'flags' => 257, 'protocol' => 3, 'alg' => 8, 'pub_key' => 'OTHER' ) ) ) );
		$other = array_merge( $this->fixed_key(), array( 'pub_key' => 'ZZZZ' ) );
		$this->assertSame( 'dnssec_too_many', Dnssec::validate( Resource::find( $custom->id() ), 'dnssec_key_add', $other )->get_error_code() );
	}

	public function test_removing_the_last_key_turns_dnssec_off_at_the_registry_and_a_registry_managed_key_stays(): void {
		$this->table();
		list( $op ) = $this->fakes();
		$key      = array( 'flags' => 257, 'protocol' => 3, 'alg' => 13, 'pub_key' => 'ONLYKEY' );
		$managed  = array( 'flags' => 257, 'protocol' => 3, 'alg' => 8, 'pub_key' => 'MANAGED', 'readonly' => 1 );
		$custom   = $this->domain( self::NAME, array( 'ns_mode' => 'custom', 'registry_ds_keys' => array( $key, $managed ) ) );
		$this->assertSame( 'dnssec_readonly', Dnssec::validate( $custom, 'dnssec_key_remove', array( 'key_id' => Dnssec::key_id( $managed ) ) )->get_error_code() );
		$this->assertSame( 'dnssec_no_key', Dnssec::validate( $custom, 'dnssec_key_remove', array( 'key_id' => 'nope' ) )->get_error_code() );
		$this->assertTrue( Dnssec::validate( $custom, 'dnssec_key_remove', array( 'key_id' => Dnssec::key_id( $key ) ) ) );

		$custom2 = $this->domain( 'one-key.com', array( 'ns_mode' => 'custom', 'registry_ds_keys' => array( $key ) ) );
		$op->reply( 'GET', '/domains/123', array( 'dnssec' => 'signedDelegation', 'dnssec_keys' => array( $key ) ) );
		$op->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		$result = Actions::run( $custom2, 'dnssec_key_remove', array( 'key_id' => Dnssec::key_id( $key ) ) );
		$this->assertTrue( $result->is_done() );
		$this->assertSame( array(), $op->calls()[1]['body']['dnssec_keys'] );
		$this->assertSame( false, $op->calls()[1]['body']['is_dnssec_enabled'] );
		$this->assertSame( 'unsigned', $result->meta()['registry_dnssec'] );
	}

	public function test_removing_a_key_the_registry_no_longer_has_sends_nothing(): void {
		$this->table();
		list( $op ) = $this->fakes();
		$key = array( 'flags' => 257, 'protocol' => 3, 'alg' => 13, 'pub_key' => 'GONE' );
		$custom = $this->domain( self::NAME, array( 'ns_mode' => 'custom', 'registry_ds_keys' => array( $key ) ) );
		$op->reply( 'GET', '/domains/123', array( 'dnssec' => 'unsigned', 'dnssec_keys' => array() ) );
		$this->assertTrue( Actions::run( $custom, 'dnssec_key_remove', array( 'key_id' => Dnssec::key_id( $key ) ) )->is_done() );
		$this->assertCount( 1, $op->calls(), 'Only the read.' );
	}

	// -- The emailed code ----------------------------------------------------------------------------------

	private function route( Resource $r, string $action, array $args = array(), ?string $code = null ): \WP_REST_Response {
		wp_set_current_user( (int) $r->license()['user_id'] );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/actions/' . $action );
		$request->set_param( 'args', $args );
		if ( null !== $code ) { $request->set_param( 'step_code', $code ); }
		return rest_get_server()->dispatch( $request );
	}

	public function test_every_dnssec_action_needs_the_emailed_code_and_uses_it_up_when_queued(): void {
		$this->table();
		$this->fakes();
		$r    = $this->domain( self::NAME, array( 'ns_mode' => 'custom' ) );
		$user = (int) $r->license()['user_id'];
		$this->assertSame( 'domain_dnssec', apply_filters( 'beltoft_services_action_step_up', '', $r, 'dnssec_key_add' ) );
		foreach ( Dnssec::ACTIONS as $action ) { $this->assertSame( Dnssec::PURPOSE, apply_filters( 'beltoft_services_action_step_up', '', $r, $action ) ); }
		$this->assertSame( '', apply_filters( 'beltoft_services_action_step_up', '', $r, 'whois_privacy' ), 'WHOIS privacy needs no code.' );

		$none = $this->route( $r, 'dnssec_key_add', $this->fixed_key() );
		$this->assertSame( 403, $none->get_status() );
		$this->assertSame( 'step_up', $none->get_data()['code'] );
		$this->assertFalse( Jobs::acting( $r->id() ), 'Nothing queued without the code.' );

		$code  = $this->emailed_step_code( $user, Dnssec::PURPOSE );
		$wrong = '000000' === $code ? '111111' : '000000';
		$this->assertSame( 403, $this->route( $r, 'dnssec_key_add', $this->fixed_key(), $wrong )->get_status() );
		$other = $this->emailed_step_code( $user, 'transfer_code' );
		$this->assertSame( 403, $this->route( $r, 'dnssec_key_add', $this->fixed_key(), $other )->get_status(), 'A code for the transfer code does not work here.' );

		$good = $this->emailed_step_code( $user, Dnssec::PURPOSE );
		// A refused argument costs no code.
		$this->assertSame( 422, $this->route( $r, 'dnssec_key_add', array_merge( $this->fixed_key(), array( 'alg' => '99' ) ), $good )->get_status() );
		$this->assertSame( 202, $this->route( $r, 'dnssec_key_add', $this->fixed_key(), $good )->get_status() );
		$this->assertTrue( Jobs::acting( $r->id() ) );
		\BeltoftServices\Progress::clear( Resource::find( $r->id() ) );
		$this->assertSame( 403, $this->route( $r, 'dnssec_key_add', $this->fixed_key(), $good )->get_status(), 'The same code works once.' );
	}

	public function test_an_action_the_extension_does_not_offer_is_unknown_to_the_route(): void {
		$this->table();
		$r = $this->domain( 'x-example.dk' );
		$this->assertSame( 403, $this->route( $r, 'dnssec_enable' )->get_status() );
	}

	public function test_the_job_runs_the_registry_work_through_the_domain_driver(): void {
		$this->table();
		list( $op ) = $this->fakes();
		$key = array( 'flags' => 257, 'protocol' => 3, 'alg' => 13, 'pub_key' => 'KEYTEXT' );
		$op->reply( 'GET', '/domains/123', array( 'dnssec_keys' => array() ) );
		$op->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		$r = $this->domain( self::NAME, array( 'ns_mode' => 'custom' ) );
		$this->assertArrayHasKey( 'dnssec_key_add', ( new DomainDriver() )->customer_actions( $r ) );
		$result = ( new DomainDriver() )->act( $r, 'dnssec_key_add', array_merge( $this->fixed_key(), array( 'pub_key' => 'KEYTEXT' ) ) );
		$this->assertTrue( $result->is_done() );
		$this->assertSame( array( $key['pub_key'] ), array_column( $op->calls()[1]['body']['dnssec_keys'], 'pub_key' ) );
	}

	public function test_the_page_has_a_security_tab_only_when_something_is_offered_and_shows_the_state(): void {
		$this->table();
		$page = static fn( \BeltoftServices\Resource $r ) => \BeltoftServices\Account\DomainPage::build( $r, array( 'license' => $r->license(), 'tab' => 'security' ) );
		$dk = $page( $this->domain( 'plain-example.dk', array( 'whois_privacy_allowed' => false ) ) );
		$this->assertArrayNotHasKey( 'security', $dk['tabs'] );
		$this->assertNull( $dk['dnssec'] );
		$this->assertNull( $dk['whois'] );

		$com = $page( $this->domain( 'full-example.com', array( 'whois_privacy_allowed' => true, 'whois_privacy_enabled' => true ) ) );
		$this->assertArrayHasKey( 'security', $com['tabs'] );
		$this->assertFalse( $com['dnssec']['on'] );
		$this->assertTrue( $com['whois']['on'] );
		$this->assertSame( 'security', $com['active'] );

		$custom = $page( $this->domain( 'ext2-example.com', array( 'ns_mode' => 'custom', 'registry_ds_keys' => array( array( 'flags' => 257, 'protocol' => 3, 'alg' => 13, 'pub_key' => 'ABCD' ) ) ) ) );
		$this->assertFalse( $custom['dnssec']['ours'] );
		$this->assertSame( 'ABCD', $custom['dnssec']['keys'][0]['pub_key'] );
		$this->assertSame( array( 8, 13 ), $custom['dnssec']['algs'] );

		// The pane prints: step-up forms for DNSSEC, the plain WHOIS privacy line, no price.
		$r = $this->domain( 'render-example.com', array( 'whois_privacy_allowed' => true, 'whois_privacy_enabled' => false ) );
		$d = $page( $r );
		ob_start();
		include BELTOFT_SERVICES_PATH . 'templates/account/domain-page.php';
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-domain-dnssec="dnssec_enable"', $html );
		$this->assertStringContainsString( 'WHOIS privacy (free): hides your name, address, email and phone in the public WHOIS.', $html );
		$this->assertStringContainsString( 'data-domain-action="whois_privacy"', $html );
		$this->assertStringNotContainsString( '—', $html );
		$this->assertStringNotContainsString( '·', $html );
	}
}
