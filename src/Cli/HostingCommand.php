<?php
/**
 * WP-CLI: hosting nodes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\Hosting\Clients;
use BeltoftServices\Hosting\Nodes;
use BeltoftServices\Hosting\ReadOnlyClient;
use BeltoftServices\Proxmox\Pve\Ownership;

defined( 'ABSPATH' ) || exit;

/**
 * `wp beltoft-services hosting node add|list|status|check`. Secrets are never printed. `check` makes read-only
 * calls only (the container listing, the template, the pool and the agent health job), and a live node is
 * refused unless the live gate is open (it never is in this version: no live client exists yet).
 */
class HostingCommand {

	/**
	 * Hosting node commands.
	 *
	 * ## OPTIONS
	 * <action> : add, list, status or check.
	 * [<id>] : The node id (not for list).
	 * [--<field>=<value>] : For add: the fields of the node form (label, node_group, api_url, token_id, pve_node, agent_url, agent_key_id, pool, storage, bridge, gateway, lan_cidr, ip_first, ssh_port_min, ssh_port_max, vmid_min, vmid_max, template, net_mbit, db_host, client, enabled; the secrets token_secret, agent_key and agent_client_key come from --<name>-file=<path> or --<name>-stdin, with dashes: --token-secret-file).
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function node( array $args, array $assoc_args ): void {
		$action = (string) ( $args[0] ?? '' );
		$id     = (string) ( $args[1] ?? '' );

		switch ( $action ) {
			case 'list':
				$this->list_nodes();
				return;
			case 'add':
				$this->add( $id, $assoc_args );
				return;
			case 'status':
				$this->status( $id );
				return;
			case 'check':
				$this->check( $id );
				return;
		}

		\WP_CLI::error( 'Use: node add|list|status|check' );
	}

	/**
	 * Cache set-up of the hosted websites.
	 *
	 * ## OPTIONS
	 * <action> : setup.
	 * [--dry-run] : List the websites without asking the nodes to change anything.
	 * [--account=<id>] : One hosting account only (the resource id).
	 *
	 * ## EXAMPLES
	 *     wp beltoft-services hosting cache setup --dry-run
	 *     wp beltoft-services hosting cache setup --account=12
	 *
	 * Installs the page cache plugin on every website that has WordPress, renders its web server rule again, and on a plan with
	 * an object cache that is preselected for a shop, switches the object cache on. A website whose customer removed a plugin
	 * keeps it removed. Safe to repeat.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function cache( array $args, array $assoc_args ): void {
		if ( 'setup' !== (string) ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Use: cache setup [--dry-run] [--account=<id>]' );
		}

		$dry  = ! empty( $assoc_args['dry-run'] );
		$rows = \BeltoftServices\Hosting\CacheSetup::run( $dry, (int) ( $assoc_args['account'] ?? 0 ) );

		if ( array() === $rows ) {
			\WP_CLI::log( 'No websites.' );
			return;
		}

		$failed = 0;

		foreach ( $rows as $row ) {
			\WP_CLI::log( sprintf( 'account %d  %s  %s', $row['resource'], $row['slug'], $row['result'] ) );
			$failed += 0 === strpos( $row['result'], 'not done' ) ? 1 : 0;
		}

		if ( $failed > 0 ) {
			\WP_CLI::warning( sprintf( '%d website(s) were not set up. The set-up is safe to repeat.', $failed ) );
		}

		\WP_CLI::success( ( $dry ? 'Dry run: ' : '' ) . count( $rows ) . ' website(s).' );
	}

	/**
	 * The malware scan of one hosting account.
	 *
	 * ## OPTIONS
	 * <action> : scan.
	 * <resource> : The hosting resource id.
	 * [--now] : Ask the node to scan the websites now (about 20 seconds a website, only one scan runs on a node at a time) before the result is read.
	 *
	 * ## EXAMPLES
	 *     wp beltoft-services hosting scan 12
	 *     wp beltoft-services hosting scan 12 --now
	 *
	 * Reads the node's last scan of each website of the account and applies it the way the daily job does: the websites' flags are
	 * updated and, for files found since the customer was last told, the customer gets the email and the admin one mail. Prints one
	 * line per website. Nothing is moved, cleaned or removed on the node.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function scan( array $args, array $assoc_args ): void {
		$r = \BeltoftServices\Resource::find( (int) ( $args[0] ?? 0 ) );

		if ( ! $r || \BeltoftServices\Hosting\HostingDriver::ID !== (string) $r->get( 'driver' ) ) {
			\WP_CLI::error( 'Use: scan <resource> [--now] (the resource id of a hosting account).' );
		}

		$job = new \BeltoftServices\Hosting\MalwareScan( $r );
		$out = $job->check( ! empty( $assoc_args['now'] ) );

		for ( $i = 0; $out instanceof \BeltoftServices\Drivers\StepResult && $out->is_poll() && $i < 120; $i++ ) {
			sleep( 2 );
			$out = $job->check( ! empty( $assoc_args['now'] ) );
		}

		if ( $out instanceof \BeltoftServices\Drivers\StepResult ) {
			\WP_CLI::error( 'The node did not answer: ' . $out->error() );
		}

		foreach ( $out['lines'] as $line ) {
			\WP_CLI::log( $line );
		}

		\WP_CLI::success( sprintf( '%d website(s) read, %d with files found, %d new file(s)%s.', $out['sites'], $out['found'], $out['new'], $out['told'] ? ', the customer and the admin were told' : '' ) );
	}

	/**
	 * Prints one line per node.
	 */
	private function list_nodes(): void {
		$all = Nodes::all();

		if ( array() === $all ) {
			\WP_CLI::log( 'No hosting nodes.' );
			return;
		}

		foreach ( $all as $id => $node ) {
			\WP_CLI::log( sprintf( '%s  %s  group=%s  client=%s  enabled=%d  accounts=%d', $id, $node['label'], $node['node_group'], $node['client'], (int) $node['enabled'], Nodes::in_use( (string) $id ) ) );
		}
	}

	/**
	 * Adds or updates a node from the flags.
	 *
	 * @param string               $id    Node id.
	 * @param array<string, mixed> $flags Flags.
	 */
	private function add( string $id, array $flags ): void {
		if ( '' === $id ) {
			\WP_CLI::error( 'Give a node id.' );
		}

		// Secrets are not taken from the command line (they would sit in the shell history and the process list): a flag
		// token_secret, agent_key or agent_client_key is refused, and --<name>-file=<path> or --<name>-stdin reads one.
		foreach ( Nodes::SECRETS as $key ) {
			if ( isset( $flags[ $key ] ) ) {
				\WP_CLI::error( sprintf( 'Do not put %1$s on the command line. Use --%2$s-file=<path> (a file only you can read) or --%2$s-stdin.', $key, str_replace( '_', '-', $key ) ) );
			}

			$dash = str_replace( '_', '-', $key );

			if ( isset( $flags[ $dash . '-file' ] ) || isset( $flags[ $key . '_file' ] ) ) {
				$path = (string) ( $flags[ $dash . '-file' ] ?? $flags[ $key . '_file' ] );

				if ( '' === $path || '/' !== $path[0] || ! is_file( $path ) || ! is_readable( $path ) ) {
					\WP_CLI::error( sprintf( 'The file for %s must be an absolute path to a readable file.', $key ) );
				}

				$flags[ $key ] = trim( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local secret file.
			} elseif ( isset( $flags[ $dash . '-stdin' ] ) || isset( $flags[ $key . '_stdin' ] ) ) {
				$flags[ $key ] = trim( (string) $this->stdin() );
			}
		}

		$result = Nodes::save( $id, $flags );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( 'Node ' . $id . ' saved.' );
	}

	/**
	 * What is on standard input (a secret, one value). A seam for the tests.
	 */
	protected function stdin(): string {
		$data = stream_get_contents( STDIN );

		return false === $data ? '' : $data;
	}

	/**
	 * The node, or an error.
	 *
	 * @param string $id Node id.
	 * @return array<string, mixed>
	 */
	private function find( string $id ): array {
		$node = '' === $id ? null : Nodes::get( $id );

		if ( null === $node ) {
			\WP_CLI::error( 'Unknown hosting node.' );
		}

		return $node;
	}

	/**
	 * Prints the settings of a node, without any secret.
	 *
	 * @param string $id Node id.
	 */
	private function status( string $id ): void {
		$node = $this->find( $id );

		foreach ( $node as $key => $value ) {
			if ( in_array( $key, Nodes::SECRETS, true ) ) {
				\WP_CLI::log( $key . ': ' . ( '' === $value ? 'not set' : 'saved' ) );
				continue;
			}

			\WP_CLI::log( $key . ': ' . ( is_scalar( $value ) ? (string) $value : '' ) );
		}

		\WP_CLI::log( 'accounts: ' . Nodes::in_use( $id ) );
		\WP_CLI::log( 'live allowed: ' . ( Clients::live_allowed() ? 'yes' : 'no' ) );
	}

	/**
	 * Read-only checks of a node.
	 *
	 * @param string $id Node id.
	 */
	private function check( string $id ): void {
		$node   = $this->find( $id );
		$issues = array();

		try {
			$api   = new ReadOnlyClient( Clients::for_node( $node ) ); // Only GET calls can pass.
			$agent = Clients::agent_for( $node );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( 'No client for this node: ' . $e->getMessage() );
		}

		$list = Ownership::listing( $api );

		if ( is_wp_error( $list ) ) {
			\WP_CLI::error( 'The node did not list its containers: ' . $list->get_error_code() );
		}

		\WP_CLI::log( 'Containers listed: ' . count( $list ) );

		$template = Ownership::entry( $list, (int) $node['template'] );

		if ( null === $template || empty( $template['template'] ) ) {
			$issues[] = 'The template ' . (int) $node['template'] . ' is missing or is not a template.';
		}

		foreach ( $list as $entry ) {
			$vmid = (int) ( $entry['vmid'] ?? 0 );

			if ( $vmid >= (int) $node['vmid_min'] && $vmid <= (int) $node['vmid_max'] && empty( $entry['template'] ) ) {
				\WP_CLI::log( 'In the customer range: ' . $vmid );
			}
		}

		$job_id = 'check-' . wp_generate_password( 12, false );
		$job    = $agent->submit( 'agent.health', array(), $job_id );

		// A live agent answers queued or running at first: look again for a few seconds.
		for ( $i = 0; $i < 20 && ! is_wp_error( $job ) && in_array( $job['status'] ?? '', array( 'queued', 'running' ), true ); $i++ ) {
			usleep( 500000 );
			$job = $agent->job( $job_id );
		}

		if ( is_wp_error( $job ) || 'done' !== ( $job['status'] ?? '' ) ) {
			$issues[] = 'The agent health job did not finish: ' . ( is_wp_error( $job ) ? $job->get_error_code() : (string) ( $job['status'] ?? 'unknown' ) );
		}

		foreach ( $issues as $issue ) {
			\WP_CLI::warning( $issue );
		}

		if ( $issues ) {
			\WP_CLI::error( count( $issues ) . ' problem(s) found.' );
		}

		\WP_CLI::success( 'Node ' . $id . ' checks passed.' );
	}
}
