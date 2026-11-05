<?php
/**
 * The Hosting settings tab, the nodes screen and the node CLI.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';
require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

use BeltoftServices\Cli\HostingCommand;
use BeltoftServices\Hosting\Config;
use BeltoftServices\Hosting\HostingSettings;
use BeltoftServices\Hosting\Nodes;
use BeltoftServices\Hosting\NodesScreen;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

/**
 * Settings, NodesScreen and HostingCommand.
 */
class SettingsCliTest extends HostingCase {

	public function set_up(): void {
		parent::set_up();
		\WP_CLI::reset();
	}

	public function test_the_tabs_and_fields_exist_and_defaults_are_neutral(): void {
		$this->assertArrayHasKey( 'hosting', Settings::tabs() );
		$this->assertArrayHasKey( 'hosting-nodes', Settings::tabs() );

		foreach ( array_keys( Config::DEFAULTS ) as $key ) {
			$this->assertArrayHasKey( 'hosting_' . $key, Settings::fields(), $key );
		}

		delete_option( Settings::OPTION );
		$this->assertSame( '', Config::get( 'preview_zone' ) );
		$this->assertSame( '', Config::preview_zone() );
		$this->assertSame( '8.3', Config::get( 'php_default' ) );
		$this->assertSame( 'hosting', Config::tag() );
		$this->assertSame( 'owner', Config::admin_user() );
		$this->assertSame( 1.5, Config::number( 'mem_overcommit', 1.0 ) );
		$this->assertSame( 2.0, Config::number( 'disk_overcommit', 1.0 ) );
		$this->assertSame( 'c7', Config::account( 7 ) );
	}

	public function test_saving_cleans_and_clamps_the_values(): void {
		$clean = Settings::sanitize(
			array(
				'hosting_preview_zone'    => ' Preview.Example.TEST ',
				'hosting_php_default'     => 'eight',
				'hosting_prefix'          => 'Ab-1!xyz123456',
				'hosting_tag'             => 'My Tag_1',
				'hosting_admin_user'      => 'Bad User',
				'hosting_mem_overcommit'  => '9',
				'hosting_disk_overcommit' => '0.2',
				'hosting_alert_percent'   => '500',
			)
		);
		$clean = HostingSettings::sanitize( $clean );

		$this->assertSame( 'preview.example.test', $clean['hosting_preview_zone'] );
		$this->assertSame( '8.3', $clean['hosting_php_default'] );
		$this->assertSame( 'ab1xyz12', $clean['hosting_prefix'] );
		$this->assertSame( 'mytag1', $clean['hosting_tag'] );
		$this->assertSame( 'owner', $clean['hosting_admin_user'] );
		$this->assertSame( '4', $clean['hosting_mem_overcommit'] );
		$this->assertSame( '1', $clean['hosting_disk_overcommit'] );
		$this->assertSame( '100', $clean['hosting_alert_percent'] );

		$this->assertSame( '', HostingSettings::sanitize( array( 'hosting_preview_zone' => 'not a zone' ) )['hosting_preview_zone'] );
	}

	public function test_provisioning_uses_the_settings_not_hardcoded_values(): void {
		$this->settings( array( 'hosting_tag' => 'webhost', 'hosting_admin_user' => 'siteowner', 'hosting_prefix' => 'h' ) );
		$r = $this->resource();
		$this->provision_fully( $r );

		$this->assertSame( 'webhost', $this->ct( $r )['config']['tags'] );
		$this->assertSame( 'h' . $r->id(), $this->ct( $r )['name'] );
		$this->assertSame( 'siteowner', $this->agent->calls_of( 'site.wp_install' )[0]['args']['admin_user'] );
	}

	public function test_the_nodes_screen_lists_nodes_and_never_prints_a_secret(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		NodesScreen::render();
		$list = (string) ob_get_clean();
		$this->assertStringContainsString( 'Node one', $list );
		$this->assertStringContainsString( '<code>n1</code>', $list );

		$_GET['edit'] = 'n1';
		ob_start();
		NodesScreen::render();
		$form = (string) ob_get_clean();
		unset( $_GET['edit'] );

		$this->assertStringContainsString( 'name="token_secret"', $form );
		$this->assertStringContainsString( 'placeholder="Saved"', $form );
		$this->assertStringNotContainsString( self::NODE_SECRET, $form );
		$this->assertStringNotContainsString( self::AGENT_KEY, $form );
		$this->assertStringContainsString( 'value="10.200.0.0/16"', $form );
		$this->assertStringNotContainsString( "\u{2014}", $form );

		wp_set_current_user( 0 );
		ob_start();
		NodesScreen::render();
		$this->assertSame( '', (string) ob_get_clean(), 'Nothing for someone without the capability.' );
	}

	public function test_cli_list_status_and_add_never_print_secrets(): void {
		$cli = new HostingCommand();

		$cli->node( array( 'list' ), array() );
		$this->assertStringContainsString( 'n1  Node one  group=g1  client=fake  enabled=1  accounts=0', \WP_CLI::text() );

		\WP_CLI::reset();
		$cli->node( array( 'status', 'n1' ), array() );
		$text = \WP_CLI::text();
		$this->assertStringContainsString( 'token_secret: saved', $text );
		$this->assertStringContainsString( 'agent_key: saved', $text );
		$this->assertStringContainsString( 'live allowed: no', $text );
		$this->assertStringNotContainsString( self::NODE_SECRET, $text );
		$this->assertStringNotContainsString( self::AGENT_KEY, $text );

		\WP_CLI::reset();
		$cli->node(
			array( 'add', 'n2' ),
			array(
				'label'        => 'Two',
				'node_group'   => 'g1',
				'api_url'      => 'https://n2.example.invalid:8006',
				'token_id'     => 'svc@pve!hosting',
				'token_secret' => 'second-node-secret-01',
				'pve_node'     => 'pve2',
				'agent_url'    => 'https://n2.example.invalid:8765',
				'agent_key_id' => 'k1',
				'agent_key'    => 'second-agent-key-0001',
				'pool'         => 'hosting-pool',
				'storage'      => 'local-zfs',
				'bridge'       => 'hbr0',
				'gateway'      => '10.200.0.1',
				'lan_cidr'     => '10.200.0.0/16',
				'ip_first'     => '10',
				'ssh_port_min' => '22001',
				'ssh_port_max' => '22999',
				'vmid_min'     => '1000',
				'vmid_max'     => '1999',
				'template'     => '9000',
				'net_mbit'     => '0',
				'mem_gb'       => '32',
				'disk_gb'      => '1000',
				'db_host'      => '10.200.0.3',
			)
		);
		$this->assertArrayHasKey( 'n2', Nodes::all() );
		$this->assertStringNotContainsString( 'second-node-secret', \WP_CLI::text() );
	}

	public function test_cli_refuses_bad_input(): void {
		$cli = new HostingCommand();

		foreach ( array(
			array( array( 'nonsense' ), array() ),
			array( array( 'add', '' ), array() ),
			array( array( 'add', 'bad id' ), array() ),
			array( array( 'status', 'nope' ), array() ),
			array( array( 'check', 'nope' ), array() ),
		) as $case ) {
			try {
				$cli->node( $case[0], $case[1] );
				$this->fail( 'No error for ' . implode( ' ', $case[0] ) );
			} catch ( \RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_cli_check_reads_only_and_reports_problems(): void {
		$cli = new HostingCommand();

		$cli->node( array( 'check', 'n1' ), array() );
		$this->assertStringContainsString( 'checks passed', \WP_CLI::text() );
		$this->assertStringContainsString( 'Containers listed: 1', \WP_CLI::text() );

		foreach ( $this->node->calls() as $call ) {
			$this->assertSame( 'GET', $call['method'], 'Only reads are sent to the node.' );
		}

		$this->assertCount( 1, $this->agent->calls() );
		$this->assertSame( 'agent.health', $this->agent->calls()[0]['op'] );

		// A template that is not there.
		$this->save_node( array( 'template' => '9100' ) );
		\WP_CLI::reset();
		try {
			$cli->node( array( 'check', 'n1' ), array() );
			$this->fail( 'The missing template was not reported.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '1 problem', $e->getMessage() );
			$this->assertStringContainsString( 'template 9100', \WP_CLI::text() );
		}

		// A listing error is an error, not a pass.
		$this->save_node();
		$this->node->fail_next( 'GET', '#/cluster/resources$#', array( 'status' => 500 ) );
		$this->expectException( \RuntimeException::class );
		$cli->node( array( 'check', 'n1' ), array() );
	}
}
