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
	 * The menu item goes after SSL certificates, with the other services.
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public static function menu( $items ) {
		$slug  = ServiceArea::get( self::class )->slug();
		$after = ServiceArea::get( Ssl::class )->slug();

		if ( ! is_array( $items ) || ! isset( $items[ $slug ] ) || ! isset( $items[ $after ] ) ) {
			return $items;
		}

		$label = $items[ $slug ];
		unset( $items[ $slug ] );
		$out = array();

		foreach ( $items as $key => $value ) {
			$out[ $key ] = $value;

			if ( $after === $key ) {
				$out[ $slug ] = $label;
			}
		}

		return $out;
	}

	/**
	 * The "Order a licence" button: the first published product that uses the licence driver, or what the filter says.
	 */
	public function url_add(): string {
		$url = self::product_url( array( 'licence-key' ) );

		/**
		 * The address of the "Order a licence" button. Return '' for no button.
		 *
		 * @param string $url The first licence product, or ''.
		 */
		return (string) apply_filters( 'beltoft_services_licences_add_url', $url );
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
