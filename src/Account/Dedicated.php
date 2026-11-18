<?php
/**
 * The dedicated servers area of My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Dedicated servers (driver kind `dedicated`): a row per server (state above the name, specs and country below) and a page
 * for each with the addresses and the Cancel block.
 */
final class Dedicated extends ServiceArea {

	/**
	 * The menu item goes after Online backup, with the other services.
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public static function menu( $items ) {
		return self::move_after( $items, ServiceArea::get( self::class )->slug(), ServiceArea::get( Storage::class )->slug() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function buttons(): array {
		$url = self::product_url( array( \BeltoftServices\ManualServer\ManualServerDriver::ID ) );

		return '' === $url ? array() : array( array( 'label' => __( 'Order a dedicated server', 'beltoft-services' ), 'url' => $url ) );
	}

	protected function key(): string {
		return 'dedicated';
	}

	protected function default_slug(): string {
		return 'dedicated-servers';
	}

	protected function kinds(): array {
		return array( \BeltoftServices\ManualServer\ManualServerDriver::KIND );
	}

	public function label(): string {
		return __( 'Dedicated servers', 'beltoft-services' );
	}

	protected function empty_text(): string {
		return __( 'You have no dedicated server yet.', 'beltoft-services' );
	}

	public function all_label(): string {
		return __( 'All dedicated servers', 'beltoft-services' );
	}

	protected function not_found_text(): string {
		return __( 'That dedicated server was not found. Here are your dedicated servers.', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $resource Resource.
	 */
	public function label_of( Resource $resource ): string {
		unset( $resource );

		return __( 'Dedicated server', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param Resource             $resource Resource.
	 */
	public function row( array $view, Resource $resource ): array {
		list( $state, $tone ) = self::state( $resource );

		$plan    = (array) $resource->get( 'plan' );
		$details = array_filter( array( (string) ( $plan['server_memory'] ?? '' ), (string) ( $plan['server_location'] ?? '' ) ) );

		return array(
			'id'      => $resource->id(),
			'url'     => $this->url( $view['license'] ),
			'name'    => '' !== (string) $resource->get( 'server_name' ) ? (string) $resource->get( 'server_name' ) : (string) ( $view['product'] ?? '' ),
			'details' => implode( ', ', $details ),
			'state'   => $state,
			'tone'    => $tone,
			'extra'   => '',
			'action'  => array(),
		);
	}
}
