<?php
/**
 * A node client that can only read.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Proxmox\Pve\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps a node client and lets GET calls through; anything else is refused here, before it reaches the client
 * (and so before it could reach a real node). The read-only checks of `wp beltoft-services hosting node check` use
 * it, so a check can never change a node, whatever code it calls.
 */
final class ReadOnlyClient implements Client {

	/**
	 * The client behind.
	 *
	 * @var Client
	 */
	private $inner;

	/**
	 * @param Client $inner The client to wrap.
	 */
	public function __construct( Client $inner ) {
		$this->inner = $inner;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $method Method.
	 * @param string               $path   Path.
	 * @param array<string, mixed> $params Parameters.
	 */
	public function request( string $method, string $path, array $params = array() ) {
		if ( 'GET' !== strtoupper( $method ) ) {
			return new \WP_Error( 'read_only', 'This client only reads: ' . strtoupper( $method ) . ' is refused.' );
		}

		return $this->inner->request( 'GET', $path, $params );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $method Method.
	 * @param string               $path   Path.
	 * @param array<string, mixed> $params Parameters.
	 */
	public function task( string $method, string $path, array $params = array() ) {
		unset( $path, $params );

		return new \WP_Error( 'read_only', 'This client only reads: ' . strtoupper( $method ) . ' tasks are refused.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $upid    Task id.
	 * @param int    $timeout Seconds.
	 */
	public function wait_task( string $upid, int $timeout ) {
		return $this->inner->wait_task( $upid, $timeout );
	}
}
