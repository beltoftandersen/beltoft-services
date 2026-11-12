<?php
/**
 * An in-memory node agent for tests and staging.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * Models the node agent of the plan (section 4.6): a fixed list of operations, each with a schema, jobs with
 * ids, the same id twice returns the first job. Everything else is refused. The replies below are what this
 * fake gives; they are the plugin's own contract for the agent, to be matched by the agent's tests, not facts
 * about a running agent.
 *
 * Documented replies (the `result` of a done job):
 * - ct.prepare: prepared (true), hostname. Needs nothing. The container is marked prepared.
 * - ct.stats: memory_mb, disk_mb (0 here).
 * - site.create: slug, root. Fails with "container not prepared" before ct.prepare.
 * - site.delete, site.php, site.domains, site.cache_purge, site.cron, site.workers: ok (true), slug. Fail with
 *   "unknown site" for a site that was not created, except site.delete (deleting a missing site is fine).
 * - site.wp_install: installed (true), slug.
 * - site.cache_setup: page_plugin (installed, updated, present, removed_by_customer or not_shipped), object_cache (enabled,
 *   installed, present, removed_by_customer, no_valkey, needs_selective_flush or failed), nginx (rendered), slug; or
 *   skipped ("no WordPress"). Idempotent. The optional object_cache argument asks for the object cache to be switched on.
 *   Fails with "unknown site" for a site that was not created.
 * - site.cache_status: page_cache (on, off, inactive, missing or unknown), object_cache (on, off or unknown) and, with
 *   the page cache plugin active, lifetime (seconds). Read only. Fails with "unknown site".
 * - site.verify: status (200), host. A loopback request inside the container for the host name (an operation this
 *   plugin adds to the plan's list for the verify step). Fails with "unknown site" for a site that was not created.
 * - site.tool_link: url (a one-time address of the file manager or the database tool, fake) and expires_in.
 * - site.country_block: countries (ISO codes, sorted; empty = off), scope (site or login), ok, slug, active (true with a list unless
 *   set_geo_missing(true): the node has no country database yet, so nothing is blocked), changed. The setting is kept in state().
 * - site.wp_login_link, site.wp_password_link: url (a one-time address, fake) and, for a site installed through site.wp_install,
 *   user and email of the administrator (the preferred admin_user when it is the one installed, else the installed one). The
 *   address of the password link is a different fake address; no reset key is ever in a reply.
 * - site.domains: ok (true), slug. The names and the optional redirects (a list of from and to names) are kept in state(). A redirect
 *   is between names of the site, its temporary (preview) name included.
 * - site.wp_address: moves WordPress to a name of the site (`to`; the optional `from` names an address of the customer's own that may be replaced). changed (true), from, to, replaced (12), dump ("wpaddr"),
 *   keep_days (7); `foreign` (true, nothing changed) when WordPress is on an address that is none of the site's names (seed it with
 *   set_wp_address()); `unchanged` (true) when it is on the name already; `dry_run` (true) with the count and no change. The next
 *   result can be scripted with script_wp_address() (for example failed and restored). The one-time login links follow it.
 * - db.create: user, databases. The password is accepted, kept out of every reply and of the call log.
 * - ct.refresh: updated (names of the replaced files, none when current), version (12 hex characters). Fails "container not
 *   running" for an unknown container or one set with set_not_running().
 * - ct.release: released (true), vmid, sites (rows forgotten). The real agent refuses ("container still exists") while the
 *   container is on the node, so the plugin calls it after the destroy; idempotent.
 * - db.drop: dropped (list of names that existed), idempotent.
 * - db.size: sizes (map of database => bytes; 0 unless set_db_size()).
 * - backup.prepare: dumped (true), label ("nightly" when none), databases (dumped), failed (not dumped). The optional
 *   label (`[a-z0-9_-]{1,32}`) names the dump, a safety point keeps its own; a labelled run fails the job if any dump fails.
 * - backup.file_restore: restored (true), slug. The snapshot must be b<unix ctime> of a backup: a safety point name is
 *   refused (an Undo rolls the container back itself). Fails with "unknown site" for a site that does not exist.
 * - backup.db_restore: restored (true), database. The snapshot argument names the dump: a safety label, or b<unix time>
 *   of a backup.
 * - backup.restore_test: ok, status (200), title_match. The agent restores the backup b<ctime> of the container into the
 *   scratch container (no network), starts the web server and PHP, imports the dump, fetches the home page and deletes the
 *   scratch container (an operation this plugin adds to the plan's list for the weekly restore test).
 * - db.readonly: account, readonly (the account's database user loses or gets back its write grants; an operation this
 *   plugin adds to the plan's list for the 110 percent storage rule).
 * - agent.health: ok (true), loaders (map "<vmid>/<slug>" => { state, ts }; empty unless set_loaders()), backup_slots (map
 *   vmid => { at "HH:MM", offset_minutes, disk_mb }; empty unless set_backup_slots()).
 * - mail.smtp: configured (true), user. The sender is kept by container (host and user only, never the password).
 * - edge.apply: routes (count). Replaces the routes of the account; an empty list removes them.
 * - every other listed operation: ok (true) and no state change (modelled: accepted, nothing done).
 *
 * Seeding and scripting: fail_next(), pending(). Inspection: calls() (secrets masked), state().
 */
class FakeAgent implements Agent {

	public const OPTION = 'beltoft_services_fake_hosting_agent';

	/**
	 * The operations (plan 4.6, v1) and their arguments: name => [type, required]. Types: vmid, ident, host,
	 * ip, int, string, bool, secret, list (strings), names (database names), routes, redirects (from and to names).
	 */
	public const OPERATIONS = array(
		'ct.prepare'        => array(
			'vmid'      => array( 'vmid', true ),
			'account'   => array( 'ident', true ),
			'hostname'  => array( 'host', true ),
			'ip'        => array( 'ip', true ),
			'ssh_port'  => array( 'int', true ),
			'valkey_mb' => array( 'int', true ),
			'workers'   => array( 'int', true ),
			'ssh'       => array( 'bool', false ),
		),
		'ct.stats'          => array( 'vmid' => array( 'vmid', true ) ),
		'ct.release'        => array( 'vmid' => array( 'vmid', true ) ),
		'ct.refresh'        => array( 'vmid' => array( 'vmid', true ) ),
		'site.create'       => array(
			'vmid'    => array( 'vmid', true ),
			'slug'    => array( 'ident', true ),
			'php'     => array( 'string', true ),
			'workers' => array( 'int', true ),
			'host'    => array( 'host', true ),
		),
		'site.delete'       => array(
			'vmid' => array( 'vmid', true ),
			'slug' => array( 'ident', true ),
		),
		'site.php'          => array(
			'vmid'      => array( 'vmid', true ),
			'slug'      => array( 'ident', true ),
			'php'       => array( 'string', true ),
			'memory_mb' => array( 'int', false ),
			'upload_mb' => array( 'int', false ),
		),
		'site.domains'      => array(
			'vmid'      => array( 'vmid', true ),
			'slug'      => array( 'ident', true ),
			'domains'   => array( 'list', true ),
			'redirects' => array( 'redirects', false ),
		),
		'site.cache_purge'  => array(
			'vmid' => array( 'vmid', true ),
			'slug' => array( 'ident', true ),
		),
		'site.cache_setup'  => array(
			'vmid'         => array( 'vmid', true ),
			'slug'         => array( 'ident', true ),
			'object_cache' => array( 'bool', false ),
		),
		'site.cache_status' => array(
			'vmid' => array( 'vmid', true ),
			'slug' => array( 'ident', true ),
		),
		'site.wp_address'   => array(
			'vmid'    => array( 'vmid', true ),
			'slug'    => array( 'ident', true ),
			'to'      => array( 'host', true ),
			'from'    => array( 'host', false ),
			'dry_run' => array( 'bool', false ),
		),
		'site.wp_install'   => array(
			'vmid'        => array( 'vmid', true ),
			'slug'        => array( 'ident', true ),
			'title'       => array( 'string', true ),
			'admin_user'  => array( 'ident', true ),
			'admin_email' => array( 'string', true ),
			'locale'      => array( 'string', false ),
			'woocommerce' => array( 'bool', false ),
		),
		'site.verify'       => array(
			'vmid' => array( 'vmid', true ),
			'slug' => array( 'ident', true ),
			'host' => array( 'host', true ),
		),
		'site.wp_login_link' => array(
			'vmid'       => array( 'vmid', true ),
			'slug'       => array( 'ident', true ),
			'admin_user' => array( 'ident', false ),
		),
		'site.wp_password_link' => array(
			'vmid'       => array( 'vmid', true ),
			'slug'       => array( 'ident', true ),
			'admin_user' => array( 'ident', false ),
		),
		'site.tool_link' => array(
			'vmid' => array( 'vmid', true ),
			'slug' => array( 'ident', true ),
			'tool' => array( 'tool', true ),
			'host' => array( 'host', false ),
		),
		'site.country_block' => array(
			'vmid'      => array( 'vmid', true ),
			'slug'      => array( 'ident', true ),
			'countries' => array( 'countries', true ),
			'scope'     => array( 'country_scope', true ),
		),
		'site.staging_create' => array(
			'vmid' => array( 'vmid', true ),
			'slug' => array( 'ident', true ),
		),
		'site.staging_push' => array(
			'vmid' => array( 'vmid', true ),
			'slug' => array( 'ident', true ),
		),
		'site.cron'         => array(
			'vmid'    => array( 'vmid', true ),
			'slug'    => array( 'ident', true ),
			'enabled' => array( 'bool', true ),
		),
		'site.workers'      => array(
			'vmid'    => array( 'vmid', true ),
			'slug'    => array( 'ident', true ),
			'workers' => array( 'int', true ),
		),
		'db.create'         => array(
			'account'   => array( 'ident', true ),
			'vmid'      => array( 'vmid', true ),
			'ip'        => array( 'ip', true ),
			'databases' => array( 'names', true ),
			'password'  => array( 'secret', true ),
		),
		'db.drop'           => array(
			'account'   => array( 'ident', true ),
			'databases' => array( 'names', true ),
		),
		'db.password'       => array(
			'account'  => array( 'ident', true ),
			'password' => array( 'secret', true ),
		),
		'db.dump'           => array(
			'account'  => array( 'ident', true ),
			'database' => array( 'ident', true ),
		),
		'db.size'           => array( 'account' => array( 'ident', true ) ),
		'db.readonly'       => array(
			'account' => array( 'ident', true ),
			'enabled' => array( 'bool', true ),
		),
		'ssh.keys'          => array(
			'vmid' => array( 'vmid', true ),
			'keys' => array( 'list', true ),
		),
		'ssh.password'      => array(
			'vmid'     => array( 'vmid', true ),
			'password' => array( 'secret', true ),
		),
		'edge.apply'        => array(
			'account' => array( 'ident', true ),
			'routes'  => array( 'routes', true ),
		),
		'edge.cert_push'    => array( 'host' => array( 'host', true ) ),
		'edge.storage_backup' => array(),
		'backup.prepare'    => array(
			'vmid'  => array( 'vmid', true ),
			'label' => array( 'ident', false ),
		),
		'backup.file_restore' => array(
			'vmid'     => array( 'vmid', true ),
			'slug'     => array( 'ident', true ),
			'snapshot' => array( 'ident', true ),
		),
		'backup.db_restore' => array(
			'account'  => array( 'ident', true ),
			'database' => array( 'ident', true ),
			'snapshot' => array( 'ident', true ),
		),
		'backup.restore_test' => array(
			'vmid'         => array( 'vmid', true ),
			'snapshot'     => array( 'ident', true ),
			'scratch_vmid' => array( 'vmid', true ),
		),
		'backup.export'     => array(
			'vmid' => array( 'vmid', true ),
			'slug' => array( 'ident', true ),
		),
		'mail.smtp'         => array(
			'vmid'     => array( 'vmid', true ),
			'host'     => array( 'host', true ),
			'user'     => array( 'string', true ),
			'password' => array( 'secret', true ),
		),
		'agent.health'      => array(),
		'agent.update'      => array(
			'version' => array( 'string', true ),
			'sha256'  => array( 'string', true ),
		),
	);

	/**
	 * Persist in the option (staging) instead of in memory.
	 *
	 * @var bool
	 */
	private $persist;

	/**
	 * State: jobs, cts, sites, dbs, edge.
	 *
	 * @var array<string, mixed>
	 */
	private $state;

	/**
	 * Recorded calls, secrets masked.
	 *
	 * @var array<int, array{op: string, job_id: string, args: array<string, mixed>}>
	 */
	private $calls = array();

	/**
	 * Scripted failures: op => list of error texts, consumed in order.
	 *
	 * @var array<string, string[]>
	 */
	private $failures = array();

	/**
	 * Scripted waits: op => list of read counts, consumed in order.
	 *
	 * @var array<string, int[]>
	 */
	private $waits = array();

	/**
	 * @param bool $persist Keep the state in the option beltoft_services_fake_hosting_agent (autoload off).
	 */
	public function __construct( bool $persist = false ) {
		$this->persist = $persist;
		$this->state   = self::blank();
		$this->load();
	}

	/**
	 * Empty state.
	 *
	 * @return array<string, mixed>
	 */
	private static function blank(): array {
		return array(
			'jobs'  => array(),
			'cts'   => array(),
			'sites' => array(),
			'dbs'   => array(),
			'edge'  => array(),
			'smtp'  => array(),
			'db_sizes' => array(),
			'dumps'    => array(),
			'dump_fails' => array(),
			'restore_tests' => array(),
			'restore_test_result' => array(),
			'health_extra' => array(),
			'backup_slots' => array(),
			'restores' => array(),
			'readonly' => array(),
			'loaders'  => array(),
			'unsupported' => array(),
			'wp_scripts'  => array(),
		);
	}

	// Scripting and inspection.

	/**
	 * Makes the next job of an operation fail with a message (a failed job, not a refusal). Consumed once.
	 *
	 * @param string $op    Operation.
	 * @param string $error Error text.
	 */
	public function fail_next( string $op, string $error = 'scripted failure' ): void {
		$this->failures[ $op ][] = $error;
	}

	/**
	 * Makes the next job of an operation show as not done for this many reads (the submit counts as the first
	 * read) before it is done.
	 *
	 * @param string $op    Operation.
	 * @param int    $reads Reads.
	 */
	public function pending( string $op, int $reads = 1 ): void {
		$this->waits[ $op ][] = max( 1, $reads );
	}

	/**
	 * Sets the size of a database in bytes, as db.size reports it.
	 *
	 * @param string $database Database name.
	 * @param int    $bytes    Size.
	 */
	public function set_db_size( string $database, int $bytes ): void {
		$this->load();
		$this->state['db_sizes'][ $database ] = $bytes;
		$this->save();
	}

	/**
	 * Makes the dump of these databases fail (a nightly run reports them in `failed`, a labelled run fails).
	 *
	 * @param string[] $databases Database names.
	 */
	public function set_dump_fails( array $databases ): void {
		$this->load();
		$this->state['dump_fails'] = $databases;
		$this->save();
	}

	/**
	 * Sets what the next restore tests answer (merged over ok, status 200, title_match true).
	 *
	 * @param array<string, mixed> $result Result.
	 */
	public function set_restore_test_result( array $result ): void {
		$this->load();
		$this->state['restore_test_result'] = $result;
		$this->save();
	}

	/**
	 * Sets more of what agent.health reports (alerts, isolation).
	 *
	 * @param array<string, mixed> $extra Members.
	 */
	public function set_health_extra( array $extra ): void {
		$this->load();
		$this->state['health_extra'] = $extra;
		$this->save();
	}

	/**
	 * Makes ct.refresh fail with "container not running" for a container.
	 *
	 * @param int  $vmid Container id.
	 * @param bool $off  Not running.
	 */
	public function set_not_running( int $vmid, bool $off = true ): void {
		$this->load();
		$this->state['not_running'][ $vmid ] = $off;
		$this->save();
	}

	/**
	 * Sets the nightly slots agent.health reports: vmid => { at "HH:MM", offset_minutes, disk_mb }.
	 *
	 * @param array<int, array<string, mixed>> $slots Slots.
	 */
	public function set_backup_slots( array $slots ): void {
		$this->load();
		$this->state['backup_slots'] = $slots;
		$this->save();
	}

	/**
	 * Sets the loader states agent.health reports: "<vmid>/<slug>" => array( state, ts ).
	 *
	 * @param array<string, array<string, mixed>> $loaders Loaders.
	 */
	public function set_loaders( array $loaders ): void {
		$this->load();
		$this->state['loaders'] = $loaders;
		$this->save();
	}

	/**
	 * Lets the node lack the country database from now on: site.country_block saves the list and answers active false.
	 *
	 * @param bool $missing Missing.
	 */
	public function set_geo_missing( bool $missing ): void {
		$this->load();
		$this->state['geo_missing'] = $missing;
		$this->save();
	}

	/**
	 * Where WordPress of a site is now (what `home` holds), as site.wp_address finds it. A site starts on its temporary name.
	 * Set it to a name that is none of the site's to see the `foreign` answer.
	 *
	 * @param int    $vmid Container id.
	 * @param string $slug Site.
	 * @param string $host Host name.
	 */
	public function set_wp_address( int $vmid, string $slug, string $host ): void {
		$this->load();
		$this->state['sites'][ $vmid ][ $slug ]['wp_address'] = $host;
		$this->save();
	}

	/**
	 * The address every site.tool_link answers with from now on (to test what the plugin refuses), or '' for the normal one.
	 *
	 * @param string $url Address.
	 */
	public function script_tool_url( string $url ): void {
		$this->load();

		if ( '' === $url ) {
			unset( $this->state['tool_url'] );
		} else {
			$this->state['tool_url'] = $url;
		}

		$this->save();
	}

	/**
	 * The result the next site.wp_address answers with (merged over changed false), nothing else is done. Consumed once.
	 *
	 * @param array<string, mixed> $result For example array( 'failed' => true, 'restored' => true ).
	 */
	public function script_wp_address( array $result ): void {
		$this->load();
		$this->state['wp_scripts'][] = $result;
		$this->save();
	}

	/**
	 * Makes operations unknown to the agent, as an older agent has them: the answer is 400 unknown_op.
	 *
	 * @param string[] $ops Operations.
	 */
	public function set_unsupported( array $ops ): void {
		$this->load();
		$this->state['unsupported'] = $ops;
		$this->save();
	}

	/**
	 * Every submit made so far, with secrets masked.
	 *
	 * @return array<int, array{op: string, job_id: string, args: array<string, mixed>}>
	 */
	public function calls(): array {
		return $this->calls;
	}

	/**
	 * The submits of one operation.
	 *
	 * @param string $op Operation.
	 * @return array<int, array{op: string, job_id: string, args: array<string, mixed>}>
	 */
	public function calls_of( string $op ): array {
		return array_values( array_filter( $this->calls, static fn( $c ) => $c['op'] === $op ) );
	}

	/**
	 * A copy of the state (secrets are never in it).
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$this->load();

		return $this->state;
	}

	// Agent.

	/**
	 * {@inheritDoc}
	 *
	 * The same job id twice returns the first job without running anything.
	 *
	 * @param string               $op     Operation.
	 * @param array<string, mixed> $args   Arguments.
	 * @param string               $job_id Job id.
	 */
	public function submit( string $op, array $args, string $job_id ) {
		$this->load();
		$this->calls[] = array(
			'op'     => $op,
			'job_id' => $job_id,
			'args'   => self::mask( $args ),
		);

		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{1,120}$/D', $job_id ) ) {
			return $this->error( 400, 'invalid', 'The job id is not valid.' );
		}

		if ( isset( $this->state['jobs'][ $job_id ] ) ) {
			return $this->view( $job_id );
		}

		if ( ! isset( self::OPERATIONS[ $op ] ) || in_array( $op, (array) $this->state['unsupported'], true ) ) {
			return $this->error( 400, 'unknown_op', 'The operation is not in the list.' );
		}

		$checked = $this->check( $op, $args );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$job = array(
			'id'     => $job_id,
			'op'     => $op,
			'status' => 'queued',
			'reads'  => 0,
		);

		if ( ! empty( $this->failures[ $op ] ) ) {
			$job['status'] = 'failed';
			$job['error']  = array_shift( $this->failures[ $op ] );
		} else {
			$result = $this->apply( $op, $checked );

			if ( is_string( $result ) ) {
				$job['status'] = 'failed';
				$job['error']  = $result;
			} else {
				$job['status'] = 'done';
				$job['result'] = $result;
			}

			if ( ! empty( $this->waits[ $op ] ) && 'done' === $job['status'] ) {
				$job['hold'] = 1 + array_shift( $this->waits[ $op ] );
			}
		}

		$this->state['jobs'][ $job_id ] = $job;
		$this->save();

		return $this->view( $job_id, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $job_id Job id.
	 */
	public function job( string $job_id ) {
		$this->load();

		if ( ! isset( $this->state['jobs'][ $job_id ] ) ) {
			return $this->error( 404, 'http_404', 'No such job.' );
		}

		$view = $this->view( $job_id );
		$this->save();

		return $view;
	}

	// Internals.

	/**
	 * Does the container's account have the database of a site (<account>_<slug with underscores>)?
	 *
	 * @param int    $vmid Container id.
	 * @param string $slug Site.
	 */
	private function has_database( int $vmid, string $slug ): bool {
		foreach ( (array) ( $this->state['dbs'] ?? array() ) as $account => $db ) {
			if ( (int) ( $db['vmid'] ?? 0 ) === $vmid && in_array( substr( $account . '_' . str_replace( '-', '_', $slug ), 0, 64 ), (array) $db['databases'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A job as the API shows it. A held job shows queued, then running, until its reads are used up.
	 *
	 * @param string $job_id Job id.
	 * @param bool   $first  The answer to the submit (counts as the first read).
	 * @return array<string, mixed>
	 */
	private function view( string $job_id, bool $first = false ): array {
		$job = &$this->state['jobs'][ $job_id ];

		if ( isset( $job['hold'] ) ) {
			++$job['reads'];

			if ( $job['reads'] < (int) $job['hold'] ) {
				return array(
					'id'     => $job_id,
					'status' => $first ? 'queued' : 'running',
				);
			}

			unset( $job['hold'] );
		}

		$out = array(
			'id'     => $job_id,
			'status' => $job['status'],
		);

		if ( isset( $job['result'] ) ) {
			$out['result'] = $job['result'];
		}

		if ( isset( $job['error'] ) ) {
			$out['error'] = $job['error'];
		}

		return $out;
	}

	/**
	 * Validates arguments against the operation's schema: unknown names, missing ones and bad values are HTTP
	 * 400 (invalid). Identifiers are validated, never taken as paths.
	 *
	 * @param string               $op   Operation.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check( string $op, array $args ) {
		$schema = self::OPERATIONS[ $op ];
		$out    = array();

		foreach ( $args as $name => $value ) {
			if ( ! isset( $schema[ $name ] ) || ! self::valid( $schema[ $name ][0], $value ) ) {
				return $this->error( 400, 'invalid', 'The argument "' . $name . '" is not valid for ' . $op . '.' );
			}

			$out[ $name ] = $value;
		}

		foreach ( $schema as $name => $spec ) {
			if ( $spec[1] && ! array_key_exists( $name, $out ) ) {
				return $this->error( 400, 'invalid', 'The argument "' . $name . '" is missing for ' . $op . '.' );
			}
		}

		// The agent's own cross-check: only between the site's own names.
		if ( 'site.domains' === $op && isset( $out['redirects'] ) ) {
			$names   = (array) $out['domains'];
			$sources = array_merge( $names, array( (string) ( $this->state['sites'][ (int) ( $out['vmid'] ?? 0 ) ][ (string) ( $out['slug'] ?? '' ) ]['host'] ?? '' ) ) );

			foreach ( $out['redirects'] as $redirect ) {
				if ( ! in_array( $redirect['from'], $sources, true ) || ! in_array( $redirect['to'], $names, true ) ) {
					return $this->error( 400, 'invalid', 'The redirects must be between names of the site.' );
				}
			}
		}

		return $out;
	}

	/**
	 * Is a value valid for a type?
	 *
	 * @param string $type  Type.
	 * @param mixed  $value Value.
	 */
	private static function valid( string $type, $value ): bool {
		switch ( $type ) {
			case 'vmid':
				return is_int( $value ) && $value >= 100;
			case 'int':
				return is_int( $value ) && $value >= 0;
			case 'bool':
				return is_bool( $value );
			case 'ident':
				return is_string( $value ) && 1 === preg_match( '/^[a-z0-9_-]{1,32}$/D', $value );
			case 'tool':
				return in_array( $value, array( 'files', 'database' ), true );
			case 'country_scope':
				return in_array( $value, array( 'site', 'login' ), true );
			case 'countries':
				return is_array( $value ) && count( $value ) <= 250 && count( array_unique( $value ) ) === count( $value ) && array() === array_filter( $value, static fn( $v ) => ! is_string( $v ) || 1 !== preg_match( '/^[A-Z]{2}$/D', $v ) );
			case 'host':
				return is_string( $value ) && strlen( $value ) <= 253 && 1 === preg_match( '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/D', $value );
			case 'ip':
				return is_string( $value ) && false !== filter_var( $value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
			case 'string':
				return is_string( $value ) && '' !== $value && strlen( $value ) <= 200;
			case 'secret':
				return is_string( $value ) && strlen( $value ) >= 16 && strlen( $value ) <= 200;
			case 'redirects':
				if ( ! is_array( $value ) || array() === $value || count( $value ) > 50 ) {
					return false;
				}

				$from = array();
				$to   = array();

				foreach ( $value as $item ) {
					if ( ! is_array( $item ) || ! isset( $item['from'], $item['to'] ) || 2 !== count( $item ) || ! self::valid( 'host', $item['from'] ) || ! self::valid( 'host', $item['to'] ) || $item['from'] === $item['to'] ) {
						return false;
					}

					$from[] = $item['from'];
					$to[]   = $item['to'];
				}

				return count( array_unique( $from ) ) === count( $from ) && array() === array_intersect( $from, $to );
			case 'list':
				return is_array( $value ) && count( $value ) <= 100 && array() === array_filter( $value, static fn( $v ) => ! is_string( $v ) || '' === $v || strlen( $v ) > 2000 );
			case 'names':
				return is_array( $value ) && array() !== $value && count( $value ) <= 50 && array() === array_filter( $value, static fn( $v ) => ! is_string( $v ) || 1 !== preg_match( '/^[a-z0-9_]{1,64}$/D', $v ) );
			case 'routes':
				if ( ! is_array( $value ) || count( $value ) > 200 ) {
					return false;
				}

				foreach ( $value as $route ) {
					if ( ! is_array( $route ) || ! isset( $route['host'], $route['ip'], $route['suspended'] ) || ! self::valid( 'host', $route['host'] ) || ! self::valid( 'ip', $route['ip'] ) || ! is_bool( $route['suspended'] ) ) {
						return false;
					}
				}

				return true;
		}

		return false;
	}

	/**
	 * Does the operation and returns its result, or a failure text.
	 *
	 * @param string               $op   Operation.
	 * @param array<string, mixed> $args Validated arguments.
	 * @return array<string, mixed>|string
	 */
	private function apply( string $op, array $args ) {
		$vmid = (int) ( $args['vmid'] ?? 0 );

		switch ( $op ) {
			case 'ct.prepare':
				$this->state['cts'][ $vmid ] = array(
					'prepared'  => true,
					'hostname'  => $args['hostname'],
					'account'   => $args['account'],
					'ip'        => $args['ip'],
					'ssh_port'  => $args['ssh_port'],
					'valkey_mb' => $args['valkey_mb'],
					'workers'   => $args['workers'],
					'ssh'       => $args['ssh'] ?? true,
				);

				return array(
					'prepared' => true,
					'hostname' => $args['hostname'],
				);

			case 'ct.refresh':
				if ( empty( $this->state['cts'][ $vmid ] ) || ! empty( $this->state['not_running'][ $vmid ] ) ) {
					return 'container not running';
				}

				// The files the agent keeps current: the first refresh of a container replaces them, later ones find them current.
				$first = empty( $this->state['cts'][ $vmid ]['refreshed'] );
				$this->state['cts'][ $vmid ]['refreshed'] = true;

				return array(
					'updated' => $first ? array( 'hosting-site', 'hosting-sendmail', '00-hosting.php' ) : array(),
					'version' => 'a1b2c3d4e5f6',
				);

			case 'ct.release':
				// After the container was destroyed: the agent forgets its container, account and site rows (idempotent).
				$sites = count( (array) ( $this->state['sites'][ $vmid ] ?? array() ) );
				unset( $this->state['cts'][ $vmid ], $this->state['sites'][ $vmid ] );
				$this->state['released'][] = $vmid;

				return array(
					'released' => true,
					'vmid'     => $vmid,
					'sites'    => $sites,
				);

			case 'ct.stats':
				return array(
					'memory_mb' => 0,
					'disk_mb'   => 0,
				);

			case 'site.create':
				if ( empty( $this->state['cts'][ $vmid ]['prepared'] ) ) {
					return 'container not prepared';
				}

				$this->state['sites'][ $vmid ][ $args['slug'] ] = array(
					'php'     => $args['php'],
					'workers' => $args['workers'],
					'host'    => $args['host'],
				);

				return array(
					'slug' => $args['slug'],
					'root' => '/home/web/sites/' . $args['slug'],
				);

			case 'site.delete':
				unset( $this->state['sites'][ $vmid ][ $args['slug'] ] );

				return array(
					'ok'   => true,
					'slug' => $args['slug'],
				);

			case 'site.wp_install':
			case 'site.verify':
			case 'site.wp_login_link':
			case 'site.wp_password_link':
			case 'site.tool_link':
			case 'site.country_block':
			case 'site.php':
			case 'site.domains':
			case 'site.cache_purge':
			case 'site.cache_setup':
			case 'site.cache_status':
			case 'site.cron':
			case 'site.workers':
			case 'site.wp_address':
				if ( ! isset( $this->state['sites'][ $vmid ][ $args['slug'] ] ) ) {
					return 'unknown site';
				}

				if ( 'site.cache_setup' === $op ) {
					$cache = array(
						'page_plugin'  => ! empty( $this->state['sites'][ $vmid ][ $args['slug'] ]['cache']['page'] ) ? 'present' : 'installed',
						'object_cache' => ! empty( $args['object_cache'] ) ? 'enabled' : 'installed',
						'nginx'        => 'rendered',
						'slug'         => $args['slug'],
					);

					$this->state['sites'][ $vmid ][ $args['slug'] ]['cache'] = array(
						'page'   => true,
						'object' => ! empty( $args['object_cache'] ) || ! empty( $this->state['sites'][ $vmid ][ $args['slug'] ]['cache']['object'] ),
					);

					return $cache;
				}

				if ( 'site.cache_status' === $op ) {
					$cache = (array) ( $this->state['sites'][ $vmid ][ $args['slug'] ]['cache'] ?? array() );

					return array(
						'page_cache'   => ! empty( $cache['page'] ) ? 'on' : 'missing',
						'object_cache' => ! empty( $cache['object'] ) ? 'on' : 'off',
						'lifetime'     => 600,
					);
				}

				if ( 'site.wp_install' === $op && ! $this->has_database( $vmid, (string) $args['slug'] ) ) {
					return 'no database for this site'; // The agent refuses an install before db.create made the site's database.
				}

				if ( 'site.wp_install' === $op ) {
					$this->state['sites'][ $vmid ][ $args['slug'] ]['admin'] = array(
						'user'  => $args['admin_user'],
						'email' => $args['admin_email'],
					);
					$this->state['sites'][ $vmid ][ $args['slug'] ]['cache'] = array(
						'page'   => true,
						'object' => false,
					);

					return array(
						'installed' => true,
						'slug'      => $args['slug'],
					);
				}

				if ( 'site.verify' === $op ) {
					return array(
						'status' => 200,
						'host'   => $args['host'],
					);
				}

				if ( 'site.tool_link' === $op ) {
					$site = $this->state['sites'][ $vmid ][ $args['slug'] ];

					return array(
						'url'        => $this->state['tool_url'] ?? 'https://' . ( $args['host'] ?? $site['wp_address'] ?? $site['host'] ) . '/.hosting/' . $args['tool'] . '/?t=fake-tool-token',
						'expires_in' => 60,
					);
				}

				if ( 'site.country_block' === $op ) {
					$countries = array_values( $args['countries'] );
					sort( $countries );
					$this->state['sites'][ $vmid ][ $args['slug'] ]['country_block'] = array() === $countries ? null : array( 'countries' => $countries, 'scope' => $args['scope'] );

					return array(
						'ok'        => true,
						'slug'      => $args['slug'],
						'countries' => $countries,
						'scope'     => $args['scope'],
						'active'    => array() !== $countries && empty( $this->state['geo_missing'] ),
						'changed'   => true,
					);
				}

				if ( 'site.wp_login_link' === $op || 'site.wp_password_link' === $op ) {
					$site = $this->state['sites'][ $vmid ][ $args['slug'] ];
					$out  = array( 'url' => 'https://' . ( $site['wp_address'] ?? $site['host'] ) . ( 'site.wp_login_link' === $op ? '/?fake-login=1' : '/?fake-password=1' ) );

					// What the template plugin reports: the administrator it used (the installed one; a site that was not installed here has none to name).
					if ( ! empty( $site['admin'] ) ) {
						$out['user']  = $site['admin']['user'];
						$out['email'] = $site['admin']['email'];
					}

					return $out;
				}

				if ( 'site.wp_address' === $op ) {
					return $this->wp_address( $vmid, (string) $args['slug'], (string) $args['to'], ! empty( $args['dry_run'] ), (string) ( $args['from'] ?? '' ) );
				}

				if ( 'site.domains' === $op ) {
					$this->state['sites'][ $vmid ][ $args['slug'] ]['domains']   = $args['domains'];
					$this->state['sites'][ $vmid ][ $args['slug'] ]['redirects'] = $args['redirects'] ?? array();
				}

				if ( 'site.php' === $op ) {
					$this->state['sites'][ $vmid ][ $args['slug'] ]['php'] = $args['php'];

					foreach ( array( 'memory_mb', 'upload_mb' ) as $limit ) {
						if ( isset( $args[ $limit ] ) ) {
							$this->state['sites'][ $vmid ][ $args['slug'] ][ $limit ] = $args[ $limit ];
						}
					}
				}

				if ( 'site.workers' === $op ) {
					$this->state['sites'][ $vmid ][ $args['slug'] ]['workers'] = $args['workers'];
				}

				return array(
					'ok'   => true,
					'slug' => $args['slug'],
				);

			case 'db.create':
				// Like the agent: the account's user and the listed databases are added, the ones it already has stay.
				$all = array_values( array_unique( array_merge( (array) ( $this->state['dbs'][ $args['account'] ]['databases'] ?? array() ), array_values( $args['databases'] ) ) ) );

				$this->state['dbs'][ $args['account'] ] = array(
					'databases' => $all,
					'host_ip'   => $args['ip'],
					'vmid'      => $vmid,
				);

				return array(
					'user'      => $args['account'],
					'databases' => array_values( $args['databases'] ),
				);

			case 'db.drop':
				$had = (array) ( $this->state['dbs'][ $args['account'] ]['databases'] ?? array() );
				$now = array_values( array_diff( $had, $args['databases'] ) );

				if ( array() === $now ) {
					unset( $this->state['dbs'][ $args['account'] ] );
				} else {
					$this->state['dbs'][ $args['account'] ]['databases'] = $now;
				}

				return array( 'dropped' => array_values( array_intersect( $had, $args['databases'] ) ) );

			case 'db.size':
				$sizes = array();

				foreach ( (array) ( $this->state['dbs'][ $args['account'] ]['databases'] ?? array() ) as $name ) {
					$sizes[ $name ] = (int) ( $this->state['db_sizes'][ $name ] ?? 0 );
				}

				return array( 'sizes' => $sizes );

			case 'backup.prepare':
				$label = $args['label'] ?? 'nightly';
				$names = array_values( array_filter( array_map( static fn( $c ) => (array) ( $c['databases'] ?? array() ), array_values( $this->state['dbs'] ) ) ) );
				$dbs   = array();

				foreach ( $names as $list ) {
					$dbs = array_merge( $dbs, array_values( $list ) );
				}

				$failed = array_values( array_intersect( $dbs, (array) ( $this->state['dump_fails'] ?? array() ) ) );

				// A labelled run fails the job when any dump fails (a safety point needs all of them).
				if ( 'nightly' !== $label && array() !== $failed ) {
					return 'dump failed: ' . implode( ', ', $failed );
				}

				$this->state['dumps'][ $vmid ][] = $label;

				return array( 'dumped' => true, 'label' => $label, 'databases' => array_values( array_diff( $dbs, $failed ) ), 'failed' => $failed );

			case 'backup.file_restore':
				if ( 1 !== preg_match( '/^b\d{1,12}$/D', (string) $args['snapshot'] ) ) {
					return 'the snapshot must be a backup (b<ctime>), not a safety point';
				}

				if ( ! isset( $this->state['sites'][ $vmid ][ $args['slug'] ] ) ) {
					return 'unknown site';
				}

				$this->state['restores'][] = array( 'op' => $op, 'vmid' => $vmid, 'slug' => $args['slug'], 'snapshot' => $args['snapshot'] );

				return array( 'restored' => true, 'slug' => $args['slug'] );

			case 'backup.db_restore':
				$this->state['restores'][] = array( 'op' => $op, 'account' => $args['account'], 'database' => $args['database'], 'snapshot' => $args['snapshot'] );

				return array( 'restored' => true, 'database' => $args['database'] );

			case 'backup.restore_test':
				$this->state['restore_tests'][] = array( 'vmid' => $vmid, 'snapshot' => $args['snapshot'], 'scratch_vmid' => $args['scratch_vmid'] );

				return array_merge( array( 'ok' => true, 'status' => 200, 'title_match' => true ), (array) ( $this->state['restore_test_result'] ?? array() ) );

			case 'db.readonly':
				$this->state['readonly'][ $args['account'] ] = $args['enabled'];

				return array( 'account' => $args['account'], 'readonly' => $args['enabled'] );

			case 'agent.health':
				return array_merge( array( 'ok' => true, 'loaders' => (object) (array) ( $this->state['loaders'] ?? array() ), 'backup_slots' => (object) (array) ( $this->state['backup_slots'] ?? array() ) ), (array) ( $this->state['health_extra'] ?? array() ) );

			case 'mail.smtp':
				$this->state['smtp'][ $vmid ] = array(
					'host' => $args['host'],
					'user' => $args['user'],
				);

				return array( 'configured' => true, 'user' => $args['user'] );

			case 'edge.apply':
				if ( array() === $args['routes'] ) {
					unset( $this->state['edge'][ $args['account'] ] );
				} else {
					$this->state['edge'][ $args['account'] ] = array_values( $args['routes'] );
				}

				return array( 'routes' => count( $args['routes'] ) );
		}

		return array( 'ok' => true );
	}

	/**
	 * site.wp_address.
	 *
	 * @param int    $vmid   Container id.
	 * @param string $slug   Site.
	 * @param string $to     The name WordPress moves to.
	 * @param bool   $dry    Count only.
	 * @param string $args_from The customer's own address that may be replaced ('' for none).
	 * @return array<string, mixed>|string
	 */
	private function wp_address( int $vmid, string $slug, string $to, bool $dry, string $args_from = '' ) {
		$site  = $this->state['sites'][ $vmid ][ $slug ];
		$names = array_merge( array( (string) $site['host'] ), (array) ( $site['domains'] ?? array() ) );
		$from  = (string) ( $site['wp_address'] ?? $site['host'] );

		if ( ! in_array( $to, $names, true ) ) {
			return 'the address is not one of the names of the site';
		}

		if ( array() !== $this->state['wp_scripts'] ) {
			return array_merge( array( 'changed' => false, 'from' => $from, 'to' => $to ), (array) array_shift( $this->state['wp_scripts'] ) );
		}

		if ( ! in_array( $from, $names, true ) && ( $args_from ?? '' ) !== $from ) {
			return array( 'changed' => false, 'foreign' => true, 'from' => $from, 'to' => $to );
		}

		if ( $from === $to ) {
			return array( 'changed' => false, 'unchanged' => true, 'from' => $from, 'to' => $to );
		}

		if ( $dry ) {
			return array( 'changed' => false, 'dry_run' => true, 'from' => $from, 'to' => $to, 'replaced' => 12 );
		}

		$this->state['sites'][ $vmid ][ $slug ]['wp_address'] = $to;
		$this->state['dumps'][ $vmid ][]                      = 'wpaddr';

		return array( 'changed' => true, 'from' => $from, 'to' => $to, 'replaced' => 12, 'dump' => 'wpaddr', 'keep_days' => 7 );
	}

	/**
	 * Arguments with every secret masked: fields matching *password*, *secret* or *key* (the agent's own rule).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private static function mask( array $args ): array {
		foreach ( $args as $name => $value ) {
			if ( 1 === preg_match( '/password|secret|key/i', (string) $name ) ) {
				$args[ $name ] = '***';
			}
		}

		return $args;
	}

	/**
	 * A refusal.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private function error( int $status, string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Reads the persisted state.
	 */
	private function load(): void {
		if ( ! $this->persist ) {
			return;
		}

		$stored = get_option( self::OPTION, null );

		if ( is_array( $stored ) ) {
			$this->state = array_merge( self::blank(), $stored );
		}
	}

	/**
	 * Writes the persisted state.
	 */
	private function save(): void {
		if ( $this->persist ) {
			update_option( self::OPTION, $this->state, false );
		}
	}
}
