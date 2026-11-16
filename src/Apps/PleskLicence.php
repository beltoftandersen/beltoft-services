<?php
/**
 * Plesk as a paid app: the Openprovider Plesk licence behind a server's tier subscription.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Apps;

use BeltoftServices\Alerts;
use BeltoftServices\Background;
use BeltoftServices\Crypto;
use BeltoftServices\Domains\Client as RegistrarClient;
use BeltoftServices\Domains\Clients as RegistrarClients;
use BeltoftServices\Domains\DomainSettings;
use BeltoftServices\Domains\LicenceCatalog;
use BeltoftServices\Domains\LicenceVendor;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Vm\ServerName;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The vendor driver of the paid app `plesk` (see the design note in AppLicence). The tier subscription's licence
 * resource (driver proxmox-app) is the entitlement; this class keeps one Openprovider Plesk licence in step with it,
 * through the REST client of the domains feature (RegistrarClients::registrar(): the fake in tests and on staging,
 * the live client only with BELTOFT_SERVICES_DOMAINS_LIVE).
 *
 * What it does, by event:
 * - provisioned (filter beltoft_services_app_licence_provision): create the licence for the tier's item, bound to the
 *   server's public IPv4, titled "licence <id> <server name>". A crash is safe: the licence is looked up by that title
 *   before one is created, so one is never created twice. The activation key is fetched and stored encrypted.
 * - tier_changed: PUT the new item, fetch the key again and hand it to the server.
 * - paused (on hold): the licence is KEPT. Openprovider bills a licence monthly whatever the server does, and a deleted
 *   licence would have to be bought again (and its key put back on the server) when the hold ends. Nothing is called.
 * - resumed: nothing is needed; the licence was kept. It is only checked.
 * - cancelled (the licence ended, the server was deleted, a reinstall left Plesk): DELETE the licence. A licence that
 *   is already gone (404) counts as deleted. A call that fails is tried again by an Action Scheduler job and an admin
 *   is told once.
 * - a reinstall that keeps Plesk: the hardware id changes with the new system, so the id is reset before the key is
 *   put on the new install.
 *
 * Spend guard. Creating a licence (and a switch to a dearer tier) spends money at Openprovider. It only happens when
 * the paid line of the tier covers the cost set for the tier (setting plesk_costs or the filter
 * beltoft_services_plesk_costs, EUR per month excluding VAT, the way SpendGuard guards a domain). A cost that is not
 * set is refused, except on the fake client. Pay as you go licences count their monthly cap.
 *
 * The key. The install job carries it (job.licence.key) when it is there; if not, Plesk installs on its own trial and a
 * job writes the key into /var/lib/beltoft-app/licence.json later (the guest turns it into a licence and answers in
 * licence.status.json with the hash of the key, never the key). The key is stored encrypted (Crypto), and never
 * logged, emailed, shown or put in a status.
 *
 * Meta on the licence resource: plesk_key_id, plesk_title, plesk_item, plesk_key (encrypted), plesk_key_state
 * (applying, installed, failed), plesk_key_job (hash of the key the guest confirmed), plesk_key_pushes,
 * plesk_hwid_reset, plesk_deleted, plesk_sync_tries (changes), plesk_delete_tries, plesk_key_tries, plesk_wait_until.
 */
final class PleskLicence {

	public const APP = 'plesk';

	/** Jobs: hand the key to the server, and repeat a call to Openprovider that failed. */
	public const KEY_HOOK  = 'beltoft_services_plesk_key';
	public const SYNC_HOOK = 'beltoft_services_plesk_sync';

	/** Guest files: the key the plugin writes and the answer the guest writes (with the hash of the key, not the key). */
	public const KEY_FILE    = '/var/lib/beltoft-app/licence.json';
	public const STATUS_FILE = '/var/lib/beltoft-app/licence.status.json';

	/** Seconds the server may take to show its address before the licence waits no more. */
	private const WAIT = HOUR_IN_SECONDS;

	/** Tries of the key job and of a repeated call before an admin is told. */
	private const TRIES = 12;

	/** Times the key is written to the server before it is called failed. */
	private const PUSHES = 3;

	public static function register(): void {
		add_filter( 'beltoft_services_app_licence_provision', array( __CLASS__, 'provision' ), 10, 4 );
		add_action( AppLicence::EVENT, array( __CLASS__, 'changed' ), 10, 5 );
		add_filter( 'beltoft_services_app_install_licence', array( __CLASS__, 'install_job' ), 10, 3 );
		add_filter( 'beltoft_services_app_licence_admin_rows', array( __CLASS__, 'admin_rows' ), 10, 2 );
		add_action( 'beltoft_services_app_installed', array( __CLASS__, 'installed' ) );
		add_action( 'beltoft_services_reinstall_started', array( __CLASS__, 'reinstall_started' ), 5, 2 );
		add_action( self::KEY_HOOK, array( __CLASS__, 'run_key' ) );
		add_action( self::SYNC_HOOK, array( __CLASS__, 'run_sync' ) );
		Background::declare(
			self::KEY_HOOK,
			array(
				'due'      => array( __CLASS__, 'key_due' ),
				'resource' => true,
				'label'    => 'Plesk key fetch and install',
			)
		);
		Background::declare(
			self::SYNC_HOOK,
			array(
				'due'      => array( __CLASS__, 'sync_due' ),
				'resource' => true,
				'label'    => 'Plesk licence change at the vendor',
			)
		);
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'fields' ) );
	}

	// -- Settings --------------------------------------------------------------------------------

	/**
	 * Settings → Services → Domains: the item and the cost of each tier.
	 *
	 * @param mixed $fields Fields.
	 * @return mixed
	 */
	public static function fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$base = array( 'tab' => DomainSettings::TAB, 'section' => DomainSettings::TAB );

		$fields['plesk_items'] = $base + array(
			'type'    => 'textarea',
			'default' => '',
			'label'   => __( 'Plesk licence items', 'beltoft-services' ),
			'desc'    => __( 'One tier key and Openprovider item per line, for example pro=PLESK-12-VPS-WEB-PRO-1M. The three Plesk for VPS items (admin, pro, host) are used when a tier is not listed.', 'beltoft-services' ),
		);
		$fields['plesk_costs'] = $base + array(
			'type'    => 'textarea',
			'default' => '',
			'label'   => __( 'Plesk licence costs', 'beltoft-services' ),
			'desc'    => __( 'One tier key and what Openprovider charges per month in EUR excluding VAT, for example pro=12.50. A licence is only ordered when the paid line of the tier covers this. Empty means unknown, and the live client refuses to order.', 'beltoft-services' ),
		);

		return $fields;
	}

	/**
	 * Tier key => Openprovider item id. The three Plesk for VPS editions (admin, pro, host) are the defaults.
	 *
	 * @return array<string, string>
	 */
	public static function items(): array {
		/**
		 * The Openprovider item of each Plesk tier.
		 *
		 * @param array<string, string> $items Tier key => item id.
		 */
		return LicenceCatalog::items( 'plesk_items', 'beltoft_services_plesk_items', LicenceCatalog::virtual_editions() );
	}

	/**
	 * Tier key => what Openprovider charges per month in EUR excluding VAT. A tier with no entry has an unknown cost.
	 *
	 * @return array<string, float>
	 */
	public static function costs(): array {
		/**
		 * What each Plesk tier costs at Openprovider, EUR per month excluding VAT.
		 *
		 * @param array<string, float> $costs Tier key => cost.
		 */
		return LicenceCatalog::costs( 'plesk_costs', 'beltoft_services_plesk_costs' );
	}

	// -- Helpers ---------------------------------------------------------------------------------

	private static function tier_of( Resource $licence ): string {
		return (string) ( ( (array) $licence->get( 'plan' ) )['tier'] ?? '' );
	}

	private static function is_plesk( Resource $licence ): bool {
		return self::APP === (string) ( ( (array) $licence->get( 'plan' ) )['app'] ?? '' );
	}

	/**
	 * Does an error say the licence does not exist? (Kept for callers and tests; the rule is LicenceVendor::gone().)
	 *
	 * @param \WP_Error $error Error.
	 */
	public static function gone( \WP_Error $error ): bool {
		return LicenceVendor::gone( $error );
	}

	private static function client(): RegistrarClient {
		return LicenceVendor::client();
	}

	private static function transient( \WP_Error $error ): bool {
		return LicenceVendor::transient( $error );
	}

	/**
	 * The licence key, decrypted, or '' when there is none.
	 *
	 * @param Resource $licence Licence resource.
	 */
	private static function key_of( Resource $licence ): string {
		$stored = (string) $licence->get( 'plesk_key' );
		$plain  = '' === $stored ? null : Crypto::decrypt( $stored );

		return is_string( $plain ) ? LicenceVendor::normalise_key( $plain ) : '';
	}

	/**
	 * The key as the guest can install it (the rule is LicenceVendor::normalise_key(); kept as the public entry tests call).
	 *
	 * @param string $key What Openprovider sent, or what was stored earlier.
	 */
	public static function normalise_key( string $key ): string {
		return LicenceVendor::normalise_key( $key );
	}

	/**
	 * Is the paid line of this tier at least the cost at Openprovider? Null when the spend is covered, else why not.
	 *
	 * @param Resource $licence Licence resource.
	 * @param string   $tier    Tier key.
	 */
	public static function spend_problem( Resource $licence, string $tier ): ?string {
		return LicenceVendor::spend_problem( $licence, new PleskFamily(), $tier, self::costs() );
	}

	/**
	 * The same check for a switch to another tier: the tier's own price (excluding VAT) must cover its cost.
	 *
	 * @param Resource $licence Licence resource.
	 * @param string   $tier    New tier key.
	 */
	private static function switch_problem( Resource $licence, string $tier ): ?string {
		$found = AppLicence::tier( self::APP, $tier, false );

		return LicenceVendor::switch_problem( new PleskFamily(), $tier, self::costs(), null !== $found ? $found['variation'] : null, $licence );
	}

	/**
	 * Admin alert for a failure that needs a person; one mail per kind of failure.
	 *
	 * @param Resource $licence Licence resource.
	 * @param string   $why     Text (no key).
	 */
	private static function alert( Resource $licence, string $why ): void {
		Alerts::admin( $licence, $why );
	}

	// -- Provision -------------------------------------------------------------------------------

	/**
	 * Creates the Openprovider licence of a Plesk tier, once.
	 *
	 * @param mixed    $result Null so far.
	 * @param mixed    $licence Licence resource.
	 * @param mixed    $server Server.
	 * @param mixed    $app    App id.
	 * @return mixed
	 */
	public static function provision( $result, $licence, $server, $app ) {
		if ( self::APP !== $app || ! $licence instanceof Resource || ! $server instanceof Resource ) {
			return $result;
		}

		$tier = self::tier_of( $licence );
		$item = self::items()[ $tier ] ?? '';

		if ( '' === $item ) {
			return StepResult::fail( 'No Openprovider item is set for the Plesk tier "' . $tier . '".' );
		}

		// A key could not be kept without the encryption key, so no money is spent before it is there.
		if ( ! Crypto::available() ) {
			return StepResult::retry( 3600, 'The encryption key is not configured, so no Plesk licence is ordered.' );
		}

		$key_id = (int) $licence->get( 'plesk_key_id' );

		if ( $key_id < 1 ) {
			$made = self::create( $licence, $server, $tier, $item );

			if ( $made instanceof StepResult ) {
				return $made;
			}

			$key_id = $made;
		}

		// The key may take a moment: a missing one never holds the licence back, a job fetches it.
		if ( '' === (string) $licence->get( 'plesk_key' ) && ! self::fetch_key( $licence ) ) {
			self::schedule( self::KEY_HOOK, $licence->id(), 120 );
		}

		return StepResult::done( array( 'plesk_key_id' => $key_id, 'plesk_item' => $item ) );
	}

	/**
	 * Finds or creates the licence. The licence's id is stored before anything else is asked.
	 *
	 * @return int|StepResult The Openprovider key id, or what the step must return.
	 */
	private static function create( Resource $licence, Resource $server, string $tier, string $item ) {
		$ip = IpPool::for_resource( $server->id() );

		if ( null === $ip || false === filter_var( (string) $ip['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$until = (int) $licence->get( 'plesk_wait_until' );
			$until = $until > 0 ? $until : time() + self::WAIT;

			if ( ! $licence->set( array( 'plesk_wait_until' => $until ) ) ) {
				return StepResult::retry( 60, 'The wait deadline could not be stored.' );
			}

			return time() < $until ? StepResult::wait( 60, 'Waiting for the address of the server.', $until ) : StepResult::fail( 'The server has no public address, so no Plesk licence was ordered.' );
		}

		$name = ServerName::of( $server );

		return LicenceVendor::find_or_create(
			$licence,
			new PleskFamily(),
			$tier,
			$item,
			(string) $ip['address'],
			self::costs(),
			array(
				'seed'    => '' !== $name ? $name : 'server',
				'comment' => sprintf( 'Beltoft Services licence %d, server %d', (int) ( $licence->license()['id'] ?? 0 ), (int) ( $server->license()['id'] ?? 0 ) ),
			)
		);
	}

	/**
	 * Fetches the activation key and keeps it encrypted. A new key is handed to the server.
	 *
	 * @param Resource $licence Licence resource.
	 */
	private static function fetch_key( Resource $licence ): bool {
		return LicenceVendor::fetch_key( $licence, new PleskFamily() );
	}

	// -- Events ----------------------------------------------------------------------------------

	/**
	 * The licence of a server's app changed.
	 *
	 * @param mixed $server  Server.
	 * @param mixed $app     App id.
	 * @param mixed $tier    Tier.
	 * @param mixed $event   Event.
	 * @param mixed $licence Licence resource.
	 */
	public static function changed( $server, $app, $tier, $event, $licence ): void {
		unset( $server, $tier );

		if ( self::APP !== $app || ! $licence instanceof Resource || 'paused' === $event ) {
			// A licence on hold is kept (see the class comment).
			return;
		}

		self::sync( $licence );
	}

	/**
	 * Makes Openprovider match the licence: deleted when it ended, the item of the tier otherwise. Safe to run again.
	 *
	 * @param Resource $licence Licence resource.
	 */
	public static function sync( Resource $licence ): bool {
		$key_id = (int) $licence->get( 'plesk_key_id' );

		if ( $key_id < 1 || ! self::is_plesk( $licence ) ) {
			return true;
		}

		// Deleting counts apart from fetching the key, so a licence that used up its key fetches is still deleted.
		if ( AppLicence::release_due( $licence ) ) {
			return LicenceVendor::release( $licence, new PleskFamily() );
		}

		$error = null;

		if ( 'ended' !== AppLicence::state( $licence ) ) {
			// Not for a lapsed licence: nothing is bought or changed at the vendor for one that is not paid.
			$tier = self::tier_of( $licence );
			$item = self::items()[ $tier ] ?? '';

			if ( '' !== $item && $item !== (string) $licence->get( 'plesk_item' ) ) {
				$problem = self::switch_problem( $licence, $tier );

				if ( null !== $problem ) {
					$tries = (int) $licence->get( 'plesk_sync_tries' ) + 1;
					$licence->set( array( 'plesk_sync_tries' => $tries ) );

					// Told once; the switch is tried again every hour (until the costs are fixed), then daily.
					if ( 1 === $tries ) {
						self::alert( $licence, $problem . ' The Plesk licence was not changed; it is tried again.' );
					}

					if ( $tries < 3 * self::TRIES ) {
						self::schedule( self::SYNC_HOOK, $licence->id(), $tries < self::TRIES ? HOUR_IN_SECONDS : DAY_IN_SECONDS );
					}

					return false;
				}

				$error = LicenceVendor::change_item( $licence, new PleskFamily(), $item );
			}
		}

		if ( null === $error ) {
			return true;
		}

		// A change counts apart from fetching the key.
		$tries = (int) $licence->get( 'plesk_sync_tries' ) + 1;
		$licence->set( array( 'plesk_sync_tries' => $tries ) );

		if ( self::TRIES === $tries ) {
			self::alert( $licence, 'Openprovider did not answer the Plesk licence change after ' . self::TRIES . ' tries (' . $error->get_error_code() . '). Check the licence at Openprovider.' );
		}

		if ( $tries < 3 * self::TRIES ) {
			self::schedule( self::SYNC_HOOK, $licence->id(), min( DAY_IN_SECONDS, 300 * $tries * $tries ) );
		}

		return false;
	}

	/**
	 * The watchdog's list (Background): a licence whose key was never fetched and that has no job. Only the fetch is
	 * covered: a key that is kept but not yet confirmed on the server is pushed by its own steps (PUSHES).
	 *
	 * @return array<int, array<int, int>>
	 */
	public static function key_due(): array {
		// Every licence with a vendor key id, in one query; only the ones still without a key pass.
		return LicenceVendor::key_due( new PleskFamily(), array_filter( Resource::find_many( Resource::ids_with_meta_value( 'plesk_key_id' ) ), static fn( $licence ) => $licence->is_live() ) );
	}

	/**
	 * The watchdog's list (Background): an ended licence whose deletion at Openprovider has not gone through. Narrowed in SQL to
	 * licences that still hold a vendor key id (a finished deletion clears it); a revoked licence has no mark of its own.
	 *
	 * @return array<int, array<int, int>>
	 */
	public static function sync_due(): array {
		$sets   = array();
		$family = new PleskFamily();

		foreach ( Resource::find_many( Resource::ids_with_meta_value( 'plesk_key_id' ) ) as $id => $licence ) {
			if ( ! self::is_plesk( $licence ) || (int) $licence->get( 'plesk_key_id' ) < 1 ) {
				continue;
			}

			if ( AppLicence::release_due( $licence ) ) {
				if ( LicenceVendor::delete_pending( $family, $licence ) ) {
					$sets[] = array( (int) $id );
				}
			} elseif ( $licence->is_live() && 'ended' !== AppLicence::state( $licence ) && (int) $licence->get( 'plesk_sync_tries' ) < 3 * self::TRIES ) {
				// A tier switch the cost guard refused (or that failed) is looked at again.
				$item = self::items()[ self::tier_of( $licence ) ] ?? '';

				if ( '' !== $item && $item !== (string) $licence->get( 'plesk_item' ) ) {
					$sets[] = array( (int) $id );
				}
			}
		}

		return $sets;
	}

	/**
	 * Job: a change to Openprovider that failed is made again.
	 *
	 * @param int $id Licence resource id.
	 */
	public static function run_sync( int $id ): void {
		$licence = Resource::find( $id );

		if ( $licence && self::is_plesk( $licence ) ) {
			self::sync( $licence );
		}
	}

	// -- Reinstall -------------------------------------------------------------------------------

	/**
	 * A reinstall that keeps Plesk builds a new system with a new hardware id: the id is reset before the new install
	 * gets its key. A reinstall to another app ends the licence (AppLicence), which deletes it.
	 *
	 * @param mixed $server Server.
	 * @param mixed $args   Arguments (unused).
	 */
	public static function reinstall_started( $server, $args = array() ): void {
		unset( $args );

		if ( ! $server instanceof Resource || self::APP !== (string) $server->get( 'app_id' ) ) {
			return;
		}

		$licence = AppLicence::current( $server );

		if ( $licence && self::is_plesk( $licence ) && (int) $licence->get( 'plesk_key_id' ) > 0 ) {
			$licence->set( array( 'plesk_hwid_reset' => 1, 'plesk_key_job' => null, 'plesk_key_state' => null, 'plesk_key_pushes' => 0 ) );
		}
	}

	// -- The install job and the key ---------------------------------------------------------------

	/**
	 * Adds the key to the install job (`licence.key`), after the hardware id of a reinstalled server is reset.
	 * Without a key yet the job carries an empty one and Plesk runs on its trial until the key job sends it.
	 *
	 * @param mixed $job     Job values.
	 * @param mixed $server  Server.
	 * @param mixed $licence Licence resource.
	 * @return mixed
	 */
	public static function install_job( $job, $server, $licence ) {
		if ( ! is_array( $job ) || ! $licence instanceof Resource || ! $server instanceof Resource || self::APP !== (string) $server->get( 'app_id' ) || ! self::is_plesk( $licence ) ) {
			return $job;
		}

		if ( 1 === (int) $licence->get( 'plesk_hwid_reset' ) && (int) $licence->get( 'plesk_key_id' ) > 0 ) {
			$reply = self::client()->request( 'POST', '/licenses/hwids/reset/plesk/' . (int) $licence->get( 'plesk_key_id' ), array( 'key_id' => (int) $licence->get( 'plesk_key_id' ), 'product' => 'plesk' ) );

			if ( is_wp_error( $reply ) && self::transient( $reply ) ) {
				return StepResult::poll( 60, 'app: waiting to reset the Plesk licence', 900 );
			}

			// A refusal (nothing to reset) must not stop the install: it goes on and the key job checks the result.
			$licence->set( array( 'plesk_hwid_reset' => null ) );
		}

		$job['licence']['key'] = self::key_of( $licence );

		return $job;
	}

	/**
	 * The app reported ready: a key that was not in the job, or did not install, is looked at.
	 *
	 * @param mixed $server Server.
	 */
	public static function installed( $server ): void {
		if ( ! $server instanceof Resource || self::APP !== (string) $server->get( 'app_id' ) ) {
			return;
		}

		$licence = AppLicence::current( $server );

		if ( $licence && self::is_plesk( $licence ) && (int) $licence->get( 'plesk_key_id' ) > 0 ) {
			self::schedule( self::KEY_HOOK, $licence->id(), 0 );
		}
	}

	/**
	 * Job: makes sure the server has the key. Reads the guest's answer first (the install may have put the key on), and
	 * writes the key file only when it has not been confirmed.
	 *
	 * @param int $id Licence resource id.
	 */
	public static function run_key( int $id ): void {
		$licence = Resource::find( $id );

		if ( ! $licence || ! self::is_plesk( $licence ) || (int) $licence->get( 'plesk_key_id' ) < 1 || 'ended' === AppLicence::state( $licence ) ) {
			return;
		}

		$key = self::key_of( $licence );

		if ( '' === $key ) {
			LicenceVendor::ask_key( $licence, new PleskFamily() );

			return;
		}

		$server = AppLicence::server_of( $licence );
		$hash   = hash( 'sha256', $key );
		unset( $key );

		if ( ! $server || self::APP !== (string) $server->get( 'app_id' ) || $hash === (string) $licence->get( 'plesk_key_job' ) ) {
			return;
		}

		// A key that is different from the one the failed attempts used (the key was corrected since) gets its own tries.
		$tried = (string) $licence->get( 'plesk_key_tried' );

		if ( '' !== $tried && $tried !== $hash ) {
			$licence->set( array( 'plesk_key_pushes' => 0, 'plesk_key_state' => null, 'plesk_key_job' => null, 'plesk_key_tries' => null ) );
		}

		$app_state = (string) $server->get( 'app_state' );

		if ( 'ready' !== $app_state ) {
			// Nothing to hand over to until the app is there; its report schedules this job again.
			if ( in_array( $app_state, array( 'pending', 'installing', 'unknown' ), true ) ) {
				self::schedule( self::KEY_HOOK, $id, 600 );
			}

			return;
		}

		$guest = AppWww::guest( $server );

		if ( null === $guest ) {
			self::schedule( self::KEY_HOOK, $id, 300 );

			return;
		}

		$read   = Apps::read_file( $guest['client'], $guest['node'], $guest['vmid'], self::STATUS_FILE );
		$status = null === $read ? null : json_decode( $read, true );

		if ( is_array( $status ) && hash_equals( $hash, (string) ( $status['hash'] ?? '' ) ) && 'ready' === ( $status['state'] ?? '' ) ) {
			$licence->set( array( 'plesk_key_job' => $hash, 'plesk_key_state' => 'installed' ) );

			return;
		}

		$answered = is_array( $status ) && hash_equals( $hash, (string) ( $status['hash'] ?? '' ) );
		$pushes   = (int) $licence->get( 'plesk_key_pushes' );
		$waiting  = 'applying' === (string) $licence->get( 'plesk_key_state' ) && time() - (int) $licence->get( 'plesk_key_at' ) < 600 && ! $answered;

		if ( $waiting ) {
			self::schedule( self::KEY_HOOK, $id, 60 );

			return;
		}

		if ( $pushes >= self::PUSHES ) {
			if ( 'failed' !== (string) $licence->get( 'plesk_key_state' ) ) {
				$licence->set( array( 'plesk_key_state' => 'failed' ) );
				self::alert( $licence, 'The Plesk key could not be installed on the server after ' . self::PUSHES . ' tries. Plesk runs on its trial. Check the server.' );

				// Loud, not silent: the server shows the reason (admin license box) and the admin is mailed about the SERVER too, because the
				// app reports ready although the licence that was paid for is not on it.
				AppAlerts::failure( $server, 'The Plesk licence is NOT installed on this server (Plesk runs on its trial): the key was refused ' . self::PUSHES . ' times by the server. The licence is ordered at Openprovider and paid.' );
			}

			return;
		}

		$key  = self::key_of( $licence );
		$base = '/nodes/' . rawurlencode( $guest['node'] ) . '/qemu/' . $guest['vmid'];
		$sent = $guest['client']->request( 'POST', $base . '/agent/file-write', array( 'file' => self::KEY_FILE, 'content' => wp_json_encode( array( 'v' => 1, 'hash' => $hash, 'key' => $key ) ) ) );
		unset( $key );

		if ( is_wp_error( $sent ) ) {
			self::schedule( self::KEY_HOOK, $id, 300 );

			return;
		}

		$licence->set( array( 'plesk_key_state' => 'applying', 'plesk_key_at' => time(), 'plesk_key_pushes' => $pushes + 1, 'plesk_key_tried' => $hash ) );
		self::schedule( self::KEY_HOOK, $id, 60 );
	}

	/**
	 * One pending job per licence and hook.
	 *
	 * @param string $hook  Hook.
	 * @param int    $id    Licence resource id.
	 * @param int    $delay Seconds.
	 */
	private static function schedule( string $hook, int $id, int $delay ): void {
		Jobs::schedule_once( time() + $delay, $hook, array( $id ) );
	}

	// -- Admin -----------------------------------------------------------------------------------

	/**
	 * Rows of the admin panel: the licence id at Openprovider and the key's state, never the key.
	 *
	 * @param mixed $rows    Rows.
	 * @param mixed $licence Licence resource.
	 * @return mixed
	 */
	public static function admin_rows( $rows, $licence ) {
		if ( ! is_array( $rows ) || ! $licence instanceof Resource || ! self::is_plesk( $licence ) ) {
			return $rows;
		}

		$key_id = (int) $licence->get( 'plesk_key_id' );

		$rows[ __( 'Openprovider licence id', 'beltoft-services' ) ] = $key_id > 0 ? (string) $key_id : ( (int) $licence->get( 'plesk_deleted' ) > 0 ? __( 'deleted', 'beltoft-services' ) : __( 'not ordered', 'beltoft-services' ) );
		$rows[ __( 'Openprovider item', 'beltoft-services' ) ]       = (string) $licence->get( 'plesk_item' );
		$rows[ __( 'Plesk key', 'beltoft-services' ) ]               = '' === (string) $licence->get( 'plesk_key' ) ? __( 'not fetched', 'beltoft-services' ) : ( 'installed' === (string) $licence->get( 'plesk_key_state' ) ? __( 'installed on the server', 'beltoft-services' ) : __( 'kept, not confirmed on the server', 'beltoft-services' ) );

		return $rows;
	}
}
