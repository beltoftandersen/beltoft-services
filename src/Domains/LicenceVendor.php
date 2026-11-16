<?php
/**
 * The vendor licence at the registrar (Openprovider Plesk licence API) for the Plesk app licence and the standalone keys.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Alerts;
use BeltoftServices\Crypto;
use BeltoftServices\Currency;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One owner of the calls to the registrar's licence API that both licence families make: Apps\PleskLicence (the licence
 * behind a server's Plesk tier) and Licences\KeyOrder (a standalone licence key). It names no feature: what differs
 * between the families (meta names, release rule, texts) arrives as a LicenceFamily. Every call goes through
 * Clients::registrar(): the fake in tests and on staging, the live client only behind LiveGate.
 */
final class LicenceVendor {

	/** Key asks (and repeated calls) before an admin is told; the asks stop at three times this. */
	public const TRIES = 12;

	/**
	 * The registrar client (the fake, the sandbox or the live one, as Clients decides).
	 */
	public static function client(): Client {
		return Clients::registrar();
	}

	/**
	 * Is an error one that a later try may cure (a transport problem, a rate limit, a server error, a missing login)?
	 *
	 * @param \WP_Error $error Error.
	 */
	public static function transient( \WP_Error $error ): bool {
		return LiveClient::is_transient( $error ) || 'credentials_missing' === $error->get_error_code();
	}

	/**
	 * Does an error say the licence does not exist? Openprovider answers HTTP 404 with its own code op_3108 ("License not
	 * found"), which the client reports as op_3108, never as http_404.
	 *
	 * @param \WP_Error $error Error.
	 */
	public static function gone( \WP_Error $error ): bool {
		$data = $error->get_error_data();

		return 'op_3108' === $error->get_error_code() || 'http_404' === $error->get_error_code() || ( is_array( $data ) && 404 === (int) ( $data['status'] ?? 0 ) );
	}

	/**
	 * The key as the guest can install it. Openprovider answers with the Plesk key FILE base64 encoded (one long line of
	 * about 17 000 characters, no markup), which `plesk bin license --install` cannot read and the guest script refused
	 * (it takes the XML itself, or an activation code of up to 64 characters): a Plesk that stayed on its trial although
	 * the licence was paid. The XML is what is stored and sent; an activation code is left alone.
	 *
	 * @param string $key What Openprovider sent, or what was stored earlier.
	 */
	public static function normalise_key( string $key ): string {
		$key = trim( $key );

		if ( '' === $key || '<' === $key[0] || strlen( $key ) <= 64 || 1 !== preg_match( '/^[A-Za-z0-9+\/=\r\n]+$/D', $key ) ) {
			return $key;
		}

		$decoded = base64_decode( (string) preg_replace( '/\s+/', '', $key ), true );

		return false !== $decoded && 1 === preg_match( '/^\s*</', $decoded ) ? trim( $decoded ) : $key;
	}

	/**
	 * The title of the vendor licence: fixed by the licence id, so a crash can find it again. Once made it is stored in
	 * the family's title meta by the caller and read back from there.
	 *
	 * @param Resource      $r      Licence resource.
	 * @param LicenceFamily $family Family.
	 * @param string        $seed   What follows the licence id (the server's name, or the bound address).
	 */
	public static function title( Resource $r, LicenceFamily $family, string $seed ): string {
		$stored = (string) $r->get( $family->meta( 'title' ) );

		if ( '' !== $stored ) {
			return $stored;
		}

		$title = trim( sprintf( 'licence %d %s', (int) ( $r->license()['id'] ?? 0 ), $seed ) );

		return substr( preg_replace( '/[^A-Za-z0-9 ._-]/', '', $title ) ?? $title, 0, 100 );
	}

	/**
	 * Is the paid line of this licence at least the cost at the vendor? Null when the spend is covered, else why not. An
	 * unknown cost is refused, except on the fake client. A usage billed licence is checked against its cap by the family.
	 *
	 * @param Resource             $r      Licence resource.
	 * @param LicenceFamily        $family Family.
	 * @param string               $key    Tier or catalogue key.
	 * @param array<string, float> $costs  Key => cost.
	 */
	public static function spend_problem( Resource $r, LicenceFamily $family, string $key, array $costs ): ?string {
		$cost = $costs[ $key ] ?? null;

		if ( null === $cost ) {
			return 'fake' === Clients::mode() ? null : 'The Openprovider cost of this ' . $family->subject() . ' is not set.';
		}

		if ( $family->uses_cap( $r ) ) {
			return $family->cap_problem( $r, $cost );
		}

		$item = SpendGuard::item_of_resource( $r );

		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return 'The paid order line for this ' . $family->licence_noun() . ' is unavailable.';
		}

		$order = $item->get_order();

		$paid = $order ? Currency::to_cost( SpendGuard::paid( $item ), (string) $order->get_currency() ) : null;

		if ( null === $paid ) {
			return 'The order is not in EUR; the Openprovider cost cannot be compared.';
		}

		return $paid + 0.01 < $cost ? 'The order was paid below the Openprovider cost.' : null;
	}

	/**
	 * The same check for a switch to another key or tier: the price of the new variation excluding VAT must cover its
	 * cost. A usage billed licence is checked like a new order (its cap).
	 *
	 * @param LicenceFamily           $family    Family.
	 * @param string                  $key       New tier or catalogue key.
	 * @param array<string, float>    $costs     Key => cost.
	 * @param \WC_Product|false|null  $variation The new variation, when known.
	 * @param Resource|null           $r         Licence resource (only needed to tell a usage billed licence).
	 */
	public static function switch_problem( LicenceFamily $family, string $key, array $costs, $variation, ?Resource $r = null ): ?string {
		$cost = $costs[ $key ] ?? null;

		if ( null === $cost ) {
			return 'fake' === Clients::mode() ? null : 'The Openprovider cost of this ' . $family->subject() . ' is not set.';
		}

		if ( null !== $r && $family->uses_cap( $r ) ) {
			return self::spend_problem( $r, $family, $key, $costs );
		}

		// The variation's price is read in the request's currency; in another one it is compared at the guard rate, and refused without one.
		$price = $variation instanceof \WC_Product ? Currency::price_to_cost( (float) wc_get_price_excluding_tax( $variation ), Currency::current() ) : null;

		return null !== $price && $price + 0.01 >= $cost ? null : 'The price of this ' . $family->subject() . ' is below the Openprovider cost.';
	}

	/**
	 * What a failed vendor call means for a step: a temporary one is tried again soon, any other slowly and an admin is told.
	 *
	 * @param Resource  $r     Licence resource.
	 * @param \WP_Error $error Error.
	 * @param string    $text  What was tried.
	 */
	public static function failure( Resource $r, \WP_Error $error, string $text ): StepResult {
		if ( self::transient( $error ) ) {
			return StepResult::retry( 300, $text );
		}

		$data = $error->get_error_data();
		Alerts::admin( $r, $text . ' (' . $error->get_error_code() . ( is_array( $data ) && ! empty( $data['desc'] ) ? ': ' . $data['desc'] : '' ) . ')' );

		return StepResult::retry( 3600, $text );
	}

	/**
	 * Finds or orders the vendor licence. The order is looked up by its title first, so a crash after the call never orders a
	 * second licence; money is spent only when the paid line covers the cost (never for a licence that was found); and the
	 * vendor id is stored before anything else is asked.
	 *
	 * What the caller decides first (arguments, address, waits, the item) stays with the caller. `$spec` carries what the
	 * order is made of: `seed` (what follows the licence id in the title), `comment` (the order's comment), `parent` (the
	 * vendor id of the licence an add-on belongs to, 0 for none) and `meta` (more meta to store with the vendor id).
	 *
	 * @param Resource             $r      Licence resource.
	 * @param LicenceFamily        $family Family.
	 * @param string               $key    Tier or catalogue key (for the cost).
	 * @param string               $item   Vendor item.
	 * @param string               $ip     Address the licence is bound to.
	 * @param array<string, float> $costs  Key => cost.
	 * @param array<string, mixed> $spec   seed, comment, parent, meta.
	 * @return int|StepResult The vendor id, or what the step must return.
	 */
	public static function find_or_create( Resource $r, LicenceFamily $family, string $key, string $item, string $ip, array $costs, array $spec ) {
		$noun   = $family->licence_noun();
		$title  = self::title( $r, $family, (string) ( $spec['seed'] ?? '' ) );
		$parent = (int) ( $spec['parent'] ?? 0 );

		if ( $title !== (string) $r->get( $family->meta( 'title' ) ) && ! $r->set( array( $family->meta( 'title' ) => $title ) ) ) {
			return StepResult::retry( 60, 'The licence title could not be stored.' );
		}

		$client = self::client();
		$found  = $client->request( 'GET', '/licenses/plesk', array(), array( 'title_pattern' => $title, 'limit' => 10 ) );

		if ( is_wp_error( $found ) ) {
			return self::failure( $r, $found, 'Openprovider could not be asked for an earlier ' . $noun . '.' );
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
			$problem = self::spend_problem( $r, $family, $key, $costs );

			if ( null !== $problem ) {
				Alerts::admin( $r, $problem . ' No ' . $noun . ' was ordered.' );

				return StepResult::fail( $problem );
			}

			$body = array(
				'title'               => $title,
				'comment'             => (string) ( $spec['comment'] ?? '' ),
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
				return self::failure( $r, $created, 'Openprovider did not order the ' . $noun . '.' );
			}

			$existing = (int) ( $created['key_id'] ?? 0 );

			if ( $existing < 1 ) {
				return StepResult::retry( 300, 'Openprovider returned no licence id.' );
			}
		}

		if ( ! $r->set( array( $family->meta( 'key_id' ) => $existing, $family->meta( 'item' ) => $item ) + (array) ( $spec['meta'] ?? array() ) ) ) {
			// The next try finds the licence by its title.
			return StepResult::retry( 60, 'The Openprovider licence id could not be stored.' );
		}

		return $existing;
	}

	/**
	 * Asks the vendor for the key and keeps it encrypted. A family with a detailed fetch reads the licence record too, records
	 * the NAMES of the fields the vendor answered with and takes an activation code when there is no key file. A key is
	 * stored only with the encryption key available, and never logged, mailed or put in an alert.
	 *
	 * @param Resource      $r      Licence resource.
	 * @param LicenceFamily $family Family.
	 * @return bool Whether a key is stored now.
	 */
	public static function fetch_key( Resource $r, LicenceFamily $family ): bool {
		$id = (int) $r->get( $family->meta( 'key_id' ) );

		if ( $id < 1 || ! Crypto::available() ) {
			return false;
		}

		$client   = self::client();
		$reply    = $client->request( 'GET', '/licenses/plesk/key/' . $id );
		$detailed = $family->detailed_key();
		$info     = $detailed ? $client->request( 'GET', '/licenses/plesk/' . $id ) : null;
		$meta     = array();

		if ( $detailed ) {
			$shape = array();

			foreach ( array( 'key' => $reply, 'licence' => $info ) as $label => $answer ) {
				if ( is_array( $answer ) ) {
					$shape[] = $label . ':' . implode( '|', array_slice( array_map( 'strval', array_keys( $answer ) ), 0, 30 ) );
				}
			}

			$meta[ $family->meta( 'shape' ) ] = substr( implode( ' ', $shape ), 0, 400 );
		}

		$key = is_array( $reply ) ? self::normalise_key( (string) ( $reply['key'] ?? '' ) ) : '';

		if ( $detailed && '' === $key && is_array( $info ) && is_string( $info['activation_code'] ?? null ) ) {
			$key = trim( $info['activation_code'] );
		}

		if ( '' === $key || strlen( $key ) > 60000 ) {
			if ( array() !== $meta ) {
				$r->set( $meta );
			}

			return false;
		}

		$meta[ $family->meta( 'key' ) ] = Crypto::encrypt( $key );
		$meta                           = $meta + $family->key_meta( $r, $key );

		if ( ! $r->set( $meta ) ) {
			return false;
		}

		$family->on_key_stored( $r, $key );

		return true;
	}

	/**
	 * One ask for a key that is not stored yet: fetch it; when there is none, count the ask, tell staff once at TRIES asks
	 * and ask again later (2 minutes times the tries, at most 6 hours), until three times TRIES asks.
	 *
	 * @param Resource      $r      Licence resource.
	 * @param LicenceFamily $family Family.
	 * @return bool Whether a key is stored now.
	 */
	public static function ask_key( Resource $r, LicenceFamily $family ): bool {
		if ( self::fetch_key( $r, $family ) ) {
			return true;
		}

		$tries = (int) $r->get( $family->meta( 'key_tries' ) ) + 1;
		$r->set( $family->ask_meta( $tries ) );

		if ( self::TRIES === $tries ) {
			Alerts::admin( $r, $family->ask_alert( $r ) );
		}

		if ( $tries < 3 * self::TRIES ) {
			Jobs::schedule_once( time() + min( 6 * HOUR_IN_SECONDS, 120 * $tries ), $family->key_hook(), array( $r->id() ) );
		}

		return false;
	}

	/**
	 * The watchdog's list of licences that wait for a key: the vendor id is known, no key is stored, the licence has not
	 * ended and the asks have not run out. The caller narrows the resources with its own query.
	 *
	 * @param LicenceFamily         $family    Family.
	 * @param iterable<int,Resource> $resources Candidates, by id.
	 * @return array<int, array<int, int>>
	 */
	public static function key_due( LicenceFamily $family, iterable $resources ): array {
		$sets = array();

		foreach ( $resources as $id => $r ) {
			if ( $family->owns( $r ) && (int) $r->get( $family->meta( 'key_id' ) ) > 0 && '' === (string) $r->get( $family->meta( 'key' ) ) && ! $family->ended( $r ) && (int) $r->get( $family->meta( 'key_tries' ) ) < 3 * self::TRIES ) {
				$sets[] = array( (int) $id );
			}
		}

		return $sets;
	}
}
