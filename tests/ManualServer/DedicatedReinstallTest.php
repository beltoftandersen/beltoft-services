<?php
/**
 * The automatic reinstall: rescue system with its one-time password, installimage config, new access, failures.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\ManualServer;

use BeltoftServices\Jobs;
use BeltoftServices\ManualServer\DedicatedReinstall;
use BeltoftServices\ManualServer\FakeRescueShell;
use BeltoftServices\ManualServer\FakeRobotClient;
use BeltoftServices\ManualServer\ImageCatalog;
use BeltoftServices\ManualServer\ManualServerDelivery;
use BeltoftServices\ManualServer\ServerControls;
use BeltoftServices\Resource;

require_once __DIR__ . '/ControlsTestCase.php';

final class DedicatedReinstallTest extends ControlsTestCase {

	private const IMAGE = 'Debian-1201-bookworm-amd64-base.tar.gz';

	/** @var int Seconds the test clock was moved. */
	private int $moved = 0;

	public function set_up(): void {
		parent::set_up();
		$this->moved = 0;
		add_filter( 'beltoft_services_now', fn( $now ) => $now + $this->moved );
		delete_option( ImageCatalog::OPTION );
		$this->settings( array( 'dedicated_images' => implode( "\n", FakeRescueShell::IMAGES ) ) );
	}

	/**
	 * Runs the job until it is no longer running, moving the clock a minute each time.
	 *
	 * @param Resource $r Resource.
	 */
	private function drive( Resource $r, string $image = self::IMAGE ): Resource {
		for ( $i = 0; $i < 60; $i++ ) {
			Jobs::act( $r->id(), DedicatedReinstall::ACTION, array( 'image' => $image ) );
			$this->moved += 60;
			$memo = Resource::find( $r->id() )->get( DedicatedReinstall::META );

			if ( ! is_array( $memo ) || 'running' !== ( $memo['state'] ?? '' ) ) {
				break;
			}
		}

		return Resource::find( $r->id() );
	}

	/** @return array<int, array<string, mixed>> */
	private function shell_calls( string $call ): array {
		return array_values( array_filter( FakeRescueShell::calls(), static fn( array $c ): bool => $call === $c['call'] ) );
	}

	private function robot_calls( string $call ): array {
		return array_values( array_filter( FakeRobotClient::calls(), static fn( array $c ): bool => $call === $c['call'] ) );
	}

	public function test_a_customer_without_a_key_gets_the_rescue_password_as_the_new_root_password(): void {
		$r = $this->controlled();
		$this->assertTrue( DedicatedReinstall::start( $r, self::IMAGE ) );

		$memo = Resource::find( $r->id() )->get( DedicatedReinstall::META );
		$this->assertSame( 'running', $memo['state'] );

		$r = $this->drive( $r );

		$this->assertSame( 'done', $r->get( DedicatedReinstall::META )['state'] );
		$this->assertSame( array( array( 'call' => 'rescue_activate', 'number' => self::NUMBER, 'os' => 'linux', 'keys' => array() ) ), array_map( static fn( $c ) => array_intersect_key( $c, array_flip( array( 'call', 'number', 'os', 'keys' ) ) ), $this->robot_calls( 'rescue_activate' ) ), 'The rescue system is switched on without a key.' );
		$this->assertSame( 'hw', $this->robot_calls( 'reset' )[0]['type'] );

		$password = FakeRobotClient::rescue_password( self::NUMBER );
		$this->assertSame( '', $password, 'The rescue system is switched off at the end and the password is gone.' );
		$this->assertNotNull( ManualServerDelivery::root_password( $r ), 'The new root password is set.' );
		$this->assertSame( 16, strlen( (string) ManualServerDelivery::root_password( $r ) ) );
		$this->assertNull( $r->get( ServerControls::RESCUE_PASSWORD ) );
		$this->assertNotEmpty( $r->get( 'last_reinstall_at' ) );
		$this->assertSame( array(), $this->shell_calls( 'start' )[0]['keys'] ? array( 'x' ) : array(), 'No key file.' );

		foreach ( FakeRescueShell::calls() as $call ) {
			$this->assertTrue( $call['password_ok'], $call['call'] . ' used the password the web service gave.' );
		}

		$this->assertSame( array( 'rescue_activate', 'reset', 'rescue_deactivate' ), array_values( array_unique( array_map( static fn( $c ) => $c['call'], array_filter( FakeRobotClient::calls(), static fn( $c ) => in_array( $c['call'], array( 'rescue_activate', 'reset', 'rescue_deactivate' ), true ) ) ) ) ) );
	}

	public function test_the_autosetup_config_is_the_documented_one(): void {
		$r = $this->controlled();
		DedicatedReinstall::start( $r, self::IMAGE );
		$this->drive( $r );

		$config = (string) $this->shell_calls( 'start' )[0]['config'];
		$this->assertSame(
			"DRIVE1 /dev/nvme0n1\nDRIVE2 /dev/nvme1n1\n\nSWRAID 1\nSWRAIDLEVEL 1\n\nBOOTLOADER grub\nHOSTNAME " . $r->get( 'server_name' ) . "\n\nPART swap swap 8G\nPART /boot ext3 1024M\nPART / ext4 all\n\nIMAGE /root/images/" . self::IMAGE . "\n",
			$config
		);
		$this->assertStringNotContainsStringIgnoringCase( 'passw', $config );
		$this->assertStringNotContainsString( 'SSHKEYS_URL', $config );
	}

	public function test_config_for_one_disk_and_for_a_key(): void {
		$one = DedicatedReinstall::config( array( '/dev/sda' ), 'srv', 'Ubuntu-2404-noble-amd64-base.tar.gz', true );
		$this->assertStringContainsString( "SWRAID 0\n", $one );
		$this->assertStringContainsString( "SSHKEYS_URL /root/ii-keys\n", $one );
		$this->assertStringContainsString( 'SWRAIDLEVEL 5', DedicatedReinstall::config( array( '/dev/a', '/dev/b', '/dev/c' ), 'srv', 'x.tar.gz', false ) );
		$this->assertStringContainsString( 'SWRAIDLEVEL 6', DedicatedReinstall::config( array( '/dev/a', '/dev/b', '/dev/c', '/dev/d' ), 'srv', 'x.tar.gz', false ) );
		$this->assertStringContainsString( 'HOSTNAME srvevil', DedicatedReinstall::config( array( '/dev/a' ), "srv\nevil ;", 'x.tar.gz', false ), 'The host name is cleaned.' );
	}

	public function test_a_customer_with_a_key_gets_the_key_installed_and_no_password(): void {
		$r = $this->controlled( true );
		$r->set( array( ManualServerDelivery::PASSWORD => \BeltoftServices\Crypto::encrypt( 'old-password' ) ) );
		DedicatedReinstall::start( Resource::find( $r->id() ), self::IMAGE );
		$r = $this->drive( $r );

		$this->assertSame( 'done', $r->get( DedicatedReinstall::META )['state'] );
		$start = $this->shell_calls( 'start' )[0];
		$this->assertTrue( $start['keys'], 'The key file is written.' );
		$this->assertStringContainsString( 'SSHKEYS_URL /root/ii-keys', (string) $start['config'] );
		$this->assertNull( ManualServerDelivery::root_password( $r ), 'The old root password is gone and there is no new one.' );
		$this->assertSame( array(), $this->robot_calls( 'rescue_activate' )[0]['keys'], 'The rescue system itself is still opened with its password, never with a key of ours.' );
	}

	public function test_the_rescue_password_is_encrypted_while_the_job_waits_and_wiped_at_the_end(): void {
		$r = $this->controlled();
		DedicatedReinstall::start( $r, self::IMAGE );
		Jobs::act( $r->id(), DedicatedReinstall::ACTION, array( 'image' => self::IMAGE ) );

		$memo     = Resource::find( $r->id() )->get( DedicatedReinstall::META );
		$password = FakeRobotClient::rescue_password( self::NUMBER );
		$this->assertNotSame( '', $password );
		$this->assertSame( 'wait', $memo['step'] );
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( $memo ) );

		$r = $this->drive( $r );
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( $r->get( DedicatedReinstall::META ) ) );
		$this->assertArrayNotHasKey( 'pw', $r->get( DedicatedReinstall::META ) );
	}

	public function test_an_image_that_the_rescue_system_does_not_have_stops_before_anything_is_erased(): void {
		$this->settings( array( 'dedicated_images' => "Debian-1201-bookworm-amd64-base.tar.gz\nGone-1-amd64-base.tar.gz" ) );
		$r = $this->controlled();
		$this->assertTrue( DedicatedReinstall::start( $r, 'Gone-1-amd64-base.tar.gz' ) );

		$r = $this->drive( $r, 'Gone-1-amd64-base.tar.gz' );
		$this->assertSame( 'failed', $r->get( DedicatedReinstall::META )['state'] );
		$this->assertSame( array(), $this->shell_calls( 'start' ), 'Nothing was written to the disks.' );
		$this->assertNotEmpty( $this->shell_calls( 'reboot' ), 'The server is started into its old system again.' );
		$this->assertNotEmpty( $this->robot_calls( 'rescue_deactivate' ) );
		$this->assertEqualsCanonicalizing( FakeRescueShell::IMAGES, ImageCatalog::names(), 'The real listing is kept for the next customer.' );
		$this->assertStringContainsString( 'not available any more', (string) $r->get( Jobs::RESULT )['text'] );
	}

	public function test_a_failed_installation_is_reported_and_the_controls_work_again(): void {
		$r      = $this->controlled();
		$before = ManualServerDelivery::root_password( $r );
		DedicatedReinstall::start( $r, self::IMAGE );
		FakeRescueShell::fail_next();
		$r = $this->drive( $r );

		$this->assertSame( 'failed', $r->get( DedicatedReinstall::META )['state'] );
		$this->assertNotEmpty( $this->robot_calls( 'rescue_deactivate' ) );
		$this->assertSame( $before, ManualServerDelivery::root_password( $r ), 'The old password is not replaced by a new one that was never installed.' );
		$this->assertNotEmpty( $this->staff_mails, 'Staff are told.' );
		$this->assertIsArray( ServerControls::reset( $r, 'sw' ), 'A failed reinstall does not block the server.' );
	}

	public function test_the_request_is_refused_for_an_unlisted_image_and_while_busy(): void {
		$r = $this->controlled();

		$this->assertSame( 'dedicated_invalid', DedicatedReinstall::start( $r, '../../etc/passwd' )->get_error_code() );
		$this->assertSame( 'dedicated_invalid', DedicatedReinstall::start( $r, 'Unknown-1-amd64-base.tar.gz' )->get_error_code() );
		$this->assertTrue( DedicatedReinstall::start( $r, self::IMAGE ) );
		$again = DedicatedReinstall::start( Resource::find( $r->id() ), self::IMAGE );
		$this->assertWPError( $again, 'A second reinstall while one runs.' );
	}

	public function test_the_rescue_password_never_reaches_the_resource_row_or_the_history(): void {
		$r = $this->controlled();
		DedicatedReinstall::start( $r, self::IMAGE );
		Jobs::act( $r->id(), DedicatedReinstall::ACTION, array( 'image' => self::IMAGE ) );
		$password = FakeRobotClient::rescue_password( self::NUMBER );

		$this->assertNotSame( '', $password );
		$row = (string) wp_json_encode( \BeltoftLicenses\Licenses::activation( $r->id() ) );
		$this->assertStringNotContainsString( $password, $row );
	}
}
