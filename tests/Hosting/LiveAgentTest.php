<?php
/**
 * The live agent client: the wire format, the gate, the TLS options, the errors. No network is ever used: the
 * transport is replaced by a recorder and the curl options by a seam.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\Agent;
use BeltoftServices\Hosting\Clients;
use BeltoftServices\Hosting\FakeAgent;
use BeltoftServices\Hosting\LiveAgent;
use BeltoftServices\Hosting\Nodes;
use BeltoftServices\Http\Signer;

/**
 * A LiveAgent whose network and curl are replaced.
 */
class RecordingAgent extends LiveAgent {

	/**
	 * Requests sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $sent = array();

	/**
	 * Answers, taken in order: array( status, body ) or a WP_Error.
	 *
	 * @var array<int, mixed>
	 */
	public $answers = array();

	/**
	 * Curl options set: option => value.
	 *
	 * @var array<int, mixed>
	 */
	public $options = array();

	/**
	 * Whether a curl option is accepted.
	 *
	 * @var bool
	 */
	public $accept = true;

	protected function send( array $request ) {
		$this->sent[] = $request;
		$answer       = array_shift( $this->answers );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		return array( 'status' => (int) $answer[0], 'body' => (string) $answer[1] );
	}

	protected function set_curl_option( $handle, int $option, $value ): bool {
		$this->options[ $option ] = $value;

		return $this->accept;
	}

	protected function curl_supported(): bool {
		return true;
	}
}

/**
 * LiveAgent.
 */
class LiveAgentTest extends HostingCase {

	private const KEY = 'the-agent-hmac-key-0000000000001';
	private const CERT = "-----BEGIN CERTIFICATE-----\nMIIBclientcert\n-----END CERTIFICATE-----";
	private const CA = "-----BEGIN CERTIFICATE-----\nMIIBcacert\n-----END CERTIFICATE-----";
	private const PRIVATE = "-----BEGIN PRIVATE KEY-----\nMIIBclientkeysecretmaterial\n-----END PRIVATE KEY-----";

	/**
	 * A complete node record, secrets in clear.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function node( array $over = array() ): array {
		return array_merge(
			array(
				'client'            => 'live',
				'agent_url'         => 'https://node.example.invalid:8765/',
				'agent_key'         => self::KEY,
				'agent_tenant'      => 'hosting',
				'agent_client_cert' => self::CERT,
				'agent_client_key'  => self::PRIVATE,
				'agent_ca'          => self::CA,
				'agent_pin'         => base64_encode( str_repeat( 'p', 32 ) ),
			),
			$over
		);
	}

	private function agent( array $over = array() ): RecordingAgent {
		return new RecordingAgent( $this->node( $over ), true );
	}

	public function test_it_refuses_to_exist_without_the_allowance_or_with_incomplete_settings(): void {
		foreach ( array( 'allowance' => array( false, array() ), 'http' => array( true, array( 'agent_url' => 'http://node.example.invalid:8765' ) ), 'key' => array( true, array( 'agent_key' => 'short' ) ), 'tenant' => array( true, array( 'agent_tenant' => '' ) ), 'cert' => array( true, array( 'agent_client_cert' => '' ) ), 'ca' => array( true, array( 'agent_ca' => 'nope' ) ), 'key pem' => array( true, array( 'agent_client_key' => 'nope' ) ), 'pin' => array( true, array( 'agent_pin' => 'abc' ) ) ) as $name => $case ) {
			try {
				new RecordingAgent( $this->node( $case[1] ), $case[0] );
				$this->fail( $name . ': a client was built.' );
			} catch ( \RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage(), $name );
				$this->assertStringNotContainsString( self::KEY, $e->getMessage() );
			}
		}

		$this->assertInstanceOf( Agent::class, $this->agent() );
	}

	public function test_the_gate_builds_the_live_client_only_for_a_live_allowed_tagged_node(): void {
		$node = $this->node();

		$this->assertInstanceOf( FakeAgent::class, Clients::build_agent( array_merge( $node, array( 'client' => 'fake' ) ), true ) );
		$this->assertInstanceOf( FakeAgent::class, Clients::build_agent( $node, false ), 'No constant: a live record is a fake.' );

		try {
			Clients::build_agent( $node, true );
			$this->fail( 'Built without a site tag.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The site tag is not set.', $e->getMessage() );
		}

		$this->settings( array( 'site_tag' => 'tagone' ) );
		$built = Clients::build_agent( $node, true );
		$this->assertInstanceOf( LiveAgent::class, $built );
		$this->assertNotInstanceOf( FakeAgent::class, $built );
		$this->assertFalse( Clients::live_allowed(), 'The constant is never defined in the suite.' );

		// An injected client is never accepted for a live node.
		add_filter( 'beltoft_services_hosting_agent', fn() => new FakeAgent() );
		$this->assertInstanceOf( LiveAgent::class, Clients::build_agent( $node, true ) );
	}

	public function test_submit_is_a_signed_post_of_the_jobs_body_and_returns_the_job(): void {
		$agent             = $this->agent();
		$agent->answers[]  = array( 202, wp_json_encode( array( 'id' => 'job:1', 'status' => 'queued' ) ) );
		$job               = $agent->submit( 'site.create', array( 'vmid' => 1000, 'slug' => 'shop', 'host' => 'shop.example.test' ), 'job:1' );

		$this->assertSame( array( 'id' => 'job:1', 'status' => 'queued' ), $job );

		$sent = $agent->sent[0];
		$this->assertSame( 'POST', $sent['method'] );
		$this->assertSame( 'https://node.example.invalid:8765/jobs', $sent['url'] );
		$this->assertSame( array( 'id' => 'job:1', 'op' => 'site.create', 'args' => array( 'vmid' => 1000, 'slug' => 'shop', 'host' => 'shop.example.test' ) ), json_decode( $sent['body'], true ) );
		$this->assertSame( '{"id":"job:1","op":"site.create","args":{"vmid":1000,"slug":"shop","host":"shop.example.test"}}', $sent['body'], 'Exactly the body that is signed.' );
		$this->assertSame( 'application/json', $sent['headers']['Content-Type'] );

		$ts = $sent['headers']['X-Beltoft-Timestamp'];
		$this->assertMatchesRegularExpression( '/^\d{10}$/D', $ts );
		$this->assertSame( 'hosting', $sent['headers']['X-Beltoft-Tenant'] );
		$this->assertSame( Signer::signature( self::KEY, 'POST', '/jobs', $ts, 'hosting', $sent['body'] ), $sent['headers']['X-Beltoft-Signature'] );
		$this->assertSame( 64, strlen( $sent['headers']['X-Beltoft-Signature'] ) );
	}

	public function test_empty_arguments_are_an_object_and_slashes_stay(): void {
		$agent            = $this->agent();
		$agent->answers[] = array( 202, wp_json_encode( array( 'id' => 'j2', 'status' => 'done', 'result' => array( 'ok' => true ) ) ) );

		$job = $agent->submit( 'agent.health', array(), 'j2' );

		$this->assertSame( '{"id":"j2","op":"agent.health","args":{}}', $agent->sent[0]['body'] );
		$this->assertSame( array( 'ok' => true ), $job['result'] );

		$agent->answers[] = array( 202, wp_json_encode( array( 'id' => 'j3', 'status' => 'queued' ) ) );
		$agent->submit( 'site.verify', array( 'path' => 'a/b' ), 'j3' );
		$this->assertStringContainsString( '"a/b"', $agent->sent[1]['body'], 'No escaped slashes: the signature covers the bytes sent.' );
	}

	public function test_job_is_a_signed_get_with_the_id_in_the_path(): void {
		$agent            = $this->agent();
		$agent->answers[] = array( 200, wp_json_encode( array( 'id' => 'job:1', 'status' => 'failed', 'error' => 'php-fpm did not start' ) ) );

		$job = $agent->job( 'job:1' );

		$this->assertSame( array( 'id' => 'job:1', 'status' => 'failed', 'error' => 'php-fpm did not start' ), $job );
		$sent = $agent->sent[0];
		$this->assertSame( 'GET', $sent['method'] );
		$this->assertSame( 'https://node.example.invalid:8765/jobs/job:1', $sent['url'] );
		$this->assertSame( '', $sent['body'] );
		$this->assertArrayNotHasKey( 'Content-Type', $sent['headers'] );
		$this->assertSame( Signer::signature( self::KEY, 'GET', '/jobs/job:1', $sent['headers']['X-Beltoft-Timestamp'], 'hosting', '' ), $sent['headers']['X-Beltoft-Signature'] );
	}

	public function test_a_404_is_the_agents_http_404_and_a_400_is_a_refusal(): void {
		$agent            = $this->agent();
		$agent->answers[] = array( 404, wp_json_encode( array( 'code' => 'http_404', 'message' => 'No such job.' ) ) );
		$gone             = $agent->job( 'nope' );

		$this->assertWPError( $gone );
		$this->assertSame( 'http_404', $gone->get_error_code() );
		$this->assertSame( 404, $gone->get_error_data()['status'] );

		$agent->answers[] = array( 400, wp_json_encode( array( 'code' => 'unknown_op', 'message' => 'The operation is not in the list.' ) ) );
		$bad              = $agent->submit( 'shell.run', array(), 'j9' );
		$this->assertSame( 'unknown_op', $bad->get_error_code() );
		$this->assertSame( 400, $bad->get_error_data()['status'] );

		$agent->answers[] = array( 401, wp_json_encode( array( 'code' => 'unauthorized', 'message' => 'Bad signature.' ) ) );
		$this->assertSame( 401, $agent->submit( 'agent.health', array(), 'j10' )->get_error_data()['status'] );
	}

	public function test_a_replay_a_busy_agent_and_a_dead_line_are_retries_without_a_status(): void {
		$agent = $this->agent();

		foreach ( array( 409, 500, 503, 429, 408 ) as $code ) {
			$agent->answers[] = array( $code, wp_json_encode( array( 'code' => 'replay', 'message' => 'again' ) ) );
			$error            = $agent->submit( 'agent.health', array(), 'jr' . $code );

			$this->assertSame( 'transport', $error->get_error_code(), (string) $code );
			$this->assertNull( $error->get_error_data(), 'No status: the step classifies it as a retry, not a refusal.' );
		}

		$agent->answers[] = new \WP_Error( 'http_request_failed', 'cURL error 7: could not connect' );
		$this->assertSame( 'transport', $agent->submit( 'agent.health', array(), 'jdead' )->get_error_code() );

		foreach ( array( '', 'not json', '{"id":"x"}', '{"id":"x","status":"weird"}' ) as $body ) {
			$agent->answers[] = array( 202, $body );
			$this->assertSame( 'transport', $agent->submit( 'agent.health', array(), 'jbad' )->get_error_code(), $body );
		}
	}

	public function test_errors_never_carry_the_key_the_signature_or_the_body(): void {
		$agent            = $this->agent();
		$agent->answers[] = array( 500, wp_json_encode( array( 'code' => 'x', 'message' => 'failed with ' . self::KEY . ' inside' ) ) );
		$error            = $agent->submit( 'db.create', array( 'password' => 'a-very-secret-password-1' ), 'js' );

		$this->assertStringNotContainsString( self::KEY, $error->get_error_message() );
		$this->assertStringNotContainsString( 'a-very-secret-password', $error->get_error_message() );
		$this->assertStringContainsString( '***', $error->get_error_message() );

		$agent->answers[] = array( 202, wp_json_encode( array( 'id' => 'js2', 'status' => 'failed', 'error' => 'cannot use ' . self::KEY ) ) );
		$job              = $agent->submit( 'db.create', array(), 'js2' );
		$this->assertStringNotContainsString( self::KEY, $job['error'] );
	}

	public function test_ids_and_operations_are_checked_before_anything_is_sent(): void {
		$agent = $this->agent();

		foreach ( array( 'has space', '', str_repeat( 'a', 121 ), "a\nb" ) as $id ) {
			$this->assertSame( 'invalid', $agent->submit( 'agent.health', array(), $id )->get_error_code() );
			$this->assertSame( 'invalid', $agent->job( $id )->get_error_code() );
		}

		foreach ( array( 'Site.Create', 'site', 'site.create; x', '../x' ) as $op ) {
			$this->assertSame( 'invalid', $agent->submit( $op, array(), 'jok' )->get_error_code(), $op );
		}

		$this->assertSame( array(), $agent->sent );
	}

	public function test_the_tls_options_are_all_set_from_memory_and_the_request_is_trusted_only_then(): void {
		$agent = $this->agent();

		$this->assertTrue( $agent->tls( 'handle', array(), 'https://node.example.invalid:8765/jobs' ) );
		$this->assertSame( self::CERT, $agent->options[ CURLOPT_SSLCERT_BLOB ] );
		$this->assertSame( self::PRIVATE, $agent->options[ CURLOPT_SSLKEY_BLOB ] );
		$this->assertSame( self::CA, $agent->options[ CURLOPT_CAINFO_BLOB ] );
		$this->assertTrue( $agent->options[ CURLOPT_SSL_VERIFYPEER ] );
		$this->assertSame( 2, $agent->options[ CURLOPT_SSL_VERIFYHOST ] );
		$this->assertSame( 'sha256//' . base64_encode( str_repeat( 'p', 32 ) ), $agent->options[ CURLOPT_PINNEDPUBLICKEY ] );

		$other = $this->agent();
		$this->assertFalse( $other->tls( 'handle', array(), 'https://elsewhere.example.invalid/jobs' ), 'Another host is not ours.' );
		$this->assertSame( array(), $other->options );

		$nopin = $this->agent( array( 'agent_pin' => '' ) );
		$nopin->tls( 'handle', array(), 'https://node.example.invalid:8765/jobs' );
		$this->assertArrayNotHasKey( CURLOPT_PINNEDPUBLICKEY, $nopin->options );

		$refusing         = $this->agent();
		$refusing->accept = false;
		$this->assertFalse( $refusing->tls( 'handle', array(), 'https://node.example.invalid:8765/jobs' ), 'One refused option and the connection is not secured.' );
	}

	public function test_a_php_without_the_curl_options_cannot_build_it(): void {
		$node = $this->node();

		try {
			new class( $node, true ) extends LiveAgent {
				protected function curl_supported(): bool {
					return false;
				}
			};
			$this->fail( 'A client was built without curl support.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'curl', $e->getMessage() );
		}
	}

	public function test_the_node_record_keeps_the_client_key_encrypted_and_validates_the_pem_text(): void {
		$base = array(
			'label' => 'Tls', 'node_group' => 'g1', 'client' => 'fake', 'api_url' => 'https://t.example.invalid:8006', 'token_id' => 'svc@pve!hosting',
			'token_secret' => self::NODE_SECRET, 'pve_node' => 'pve1', 'agent_url' => 'https://t.example.invalid:8765', 'agent_key_id' => 'k1', 'agent_key' => self::AGENT_KEY,
			'pool' => 'hosting-pool', 'storage' => 'local-zfs', 'bridge' => 'hbr0', 'gateway' => '10.200.0.1', 'lan_cidr' => '10.200.0.0/16', 'ip_first' => 10,
			'ssh_port_min' => 22001, 'ssh_port_max' => 22999, 'vmid_min' => 1000, 'vmid_max' => 1999, 'template' => 9000, 'net_mbit' => 0, 'mem_gb' => 32, 'disk_gb' => 1000, 'db_host' => '10.200.0.3',
		);

		$this->assertTrue( Nodes::save( 'tls', array_merge( $base, array( 'agent_client_cert' => self::CERT, 'agent_client_key' => self::PRIVATE, 'agent_ca' => self::CA, 'agent_tenant' => 'tenant-one' ) ) ) );

		$raw = (string) wp_json_encode( get_option( Nodes::OPTION ) );
		$this->assertStringNotContainsString( 'clientkeysecretmaterial', $raw, 'The private key is encrypted at rest.' );
		$node = Nodes::get( 'tls' );
		$this->assertSame( self::PRIVATE, $node['agent_client_key'] );
		$this->assertSame( self::CERT, $node['agent_client_cert'] );
		$this->assertSame( 'tenant-one', $node['agent_tenant'] );
		$this->assertTrue( Nodes::has_secret( 'tls', 'agent_client_key' ) );

		$this->assertTrue( Nodes::save( 'tls', array_merge( $base, array( 'agent_client_cert' => self::CERT, 'agent_ca' => self::CA, 'agent_client_key' => '' ) ) ) );
		$this->assertSame( self::PRIVATE, Nodes::get( 'tls' )['agent_client_key'], 'An empty field keeps the saved key.' );

		$this->assertWPError( Nodes::save( 'tls', array_merge( $base, array( 'agent_client_cert' => 'not pem' ) ) ) );
		$this->assertWPError( Nodes::save( 'tls', array_merge( $base, array( 'agent_ca' => "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----" ) ) ) );
		$this->assertWPError( Nodes::save( 'tls', array_merge( $base, array( 'agent_client_key' => 'just a long string without markers' ) ) ) );
		$this->assertWPError( Nodes::save( 'tls', array_merge( $base, array( 'agent_tenant' => 'bad tenant' ) ) ) );

		// The screen never prints the private key.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['edit'] = 'tls';
		ob_start();
		\BeltoftServices\Hosting\NodesScreen::render();
		$form = (string) ob_get_clean();
		unset( $_GET['edit'] );
		wp_set_current_user( 0 );
		$this->assertStringNotContainsString( 'clientkeysecretmaterial', $form );
		$this->assertStringContainsString( 'name="agent_client_key"', $form );
		$this->assertStringContainsString( 'MIIBclientcert', $form );
	}
}
