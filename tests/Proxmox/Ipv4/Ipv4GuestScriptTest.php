<?php
/**
 * The apply script that runs inside the guest (data/ipv4-extra-apply.script), run here against stub `ip`, `nmcli` and `netplan`
 * commands and a temporary state and etc folder. Nothing touches this machine's network.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Ipv4;

use BeltoftServices\Tests\TestCase;

class Ipv4GuestScriptTest extends TestCase {

	private const MAIN = '198.51.100.10';
	private const GW   = '198.51.100.1';
	private const X1   = '203.0.113.21';
	private const X2   = '203.0.113.22';

	/** @var string */
	private $dir = '';

	public function set_up(): void {
		parent::set_up();

		$this->dir = sys_get_temp_dir() . '/bs-xip-' . wp_generate_password( 8, false );

		foreach ( array( 'stub', 'state', 'etc/netplan', 'etc/network/interfaces.d', 'bin' ) as $sub ) {
			mkdir( $this->dir . '/' . $sub, 0700, true );
		}

		// Only the commands the script needs, so the machine's own ip, nmcli and netplan are never found.
		foreach ( array( 'awk', 'sed', 'grep', 'tr', 'mktemp', 'date', 'head', 'cp', 'rm', 'mv', 'mkdir', 'chmod', 'cat', 'ls' ) as $tool ) {
			$path = trim( (string) shell_exec( 'command -v ' . $tool ) );
			$this->assertNotSame( '', $path, $tool );
			symlink( $path, $this->dir . '/bin/' . $tool );
		}

		file_put_contents( $this->dir . '/stub/addrs', 'ens18 ' . self::MAIN . "/24\n" );
		file_put_contents( $this->dir . '/stub/route', 'default via ' . self::GW . " dev ens18 proto static\n" );
		file_put_contents( $this->dir . '/stub/mac', "bc:24:11:aa:bb:cc\n" );
	}

	public function tear_down(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
		parent::tear_down();
	}

	/** Makes a stub command available on the script's PATH. */
	private function stub( string $name ): void {
		$target = $this->dir . '/bin/' . $name;

		if ( file_exists( $target ) ) {
			return;
		}

		copy( BELTOFT_SERVICES_PATH . 'tests/fixtures/guest-stubs/' . $name, $target );
		chmod( $target, 0755 );
	}

	private function cloud_init_netplan( string $id = 'ens18' ): void {
		$yaml = "network:\n  version: 2\n  ethernets:\n    {$id}:\n      match:\n        macaddress: \"bc:24:11:aa:bb:cc\"\n      set-name: \"ens18\"\n      addresses:\n      - \"" . self::MAIN . "/24\"\n      routes:\n      - to: default\n        via: " . self::GW . "\n";
		file_put_contents( $this->dir . '/etc/netplan/50-cloud-init.yaml', $yaml );
		file_put_contents( $this->dir . '/stub/netplan_get', $yaml );
		$this->stub( 'netplan' );
	}

	private function want( array $extras, string $main = self::MAIN, string $run = 'r1' ): void {
		file_put_contents( $this->dir . '/state/ipv4-extra.json', wp_json_encode( array( 'v' => 1, 'run' => $run, 'main' => $main, 'extras' => $extras ) ) );
	}

	/**
	 * Runs the script. Returns the status it wrote and the exit code.
	 *
	 * @param array<string, string> $env Extra environment (stub switches).
	 * @return array{0: array<string, mixed>, 1: int}
	 */
	private function run_script( array $env = array() ): array {
		$this->stub( 'ip' );
		$vars = array_merge(
			array(
				'PATH'                  => $this->dir . '/bin',
				'STUB_DIR'              => $this->dir . '/stub',
				'BELTOFT_XIP_STATE'     => $this->dir . '/state',
				'BELTOFT_XIP_ETC'       => $this->dir . '/etc',
				'BELTOFT_XIP_KEEP_PATH' => '1',
			),
			$env
		);
		$line = 'env -i';

		foreach ( $vars as $name => $value ) {
			$line .= ' ' . $name . '=' . escapeshellarg( $value );
		}

		exec( $line . ' /bin/sh ' . escapeshellarg( BELTOFT_SERVICES_PATH . 'data/ipv4-extra-apply.script' ) . ' 2>&1', $out, $code );
		$status = json_decode( (string) @file_get_contents( $this->dir . '/state/ipv4-extra.status.json' ), true );
		$this->assertIsArray( $status, 'The script always leaves a status. Output: ' . implode( "\n", $out ) );

		return array( $status, $code );
	}

	/** @return string[] The addresses on the stub card, as "address/len". */
	private function addresses(): array {
		$out = array();

		foreach ( file( $this->dir . '/stub/addrs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$out[] = explode( ' ', $line )[1];
		}

		return $out;
	}

	private function read( string $path ): string {
		return (string) @file_get_contents( $this->dir . '/' . $path );
	}

	public function test_the_script_is_valid_posix_shell(): void {
		$file = BELTOFT_SERVICES_PATH . 'data/ipv4-extra-apply.script';

		foreach ( array( '/bin/sh', '/bin/dash' ) as $shell ) {
			if ( ! is_executable( $shell ) ) {
				continue;
			}

			exec( $shell . ' -n ' . escapeshellarg( $file ) . ' 2>&1', $out, $code );
			$this->assertSame( 0, $code, $shell . ': ' . implode( "\n", $out ) );
		}

		$this->assertSame( '#!/bin/sh', strtok( (string) file_get_contents( $file ), "\n" ) );
		$this->assertDoesNotMatchRegularExpression( '/\b(reboot|shutdown|netplan apply|systemctl restart|ifdown|nmcli connection (up|down|delete|add))\b/', (string) preg_replace( '/^\s*#.*$/m', '', (string) file_get_contents( $file ) ), 'The network is never restarted and no second profile is made.' );
	}

	public function test_netplan_a_drop_in_with_the_same_ethernet_id_and_only_the_addresses(): void {
		$this->cloud_init_netplan();
		$this->want( array( self::X1, self::X2 ) );
		list( $status, $code ) = $this->run_script();

		$this->assertSame( 0, $code, wp_json_encode( $status ) );
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 'netplan', $status['stack'] );
		$this->assertSame( 'ens18', $status['nic'], 'The card is the one that holds the main address.' );
		$this->assertSame( 'ens18' === $status['nic'] ? array( self::X1, self::X2 ) : array(), $status['applied'] );
		$this->assertTrue( $status['persisted'] );
		$this->assertSame( array( self::MAIN . '/24', self::X1 . '/32', self::X2 . '/32' ), $this->addresses() );

		$drop = $this->read( 'etc/netplan/61-beltoft-extra.yaml' );
		$this->assertStringContainsString( "    ens18:\n      addresses:\n        - " . self::X1 . "/32\n        - " . self::X2 . "/32\n", $drop );
		$this->assertStringNotContainsString( self::MAIN, $drop, 'The main address is never in our file.' );
		$this->assertStringNotContainsString( 'routes', $drop );
		$this->assertStringNotContainsString( 'match', $drop, 'The definition is merged by id, not made a second time.' );
		$this->assertStringNotContainsString( 'netplan apply', $this->read( 'stub/netplan.log' ) );
		$this->assertStringContainsString( 'netplan generate', $this->read( 'stub/netplan.log' ) );
		$this->assertSame( '0600', substr( sprintf( '%o', fileperms( $this->dir . '/etc/netplan/61-beltoft-extra.yaml' ) ), -4 ) );
	}

	public function test_the_card_is_found_by_the_main_address_and_not_assumed_to_be_eth0(): void {
		file_put_contents( $this->dir . '/stub/addrs', "lo 127.0.0.1/8\nenp6s18 " . self::MAIN . "/24\n" );
		$this->cloud_init_netplan( 'zeta' );
		file_put_contents( $this->dir . '/etc/netplan/50-cloud-init.yaml', str_replace( 'ens18', 'enp6s18', $this->read( 'etc/netplan/50-cloud-init.yaml' ) ) );
		file_put_contents( $this->dir . '/stub/netplan_get', $this->read( 'etc/netplan/50-cloud-init.yaml' ) );
		$this->want( array( self::X1 ) );
		list( $status ) = $this->run_script();

		$this->assertSame( 'enp6s18', $status['nic'] );
		$this->assertContains( self::X1 . '/32', $this->addresses() );
		$this->assertStringNotContainsString( 'lo', implode( ' ', array_filter( file( $this->dir . '/stub/addrs' ), static fn( $l ) => false !== strpos( $l, self::X1 ) ) ) );
		$this->assertStringContainsString( '    zeta:', $this->read( 'etc/netplan/61-beltoft-extra.yaml' ), 'The id is the one the cloud-init file uses (here the MAC match with another name).' . $this->read( 'etc/netplan/61-beltoft-extra.yaml' ) );
	}

	public function test_it_is_idempotent_and_removes_only_what_it_added(): void {
		$this->cloud_init_netplan();
		$this->want( array( self::X1, self::X2 ) );
		$this->run_script();
		$once = $this->addresses();
		$drop = $this->read( 'etc/netplan/61-beltoft-extra.yaml' );

		list( $again ) = $this->run_script();
		$this->assertSame( $once, $this->addresses(), 'The second run changes nothing.' );
		$this->assertSame( $drop, $this->read( 'etc/netplan/61-beltoft-extra.yaml' ) );
		$this->assertSame( array(), $again['added'] );

		// One extra goes away.
		$this->want( array( self::X2 ), self::MAIN, 'r2' );
		list( $less ) = $this->run_script();
		$this->assertSame( array( self::X1 ), $less['removed'] );
		$this->assertSame( array( self::MAIN . '/24', self::X2 . '/32' ), $this->addresses() );
		$this->assertStringNotContainsString( self::X1, $this->read( 'etc/netplan/61-beltoft-extra.yaml' ) );
		$this->assertSame( self::X2 . " added\n", $this->read( 'state/ipv4-extra.owned' ) );

		// None left: our file holds no address, the main address is still there.
		$this->want( array(), self::MAIN, 'r3' );
		$this->run_script();
		$this->assertSame( array( self::MAIN . '/24' ), $this->addresses() );
		$this->assertStringNotContainsString( '/32', $this->read( 'etc/netplan/61-beltoft-extra.yaml' ) );
		$this->assertSame( '', $this->read( 'state/ipv4-extra.owned' ) );
	}

	public function test_an_address_that_was_already_there_is_adopted_and_never_removed(): void {
		$this->cloud_init_netplan();
		file_put_contents( $this->dir . '/stub/addrs', 'ens18 ' . self::MAIN . "/24\nens18 " . self::X1 . "/32\n", FILE_APPEND | LOCK_EX );
		file_put_contents( $this->dir . '/stub/addrs', 'ens18 ' . self::MAIN . "/24\nens18 " . self::X1 . "/32\n" );
		$this->want( array( self::X1 ) );
		list( $status ) = $this->run_script();

		$this->assertSame( array( self::X1 ), $status['adopted'] );
		$this->assertSame( array(), $status['added'] );
		$this->assertSame( self::X1 . " adopted\n", $this->read( 'state/ipv4-extra.owned' ) );

		$this->want( array(), self::MAIN, 'r2' );
		$this->run_script();
		$this->assertContains( self::X1 . '/32', $this->addresses(), 'It did not add the address, so it does not take it away.' );
		$this->assertStringNotContainsString( self::X1, $this->read( 'etc/netplan/61-beltoft-extra.yaml' ) );
	}

	public function test_an_address_the_script_did_not_list_is_never_removed(): void {
		$this->cloud_init_netplan();
		file_put_contents( $this->dir . '/stub/addrs', 'ens18 ' . self::MAIN . "/24\nens18 192.0.2.77/32\n" );
		$this->want( array( self::X1 ) );
		$this->run_script();
		$this->want( array(), self::MAIN, 'r2' );
		$this->run_script();

		$this->assertSame( array( self::MAIN . '/24', '192.0.2.77/32' ), $this->addresses(), 'A customer address that is not in the owned list stays.' );
	}

	public function test_the_main_address_is_not_an_extra_and_must_be_on_a_card(): void {
		$this->cloud_init_netplan();
		$this->want( array( self::MAIN, 'not-an-address', '999.1.1.1', self::X1 ) );
		list( $status ) = $this->run_script();

		$this->assertFalse( $status['ok'] );
		$this->assertSame( array( self::X1 ), $status['wanted'] );
		$this->assertSame( array( self::MAIN . '/24', self::X1 . '/32' ), $this->addresses() );
		$this->assertStringContainsString( 'is the main address', implode( ' ', $status['errors'] ) );

		// The main address is on no card: nothing is changed.
		$this->want( array( self::X2 ), '192.0.2.200', 'r2' );
		list( $stopped, $code ) = $this->run_script();
		$this->assertSame( 1, $code );
		$this->assertSame( array( self::MAIN . '/24', self::X1 . '/32' ), $this->addresses() );
		$this->assertStringContainsString( 'is not on any network interface', implode( ' ', $stopped['errors'] ) );
		$this->assertSame( 'r2', $stopped['run'] );
	}

	public function test_a_step_that_would_lose_the_main_address_is_undone_and_reported(): void {
		$this->cloud_init_netplan();
		$this->want( array( self::X1 ) );
		list( $status, $code ) = $this->run_script( array( 'STUB_DROP_MAIN' => self::MAIN . '/24' ) );

		$this->assertSame( 1, $code );
		$this->assertFalse( $status['ok'] );
		$this->assertContains( self::MAIN . '/24', $this->addresses(), 'The main address was put back.' );
		$this->assertStringContainsString( 'main address', implode( ' ', $status['errors'] ) );
		$this->assertFileDoesNotExist( $this->dir . '/etc/netplan/61-beltoft-extra.yaml', 'Nothing was made persistent after the main address was in danger.' );
	}

	public function test_a_lost_default_route_is_put_back(): void {
		$this->cloud_init_netplan();
		$this->want( array( self::X1 ) );
		list( $status ) = $this->run_script( array( 'STUB_ADD_FAIL' => '' ) );
		$this->assertTrue( $status['ok'] );

		// A route that changes while the script runs (the stub drops it at the first add).
		unlink( $this->dir . '/state/ipv4-extra.owned' );
		file_put_contents( $this->dir . '/stub/addrs', 'ens18 ' . self::MAIN . "/24\n" );
		$route = $this->dir . '/stub/route';
		$ip    = $this->dir . '/bin/ip';
		file_put_contents( $ip, str_replace( '"addr add")', "\"addr add\")\n\t: >\"\$D/route\"", (string) file_get_contents( $ip ) ) );
		$this->want( array( self::X1 ), self::MAIN, 'r2' );
		list( $after ) = $this->run_script();

		$this->assertStringContainsString( 'default route', implode( ' ', $after['errors'] ) );
		$this->assertStringContainsString( 'default via ' . self::GW, (string) file_get_contents( $route ), 'The route was put back.' );
	}

	public function test_a_failing_add_and_a_refused_netplan_file_are_reported_and_the_file_is_removed(): void {
		$this->cloud_init_netplan();
		$this->want( array( self::X1 ) );
		list( $status ) = $this->run_script( array( 'STUB_GEN_RC' => '1' ) );

		$this->assertFalse( $status['ok'] );
		$this->assertFalse( $status['persisted'] );
		$this->assertContains( self::X1 . '/32', $this->addresses(), 'It still works live.' );
		$this->assertFileDoesNotExist( $this->dir . '/etc/netplan/61-beltoft-extra.yaml' );
		$this->assertStringContainsString( 'netplan generate refused', implode( ' ', $status['errors'] ) );

		unlink( $this->dir . '/state/ipv4-extra.owned' );
		file_put_contents( $this->dir . '/stub/addrs', 'ens18 ' . self::MAIN . "/24\n" );
		$this->want( array( self::X2 ), self::MAIN, 'r2' );
		list( $failed ) = $this->run_script( array( 'STUB_ADD_FAIL' => '1' ) );
		$this->assertFalse( $failed['ok'] );
		$this->assertSame( array(), $failed['applied'] );
		$this->assertStringContainsString( 'ip addr add', implode( ' ', $failed['errors'] ) );
	}

	public function test_netplan_without_a_definition_for_the_card_stays_live_only(): void {
		$this->cloud_init_netplan();
		file_put_contents( $this->dir . '/stub/netplan_get', "network:\n  version: 2\n  ethernets:\n    other0:\n      dhcp4: true\n" );
		file_put_contents( $this->dir . '/etc/netplan/50-cloud-init.yaml', $this->read( 'stub/netplan_get' ) );
		$this->want( array( self::X1 ) );
		list( $status ) = $this->run_script();

		$this->assertFalse( $status['persisted'] );
		$this->assertContains( self::X1 . '/32', $this->addresses() );
		$this->assertFileDoesNotExist( $this->dir . '/etc/netplan/61-beltoft-extra.yaml', 'No second definition for the card: networkd would use the first file only and could drop the main address.' );
		$this->assertStringContainsString( 'no ethernet definition', implode( ' ', $status['errors'] ) );
	}

	public function test_networkmanager_adds_to_the_existing_profile_and_never_makes_another(): void {
		file_put_contents( $this->dir . '/stub/nm_conn', "cloud-init ens18\n" );
		file_put_contents( $this->dir . '/stub/nm_addrs', self::MAIN . "/24\n" );
		$this->stub( 'nmcli' );
		$this->want( array( self::X1, self::X2 ) );
		list( $status ) = $this->run_script();

		$this->assertTrue( $status['ok'], wp_json_encode( $status ) );
		$this->assertSame( 'networkmanager', $status['stack'] );
		$this->assertSame( 'cloud-init ens18', $status['profile'] );
		$this->assertTrue( $status['persisted'] );
		$this->assertSame( array( self::MAIN . '/24', self::X1 . '/32', self::X2 . '/32' ), array_values( array_filter( explode( "\n", $this->read( 'stub/nm_addrs' ) ) ) ) );
		$log = $this->read( 'stub/nmcli.log' );
		$this->assertStringContainsString( 'connection modify cloud-init ens18 +ipv4.addresses ' . self::X1 . '/32', $log );
		$this->assertStringNotContainsString( 'connection add', $log );
		$this->assertStringNotContainsString( 'connection up', $log );
		$this->assertDirectoryDoesNotExist( $this->dir . '/etc/NetworkManager' );

		// Again: nothing is added twice. Then one goes away.
		$this->run_script();
		$this->assertSame( 3, count( array_filter( explode( "\n", $this->read( 'stub/nm_addrs' ) ) ) ) );
		$this->want( array( self::X1 ), self::MAIN, 'r2' );
		$this->run_script();
		$this->assertSame( array( self::MAIN . '/24', self::X1 . '/32' ), array_values( array_filter( explode( "\n", $this->read( 'stub/nm_addrs' ) ) ) ) );
	}

	public function test_networkmanager_that_cannot_change_the_profile_leaves_it_as_it_was(): void {
		file_put_contents( $this->dir . '/stub/nm_conn', "cloud-init ens18\n" );
		file_put_contents( $this->dir . '/stub/nm_addrs', self::MAIN . "/24\n" );
		$this->stub( 'nmcli' );
		$this->want( array( self::X1 ) );
		list( $status ) = $this->run_script( array( 'STUB_NM_FAIL' => '1' ) );

		$this->assertFalse( $status['ok'] );
		$this->assertContains( self::MAIN . '/24', $this->addresses() );
		$this->assertStringContainsString( self::MAIN . '/24', $this->read( 'stub/nm_addrs' ) );
		$this->assertStringContainsString( 'nmcli could not add', implode( ' ', $status['errors'] ) );
	}

	public function test_ifupdown_gets_a_file_of_its_own_when_interfaces_d_is_sourced(): void {
		file_put_contents( $this->dir . '/etc/network/interfaces', "# managed\nsource /etc/network/interfaces.d/*\n" );
		file_put_contents( $this->dir . '/etc/network/interfaces.d/50-cloud-init', "auto ens18\niface ens18 inet static\n    address " . self::MAIN . "/24\n" );
		$this->want( array( self::X1, self::X2 ) );
		list( $status ) = $this->run_script();

		$this->assertTrue( $status['ok'], wp_json_encode( $status ) );
		$this->assertSame( 'ifupdown', $status['stack'] );
		$this->assertTrue( $status['persisted'] );
		$file = $this->read( 'etc/network/interfaces.d/61-beltoft-extra' );
		$this->assertStringContainsString( "auto ens18:1\niface ens18:1 inet manual\n    up ip addr add " . self::X1 . "/32 dev ens18\n    down ip addr del " . self::X1 . "/32 dev ens18\n", $file );
		$this->assertStringContainsString( 'ens18:2', $file );
		$this->assertStringNotContainsString( self::MAIN, $file );
		$this->assertSame( "# managed\nsource /etc/network/interfaces.d/*\n", $this->read( 'etc/network/interfaces' ), 'The main interfaces file is untouched.' );
		$this->assertStringContainsString( 'iface ens18 inet static', $this->read( 'etc/network/interfaces.d/50-cloud-init' ), 'The cloud-init file is untouched.' );
	}

	public function test_ifupdown_without_interfaces_d_is_live_only_and_says_so(): void {
		file_put_contents( $this->dir . '/etc/network/interfaces', "auto ens18\niface ens18 inet static\n    address " . self::MAIN . "/24\n" );
		$this->want( array( self::X1 ) );
		list( $status ) = $this->run_script();

		$this->assertFalse( $status['persisted'] );
		$this->assertFalse( $status['ok'] );
		$this->assertContains( self::X1 . '/32', $this->addresses() );
		$this->assertFileDoesNotExist( $this->dir . '/etc/network/interfaces.d/61-beltoft-extra' );
		$this->assertStringContainsString( 'live only', implode( ' ', $status['errors'] ) );
	}

	public function test_an_unknown_stack_is_live_only(): void {
		$this->want( array( self::X1 ) );
		list( $status ) = $this->run_script();

		$this->assertSame( 'none', $status['stack'] );
		$this->assertFalse( $status['persisted'] );
		$this->assertContains( self::X1 . '/32', $this->addresses() );
	}

	public function test_a_missing_wanted_list_is_reported(): void {
		list( $status, $code ) = $this->run_script();

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'is missing', implode( ' ', $status['errors'] ) );
		$this->assertSame( array( self::MAIN . '/24' ), $this->addresses() );
	}
}
