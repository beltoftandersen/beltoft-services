<?php
/**
 * An engine whose customer chooses what happens when its memory is full.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by an engine on top of DbEngine when it is an in-memory store with an eviction policy (Valkey). The
 * database page then offers "When memory is full" (DbPolicy). An engine without it offers nothing.
 */
interface DbEngineEviction {

	/**
	 * The policies the customer may choose: policy => array( title, plain explanation ).
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public function eviction_choices(): array;

	/**
	 * The policy of a server that was never changed.
	 */
	public function eviction_default(): string;
}
