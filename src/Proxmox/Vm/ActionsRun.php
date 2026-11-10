<?php
/**
 * What the run classes of Actions start from.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * For a class that uses Steps and Prepares and does one kind of server action for an Actions run: takes the prepared
 * client, cluster settings, secrets, wait and finish time from the Actions that created it.
 */
trait ActionsRun {

	/**
	 * Cluster settings (token secret decrypted), as Actions prepared them.
	 *
	 * @var array<string, mixed>
	 */
	private $cluster = array();

	/**
	 * @param Resource $r     Resource.
	 * @param Actions  $owner The Actions run that prepared the client and polled the stored task.
	 */
	public function __construct( Resource $r, Actions $owner ) {
		$this->r = $r;
		$state = $owner->state();

		$this->client    = $state['client'];
		$this->cluster   = $state['cluster'];
		$this->secrets   = $state['secrets'];
		$this->wait      = $state['wait'];
		$this->finished  = $state['finished'];
	}
}
