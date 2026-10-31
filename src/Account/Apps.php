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
	 * An app's page shows no technical driver name ("HTTP API"): the app's own name is its title.
	 *
	 * @param Resource $resource Resource.
	 */
	public function label_of( Resource $resource ): string {
		unset( $resource );

		return '';
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

		/**
		 * The address of an app's own page inside My Account, for a plugin that draws the app there. When it gives one,
		 * the Open button goes to it, in the same window, instead of leaving for the app's own site.
		 *
		 * @param string   $url      Address, '' for none.
		 * @param Resource $resource The app's resource.
		 * @param array<string, mixed> $license License row.
		 */
		$inside = (string) apply_filters( 'beltoft_services_account_app_url', '', $resource, $view['license'] );

		if ( $http && '' !== $inside && 'ready' === (string) $resource->get( 'state' ) ) {
			$action = array(
				'label'    => $http->open_label( $resource ),
				'url'      => $inside,
				'internal' => true,
			);
		} elseif ( $http && $http->can_open_resource( $resource ) ) {
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
