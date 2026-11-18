<?php
/**
 * Shared set-up of the dedicated server control tests: a delivered server that the fake Robot knows.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\ManualServer;

use BeltoftServices\ManualServer\FakeRescueShell;
use BeltoftServices\ManualServer\FakeRobotClient;
use BeltoftServices\ManualServer\ManualServerDelivery;
use BeltoftServices\ManualServer\RobotOrder;
use BeltoftServices\Resource;

require_once __DIR__ . '/ManualServerTestCase.php';

/**
 * Base class.
 */
abstract class ControlsTestCase extends ManualServerTestCase {

	protected const NUMBER = 400010;

	protected const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEw3oOyNIkNWlRSRbLtPNXZBxB1OPwJ0iVTU0nO3C0rW';

	public function set_up(): void {
		parent::set_up();
		$this->automatic();
		FakeRescueShell::reset();
		wp_cache_flush();
	}

	public function tear_down(): void {
		FakeRobotClient::reset();
		FakeRescueShell::reset();
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_now' );
		parent::tear_down();
	}

	/** The IPv4 address the fake gives this server. */
	protected function ipv4(): string {
		return '192.0.2.' . ( self::NUMBER % 250 + 1 );
	}

	/** The IPv6 network the fake gives this server. */
	protected function net(): string {
		return '2001:db8:' . dechex( self::NUMBER % 65535 ) . '::';
	}

	/**
	 * A delivered server with a supplier number the fake knows.
	 *
	 * @param bool     $with_key A customer SSH key was given at checkout.
	 * @param int|null $user     Customer.
	 */
	protected function controlled( bool $with_key = false, ?int $user = null ): Resource {
		$v = $this->server_product();
		$r = $this->ready_server( $v['u'], $user ?? $this->new_user() );
		FakeRobotClient::add_server( self::NUMBER );
		$meta = array(
			RobotOrder::NUMBER                => self::NUMBER,
			ManualServerDelivery::IPS         => array( $this->ipv4() ),
			RobotOrder::IPV6                  => $this->net(),
			ManualServerDelivery::NOTE        => null,
			'manual_ready_by'                 => 'automatic',
		);

		if ( $with_key ) {
			$meta['ssh_key'] = self::KEY;
		}

		$this->assertTrue( $r->set( $meta ) );

		return Resource::find( $r->id() );
	}
}
