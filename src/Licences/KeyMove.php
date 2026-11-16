<?php
/**
 * Change the server address of a standalone licence key.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Licences;

use BeltoftLicenses\Licenses;
use BeltoftServices\Account\Licences as LicencesArea;
use BeltoftServices\Account\Panel;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Alerts;
use BeltoftServices\Apps\AppLicence;
use BeltoftServices\Apps\PleskLicence;
use BeltoftServices\Domains\LicenceVendor;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The customer's action `change_address`: the vendor licence is bound to another server address, nothing is ordered, charged
 * or deleted. It runs through the shared action controller (the generic route resources/<id>/actions/change_address, then
 * Jobs::start_action and the job under the resource lock).
 *
 * - The address is checked by the checkout check (KeyField::resolve) so there is one rule for a licence and its address.
 * - The move is LicenceVendor::move(): the address (and for a Plesk edition the hardware id). Stored data (`lic_ip`, `lic_attach`,
 *   `lic_moves`) changes after the vendor accepted. A temporary error is retried; a final refusal leaves the old address stored, tells
 *   the customer and staff.
 * - A change is limited (filter `beltoft_services_licence_moves_per_day`, default 1 in 24 hours): a licence is not a way to share
 *   one key between machines.
 * - Children: an add-on is ordered under its parent at the vendor (`parent_key_id`) and with its own address binding. When an edition
 *   moves, each live add-on with `lic_parent` = the edition's vendor id follows in its own job (internal action `follow_parent`).
 *   The same follows a kept app licence that is used on a new server (PleskLicence::MOVED). An add-on with a live parent cannot be
 *   moved alone.
 */
final class KeyMove {

	public const ACTION = 'change_address';
	public const FOLLOW = 'follow_parent';

	/** Meta: unix times of the changes of the last 24 hours. */
	public const MOVES  = 'lic_moves';
	public const HANDLE = 'beltoft-services-lickey';

	/** Hooks. */
	public static function register(): void {
		add_filter( 'beltoft_services_action_refusal', array( __CLASS__, 'refusal' ), 10, 4 );
		add_action( PleskLicence::MOVED, array( __CLASS__, 'app_licence_moved' ), 10, 3 );
		add_filter( 'beltoft_services_account_buttons', array( __CLASS__, 'no_generic_button' ), 10, 2 );
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ), 20, 2 );
	}

	/**
	 * Changes in the last 24 hours that count against the limit.
	 *
	 * @param Resource $r Licence resource.
	 * @return int[] Unix times, oldest first.
	 */
	private static function recent( Resource $r ): array {
		$now = time();
		$out = array();

		foreach ( (array) $r->get( self::MOVES ) as $at ) {
			if ( (int) $at > $now - DAY_IN_SECONDS ) {
				$out[] = (int) $at;
			}
		}

		sort( $out );

		return $out;
	}

	/**
	 * Changes allowed in 24 hours.
	 */
	private static function per_day(): int {
		/**
		 * Filters how many times the server address of one licence key may be changed in 24 hours.
		 *
		 * @param int $max Changes (default 1).
		 */
		return max( 1, (int) apply_filters( 'beltoft_services_licence_moves_per_day', 1 ) );
	}

	/**
	 * Is the customer offered the change now? A live, delivered licence that accepts actions.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function available( Resource $r ): bool {
		return $r->accepts_actions() && ! KeyOrder::ended( $r ) && (int) $r->get( 'lic_key_id' ) > 0 && '' !== (string) $r->get( 'lic_key' ) && '' !== (string) $r->get( 'lic_ip' );
	}

	/**
	 * Is the parent of an add-on a live licence of ours (a standalone edition, or the app licence of a server)? Then the add-on
	 * follows it and cannot be moved alone.
	 *
	 * @param Resource $r Add-on resource.
	 */
	public static function parent_live( Resource $r ): bool {
		$parent = (int) $r->get( 'lic_parent' );

		if ( $parent < 1 ) {
			return false;
		}

		foreach ( Resource::find_many( Resource::find_by_meta( KeyDriver::KIND, 'lic_key_id', $parent ) ) as $found ) {
			if ( ! KeyOrder::ended( $found ) ) {
				return true;
			}
		}

		foreach ( Resource::find_many( Resource::find_by_meta( 'applic', 'plesk_key_id', $parent ) ) as $found ) {
			if ( 'ended' !== AppLicence::state( $found ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The live add-ons that hang under a vendor licence.
	 *
	 * @param int $parent Vendor id of the parent licence.
	 * @return Resource[]
	 */
	public static function children( int $parent ): array {
		$out = array();

		foreach ( $parent > 0 ? Resource::find_many( Resource::find_by_meta( KeyDriver::KIND, 'lic_parent', $parent ) ) : array() as $found ) {
			if ( ! KeyOrder::ended( $found ) && (int) $found->get( 'lic_key_id' ) > 0 ) {
				$out[] = $found;
			}
		}

		return $out;
	}

	/**
	 * Has the licence used up its changes of the last 24 hours? The sentence says when it can be changed again.
	 *
	 * @param Resource $r Licence resource.
	 */
	private static function limit_problem( Resource $r ): ?\WP_Error {
		$recent = self::recent( $r );

		if ( count( $recent ) < self::per_day() ) {
			return null;
		}

		$again = $recent[ count( $recent ) - self::per_day() ] + DAY_IN_SECONDS;

		/* translators: %s: date and time the address can be changed again. */
		return new \WP_Error( 'lickey_move_limit', sprintf( __( 'The server address of this licence was changed recently. You can change it again after %s.', 'beltoft-services' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $again ) ) );
	}

	/**
	 * All checks of a change, with the checkout's check of the address: the new address and server, or the sentence for the customer.
	 *
	 * @param Resource             $r    Licence resource.
	 * @param array<string, mixed> $args source (own:<license id> or other) and ip.
	 * @return array{ip: string, attach: int}|\WP_Error
	 */
	public static function check( Resource $r, array $args ) {
		if ( ! self::available( $r ) ) {
			return new \WP_Error( 'lickey_move_unavailable', __( 'The server address of this licence cannot be changed now.', 'beltoft-services' ) );
		}

		$limit = self::limit_problem( $r );

		if ( null !== $limit ) {
			return $limit;
		}

		if ( ! KeyCatalog::is_edition( KeyOrder::key_of( $r ) ) && self::parent_live( $r ) ) {
			return new \WP_Error( 'lickey_move_parent', __( 'This add-on belongs to a Plesk licence. Change the server address of that licence and the add-on follows.', 'beltoft-services' ) );
		}

		$found = KeyField::resolve(
			array(
				'source' => (string) ( $args['source'] ?? 'other' ),
				'ip'     => (string) ( $args['ip'] ?? '' ),
			),
			(int) ( $r->license()['user_id'] ?? 0 ),
			KeyOrder::key_of( $r ),
			$r
		);

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		if ( $found['ip'] === (string) $r->get( 'lic_ip' ) ) {
			return new \WP_Error( 'lickey_move_same', __( 'The licence already uses this address.', 'beltoft-services' ) );
		}

		return array( 'ip' => (string) $found['ip'], 'attach' => (int) $found['attach'] );
	}

	/**
	 * Listener of beltoft_services_action_refusal: a bad address or a refused change is answered by the route, before anything is queued.
	 *
	 * @param mixed $refusal  Refusal so far.
	 * @param mixed $resource Resource.
	 * @param mixed $action   Action name.
	 * @param mixed $args     Cleaned arguments.
	 * @return mixed
	 */
	public static function refusal( $refusal, $resource, $action, $args ) {
		if ( null !== $refusal || self::ACTION !== $action || ! $resource instanceof Resource || KeyDriver::ID !== $resource->get( 'driver' ) ) {
			return $refusal;
		}

		$checked = self::check( $resource, is_array( $args ) ? $args : array() );

		return is_wp_error( $checked ) ? new \WP_Error( $checked->get_error_code(), $checked->get_error_message(), array( 'status' => 422 ) ) : $refusal;
	}

	/**
	 * The job of `change_address`. Safe to run again: a licence that is already bound to the new address (stored) only passes on to its add-ons.
	 *
	 * @param Resource             $r    Licence resource.
	 * @param array<string, mixed> $args source and ip.
	 */
	public static function run( Resource $r, array $args ): StepResult {
		$said = static fn( string $text ): array => array( Jobs::TEXT => $text, Jobs::EXPECTED => true );
		$ip   = (string) ( $args['ip'] ?? '' );

		// A retry after the address was stored: no second check (the limit would count the change itself) and no second call.
		if ( '' !== $ip && $ip === (string) $r->get( 'lic_ip' ) && self::moved_recently( $r ) ) {
			return self::pass_on( $r, $ip, (int) $r->get( 'lic_attach' ) );
		}

		$checked = self::check( $r, $args );

		if ( is_wp_error( $checked ) ) {
			return StepResult::fail( $checked->get_error_message(), $said( $checked->get_error_message() ) );
		}

		$stopped = LicenceVendor::move(
			$r,
			new KeyFamily(),
			$checked['ip'],
			KeyCatalog::is_edition( KeyOrder::key_of( $r ) ),
			array(
				'wait' => StepResult::retry( 300, 'Openprovider did not move the licence yet.' ),
				'tail' => 'The licence stays on the old address ' . (string) $r->get( 'lic_ip' ) . ' in our records. Bind it to ' . $checked['ip'] . ' at Openprovider if the customer needs it, or ask the customer to try again.',
				'said' => __( 'The server address was not changed. We have been told and will look into it.', 'beltoft-services' ),
			)
		);

		if ( null !== $stopped ) {
			return $stopped;
		}

		$moves   = self::recent( $r );
		$moves[] = time();

		if ( ! $r->set( array( 'lic_ip' => $checked['ip'], 'lic_attach' => $checked['attach'], self::MOVES => $moves ) ) ) {
			return StepResult::retry( 60, 'The new server address could not be stored.' );
		}

		return self::pass_on( Resource::find( $r->id() ) ?? $r, $checked['ip'], $checked['attach'] );
	}

	/**
	 * Was the address stored by a change of the last minutes (so a repeated job is the same change)?
	 *
	 * @param Resource $r Licence resource.
	 */
	private static function moved_recently( Resource $r ): bool {
		$recent = self::recent( $r );

		return array() !== $recent && time() - (int) end( $recent ) < HOUR_IN_SECONDS;
	}

	/**
	 * The add-ons of an edition follow it, each in its own job. A add-on that cannot be queued now (it is busy) makes the step try again.
	 *
	 * @param Resource $r      Licence resource whose address is stored.
	 * @param string   $ip     Its address.
	 * @param int      $attach Its server (license id), 0 for none.
	 */
	private static function pass_on( Resource $r, string $ip, int $attach ): StepResult {
		$text = __( 'The server address was changed.', 'beltoft-services' );

		if ( KeyCatalog::is_edition( KeyOrder::key_of( $r ) ) && ! self::queue_children( (int) $r->get( 'lic_key_id' ), $ip, $attach ) ) {
			return StepResult::retry( 300, 'An add-on of this licence is busy and does not follow yet.' );
		}

		return StepResult::done( array( Jobs::TEXT => $text ) );
	}

	/**
	 * Queues `follow_parent` for every live add-on under a vendor licence whose address is not the new one yet.
	 *
	 * @param int    $parent Vendor id of the parent.
	 * @param string $ip     New address.
	 * @param int    $attach New server (license id).
	 * @return bool False when an add-on could not be queued.
	 */
	public static function queue_children( int $parent, string $ip, int $attach ): bool {
		$all = true;

		foreach ( self::children( $parent ) as $child ) {
			if ( $ip === (string) $child->get( 'lic_ip' ) ) {
				continue;
			}

			$queued = Jobs::start_action( $child, self::FOLLOW, __( 'Moving the licence to the new server address', 'beltoft-services' ), array( 'parent' => $parent, 'ip' => $ip, 'attach' => $attach ) );

			if ( is_wp_error( $queued ) ) {
				$all = false;
			}
		}

		return $all;
	}

	/**
	 * The job of `follow_parent`: an add-on is bound to the address its parent moved to. Only the address is sent. Not limited and not
	 * a customer's choice, so it has no check of the address beyond the parent link.
	 *
	 * @param Resource             $r    Add-on resource.
	 * @param array<string, mixed> $args parent, ip, attach.
	 */
	public static function follow( Resource $r, array $args ): StepResult {
		$ip     = (string) ( $args['ip'] ?? '' );
		$attach = (int) ( $args['attach'] ?? 0 );

		if ( (int) $r->get( 'lic_parent' ) < 1 || (int) $r->get( 'lic_parent' ) !== (int) ( $args['parent'] ?? 0 ) || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return StepResult::fail( 'The add-on does not belong to this licence.', array( Jobs::EXPECTED => true ) );
		}

		if ( $ip === (string) $r->get( 'lic_ip' ) || KeyOrder::ended( $r ) ) {
			return StepResult::done();
		}

		$stopped = LicenceVendor::move(
			$r,
			new KeyFamily(),
			$ip,
			false,
			array(
				'wait' => StepResult::retry( 300, 'Openprovider did not move the add-on yet.' ),
				'tail' => 'The add-on follows its Plesk licence to ' . $ip . '; bind it to that address at Openprovider.',
				'said' => __( 'The licence could not follow its Plesk licence to the new server address. We have been told and will look into it.', 'beltoft-services' ),
			)
		);

		if ( null !== $stopped ) {
			return $stopped;
		}

		if ( ! $r->set( array( 'lic_ip' => $ip, 'lic_attach' => $attach ) ) ) {
			return StepResult::retry( 60, 'The new server address could not be stored.' );
		}

		return StepResult::done();
	}

	/**
	 * Listener of PleskLicence::MOVED: the add-ons that hang under a kept app licence follow it to its new server.
	 *
	 * @param mixed $licence App licence resource.
	 * @param mixed $ip      The new address.
	 * @param mixed $server  The new server.
	 */
	public static function app_licence_moved( $licence, $ip = '', $server = null ): void {
		if ( ! $licence instanceof Resource || ! $server instanceof Resource || ! is_string( $ip ) ) {
			return;
		}

		if ( ! self::queue_children( (int) $licence->get( 'plesk_key_id' ), $ip, (int) ( $server->license()['id'] ?? 0 ) ) ) {
			Alerts::admin( $licence, 'An add-on of this Plesk licence is busy and did not follow the licence to ' . $ip . '. Change its server address by hand or wait and check the add-on.' );
		}
	}

	/**
	 * The address change needs an address: it has its own form (form()), so the generic button, which posts no arguments, is not drawn.
	 *
	 * @param mixed $actions  Action name => label.
	 * @param mixed $resource Resource.
	 * @return mixed
	 */
	public static function no_generic_button( $actions, $resource = null ) {
		if ( is_array( $actions ) && $resource instanceof Resource && KeyDriver::ID === $resource->get( 'driver' ) ) {
			unset( $actions[ self::ACTION ] );
		}

		return $actions;
	}

	/**
	 * Is the form shown to this viewer? The owner, while the change is possible or its limit says when.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function offer( Resource $r ): bool {
		return self::available( $r ) && ( KeyCatalog::is_edition( KeyOrder::key_of( $r ) ) || ! self::parent_live( $r ) );
	}

	/**
	 * The form on the licence page (nothing when the change is not offered).
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function form( Resource $r ): void {
		if ( ! self::offer( $r ) ) {
			return;
		}

		$own     = KeyCatalog::is_edition( KeyOrder::key_of( $r ) ) ? array() : KeyField::own_servers( (int) ( $r->license()['user_id'] ?? 0 ) );
		$limited = self::limit_problem( $r );

		echo '<div class="beltoft-services-lickey-move" data-lickey-move data-resource="' . esc_attr( (string) $r->id() ) . '">';
		echo '<h3>' . esc_html__( 'Change server address', 'beltoft-services' ) . '</h3>';

		if ( null !== $limited ) {
			echo '<p>' . esc_html( $limited->get_error_message() ) . '</p></div>';

			return;
		}

		echo '<p>' . esc_html__( 'You can change the server address of this licence here. Nothing is charged. The key stops working on the old address.', 'beltoft-services' ) . '</p>';

		if ( KeyCatalog::is_edition( KeyOrder::key_of( $r ) ) ) {
			echo '<p>' . esc_html__( 'Add-ons of this licence move with it.', 'beltoft-services' ) . '</p>';
		}

		if ( array() !== $own ) {
			echo '<p><label>' . esc_html__( 'Server', 'beltoft-services' ) . '<br><select data-lickey-source>';

			foreach ( $own as $license_id => $row ) {
				echo '<option value="own:' . esc_attr( (string) $license_id ) . '">' . esc_html( $row['name'] . ' (' . $row['ip'] . ')' ) . '</option>';
			}

			echo '<option value="other">' . esc_html__( 'Another server (enter the address)', 'beltoft-services' ) . '</option></select></label></p>';
		}

		echo '<p><label>' . esc_html__( 'New server address (IPv4)', 'beltoft-services' ) . '<br><input type="text" data-lickey-ip maxlength="15" autocomplete="off" spellcheck="false" placeholder="203.0.113.10"></label></p>';
		echo '<p><button type="button" class="button woocommerce-button bs-btn" data-lickey-go>' . esc_html__( 'Change address', 'beltoft-services' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * Listener of beltoft_services_account_assets: the script of the form, on the licences endpoint.
	 *
	 * @param mixed $parent   Handle of the panel script.
	 * @param mixed $endpoint The My Account endpoint being shown.
	 */
	public static function assets( $parent = null, $endpoint = null ): void {
		if ( ! is_string( $parent ) || ! is_string( $endpoint ) || ServiceArea::get( LicencesArea::class )->slug() !== $endpoint || ! is_user_logged_in() ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/lickey.js', BELTOFT_SERVICES_FILE ), array( Panel::HANDLE, \BeltoftServices\Status::HANDLE, \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/lickey.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesLickey',
			array(
				'strings' => array(
					'working' => __( 'Changing the server address...', 'beltoft-services' ),
					'failed'  => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'limit'   => __( 'Too many requests. Please wait and try again later.', 'beltoft-services' ),
					'noIp'    => __( 'Enter the new server address.', 'beltoft-services' ),
					'pending' => __( 'The change is taking longer than usual. Reload the page in a few minutes to see the result.', 'beltoft-services' ),
				),
			)
		);
	}
}
