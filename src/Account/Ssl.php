<?php
/**
 * The SSL certificates area of My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Resource;
use BeltoftServices\Ssl\Panels;

defined( 'ABSPATH' ) || exit;

/**
 * Certificates (driver kind `ssl`): a row per certificate (the name, its state and when it ends) and a page for each.
 */
final class Ssl extends ServiceArea {

	/**
	 * The menu item goes after Email, with the other services.
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public static function menu( $items ) {
		$slug  = ServiceArea::get( self::class )->slug();
		$after = ServiceArea::get( Email::class )->slug();

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
	 * The "Order a certificate" button: the first published product that uses the ssl driver, or what the filter says.
	 */
	public function url_add(): string {
		$url = self::product_url( array( 'ssl' ) );

		/**
		 * The address of the "Order a certificate" button. Return '' for no button.
		 *
		 * @param string $url The first certificate product, or ''.
		 */
		return (string) apply_filters( 'beltoft_services_ssl_add_url', $url );
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
