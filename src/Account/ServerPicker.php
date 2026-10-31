<?php
/**
 * My Account → Servers → New server: choose a plan, a system or an app, and go on to checkout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\AppField;
use BeltoftServices\Proxmox\AppLicence;
use BeltoftServices\Proxmox\AppLicenceCart;
use BeltoftServices\Proxmox\BackupField;
use BeltoftServices\Proxmox\DiskCart;
use BeltoftServices\Proxmox\DiskField;
use BeltoftServices\Proxmox\Offers;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\Picked;
use BeltoftServices\Proxmox\ServerName;
use BeltoftServices\Proxmox\SshKeyField;
use BeltoftServices\Proxmox\Tiles;
use BeltoftServices\RateLimit;
use BeltoftServices\Usage\BillingField;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * The page is the address /my-account/vps/new/ (the Servers endpoint with the value "new"). A plain form posted to the
 * same address: it works without script, and an error comes back on the page with the choices kept. The handler checks
 * the choices with the code checkout runs (Offers for the plan and its stock, OsField::check, AppField::resolve,
 * ServerName::normalise, SshKeyField::normalise), puts the plan in the cart with the choices on the cart line (see
 * Picked) and sends the customer to checkout. Nothing here calls Proxmox: stock is the cached capacity.
 *
 * A customer who is not logged in is sent to the login with the address to come back to.
 */
final class ServerPicker {

	public const NONCE  = 'beltoft-services-picker';
	public const FIELD  = 'beltoft_services_picker_nonce';
	public const PLAN   = 'beltoft_services_picker_plan';
	public const BACKUP  = 'beltoft_services_picker_backups';
	public const BILLING = 'beltoft_services_picker_billing';
	public const HANDLE = 'beltoft-services-picker';

	/**
	 * What the page prints after a refused post: the messages by field, and the values to print again.
	 *
	 * @var array{errors: array<string, string>, values: array<string, string>}
	 */
	private static $state = array(
		'errors' => array(),
		'values' => array(),
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'submit' ), 5 );
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ), 16, 2 );
	}

	/**
	 * The address of the page; $plan starts the page on that plan (a variation id).
	 *
	 * @param int $plan Variation id, 0 for none.
	 */
	public static function url( int $plan = 0 ): string {
		return Servers::url_new( $plan );
	}

	/**
	 * Is the request for the New server page?
	 */
	private static function on_page(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( Servers::slug() ) && Servers::is_new();
	}

	// -- The post ----------------------------------------------------------------------------------

	/**
	 * Early on the page: a visitor who is not logged in goes to the login and comes back here; a post is checked and,
	 * when it holds, the cart is filled and the customer goes to checkout. A refused post prints the page again.
	 */
	public static function submit(): void {
		if ( ! self::on_page() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( self::url() ), wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in process().
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified in process() and every value is checked against what is offered.
		$result = self::process( wp_unslash( $_POST ), get_current_user_id() );

		if ( '' !== $result['redirect'] ) {
			wp_safe_redirect( $result['redirect'] );
			exit;
		}
	}

	/**
	 * Checks a post and, when it holds, puts the server in the cart.
	 *
	 * Keys of $post: the nonce, the plan (a variation id), the backups box, and the names of the checkout fields (operating
	 * system, app, version, domain, name, key). Anything not listed is ignored.
	 *
	 * @param array<string, mixed> $post Raw post (unslashed).
	 * @param int                  $user Customer.
	 * @return array{redirect: string, errors: array<string, string>}
	 */
	public static function process( array $post, int $user ): array {
		$values = self::values( $post );

		self::$state = array(
			'errors' => array(),
			'values' => $values,
		);

		$fail = static function ( string $field, string $message ) {
			self::$state['errors'][ $field ] = $message;

			return array(
				'redirect' => '',
				'errors'   => self::$state['errors'],
			);
		};

		if ( $user <= 0 || false === wp_verify_nonce( isset( $post[ self::FIELD ] ) && is_string( $post[ self::FIELD ] ) ? $post[ self::FIELD ] : '', self::NONCE ) ) {
			return $fail( 'form', __( 'Your session has expired. Reload the page and try again.', 'beltoft-services' ) );
		}

		/**
		 * Most posts of the picker a customer can make in an hour (0 turns the limit off).
		 *
		 * @param int $limit Default 20.
		 */
		if ( RateLimit::exceeded( $user, 'beltoft-services:picker', (int) apply_filters( 'beltoft_services_picker_rate_limit', 20 ), HOUR_IN_SECONDS ) ) {
			return $fail( 'form', __( 'Too many tries. Please wait a while and try again.', 'beltoft-services' ) );
		}

		$checked = self::check( $values, $user );

		if ( array() !== $checked['errors'] ) {
			self::$state['errors'] = $checked['errors'];

			return array(
				'redirect' => '',
				'errors'   => $checked['errors'],
			);
		}

		$added = self::add_to_cart( $checked );

		if ( null !== $added ) {
			return $fail( 'form', $added );
		}

		return array(
			'redirect' => wc_get_checkout_url(),
			'errors'   => array(),
		);
	}

	/**
	 * The values of a post as plain strings.
	 *
	 * @param array<string, mixed> $post Raw post.
	 * @return array<string, string>
	 */
	private static function values( array $post ): array {
		$get = static function ( string $key ) use ( $post ): string {
			return isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? trim( (string) $post[ $key ] ) : '';
		};

		return array(
			'plan'      => (string) absint( $get( self::PLAN ) ),
			'backups'   => '' !== $get( self::BACKUP ) ? '1' : '',
			'billing'   => 'usage' === $get( self::BILLING ) ? 'usage' : '',
			'os'        => $get( OsField::POST_KEY ),
			'app'       => $get( AppField::POST_APP ),
			'version'   => $get( AppField::POST_VERSION ),
			'tier'      => $get( AppField::POST_TIER ),
			'domain_on' => '' !== $get( AppField::POST_DOMAIN ) && AppField::MODE_OWN !== $get( AppField::POST_DOMAIN ) ? '1' : '',
			'own_on'    => AppField::MODE_OWN === $get( AppField::POST_DOMAIN ) ? '1' : '',
			'own'       => $get( AppField::POST_OWN ),
			'zone'      => $get( AppField::POST_ZONE ),
			'sub'       => $get( AppField::POST_SUB ),
			'name'      => $get( ServerName::POST_KEY ),
			'ssh_key'   => $get( SshKeyField::POST_KEY ),
		);
	}

	/**
	 * Checks the values with the code checkout runs.
	 *
	 * @param array<string, string> $values Values (see values()).
	 * @param int                   $user   Customer.
	 * @return array{errors: array<string, string>, plan: array<string, mixed>|null, variation_id: int, choices: array<string, string>}
	 */
	public static function check( array $values, int $user ): array {
		$out = array(
			'errors'       => array(),
			'plan'         => null,
			'variation_id' => 0,
			'choices'      => array(),
		);
		$plan = Offers::find( (int) $values['plan'] );

		if ( null === $plan ) {
			$out['errors']['plan'] = __( 'Choose a plan.', 'beltoft-services' );

			return $out;
		}

		if ( ! $plan['in_stock'] ) {
			$out['errors']['plan'] = __( 'That plan is out of stock. Choose another plan.', 'beltoft-services' );

			return $out;
		}

		/**
		 * Whether this customer can order this plan now (a site rule, such as a limit on servers).
		 *
		 * @param bool                 $allowed Default true.
		 * @param array<string, mixed> $plan    The plan (Offers).
		 * @param int                  $user    Customer.
		 */
		if ( ! apply_filters( 'beltoft_services_picker_can_buy', true, $plan, $user ) ) {
			$out['errors']['plan'] = __( 'This plan cannot be ordered right now.', 'beltoft-services' );

			return $out;
		}

		$out['plan']         = $plan;
		$out['variation_id'] = (int) $plan['variation_id'];

		if ( '' !== $values['backups'] ) {
			if ( empty( $plan['backups'] ) ) {
				$out['errors']['backups'] = __( 'Backups are not offered for this plan.', 'beltoft-services' );
			} else {
				$out['variation_id'] = (int) $plan['backups']['variation_id'];
			}
		}

		if ( 'usage' === ( $values['billing'] ?? '' ) ) {
			$usage   = (array) ( $plan['usage'] ?? array() );
			$backups = '' !== $values['backups'] && ! empty( $plan['backups'] );
			$target  = array() === $usage ? 0 : (int) ( $backups ? $usage['backups_variation_id'] : $usage['variation_id'] );

			if ( array() === $usage ) {
				$out['errors']['billing'] = __( 'Pay as you go is not offered for this plan.', 'beltoft-services' );
			} elseif ( $target < 1 ) {
				$out['errors']['billing'] = __( 'Pay as you go is not offered with daily backups for this plan.', 'beltoft-services' );
			} else {
				$out['variation_id'] = $target;
			}
		}

		$choices = Picked::clean( array() );
		$offer   = AppField::offer_for( (int) $plan['product_id'], (int) $plan['variation_id'] );

		if ( '' !== $values['app'] ) {
			$app = null === $offer ? __( 'Apps are not available for this server. Choose none.', 'beltoft-services' ) : AppField::resolve(
				$offer,
				array(
					'app'       => $values['app'],
					'version'   => $values['version'],
					'tier'      => $values['tier'],
					'domain_on' => '' !== $values['domain_on'],
					'zone'      => $values['zone'],
					'sub'       => $values['sub'],
					'own_on'    => '' !== $values['own_on'],
					'own'       => $values['own'],
				),
				$user
			);

			if ( is_string( $app ) ) {
				$out['errors']['app'] = $app;
			} else {
				$choices['app']       = $app['app'];
				$choices['version']   = $app['version'];
				$choices['tier']      = $app['tier'];
				$choices['zone']      = $app['zone'];
				$choices['sub']       = '' !== $app['domain'] ? $values['sub'] : '';
				$choices['domain_on'] = '' !== $app['domain'] ? '1' : '';
				$choices['own']       = $app['own'];
				// An app runs on its own operating system, whatever the tiles said.
				$values['os'] = (string) $offer['os'];
			}
		}

		$system = OsField::choice_for( (int) $plan['product_id'], (int) $plan['variation_id'] );

		if ( null !== $system ) {
			$why = OsField::check( $values['os'], $system );

			if ( null !== $why ) {
				$out['errors']['os'] = $why;
			} else {
				$choices['os'] = $values['os'];
			}
		}

		$name = ServerName::normalise( $values['name'] );

		if ( null === $name ) {
			$out['errors']['name'] = ServerName::invalid_message();
		} else {
			$choices['name'] = $name;
		}

		if ( '' !== $values['ssh_key'] ) {
			$key = SshKeyField::normalise( $values['ssh_key'] );

			if ( null === $key ) {
				$out['errors']['ssh_key'] = SshKeyField::invalid_message();
			} else {
				$choices['ssh_key'] = $key;
			}
		}

		$out['choices'] = Picked::clean( $choices );

		return $out;
	}

	/**
	 * Replaces an earlier try's server in the cart with this one. Everything runs through WooCommerce, so the stock checks and the
	 * shop's other cart rules apply as for any add-to-cart.
	 *
	 * @param array{variation_id: int, plan: array<string, mixed>|null, choices: array<string, string>} $checked Result of check().
	 * @return string|null The refusal text, or null when the cart holds the server.
	 */
	private static function add_to_cart( array $checked ): ?string {
		$cart      = function_exists( 'WC' ) ? WC()->cart : null;
		$variation = wc_get_product( $checked['variation_id'] );

		if ( ! $cart || ! $variation instanceof \WC_Product_Variation || null === $checked['plan'] ) {
			return __( 'The server could not be added. Please try again.', 'beltoft-services' );
		}

		// A payment for a subscription that exists (renewal, switch, resubscribe) is never touched or mixed with a new server.
		$old = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( DiskCart::flow( (array) $item ) ) {
				return __( 'Finish or remove the subscription payment in your cart first.', 'beltoft-services' );
			}

			$driver = Product::driver_for( (int) ( $item['product_id'] ?? 0 ) );

			// The earlier try's server, and the extra disk or app licence that was tied to it.
			if ( ( $driver && SshKeyField::DRIVER === $driver->id() ) || 'cart_server' === ( $item['beltoft_services_disk_for'] ?? null ) || isset( $item[ AppLicence::CART_UID ] ) ) {
				$old[] = (string) $key;
			}
		}

		wc_clear_notices();

		$key = $cart->add_to_cart( (int) $checked['plan']['product_id'], 1, $variation->get_id(), BackupField::attributes( $variation ), array( Picked::KEY => $checked['choices'] ) );

		if ( false !== $key ) {
			// The old lines go only now that the new one is in. The shop treats the same plan with the same choices as "already in
			// the cart" and returns that line: it stays, at one.
			DiskField::during_server_swap(
				static function () use ( $cart, $old, $key ): bool {
					foreach ( $old as $old_key ) {
						if ( (string) $key === $old_key ) {
							$cart->set_quantity( $old_key, 1, false );
						} else {
							$cart->remove_cart_item( $old_key );
						}
					}

					return true;
				}
			);

			// The tier of a paid app is its own line, tied to the server that is in the cart now.
			if ( '' !== $checked['choices']['app'] && AppLicence::paid( $checked['choices']['app'] ) && ! AppLicenceCart::choose( $checked['choices']['app'], $checked['choices']['tier'] ) ) {
				return __( 'The app licence could not be added. Please try again.', 'beltoft-services' );
			}

			$cart->calculate_totals();

			return null;
		}

		$notes = array();

		foreach ( (array) wc_get_notices( 'error' ) as $notice ) {
			$text = is_array( $notice ) ? (string) ( $notice['notice'] ?? '' ) : (string) $notice;

			if ( '' !== $text ) {
				$notes[] = wp_strip_all_tags( $text );
			}
		}

		wc_clear_notices();

		return array() !== $notes ? implode( ' ', $notes ) : __( 'The server could not be added. Please try again.', 'beltoft-services' );
	}

	// -- Assets ------------------------------------------------------------------------------------

	/**
	 * The page's own style and script, with the tiles' and the app fields' (account page loader). Nothing loads elsewhere.
	 *
	 * @param mixed $parent   Handle of the generic account script.
	 * @param mixed $endpoint The endpoint being shown.
	 */
	public static function assets( $parent = null, $endpoint = null ): void {
		if ( ! is_string( $parent ) || ( is_string( $endpoint ) && Servers::slug() !== $endpoint ) || ! self::on_page() || ! is_user_logged_in() ) {
			return;
		}

		Tiles::enqueue();
		AppField::enqueue();
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/picker.css', BELTOFT_SERVICES_FILE ), array( Tiles::HANDLE ), \BeltoftServices\Assets::version( 'assets/picker.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/picker.js', BELTOFT_SERVICES_FILE ), array( Tiles::HANDLE ), \BeltoftServices\Assets::version( 'assets/picker.js' ), true );
	}

	// -- The page ----------------------------------------------------------------------------------

	/**
	 * "4 GB" or "512 MB".
	 *
	 * @param int $mb Megabytes.
	 */
	public static function memory( int $mb ): string {
		if ( $mb >= 1024 ) {
			/* translators: %s: memory in gigabytes, such as 4 or 1.5 */
			return sprintf( __( '%s GB RAM', 'beltoft-services' ), (string) ( round( $mb / 1024, 1 ) + 0 ) );
		}

		/* translators: %d: memory in megabytes */
		return sprintf( __( '%d MB RAM', 'beltoft-services' ), $mb );
	}

	/**
	 * A price as plain text, with what it is per: "€4.00 per month excluding VAT".
	 *
	 * @param float  $amount Amount excluding tax.
	 * @param string $period "month", "3 months" or '' for a one-off.
	 */
	public static function price_text( float $amount, string $period ): string {
		$money = html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );

		if ( '' === $period ) {
			/* translators: %s: price such as €4.00 */
			return sprintf( __( '%s excluding VAT', 'beltoft-services' ), $money );
		}

		/* translators: 1: price such as €4.00, 2: period such as month */
		return sprintf( __( '%1$s per %2$s excluding VAT', 'beltoft-services' ), $money, $period );
	}

	/**
	 * Prints the page.
	 */
	public static function render(): void {
		$products = Offers::products();
		$plans    = Offers::plans();
		$values   = array_merge( Picked::clean( array() ), array( 'plan' => '', 'backups' => '', 'billing' => '' ), self::$state['values'] );
		$errors   = self::$state['errors'];
		$selected = self::selected( $plans, (int) $values['plan'] );

		echo '<div class="beltoft-services-picker">';
		echo '<p class="beltoft-services-server__back"><a href="' . esc_url( Servers::url_list() ) . '">&lsaquo; ' . esc_html__( 'All servers', 'beltoft-services' ) . '</a></p>';
		echo '<h2 class="beltoft-services-picker__title">' . esc_html__( 'New server', 'beltoft-services' ) . '</h2>';

		if ( array() === $plans ) {
			echo '<p class="beltoft-services-notice" role="status">' . esc_html__( 'No server can be ordered right now.', 'beltoft-services' ) . '</p></div>';

			return;
		}

		echo '<p class="beltoft-services-picker__intro">' . esc_html__( 'Choose a plan and what the server starts with. You pay on the next page.', 'beltoft-services' ) . '</p>';
		self::error( $errors, 'form' );

		echo '<form class="beltoft-services-picker__form" method="post" action="' . esc_url( self::url() ) . '">';
		wp_nonce_field( self::NONCE, self::FIELD );

		$available = array_filter( $plans, static fn( $plan ) => ! empty( $plan['in_stock'] ) );
		$unknown   = array() !== array_filter( $available, static fn( $plan ) => ! empty( $plan['unknown'] ) );

		echo '<fieldset class="beltoft-services-picker__plans"><legend class="beltoft-services-picker__legend">' . esc_html__( 'Plan', 'beltoft-services' ) . '</legend>';

		if ( array() === $available ) {
			echo '<p class="beltoft-services-picker__soldout">' . esc_html__( 'Every plan is out of stock right now. Please check back soon.', 'beltoft-services' ) . '</p>';
		}

		foreach ( $products as $product ) {
			if ( count( $products ) > 1 ) {
				echo '<p class="beltoft-services-picker__product">' . esc_html( $product['name'] ) . '</p>';
			}

			echo '<div class="beltoft-services-picker__grid">';

			foreach ( $product['plans'] as $plan ) {
				self::card( $plan, (int) $plan['variation_id'] === $selected );
			}

			echo '</div>';
		}

		if ( $unknown ) {
			echo '<p class="beltoft-services-picker__soldout">' . esc_html__( 'Availability is checked again at checkout.', 'beltoft-services' ) . '</p>';
		}

		self::error( $errors, 'plan' );
		echo '</fieldset>';

		self::backups( $plans, $selected, '' !== $values['backups'] );
		self::error( $errors, 'backups' );

		self::billing( $plans, $selected, 'usage' === $values['billing'], '' !== $values['backups'] );
		self::error( $errors, 'billing' );

		$chosen = $plans[ $selected ] ?? reset( $plans );

		if ( $chosen ) {
			self::choices( $chosen, $values, $errors );
		}

		echo '<div class="beltoft-services-picker__go">';
		echo '<p class="beltoft-services-picker__summary" data-picker-summary aria-live="polite">' . esc_html( isset( $plans[ $selected ] ) ? self::summary( $plans[ $selected ], '' !== $values['backups'], 'usage' === $values['billing'] ) : '' ) . '</p>';
		echo '<button type="submit" class="beltoft-services-picker__button" data-picker-submit' . ( isset( $plans[ $selected ] ) ? '' : ' disabled' ) . '>' . esc_html__( 'Continue to checkout', 'beltoft-services' ) . '</button>';
		echo '</div>';

		echo '</form></div>';
	}

	/**
	 * The plan that starts selected: the posted one, else ?plan=, else the first that can be bought; 0 when none can.
	 *
	 * @param array<int, array<string, mixed>> $plans  Offered plans.
	 * @param int                              $posted Posted plan.
	 */
	private static function selected( array $plans, int $posted ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a starting choice that only selects among the offered plans.
		$asked = isset( $_GET['plan'] ) ? absint( wp_unslash( $_GET['plan'] ) ) : 0;

		foreach ( array( $posted, $asked ) as $id ) {
			if ( $id > 0 && isset( $plans[ $id ] ) && ( ! empty( $plans[ $id ]['in_stock'] ) || $id === $posted ) ) {
				return $id;
			}
		}

		foreach ( $plans as $id => $plan ) {
			if ( ! empty( $plan['in_stock'] ) ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * One plan card: a radio, with the name, the site's short note when it gives one, the price and the numbers. A plan that cannot be bought says so in plain text.
	 *
	 * @param array<string, mixed> $plan     Plan.
	 * @param bool                 $selected Checked.
	 */
	private static function card( array $plan, bool $selected ): void {
		$stock = ! empty( $plan['in_stock'] );
		$extra = $plan['backups'];
		$attrs = array(
			'data-cores'            => (string) $plan['cores'],
			'data-memory'           => (string) $plan['memory_mb'],
			'data-disk'             => (string) $plan['disk_gb'],
			'data-summary'          => self::summary( $plan, false ),
			'data-has-backups'      => $extra ? '1' : '0',
			'data-backups-label'    => $extra ? self::backups_label( $plan ) : '',
			'data-summary-backups'  => $extra ? self::summary( $plan, true ) : '',
			'data-has-usage'        => $plan['usage'] ? '1' : '0',
			'data-has-usage-backups' => $plan['usage'] && $plan['usage']['backups_variation_id'] ? '1' : '0',
		);

		if ( $plan['usage'] ) {
			$attrs += array(
				'data-usage-label'          => self::usage_label( $plan, false ),
				'data-usage-label-backups'  => self::usage_label( $plan, true ),
				'data-usage-note'           => BillingField::note(),
				'data-usage-note-backups'   => BillingField::note(),
				'data-summary-usage'        => self::summary( $plan, false, true ),
				'data-summary-usage-backups' => self::summary( $plan, true, true ),
			);
		}

		echo '<label class="beltoft-services-plan' . ( $stock ? '' : ' is-disabled' ) . '">';
		echo '<input class="beltoft-services-plan__input" type="radio" name="' . esc_attr( self::PLAN ) . '" value="' . esc_attr( (string) $plan['variation_id'] ) . '"';
		checked( $selected, true );
		disabled( ! $stock, true );

		foreach ( $attrs as $name => $value ) {
			echo ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}

		echo '>';
		echo '<span class="beltoft-services-plan__face">';
		echo '<span class="beltoft-services-plan__name">' . esc_html( (string) $plan['name'] ) . '</span>';

		if ( '' !== (string) ( $plan['note'] ?? '' ) ) {
			echo '<span class="beltoft-services-plan__note">' . esc_html( (string) $plan['note'] ) . '</span>';
		}

		echo '<span class="beltoft-services-plan__price"><b>' . wp_kses_post( wc_price( (float) $plan['price'] ) ) . '</b> <span>' . esc_html( self::per( (string) $plan['period'] ) ) . '</span></span>';
		echo '<span class="beltoft-services-plan__specs">';
		/* translators: %d: number of virtual CPU cores */
		echo '<span>' . esc_html( sprintf( _n( '%d vCPU', '%d vCPU', (int) $plan['cores'], 'beltoft-services' ), (int) $plan['cores'] ) ) . '</span>';
		echo '<span>' . esc_html( self::memory( (int) $plan['memory_mb'] ) ) . '</span>';
		/* translators: %d: disk size in gigabytes */
		echo '<span>' . esc_html( sprintf( __( '%d GB disk', 'beltoft-services' ), (int) $plan['disk_gb'] ) ) . '</span>';
		echo '</span>';

		if ( ! $stock ) {
			echo '<span class="beltoft-services-plan__stock">' . esc_html__( 'Out of stock', 'beltoft-services' ) . '</span>';
		}

		echo '</span></label>';
	}

	/**
	 * "per month excluding VAT".
	 *
	 * @param string $period Period.
	 */
	public static function per( string $period ): string {
		if ( '' === $period ) {
			return __( 'excluding VAT', 'beltoft-services' );
		}

		/* translators: %s: period such as month */
		return sprintf( __( 'per %s excluding VAT', 'beltoft-services' ), $period );
	}

	/**
	 * The backups line of a plan: "Daily backups, kept for 7 days (+€2.00 per month excluding VAT)".
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	private static function backups_label( array $plan ): string {
		$extra = (array) $plan['backups'];
		$money = html_entity_decode( wp_strip_all_tags( wc_price( (float) $extra['extra'] ) ), ENT_QUOTES, 'UTF-8' );
		$per   = '' === (string) $plan['period'] ? '' : ' ' . sprintf( /* translators: %s: period such as month */ __( 'per %s', 'beltoft-services' ), (string) $plan['period'] );

		/* translators: 1: price difference such as €2.00, 2: " per month" or empty */
		$price = sprintf( __( '+%1$s%2$s excluding VAT', 'beltoft-services' ), $money, $per );

		if ( (int) $extra['days'] > 0 ) {
			/* translators: 1: number of days, 2: price */
			return sprintf( _n( 'Daily backups, kept for %1$d day (%2$s)', 'Daily backups, kept for %1$d days (%2$s)', (int) $extra['days'], 'beltoft-services' ), (int) $extra['days'], $price );
		}

		/* translators: %s: price */
		return sprintf( __( 'Daily backups (%s)', 'beltoft-services' ), $price );
	}

	/**
	 * One line for the chosen plan: "Starter: €4.00 per month excluding VAT".
	 *
	 * @param array<string, mixed> $plan    Plan.
	 * @param bool                 $backups With backups.
	 * @param bool                 $usage   Pay as you go.
	 */
	private static function summary( array $plan, bool $backups, bool $usage = false ): string {
		$backups = $backups && $plan['backups'];

		if ( $usage && $plan['usage'] ) {
			$price = self::usage_label( $plan, $backups );

			if ( $backups ) {
				/* translators: 1: plan name, 2: price */
				return sprintf( __( '%1$s with daily backups, pay as you go: %2$s', 'beltoft-services' ), (string) $plan['name'], $price );
			}

			/* translators: 1: plan name, 2: price */
			return sprintf( __( '%1$s, pay as you go: %2$s', 'beltoft-services' ), (string) $plan['name'], $price );
		}

		$extra = $backups ? (float) $plan['backups']['extra'] : 0.0;
		$price = self::price_text( (float) $plan['price'] + $extra, (string) $plan['period'] );

		if ( $backups ) {
			/* translators: 1: plan name, 2: price */
			return sprintf( __( '%1$s with daily backups: %2$s', 'beltoft-services' ), (string) $plan['name'], $price );
		}

		/* translators: 1: plan name, 2: price */
		return sprintf( __( '%1$s: %2$s', 'beltoft-services' ), (string) $plan['name'], $price );
	}

	/**
	 * The hourly price and the cap of a plan's pay-as-you-go option.
	 *
	 * @param array<string, mixed> $plan    Plan.
	 * @param bool                 $backups With backups.
	 * @return array{hourly: float, cap: float}
	 */
	private static function usage_rates( array $plan, bool $backups ): array {
		$usage = (array) $plan['usage'];

		return $backups && ! empty( $usage['backups_variation_id'] )
			? array( 'hourly' => (float) $usage['backups_hourly'], 'cap' => (float) $usage['backups_cap'] )
			: array( 'hourly' => (float) $usage['hourly'], 'cap' => (float) $usage['cap'] );
	}

	/**
	 * "€0.0226 per hour, max €16.50 per month excluding VAT".
	 *
	 * @param array<string, mixed> $plan    Plan.
	 * @param bool                 $backups With backups.
	 */
	private static function usage_label( array $plan, bool $backups ): string {
		$rates = self::usage_rates( $plan, $backups );

		return Rates::terms( $rates['hourly'], $rates['cap'] );
	}

	/**
	 * The billing choice under the backups box: Monthly or Pay as you go. The script shows it for plans that have the option.
	 *
	 * @param array<int, array<string, mixed>> $plans    Plans.
	 * @param int                              $selected Selected plan.
	 * @param bool                             $usage    Pay as you go chosen.
	 * @param bool                             $backups  Backups ticked.
	 */
	private static function billing( array $plans, int $selected, bool $usage, bool $backups ): void {
		if ( array() === array_filter( $plans, static fn( $plan ) => ! empty( $plan['usage'] ) ) ) {
			return;
		}

		$plan  = $plans[ $selected ] ?? null;
		$shown = $plan && ! empty( $plan['usage'] );
		$with  = $shown && $backups && ! empty( $plan['backups'] ) && ! empty( $plan['usage']['backups_variation_id'] );

		echo '<fieldset class="beltoft-services-picker__billing" data-picker-billing' . ( $shown ? '' : ' hidden' ) . '><legend class="beltoft-services-picker__legend">' . esc_html__( 'Billing', 'beltoft-services' ) . '</legend>';
		echo '<label><input type="radio" name="' . esc_attr( self::BILLING ) . '" value="monthly"';
		checked( ! ( $usage && $shown ), true );
		echo '> <span>' . esc_html__( 'Monthly', 'beltoft-services' ) . '</span></label>';
		echo '<label><input type="radio" name="' . esc_attr( self::BILLING ) . '" value="usage"';
		checked( $usage && $shown, true );
		echo '> <span>' . esc_html__( 'Pay as you go', 'beltoft-services' ) . '</span> <span class="beltoft-services-picker__rate" data-picker-usage-label>' . esc_html( $shown ? self::usage_label( $plan, $with ) : '' ) . '</span></label>';
		echo '<p class="description" data-picker-usage-note' . ( '' === BillingField::note() ? ' hidden' : '' ) . '>' . esc_html( $shown ? BillingField::note() : '' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * The backups tick box under the cards; the script shows it for plans that have the option.
	 *
	 * @param array<int, array<string, mixed>> $plans    Plans.
	 * @param int                              $selected Selected plan.
	 * @param bool                             $on       Ticked.
	 */
	private static function backups( array $plans, int $selected, bool $on ): void {
		$any   = array_filter( $plans, static fn( $plan ) => ! empty( $plan['backups'] ) );
		$plan  = $plans[ $selected ] ?? null;
		$shown = $plan && ! empty( $plan['backups'] );

		if ( array() === $any ) {
			return;
		}

		echo '<p class="beltoft-services-picker__backups" data-picker-backups' . ( $shown ? '' : ' hidden' ) . '><label><input type="checkbox" name="' . esc_attr( self::BACKUP ) . '" value="1"';
		checked( $on && $shown, true );
		echo '> <span data-picker-backups-label>' . esc_html( $shown ? self::backups_label( $plan ) : '' ) . '</span></label></p>';
	}

	/**
	 * The choices under the plan: the operating system, the app, the name and the key, as checkout prints them.
	 *
	 * @param array<string, mixed>  $plan   The plan the page starts on.
	 * @param array<string, string> $values Values to start from.
	 * @param array<string, string> $errors Messages by field.
	 */
	private static function choices( array $plan, array $values, array $errors ): void {
		$product = (int) $plan['product_id'];
		$variant = (int) $plan['variation_id'];
		$system  = OsField::choice_for( $product, $variant );
		$offer   = AppField::offer_for( $product, $variant );

		if ( null !== $system ) {
			$os = (int) $values['os'];

			OsField::print_field( $system, isset( $system['options'][ $os ] ) ? $os : (int) $system['default'] );
			self::error( $errors, 'os' );
		}

		if ( null !== $offer ) {
			AppField::print_fields( $offer, $values );
			self::error( $errors, 'app' );
		}

		echo '<div class="beltoft-services-picker__more">';
		ServerName::print_field( $values['name'] );
		self::error( $errors, 'name' );
		SshKeyField::print_field( $values['ssh_key'] );
		self::error( $errors, 'ssh_key' );
		echo '</div>';
	}

	/**
	 * Prints a message at a field.
	 *
	 * @param array<string, string> $errors Messages by field.
	 * @param string                $field  Field.
	 */
	private static function error( array $errors, string $field ): void {
		if ( isset( $errors[ $field ] ) ) {
			echo '<p class="beltoft-services-picker__error" role="alert">' . esc_html( $errors[ $field ] ) . '</p>';
		}
	}
}
