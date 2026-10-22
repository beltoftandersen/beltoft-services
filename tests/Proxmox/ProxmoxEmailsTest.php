<?php
/**
 * The customer emails of a Proxmox VM: ready, paused and the setup delay. Only the fake client is used: nothing here reaches a Proxmox host.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Account\Panel as AccountPanel;
use BeltoftServices\Admin\Panel as AdminPanel;
use BeltoftServices\Crypto;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Access;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * VM emails.
 */
class ProxmoxEmailsTest extends TestCase {

	private const SECRET = 'secret-secret-secret-1';

	/**
	 * The fake cluster.
	 *
	 * @var FakeClient
	 */
	private $fake;

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

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

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );

		$this->wipe_tables();
		delete_option( Clusters::OPTION );
		$this->settings(
			array(
				'task_wait'   => 5,
				'admin_email' => 'ops@example.com',
			)
		);

		$this->fake = new FakeClient();
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );

		$driver = new ProxmoxDriver();
		add_filter(
			'beltoft_services_drivers',
			function ( $drivers ) use ( $driver ) {
				$drivers['proxmox'] = $driver;
				return $drivers;
			}
		);

		$this->fake->add_node( 'node-a' );
		$this->template();
		$this->cluster();
		$this->seed_capacity();
		IpPool::add( '192.0.2.10' );
		IpPool::add( '192.0.2.11' );

		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		delete_option( Clusters::OPTION );
		$this->wipe_tables();

		parent::tear_down();
	}


	private function wipe_tables(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['vms'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	/**
	 * The template: VMID 9000 on node-a with one 10G disk, a NIC with a MAC and a tag, and the agent.
	 *
	 * @param array<string, mixed> $over Overrides of the VM.
	 */
	private function template( array $over = array() ): void {
		$this->fake->add_vm(
			array_merge(
				array(
					'vmid'     => 9000,
					'node'     => 'node-a',
					'name'     => 'debian-template',
					'template' => 1,
					'config'   => array(
						'scsi0'  => 'disk-store:vm-9000-disk-0,size=10G',
						'net0'   => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0,tag=5',
						'agent'  => '1',
						'memory' => 512,
						'cores'  => 1,
					),
				),
				$over
			)
		);
	}

	/**
	 * Saves the cluster "lab".
	 *
	 * @param array<string, mixed> $over Form overrides.
	 */
	private function cluster( array $over = array() ): void {
		$saved = Clusters::save(
			'lab',
			array_merge(
				array(
					'label'             => 'Lab cluster',
					'api_url'           => 'https://pve.example.invalid:8006',
					'token_id'          => 'svc@pve!beltoft',
					'token_secret'      => self::SECRET,
					'client'            => 'fake',
					'pool_api'          => 'pools',
					'nodes'             => 'node-a',
					'storage'           => 'disk-store',
					'cloudinit_storage' => 'ci-store',
					'bridge'            => 'vmbr9',
					'vlan'              => '',
					'pool'              => '',
					'boot_disk'         => 'scsi0',
					'gateway'           => '192.0.2.1',
					'nameservers'       => '192.0.2.53, 192.0.2.54',
					'prefix'            => '24',
					'vmid_min'          => '5000',
					'vmid_max'          => '5099',
					'templates'         => "9000 = Debian 12\n9001 = Ubuntu 24.04",
					'vendor_snippet'    => '',
					'block_smtp'        => '1',
				),
				$over
			)
		);

		$this->assertTrue( $saved );
	}

	/**
	 * Mails to the customer whose subject contains a text.
	 *
	 * @param string $text Subject text.
	 * @return array<int, array<string, mixed>>
	 */
	private function customer_mails( string $text = '' ): array {
		return array_values(
			array_filter(
				$this->mails,
				static function ( $mail ) use ( $text ) {
					$to = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];
					return false !== strpos( $to, 'customer@example.com' ) && ( '' === $text || false !== stripos( (string) $mail['subject'], $text ) );
				}
			)
		);
	}

	/**
	 * A VM product and a license for it; the jobs provision it.
	 *
	 * @param array<string, mixed> $extra Product extras (retention).
	 * @return array<string, mixed>
	 */
	private function license( array $extra = array() ): array {
		$product = $this->service_product(
			'proxmox',
			array(
				'cluster'   => 'lab',
				'template'  => 9000,
				'cores'     => 2,
				'memory_mb' => 4096,
				'disk_gb'   => 30,
			),
			array_merge( array( 'max' => 3 ), $extra )
		);

		return $this->service_license( $product );
	}

	/**
	 * The password stored for the customer, in plain text (for the tests that look for it).
	 *
	 * @param Resource $r Resource.
	 */
	private function password( Resource $r ): string {
		$password = Crypto::decrypt( (string) Resource::find( $r->id() )->get( Access::META_REVEAL ) );

		$this->assertIsString( $password );
		$this->assertGreaterThan( 10, strlen( (string) $password ) );

		return (string) $password;
	}

	public function test_ready_email_gives_ip_login_os_and_size_but_never_a_secret(): void {
		$license = $this->license();
		$this->run_jobs();

		$r = Resource::for_license( (int) $license['id'] );
		$this->assertSame( 'ready', $r->get( 'state' ) );

		$ready = $this->customer_mails( 'ready' );
		$this->assertCount( 1, $ready );

		$ip      = (string) $r->get( 'ip' );
		$message = (string) $ready[0]['message'];
		$this->assertMatchesRegularExpression( '/^192\.0\.2\.1[01]$/', $ip );
		$this->assertStringContainsString( $ip, $message );
		$this->assertStringContainsString( 'ssh root@' . $ip, $message );
		$this->assertStringContainsString( 'Debian 12', $message );
		$this->assertStringContainsString( '2 vCPU, 4096 MB RAM, 30 GB disk', $message );
		$this->assertStringContainsString( 'Shown once in your account', $message );

		$this->assertSecretFree( $message, $r );
	}

	/**
	 * No password, token secret, key or reveal value in a mail.
	 *
	 * @param string   $text Mail text.
	 * @param Resource $r    Resource.
	 */
	private function assertSecretFree( string $text, Resource $r ): void {
		$this->assertStringNotContainsString( $this->password( $r ), $text );
		$this->assertStringNotContainsString( self::SECRET, $text );
		$this->assertStringNotContainsString( 'cipassword', $text );
		$this->assertStringNotContainsString( 'bs1:', $text, 'No encrypted value either.' );
		$this->assertStringNotContainsString( (string) $r->get( Access::META_REVEAL ), $text );
	}

	public function test_ready_email_in_plain_text_and_html_escapes_each_for_its_type(): void {
		$license = $this->license();
		$this->run_jobs();
		$r = Resource::for_license( (int) $license['id'] );

		add_filter(
			'beltoft_services_email_details',
			static function ( $details ) {
				$details['Note <b>']      = 'Tom & Jerry <script>alert(1)</script>';
				$details['Empty']         = '';
				$details['Array']         = array( 'x' );
				return $details;
			},
			20
		);

		$email = Emails::get( 'beltoft_services_ready' );

		$email->email_type = 'html';
		$this->mails       = array();
		$email->trigger( $r );
		$html = (string) $this->customer_mails( 'ready' )[0]['message'];

		$this->assertStringContainsString( 'Tom &amp; Jerry &lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Note &lt;b&gt;', $html );
		$this->assertStringNotContainsString( 'Empty', $html, 'A line with no value is dropped.' );
		$this->assertSecretFree( $html, $r );

		$email->email_type = 'plain';
		$this->mails       = array();
		$email->trigger( $r );
		$plain = (string) $this->customer_mails( 'ready' )[0]['message'];
		$email->email_type = 'html';

		$this->assertStringContainsString( 'Tom & Jerry', $plain );
		$this->assertStringNotContainsString( 'alert(1)', $plain, 'A script element is dropped with its content.' );
		$this->assertStringNotContainsString( '&amp;', $plain );
		$this->assertStringNotContainsString( '<', $plain );
		$this->assertStringContainsString( 'ssh root@' . $r->get( 'ip' ), $plain );
		$this->assertSecretFree( $plain, $r );
	}

	public function test_the_ready_details_only_belong_to_the_ready_email_of_a_vm(): void {
		$license = $this->license();
		$this->run_jobs();
		$r = Resource::for_license( (int) $license['id'] );

		$this->assertSame( array(), apply_filters( 'beltoft_services_email_details', array(), 'beltoft_services_paused', $r ) );
		$this->assertSame( array(), apply_filters( 'beltoft_services_email_details', array(), 'beltoft_services_deleted', $r ) );
	}

	public function test_paused_email_gives_a_fixed_reason_and_the_deletion_date(): void {
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$license = $this->license( array( 'retention' => 30 ) );
		$this->run_jobs();
		$r = Resource::for_license( (int) $license['id'] );
		$this->mails = array();

		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_SUSPENDED ) );
		$this->run_jobs();

		$mail = $this->customer_mails( 'paused' );
		$this->assertCount( 1, $mail );
		$message = (string) $mail[0]['message'];
		$paused  = (int) Resource::find( $r->id() )->get( 'paused_at' );
		$this->assertStringContainsString( 'Your subscription is on hold', $message );
		$this->assertStringContainsString( wp_date( (string) get_option( 'date_format' ), $paused + 30 * DAY_IN_SECONDS ), $message );
		$this->assertStringNotContainsString( 'License suspended', $message, 'Not the history text.' );
		$this->assertSecretFree( $message, $r );
	}

	public function test_paused_email_without_deletion_promises_no_date(): void {
		$this->settings( array( 'deletion_mode' => 'off' ) );
		$license = $this->license( array( 'retention' => 30 ) );
		$this->run_jobs();
		$this->mails = array();

		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_REVOKED ) );
		$this->run_jobs();

		$message = (string) $this->customer_mails( 'paused' )[0]['message'];
		$this->assertStringContainsString( 'cancelled or refunded', $message );
		$this->assertStringContainsString( 'data is kept', $message );
		$this->assertStringNotContainsString( 'deleted no earlier than', $message );
	}

	public function test_the_paused_reason_never_shows_raw_text_an_admin_or_an_abuse_figure(): void {
		$r = new \ReflectionMethod( \BeltoftServices\Emails\ServiceEmail::class, 'customer_reason' );

		foreach ( array( 'Paused by boss', 'Abuse suspected: CPU 97% for 3 h on node-a', 'License not active (found by the sweep)', 'Something <b>odd</b> pve-secret' ) as $raw ) {
			$text = $r->invoke( null, $raw );
			$this->assertStringNotContainsString( 'boss', $text );
			$this->assertStringNotContainsString( '97', $text );
			$this->assertStringNotContainsString( 'node-a', $text );
			$this->assertStringNotContainsString( 'odd', $text );
			$this->assertStringNotContainsString( 'secret', $text );
			$this->assertStringNotContainsString( '<', $text );
		}

		$this->assertSame( 'Paused by an administrator.', $r->invoke( null, 'Paused by boss' ) );
		$this->assertSame( 'This server is suspended. Contact support.', $r->invoke( null, 'Paused by boss', true ) );
		$this->assertSame( '', $r->invoke( null, '' ) );
	}

	public function test_a_breach_hold_paused_email_has_the_fixed_text_and_the_breach_deletion_date(): void {
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$license = $this->license( array( 'retention' => 30 ) );
		$this->run_jobs();
		$r = Resource::for_license( (int) $license['id'] );
		$this->mails = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator', 'user_login' => 'boss' ) ) );
		$this->assertTrue( AdminPanel::act( $r, 'suspend', 'SUSPEND', 'Port scanning of third parties' ) );
		$this->run_jobs();

		$mail = $this->customer_mails( 'paused' );
		$this->assertCount( 1, $mail );
		$message = (string) $mail[0]['message'];
		$this->assertStringContainsString( 'This server is suspended. Contact support.', $message );
		$this->assertStringContainsString( wp_date( (string) get_option( 'date_format' ), time() + 14 * DAY_IN_SECONDS ), $message );
		$this->assertStringNotContainsString( 'Port scanning', $message );
		$this->assertStringNotContainsString( 'boss', $message );
		$this->assertStringNotContainsString( 'starts again', $message, 'A held service does not start again by itself.' );
	}

	/**
	 * A license whose clone leaves a VM behind: the provision fails for good.
	 *
	 * @return array<string, mixed>
	 */
	private function failing_license(): array {
		$license = $this->license();
		$this->fake->next_task(
			array(
				'exitstatus' => 'ERROR: clone failed on pve-node secret-path',
				'effect'     => true,
			)
		);
		$this->run_jobs();

		return $license;
	}

	public function test_a_failed_provision_tells_the_customer_once_without_details(): void {
		$license = $this->failing_license();
		$r       = Resource::for_license( (int) $license['id'] );

		$this->assertSame( 'failed', $r->get( 'state' ) );
		$this->assertSame( 'provision', $r->get( 'failed_op' ) );

		$delay = $this->customer_mails( 'delay' );
		$this->assertCount( 1, $delay );
		$text = html_entity_decode( (string) $delay[0]['message'], ENT_QUOTES );
		$this->assertStringContainsString( "There's a delay setting up your server. We're on it.", $text );
		$this->assertStringNotContainsString( 'clone', strtolower( $text ) );
		$this->assertStringNotContainsString( 'secret-path', $text );
		$this->assertStringNotContainsString( '5000', $text, 'No VMID.' );
		$this->assertStringNotContainsString( (string) IpPool::for_resource( $r->id() )['address'], $text );
		$this->assertSame( array(), $this->customer_mails( 'ready' ), 'Never the ready email.' );

		// The admin is alerted as before.
		$alerts = array_filter( $this->mails, static fn( $m ) => 'ops@example.com' === ( is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to'] ) );
		$this->assertNotEmpty( $alerts );
		$this->assertStringContainsString( 'Service failed', implode( ' ', wp_list_pluck( $alerts, 'subject' ) ) );

		// An admin retry that fails again does not send a second email.
		$this->mails = array();
		$this->fake->next_task( array( 'exitstatus' => 'ERROR: clone failed', 'effect' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		AdminPanel::act( Resource::find( $r->id() ), 'retry' );
		$this->run_jobs();
		Emails::on_state_changed( Resource::find( $r->id() ), 'provisioning', 'failed' );
		$this->assertSame( array(), $this->customer_mails( 'delay' ), 'One email per resource.' );
	}

	public function test_a_failed_provision_keeps_the_ip_held(): void {
		$license = $this->failing_license();
		$r       = Resource::for_license( (int) $license['id'] );

		$row = IpPool::for_resource( $r->id() );
		$this->assertNotNull( $row, 'The IP stays reserved for the resource.' );
		$this->assertMatchesRegularExpression( '/^192\.0\.2\.1[01]$/', $row['address'] );
	}

	public function test_the_customer_panel_shows_the_delay_not_the_failure(): void {
		$license = $this->failing_license();
		$r       = Resource::for_license( (int) $license['id'] );

		$this->assertSame( "There's a delay setting up your server. We're on it.", AccountPanel::message( 'failed', $r ) );
		$this->assertSame( 'Delayed', AccountPanel::label( 'failed', $r ) );

		wp_set_current_user( (int) $license['user_id'] );
		ob_start();
		AccountPanel::render( $license );
		$html = html_entity_decode( (string) ob_get_clean(), ENT_QUOTES );

		$this->assertStringContainsString( "There's a delay setting up your server. We're on it.", $html );
		$this->assertStringNotContainsString( 'secret-path', $html );
		$this->assertStringNotContainsString( 'clone failed', $html );

		// Another failure (a pause that failed) keeps the general text.
		$this->assertTrue( $r->set( array( 'failed_op' => 'pause' ) ) );
		$this->assertStringContainsString( 'Something went wrong', AccountPanel::message( 'failed', Resource::find( $r->id() ) ) );
	}

	public function test_a_failed_pause_or_a_driver_that_is_no_server_sends_no_delay_email(): void {
		$license = $this->license();
		$this->run_jobs();
		$r = Resource::for_license( (int) $license['id'] );
		$this->mails = array();

		$r->set( array( 'failed_op' => 'pause' ) );
		Emails::on_state_changed( Resource::find( $r->id() ), 'ready', 'failed' );
		$this->assertSame( array(), $this->customer_mails( 'delay' ) );
	}
}
