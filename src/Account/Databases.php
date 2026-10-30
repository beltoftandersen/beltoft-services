<?php
/**
 * The Databases area of My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Proxmox\DbCard;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Managed databases (driver kind `db`): a row per database (name, engine and major version, state, private host
 * address) and a page with an Overview tab (the connection details of DbCard), a Backups tab and a New password tab.
 */
final class Databases extends ServiceArea {

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
			'details' => $card['version'] > 0 ? 'PostgreSQL ' . $card['version'] : 'PostgreSQL',
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

		return $card['version'] > 0 ? 'PostgreSQL ' . $card['version'] : 'PostgreSQL';
	}
}
