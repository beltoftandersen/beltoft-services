<?php
/**
 * The Email area of My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Mail\MailGuard;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Business email (driver kind `mail`): a row per email domain (the domain, its mailboxes used of the plan, state, DNS) and
 * a page with Mailboxes (the overview), Aliases, DNS and Connect tabs, drawn by MailCard.
 */
final class Email extends ServiceArea {

	/**
	 * The Email menu item goes right after Domains, with the other services, before Billing and Support. Without a
	 * Domains item it stays where it is.
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public static function menu( $items ) {
		$slug = ServiceArea::get( self::class )->slug();

		if ( ! is_array( $items ) || ! isset( $items[ $slug ] ) || ! isset( $items[ Domains::slug() ] ) ) {
			return $items;
		}

		$label = $items[ $slug ];
		unset( $items[ $slug ] );
		$out = array();

		foreach ( $items as $key => $value ) {
			$out[ $key ] = $value;

			if ( Domains::slug() === $key ) {
				$out[ $slug ] = $label;
			}
		}

		return $out;
	}

	/**
	 * The address a customer buys an email domain at: the first published product that uses the mail driver, or what the
	 * filter beltoft_services_email_add_url says. '' when there is none (then there is no button).
	 */
	public function url_add(): string {
		$url = '';
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'meta_key'       => \BeltoftServices\Product::META_DRIVER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one product, one query.
				'meta_value'     => 'mail', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( $ids ) {
			$url = (string) get_permalink( (int) $ids[0] );
		}

		/**
		 * The address of the "Add an email domain" button. Return '' for no button.
		 *
		 * @param string $url Address of the first email product, or ''.
		 */
		return (string) apply_filters( 'beltoft_services_email_add_url', $url );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function buttons(): array {
		$url = $this->url_add();

		return '' === $url ? array() : array(
			array(
				'label' => __( 'Add an email domain', 'beltoft-services' ),
				'url'   => $url,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function key(): string {
		return 'email';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_slug(): string {
		return 'email';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function kinds(): array {
		return array( 'mail' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Email', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function empty_text(): string {
		return __( 'You have no email domains yet.', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function all_label(): string {
		return __( 'All email domains', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function not_found_text(): string {
		return __( 'That email domain was not found. Here are your email domains.', 'beltoft-services' );
	}

	/**
	 * The tabs after the first one, which is the mailboxes.
	 *
	 * {@inheritDoc}
	 */
	protected function page_tabs(): array {
		return array(
			'aliases'  => __( 'Aliases', 'beltoft-services' ),
			'dns'      => __( 'DNS', 'beltoft-services' ),
			'settings' => __( 'Connect', 'beltoft-services' ),
		);
	}

	/**
	 * The first tab is the mailboxes, at the address of the page itself.
	 *
	 * {@inheritDoc}
	 */
	public function nav(): array {
		$out = array( 'overview' => __( 'Mailboxes', 'beltoft-services' ) );

		foreach ( $this->tabs() as $slug ) {
			$out[ $slug ] = $this->page_tabs()[ $slug ] ?? $slug;
		}

		return $out;
	}

	/**
	 * The page's title is the domain.
	 *
	 * @param Resource             $resource Resource.
	 * @param array<string, mixed> $view     Display values of the license.
	 */
	public function title_of( Resource $resource, array $view ): string {
		$domain = MailGuard::domain( $resource );

		return '' !== $domain ? $domain : (string) ( $view['product'] ?? '' );
	}

	/**
	 * The small text next to the title: what the service is.
	 *
	 * {@inheritDoc}
	 *
	 * @param Resource $resource Resource.
	 */
	public function label_of( Resource $resource ): string {
		unset( $resource );

		return __( 'Business email', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param Resource             $resource Resource.
	 */
	public function row( array $view, Resource $resource ): array {
		list( $state, $tone ) = self::state( $resource );

		$limits = MailGuard::limits( $resource );
		$dns    = (string) $resource->get( 'mail_dns' );

		return array(
			'id'      => $resource->id(),
			'url'     => $this->url( $view['license'] ),
			'name'    => '' !== MailGuard::domain( $resource ) ? MailGuard::domain( $resource ) : (string) ( $view['product'] ?? '' ),
			'details' => sprintf(
				/* translators: 1: mailboxes in use, 2: mailboxes the plan allows */
				__( '%1$d of %2$d mailboxes', 'beltoft-services' ),
				(int) $resource->get( 'mail_boxes' ),
				$limits['mailboxes']
			),
			'state'   => $state,
			'tone'    => $tone,
			'extra'   => 'ready' === (string) $resource->get( 'state' ) && 'ok' !== $dns && '' !== $dns ? __( 'DNS records missing', 'beltoft-services' ) : '',
			'action'  => array(),
		);
	}
}
