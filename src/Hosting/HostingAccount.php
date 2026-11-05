<?php
/**
 * The hosting part of My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Children;
use BeltoftServices\Mail\MailDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the hosting panel (what HostingDriver::render_account() shows) and the "Included with" line of an Email
 * lite domain. The panel is server-rendered facts: sites, their addresses and states, the plan's ceilings and the
 * Email lite line. The changes go through the REST routes (HostingRoutes); the account script that calls them is
 * a later slice.
 */
final class HostingAccount {

	/**
	 * "Included with <hosting product name>" for a mail resource that is a child of a hosting account, else ''.
	 *
	 * @param Resource $r Resource.
	 */
	public static function included_label( Resource $r ): string {
		if ( MailDriver::ID !== $r->get( 'driver' ) ) {
			return '';
		}

		$parent = Children::parent_of( $r );

		if ( ! $parent || HostingDriver::ID !== $parent->get( 'driver' ) ) {
			return '';
		}

		/* translators: %s: the name of the hosting plan. */
		return sprintf( __( 'Included with %s', 'beltoft-services' ), self::product_name( $parent ) );
	}

	/**
	 * The product name of a resource's license.
	 *
	 * @param Resource $r Resource.
	 */
	public static function product_name( Resource $r ): string {
		$license = $r->license();
		$id      = (int) ( $license['product_id'] ?? 0 );
		$name    = $id > 0 ? wp_strip_all_tags( get_the_title( $id ) ) : '';

		return '' !== $name ? $name : __( 'your hosting', 'beltoft-services' );
	}

	/**
	 * The panel.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function render( Resource $r ): void {
		$record = Records::for_resource( $r->id() );

		if ( null === $record ) {
			return;
		}

		$actions = new HostingActions( $r );
		$limits  = $actions->limits();

		echo '<div class="beltoft-hosting">';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: websites used, 2: websites allowed, 3: PHP workers allowed. */
					__( '%1$d of %2$d websites. Up to %3$d PHP workers for all websites together.', 'beltoft-services' ),
					$limits['sites_used'],
					$limits['sites'],
					$limits['php_workers']
				)
			)
		);

		echo '<table class="beltoft-hosting-sites"><thead><tr><th>' . esc_html__( 'Website', 'beltoft-services' ) . '</th><th>' . esc_html__( 'PHP', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Addresses', 'beltoft-services' ) . '</th></tr></thead><tbody>';

		foreach ( $actions->sites() as $site ) {
			$names = array();

			foreach ( $site['domains'] as $d ) {
				$names[] = $d['host'] . ( 'active' === $d['state'] ? '' : ' (' . ( 'waiting' === $d['state'] ? __( 'waiting for DNS', 'beltoft-services' ) : __( 'pending', 'beltoft-services' ) ) . ')' );
			}

			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( '' !== $site['label'] ? $site['label'] . ' (' . $site['slug'] . ')' : $site['slug'] ),
				esc_html( $site['php'] ),
				esc_html( implode( ', ', $names ) )
			);
		}

		echo '</tbody></table>';

		$mail = null;

		foreach ( Children::of( $r ) as $child ) {
			if ( MailDriver::ID === $child->get( 'driver' ) && 'deleted' !== $child->get( 'state' ) ) {
				$mail = $child;
			}
		}

		echo '<p>';

		if ( $mail ) {
			echo esc_html( sprintf( '%s: %s', self::included_label( $mail ), (string) $mail->get( \BeltoftServices\Mail\MailDomain::META_DOMAIN ) ) );
		} else {
			echo esc_html__( 'Email is included with your plan. Set it up for your domain when you are ready.', 'beltoft-services' );
		}

		echo '</p></div>';
	}
}
