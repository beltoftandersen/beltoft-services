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
	 * The hosting panel's stylesheet. Themes can turn it off with the beltoft_services_account_css filter, like the panel's.
	 */
	public static function style(): void {
		if ( apply_filters( 'beltoft_services_account_css', true ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'assets/hosting.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/hosting.css' ) );
		}
	}

	/**
	 * The hosting script, after the generic panel script (which holds the REST root and nonce). It is enqueued when
	 * a hosting panel is printed, not on every account page: a queued script that depends on the panel script would
	 * count as the panel script being loaded.
	 */
	public static function enqueue(): void {
		$handle = \BeltoftServices\Account\Panel::HANDLE;

		self::style();
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/hosting.js', BELTOFT_SERVICES_FILE ), array( $handle, \BeltoftServices\Status::HANDLE ), \BeltoftServices\Assets::version( 'assets/hosting.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftHosting',
			array(
				'strings' => array(
					'working'   => __( 'Working...', 'beltoft-services' ),
					'done'      => __( 'Done.', 'beltoft-services' ),
					'failed'    => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'confirm'   => __( 'Type the website name to remove it. Its files and database are deleted.', 'beltoft-services' ),
					'buy'       => __( 'Buy and add', 'beltoft-services' ),
					'opening'   => __( 'Opening WordPress...', 'beltoft-services' ),
					'confirmRestore' => __( 'Restore from this backup? A safety copy of your hosting is made first, so you can undo it for 24 hours.', 'beltoft-services' ),
					'confirmPack' => __( 'Remove one extra storage pack from this mailbox? It ends at your next renewal, the storage stays until then, and nothing is refunded.', 'beltoft-services' ),
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
	 * How a website is named to the customer: its title, with the website name after it when the customer chose that
	 * name; a name made from the domain is internal and not shown.
	 *
	 * @param array<string, mixed> $site Site row.
	 */
	public static function site_name( array $site ): string {
		$label = (string) $site['label'];
		$name  = (string) $site['slug'];

		if ( '' === $label || $label === $name ) {
			return $name;
		}

		return SiteField::slug_is_made( $name, $label ) ? $label : $label . ' (' . $name . ')';
	}

	/**
	 * The customer's name for a website of an account, from its slug.
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $slug Website name (slug).
	 */
	public static function site_name_of( Resource $r, string $slug ): string {
		$site = Sites::find( $r->id(), $slug );

		return null === $site ? $slug : self::site_name( $site );
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

		if ( '' !== SiteNewDomain::hint( $r ) ) {
			echo '<p class="beltoft-hosting-notice">' . esc_html( SiteNewDomain::hint( $r ) ) . '</p>';
		}

		printf(
			'<p class="beltoft-hosting-summary">%s</p>',
			esc_html(
				0 === $limits['sites']
					? sprintf(
						/* translators: 1: websites in the account, 2: PHP workers allowed. */
						_n( '%1$d website. Unlimited websites and subdomains. Up to %2$d PHP workers for all websites together.', '%1$d websites. Unlimited websites and subdomains. Up to %2$d PHP workers for all websites together.', $limits['sites_used'], 'beltoft-services' ),
						$limits['sites_used'],
						$limits['php_workers']
					)
					: sprintf(
						/* translators: 1: websites used, 2: websites allowed, 3: PHP workers allowed. */
						__( '%1$d of %2$d websites. Up to %3$d PHP workers for all websites together.', 'beltoft-services' ),
						$limits['sites_used'],
						$limits['sites'],
						$limits['php_workers']
					)
			)
		);

		foreach ( Addons::unconnected_domains( $r ) as $paid ) {
			printf(
				'<p class="beltoft-hosting-notice">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: domain name. */
						__( 'Your extra domain %s is paid. It is being connected.', 'beltoft-services' ),
						\BeltoftServices\Domains\HostName::display( $paid )
					)
				)
			);
		}

		echo '<table class="beltoft-hosting-sites"><thead><tr><th>' . esc_html__( 'Website', 'beltoft-services' ) . '</th><th>' . esc_html__( 'PHP', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Addresses', 'beltoft-services' ) . '</th>' . ( $ready ? '<th><span class="screen-reader-text">' . esc_html__( 'Actions', 'beltoft-services' ) . '</span></th>' : '' ) . '</tr></thead><tbody>';

		$ending = Addons::ending_domains( $r );

		foreach ( $sites as $site ) {
			$names = array();

			foreach ( $site['domains'] as $d ) {
				$names[] = \BeltoftServices\Domains\HostName::display( $d['host'] )
					. ( 'active' === $d['state'] ? '' : ' (' . ( 'waiting' === $d['state'] ? __( 'waiting for DNS', 'beltoft-services' ) : __( 'pending', 'beltoft-services' ) ) . ')' )
					. ( 'preview' !== $d['kind'] && in_array( Addons::registrable( $d['host'] ), $ending, true ) ? ' (' . __( 'ends at the next renewal', 'beltoft-services' ) . ')' : '' );
			}

			printf(
				'<tr><td data-label="%4$s">%1$s</td><td data-label="%5$s">%2$s</td><td data-label="%6$s">%3$s</td>',
				esc_html( self::site_name( $site ) ),
				esc_html( $site['php'] ),
				esc_html( implode( ', ', $names ) ),
				esc_attr__( 'Website', 'beltoft-services' ),
				esc_attr__( 'PHP', 'beltoft-services' ),
				esc_attr__( 'Addresses', 'beltoft-services' )
			);

			if ( $ready ) {
				$marker = ( (array) $r->get( 'php_rollback' ) )[ $site['slug'] ] ?? null;
				$back   = is_array( $marker ) && (int) ( $marker['until'] ?? 0 ) > \BeltoftServices\Jobs::now() ? ' <button type="button" class="beltoft-hosting-btn" data-hosting-action="php_rollback" data-slug="' . esc_attr( $site['slug'] ) . '">' . esc_html( sprintf( /* translators: %s: PHP version. */ __( 'Go back to PHP %s', 'beltoft-services' ), (string) $marker['from'] ) ) . '</button>' : '';

				printf(
					'<td><div class="beltoft-hosting-actions"><button type="button" class="beltoft-hosting-btn" data-hosting-action="login" data-slug="%1$s">%2$s</button> <button type="button" class="beltoft-hosting-btn" data-hosting-action="cache" data-slug="%1$s">%3$s</button> <button type="button" class="beltoft-hosting-btn" data-hosting-action="remove_site" data-slug="%1$s">%4$s</button>',
					esc_attr( $site['slug'] ),
					esc_html__( 'Open WordPress admin', 'beltoft-services' ),
					esc_html__( 'Clear cache', 'beltoft-services' ),
					esc_html__( 'Remove', 'beltoft-services' )
				);
				echo wp_kses( $back, array( 'button' => array( 'type' => true, 'class' => true, 'data-hosting-action' => true, 'data-slug' => true ) ) );
				echo '</div></td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';

		// A name the customer points at us themselves: the records to add, including the proof that the name is theirs.
		$node = (array) ( Nodes::get( (string) ( Records::for_resource( $r->id() )['node'] ?? '' ) ) ?? array() );

		foreach ( SiteDomains::for_resource( $r->id() ) as $d ) {
			if ( 'external' !== $d['kind'] || 'active' === $d['state'] ) {
				continue;
			}

			$rows = array();

			foreach ( SiteDns::instructions( $node, $d['host'], $r->id() ) as $row ) {
				$rows[] = $row['type'] . ' ' . ( $row['name'] ?? $d['host'] ) . ' = ' . $row['value'];
			}

			echo '<p class="beltoft-hosting-notice">' . esc_html( sprintf( /* translators: 1: domain name, 2: DNS records. */ __( 'To use %1$s, add these DNS records at your DNS provider: %2$s. The website goes live when both are found.', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( $d['host'] ), implode( '; ', $rows ) ) ) . '</p>';
		}

		foreach ( (array) $r->get( 'php_pinned' ) as $slug => $version ) {
			if ( null !== Sites::find( $r->id(), (string) $slug ) && Config::php_end( (string) $version ) !== null && (int) Config::php_end( (string) $version ) < \BeltoftServices\Jobs::now() ) {
				echo '<p class="beltoft-hosting-warning">' . esc_html( sprintf( /* translators: 1: website name, 2: PHP version. */ __( 'The website %1$s runs on PHP %2$s, which no longer gets security updates. Move it to a supported version as soon as you can.', 'beltoft-services' ), self::site_name_of( $r, (string) $slug ), (string) $version ) ) . '</p>';
			}
		}

		foreach ( HostingUsage::loader_problems( $r ) as $slug => $state ) {
			echo '<p class="beltoft-hosting-notice">' . esc_html(
				sprintf(
					/* translators: 1: website name, 2: state. */
					__( 'The website %1$s needs the hosting loader file for the sign-in link and website mail (%2$s). Contact support if it keeps showing.', 'beltoft-services' ),
					self::site_name_of( $r, (string) $slug ),
					$state
				)
			) . '</p>';
		}

		if ( $ready ) {
			self::print_forms( $limits, $sites );
		}

		self::print_backups( $r, $sites, $ready );

		$mail = SiteMail::child_of( $r );

		echo '<div class="beltoft-hosting-mail"><p>';

		if ( $mail ) {
			$mail_domain = \BeltoftServices\Domains\HostName::display( (string) $mail->get( \BeltoftServices\Mail\MailDomain::META_DOMAIN ) );

			echo esc_html( sprintf( '%s: %s', self::included_label( $mail ), $mail_domain ) );
			echo ' ' . esc_html(
				sprintf(
					/* translators: %s: the mail domain. */
					__( 'Email lite stays on %s. It does not move when the main domain changes.', 'beltoft-services' ),
					$mail_domain
				)
			);

			$area = \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Email::class );
			$url  = $area->url( $mail->license() );

			if ( '' !== $url ) {
				echo '</p><p><a class="beltoft-hosting-link" href="' . esc_url( $url ) . '">' . esc_html__( 'Manage mailboxes', 'beltoft-services' ) . '</a>';
			}
		} elseif ( null === LiteMail::ready_domain( $r ) ) {
			echo esc_html__( 'Connect your own domain to a website to set up Email lite. It is included with your plan and uses that domain.', 'beltoft-services' );
		} else {
			echo esc_html(
				sprintf(
					/* translators: %s: the account's own domain. */
					__( 'Set up Email lite on %s so your websites can send mail. Email is included with your plan; without it a website cannot send mail.', 'beltoft-services' ),
					\BeltoftServices\Domains\HostName::display( (string) LiteMail::ready_domain( $r ) )
				)
			);

			if ( $ready ) {
				echo '</p><p><button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="enable_mail">' . esc_html__( 'Set up email', 'beltoft-services' ) . '</button>';
			}
		}

		echo '</p></div>';

		if ( $mail && $ready ) {
			self::print_packs( $r, $mail );
		}

		echo '<p class="beltoft-hosting-message" role="status" aria-live="polite"></p></div>';
	}

	/**
	 * Extra storage per Email lite mailbox: packs of 5 GB, up to four for each, added and ended here.
	 *
	 * @param Resource $r    Hosting resource.
	 * @param Resource $mail Email lite resource.
	 */
	private static function print_packs( Resource $r, Resource $mail ): void {
		$quote = Addons::quote( $r, Addons::KIND_PACK );
		$boxes = \BeltoftServices\Mail\Mailboxes::all( $mail );

		if ( null === $quote || ! is_array( $boxes ) ) {
			return;
		}

		if ( array() === $boxes ) {
			echo '<div class="beltoft-hosting-packs"><h4>' . esc_html__( 'Extra mailbox storage', 'beltoft-services' ) . '</h4><p>' . esc_html__( 'Create a mailbox first. Extra storage is added to a mailbox.', 'beltoft-services' ) . '</p></div>';

			return;
		}

		$confirm = Addons::confirm_text( Addons::KIND_PACK, $quote );
		$packs   = Addons::packs( $r );

		echo '<div class="beltoft-hosting-packs"><h4>' . esc_html__( 'Extra mailbox storage', 'beltoft-services' ) . '</h4><p>' . esc_html(
			sprintf(
				/* translators: 1: price per month and pack, 2: most packs on one mailbox. */
				__( 'Add 5 GB to a mailbox for %1$s per month, up to %2$d packs for each mailbox.', 'beltoft-services' ),
				Addons::money( (float) $quote['unit'] ),
				Addons::MAX_PACKS
			)
		) . '</p><ul class="beltoft-hosting-pack-list">';

		foreach ( $boxes as $box ) {
			$email = (string) $box['email'];
			$n     = (int) ( $packs[ $email ] ?? 0 );
			$ending = count( array_filter( Addons::lines( $r, Addons::KIND_PACK, $email ), static fn( $i ) => 'yes' === (string) $i->get_meta( Addons::I_ENDING ) ) );

			echo '<li><span class="beltoft-hosting-pack-name">' . esc_html(
				sprintf(
					/* translators: 1: mailbox address, 2: size in GB. */
					__( '%1$s: %2$s GB', 'beltoft-services' ),
					$email,
					(string) round( (int) $box['quota_mb'] / 1024, 1 )
				)
			) . '</span>';

			if ( $ending > 0 ) {
				echo ' ' . esc_html(
					sprintf(
						/* translators: %d: packs that end at the next renewal. */
						_n( '(%d pack ends at the next renewal)', '(%d packs end at the next renewal)', $ending, 'beltoft-services' ),
						$ending
					)
				);
			}

			if ( $n < Addons::MAX_PACKS ) {
				echo ' <button type="button" class="beltoft-hosting-btn" data-hosting-action="add_pack" data-mailbox="' . esc_attr( $email ) . '" data-confirm="' . esc_attr( $confirm ) . '" data-cents="' . (int) round( $quote['now'] * 100 ) . '">' . esc_html__( 'Add 5 GB', 'beltoft-services' ) . '</button>';
			}

			if ( $n - $ending > 0 ) {
				echo ' <button type="button" class="beltoft-hosting-btn" data-hosting-action="remove_pack" data-mailbox="' . esc_attr( $email ) . '">' . esc_html__( 'Remove a pack', 'beltoft-services' ) . '</button>';
			}

			echo '</li>';
		}

		echo '</ul></div>';
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
						'<td><div class="beltoft-hosting-actions"><button type="button" class="beltoft-hosting-btn" data-hosting-action="restore" data-scope="account" data-volid="%1$s">%2$s</button> <button type="button" class="beltoft-hosting-btn" data-hosting-action="restore" data-scope="files" data-volid="%1$s">%3$s</button> <button type="button" class="beltoft-hosting-btn" data-hosting-action="restore" data-scope="database" data-volid="%1$s">%4$s</button></div></td>',
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
			echo '<div class="beltoft-hosting-row">';

			if ( array() !== $sites ) {
				echo '<label class="beltoft-hosting-field"><span>' . esc_html__( 'Website to restore', 'beltoft-services' ) . '</span><select data-hosting-field="restore_slug">';

				foreach ( $sites as $site ) {
					echo '<option value="' . esc_attr( $site['slug'] ) . '">' . esc_html( self::site_name( $site ) ) . '</option>';
				}

				echo '</select></label>';
			}

			echo '<button type="button" class="beltoft-hosting-btn" data-hosting-action="backup_now">' . esc_html__( 'Back up now', 'beltoft-services' ) . '</button></div>';

			if ( null !== $safety ) {
				echo '<p>' . esc_html( sprintf( /* translators: %s: time the safety copy is kept until. */ __( 'A safety copy from before your last restore is kept until %s.', 'beltoft-services' ), wp_date( 'j F Y, H:i', (int) $safety['expires'] ) ) ) . ' <button type="button" class="beltoft-hosting-btn" data-hosting-action="undo">' . esc_html__( 'Undo the last restore', 'beltoft-services' ) . '</button></p>';
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

		if ( 0 === $limits['sites'] || $limits['sites_used'] < $limits['sites'] ) {
			echo '<div class="beltoft-hosting-row"><label class="beltoft-hosting-field"><span>' . esc_html__( 'Website name', 'beltoft-services' ) . '</span><input type="text" data-hosting-field="slug" maxlength="32"></label>';
			echo '<label class="beltoft-hosting-field"><span>' . esc_html__( 'PHP version', 'beltoft-services' ) . '</span><select data-hosting-field="php">';

			foreach ( $limits['php_versions'] as $version ) {
				echo '<option value="' . esc_attr( $version ) . '">' . esc_html( 'PHP ' . $version ) . '</option>';
			}

			echo '</select></label><button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="add_site">' . esc_html__( 'Add website', 'beltoft-services' ) . '</button></div>';
		}

		if ( array() !== $sites ) {
			echo '<div class="beltoft-hosting-row"><label class="beltoft-hosting-field"><span>' . esc_html__( 'Website', 'beltoft-services' ) . '</span><select data-hosting-field="domain_slug">';

			foreach ( $sites as $site ) {
				echo '<option value="' . esc_attr( $site['slug'] ) . '">' . esc_html( self::site_name( $site ) ) . '</option>';
			}

			echo '</select></label><label class="beltoft-hosting-field"><span>' . esc_html__( 'Domain name', 'beltoft-services' ) . '</span><input type="text" data-hosting-field="host" placeholder="www.example.com"></label><button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="add_domain">' . esc_html__( 'Add domain', 'beltoft-services' ) . '</button></div>';
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

		echo '<div class="beltoft-hosting-checklist"><h4>' . esc_html__( 'Getting started', 'beltoft-services' ) . '</h4><ul>';

		foreach ( self::checklist( $r ) as $key => $done ) {
			echo '<li data-done="' . ( $done ? '1' : '0' ) . '"><span class="beltoft-hosting-mark" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $done ? __( 'Done:', 'beltoft-services' ) : __( 'To do:', 'beltoft-services' ) ) . '</span><span class="beltoft-hosting-check-label">' . esc_html( $labels[ $key ] ) . '</span>';

			if ( $ready && ! $done && in_array( $key, self::MANUAL_ITEMS, true ) ) {
				echo ' <button type="button" class="beltoft-hosting-btn" data-hosting-action="check_item" data-item="' . esc_attr( $key ) . '">' . esc_html__( 'Mark as done', 'beltoft-services' ) . '</button>';
			}

			echo '</li>';
		}

		echo '</ul>';

		if ( $ready ) {
			echo '<p><button type="button" class="beltoft-hosting-btn" data-hosting-action="dismiss_checklist">' . esc_html__( 'Hide this list', 'beltoft-services' ) . '</button></p>';
		}

		echo '</div>';
	}
}
