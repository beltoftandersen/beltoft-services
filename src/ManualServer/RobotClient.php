<?php
/**
 * The calls of the Robot webservice that the dedicated server driver uses, and nothing else.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

defined( 'ABSPATH' ) || exit;

/**
 * Documented at https://robot.hetzner.com/doc/webservice/en.html. Every method returns the documented object (the content of
 * `transaction`, `server` or `cancellation`) or a WP_Error whose code is the documented error code (INVALID_INPUT,
 * PRECONDITION_FAILED, RATE_LIMIT_EXCEEDED, NOT_FOUND, SERVER_NOT_FOUND, CONFLICT, INTERNAL_ERROR) and whose data holds `status`
 * (the HTTP status; 0 when no answer came). The error message never holds a request body (it may hold the root password).
 */
interface RobotClient {

	/**
	 * POST /order/server/transaction (Server ordering). Fields: product_id, location, dist, password or authorized_key[] (the documentation: either one), addon[], test.
	 *
	 * @param array<string, mixed> $fields product_id (string), location, dist, password (strings), authorized_key and addon (string[]), test (bool).
	 * @return array<string, mixed>|\WP_Error The `transaction` object.
	 */
	public function order( array $fields );

	/**
	 * POST /key (SSH keys): "Add a new SSH key"; fields name and data ("SSH key data in OpenSSH or SSH2 format"). 201 with the key
	 * (name, fingerprint, type, size, data); 409 KEY_ALREADY_EXISTS "The supplied key already exists"; 400 INVALID_INPUT.
	 *
	 * @param string $name Key name.
	 * @param string $data Key data.
	 * @return array<string, mixed>|\WP_Error The `key` object.
	 */
	public function add_key( string $name, string $data );

	/**
	 * GET /key (SSH keys): "Query all SSH keys", each with fingerprint and data. A 404 NOT_FOUND is documented for it.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error The `key` objects.
	 */
	public function keys();

	/**
	 * GET /order/server/transaction/{id}.
	 *
	 * @param string $id Transaction id.
	 * @return array<string, mixed>|\WP_Error The `transaction` object.
	 */
	public function transaction( string $id );

	/**
	 * GET /server/{server-number}.
	 *
	 * @param int $number Server number.
	 * @return array<string, mixed>|\WP_Error The `server` object.
	 */
	public function server( int $number );

	/**
	 * POST /server/{server-number}/cancellation with cancellation_date=now and reserve_location=false.
	 *
	 * @param int $number Server number.
	 * @return array<string, mixed>|\WP_Error The `cancellation` object.
	 */
	public function cancel( int $number );

	/**
	 * GET /reset/{server-number} (Reset): `reset` object with type[] (the reset options of the server) and operating_status.
	 * 404 SERVER_NOT_FOUND, 404 RESET_NOT_AVAILABLE.
	 *
	 * @param int $number Server number.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function reset_options( int $number );

	/**
	 * POST /reset/{server-number} with type (Reset; 50 requests an hour). 400 INVALID_INPUT, 404 SERVER_NOT_FOUND / RESET_NOT_AVAILABLE,
	 * 409 RESET_MANUAL_ACTIVE, 500 RESET_FAILED.
	 *
	 * @param int    $number Server number.
	 * @param string $type   A type the server lists (sw, hw, power, power_long).
	 * @return array<string, mixed>|\WP_Error The `reset` object.
	 */
	public function reset_server( int $number, string $type );

	/**
	 * GET /wol/{server-number} (Wake on LAN): 404 WOL_NOT_AVAILABLE when the server has none.
	 *
	 * @param int $number Server number.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function wol_available( int $number );

	/**
	 * POST /wol/{server-number}, empty body (Wake on LAN; 10 requests an hour). 404 WOL_NOT_AVAILABLE, 500 WOL_FAILED.
	 *
	 * @param int $number Server number.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function wol( int $number );

	/**
	 * GET /boot/{server-number}/rescue (Boot configuration): os[] or the active os, active, password, authorized_key[], host_key[].
	 *
	 * @param int $number Server number.
	 * @return array<string, mixed>|\WP_Error The `rescue` object.
	 */
	public function rescue_status( int $number );

	/**
	 * POST /boot/{server-number}/rescue with os and optional authorized_key[] (fingerprints). Without a key the answer holds a
	 * generated root `password` (Doc, example of the call). 400 INVALID_INPUT, 404 BOOT_NOT_AVAILABLE, 500 BOOT_ACTIVATION_FAILED.
	 *
	 * @param int      $number Server number.
	 * @param string   $os     An os the status lists (linux).
	 * @param string[] $keys   Key fingerprints, may be empty.
	 * @return array<string, mixed>|\WP_Error The `rescue` object.
	 */
	public function rescue_activate( int $number, string $os, array $keys );

	/**
	 * DELETE /boot/{server-number}/rescue. 500 BOOT_DEACTIVATION_FAILED.
	 *
	 * @param int $number Server number.
	 * @return array<string, mixed>|\WP_Error The `rescue` object.
	 */
	public function rescue_deactivate( int $number );

	/**
	 * GET /rdns?server_ip=<main ip> (Reverse DNS): the entries of one server, each ip and ptr. 404 NOT_FOUND means none: answered as an empty list.
	 *
	 * @param string $server_ip Main IPv4 address of the server.
	 * @return array<int, array<string, string>>|\WP_Error
	 */
	public function rdns_list( string $server_ip );

	/**
	 * POST /rdns/{ip} with ptr ("Update/create"; 201 created, 200 updated). 400 INVALID_INPUT, 404 IP_NOT_FOUND, 500 RDNS_CREATE_FAILED / RDNS_UPDATE_FAILED.
	 *
	 * @param string $ip  Address.
	 * @param string $ptr Host name.
	 * @return array<string, mixed>|\WP_Error The `rdns` object.
	 */
	public function rdns_set( string $ip, string $ptr );

	/**
	 * DELETE /rdns/{ip}. 404 IP_NOT_FOUND, 500 RDNS_DELETE_FAILED.
	 *
	 * @param string $ip Address.
	 * @return true|\WP_Error
	 */
	public function rdns_delete( string $ip );

	/**
	 * GET /firewall/{server-number} (Firewall): status, filter_ipv6, whitelist_hos, port, rules.input[] and rules.output[].
	 * 404 SERVER_NOT_FOUND / FIREWALL_PORT_NOT_FOUND / FIREWALL_NOT_AVAILABLE.
	 *
	 * @param int $number Server number.
	 * @return array<string, mixed>|\WP_Error The `firewall` object.
	 */
	public function firewall( int $number );

	/**
	 * POST /firewall/{server-number}: "Apply a new firewall configuration" (it replaces the rule set). Fields status, filter_ipv6, whitelist_hos
	 * and rules[input|output][i][field]. 409 FIREWALL_IN_PROCESS / FIREWALL_RULE_LIMIT_EXCEEDED / FIREWALL_CANNOT_BE_DISABLED.
	 *
	 * @param int                  $number Server number.
	 * @param array<string, mixed> $config status (string), filter_ipv6 (bool), whitelist_hos (bool), rules (input and output lists of rules).
	 * @return array<string, mixed>|\WP_Error The `firewall` object.
	 */
	public function firewall_set( int $number, array $config );

	/**
	 * GET /order/server/product (Server ordering): the products, each with id and dist[] ("Available distributions"). Read only.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error The `product` objects.
	 */
	public function products();
}
