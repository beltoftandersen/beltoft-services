<?php
/**
 * What monitoring needs to know about one kind of guest (a virtual machine, a container).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Monitoring;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The adapter of one driver. Everything shared (history table, 5-minute round, live route, charts route, alert rules,
 * panel) lives in this folder and asks the adapter only for inputs: where the guest runs, how to read its memory, its rrd
 * samples and its disk. An adapter holds no thresholds, no storage and no rolling; the folder names no driver. Adapters
 * are added on the filter `beltoft_services_monitoring_guests` (see Guests).
 *
 * Every read an adapter makes is a read, after the guest is proven ours (its tag), and never anything else.
 */
interface Guest {

	/**
	 * Is this resource served by this adapter (its monitoring page, routes, live bars and usage emails)?
	 *
	 * @param Resource $r Resource.
	 */
	public function owns( Resource $r ): bool;

	/**
	 * The guests that are up now and of which a memory reading is wanted (they may include ones that `owns()` is false for,
	 * such as database servers, which are read but never mailed). One read of the listing; never a call per guest.
	 *
	 * @return array<int, array{resource: Resource, group: string, uptime: ?int, ref: mixed}> `group` is the node (a failing node is skipped for the round), `ref` is handed back to memory().
	 */
	public function running(): array;

	/**
	 * Power, cpu (0 to 1) and uptime of the given owned resources, from one listing kept a few seconds.
	 *
	 * @param Resource[] $resources Resources this adapter owns.
	 * @return array<int, array{power: string, cpu: float, uptime: ?int, ref: mixed}> By resource id; a resource the listing does not show is left out.
	 */
	public function live_rows( array $resources ): array;

	/**
	 * Memory in use (without the file cache) of a running guest, or null when it cannot be read.
	 *
	 * @param Resource  $r         Resource.
	 * @param mixed     $ref       The `ref` of running() or live_rows().
	 * @param bool|null $node_down Set to true when the node itself did not answer (not the guest).
	 * @return array{used_mb: int, total_mb: int}|null
	 */
	public function memory( Resource $r, $ref, ?bool &$node_down = null ): ?array;

	/**
	 * The rrd samples of a guest for hour, day or month (rows with cpu, netin, netout, diskread, diskwrite and time), or null.
	 *
	 * @param Resource $r         Resource.
	 * @param string   $timeframe hour, day or month.
	 * @return array<int, mixed>|null
	 */
	public function samples( Resource $r, string $timeframe ): ?array;

	/**
	 * The disk of a guest: used and total in the same unit (bytes), or null when there is no reading.
	 *
	 * @param Resource $r       Resource.
	 * @param bool     $refresh True when a stale reading may be read again now (the live bars); false for stored values only.
	 * @return array{used: int|float, total: int|float}|null
	 */
	public function disk( Resource $r, bool $refresh = false ): ?array;

	/**
	 * The table that holds this guest's records (resource_id column): history rows of a resource that no adapter's table knows are deleted.
	 */
	public function record_table(): string;
}
