<?php
/**
 * The SSL certificates area of My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Ssl;

use BeltoftServices\Resource;
use BeltoftServices\Ssl\Panels;
use BeltoftServices\Account\Email;
use BeltoftServices\Account\ServiceArea;

defined( 'ABSPATH' ) || exit;

/**
 * Certificates (driver kind `ssl`): a row per certificate (the name, its state and when it ends) and a page for each.
 */
final class Ssl extends ServiceArea {

	/**
	 * Hooks: the place in the list of areas, the menu position.
	 */
	public static function register(): void {
		ServiceArea::offer( self::class, 60 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 101 );
	}

	/**
	 * The menu item goes after Email, with the other services.
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public static function menu( $items ) {
		return self::move_after( $items, ServiceArea::get( self::class )->slug(), ServiceArea::get( Email::class )->slug() );
	}

	/**
	 * The "Order a certificate" button: the first published product that uses the ssl driver.
	 */
	public function url_add(): string {
		$url = self::product_url( array( 'ssl' ) );

		return (string) $url;
	}

	/**
	 * {@inheritDoc}
	 */
	public function buttons(): array {
		$url = $this->url_add();

		return '' === $url ? array() : array(
			array(
				'label' => __( 'Order a certificate', 'beltoft-services' ),
				'url'   => $url,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function key(): string {
		return 'ssl';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_slug(): string {
		return 'ssl-certificates';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function kinds(): array {
		return array( 'ssl' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'SSL certificates', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function empty_text(): string {
		return __( 'You have no SSL certificates yet.', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function all_label(): string {
		return __( 'All SSL certificates', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function not_found_text(): string {
		return __( 'That certificate was not found. Here are your certificates.', 'beltoft-services' );
	}

	/**
	 * The page's title is the name on the certificate.
	 *
	 * @param Resource             $resource Resource.
	 * @param array<string, mixed> $view     Display values of the license.
	 */
	public function title_of( Resource $resource, array $view ): string {
		$name = (string) $resource->get( 'ssl_name' );

		return '' !== $name ? \BeltoftServices\Domains\HostName::display( $name ) : (string) ( $view['product'] ?? '' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $resource Resource.
	 */
	public function label_of( Resource $resource ): string {
		return \BeltoftServices\Ssl\Provision::is_smime( $resource ) ? __( 'S/MIME certificate', 'beltoft-services' ) : __( 'SSL certificate', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param Resource             $resource Resource.
	 */
	public function row( array $view, Resource $resource ): array {
		list( $state, $tone ) = self::state( $resource );

		$status = (string) $resource->get( 'ssl_status' );
		$to     = (string) $resource->get( 'ssl_valid_to' );

		return array(
			'id'      => $resource->id(),
			'url'     => $this->url( $view['license'] ),
			'name'    => '' !== (string) $resource->get( 'ssl_name' ) ? \BeltoftServices\Domains\HostName::display( (string) $resource->get( 'ssl_name' ) ) : (string) ( $view['product'] ?? '' ),
			'details' => (string) ( $view['product'] ?? '' ),
			'state'   => 'ready' === (string) $resource->get( 'state' ) ? Panels::status_text( $resource ) : $state,
			'tone'    => $tone,
			'extra'   => 'ACT' === $status && '' !== $to ? sprintf( /* translators: %s: date. */ __( 'Valid to %s', 'beltoft-services' ), wp_date( get_option( 'date_format' ), (int) strtotime( $to . ' UTC' ) ) ) : '',
			'action'  => array(),
		);
	}
}
