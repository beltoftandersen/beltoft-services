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

	public const HANDLE = 'beltoft-services-hosting';

	/** The checklist items the customer ticks by hand. */
	public const MANUAL_ITEMS = array( 'build', 'backups' );

	/**
	 * The hosting script, after the generic panel script (which holds the REST root and nonce). It is enqueued when
	 * a hosting panel is printed, not on every account page: a queued script that depends on the panel script would
	 * count as the panel script being loaded.
	 */
	public static function enqueue(): void {
		$handle = \BeltoftServices\Account\Panel::HANDLE;

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/hosting.js', BELTOFT_SERVICES_FILE ), array( $handle ), \BeltoftServices\Assets::version( 'assets/hosting.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftHosting',
			array(
				'strings' => array(
					'working'   => __( 'Working...', 'beltoft-services' ),
					'done'      => __( 'Done.', 'beltoft-services' ),
					'failed'    => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'confirm'   => __( 'Type the website name to remove it. Its files and database are deleted.', 'beltoft-services' ),
					'opening'   => __( 'Opening WordPress...', 'beltoft-services' ),
					'confirmRestore' => __( 'Restore from this backup? A safety copy of your hosting is made first, so you can undo it for 24 hours.', 'beltoft-services' ),
					'confirmUndo' => __( 'Go back to the safety copy? Changes made since the restore are lost.', 'beltoft-services' ),
				),
			)
		);
	}

	/**
	 * The first-visit checklist: what is done and what is not. Domain and email are read from the account, the
	 * other two are ticked by the customer.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<string, bool>
	 */
	public static function checklist( Resource $r ): array {
		$manual = array_map( 'strval', (array) $r->get( 'checklist' ) );
		$domain = false;

		foreach ( SiteDomains::for_resource( $r->id() ) as $d ) {
			$domain = $domain || 'preview' !== $d['kind'];
		}

		return array(
			'domain'  => $domain,
			'email'   => null !== SiteMail::child_of( $r ),
			'build'   => in_array( 'build', $manual, true ),
			'backups' => in_array( 'backups', $manual, true ),
		);
	}

	/**
	 * Is the checklist shown? Not once it is dismissed or everything is done.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function checklist_visible( Resource $r ): bool {
		return ! $r->get( 'checklist_dismissed' ) && in_array( false, self::checklist( $r ), true );
	}


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

		self::enqueue();

		$actions = new HostingActions( $r );
		$limits  = $actions->limits();
		$sites   = $actions->sites();
		$ready   = 'ready' === $r->get( 'state' ) && 'ready' === $r->get( 'desired' );

		echo '<div class="beltoft-hosting" data-hosting-resource="' . esc_attr( (string) $r->id() ) . '">';

		if ( self::checklist_visible( $r ) ) {
			self::print_checklist( $r, $ready );
		}

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

		echo '<table class="beltoft-hosting-sites"><thead><tr><th>' . esc_html__( 'Website', 'beltoft-services' ) . '</th><th>' . esc_html__( 'PHP', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Addresses', 'beltoft-services' ) . '</th>' . ( $ready ? '<th></th>' : '' ) . '</tr></thead><tbody>';

		foreach ( $sites as $site ) {
			$names = array();

			foreach ( $site['domains'] as $d ) {
				$names[] = $d['host'] . ( 'active' === $d['state'] ? '' : ' (' . ( 'waiting' === $d['state'] ? __( 'waiting for DNS', 'beltoft-services' ) : __( 'pending', 'beltoft-services' ) ) . ')' );
			}

			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td>',
				esc_html( '' !== $site['label'] ? $site['label'] . ' (' . $site['slug'] . ')' : $site['slug'] ),
				esc_html( $site['php'] ),
				esc_html( implode( ', ', $names ) )
			);

			if ( $ready ) {
				printf(
					'<td><button type="button" data-hosting-action="login" data-slug="%1$s">%2$s</button> <button type="button" data-hosting-action="cache" data-slug="%1$s">%3$s</button> <button type="button" data-hosting-action="remove_site" data-slug="%1$s">%4$s</button></td>',
					esc_attr( $site['slug'] ),
					esc_html__( 'Open WordPress admin', 'beltoft-services' ),
					esc_html__( 'Clear cache', 'beltoft-services' ),
					esc_html__( 'Remove', 'beltoft-services' )
				);
			}

			echo '</tr>';
		}

		echo '</tbody></table>';

		foreach ( HostingUsage::loader_problems( $r ) as $slug => $state ) {
			echo '<p class="beltoft-hosting-notice">' . esc_html(
				sprintf(
					/* translators: 1: website name, 2: state. */
					__( 'The website %1$s needs the hosting loader file for the sign-in link and website mail (%2$s). Contact support if it keeps showing.', 'beltoft-services' ),
					$slug,
					$state
				)
			) . '</p>';
		}

		if ( $ready ) {
			self::print_forms( $limits, $sites );
		}

		self::print_backups( $r, $sites, $ready );

		$mail = SiteMail::child_of( $r );

		echo '<p>';

		if ( $mail ) {
			echo esc_html( sprintf( '%s: %s', self::included_label( $mail ), (string) $mail->get( \BeltoftServices\Mail\MailDomain::META_DOMAIN ) ) );
		} else {
			echo esc_html__( 'Email is included with your plan. Set it up for your domain when you are ready.', 'beltoft-services' );

			if ( $ready ) {
				echo ' <input type="text" data-hosting-field="mail_domain" placeholder="example.com" aria-label="' . esc_attr__( 'Domain for email', 'beltoft-services' ) . '"> <button type="button" data-hosting-action="enable_mail">' . esc_html__( 'Set up email', 'beltoft-services' ) . '</button>';
			}
		}

		echo '</p><p class="beltoft-hosting-message" role="status" aria-live="polite"></p></div>';
	}

	/**
	 * The backups: the stored list, Back up now, the restore buttons and Undo. Nothing here calls the node.
	 *
	 * @param Resource                         $r     Hosting resource.
	 * @param array<int, array<string, mixed>> $sites Sites.
	 * @param bool                             $ready Whether the account takes changes.
	 */
	private static function print_backups( Resource $r, array $sites, bool $ready ): void {
		if ( ! HostingBackups::enabled( $r ) ) {
			return;
		}

		$list   = HostingBackups::stored( $r );
		$safety = HostingRestore::safety( $r );
		$busy   = ( new HostingBackups( $r ) )->active() || HostingRestore::active( $r );

		echo '<div class="beltoft-hosting-backups"><h4>' . esc_html__( 'Backups', 'beltoft-services' ) . '</h4>';

		if ( $busy ) {
			echo '<p>' . esc_html__( 'A backup or a restore is running.', 'beltoft-services' ) . '</p>';
		}

		if ( array() === $list ) {
			echo '<p>' . esc_html__( 'No backups yet. The first one is made tonight.', 'beltoft-services' ) . '</p>';
		} else {
			echo '<table class="beltoft-hosting-backup-list"><thead><tr><th>' . esc_html__( 'Date', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Kind', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Size', 'beltoft-services' ) . '</th>' . ( $ready && ! $busy ? '<th></th>' : '' ) . '</tr></thead><tbody>';

			foreach ( $list as $b ) {
				printf(
					'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td>',
					esc_html( wp_date( 'j F Y, H:i', (int) $b['ctime'] ) ),
					esc_html( 'manual' === $b['kind'] ? __( 'Manual', 'beltoft-services' ) : __( 'Nightly', 'beltoft-services' ) ),
					esc_html( size_format( (int) $b['size'] ) )
				);

				if ( $ready && ! $busy ) {
					printf(
						'<td><button type="button" data-hosting-action="restore" data-scope="account" data-volid="%1$s">%2$s</button> <button type="button" data-hosting-action="restore" data-scope="files" data-volid="%1$s">%3$s</button> <button type="button" data-hosting-action="restore" data-scope="database" data-volid="%1$s">%4$s</button></td>',
						esc_attr( (string) $b['volid'] ),
						esc_html__( 'Restore everything', 'beltoft-services' ),
						esc_html__( 'Restore files', 'beltoft-services' ),
						esc_html__( 'Restore database', 'beltoft-services' )
					);
				}

				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		if ( $ready && ! $busy ) {
			echo '<p>';

			if ( array() !== $sites ) {
				echo '<select data-hosting-field="restore_slug" aria-label="' . esc_attr__( 'Website to restore', 'beltoft-services' ) . '">';

				foreach ( $sites as $site ) {
					echo '<option value="' . esc_attr( $site['slug'] ) . '">' . esc_html( $site['slug'] ) . '</option>';
				}

				echo '</select> ';
			}

			echo '<button type="button" data-hosting-action="backup_now">' . esc_html__( 'Back up now', 'beltoft-services' ) . '</button></p>';

			if ( null !== $safety ) {
				echo '<p>' . esc_html( sprintf( /* translators: %s: time the safety copy is kept until. */ __( 'A safety copy from before your last restore is kept until %s.', 'beltoft-services' ), wp_date( 'j F Y, H:i', (int) $safety['expires'] ) ) ) . ' <button type="button" data-hosting-action="undo">' . esc_html__( 'Undo the last restore', 'beltoft-services' ) . '</button></p>';
			}
		}

		echo '</div>';
	}

	/**
	 * The add forms.
	 *
	 * @param array<string, mixed>             $limits Ceilings.
	 * @param array<int, array<string, mixed>> $sites  Sites.
	 */
	private static function print_forms( array $limits, array $sites ): void {
		echo '<div class="beltoft-hosting-add">';

		if ( $limits['sites_used'] < $limits['sites'] ) {
			echo '<p><input type="text" data-hosting-field="slug" maxlength="32" placeholder="' . esc_attr__( 'Website name', 'beltoft-services' ) . '" aria-label="' . esc_attr__( 'Website name', 'beltoft-services' ) . '"> ';
			echo '<select data-hosting-field="php" aria-label="' . esc_attr__( 'PHP version', 'beltoft-services' ) . '">';

			foreach ( $limits['php_versions'] as $version ) {
				echo '<option value="' . esc_attr( $version ) . '">' . esc_html( 'PHP ' . $version ) . '</option>';
			}

			echo '</select> <button type="button" data-hosting-action="add_site">' . esc_html__( 'Add website', 'beltoft-services' ) . '</button></p>';
		}

		if ( array() !== $sites ) {
			echo '<p><select data-hosting-field="domain_slug" aria-label="' . esc_attr__( 'Website', 'beltoft-services' ) . '">';

			foreach ( $sites as $site ) {
				echo '<option value="' . esc_attr( $site['slug'] ) . '">' . esc_html( $site['slug'] ) . '</option>';
			}

			echo '</select> <input type="text" data-hosting-field="host" placeholder="www.example.com" aria-label="' . esc_attr__( 'Domain name', 'beltoft-services' ) . '"> <button type="button" data-hosting-action="add_domain">' . esc_html__( 'Add domain', 'beltoft-services' ) . '</button></p>';
		}

		echo '</div>';
	}

	/**
	 * The first-visit checklist card.
	 *
	 * @param Resource $r     Hosting resource.
	 * @param bool     $ready Whether the account takes changes.
	 */
	private static function print_checklist( Resource $r, bool $ready ): void {
		$labels = array(
			'domain'  => __( 'Connect your domain', 'beltoft-services' ),
			'email'   => __( 'Set up email', 'beltoft-services' ),
			'build'   => __( 'Build or upload your site', 'beltoft-services' ),
			'backups' => __( 'Check your backups', 'beltoft-services' ),
		);

		echo '<div class="beltoft-hosting-checklist"><p><strong>' . esc_html__( 'Getting started', 'beltoft-services' ) . '</strong></p><ul>';

		foreach ( self::checklist( $r ) as $key => $done ) {
			echo '<li data-done="' . ( $done ? '1' : '0' ) . '">' . esc_html( ( $done ? '[x] ' : '[ ] ' ) . $labels[ $key ] );

			if ( $ready && ! $done && in_array( $key, self::MANUAL_ITEMS, true ) ) {
				echo ' <button type="button" data-hosting-action="check_item" data-item="' . esc_attr( $key ) . '">' . esc_html__( 'Mark as done', 'beltoft-services' ) . '</button>';
			}

			echo '</li>';
		}

		echo '</ul>';

		if ( $ready ) {
			echo '<p><button type="button" data-hosting-action="dismiss_checklist">' . esc_html__( 'Hide this list', 'beltoft-services' ) . '</button></p>';
		}

		echo '</div>';
	}
}
