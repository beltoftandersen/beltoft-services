<?php
/**
 * The hosting area of My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Hosting accounts (driver kind `hosting`): a row per account and a page with what HostingDriver prints in the service
 * panel. The tab is there for every customer; with no account it shows the empty state and an Order hosting button that
 * goes to the page set in Settings > Services > Hosting (a hosting plan is ordered from the plan cards of that page).
 */
final class Hosting extends ServiceArea {

	/**
	 * The address of the "Order hosting" button: the "Hosting order page" setting (a path on this site or a full
	 * address), else the first published product that uses the hosting driver, or what the filter says. '' when
	 * there is none (then there is no button).
	 */
	public function url_add(): string {
		$url = self::setting_url( 'hosting_order_url' );

		if ( '' === $url ) {
			$url = self::product_url( array( 'hosting' ) );
		}

		/**
		 * The address of the "Order hosting" button. Return '' for no button.
		 *
		 * @param string $url The order page setting, else the first hosting product, or ''.
		 */
		return (string) apply_filters( 'beltoft_services_hosting_add_url', $url );
	}

	/**
	 * {@inheritDoc}
	 */
	public function buttons(): array {
		$url = $this->url_add();

		return '' === $url ? array() : array(
			array(
				'label' => __( 'Order hosting', 'beltoft-services' ),
				'url'   => $url,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function key(): string {
		return 'hosting';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_slug(): string {
		return 'hosting';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function kinds(): array {
		return array( 'hosting' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return \BeltoftServices\Hosting\Config::label();
	}

	/**
	 * The tabs after the overview, each its own address (the account's page, then /websites, /files, /backups, /email).
	 * Which of them a customer is offered is HostingView::tabs(); an address of one that is not offered shows the overview.
	 *
	 * {@inheritDoc}
	 */
	protected function page_tabs(): array {
		return array(
			'websites' => __( 'Websites', 'beltoft-services' ),
			'files'    => __( 'Files', 'beltoft-services' ),
			'backups'  => __( 'Backups', 'beltoft-services' ),
			'email'    => __( 'Email', 'beltoft-services' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function empty_text(): string {
		return __( 'You have no hosting yet.', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function all_label(): string {
		return __( 'All hosting', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function not_found_text(): string {
		return __( 'That hosting account was not found. Here is your hosting.', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param Resource             $resource Resource.
	 */
	public function row( array $view, Resource $resource ): array {
		list( $state, $tone ) = self::state( $resource );

		return array(
			'id'      => $resource->id(),
			'url'     => $this->url( $view['license'] ),
			'name'    => (string) ( $view['product'] ?? '' ),
			'details' => '',
			'state'   => $state,
			'tone'    => $tone,
			'extra'   => '',
			'action'  => array(),
		);
	}
}
