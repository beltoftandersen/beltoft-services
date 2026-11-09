<?php
/**
 * The Openprovider licence behind a standalone licence key (a Plesk edition or an add-on such as CloudLinux).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Licences;

use BeltoftLicenses\Licenses;
use BeltoftServices\Alerts;
use BeltoftServices\Crypto;
use BeltoftServices\Domains\Client as RegistrarClient;
use BeltoftServices\Domains\Clients as RegistrarClients;
use BeltoftServices\Domains\LiveClient;
use BeltoftServices\Domains\SpendGuard;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\AppLicence;
use BeltoftServices\Proxmox\PleskLicence;
use BeltoftServices\Proxmox\SshKeyField;
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
 * lic_key_kind (file or code), lic_state (ordering, waiting, ready), lic_shape, lic_tries, lic_deleted, lic_wait_until.
 */
final class KeyOrder {

	/** Jobs: ask for the key again, and repeat a call to Openprovider that failed. */
	public const KEY_HOOK  = 'beltoft_services_licence_key';
	public const SYNC_HOOK = 'beltoft_services_licence_sync';

	/** Seconds the licence waits for the licence of its parent before it is ordered on its own. */
	private const WAIT = HOUR_IN_SECONDS;

	/** Asks before staff are told. */
	private const TRIES = 12;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::KEY_HOOK, array( __CLASS__, 'run_key' ) );
		add_action( self::SYNC_HOOK, array( __CLASS__, 'run_sync' ) );
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

	private static function client(): RegistrarClient {
		return RegistrarClients::registrar();
	}

	private static function transient( \WP_Error $error ): bool {
		return LiveClient::is_transient( $error ) || 'credentials_missing' === $error->get_error_code();
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
	 * The licence key, decrypted, or '' when there is none.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function key_text( Resource $r ): string {
		$stored = (string) $r->get( 'lic_key' );
		$plain  = '' === $stored ? null : Crypto::decrypt( $stored );

		return is_string( $plain ) ? PleskLicence::normalise_key( $plain ) : '';
	}

	/**
	 * The title of the vendor licence: fixed by the licence id, so a crash can find it again.
	 *
	 * @param Resource $r Licence resource.
	 */
	private static function title( Resource $r ): string {
		$stored = (string) $r->get( 'lic_title' );

		if ( '' !== $stored ) {
			return $stored;
		}

		$title = sprintf( 'licence %d %s', (int) ( $r->license()['id'] ?? 0 ), (string) $r->get( 'lic_ip' ) );

		return substr( preg_replace( '/[^A-Za-z0-9 ._-]/', '', $title ) ?? $title, 0, 100 );
	}

	/**
	 * Is the paid line at least the cost at the vendor? Null when covered, else why not.
	 *
	 * @param Resource $r   Licence resource.
	 * @param string   $key Catalogue key.
	 */
	public static function spend_problem( Resource $r, string $key ): ?string {
		$cost = KeyCatalog::costs()[ $key ] ?? null;

		if ( null === $cost ) {
			return 'fake' === RegistrarClients::mode() ? null : 'The Openprovider cost of this licence is not set.';
		}

		$item = SpendGuard::item_of_resource( $r );

		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return 'The paid order line for this licence is unavailable.';
		}

		$order = $item->get_order();

		if ( ! $order || 'EUR' !== strtoupper( (string) $order->get_currency() ) ) {
			return 'The order is not in EUR; the Openprovider cost cannot be compared.';
		}

		return (float) $item->get_total() + 0.01 < $cost ? 'The order was paid below the Openprovider cost.' : null;
	}

	/**
	 * The same check for a switch to another key: the variation's price excluding VAT must cover its cost.
	 *
	 * @param string                  $key       New catalogue key.
	 * @param \WC_Product|false|null  $variation The new variation.
	 */
	private static function switch_problem( string $key, $variation ): ?string {
		$cost = KeyCatalog::costs()[ $key ] ?? null;

		if ( null === $cost ) {
			return 'fake' === RegistrarClients::mode() ? null : 'The Openprovider cost of this licence is not set.';
		}

		return $variation instanceof \WC_Product && (float) wc_get_price_excluding_tax( $variation ) + 0.01 >= $cost ? null : 'The price of this licence is below the Openprovider cost.';
	}

	/**
	 * What a failed call means for a step: a temporary one is tried again soon, any other slowly and an admin is told.
	 *
	 * @param Resource  $r     Licence resource.
	 * @param \WP_Error $error Error.
	 * @param string    $text  What was tried.
	 */
	private static function failure( Resource $r, \WP_Error $error, string $text ): StepResult {
		if ( self::transient( $error ) ) {
			return StepResult::retry( 300, $text );
		}

		$data = $error->get_error_data();
		Alerts::admin( $r, $text . ' (' . $error->get_error_code() . ( is_array( $data ) && ! empty( $data['desc'] ) ? ': ' . $data['desc'] : '' ) . ')' );

		return StepResult::retry( 3600, $text );
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

		if ( $owner && SshKeyField::DRIVER === $owner->get( 'driver' ) && (int) ( $owner->license()['user_id'] ?? 0 ) === $user ) {
			$licence = AppLicence::current( $owner );
			$id      = $licence ? (int) $licence->get( 'plesk_key_id' ) : 0;

			if ( $id > 0 ) {
				return $id;
			}

			if ( $licence && 'ended' !== AppLicence::state( $licence ) ) {
				return -1;
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
	 * Live licence resources of this driver bound to an address (any customer), newest first.
	 *
	 * @param string $ip Address.
	 * @return Resource[]
	 */
	public static function live( string $ip ): array {

		$out = array();

		foreach ( Resource::find_by_meta( 'lickey', 'lic_ip', $ip, 'DESC' ) as $id ) {
			$found = Resource::find( (int) $id );

			if ( $found && KeyDriver::ID === $found->get( 'driver' ) && $ip === (string) $found->get( 'lic_ip' ) && ! in_array( (string) $found->get( 'state' ), array( 'failed', 'deleting', 'deleted' ), true ) ) {
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

		$title = self::title( $r );

		if ( $title !== (string) $r->get( 'lic_title' ) && ! $r->set( array( 'lic_title' => $title ) ) ) {
			return StepResult::retry( 60, 'The licence title could not be stored.' );
		}

		$client = self::client();
		$found  = $client->request( 'GET', '/licenses/plesk', array(), array( 'title_pattern' => $title, 'limit' => 10 ) );

		if ( is_wp_error( $found ) ) {
			return self::failure( $r, $found, 'Openprovider could not be asked for an earlier licence.' );
		}

		$existing = 0;

		foreach ( (array) ( $found['results'] ?? array() ) as $row ) {
			if ( is_array( $row ) && (int) ( $row['key_id'] ?? 0 ) > 0 && (string) ( $row['title'] ?? '' ) === $title ) {
				$existing = (int) $row['key_id'];
				break;
			}
		}

		if ( 0 === $existing ) {
			// Money is spent here: only when the paid line covers the cost. A licence found above (a crash-resume) never gets here.
			$problem = self::spend_problem( $r, $key );

			if ( null !== $problem ) {
				Alerts::admin( $r, $problem . ' No licence was ordered.' );

				return StepResult::fail( $problem );
			}

			$body = array(
				'title'               => $title,
				'comment'             => sprintf( 'Beltoft Services licence %d', (int) ( $r->license()['id'] ?? 0 ) ),
				'items'               => array( $item ),
				'period'              => 1,
				'ip_address_binding'  => $ip,
				'restrict_ip_binding' => true,
			);

			if ( $parent > 0 ) {
				$body['parent_key_id'] = $parent;
			}

			$created = $client->request( 'POST', '/licenses/plesk', $body );

			if ( is_wp_error( $created ) ) {
				// A call that timed out may still have gone through: the next try finds it by its title.
				return self::failure( $r, $created, 'Openprovider did not order the licence.' );
			}

			$existing = (int) ( $created['key_id'] ?? 0 );

			if ( $existing < 1 ) {
				return StepResult::retry( 300, 'Openprovider returned no licence id.' );
			}
		}

		if ( ! $r->set( array( 'lic_key_id' => $existing, 'lic_item' => $item, 'lic_parent' => max( 0, $parent ) ) ) ) {
			// The next try finds the licence by its title.
			return StepResult::retry( 60, 'The Openprovider licence id could not be stored.' );
		}

		return $existing;
	}

	/**
	 * Asks the vendor for the key and keeps it encrypted. Also records the NAMES of the fields the vendor answers with,
	 * so staff can see how a kind of licence is delivered. A key can come as a Plesk key file (XML, possibly base64) or
	 * as a short activation code; a code is read from the key call or from the licence's activation_code field.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function fetch_key( Resource $r ): bool {
		$id = (int) $r->get( 'lic_key_id' );

		if ( $id < 1 || ! Crypto::available() ) {
			return false;
		}

		$client = self::client();
		$reply  = $client->request( 'GET', '/licenses/plesk/key/' . $id );
		$info   = $client->request( 'GET', '/licenses/plesk/' . $id );
		$shape  = array();

		foreach ( array( 'key' => $reply, 'licence' => $info ) as $label => $answer ) {
			if ( is_array( $answer ) ) {
				$shape[] = $label . ':' . implode( '|', array_slice( array_map( 'strval', array_keys( $answer ) ), 0, 30 ) );
			}
		}

		$key = is_array( $reply ) ? PleskLicence::normalise_key( (string) ( $reply['key'] ?? '' ) ) : '';

		if ( '' === $key && is_array( $info ) && is_string( $info['activation_code'] ?? null ) ) {
			$key = trim( $info['activation_code'] );
		}

		$meta = array( 'lic_shape' => substr( implode( ' ', $shape ), 0, 400 ) );

		if ( '' === $key || strlen( $key ) > 60000 ) {
			$r->set( $meta );

			return false;
		}

		$meta['lic_key']      = Crypto::encrypt( $key );
		$meta['lic_key_kind'] = '<' === $key[0] ? 'file' : 'code';
		$meta['lic_state']    = 'ready';

		return $r->set( $meta );
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

		if ( self::fetch_key( $r ) ) {
			return;
		}

		$tries = (int) $r->get( 'lic_tries' ) + 1;
		$r->set( array( 'lic_tries' => $tries, 'lic_state' => 'waiting' ) );

		if ( self::TRIES === $tries ) {
			Alerts::admin( $r, 'Openprovider has not handed over a key for this licence after ' . self::TRIES . ' tries. Open the licence at Openprovider and copy the key to the customer. Fields answered: ' . (string) $r->get( 'lic_shape' ) . '.' );
		}

		if ( $tries < 3 * self::TRIES ) {
			self::schedule( self::KEY_HOOK, $id, min( 6 * HOUR_IN_SECONDS, 120 * $tries ) );
		}
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

		$reply = self::client()->request( 'PUT', '/licenses/plesk/' . $id, array( 'items' => array( $item ) ) );

		if ( is_wp_error( $reply ) ) {
			return self::failure( $r, $reply, 'Openprovider did not change the licence.' );
		}

		$r->set( array( 'lic_item' => $item, 'lic_key' => null, 'lic_state' => 'waiting', 'lic_tries' => 0 ) );

		if ( ! self::fetch_key( $r ) ) {
			self::schedule( self::KEY_HOOK, $r->id(), 120 );
		}

		return StepResult::done();
	}

	/**
	 * Deletes the vendor licence. Safe to run again; a licence that is gone counts as deleted. A failure is repeated by a job.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function release( Resource $r ): bool {
		$id = (int) $r->get( 'lic_key_id' );

		if ( $id < 1 ) {
			return true;
		}

		$reply = self::client()->request( 'DELETE', '/licenses/plesk/' . $id );

		if ( ! is_wp_error( $reply ) || PleskLicence::gone( $reply ) ) {
			$r->set( array( 'lic_key_id' => null, 'lic_key' => null, 'lic_state' => null, 'lic_deleted' => time(), 'lic_tries' => null ) );

			return true;
		}

		$tries = (int) $r->get( 'lic_tries' ) + 1;
		$r->set( array( 'lic_tries' => $tries ) );

		if ( self::TRIES === $tries ) {
			Alerts::admin( $r, 'Openprovider did not answer the licence deletion after ' . self::TRIES . ' tries (' . $reply->get_error_code() . '). Delete the licence at Openprovider, or it keeps billing.' );
		}

		if ( $tries < 3 * self::TRIES ) {
			self::schedule( self::SYNC_HOOK, $r->id(), min( DAY_IN_SECONDS, 300 * $tries * $tries ) );
		}

		return false;
	}

	/**
	 * Job: a deletion that failed is made again.
	 *
	 * @param int $id Licence resource id.
	 */
	public static function run_sync( int $id ): void {
		$r = Resource::find( $id );

		if ( $r && KeyDriver::ID === $r->get( 'driver' ) && self::ended( $r ) ) {
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
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$waiting = as_get_scheduled_actions( array( 'hook' => $hook, 'args' => array( $id ), 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ), 'ids' );

		if ( ! $waiting ) {
			as_schedule_single_action( time() + $delay, $hook, array( $id ), Jobs::GROUP );
		}
	}
}
