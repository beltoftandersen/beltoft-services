<?php
/**
 * The choices of a licence key at checkout: the server address, the kind of server, and for an add-on which server.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Licences;

use BeltoftLicenses\Licenses;
use BeltoftServices\Checkout\LineField;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Apps;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\PleskLicence;
use BeltoftServices\Proxmox\Vm\ServerName;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One block per licence line in the classic checkout (quantity 2 is refused: each licence is for one address). The
 * choices are checked on submit, saved on the order line and handed to the new resource as meta through
 * beltoft_services_initial_meta. The block checkout has no such fields, so it is refused for a cart with a licence.
 * Nothing here orders anything at the vendor.
 *
 * A Plesk edition for a virtual server is only valid on one (the vendor sells different items for physical servers),
 * so the customer confirms the kind of server the variation is for. An add-on can be bound to one of the customer's
 * own Plesk servers on the platform (then the Plesk licence of that server is its parent) or to an address typed.
 */
final class KeyField {

	public const POST_KEY  = 'beltoft_services_lickey';
	public const ITEM_META = '_beltoft_services_lickey';

	/** Hooks. */
	public static function register(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'block_store_api' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_on_item' ), 30, 4 );
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 30, 3 );
	}

	/**
	 * Is the product (or variation) one of the licence key driver?
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function is_key_product( int $product_id ): bool {
		$driver = $product_id > 0 ? Product::driver_for( $product_id ) : null;

		return $driver instanceof Driver && KeyDriver::ID === $driver->id();
	}

	/**
	 * The cart lines that start a new licence.
	 *
	 * @return array<string, array<string, mixed>> Cart item key => cart item.
	 */
	public static function cart_lines(): array {
		return LineField::cart_lines( array( __CLASS__, 'is_key_product' ) );
	}

	/**
	 * The catalogue key and server type of a cart line.
	 *
	 * @param array<string, mixed> $item Cart item.
	 * @return array{key: string, physical: bool}
	 */
	private static function plan_of( array $item ): array {
		$plan = Product::plan( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) ?: null );
		$tier = (string) ( $plan['lic_tier'] ?? '' );
		$phys = KeyCatalog::is_edition( $tier ) && 'physical' === (string) ( $plan['lic_server'] ?? '' );

		return array( 'key' => $phys ? $tier . '-physical' : $tier, 'physical' => $phys );
	}

	/**
	 * A public IPv4 address, or ''.
	 *
	 * @param mixed $raw What was typed.
	 */
	public static function public_ipv4( $raw ): string {
		$ip = is_string( $raw ) ? trim( $raw ) : '';

		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ? $ip : '';
	}

	/**
	 * Does the server run AlmaLinux (a Plesk app version whose systems are AlmaLinux)? CloudLinux needs it.
	 *
	 * @param Resource $server Server.
	 */
	public static function server_is_alma( Resource $server ): bool {
		$def = Apps::definition( (string) $server->get( 'app_id' ) );

		foreach ( is_array( $def ) ? (array) $def['versions'] : array() as $version ) {
			if ( is_array( $version ) && (string) ( $version['id'] ?? '' ) === (string) $server->get( 'app_version' ) ) {
				foreach ( (array) ( $version['os'] ?? array() ) as $family ) {
					if ( is_string( $family ) && str_starts_with( $family, 'almalinux-' ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * The customer's own Plesk servers on the platform: license id => array( name, address ).
	 *
	 * @param int $user_id Customer.
	 * @return array<int, array{name: string, ip: string, resource: Resource}>
	 */
	public static function own_servers( int $user_id ): array {
		$out = array();

		foreach ( $user_id > 0 ? Licenses::where( 'user_id', $user_id ) : array() as $license ) {
			$server = Resource::for_license( (int) $license['id'] );

			if ( ! $server || ProxmoxDriver::ID !== $server->get( 'driver' ) || PleskLicence::APP !== (string) $server->get( 'app_id' ) || in_array( (string) $server->get( 'state' ), array( 'failed', 'deleting', 'deleted' ), true ) ) {
				continue;
			}

			$ip = IpPool::for_resource( $server->id() );

			if ( null === $ip || '' === self::public_ipv4( $ip['address'] ?? '' ) ) {
				continue;
			}

			$name                        = ServerName::of( $server );
			$out[ (int) $license['id'] ] = array( 'name' => '' !== $name ? $name : (string) $ip['address'], 'ip' => (string) $ip['address'], 'resource' => $server );
		}

		return $out;
	}

	/**
	 * The choices of one line, or why they cannot be used.
	 *
	 * @param array<string, mixed> $input   source (own:<license id> or other), ip, confirm.
	 * @param int                  $user_id Customer.
	 * @param string               $key     Catalogue key.
	 * @return array<string, mixed>|\WP_Error What is saved on the order line; the error's message is for the customer.
	 */
	public static function resolve( array $input, int $user_id, string $key ) {
		if ( '' === $key ) {
			return new \WP_Error( 'lickey_product', __( 'This licence is not set up for sale yet. Please try again later.', 'beltoft-services' ) );
		}

		$edition = KeyCatalog::is_edition( $key );
		$attach  = 0;
		$ip      = '';
		$source  = (string) ( $input['source'] ?? 'other' );

		if ( ! $edition && 1 === preg_match( '/^own:(\d+)$/D', $source, $m ) ) {
			$own = self::own_servers( $user_id )[ (int) $m[1] ] ?? null;

			if ( null === $own ) {
				return new \WP_Error( 'lickey_server', __( 'Choose one of your own servers, or enter an address.', 'beltoft-services' ) );
			}

			if ( str_starts_with( $key, 'cloudlinux-' ) && ! self::server_is_alma( $own['resource'] ) ) {
				return new \WP_Error( 'lickey_alma', __( 'CloudLinux needs a server with AlmaLinux. This server runs another system. Choose a Plesk version on AlmaLinux when you order a server.', 'beltoft-services' ) );
			}

			$attach = (int) $m[1];
			$ip     = $own['ip'];
		} else {
			$ip = self::public_ipv4( $input['ip'] ?? '' );

			if ( '' === $ip ) {
				return new \WP_Error( 'lickey_ip', __( 'Enter the public IPv4 address of the server the licence is for, for example 203.0.113.10.', 'beltoft-services' ) );
			}

			$pool = IpPool::find( $ip );

			if ( null !== $pool ) {
				// An address of ours: only its owner may bind a licence to it, and a Plesk edition is bought with the server.
				$mine = array_filter( self::own_servers( $user_id ), static fn( $row ) => $row['ip'] === $ip );

				if ( $edition || array() === $mine ) {
					return new \WP_Error( 'lickey_ours', $edition ? __( 'This address belongs to one of our servers. Plesk for our servers is ordered with the server.', 'beltoft-services' ) : __( 'This address is not one of your servers.', 'beltoft-services' ) );
				}

				$attach = (int) array_key_first( $mine );
			}
		}

		if ( empty( $input['confirm'] ) && $edition ) {
			return new \WP_Error( 'lickey_confirm', KeyCatalog::is_physical( $key ) ? __( 'Confirm that the server is a physical server.', 'beltoft-services' ) : __( 'Confirm that the server is a virtual server. A licence for a virtual server is not valid on a physical one.', 'beltoft-services' ) );
		}

		foreach ( KeyOrder::live( $ip ) as $other ) {
			if ( KeyCatalog::is_edition( $key ) && KeyCatalog::is_edition( KeyOrder::key_of( $other ) ) ) {
				return new \WP_Error( 'lickey_duplicate', __( 'This address already has a Plesk licence.', 'beltoft-services' ) );
			}

			if ( KeyOrder::key_of( $other ) === $key ) {
				return new \WP_Error( 'lickey_duplicate', __( 'This address already has this licence.', 'beltoft-services' ) );
			}
		}

		return array( 'ip' => $ip, 'attach' => $attach, 'key' => $key, 'server' => KeyCatalog::is_physical( $key ) ? 'physical' : 'vps' );
	}

	/**
	 * The block checkout cannot ask for the choices, so a cart with a new licence line is refused there.
	 *
	 * @param \WC_Order         $order   Order being made.
	 * @param \WP_REST_Request $request Store API request.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the cart has a licence line.
	 */
	public static function block_store_api( \WC_Order $order, \WP_REST_Request $request ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		LineField::refuse_store_api( array( __CLASS__, 'is_key_product' ), 'beltoft_services_lickey_checkout_required', __( 'Licences are ordered at the website checkout, where you enter the server address. Please use the checkout page of the website.', 'beltoft-services' ) );
	}

	/**
	 * What the form posted for one cart line, only inside WooCommerce's own checkout run (it verified its nonce first).
	 *
	 * @param string $key Cart item key.
	 * @return array<string, string>
	 */
	private static function posted( string $key ): array {
		return did_action( 'woocommerce_checkout_process' ) ? self::read_post( $key ) : array();
	}

	/**
	 * The posted values of one line as plain strings.
	 *
	 * @param string $key Cart item key.
	 * @return array<string, string>
	 */
	private static function read_post( string $key ): array {
		$out = LineField::read_post( self::POST_KEY, $key, array( 'source', 'ip', 'confirm' ) );

		return $out;
	}

	/**
	 * Prints one block per licence line.
	 */
	public static function render(): void {
		$lines = self::cart_lines();

		if ( array() === $lines ) {
			return;
		}

		$own = self::own_servers( get_current_user_id() );

		echo '<div class="beltoft-services-lickey-field" style="margin:40px 0 0">';
		echo '<h3 style="margin:0 0 16px">' . esc_html__( 'Licence', 'beltoft-services' ) . '</h3>';

		foreach ( $lines as $cart_key => $item ) {
			self::print_line( (string) $cart_key, self::plan_of( $item ), $own );
		}

		echo '</div>';
	}

	/**
	 * The fields of one line.
	 *
	 * @param string                                                          $cart_key Cart item key.
	 * @param array{key: string, physical: bool}                              $plan     Plan.
	 * @param array<int, array{name: string, ip: string, resource: Resource}> $own      The customer's Plesk servers.
	 */
	private static function print_line( string $cart_key, array $plan, array $own ): void {
		$name = self::POST_KEY . '[' . $cart_key . ']';
		$id   = self::POST_KEY . '_' . sanitize_key( $cart_key );
		$post = self::read_post( $cart_key );

		echo '<fieldset class="beltoft-services-lickey-field__line" style="border:0;padding:0;margin:0 0 16px">';

		if ( ! KeyCatalog::is_edition( $plan['key'] ) && array() !== $own ) {
			echo '<p class="form-row form-row-wide"><label for="' . esc_attr( $id ) . '_source">' . esc_html__( 'Server', 'beltoft-services' ) . '</label>';
			echo '<span class="woocommerce-input-wrapper"><select name="' . esc_attr( $name ) . '[source]" id="' . esc_attr( $id ) . '_source">';

			foreach ( $own as $license_id => $row ) {
				$value = 'own:' . $license_id;
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $post['source'] ?? '', $value, false ) . '>' . esc_html( $row['name'] . ' (' . $row['ip'] . ')' ) . '</option>';
			}

			echo '<option value="other"' . selected( $post['source'] ?? '', 'other', false ) . '>' . esc_html__( 'Another server (enter the address)', 'beltoft-services' ) . '</option>';
			echo '</select></span></p>';
		}

		echo '<p class="form-row form-row-wide validate-required"><label for="' . esc_attr( $id ) . '_ip">' . esc_html__( 'Server address (IPv4)', 'beltoft-services' ) . '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'beltoft-services' ) . '" style="text-decoration:none">*</abbr></label>';
		echo '<span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="' . esc_attr( $name ) . '[ip]" id="' . esc_attr( $id ) . '_ip" maxlength="15" autocomplete="off" spellcheck="false" placeholder="203.0.113.10" value="' . esc_attr( $post['ip'] ?? '' ) . '"></span>';
		echo '<span class="description">' . esc_html__( 'The licence is tied to this public address and works for it only.', 'beltoft-services' ) . '</span></p>';

		if ( KeyCatalog::is_edition( $plan['key'] ) ) {
			$text = $plan['physical'] ? __( 'The server is a physical server.', 'beltoft-services' ) : __( 'The server is a virtual server (VPS). This licence is not valid on a physical server.', 'beltoft-services' );
			// validate-required: the checkout step stops on an unticked box, as on an empty address.
			echo '<p class="form-row form-row-wide validate-required"><label><input type="checkbox" class="input-checkbox" name="' . esc_attr( $name ) . '[confirm]" value="1"' . checked( $post['confirm'] ?? '', '1', false ) . '> ' . esc_html( $text ) . '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'beltoft-services' ) . '" style="text-decoration:none">*</abbr></label></p>';
		} elseif ( str_starts_with( $plan['key'], 'cloudlinux-' ) ) {
			echo '<p class="description">' . esc_html__( 'CloudLinux runs on AlmaLinux. On a server of ours it needs the Plesk version on AlmaLinux.', 'beltoft-services' ) . '</p>';
		}

		echo '</fieldset>';
	}

	/**
	 * Refuses a classic checkout whose licence lines have unusable choices.
	 *
	 * @param mixed     $data   Posted checkout data.
	 * @param \WP_Error $errors Errors.
	 */
	public static function validate( $data, $errors ): void {
		unset( $data );

		$lines = self::cart_lines();

		if ( array() === $lines || ! $errors instanceof \WP_Error ) {
			return;
		}

		foreach ( $lines as $cart_key => $item ) {
			if ( (int) ( $item['quantity'] ?? 1 ) > 1 ) {
				$errors->add( 'beltoft_services_lickey', __( 'Each licence is for one server address. Set the quantity to 1 and order again for another licence.', 'beltoft-services' ) );
				continue;
			}

			$found = self::resolve( self::posted( (string) $cart_key ), get_current_user_id(), self::plan_of( $item )['key'] );

			if ( is_wp_error( $found ) ) {
				$errors->add( 'beltoft_services_lickey', $found->get_error_message() );
			}
		}
	}

	/**
	 * Saves the choices on the order line.
	 *
	 * @param \WC_Order_Item_Product $item   Line item.
	 * @param string                 $key    Cart item key.
	 * @param array<string, mixed>   $values Cart item.
	 * @param \WC_Order              $order  Order.
	 */
	public static function save_on_item( $item, $key = '', $values = array(), $order = null ): void {
		unset( $order );

		if ( ! $item instanceof \WC_Order_Item_Product || ! is_string( $key ) || ! isset( self::cart_lines()[ $key ] ) ) {
			return;
		}

		$found = self::resolve( self::posted( $key ), get_current_user_id(), self::plan_of( is_array( $values ) ? $values : array() )['key'] );

		if ( is_array( $found ) ) {
			$item->add_meta_data( self::ITEM_META, $found, true );
		}
	}

	/**
	 * A new licence starts with the choices its order line carries. When an order has several lines of one product, each
	 * license takes a line no other licence of the order has taken.
	 *
	 * @param mixed                $meta    Meta so far.
	 * @param array<string, mixed> $license License row.
	 * @param Driver               $driver  Driver.
	 * @return mixed
	 */
	public static function initial_meta( $meta, $license, $driver ) {
		if ( ! is_array( $meta ) || ! is_array( $license ) || ! $driver instanceof Driver || KeyDriver::ID !== $driver->id() ) {
			return $meta;
		}

		$order_id = (int) ( $license['order_id'] ?? 0 );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof \WC_Order ) {
			return $meta;
		}

		$product_id = (int) ( $license['product_id'] ?? 0 );
		$variation  = (int) ( $license['variation_id'] ?? 0 );
		$used       = array();

		foreach ( Licenses::where( 'order_id', $order_id ) as $other ) {
			if ( (int) $other['id'] === (int) ( $license['id'] ?? 0 ) ) {
				continue;
			}

			$resource = Resource::for_license( (int) $other['id'] );

			if ( $resource && KeyDriver::ID === $resource->get( 'driver' ) ) {
				$used[] = (int) $resource->get( 'order_item_id' );
			}
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || (int) $item->get_product_id() !== $product_id || ( 0 !== $variation && (int) $item->get_variation_id() !== $variation ) || in_array( (int) $item->get_id(), $used, true ) ) {
				continue;
			}

			$data = $item->get_meta( self::ITEM_META, true );

			if ( ! is_array( $data ) || empty( $data['ip'] ) ) {
				continue;
			}

			$meta['lic_ip']        = (string) $data['ip'];
			$meta['lic_attach']    = (int) $data['attach'];
			$meta['lic_server']    = (string) $data['server'];
			$meta['order_item_id'] = (int) $item->get_id();

			break;
		}

		return $meta;
	}
}
