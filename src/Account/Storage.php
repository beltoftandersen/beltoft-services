<?php
/**
 * The backup storage area of My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Resource;
use BeltoftServices\Storage\StoragePanel;

defined( 'ABSPATH' ) || exit;

/**
 * Backup storage (driver kind `storage`): a row per box (state above the name, size and location below) and a page for each.
 */
final class Storage extends ServiceArea {

	/**
	 * The menu item goes after Licences, with the other services.
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public static function menu( $items ) {
		return self::move_after( $items, ServiceArea::get( self::class )->slug(), ServiceArea::get( Licences::class )->slug() );
	}

	/**
	 * The "Order backup storage" button: the first published product that uses the storage driver, or what the filter says.
	 */
	public function url_add(): string {
		/**
		 * The address of the "Order backup storage" button. Return '' for no button.
		 *
		 * @param string $url The first storage product, or ''.
		 */
		return (string) apply_filters( 'beltoft_services_storage_add_url', self::product_url( array( 'storage' ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function buttons(): array {
		$url = $this->url_add();

		return '' === $url ? array() : array( array( 'label' => __( 'Order online backup', 'beltoft-services' ), 'url' => $url ) );
	}

	protected function key(): string {
		return 'storage';
	}

	protected function default_slug(): string {
		return 'backup-storage';
	}

	protected function kinds(): array {
		return array( 'storage' );
	}

	public function label(): string {
		return __( 'Online backup', 'beltoft-services' );
	}

	protected function empty_text(): string {
		return __( 'You have no online backup yet.', 'beltoft-services' );
	}

	public function all_label(): string {
		return __( 'All online backups', 'beltoft-services' );
	}

	protected function not_found_text(): string {
		return __( 'That online backup was not found. Here is your online backup.', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $resource Resource.
	 */
	public function label_of( Resource $resource ): string {
		unset( $resource );

		return __( 'Online backup', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param Resource             $resource Resource.
	 */
	public function row( array $view, Resource $resource ): array {
		list( $state, $tone ) = self::state( $resource );

		$size    = (int) $resource->get( 'storage_size_bytes' );
		$country = \BeltoftServices\Storage\StorageSettings::country();

		return array(
			'id'      => $resource->id(),
			'url'     => $this->url( $view['license'] ),
			'name'    => (string) ( $view['product'] ?? '' ),
			'details' => $size > 0 ? \BeltoftServices\Storage\Boxes::size_text( $size ) . ', ' . $country : $country,
			'state'   => 'ready' === (string) $resource->get( 'state' ) ? StoragePanel::status_text( $resource ) : $state,
			'tone'    => $tone,
			'extra'   => '',
			'action'  => array(),
		);
	}
}
