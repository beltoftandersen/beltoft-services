<?php
/**
 * The licences area of My Account: standalone Plesk licences and add-on keys.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Licences\KeyPanel;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Licences (driver kind `lickey`): a row per licence (its name, the server address and its state) and a page for each
 * that shows the key.
 */
final class Licences extends ServiceArea {

	/**
	 * Hooks: the place in the list of areas, the menu position.
	 */
	public static function register(): void {
		ServiceArea::offer( self::class, 70 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 102 );
	}

	/**
	 * The menu item goes after SSL certificates, with the other services.
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public static function menu( $items ) {
		return self::move_after( $items, ServiceArea::get( self::class )->slug(), ServiceArea::get( Ssl::class )->slug() );
	}

	/**
	 * The "Order a licence" button: the first published product that uses the licence driver.
	 */
	public function url_add(): string {
		$url = self::product_url( array( 'licence-key' ) );

		return (string) $url;
	}

	/**
	 * {@inheritDoc}
	 */
	public function buttons(): array {
		$url = $this->url_add();

		return '' === $url ? array() : array(
			array(
				'label' => __( 'Order a licence', 'beltoft-services' ),
				'url'   => $url,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function key(): string {
		return 'licences';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_slug(): string {
		return 'licences';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function kinds(): array {
		return array( 'lickey' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Server licences', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function empty_text(): string {
		return __( 'You have no server licences yet.', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function all_label(): string {
		return __( 'All server licences', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function not_found_text(): string {
		return __( 'That licence was not found. Here are your licences.', 'beltoft-services' );
	}

	/**
	 * The page's title is the licence name.
	 *
	 * @param Resource             $resource Resource.
	 * @param array<string, mixed> $view     Display values of the license.
	 */
	public function title_of( Resource $resource, array $view ): string {
		unset( $view );

		return KeyPanel::label( $resource );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $resource Resource.
	 */
	public function label_of( Resource $resource ): string {
		unset( $resource );

		return __( 'Licence', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param Resource             $resource Resource.
	 */
	public function row( array $view, Resource $resource ): array {
		list( $state, $tone ) = self::state( $resource );

		$ready = 'ready' === (string) $resource->get( 'state' );

		return array(
			'id'      => $resource->id(),
			'url'     => $this->url( $view['license'] ),
			'name'    => KeyPanel::label( $resource ),
			'details' => (string) $resource->get( 'lic_ip' ),
			'state'   => $ready ? KeyPanel::status_text( $resource ) : $state,
			'tone'    => $tone,
			'extra'   => '',
			'action'  => array(),
		);
	}
}
