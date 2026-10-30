<?php
/**
 * What differs between kinds of build in Provision.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * A build profile answers the questions Provision and Operations::cleanup have about the kind of
 * machine being built. VpsProfile is the public-IP VM; a filter (`beltoft_services_build_profile`,
 * see Profiles) can supply another.
 */
interface BuildProfile {

	/**
	 * Order of the steps. `wipe` only runs while meta `wipe` is set.
	 *
	 * @return string[]
	 */
	public function steps(): array;

	/**
	 * Does the machine hold a public IP address from the pool (the build holds and records one); cleanup always gives back any address the resource holds?
	 */
	public function uses_public_ip(): bool;

	/**
	 * The address step: holds an address for the resource.
	 *
	 * @param int $resource_id Resource id.
	 * @return array<string, mixed>|null The pool row, or null when none is free.
	 * @throws \RuntimeException When the pool is busy.
	 */
	public function hold_address( int $resource_id ): ?array;

	/**
	 * The network parameters of the config write.
	 *
	 * @param array<string, mixed>      $cluster Cluster settings.
	 * @param array<string, mixed>|null $ip      The held pool row (null when the profile holds none).
	 * @param string                    $net0    The net0 string Provision built.
	 * @return array<string, string> Config parameters (`ipconfig0`, `net0`, ...).
	 */
	public function network_params( array $cluster, ?array $ip, string $net0 ): array;

	/**
	 * The cicustom value (`vendor=...`) of the config write, or null for none.
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 */
	public function cicustom( array $cluster ): ?string;

	/**
	 * Meta stored when the build is complete.
	 *
	 * @param array<string, mixed>      $record VM record.
	 * @param array<string, mixed>|null $ip     The held pool row (null when the profile holds none).
	 * @return array<string, mixed>
	 */
	public function post_build_meta( array $record, ?array $ip ): array;
}
