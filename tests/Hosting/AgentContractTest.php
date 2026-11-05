<?php
/**
 * The plugin's FakeAgent against the node agent's own contract file. Runs only when the agent repository is next to
 * this one; the file is read, never written, and nothing is executed from it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Hosting\FakeAgent;
use BeltoftServices\Tests\TestCase;

/**
 * FakeAgent::OPERATIONS and tests/contract.json of the agent.
 */
class AgentContractTest extends TestCase {

	private const FILE = '/var/www/wordpress/workspaces/hosting-agent/tests/contract.json';

	/**
	 * What this plugin adds to the agent's contract, and the agent must take (reported in the hand-over notes):
	 * operation => argument names. An empty list means the whole operation is the plugin's.
	 */
	private const PLUGIN_ADDITIONS = array(
		'ct.prepare'        => array( 'ssh' ),
		'site.wp_install'   => array( 'woocommerce' ),
		'site.php'          => array( 'memory_mb', 'upload_mb' ),
		'backup.prepare'    => array( 'label' ),
		'db.readonly'       => array(),
	);

	/**
	 * The contract, or null.
	 *
	 * @return array<string, array<string, array{0: string, 1: bool}>>|null
	 */
	private function contract(): ?array {
		if ( ! is_readable( self::FILE ) ) {
			return null;
		}

		$data = json_decode( (string) file_get_contents( self::FILE ), true );

		return is_array( $data ) ? $data : null;
	}

	public function test_every_operation_of_the_agent_is_in_the_fake_with_the_same_arguments(): void {
		$contract = $this->contract();

		if ( null === $contract ) {
			$this->markTestSkipped( 'The agent contract file is not present.' );
		}

		foreach ( $contract as $op => $args ) {
			$this->assertArrayHasKey( $op, FakeAgent::OPERATIONS, $op . ' is an agent operation the fake does not know.' );

			foreach ( $args as $name => $spec ) {
				$this->assertArrayHasKey( $name, FakeAgent::OPERATIONS[ $op ], $op . ' argument ' . $name );
				$this->assertSame( $spec[0], FakeAgent::OPERATIONS[ $op ][ $name ][0], $op . ' ' . $name . ' type' );
				$this->assertSame( (bool) $spec[1], (bool) FakeAgent::OPERATIONS[ $op ][ $name ][1], $op . ' ' . $name . ' required' );
			}
		}
	}

	public function test_the_fake_has_nothing_the_agent_lacks_except_the_listed_additions(): void {
		$contract = $this->contract();

		if ( null === $contract ) {
			$this->markTestSkipped( 'The agent contract file is not present.' );
		}

		$this->addToAssertionCount( 1 );

		foreach ( FakeAgent::OPERATIONS as $op => $args ) {
			$known = array_key_exists( $op, $contract );

			if ( ! $known ) {
				$this->assertArrayHasKey( $op, self::PLUGIN_ADDITIONS, $op . ' is in the fake but not in the agent contract and not in the list of additions.' );

				continue;
			}

			foreach ( array_keys( $args ) as $name ) {
				if ( ! array_key_exists( $name, $contract[ $op ] ) ) {
					$this->assertContains( $name, self::PLUGIN_ADDITIONS[ $op ] ?? array(), $op . ' argument ' . $name . ' is in the fake but not in the agent contract and not in the list of additions.' );
				}
			}
		}
	}

	public function test_the_additions_are_all_optional_arguments_or_whole_operations(): void {
		foreach ( self::PLUGIN_ADDITIONS as $op => $names ) {
			$this->assertArrayHasKey( $op, FakeAgent::OPERATIONS );

			foreach ( $names as $name ) {
				$this->assertFalse( (bool) FakeAgent::OPERATIONS[ $op ][ $name ][1], $op . ' ' . $name . ' is optional, so an older agent contract still works.' );
			}
		}
	}
}
