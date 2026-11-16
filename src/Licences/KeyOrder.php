<?php
/**
 * The Openprovider licence behind a standalone licence key (a Plesk edition or an add-on such as CloudLinux).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Licences;

use BeltoftLicenses\Licenses;
use BeltoftServices\Alerts;
use BeltoftServices\Background;
use BeltoftServices\Crypto;
use BeltoftServices\Domains\LicenceVendor;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Apps\PleskLicence;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One licence resource (driver licence-key) keeps one Openprovider licence, bound to the customer's server address,
 * through the registrar client of the domains feature (the fake in tests and on staging, the live client only with
 * BELTOFT_SERVICES_DOMAINS_LIVE). Same rules as PleskLicence:
 * - the order is found by its title before one is created, so a crash never orders twice;
 * - money is only spent when the paid line covers the cost of the key (setting licence_costs); an unknown cost is
 *   refused except on the fake client;
 * - a licence on hold is kept (it is billed monthly anyway), a licence that ended or a service that is deleted is deleted
 *   at the vendor (404 counts as done; a failure is repeated by a job and an admin is told once);
 * - the key is stored encrypted and never logged, mailed or put in an alert.
 *
 * An add-on is ordered with the Plesk licence of the same customer and address as its parent (`parent_key_id`) when
 * there is one (a standalone edition, or the Plesk app of one of our servers), else on its own (the panel orders a
 * third-party extension key without a Plesk licence).
 *
 * Delivery is defensive, because the first real order of each kind shows how the vendor hands the key over: a key that
 * is not there yet leaves the licence waiting (the customer reads "being prepared"), a job asks again, and after
 * TRIES asks staff are told with the NAMES of the fields the vendor answered (never their values).
 *
 * Meta: lic_ip, lic_attach, lic_server (from checkout); lic_key_id, lic_title, lic_item, lic_parent, lic_key (encrypted),
 * lic_key_kind (file or code), lic_state (ordering, waiting, ready), lic_shape, lic_tries (key asks), lic_delete_tries, lic_deleted, lic_wait_until.
 */
final class KeyOrder {

	/** Jobs: ask for the key again, and repeat a call to Openprovider that failed. */
	public const KEY_HOOK  = 'beltoft_services_licence_key';
	public const SYNC_HOOK = 'beltoft_services_licence_sync';

	/** Seconds the licence waits for the licence of its parent before it is ordered on its own. */
	private const WAIT = HOUR_IN_SECONDS;

	/** Asks before staff are told. */
	private const TRIES = LicenceVendor::TRIES;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'state_changed' ), 20, 3 );
		add_action( self::KEY_HOOK, array( __CLASS__, 'run_key' ) );
		add_action( self::SYNC_HOOK, array( __CLASS__, 'run_sync' ) );
		Background::declare(
			self::KEY_HOOK,
			array(
				'due'      => array( __CLASS__, 'key_due' ),
				'resource' => true,
				'label'    => 'Licence key fetch',
			)
		);
		Background::declare(
			self::SYNC_HOOK,
			array(
				'due'      => array( __CLASS__, 'sync_due' ),
				'resource' => true,
				'label'    => 'Licence deletion at the vendor',
			)
		);
	}

	/**
	 * A licence went to failed before anything was ordered: its subscription is cancelled (LicenceVendor::on_state).
	 *
	 * @param mixed $r   Resource.
	 * @param mixed $old Old state.
	 * @param mixed $new New state.
	 */
	public static function state_changed( $r, $old, $new ): void {
		unset( $old );

		if ( $r instanceof Resource && is_string( $new ) ) {
			LicenceVendor::on_state( $r, $new, new KeyFamily() );
		}
	}

	// -- Helpers ---------------------------------------------------------------------------------

	/**
	 * The catalogue key of a licence: the tier, with -physical for an edition on a physical server.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function key_of( Resource $r ): string {
		$plan = (array) $r->get( 'plan' );
		$tier = (string) ( $plan['lic_tier'] ?? '' );

		return KeyCatalog::is_edition( $tier ) && 'physical' === (string) ( $plan['lic_server'] ?? '' ) ? $tier . '-physical' : $tier;
	}

	/**
	 * Has the licence ended (so the vendor's licence must go)?
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function ended( Resource $r ): bool {
		if ( in_array( (string) $r->get( 'state' ), array( 'failed', 'deleting', 'deleted' ), true ) ) {
			return true;
		}

		$status = Licenses::effective_status( $r->license() );

		return Licenses::STATUS_ACTIVE !== $status && Licenses::STATUS_SUSPENDED !== $status;
	}

	/**
	 * Must the vendor's licence go now? When the licence lapsed or is gone for good: its resource is being or was deleted,
	 * its provision failed after the order or its delete failed, or its licence expired or was revoked (owner decision
	 * 2026-11-16: no grace days, a customer who pays later gets a new key). A licence on hold for a payment retry
	 * (suspended) is kept, and a failed plan change, pause or resume never deletes the licence of a customer who still
	 * has it paid.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function release_due( Resource $r ): bool {
		$state = (string) $r->get( 'state' );

		if ( in_array( $state, array( 'deleting', 'deleted' ), true ) ) {
			return true;
		}

		if ( 'failed' === $state && in_array( (string) $r->get( 'failed_op' ), array( 'provision', 'delete' ), true ) ) {
			return true;
		}

		return in_array( Licenses::effective_status( $r->license() ), array( Licenses::STATUS_EXPIRED, Licenses::STATUS_REVOKED ), true );
	}

	/**
	 * The licence key, decrypted, or '' when there is none.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function key_text( Resource $r ): string {
		$stored = (string) $r->get( 'lic_key' );
		$plain  = '' === $stored ? null : Crypto::decrypt( $stored );

		return is_string( $plain ) ? LicenceVendor::normalise_key( $plain ) : '';
	}

	/**
	 * Is the paid line at least the cost at the vendor? Null when covered, else why not.
	 *
	 * @param Resource $r   Licence resource.
	 * @param string   $key Catalogue key.
	 */
	public static function spend_problem( Resource $r, string $key ): ?string {
		return LicenceVendor::spend_problem( $r, new KeyFamily(), $key, KeyCatalog::costs() );
	}

	/**
	 * The same check for a switch to another key: the variation's price excluding VAT must cover its cost.
	 *
	 * @param string                  $key       New catalogue key.
	 * @param \WC_Product|false|null  $variation The new variation.
	 */
	private static function switch_problem( string $key, $variation ): ?string {
		return LicenceVendor::switch_problem( new KeyFamily(), $key, KeyCatalog::costs(), $variation );
	}

	/**
	 * What a failed call means for a step: a temporary one is tried again soon, any other slowly and an admin is told.
	 *
	 * @param Resource  $r     Licence resource.
	 * @param \WP_Error $error Error.
	 * @param string    $text  What was tried.
	 */
	private static function failure( Resource $r, \WP_Error $error, string $text ): StepResult {
		return LicenceVendor::failure( $r, $error, $text );
	}

	// -- The parent ------------------------------------------------------------------------------

	/**
	 * The vendor id of the Plesk licence an add-on belongs to. 0 when there is none; -1 when there is one that has no
	 * vendor id yet (it is still being ordered).
	 *
	 * @param Resource $r Add-on resource.
	 */
	public static function parent_id( Resource $r ): int {
		$user = (int) ( $r->license()['user_id'] ?? 0 );
		$ip   = (string) $r->get( 'lic_ip' );

		// The customer chose one of our own Plesk servers: its Plesk licence is the parent.
		$attach = (int) $r->get( 'lic_attach' );
		$owner  = $attach > 0 ? Resource::for_license( $attach ) : null;

		if ( $owner && ProxmoxDriver::ID === $owner->get( 'driver' ) && (int) ( $owner->license()['user_id'] ?? 0 ) === $user ) {
			$id = PleskLicence::vendor_id_for_server( $owner );

			if ( 0 !== $id ) {
				return $id;
			}
		}

		// An edition of ours for the same address and customer.
		$edition = '' === $ip ? null : self::edition_for_ip( $user, $ip );

		if ( $edition ) {
			$id = (int) $edition->get( 'lic_key_id' );

			return $id > 0 ? $id : -1;
		}

		return 0;
	}

	/**
	 * The live standalone edition of a customer for an address.
	 *
	 * @param int    $user_id Customer.
	 * @param string $ip      Address.
	 */
	public static function edition_for_ip( int $user_id, string $ip ): ?Resource {
		foreach ( self::live( $ip ) as $found ) {
			if ( (int) ( $found->license()['user_id'] ?? 0 ) === $user_id && KeyCatalog::is_edition( self::key_of( $found ) ) ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Live licence resources of this driver bound to an address (any customer), newest first. A licence that has ended (see ended()) does not count.
	 *
	 * @param string $ip Address.
	 * @return Resource[]
	 */
	public static function live( string $ip ): array {

		$out = array();

		foreach ( Resource::find_by_meta( 'lickey', 'lic_ip', $ip, 'DESC' ) as $id ) {
			$found = Resource::find( (int) $id );

			if ( $found && KeyDriver::ID === $found->get( 'driver' ) && $ip === (string) $found->get( 'lic_ip' ) && ! self::ended( $found ) ) {
				$out[] = $found;
			}
		}

		return $out;
	}

	// -- Provision -------------------------------------------------------------------------------

	/**
	 * Orders the vendor licence, once, and fetches its key.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function provision( Resource $r ): StepResult {
		$key  = self::key_of( $r );
		$item = KeyCatalog::items()[ $key ] ?? '';
		$ip   = (string) $r->get( 'lic_ip' );

		if ( '' === $key ) {
			return StepResult::fail( 'The product names no licence key.' );
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return StepResult::fail( 'The order carries no valid server address, so no licence was ordered.' );
		}

		if ( '' === $item ) {
			// Not a failure of the order: the owner sets the item and the next try orders it.
			Alerts::admin( $r, 'No Openprovider item is set for the licence "' . $key . '" (Settings, Services, Domains, Licence key items). Nothing was ordered.' );

			return StepResult::retry( 3600, 'No Openprovider item is set for this licence.' );
		}

		if ( ! Crypto::available() ) {
			return StepResult::retry( 3600, 'The encryption key is not configured, so no licence is ordered.' );
		}

		$key_id = (int) $r->get( 'lic_key_id' );

		if ( $key_id < 1 ) {
			$made = self::create( $r, $key, $item, $ip );

			if ( $made instanceof StepResult ) {
				return $made;
			}

			$key_id = $made;
		}

		$have = '' !== (string) $r->get( 'lic_key' ) || self::fetch_key( $r );

		if ( ! $have ) {
			self::schedule( self::KEY_HOOK, $r->id(), 120 );
		}

		return StepResult::done( array( 'steps' => array( 'ready' ), 'lic_key_id' => $key_id, 'lic_item' => $item, 'lic_state' => $have ? 'ready' : 'waiting' ) );
	}

	/**
	 * Finds or creates the licence. Its id is stored before anything else is asked.
	 *
	 * @param Resource $r    Licence resource.
	 * @param string   $key  Catalogue key.
	 * @param string   $item Vendor item.
	 * @param string   $ip   Server address.
	 * @return int|StepResult The vendor id, or what the step must return.
	 */
	private static function create( Resource $r, string $key, string $item, string $ip ) {
		$parent = 0;

		if ( ! KeyCatalog::is_edition( $key ) ) {
			$parent = self::parent_id( $r );

			if ( -1 === $parent ) {
				$until = (int) $r->get( 'lic_wait_until' );
				$until = $until > 0 ? $until : time() + self::WAIT;

				if ( ! $r->set( array( 'lic_wait_until' => $until ) ) ) {
					return StepResult::retry( 60, 'The wait deadline could not be stored.' );
				}

				if ( time() < $until ) {
					return StepResult::wait( 120, 'Waiting for the Plesk licence of this server.', $until );
				}

				// The Plesk licence did not come: the add-on is ordered on its own.
				$parent = 0;
			}
		}

		return LicenceVendor::find_or_create(
			$r,
			new KeyFamily(),
			$key,
			$item,
			$ip,
			KeyCatalog::costs(),
			array(
				'seed'    => (string) $r->get( 'lic_ip' ),
				'comment' => sprintf( 'Beltoft Services licence %d', (int) ( $r->license()['id'] ?? 0 ) ),
				'parent'  => $parent,
				'meta'    => array( 'lic_parent' => max( 0, $parent ) ),
			)
		);
	}

	/**
	 * Asks the vendor for the key and keeps it encrypted. Also records the NAMES of the fields the vendor answers with,
	 * so staff can see how a kind of licence is delivered. A key can come as a Plesk key file (XML, possibly base64) or
	 * as a short activation code; a code is read from the key call or from the licence's activation_code field.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function fetch_key( Resource $r ): bool {
		return LicenceVendor::fetch_key( $r, new KeyFamily() );
	}

	/**
	 * The watchdog's list (Background): a licence that waits for its key and has no job. The business limit of the asks
	 * (3 x TRIES) is respected here, so a licence given up on is not asked again.
	 *
	 * @return array<int, array<int, int>>
	 */
	public static function key_due(): array {
		// Only the licences still waiting for a key (SQL), read in one query.
		return LicenceVendor::key_due( new KeyFamily(), Resource::find_many( Resource::find_by_meta( KeyDriver::KIND, 'lic_state', 'waiting' ) ) );
	}

	/**
	 * The watchdog's list (Background): a licence whose vendor licence must go (release_due) and whose deletion has not gone
	 * through. Narrowed in SQL to licences that still hold a vendor key id (a finished deletion clears it).
	 *
	 * @return array<int, array<int, int>>
	 */
	public static function sync_due(): array {
		return LicenceVendor::delete_due( new KeyFamily(), Resource::find_many( Resource::ids_with_meta_value( 'lic_key_id', array(), KeyDriver::KIND ) ) );
	}

	/**
	 * Job: the key was not there when the licence was ordered. Asks again; staff are told after TRIES asks.
	 *
	 * @param int $id Licence resource id.
	 */
	public static function run_key( int $id ): void {
		$r = Resource::find( $id );

		if ( ! $r || KeyDriver::ID !== $r->get( 'driver' ) || (int) $r->get( 'lic_key_id' ) < 1 || '' !== (string) $r->get( 'lic_key' ) || self::ended( $r ) ) {
			return;
		}

		LicenceVendor::ask_key( $r, new KeyFamily() );
	}

	// -- Change and end --------------------------------------------------------------------------

	/**
	 * Makes the vendor match the licence after a plan change: the item of the new key, then its key again.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function switch_item( Resource $r ): StepResult {
		$id   = (int) $r->get( 'lic_key_id' );
		$key  = self::key_of( $r );
		$item = KeyCatalog::items()[ $key ] ?? '';

		if ( $id < 1 || '' === $item || $item === (string) $r->get( 'lic_item' ) ) {
			return StepResult::done();
		}

		$product   = (int) ( $r->license()['product_id'] ?? 0 );
		$variation = (int) ( $r->license()['variation_id'] ?? 0 );
		$problem   = self::switch_problem( $key, wc_get_product( $variation > 0 ? $variation : $product ) );

		if ( null !== $problem ) {
			Alerts::admin( $r, $problem . ' The licence was not changed.' );

			return StepResult::retry( 3600, $problem );
		}

		$error = LicenceVendor::change_item( $r, new KeyFamily(), $item );

		if ( null !== $error ) {
			return self::failure( $r, $error, 'Openprovider did not change the licence.' );
		}

		return StepResult::done();
	}

	/**
	 * Deletes the vendor licence. Safe to run again; a licence that is gone counts as deleted. A failure is repeated by a job.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function release( Resource $r ): bool {
		return LicenceVendor::release( $r, new KeyFamily() );
	}

	/**
	 * Job: a deletion that failed is made again.
	 *
	 * @param int $id Licence resource id.
	 */
	public static function run_sync( int $id ): void {
		$r = Resource::find( $id );

		if ( $r && KeyDriver::ID === $r->get( 'driver' ) && self::release_due( $r ) ) {
			self::release( $r );
		}
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
}
