<?php
/**
 * The Apps area of My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Api\Routes;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Http\HttpDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Apps provisioned over HTTP (driver kind `http`, for example an OdooWoo app): a row per app (name, state, usage
 * and an Open button that uses the open route) and a page with what the HTTP driver prints in the service panel.
 */
final class Apps extends ServiceArea {

	/**
	 * {@inheritDoc}
	 */
	protected function key(): string {
		return 'apps';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_slug(): string {
		return 'apps';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function kinds(): array {
		return array( 'http' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Apps', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function empty_text(): string {
		return __( 'You have no apps yet.', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function all_label(): string {
		return __( 'All apps', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function not_found_text(): string {
		return __( 'That app was not found. Here are your apps.', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param Resource             $resource Resource.
	 */
	public function row( array $view, Resource $resource ): array {
		list( $state, $tone ) = self::state( $resource );

		$driver = Registry::get( (string) $resource->get( 'driver' ) );
		$http   = $driver instanceof HttpDriver ? $driver : null;
		$action = array();

		if ( $http && $http->can_open_resource( $resource ) ) {
			$action = array(
				'label' => $http->open_label( $resource ),
				'url'   => Routes::open_url( $resource->id() ),
			);
		}

		return array(
			'id'      => $resource->id(),
			'url'     => $this->url( $view['license'] ),
			'name'    => (string) ( $view['product'] ?? '' ),
			'details' => $http ? $http->usage_text( $resource ) : '',
			'state'   => $state,
			'tone'    => $tone,
			'extra'   => '',
			'action'  => $action,
		);
	}
}
