<?php
/**
 * The Databases area of My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Proxmox\DbCard;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Managed databases (driver kind `db`): a row per database (name, engine and major version, state, private host
 * address) and a page with an Overview tab (the connection details of DbCard), a Backups tab and a New password tab.
 */
final class Databases extends ServiceArea {

	/**
	 * The value after the endpoint that is the New database page (DbPicker).
	 */
	public const NEW_VALUE = 'new';

	/**
	 * The address of the New database page.
	 */
	public function url_new(): string {
		return function_exists( 'wc_get_endpoint_url' ) ? wc_get_endpoint_url( $this->slug(), self::NEW_VALUE, wc_get_page_permalink( 'myaccount' ) ) : '';
	}

	/**
	 * Is the address the New database page (the value after the endpoint is "new")?
	 */
	public function is_new(): bool {
		global $wp;

		$value = is_object( $wp ) && isset( $wp->query_vars[ $this->slug() ] ) ? (string) $wp->query_vars[ $this->slug() ] : '';
		$parts = explode( '/', trim( sanitize_text_field( $value ), '/' ) );

		return self::NEW_VALUE === $parts[0];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int, array<string, mixed>> $views License views.
	 * @param string                           $empty Text for an empty tab.
	 */
	public function render( array $views, string $empty = '' ): void {
		if ( $this->is_new() ) {
			DbPicker::render();

			return;
		}

		parent::render( $views, $empty );
	}

	/**
	 * {@inheritDoc}
	 */
	public function buttons(): array {
		$url = '' !== self::product_url( array( DbDriver::ID ) ) ? $this->url_new() : '';

		/**
		 * The address of the "Create a database" button: the New database page when a database product is published.
		 * Return '' for no button.
		 *
		 * @param string $url Address, or ''.
		 */
		$url = (string) apply_filters( 'beltoft_services_databases_add_url', $url );

		return '' === $url ? array() : array(
			array(
				'label' => __( 'Create a database', 'beltoft-services' ),
				'url'   => $url,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function key(): string {
		return 'databases';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_slug(): string {
		return 'databases';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function kinds(): array {
		return array( 'db' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Databases', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function empty_text(): string {
		return __( 'You have no databases yet.', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function all_label(): string {
		return __( 'All databases', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function not_found_text(): string {
		return __( 'That database was not found. Here are your databases.', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function page_tabs(): array {
		return array(
			'access'   => __( 'Access', 'beltoft-services' ),
			'backups'  => __( 'Backups', 'beltoft-services' ),
			'password' => __( 'New password', 'beltoft-services' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param Resource             $resource Resource.
	 */
	public function row( array $view, Resource $resource ): array {
		list( $state, $tone ) = self::state( $resource );

		$card = DbCard::view( $resource );

		return array(
			'id'      => $resource->id(),
			'url'     => $this->url( $view['license'] ),
			'name'    => (string) ( $view['product'] ?? '' ),
			'details' => $card['engine'],
			'state'   => $state,
			'tone'    => $tone,
			'extra'   => (string) $card['host'],
			'action'  => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $resource Resource.
	 */
	public function label_of( Resource $resource ): string {
		$card = DbCard::view( $resource );

		return $card['engine'];
	}
}
