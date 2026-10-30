<?php
/**
 * Who may reach a managed database over its public IP address: the allow-list and its two enforcement layers.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The list lives in the resource's meta (`db_allow`, `db_world`): the single source. Two layers enforce it and both
 * must allow a connection: the VM firewall (rules on net0 for the engine's port, tagged `bs-db:`, written by Firewall) and
 * the guest's own allow-list (`pg_hba.conf`, or nftables for SQL Server and Valkey; the `allow` job of the db-v1 protocol). The private network is not part of it: the
 * customer's own /28 is always open on net1.
 *
 * A change is stored under the resource lock with a new revision (DbAccessRoute) and one internal job, `db_access_apply`,
 * brings both layers to the stored state. Narrowing is never looser than the customer sees and a crash between the
 * layers leaves the stricter one: the firewall closes first, the guest follows, the firewall opens last.
 */
final class DbAccess {

	use Steps;
	use Prepares;

	public const APPLY = 'db_access_apply';

	/** Meta keys. */
	public const LIST    = 'db_allow';
	public const WORLD   = 'db_world';
	public const REV     = 'db_access_rev';
	public const APPLIED = 'db_access_applied';
	public const STATE   = 'db_access_state';
	public const OP      = 'db_access_op';

	/** The word that opens the database to everyone. */
	public const CONFIRM_WORLD = 'OPEN TO EVERYONE';

	/** The guest takes at most 20 entries. */
	public const MAX_ENTRIES = 20;

	/** The shortest prefix of a range (a /16 is 65536 addresses). */
	public const MIN_PREFIX = 16;

	/** Seconds the guest has for an allow job. */
	private const BUDGET = 600;

	/** Changes per user, resource and minute (Routes::guard). */
	public const RATE_LIMIT = 10;

	/**
	 * Ranges that are never the customer's address on the internet: private, loopback, link local, shared and reserved.
	 *
	 * @var string[]
	 */
	private const REFUSED = array( '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16', '224.0.0.0/3' );

	/** @var array<string, mixed> */
	private $cluster = array();

	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * Is the resource a database of ours?
	 *
	 * @param mixed $r Resource.
	 */
	public static function is_db( $r ): bool {
		return $r instanceof Resource && DbDriver::ID === $r->get( 'driver' );
	}

	// -- The stored list -------------------------------------------------------------------------

	/**
	 * The stored entries, each checked again.
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array{cidr: string, label: string, added: int}>
	 */
	public static function entries( Resource $r ): array {
		$out = array();

		foreach ( (array) $r->get( self::LIST ) as $entry ) {
			$cidr = is_array( $entry ) ? self::normalise( (string) ( $entry['cidr'] ?? '' ) ) : null;

			if ( is_string( $cidr ) && ! isset( $out[ $cidr ] ) ) {
				$out[ $cidr ] = array(
					'cidr'  => $cidr,
					'label' => self::clean_label( (string) ( $entry['label'] ?? '' ) ),
					'added' => (int) ( $entry['added'] ?? 0 ),
				);
			}
		}

		return array_slice( array_values( $out ), 0, self::MAX_ENTRIES );
	}

	/**
	 * Is the database open to everyone?
	 *
	 * @param Resource $r Resource.
	 */
	public static function world( Resource $r ): bool {
		return true === $r->get( self::WORLD );
	}

	/**
	 * The public address the database has now, or ''. Only an address that is in use counts: one that is still
	 * being added is not reachable yet.
	 *
	 * @param Resource $r Resource.
	 */
	public static function public_address( Resource $r ): string {
		$row = IpPool::for_resource( $r->id() );

		return null !== $row && 'assigned' === $row['state'] ? $row['address'] : '';
	}

	/**
	 * A label: plain text, short.
	 *
	 * @param string $label Label.
	 */
	public static function clean_label( string $label ): string {
		return mb_substr( sanitize_text_field( $label ), 0, Firewall::MAX_LABEL );
	}

	/**
	 * An address or range as `a.b.c.d/n`, or null. IPv4 only; no host bits.
	 *
	 * @param string $input Address, or range with prefix.
	 */
	public static function normalise( string $input ): ?string {
		$cidr = self::canonical( $input );

		return is_string( $cidr ) ? $cidr : null;
	}

	/**
	 * Address or range as `a.b.c.d/n`, or the reason it is refused.
	 *
	 * @param string $input Address, or range with prefix.
	 * @return string|\WP_Error
	 */
	private static function canonical( string $input ) {
		$parsed = self::parse( trim( $input ) );

		return is_array( $parsed ) ? long2ip( $parsed[0] ) . '/' . $parsed[1] : $parsed;
	}

	/**
	 * Address and prefix of a valid entry, or the reason it is not one.
	 *
	 * @param string $input Input.
	 * @return array{0:int,1:int}|\WP_Error
	 */
	private static function parse( string $input ) {
		if ( 1 !== preg_match( '#^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$#D', $input, $m ) ) {
			return new \WP_Error( 'beltoft_services_cidr', __( 'Enter an IPv4 address such as 203.0.113.7, or a range such as 203.0.113.0/24.', 'beltoft-services' ) );
		}

		$long   = ip2long( $m[1] );
		$prefix = isset( $m[2] ) ? (int) $m[2] : 32;

		if ( false === $long || false === filter_var( $m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) || $prefix > 32 ) {
			return new \WP_Error( 'beltoft_services_cidr', __( 'Enter an IPv4 address such as 203.0.113.7, or a range such as 203.0.113.0/24.', 'beltoft-services' ) );
		}

		if ( $prefix < self::MIN_PREFIX ) {
			/* translators: %d: the shortest prefix, 16 */
			return new \WP_Error( 'beltoft_services_cidr_wide', sprintf( __( 'A range can be at most a /%d (65536 addresses). To let everyone in, use the option to open the database to everyone.', 'beltoft-services' ), self::MIN_PREFIX ), array( 'status' => 422 ) );
		}

		$mask  = 0 === $prefix ? 0 : ( 0xFFFFFFFF << ( 32 - $prefix ) ) & 0xFFFFFFFF;
		$start = $long & 0xFFFFFFFF;

		if ( ( $start & $mask ) !== $start ) {
			return new \WP_Error( 'beltoft_services_cidr_host', sprintf( /* translators: %s: the first address of the range */ __( 'The range must start at its first address, %s.', 'beltoft-services' ), long2ip( $start & $mask ) . '/' . $prefix ), array( 'status' => 422 ) );
		}

		$end = $start | ( ~$mask & 0xFFFFFFFF );

		foreach ( self::REFUSED as $block ) {
			list( $base, $bits ) = explode( '/', $block );
			$block_mask          = ( 0xFFFFFFFF << ( 32 - (int) $bits ) ) & 0xFFFFFFFF;
			$block_start         = (int) ip2long( $base ) & $block_mask;
			$block_end           = $block_start | ( ~$block_mask & 0xFFFFFFFF );

			if ( $start <= $block_end && $end >= $block_start ) {
				return new \WP_Error( 'beltoft_services_cidr_private', __( 'This address is on a private or reserved network. Applications on your private network already reach the database; enter the public address of the place the connection comes from.', 'beltoft-services' ), array( 'status' => 422 ) );
			}
		}

		return array( $start, $prefix );
	}

	/**
	 * The address the customer is using (IPv4 only), or ''. A convenience for the button "Use my current IP".
	 */
	public static function my_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		/** This filter is documented in RateLimit::visitor(). */
		$ip = (string) apply_filters( 'beltoft_services_client_ip', $ip );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $ip : '';
	}

	// -- A change ----------------------------------------------------------------------------------

	/**
	 * Can the customer change the list now? The database has a public address in use, is ready, and the resource
	 * accepts actions.
	 *
	 * @param Resource $r Resource.
	 */
	public static function editable( Resource $r ): bool {
		return self::is_db( $r ) && 'ready' === $r->get( 'db_state' ) && '' !== self::public_address( $r ) && $r->accepts_actions() && DbIp::idle( $r );
	}

	/**
	 * The meta a requested change would store, or the reason it is refused. The caller stores it under the
	 * resource lock (see DbAccessRoute).
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $body Request body: op add (cidr, label), remove (cidr), world_on (confirm), world_off.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function change( Resource $r, array $body ) {
		$entries = self::entries( $r );
		$world   = self::world( $r );
		$op      = (string) ( $body['op'] ?? '' );

		if ( 'add' === $op ) {
			$cidr = self::canonical( is_string( $body['cidr'] ?? null ) ? $body['cidr'] : '' );

			if ( $cidr instanceof \WP_Error ) {
				return $cidr;
			}

			if ( in_array( $cidr, array_column( $entries, 'cidr' ), true ) ) {
				return new \WP_Error( 'beltoft_services_cidr_duplicate', __( 'This address is already in the list.', 'beltoft-services' ) );
			}

			if ( count( $entries ) >= self::MAX_ENTRIES ) {
				/* translators: %d: the most entries, 20 */
				return new \WP_Error( 'beltoft_services_cidr_full', sprintf( __( 'The list holds at most %d addresses. Remove one first, or use a range.', 'beltoft-services' ), self::MAX_ENTRIES ) );
			}

			$entries[] = array( 'cidr' => $cidr, 'label' => self::clean_label( is_string( $body['label'] ?? null ) ? $body['label'] : '' ), 'added' => time() );
		} elseif ( 'remove' === $op ) {
			$cidr = self::normalise( is_string( $body['cidr'] ?? null ) ? $body['cidr'] : '' );
			$left = null === $cidr ? $entries : array_values( array_filter( $entries, static fn( $e ) => $e['cidr'] !== $cidr ) );

			if ( count( $left ) === count( $entries ) ) {
				return new \WP_Error( 'beltoft_services_cidr_missing', __( 'This address is not in the list.', 'beltoft-services' ), array( 'status' => 404 ) );
			}

			$entries = $left;
		} elseif ( 'world_on' === $op ) {
			if ( self::CONFIRM_WORLD !== ( is_string( $body['confirm'] ?? null ) ? $body['confirm'] : '' ) ) {
				/* translators: %s: the words to type, OPEN TO EVERYONE */
				return new \WP_Error( 'beltoft_services_confirm', sprintf( __( 'Type %s to confirm. Anyone on the internet can then try to log in to your database.', 'beltoft-services' ), self::CONFIRM_WORLD ), array( 'status' => 422 ) );
			}

			$world = true;
		} elseif ( 'world_off' === $op ) {
			$world = false;
		} else {
			return new \WP_Error( 'beltoft_services_access_op', __( 'This change is not available.', 'beltoft-services' ) );
		}

		return array(
			self::LIST  => $entries,
			self::WORLD => $world,
			self::REV   => (int) $r->get( self::REV ) + 1,
			self::STATE => 'applying',
		);
	}

	/**
	 * The firewall rules the stored list asks for on net0: tag => source (null: from anywhere). Open to everyone is one
	 * rule; otherwise one per entry (a single host without its /32).
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string|null>
	 */
	public static function rules_wanted( Resource $r ): array {
		if ( self::world( $r ) ) {
			return array( Firewall::DB_WORLD => null );
		}

		$out = array();

		foreach ( self::entries( $r ) as $entry ) {
			$source                                   = preg_replace( '#/32$#D', '', $entry['cidr'] );
			$out[ Firewall::TAG_DB . $source ] = (string) $source;
		}

		return $out;
	}

	/**
	 * What the Access tab shows.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>
	 */
	public static function view( Resource $r ): array {
		$format  = (string) get_option( 'date_format' );
		$address = self::public_address( $r );
		$entries = array();

		foreach ( self::entries( $r ) as $entry ) {
			$entries[] = array(
				'cidr'  => preg_replace( '#/32$#D', '', $entry['cidr'] ),
				'label' => $entry['label'],
				'added' => $entry['added'] > 0 ? wp_date( $format, $entry['added'] ) : '',
			);
		}

		$wanted = DbIp::plan_wants( (array) $r->get( 'plan' ) );

		return array(
			'address'   => $address,
			'adding'    => 'add' === DbIp::action( $r ) || ( $wanted && '' === $address ),
			'removing'  => 'remove' === DbIp::action( $r ),
			'entries'   => $entries,
			'world'     => self::world( $r ),
			'state'     => (string) $r->get( self::STATE ),
			'editable'  => self::editable( $r ),
			'max'       => self::MAX_ENTRIES,
			'my_ip'     => self::my_ip(),
			'confirm'   => self::CONFIRM_WORLD,
			'add_url'   => '' === $address && ! $wanted ? DbIp::switch_url( $r, true ) : '',
			'add_price' => '' === $address && ! $wanted ? DbIp::price_label( $r, true ) : '',
			'sold_out'  => '' === $address && ! $wanted && 0 === IpPool::available_for_db(),
			'remove_url' => '' !== $address && $wanted ? DbIp::switch_url( $r, false ) : '',
		);
	}

	// -- The job -------------------------------------------------------------------------------------

	/**
	 * Brings the firewall and the guest to the stored list: the firewall closes what is gone, the guest takes the
	 * list (allow job), the firewall opens what is new. Each phase is idempotent; the stored operation remembers
	 * where it was. A newer revision starts over.
	 *
	 * @param bool $inline   Called by DbIp, which has an address change in progress and wants the list applied as part of it.
	 * @param bool $tolerant A guest that cannot be told is reported to the admin, and the layers that can be changed are.
	 */
	public function run( bool $inline = false, bool $tolerant = false ): StepResult {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'db_access: no VM is recorded for this service' );
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		$rev = (int) $this->r->get( self::REV );
		$op  = $this->r->get( self::OP );
		$op  = is_array( $op ) && (int) ( $op['rev'] ?? 0 ) === $rev ? $op : array( 'rev' => $rev, 'phase' => 'close', 'at' => time() );

		// An address that is being added or taken away is the concern of DbIp, which applies the list at its end.
		if ( ! $inline && ! DbIp::idle( $this->r ) ) {
			return StepResult::poll( 15, 'db_access: waiting for the public address change', self::BUDGET );
		}

		$v = $this->verified( $record, 'db_access' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$firewall = new Firewall( $this->r, $this->client, $this->cluster );

		if ( 'close' === $op['phase'] ) {
			$done = $firewall->sync_database_access( $record, $v, 'close' );

			if ( $done instanceof StepResult ) {
				return $done;
			}

			$op['phase'] = 'guest';
			$this->r->set( array( self::OP => $op ) );
		}

		if ( 'guest' === $op['phase'] ) {
			$guest = $this->guest( $record, $v, $op );

			if ( $guest instanceof StepResult && $tolerant && $guest->is_failed() ) {
				\BeltoftServices\Alerts::admin( $this->r, 'The access list could not be sent to the database server (' . $guest->error() . '). The network card is changed anyway.' );
				$guest = $op;
			}

			if ( $guest instanceof StepResult ) {
				return $guest;
			}

			$op          = $guest;
			$op['phase'] = 'open';
			$this->r->set( array( self::OP => $op ) );
		}

		$v = $this->verified( $record, 'db_access' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$done = $firewall->sync_database_access( $record, $v, 'open' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$this->r->set( array( self::APPLIED => $rev, self::STATE => (int) $this->r->get( self::REV ) === $rev ? 'applied' : 'applying', self::OP => null ) );

		return StepResult::done( array(), self::event_text( $this->r ) );
	}

	/**
	 * The history line of a finished change.
	 *
	 * @param Resource $r Resource.
	 */
	private static function event_text( Resource $r ): string {
		return self::world( $r ) ? 'Database open to everyone' : 'Database access list applied (' . count( self::entries( $r ) ) . ' addresses)';
	}

	/**
	 * The allow job: sent once, then its status is waited for.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @param array<string, mixed> $op     Stored operation.
	 * @return array<string, mixed>|StepResult The operation when the guest has applied the list.
	 */
	private function guest( array $record, array $v, array $op ) {
		$record['node'] = $v['node'];

		if ( empty( $op['request'] ) ) {
			$op['request'] = wp_generate_uuid4();
			$op['sent']    = false;
			$op['at']      = time();
		}

		if ( empty( $op['sent'] ) ) {
			$sent = DbJob::send( $this->client, $record, self::job( $this->r, (string) $op['request'] ) );

			if ( is_wp_error( $sent ) ) {
				return time() - (int) ( $op['at'] ?? time() ) > self::BUDGET
					? StepResult::fail( 'The guest agent did not accept the access list job' )
					: StepResult::poll( 10, 'db_access: waiting for the guest agent', self::BUDGET );
			}

			$op['sent'] = true;
			$op['at']   = time();

			if ( ! $this->r->set( array( self::OP => $op ) ) ) {
				return StepResult::retry( 0, 'db_access: progress could not be stored' );
			}

			return StepResult::poll( 5, 'db_access: the access list was sent', self::BUDGET );
		}

		$status = DbGuest::status( $this->client, (string) $record['node'], (int) $record['vmid'], (string) $op['request'] );

		if ( null !== $status && 'failed' === $status['state'] ) {
			return StepResult::fail( 'The database server did not apply the access list (step ' . $status['step'] . ')' );
		}

		if ( null === $status || 'ready' !== $status['state'] ) {
			return time() - (int) ( $op['at'] ?? time() ) > self::BUDGET
				? StepResult::fail( 'The database server did not apply the access list within 10 minutes' )
				: StepResult::poll( 10, 'db_access: waiting for the database server', self::BUDGET );
		}

		return $op;
	}

	/**
	 * The allow job of the stored list (the guest wants every entry with its prefix).
	 *
	 * @param Resource $r       Resource.
	 * @param string   $request Request id.
	 * @return array<string, mixed>
	 */
	public static function job( Resource $r, string $request ): array {
		return array(
			'v'       => 1,
			'request' => $request,
			'op'      => 'allow',
			'allow'   => array_column( self::entries( $r ), 'cidr' ),
			'world'   => self::world( $r ),
		);
	}

	/**
	 * Tells the account owner that the list changed or opened to everyone. Plain text; never a secret.
	 *
	 * @param Resource $r    Resource.
	 * @param string   $what Which change: world_on, world_off, add or remove.
	 */
	public static function notify( Resource $r, string $what ): void {
		$license = $r->license();
		$to      = (string) ( $license['email'] ?? '' );

		if ( ! is_email( $to ) ) {
			return;
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		if ( 'world_on' === $what ) {
			$subject = __( 'Your database is open to everyone', 'beltoft-services' );
			$text    = __( 'You opened your database to everyone. Anyone on the internet can now try to log in to it. Open it only for as long as you need to. You can limit access to a list of addresses again in your account, under Databases, on the Access tab.', 'beltoft-services' );
		} else {
			$subject = __( 'The access list of your database changed', 'beltoft-services' );
			$text    = __( 'The list of addresses that may connect to your database over its public IP address was changed in your account. If you did not do this, change your password and contact us.', 'beltoft-services' );
		}

		wp_mail( $to, sprintf( '[%s] %s', $site, $subject ), $text . "\n" );
	}
}
