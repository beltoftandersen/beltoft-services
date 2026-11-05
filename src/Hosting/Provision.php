<?php
/**
 * Provisioning a hosting account, step by step.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Crypto;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Proxmox\Ownership;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Runs HostingDriver::provision(). Every step is recorded in the resource meta (`steps`, and its own values)
 * right after it ran and re-reads the remote state before it acts, so a crash, a timeout or a retry resumes
 * without doing anything twice (the way Proxmox\Provision works).
 *
 * Steps (plan 16.4): place (the node of the plan's group), ip (a private address and an SSH port), vmid, clone
 * (linked, tagged with the ownership line), configure (limits, network, firewall options, root disk), start,
 * prepare (the agent's ct.prepare), db (db.create on the shared database server), site (site.create and
 * site.wp_install), edge (edge.apply), dns (the preview name, SiteDns), verify (a loopback request inside the
 * container through the agent), mail (the fallback sender for website mail), ready. Not done: the request through the public edge address, which needs a live node.
 *
 * Proxmox endpoints (API viewer, https://pve.proxmox.com/pve-docs/api-viewer/index.html#<path>):
 * /cluster/resources, /cluster/nextid, /nodes/{node}/lxc/{vmid}/clone, .../config (GET, PUT), .../resize,
 * .../status/start, .../status/current, .../firewall/options (GET, PUT), /nodes/{node}/tasks/{upid}/status.
 *
 * Nothing here deletes a container; a failure never deletes anything.
 */
final class Provision {

	use Runs;

	/**
	 * The steps, in order.
	 */
	public const STEPS = array( 'place', 'ip', 'vmid', 'clone', 'configure', 'start', 'prepare', 'db', 'site', 'edge', 'dns', 'verify', 'mail', 'ready' );

	/**
	 * The plan, typed.
	 *
	 * @var array<string, int|string|bool>
	 */
	private $plan = array();

	/**
	 * Meta of the steps completed in this run, merged into the final result.
	 *
	 * @var array<string, mixed>
	 */
	private $out = array();

	/**
	 * @param Resource $r Resource.
	 */
	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * Runs what is left of the provision.
	 */
	public function run(): StepResult {
		$early = $this->preflight();

		if ( null !== $early ) {
			return $early;
		}

		// A container that was placed on a node before: connect first, so a task from an earlier run is polled.
		if ( null !== Records::for_resource( $this->r->id() ) ) {
			$early = $this->connect();

			if ( null !== $early ) {
				return $early;
			}

			$early = $this->poll_task();

			if ( null !== $early ) {
				return $early;
			}
		}

		$steps = $this->r->get( 'steps' );

		foreach ( self::STEPS as $name ) {
			if ( in_array( $name, $steps, true ) ) {
				continue;
			}

			// A license that ended since this job was queued builds nothing and starts nothing.
			if ( in_array( $name, array( 'clone', 'start' ), true ) && Reconciler::guard( $this->r ) ) {
				return StepResult::retry( 0, 'The license is no longer active; provisioning stopped' );
			}

			if ( 'place' !== $name && null === $this->client ) {
				$early = $this->connect();

				if ( null !== $early ) {
					return $early;
				}
			}

			$meta = $this->{'step_' . $name}();

			if ( $meta instanceof StepResult ) {
				return $meta;
			}

			$steps[] = $name;

			if ( ! $this->r->set( array_merge( $meta, array( 'steps' => $steps ) ) ) ) {
				return StepResult::retry( 0, $name . ': the step result could not be stored' );
			}

			$this->progressed = true;
			$this->out        = array_merge( $this->out, $meta );
		}

		return StepResult::done( array_merge( $this->out, array( 'steps' => $steps ) ) );
	}

	// -- Preflight -------------------------------------------------------------------------------

	/**
	 * Checks that do not depend on the remote state; they run on every call and are not recorded. Each failure
	 * is permanent.
	 */
	private function preflight(): ?StepResult {
		if ( ! Crypto::available() ) {
			return StepResult::fail( 'BELTOFT_SERVICES_KEY is not configured: the database password cannot be stored safely' );
		}

		$this->plan = Plan::typed( (array) $this->r->get( 'plan' ) );
		$problem    = Plan::problem( $this->plan );

		if ( null !== $problem ) {
			return StepResult::fail( $problem );
		}

		if ( '' === Config::preview_zone() ) {
			return StepResult::fail( 'The hosting preview zone is not set' );
		}

		return null;
	}

	// -- Steps -----------------------------------------------------------------------------------

	/**
	 * Step place: the node of the plan's group with room and the most free memory (HostingCapacity).
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_place() {
		$group = (string) $this->plan['node_group'];

		if ( array() === Nodes::in_group( $group ) ) {
			return StepResult::fail( 'No enabled hosting node in the group "' . $group . '"' );
		}

		// The choice and the record are one step under a lock, so two accounts do not take the last room together.
		list( $locked, $record ) = \BeltoftServices\Locks::with(
			\BeltoftServices\Locks::name( 'hosting-place', 0 ),
			\BeltoftServices\Locks::allocation_wait(),
			function () {
				$stored = Records::for_resource( $this->r->id() );

				if ( $stored ) {
					return $stored; // An account placed before keeps its node, whatever the group holds now.
				}

				$best = HostingCapacity::place( $this->plan );

				return null === $best ? null : Records::open( $this->r->id(), $best );
			}
		);

		if ( ! $locked ) {
			return StepResult::retry( 0, 'place: the placement is busy' );
		}

		if ( ! is_array( $record ) ) {
			return StepResult::retry( 0, 'place: no node of the group "' . $group . '" has room for this plan' );
		}

		$err = $this->connect();

		return $err ?? array( 'node' => $record['node'] );
	}

	/**
	 * Step ip: a private address and a public SSH port for the container.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_ip() {
		try {
			$ip   = CtIpPool::assign( $this->r->id(), $this->node, $this->node_id );
			$port = SshPorts::assign( $this->r->id(), $this->node, $this->node_id );
		} catch ( \RuntimeException $e ) {
			return StepResult::retry( 0, 'ip: ' . $this->scrub( $e->getMessage(), 120 ) );
		}

		if ( null === $ip ) {
			return StepResult::retry( 0, 'ip: no free container address on the node' );
		}

		if ( null === $port ) {
			return StepResult::retry( 0, 'ip: no free SSH port on the node' );
		}

		return array(
			'ip'       => $ip,
			'ssh_port' => $port,
		);
	}

	/**
	 * Step vmid: a container id in the node's customer range.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_vmid() {
		$list = Ownership::listing( $this->client );

		if ( is_wp_error( $list ) ) {
			return $this->classify( $list, 'vmid' );
		}

		$taken = array_map( static fn( $e ) => (int) ( $e['vmid'] ?? 0 ), $list );
		$c     = $this->client;

		try {
			$vmid = Records::allocate_vmid(
				$this->r->id(),
				(int) $this->node['vmid_min'],
				(int) $this->node['vmid_max'],
				$taken,
				static function ( int $id ) use ( $c ): bool {
					$free = Ownership::is_free( $c, $id );

					if ( is_wp_error( $free ) ) {
						throw new \RuntimeException( 'the node did not answer the free-id check' );
					}

					return $free;
				}
			);
		} catch ( \RuntimeException $e ) {
			return StepResult::retry( 0, 'vmid: ' . $this->scrub( $e->getMessage(), 120 ) );
		}

		if ( null === $vmid ) {
			return StepResult::retry( 0, 'vmid: no free container id in the node range' );
		}

		return array( 'vmid' => $vmid );
	}

	/**
	 * Step clone: a linked clone of the template, named after the account, in the pool, tagged as ours.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_clone() {
		$record = Records::for_resource( $this->r->id() );
		$list   = Ownership::listing( $this->client );

		if ( is_wp_error( $list ) ) {
			return $this->classify( $list, 'clone' );
		}

		// Already there (an earlier run, or the task ended after the last run): prove it is ours, then go on.
		if ( null !== Ownership::entry( $list, (int) $record['vmid'] ) ) {
			$v = $this->verified( $record, 'clone' );

			return $v instanceof StepResult ? $v : array();
		}

		$done = $this->run_task(
			'clone',
			'POST',
			'/nodes/' . rawurlencode( (string) $this->node['pve_node'] ) . '/lxc/' . (int) $this->node['template'] . '/clone',
			array(
				'newid'       => (int) $record['vmid'],
				'hostname'    => Config::account( $this->r->id() ),
				'pool'        => (string) $this->node['pool'],
				'description' => CtOwnership::tag( $this->r ),
				'full'        => 0,
			),
			'clone'
		);

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$v = $this->verified( $record, 'clone' );

		if ( $v instanceof StepResult ) {
			return true === $done ? $v : StepResult::retry( 0, 'clone: the clone task failed (' . $done['failed'] . ')' );
		}

		return array();
	}

	/**
	 * Step configure: limits, network, mount point, firewall options and group, root disk.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_configure() {
		$record = Records::for_resource( $this->r->id() );
		$v      = $this->verified( (array) $record, 'configure' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$base = $this->lxc( $v['node'] );
		$rate = (int) $this->node['net_mbit'];

		// Parameter names to be checked in the API viewer, and the token's privilege for mp0 (plan 16.4 [verify]).
		$net = sprintf(
			'name=eth0,bridge=%s,ip=%s/%d,gw=%s,firewall=1%s',
			$this->node['bridge'],
			$record['ip'],
			self::prefix( (string) $this->node['lan_cidr'] ),
			$this->node['gateway'],
			$rate > 0 ? ',rate=' . $rate : ''
		);

		$diff = array();

		foreach ( array(
			'net0'    => $net,
			'onboot'  => 1,
			'tags'    => Config::tag(),
		) as $key => $value ) {
			if ( (string) ( $v['config'][ $key ] ?? '' ) !== (string) $value ) {
				$diff[ $key ] = $value;
			}
		}

		// The dump mount (a quarter of the disk) holds the nightly SQL dumps, the only copy of the databases in the container backup, so it is part of the backup (backup=1). It is created once; an existing mount point is never rewritten.
		if ( ! isset( $v['config']['mp0'] ) ) {
			$diff['mp0'] = sprintf( '%s:%d,mp=/var/backups/db,backup=1', $this->node['storage'], max( 1, intdiv( (int) $this->plan['disk_gb'], 4 ) ) );
		}

		if ( array() !== $diff ) {
			$sent = $this->put_config( $base, $v['config'], $diff, 'configure' );

			if ( $sent instanceof StepResult ) {
				return $sent;
			}

			$v = $this->verified( (array) $record, 'configure' );

			if ( $v instanceof StepResult ) {
				return $v;
			}
		}

		$fw = $this->firewall( $base );

		if ( $fw instanceof StepResult ) {
			return $fw;
		}

		$limits = $this->apply_limits( $v, $this->plan, 'configure' );

		return $limits instanceof StepResult ? $limits : array();
	}

	/**
	 * The container firewall options: on, IP and MAC filter, the inbound policy written explicitly.
	 *
	 * @param string $base Container path.
	 * @return true|StepResult
	 */
	private function firewall( string $base ) {
		$current = $this->client->request( 'GET', $base . '/firewall/options' );

		if ( is_wp_error( $current ) ) {
			return $this->classify( $current, 'configure' );
		}

		$want = array(
			'enable'    => 1,
			'ipfilter'  => 1,
			'macfilter' => 1,
			'policy_in' => 'DROP',
			'policy_out' => 'DROP',
		);
		$diff = array();

		foreach ( $want as $key => $value ) {
			if ( (string) ( is_array( $current ) ? ( $current[ $key ] ?? '' ) : '' ) !== (string) $value ) {
				$diff[ $key ] = $value;
			}
		}

		if ( array() !== $diff ) {
			$answer = $this->client->request( 'PUT', $base . '/firewall/options', $diff );

			if ( is_wp_error( $answer ) ) {
				return $this->classify( $answer, 'configure' );
			}
		}

		// With both policies on DROP nothing passes until the node's security group (edge, database, DNS, mail ports) is joined.
		$group = (string) ( $this->node['fw_group'] ?? '' );

		if ( '' === $group ) {
			return true;
		}

		$rules = $this->client->request( 'GET', $base . '/firewall/rules' );

		if ( is_wp_error( $rules ) ) {
			return $this->classify( $rules, 'configure' );
		}

		foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
			if ( is_array( $rule ) && 'group' === ( $rule['type'] ?? '' ) && $group === ( $rule['action'] ?? '' ) ) {
				return true;
			}
		}

		$added = $this->client->request( 'POST', $base . '/firewall/rules', array( 'type' => 'group', 'action' => $group, 'enable' => 1 ) );

		return is_wp_error( $added ) ? $this->classify( $added, 'configure' ) : true;
	}

	/**
	 * Step start: the container is running.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_start() {
		$v = $this->verified( (array) Records::for_resource( $this->r->id() ), 'start' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$on = $this->power_on( $v, 'start' );

		return $on instanceof StepResult ? $on : array();
	}

	/**
	 * Step prepare: the agent's first-boot job (host keys, machine id, hostname, Valkey size, worker budget).
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_prepare() {
		$record = Records::for_resource( $this->r->id() );
		$result = $this->agent_call(
			'prepare',
			'ct.prepare',
			array(
				'vmid'      => (int) $record['vmid'],
				'account'   => Config::account( $this->r->id() ),
				'hostname'  => Config::account( $this->r->id() ),
				'ip'        => (string) $record['ip'],
				'ssh_port'  => (int) $record['ssh_port'],
				'valkey_mb' => (int) $this->plan['valkey_mb'],
				'workers'   => Plan::workers_per_site( $this->plan, 1 ),
				'ssh'       => ! empty( $this->plan['ssh'] ), // False is SFTP only (the Start plan).
			)
		);

		return $result instanceof StepResult ? $result : array( 'prepared_ssh' => ! empty( $this->plan['ssh'] ) );
	}

	/**
	 * Step db: the account's database user and the first site's database on the shared database server. The
	 * password is made here, kept encrypted in the resource meta and sent in the job; it is never logged.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_db() {
		$spec = $this->site_spec();

		if ( $spec instanceof StepResult ) {
			return $spec;
		}

		$secret = (string) $this->r->get( 'db_secret' );

		if ( '' === $secret ) {
			$secret = Crypto::encrypt( wp_generate_password( 32, false ) );

			if ( ! $this->r->set( array( 'db_secret' => $secret ) ) ) {
				return StepResult::retry( 0, 'db: the database password could not be stored' );
			}
		}

		$password = Crypto::decrypt( $secret );

		if ( null === $password || '' === $password ) {
			return StepResult::fail( 'db: the stored database password cannot be decrypted' );
		}

		$this->secrets[] = $password;
		$record          = Records::for_resource( $this->r->id() );

		$result = $this->agent_call(
			'db_create',
			'db.create',
			array(
				'account'   => Config::account( $this->r->id() ),
				'vmid'      => (int) $record['vmid'],
				'ip'        => (string) $record['ip'],
				'databases' => array( self::db_name( $this->r->id(), $spec['slug'] ) ),
				'password'  => $password,
			)
		);

		if ( $result instanceof StepResult ) {
			return $result;
		}

		Records::set_db_host( $this->r->id(), (string) $this->node['db_host'] );

		return array( 'db_user' => Config::account( $this->r->id() ) );
	}

	/**
	 * Step site: the first website and, unless the order said otherwise, a WordPress install.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_site() {
		$spec = $this->site_spec();

		if ( $spec instanceof StepResult ) {
			return $spec;
		}

		if ( ! Sites::add( $this->r->id(), $spec['slug'], $spec['label'], $spec['php'], Plan::workers_per_site( $this->plan, 1 ) ) ) {
			return StepResult::fail( 'site: the site "' . $spec['slug'] . '" could not be recorded (is the name taken?)' );
		}

		$record = Records::for_resource( $this->r->id() );
		$vmid   = (int) $record['vmid'];

		$created = $this->agent_call(
			'site_create',
			'site.create',
			array(
				'vmid'    => $vmid,
				'slug'    => $spec['slug'],
				'php'     => $spec['php'],
				'workers' => Plan::workers_per_site( $this->plan, 1 ),
				'host'    => EdgeMap::preview_host( $spec['slug'] ),
			)
		);

		if ( $created instanceof StepResult ) {
			return $created;
		}

		if ( $spec['install'] ) {
			$installed = $this->agent_call(
				'site_install',
				'site.wp_install',
				array(
					'vmid'        => $vmid,
					'slug'        => $spec['slug'],
					'title'       => '' !== $spec['label'] ? $spec['label'] : $spec['slug'],
					'admin_user'  => $spec['admin_user'],
					'admin_email' => $spec['admin_email'],
					'locale'      => $spec['language'],
					'woocommerce' => 'woocommerce' === $spec['install_kind'],
				)
			);

			if ( $installed instanceof StepResult ) {
				return $installed;
			}
		}

		return array();
	}

	/**
	 * Step edge: the routes of the account's names go to the edge.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_edge() {
		$result = $this->agent_call(
			'edge_apply',
			'edge.apply',
			array(
				'account' => Config::account( $this->r->id() ),
				'routes'  => EdgeMap::routes( $this->r->id(), false ),
			)
		);

		return $result instanceof StepResult ? $result : array();
	}

	/**
	 * Step dns: the preview name of the first site points at the edge.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_dns() {
		$spec = $this->site_spec();

		if ( $spec instanceof StepResult ) {
			return $spec;
		}

		$site = Sites::find( $this->r->id(), $spec['slug'] );

		if ( null === $site ) {
			return StepResult::fail( 'dns: the site record is missing' );
		}

		$host = EdgeMap::preview_host( $spec['slug'] );

		if ( ! SiteDomains::add( $this->r->id(), (int) $site['id'], $host, 'preview' ) ) {
			return StepResult::fail( 'dns: the preview name "' . $host . '" could not be recorded (is it taken?)' );
		}

		$done = SiteDns::publish_preview( $spec['slug'], $this->node );

		if ( is_wp_error( $done ) ) {
			return StepResult::retry( 0, 'dns: ' . $this->scrub( $done->get_error_message(), 160 ) );
		}

		if ( true !== $done ) {
			return StepResult::fail( 'dns: ' . $this->scrub( (string) $done, 160 ) );
		}

		SiteDomains::set_state( $host, 'active' );

		return $this->attach_domain( $spec, $site );
	}

	/**
	 * The domain the order named: a name on our name servers is written now, an external one waits for the customer.
	 * A name that cannot be attached (taken since, not valid) is left out and the site still goes live on its
	 * temporary address; the customer can add it again from the account.
	 *
	 * @param array<string, mixed> $spec Site spec.
	 * @param array<string, mixed> $site Site record.
	 * @return array<string, mixed>|StepResult
	 */
	private function attach_domain( array $spec, array $site ) {
		if ( '' === (string) $spec['domain'] || null !== SiteDomains::find( (string) $spec['domain'] ) ) {
			return array();
		}

		$added = SiteDns::add_domain( $this->r, (string) $site['slug'], (string) $spec['domain'], $this->node );

		if ( is_wp_error( $added ) ) {
			return StepResult::retry( 0, 'dns: ' . $this->scrub( $added->get_error_message(), 160 ) );
		}

		if ( is_array( $added ) && 'active' === $added['state'] ) {
			$edge = $this->agent_call( 'edge_domain', 'edge.apply', array( 'account' => Config::account( $this->r->id() ), 'routes' => EdgeMap::routes( $this->r->id(), false ) ) );

			if ( $edge instanceof StepResult ) {
				return $edge;
			}
		}

		return array();
	}

	/**
	 * Step verify: a loopback request inside the container answers for the preview name.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_verify() {
		$spec   = $this->site_spec();
		$record = Records::for_resource( $this->r->id() );

		if ( $spec instanceof StepResult ) {
			return $spec;
		}

		$result = $this->agent_call(
			'site_verify',
			'site.verify',
			array(
				'vmid' => (int) $record['vmid'],
				'slug' => $spec['slug'],
				'host' => EdgeMap::preview_host( $spec['slug'] ),
			)
		);

		if ( $result instanceof StepResult ) {
			return $result;
		}

		return 200 === (int) ( $result['status'] ?? 0 ) ? array() : StepResult::retry( 0, 'verify: the site answered ' . (int) ( $result['status'] ?? 0 ) );
	}

	/**
	 * Step mail: website mail goes out through our mail server from the account's own fallback mailbox (SiteMail).
	 * Nothing happens until the mail host is set and the preview zone is a mail domain, and the step does not wait for them.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function step_mail() {
		return ( new SiteMail( $this->r ) )->use_fallback();
	}

	/**
	 * Step ready: the account's facts for the panels.
	 *
	 * @return array<string, mixed>
	 */
	private function step_ready(): array {
		$record = (array) Records::for_resource( $this->r->id() );

		return array(
			'hosting' => array(
				'node'     => $this->node_id,
				'vmid'     => (int) $record['vmid'],
				'ip'       => (string) $record['ip'],
				'ssh_port' => (int) $record['ssh_port'],
			),
		);
	}

	// -- Helpers ---------------------------------------------------------------------------------

	/**
	 * The first site: what the order stored in the meta `site` (slug, label, php, install, admin_user), with
	 * defaults for an admin-issued license. Stored once, so every step sees the same values.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	private function site_spec() {
		$stored = $this->r->get( 'site_spec' );

		if ( is_array( $stored ) && isset( $stored['slug'] ) ) {
			return $stored;
		}

		$given = (array) $this->r->get( 'site' );
		$slug  = isset( $given['slug'] ) ? strtolower( (string) $given['slug'] ) : 'w' . $this->r->id();

		// The name was free at the checkout, but a name is taken by whoever makes it first (an order with several lines, a held name that
		// lapsed): then the account starts on a generated name, and the customer can add or rename later. Never a failed provision.
		if ( isset( $given['slug'] ) && null === Sites::find( $this->r->id(), $slug ) && Sites::taken( $slug ) ) {
			$slug = 'w' . $this->r->id();
		}

		if ( ! Sites::valid_slug( $slug ) ) {
			return StepResult::fail( 'site: the site name "' . mb_substr( sanitize_text_field( $slug ), 0, 40 ) . '" is not valid' );
		}

		$license = $this->r->license();
		$spec    = array(
			'slug'        => $slug,
			'label'       => sanitize_text_field( (string) ( $given['label'] ?? '' ) ),
			'php'         => 1 === preg_match( '/^\d\.\d{1,2}$/D', (string) ( $given['php'] ?? '' ) ) ? (string) $given['php'] : Config::get( 'php_default' ),
			'install'     => ! array_key_exists( 'install', $given ) || ! empty( $given['install'] ),
			'admin_user'  => 1 === preg_match( '/^[a-z0-9_-]{1,32}$/D', (string) ( $given['admin_user'] ?? '' ) ) ? (string) $given['admin_user'] : Config::admin_user(),
			'admin_email' => sanitize_email( (string) ( '' !== (string) ( $given['admin_email'] ?? '' ) ? $given['admin_email'] : ( $license['email'] ?? '' ) ) ),
			'install_kind' => in_array( (string) ( $given['install_kind'] ?? '' ), SiteField::INSTALLS, true ) ? (string) $given['install_kind'] : 'wordpress',
			'language'    => 1 === preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?$/D', (string) ( $given['language'] ?? '' ) ) ? (string) $given['language'] : 'en_US',
			'domain'      => isset( $given['domain'] ) ? strtolower( (string) $given['domain'] ) : '',
		);

		if ( '' === $spec['admin_email'] ) {
			$spec['admin_email'] = 'unknown@invalid';
		}

		if ( ! $this->r->set( array( 'site_spec' => $spec ) ) ) {
			return StepResult::retry( 0, 'site: the site settings could not be stored' );
		}

		return $spec;
	}

	/**
	 * The database name of a site: the account name and the slug with dashes as underscores.
	 *
	 * @param int    $resource_id Activation id.
	 * @param string $slug        Site slug.
	 */
	public static function db_name( int $resource_id, string $slug ): string {
		return substr( Config::account( $resource_id ) . '_' . str_replace( '-', '_', $slug ), 0, 64 );
	}

	/**
	 * The prefix length of a CIDR.
	 *
	 * @param string $cidr For example 10.200.0.0/16.
	 */
	private static function prefix( string $cidr ): int {
		return (int) substr( $cidr, (int) strrpos( $cidr, '/' ) + 1 );
	}
}
