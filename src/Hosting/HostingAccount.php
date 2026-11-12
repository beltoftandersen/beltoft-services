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
	 * Gives the mail area the "Included with" label of an Email lite resource that is a child of a hosting account.
	 */
	public static function register(): void {
		add_filter( \BeltoftServices\Domains\Ownership::FILTER, array( SiteDns::class, 'answer' ), 10, 3 );
		add_filter( 'beltoft_services_mail_included_label', array( self::class, 'included_label' ), 10, 2 );
		add_filter( 'beltoft_services_stepup_purposes', array( self::class, 'stepup_purposes' ) );
		add_filter( 'beltoft_services_stepup_text', array( self::class, 'stepup_text' ), 10, 2 );
	}

	/**
	 * Registers the purpose of the emailed code before a password or an SSH key is set for file access.
	 *
	 * @param mixed $purposes Purposes so far.
	 * @return array<int, string>
	 */
	public static function stepup_purposes( $purposes ): array {
		return array_merge( (array) $purposes, array( HostingRoutes::STEP_PURPOSE ) );
	}

	/**
	 * What the code email says this purpose is for.
	 *
	 * @param mixed  $text    Text so far.
	 * @param string $purpose The purpose.
	 */
	public static function stepup_text( $text, $purpose ): string {
		return HostingRoutes::STEP_PURPOSE === $purpose ? __( 'setting a password or an SSH key for your hosting files', 'beltoft-services' ) : (string) $text;
	}

	public const HANDLE = 'beltoft-services-hosting';

	/** The checklist items the customer ticks by hand. */
	public const MANUAL_ITEMS = array( 'build', 'backups', 'password' );

	/** Items that are never required: an open one does not keep the list on the page. */
	public const OPTIONAL_ITEMS = array( 'password' );

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
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/hosting.js', BELTOFT_SERVICES_FILE ), array( $handle, \BeltoftServices\Status::HANDLE, \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/hosting.js' ), true );
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
					'openingFiles' => __( 'Opening the file manager...', 'beltoft-services' ),
					'openingDatabase' => __( 'Opening the database tool...', 'beltoft-services' ),
					/* translators: 1: WordPress user name, 2: email address. */
					'signedInAs' => __( 'Signed in as %1$s (%2$s).', 'beltoft-services' ),
					/* translators: %s: WordPress user name. */
					'passwordFor' => __( 'Choose a password for %s.', 'beltoft-services' ),
					'confirmRemoveDomain' => __( 'Remove this address from the website?', 'beltoft-services' ),
					'confirmRemoveKey' => __( 'Remove this SSH key?', 'beltoft-services' ),
					'codeSent'  => __( 'A code was sent to your email.', 'beltoft-services' ),
					'copied'    => __( 'Copied.', 'beltoft-services' ),
					'confirmRestore' => __( 'Restore from this backup? A safety copy of your hosting is made first, so you can undo it for 24 hours.', 'beltoft-services' ),
					'confirmPack' => __( 'Remove one extra storage pack from this mailbox? It ends at your next renewal, the storage stays until then, and nothing is refunded.', 'beltoft-services' ),
					'confirmUndo' => __( 'Go back to the safety copy? Changes made since the restore are lost.', 'beltoft-services' ),
					'cachePageOn' => __( 'Page cache: on.', 'beltoft-services' ),
					'cachePageOff' => __( 'Page cache: off in WordPress.', 'beltoft-services' ),
					'cachePageInactive' => __( 'Page cache: off, its WordPress plugin is deactivated.', 'beltoft-services' ),
					'cachePageMissing' => __( 'Page cache: off, its WordPress plugin is not installed.', 'beltoft-services' ),
					'cacheObjectOn' => __( 'Object cache: on.', 'beltoft-services' ),
					'cacheObjectOff' => __( 'Object cache: off.', 'beltoft-services' ),
					'saveAnyway' => __( 'Save anyway', 'beltoft-services' ),
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
			'domain'   => $domain,
			'email'    => null !== SiteMail::child_of( $r ),
			'build'    => in_array( 'build', $manual, true ),
			'backups'  => in_array( 'backups', $manual, true ),
			'password' => in_array( 'password', $manual, true ),
		);
	}

	/**
	 * Is the checklist shown? Not once it is dismissed or everything is done.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function checklist_visible( Resource $r ): bool {
		return ! $r->get( 'checklist_dismissed' ) && in_array( false, array_diff_key( self::checklist( $r ), array_flip( self::OPTIONAL_ITEMS ) ), true );
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
	 * @param string   $label What an earlier callback made of the label.
	 * @param Resource $r     Resource.
	 */
	public static function included_label( $label, Resource $r ): string {
		$label = (string) $label;

		if ( MailDriver::ID !== $r->get( 'driver' ) ) {
			return $label;
		}

		$parent = Children::parent_of( $r );

		if ( ! $parent || HostingDriver::ID !== $parent->get( 'driver' ) ) {
			return $label;
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
	 * The panel: the first-visit list, then the websites (one panel each), Add a website, Files (SFTP), Backups and Email,
	 * each a panel with its own heading. Facts are printed by the server; the changes go through the REST routes.
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

		self::print_notices( $r );

		if ( self::checklist_visible( $r ) ) {
			self::print_checklist( $r, $ready, $sites );
		}

		self::print_websites( $r, $limits, $sites, $ready );

		if ( $ready && ( 0 === $limits['sites'] || $limits['sites_used'] < $limits['sites'] ) ) {
			self::print_add_site( $limits );
		}

		self::print_move();
		self::print_files( $r, $actions->files(), $ready );
		self::print_backups( $r, $sites, $ready );
		self::print_email( $r, $ready );

		echo '</div>';
	}

	/**
	 * Opens a panel: a card with its heading (and anything that sits at the right of the heading).
	 *
	 * @param string $title Heading.
	 * @param string $id    Element id, or ''.
	 * @param string $head  Markup at the right of the heading (already escaped), or ''.
	 * @param string $class Extra class.
	 * @param string $attrs Extra attributes (already escaped).
	 */
	private static function panel_open( string $title, string $id = '', string $head = '', string $class = '', string $attrs = '' ): void {
		echo '<section class="beltoft-hosting-panel' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . $attrs . '><header class="beltoft-hosting-panel__head"><h3 class="beltoft-hosting-panel__title">' . $title . '</h3>' . $head . '</header>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callers pass escaped markup.
	}

	/**
	 * Closes a panel.
	 */
	private static function panel_close(): void {
		echo '</section>';
	}

	/**
	 * The notices that belong to no website: a hint, a paid domain that is being connected, an unsupported PHP version
	 * and a refused loader file.
	 *
	 * @param Resource $r Hosting resource.
	 */
	private static function print_notices( Resource $r ): void {
		ob_start();

		if ( '' !== SiteNewDomain::hint( $r ) ) {
			echo '<p class="beltoft-hosting-notice">' . esc_html( SiteNewDomain::hint( $r ) ) . '</p>';
		}

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

		$notices = (string) ob_get_clean();

		if ( '' !== $notices ) {
			echo '<div class="beltoft-hosting-notices">' . $notices . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above with esc_html.
		}
	}

	/**
	 * The websites: a heading with the plan's limits, then one panel for each website.
	 *
	 * @param Resource                         $r      Hosting resource.
	 * @param array<string, mixed>             $limits Ceilings.
	 * @param array<int, array<string, mixed>> $sites  Sites.
	 * @param bool                             $ready  Whether the account takes changes.
	 */
	private static function print_websites( Resource $r, array $limits, array $sites, bool $ready ): void {
		echo '<div class="beltoft-hosting-section"><h3 class="beltoft-hosting-section__title">' . esc_html__( 'Websites', 'beltoft-services' ) . '</h3><p class="beltoft-hosting-section__note">';
		echo esc_html(
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
		);
		echo '</p></div>';

		$ending = Addons::ending_domains( $r );
		$node   = (array) ( Nodes::get( (string) ( Records::for_resource( $r->id() )['node'] ?? '' ) ) ?? array() );

		foreach ( $sites as $site ) {
			self::print_site( $r, $site, count( $sites ) > 1, $ready, $ending, $node, $limits );
		}
	}

	/**
	 * One website as a panel: its name, main address and state in the heading row, then what can be done with it.
	 *
	 * @param Resource             $r      Hosting resource.
	 * @param array<string, mixed> $site   Site from HostingActions::sites().
	 * @param bool                 $many   Whether the account has more than one website (the last one cannot be removed).
	 * @param bool                 $ready  Whether the account takes changes.
	 * @param string[]             $ending Registrable domains that end at the next renewal.
	 * @param array<string, mixed> $node   Node settings.
	 * @param array<string, mixed> $limits Ceilings.
	 */
	private static function print_site( Resource $r, array $site, bool $many, bool $ready, array $ending, array $node, array $limits ): void {
		$slug    = (string) $site['slug'];
		$main    = (string) $site['main'];
		$preview = (string) $site['preview'];
		$wp      = (array) $site['wp'];
		$own     = array() !== array_filter( $site['domains'], static fn( $d ) => 'preview' !== $d['kind'] );
		$head    = '<a class="beltoft-hosting-link beltoft-hosting-panel__main" href="' . esc_url( 'https://' . $main . '/' ) . '" target="_blank" rel="noopener">' . esc_html( \BeltoftServices\Domains\HostName::display( $main ) ) . '</a>'
			. '<span class="beltoft-hosting-status' . ( $ready ? ' is-ok' : '' ) . '">' . esc_html( $ready ? __( 'Online', 'beltoft-services' ) : __( 'Not ready', 'beltoft-services' ) ) . '</span>';

		self::panel_open( esc_html( self::site_name( $site ) ), 'beltoft-site-' . $slug, $head, 'beltoft-hosting-site', ' data-hosting-scope data-slug="' . esc_attr( $slug ) . '"' );
		echo '<dl class="beltoft-hosting-facts">';

		// Address.
		echo '<div><dt>' . esc_html__( 'Address', 'beltoft-services' ) . '</dt><dd>';
		echo '<ul class="beltoft-hosting-names">';

		foreach ( $site['domains'] as $d ) {
			$is_main = $d['host'] === $main && 'preview' !== $d['kind'];
			$notes   = array();

			if ( 'preview' === $d['kind'] ) {
				$notes[] = __( 'Temporary address', 'beltoft-services' );
			} elseif ( $is_main ) {
				$notes[] = __( 'Main address', 'beltoft-services' );
			}

			if ( $own && $d['host'] === $wp['address'] ) {
				$notes[] = __( 'WordPress address', 'beltoft-services' );
			}

			if ( 'active' !== $d['state'] ) {
				$notes[] = 'waiting' === $d['state'] ? __( 'waiting for DNS', 'beltoft-services' ) : __( 'pending', 'beltoft-services' );
			}

			if ( 'preview' !== $d['kind'] && in_array( Addons::registrable( $d['host'] ), $ending, true ) ) {
				$notes[] = __( 'ends at the next renewal', 'beltoft-services' );
			}

			echo '<li><span class="beltoft-hosting-name">' . esc_html( \BeltoftServices\Domains\HostName::display( $d['host'] ) ) . '</span>';

			if ( array() !== $notes ) {
				echo ' <span class="beltoft-hosting-note">' . esc_html( implode( ', ', $notes ) ) . '</span>';
			}

			if ( $ready && 'preview' !== $d['kind'] ) {
				echo ' <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="remove_domain" data-host="' . esc_attr( $d['host'] ) . '">' . esc_html__( 'Remove', 'beltoft-services' ) . '</button>';
			}

			echo '</li>';
		}

		echo '</ul>';

		self::print_wordpress( $slug, $site, $ready );

		foreach ( $site['pairs'] as $pair ) {
			self::print_pair( $slug, $pair, $ready, (string) $wp['address'] );
		}

		DnsGuide::render( $r, $site, $node, $ready );

		if ( $ready ) {
			echo '<div class="beltoft-hosting-formrow" data-hosting-scope id="beltoft-site-' . esc_attr( $slug ) . '-connect"><label class="beltoft-hosting-field"><span>' . esc_html__( 'Connect a domain', 'beltoft-services' ) . '</span><input type="text" data-hosting-field="host" placeholder="example.com" autocomplete="off" autocapitalize="off" spellcheck="false"></label><button type="button" class="beltoft-hosting-btn" data-hosting-action="add_domain" data-slug="' . esc_attr( $slug ) . '">' . esc_html__( 'Connect', 'beltoft-services' ) . '</button></div>';
			echo '<p class="beltoft-hosting-help">' . esc_html__( 'A domain is connected with and without www. A subdomain is connected only as typed.', 'beltoft-services' ) . '</p>';
		}

		echo '</dd></div>';

		// PHP.
		echo '<div><dt>' . esc_html__( 'PHP', 'beltoft-services' ) . '</dt><dd>';
		echo '<span class="beltoft-hosting-php">' . esc_html( 'PHP ' . $site['php'] ) . '</span>';

		if ( $ready ) {
			echo ' <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="php_edit" aria-expanded="false">' . esc_html__( 'Change', 'beltoft-services' ) . '</button>';
			echo '<span class="beltoft-hosting-inline" data-php-form hidden><label class="screen-reader-text" for="beltoft-php-' . esc_attr( $slug ) . '">' . esc_html__( 'PHP version', 'beltoft-services' ) . '</label><select id="beltoft-php-' . esc_attr( $slug ) . '" data-hosting-field="php_version">';

			foreach ( $limits['php_versions'] as $version ) {
				echo '<option value="' . esc_attr( $version ) . '"' . selected( $version, (string) $site['php'], false ) . '>' . esc_html( 'PHP ' . $version ) . '</option>';
			}

			echo '</select><button type="button" class="beltoft-hosting-btn" data-hosting-action="php_save" data-slug="' . esc_attr( $slug ) . '">' . esc_html__( 'Save', 'beltoft-services' ) . '</button><button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="php_cancel">' . esc_html__( 'Cancel', 'beltoft-services' ) . '</button></span>';

			$marker = ( (array) $r->get( 'php_rollback' ) )[ $slug ] ?? null;

			if ( is_array( $marker ) && (int) ( $marker['until'] ?? 0 ) > \BeltoftServices\Jobs::now() ) {
				echo ' <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="php_rollback" data-slug="' . esc_attr( $slug ) . '">' . esc_html( sprintf( /* translators: %s: PHP version. */ __( 'Go back to PHP %s', 'beltoft-services' ), (string) $marker['from'] ) ) . '</button>';
			}
		}

		echo '</dd></div>';

		// WordPress login.
		echo '<div><dt>' . esc_html__( 'WordPress login', 'beltoft-services' ) . '</dt><dd>';
		echo '<p class="beltoft-hosting-line" data-login-facts>' . esc_html( self::login_facts( (string) $site['admin_user'], (string) $site['admin_email'] ) ) . '</p>';

		if ( $ready ) {
			echo '<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="login" data-slug="' . esc_attr( $slug ) . '">' . esc_html__( 'Log in to WordPress', 'beltoft-services' ) . '</button><button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="password" data-slug="' . esc_attr( $slug ) . '">' . esc_html__( 'Set a WordPress password', 'beltoft-services' ) . '</button></div>';
		}

		echo '<p class="beltoft-hosting-help">' . esc_html( sprintf( /* translators: %s: the website's WordPress sign-in address. */ __( 'You have no WordPress password until you set one. With a password you can log in at %s without this page.', 'beltoft-services' ), 'https://' . $wp['address'] . '/wp-login.php' ) ) . '</p>';
		echo '</dd></div>';

		// Cache.
		echo '<div><dt>' . esc_html__( 'Cache', 'beltoft-services' ) . '</dt><dd>';

		if ( $ready ) {
			echo '<p class="beltoft-hosting-line" data-cache-facts data-slug="' . esc_attr( $slug ) . '" hidden></p>';
			echo '<button type="button" class="beltoft-hosting-btn" data-hosting-action="cache" data-slug="' . esc_attr( $slug ) . '">' . esc_html__( 'Clear cache', 'beltoft-services' ) . '</button>';
		}

		echo '<p class="beltoft-hosting-help">' . esc_html__( 'Clears the page cache and the object cache of this website.', 'beltoft-services' ) . '</p>';
		echo '<p class="beltoft-hosting-help">' . esc_html__( 'The page cache is switched on and off in the WordPress dashboard, under Settings. Switch it off there, or deactivate or delete its plugin, to use another caching plugin.', 'beltoft-services' ) . '</p></dd></div>';

		// Files.
		echo '<div><dt>' . esc_html__( 'Files', 'beltoft-services' ) . '</dt><dd>';

		if ( $ready ) {
			echo '<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn" data-hosting-action="tool_files" data-slug="' . esc_attr( $slug ) . '">' . esc_html__( 'Open file manager', 'beltoft-services' ) . '</button></div>';
			echo '<p class="beltoft-hosting-help">' . esc_html__( 'Opens in a new tab and shows the files of this website. The tab ends after 30 minutes without use.', 'beltoft-services' ) . '</p>';
		}

		echo '<p class="beltoft-hosting-help">' . esc_html__( 'Upload and download files with SFTP.', 'beltoft-services' ) . ' <a class="beltoft-hosting-link" href="#beltoft-hosting-files">' . esc_html__( 'Files (SFTP)', 'beltoft-services' ) . '</a></p></dd></div>';

		// Database.
		echo '<div><dt>' . esc_html__( 'Database', 'beltoft-services' ) . '</dt><dd>';

		if ( $ready ) {
			echo '<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn" data-hosting-action="tool_database" data-slug="' . esc_attr( $slug ) . '">' . esc_html__( 'Open database', 'beltoft-services' ) . '</button></div>';
		}

		echo '<p class="beltoft-hosting-help">' . esc_html__( 'Opens phpMyAdmin for the database of this website in a new tab. You do not need a password. The tab ends after 30 minutes without use.', 'beltoft-services' ) . '</p></dd></div>';

		self::print_scan( (array) $site['scan'] );
		self::print_staging( $slug, (array) $site['staging'], $ready, $limits );
		self::print_protection( $slug, (array) $site['country'], $ready );

		echo '</dl>';

		if ( $ready && $many ) {
			echo '<footer class="beltoft-hosting-panel__foot"><button type="button" class="beltoft-hosting-linkbtn beltoft-hosting-linkbtn--danger" data-hosting-action="remove_site" data-slug="' . esc_attr( $slug ) . '">' . esc_html__( 'Remove website', 'beltoft-services' ) . '</button></footer>';
		}

		self::panel_close();
	}

	/**
	 * The result of the last nightly malware scan of a website. Nothing is drawn for a website that was never scanned.
	 *
	 * @param array<string, mixed> $v MalwareScan::view().
	 */
	private static function print_scan( array $v ): void {
		$words = MalwareScan::words( $v );

		if ( '' === $words ) {
			return;
		}

		echo '<div><dt>' . esc_html__( 'Malware scan', 'beltoft-services' ) . '</dt><dd>';
		echo '<p class="' . ( 'found' === $v['state'] ? 'beltoft-hosting-warning' : 'beltoft-hosting-line' ) . '" data-scan-state role="status">' . esc_html( $words ) . '</p>';

		if ( 'found' === $v['state'] ) {
			echo '<p class="beltoft-hosting-help">' . esc_html__( 'Your website is still online and nothing was changed. Restore a backup from before this date, or remove the files with the file manager. Contact support if you need help.', 'beltoft-services' ) . '</p>';
		}

		echo '<p class="beltoft-hosting-help">' . esc_html__( 'Your website is scanned every night for known malware. The scan only reads your files. It does not change or remove anything.', 'beltoft-services' ) . '</p></dd></div>';
	}

	/**
	 * The staging copy of a website: what it is, its state while a job runs (the page asks the node's state every few seconds
	 * and reloads when the job is done), and the buttons that go with the state.
	 *
	 * @param string               $slug   Site.
	 * @param array<string, mixed> $v      SiteStaging::view().
	 * @param bool                 $ready  Whether the account takes changes.
	 * @param array<string, mixed> $limits Ceilings.
	 */
	private static function print_staging( string $slug, array $v, bool $ready, array $limits ): void {
		$when = static fn( int $time ): string => wp_date( 'j F Y, H:i', $time );
		$id   = 'beltoft-staging-' . $slug;
		$busy = array(
			'creating'   => __( 'The staging copy is being made. This can take a few minutes.', 'beltoft-services' ),
			'refreshing' => __( 'The staging copy is being refreshed from the live website. This can take a few minutes.', 'beltoft-services' ),
			'pushing'    => __( 'The staging copy is being pushed to the live website. The live website may show a maintenance message for a short time.', 'beltoft-services' ),
			'undoing'    => __( 'The last push is being undone. The live website may show a maintenance message for a short time.', 'beltoft-services' ),
			'deleting'   => __( 'The staging copy is being deleted.', 'beltoft-services' ),
		);
		$fail = array(
			'create'  => __( 'The staging copy could not be made. Your website was not changed.', 'beltoft-services' ),
			'refresh' => __( 'The staging copy could not be refreshed. Your website was not changed. Refresh it again, or delete it.', 'beltoft-services' ),
			'push'    => __( 'The push did not work. Your live website was put back as it was.', 'beltoft-services' ),
			'undo'    => __( 'The undo did not work. Try again, or contact support.', 'beltoft-services' ),
			'delete'  => __( 'The staging copy could not be deleted. Try again.', 'beltoft-services' ),
		);

		echo '<div><dt>' . esc_html__( 'Staging', 'beltoft-services' ) . '</dt><dd>';

		if ( $v['busy'] ) {
			echo '<p class="beltoft-hosting-line" data-staging-busy data-slug="' . esc_attr( $slug ) . '" role="status">' . esc_html( $busy[ $v['state'] ] ?? '' ) . '</p>';
		} else {
			if ( '' !== $v['note'] ) {
				if ( 'unsupported' === $v['note'] ) {
					$words = __( 'Your hosting cannot do this yet. Contact support.', 'beltoft-services' );
				} elseif ( 'unsafe' === $v['note'] ) {
					$words = __( 'The push did not work and the live website could not be put back automatically. Support has been told.', 'beltoft-services' );
				} else {
					$words = (string) ( $fail[ $v['failed'] ] ?? __( 'That did not work. Try again, or contact support.', 'beltoft-services' ) );
				}

				echo '<p class="beltoft-hosting-warning" data-staging-note>' . esc_html( $words ) . '</p>';
			}

			if ( $v['exists'] ) {
				echo '<p class="beltoft-hosting-line" data-staging-state>';
				/* translators: %s: the address of the staging copy. */
				echo wp_kses( sprintf( esc_html__( 'The staging copy is ready at %s.', 'beltoft-services' ), '<a class="beltoft-hosting-link" href="' . esc_url( 'https://' . $v['host'] . '/' ) . '" target="_blank" rel="noopener">' . esc_html( \BeltoftServices\Domains\HostName::display( (string) $v['host'] ) ) . '</a>' ), array( 'a' => array( 'class' => true, 'href' => true, 'target' => true, 'rel' => true ) ) );

				if ( $v['at'] > 0 ) {
					/* translators: %s: date and time. */
					echo ' ' . esc_html( sprintf( __( 'Made on %s.', 'beltoft-services' ), $when( (int) $v['at'] ) ) );
				}

				if ( $v['refreshed'] > 0 ) {
					/* translators: %s: date and time. */
					echo ' ' . esc_html( sprintf( __( 'Refreshed from the live website on %s.', 'beltoft-services' ), $when( (int) $v['refreshed'] ) ) );
				}

				echo '</p>';

				if ( $ready ) {
					$files = __( 'Push the staging copy to the live website? Its files replace the files of the live website. The database and the media library of the live website stay as they are. A safety point of the live website is made first, and you can undo the push for 7 days.', 'beltoft-services' );
					$both  = __( 'Push the staging copy to the live website? Its files and its database replace those of the live website. Orders, customers, comments and other changes made on the live website since the copy was made are lost. A safety point of the live website is made first, and you can undo the push for 7 days.', 'beltoft-services' );

					echo '<div class="beltoft-hosting-buttons">';
					echo '<button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="staging_open" data-slug="' . esc_attr( $slug ) . '">' . esc_html__( 'Open staging', 'beltoft-services' ) . '</button>';
					echo '<button type="button" class="beltoft-hosting-btn" data-hosting-action="staging_edit" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '">' . esc_html__( 'Push to live', 'beltoft-services' ) . '</button>';
					echo '<button type="button" class="beltoft-hosting-btn" data-hosting-action="staging_refresh" data-slug="' . esc_attr( $slug ) . '" data-confirm="' . esc_attr__( 'Refresh the staging copy from the live website? Changes made only in the staging copy are lost.', 'beltoft-services' ) . '">' . esc_html__( 'Refresh from live', 'beltoft-services' ) . '</button>';
					echo '<button type="button" class="beltoft-hosting-linkbtn beltoft-hosting-linkbtn--danger" data-hosting-action="staging_delete" data-slug="' . esc_attr( $slug ) . '" data-confirm="' . esc_attr__( 'Delete the staging copy? Its files and its database are deleted. The live website is not changed.', 'beltoft-services' ) . '">' . esc_html__( 'Delete staging copy', 'beltoft-services' ) . '</button>';
					echo '</div>';
					echo '<p class="beltoft-hosting-help">' . esc_html__( 'Open staging signs you in to the WordPress of the staging copy. The address above shows the copy as visitors would see it.', 'beltoft-services' ) . '</p>';
					echo '<div class="beltoft-hosting-secure" id="' . esc_attr( $id ) . '" data-staging-form hidden>';
					echo '<fieldset class="beltoft-hosting-fieldset"><legend>' . esc_html__( 'What to push', 'beltoft-services' ) . '</legend>';
					echo '<label class="beltoft-hosting-radio"><input type="radio" name="' . esc_attr( $id ) . '-what" value="full" data-confirm="' . esc_attr( $both ) . '" checked> <span>' . esc_html__( 'Files and database', 'beltoft-services' ) . '</span></label> ';
					echo '<label class="beltoft-hosting-radio"><input type="radio" name="' . esc_attr( $id ) . '-what" value="files" data-confirm="' . esc_attr( $files ) . '"> <span>' . esc_html__( 'Files only', 'beltoft-services' ) . '</span></label>';
					echo '<p class="beltoft-hosting-warning">' . esc_html__( 'Files and database: orders, customers, comments and other changes made on the live website since the staging copy was made are replaced.', 'beltoft-services' ) . '</p>';
					echo '<p class="beltoft-hosting-help">' . esc_html__( 'Files only: themes, plugins and other files are pushed. The database and the media library of the live website stay as they are.', 'beltoft-services' ) . '</p></fieldset>';
					echo '<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn" data-hosting-action="staging_push" data-slug="' . esc_attr( $slug ) . '">' . esc_html__( 'Push to live', 'beltoft-services' ) . '</button><button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="staging_cancel">' . esc_html__( 'Cancel', 'beltoft-services' ) . '</button></div></div>';
				}
			} elseif ( $ready ) {
				if ( 'one' === (string) ( $limits['staging'] ?? 'one' ) && (int) ( $limits['staging_used'] ?? 0 ) > 0 ) {
					echo '<p class="beltoft-hosting-line">' . esc_html__( 'Your plan includes one staging copy, and another website has it.', 'beltoft-services' ) . '</p>';
				} else {
					echo '<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn" data-hosting-action="staging_create" data-slug="' . esc_attr( $slug ) . '">' . esc_html__( 'Create staging copy', 'beltoft-services' ) . '</button></div>';
				}
			}

			if ( $ready && null !== $v['undo'] ) {
				$full = 'full' === $v['undo']['scope'];
				$ask  = $full
					? __( 'Undo the last push? The live website goes back to how it was before the push, its files and its database. Orders, comments and other changes made on the live website since the push are lost.', 'beltoft-services' )
					: __( 'Undo the last push? The files of the live website go back to how they were before the push. The database is not changed.', 'beltoft-services' );

				echo '<p class="beltoft-hosting-line"><button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="staging_undo" data-slug="' . esc_attr( $slug ) . '" data-confirm="' . esc_attr( $ask ) . '">' . esc_html__( 'Undo last push', 'beltoft-services' ) . '</button> ';
				/* translators: %s: date and time. */
				echo esc_html( sprintf( __( 'You can undo the last push until %s.', 'beltoft-services' ), $when( (int) $v['undo']['at'] + SiteStaging::KEEP ) ) ) . '</p>';
			}
		}

		echo '<p class="beltoft-hosting-help">' . esc_html__( 'A staging copy is a copy of this website on an address of its own, to try changes in before they go live. It sends no email, is not shown to search engines and does not count as a website.', 'beltoft-services' ) . '</p></dd></div>';
	}

	/**
	 * The country block of a website: what it does now, and the form to change it (hidden until Change is pressed).
	 *
	 * @param string               $slug  Site.
	 * @param array<string, mixed> $block CountryBlock::view().
	 * @param bool                 $ready Whether the account takes changes.
	 */
	private static function print_protection( string $slug, array $block, bool $ready ): void {
		$names   = \BeltoftServices\Hosting\CountryBlock::names();
		$lists   = \BeltoftServices\Hosting\CountryBlock::lists();
		$enabled = ! empty( $block['enabled'] );
		$chosen  = array_map( static fn( $c ) => (string) $c['code'], (array) $block['countries'] );
		$scope   = (string) $block['scope'];
		$id      = 'beltoft-country-' . $slug;

		echo '<div><dt>' . esc_html__( 'Protection', 'beltoft-services' ) . '</dt><dd>';

		if ( ! $enabled ) {
			echo '<p class="beltoft-hosting-line" data-country-state>' . esc_html__( 'Country block: off. Visitors from every country can reach this website.', 'beltoft-services' ) . '</p>';
		} else {
			$list = implode( ', ', array_map( static fn( $c ) => (string) $c['name'], (array) $block['countries'] ) );
			/* translators: 1: where the block applies, 2: the blocked countries. */
			echo '<p class="beltoft-hosting-line" data-country-state>' . esc_html( sprintf( __( 'Country block: on for %1$s. Blocked: %2$s.', 'beltoft-services' ), 'login' === $scope ? __( 'the login and the admin area', 'beltoft-services' ) : __( 'the whole website', 'beltoft-services' ), $list ) ) . '</p>';

			if ( false === $block['active'] ) {
				echo '<p class="beltoft-hosting-warning">' . esc_html__( 'The list is saved, but the country database is not in place on your server yet, so nothing is blocked until it is.', 'beltoft-services' ) . '</p>';
			}
		}

		if ( $ready ) {
			echo '<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn" data-hosting-action="country_edit" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '">' . esc_html( $enabled ? __( 'Change', 'beltoft-services' ) : __( 'Block countries', 'beltoft-services' ) ) . '</button></div>';
			echo '<div class="beltoft-hosting-secure" id="' . esc_attr( $id ) . '" data-country-form hidden>';
			echo '<fieldset class="beltoft-hosting-fieldset"><legend>' . esc_html__( 'Countries to block', 'beltoft-services' ) . '</legend>';

			if ( array() !== $lists ) {
				echo '<div class="beltoft-hosting-buttons">';

				foreach ( $lists as $list_id => $list ) {
					/* translators: %s: the name of a ready list of countries. */
					echo '<button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="country_list" data-codes="' . esc_attr( implode( ' ', $list['countries'] ) ) . '">' . esc_html( sprintf( __( 'Select: %s', 'beltoft-services' ), $list['label'] ) ) . '</button>';
				}

				echo '</div>';

				foreach ( $lists as $list ) {
					/* translators: 1: the name of a ready list, 2: its countries. */
					echo '<p class="beltoft-hosting-help">' . esc_html( sprintf( __( '%1$s: %2$s.', 'beltoft-services' ), $list['label'], implode( ', ', array_map( static fn( $c ) => $names[ $c ] ?? $c, $list['countries'] ) ) ) ) . '</p>';
				}
			}

			echo '<label class="beltoft-hosting-field"><span>' . esc_html__( 'Find a country', 'beltoft-services' ) . '</span><input type="search" data-country-filter data-hosting-nosubmit autocomplete="off" spellcheck="false"></label>';
			echo '<div class="beltoft-hosting-countries" role="group" aria-label="' . esc_attr__( 'Countries', 'beltoft-services' ) . '">';

			foreach ( $names as $code => $name ) {
				echo '<label class="beltoft-hosting-check" data-country-name="' . esc_attr( function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name ) ) . '"><input type="checkbox" data-country value="' . esc_attr( $code ) . '"' . checked( in_array( $code, $chosen, true ), true, false ) . '> <span>' . esc_html( $name ) . '</span></label>';
			}

			echo '</div></fieldset>';
			echo '<fieldset class="beltoft-hosting-fieldset"><legend>' . esc_html__( 'Where it applies', 'beltoft-services' ) . '</legend>';
			echo '<label class="beltoft-hosting-radio"><input type="radio" name="' . esc_attr( $id ) . '-scope" value="site"' . checked( 'login' !== $scope, true, false ) . '> <span>' . esc_html__( 'Whole website', 'beltoft-services' ) . '</span></label> ';
			echo '<label class="beltoft-hosting-radio"><input type="radio" name="' . esc_attr( $id ) . '-scope" value="login"' . checked( 'login', $scope, false ) . '> <span>' . esc_html__( 'Login and admin only', 'beltoft-services' ) . '</span></label>';
			echo '<p class="beltoft-hosting-help">' . esc_html__( 'Login and admin only covers wp-login.php, xmlrpc.php and the WordPress admin area (not admin-ajax.php and admin-post.php), and the file manager and database tool. The rest of the website stays open.', 'beltoft-services' ) . '</p></fieldset>';
			echo '<p class="beltoft-hosting-help">' . esc_html__( 'If your own country is blocked, you cannot reach the website or the buttons on this page that open it.', 'beltoft-services' ) . '</p>';
			echo '<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn" data-hosting-action="country_save" data-slug="' . esc_attr( $slug ) . '">' . esc_html__( 'Save', 'beltoft-services' ) . '</button><button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="country_cancel">' . esc_html__( 'Cancel', 'beltoft-services' ) . '</button>';

			if ( $enabled ) {
				echo '<button type="button" class="beltoft-hosting-linkbtn beltoft-hosting-linkbtn--danger" data-hosting-action="country_off" data-slug="' . esc_attr( $slug ) . '">' . esc_html__( 'Turn off', 'beltoft-services' ) . '</button>';
			}

			echo '</div></div>';
		}

		echo '<p class="beltoft-hosting-help">' . esc_html__( 'The country is looked up from the visitor\'s address. This is not exact, and a VPN gets around it.', 'beltoft-services' ) . '</p>';
		echo '<p class="beltoft-hosting-help">' . esc_html( \BeltoftServices\Hosting\CountryBlock::ATTRIBUTION ) . '</p></dd></div>';
	}

	/**
	 * What the page says about the address WordPress itself uses, and the one button that goes with it. Nothing is printed
	 * for a website without a domain of its own (WordPress is on the temporary address, as it has to be).
	 *
	 * @param string               $slug  Site.
	 * @param array<string, mixed> $site  Site from HostingActions::sites().
	 * @param bool                 $ready Whether the account takes changes.
	 */
	private static function print_wordpress( string $slug, array $site, bool $ready ): void {
		$wp      = (array) $site['wp'];
		$main    = (string) $site['main'];
		$preview = (string) $site['preview'];
		$show    = static fn( string $host ): string => \BeltoftServices\Domains\HostName::display( $host );
		$own     = array_values( array_filter( $site['domains'], static fn( $d ) => 'preview' !== $d['kind'] ) );
		$button  = null;
		$line    = '';

		if ( array() === $own && '' === $wp['moving'] && '' === $wp['note'] ) {
			return;
		}

		if ( '' !== $wp['moving'] ) {
			/* translators: %s: address. */
			$line = sprintf( __( 'WordPress is being moved to %s. This can take a few minutes.', 'beltoft-services' ), $show( (string) $wp['moving'] ) );
		} elseif ( 'unsupported' === $wp['note'] ) {
			$line = __( 'Your hosting cannot move WordPress to another address yet. Contact support.', 'beltoft-services' );
		} elseif ( 'foreign' === $wp['note'] ) {
			$line = '' === $wp['seen']
				? __( 'WordPress is set to an address you chose yourself. It was left as it is.', 'beltoft-services' )
				/* translators: %s: address. */
				: sprintf( __( 'WordPress is set to %s, an address you chose yourself. It was left as it is.', 'beltoft-services' ), $show( (string) $wp['seen'] ) );

			if ( '' !== $wp['seen'] ) {
				/* translators: 1: the address WordPress has now, 2: the main address. */
				$button = array( $main, sprintf( __( 'Replace %1$s with %2$s in this website? A copy of the database is made first, and put back if the change fails.', 'beltoft-services' ), $show( (string) $wp['seen'] ), $show( $main ) ) );
			}
		} elseif ( 'failed' === $wp['note'] ) {
			$line = __( 'Moving WordPress did not work. Your website was not changed.', 'beltoft-services' );
		} elseif ( $wp['address'] !== $main ) {
			$working = array() !== array_filter( $own, static fn( $d ) => $d['host'] === $wp['address'] && 'active' !== $d['state'] );

			if ( $working ) {
				/* translators: %s: address. */
				$line = sprintf( __( 'WordPress uses %s, which does not point to your hosting now.', 'beltoft-services' ), $show( (string) $wp['address'] ) );
			} else {
				/* translators: %s: address. */
				$line = sprintf( __( 'WordPress uses %s.', 'beltoft-services' ), $show( (string) $wp['address'] ) );
			}
		} elseif ( $main !== $preview && '' !== $wp['prev'] ) {
			/* translators: %s: address. */
			$line = sprintf( __( 'WordPress now uses %s.', 'beltoft-services' ), $show( (string) $wp['address'] ) );
		}

		if ( null === $button && $wp['address'] !== $main && in_array( $wp['note'], array( '', 'failed' ), true ) && '' === $wp['moving'] ) {
			/* translators: %s: address. */
			$button = array( $main, sprintf( __( 'Move WordPress to %s? A copy of the database is made first, and put back if the change fails.', 'beltoft-services' ), $show( $main ) ) );
		}

		if ( '' === $line && null === $button ) {
			return;
		}

		echo '<p class="beltoft-hosting-line" data-wp-address>' . esc_html( $line );

		if ( $ready && null !== $button ) {
			/* translators: %s: address. */
			echo ' <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="wp_address" data-slug="' . esc_attr( $slug ) . '" data-host="' . esc_attr( $button[0] ) . '" data-confirm="' . esc_attr( $button[1] ) . '">' . esc_html( sprintf( __( 'Make %s the WordPress address', 'beltoft-services' ), $show( $button[0] ) ) ) . '</button>';
		}

		if ( $ready && null === $button && '' === $wp['moving'] && '' !== $wp['prev'] && $wp['prev'] !== $wp['address'] ) {
			/* translators: %s: address. */
			echo ' <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="wp_address" data-slug="' . esc_attr( $slug ) . '" data-host="' . esc_attr( (string) $wp['prev'] ) . '" data-confirm="' . esc_attr( sprintf( __( 'Move WordPress back to %s? A copy of the database is made first, and put back if the change fails.', 'beltoft-services' ), $show( (string) $wp['prev'] ) ) ) . '">' . esc_html( sprintf( __( 'Go back to %s', 'beltoft-services' ), $show( (string) $wp['prev'] ) ) ) . '</button>';
		}

		echo '</p>';
	}

	/**
	 * What the page says before a click about the user the sign-in link uses.
	 *
	 * @param string $user  User name the install made the administrator, or ''.
	 * @param string $email Its email, or ''.
	 */
	public static function login_facts( string $user, string $email ): string {
		if ( '' === $user ) {
			return __( 'Signs you in as the administrator of this website. No password is needed.', 'beltoft-services' );
		}

		if ( '' === $email ) {
			/* translators: %s: WordPress user name. */
			return sprintf( __( 'Signs you in as %s. No password is needed.', 'beltoft-services' ), $user );
		}

		/* translators: 1: WordPress user name, 2: email address. */
		return sprintf( __( 'Signs you in as %1$s (%2$s). No password is needed.', 'beltoft-services' ), $user, $email );
	}

	/**
	 * The control of a with-and-without-www pair: which name is the main address; the other one redirects to it.
	 *
	 * @param string               $slug  Site.
	 * @param array<string, mixed> $pair  base, www, main, ready.
	 * @param bool                 $ready Whether the account takes changes.
	 * @param string               $wp_address The address WordPress uses.
	 */
	private static function print_pair( string $slug, array $pair, bool $ready, string $wp_address = '' ): void {
		// Until both names point here there is nothing to choose: the DNS guide under the names says what to do first.
		if ( ! $pair['ready'] ) {
			return;
		}

		$name = 'beltoft-main-' . $slug . '-' . preg_replace( '/[^a-z0-9]/', '-', (string) $pair['base'] );

		echo '<div class="beltoft-hosting-main" role="radiogroup" aria-labelledby="' . esc_attr( $name ) . '-label" data-hosting-scope><span class="beltoft-hosting-main__label" id="' . esc_attr( $name ) . '-label">' . esc_html__( 'Main address', 'beltoft-services' ) . '</span>';

		foreach ( array( (string) $pair['base'], (string) $pair['www'] ) as $host ) {
			echo '<label class="beltoft-hosting-radio"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $host ) . '"' . checked( $host, (string) $pair['main'], false ) . ( $ready ? '' : ' disabled' ) . '> <span>' . esc_html( \BeltoftServices\Domains\HostName::display( $host ) ) . '</span></label>';
		}

		if ( $ready ) {
			echo '<button type="button" class="beltoft-hosting-btn" data-hosting-action="set_main">' . esc_html__( 'Save', 'beltoft-services' ) . '</button>';
		}

		// WordPress follows the choice when it is on a name of this pair; otherwise the choice leaves its address alone.
		$follows = in_array( $wp_address, array( (string) $pair['base'], (string) $pair['www'] ), true );

		echo '<p class="beltoft-hosting-help">' . esc_html( $follows ? __( 'The other address redirects to the main address. WordPress moves to the main address too.', 'beltoft-services' ) : __( 'The other address redirects to the main address. This does not change the address set in WordPress.', 'beltoft-services' ) ) . '</p>';

		if ( '' === (string) $pair['main'] ) {
			echo '<p class="beltoft-hosting-help">' . esc_html__( 'Both addresses show the website. Choose a main address to redirect the other one.', 'beltoft-services' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * The "Move a website here" panel: shown when the shop has a page for the request (setting "Website move request page").
	 */
	private static function print_move(): void {
		$url = trim( (string) \BeltoftServices\Settings::get( 'hosting_move_url' ) );
		$url = '' === $url || 0 === strpos( $url, '//' ) ? '' : esc_url_raw( 0 === strpos( $url, '/' ) ? home_url( $url ) : $url );

		/**
		 * The address of the "Request a move" button on the hosting account page. Return '' for no panel.
		 *
		 * @param string $url The setting, as a full address, or ''.
		 */
		$url = (string) apply_filters( 'beltoft_services_hosting_move_url', $url );

		if ( '' === $url ) {
			return;
		}

		$free = (bool) \BeltoftServices\Settings::get( 'hosting_move_free' );

		self::panel_open( esc_html__( 'Move a website here', 'beltoft-services' ), 'beltoft-hosting-move', '', 'beltoft-hosting-quiet' );
		echo '<p>' . esc_html( $free ? __( 'Have a website somewhere else? We move it here for you, free of charge.', 'beltoft-services' ) : __( 'Have a website somewhere else? We can move it here for you.', 'beltoft-services' ) ) . '</p>';
		echo '<p><a class="beltoft-hosting-btn" href="' . esc_url( $url ) . '">' . esc_html__( 'Request a move', 'beltoft-services' ) . '</a></p>';
		self::panel_close();
	}

	/**
	 * The Add a website panel: a title and the PHP version.
	 *
	 * @param array<string, mixed> $limits Ceilings.
	 */
	private static function print_add_site( array $limits ): void {
		self::panel_open( esc_html__( 'Add a website', 'beltoft-services' ), 'beltoft-hosting-add', '', 'beltoft-hosting-panel--quiet', ' data-hosting-scope' );
		echo '<div class="beltoft-hosting-formrow"><label class="beltoft-hosting-field"><span>' . esc_html__( 'Website title', 'beltoft-services' ) . '</span><input type="text" data-hosting-field="label" maxlength="100" autocomplete="off"></label>';
		echo '<label class="beltoft-hosting-field"><span>' . esc_html__( 'PHP version', 'beltoft-services' ) . '</span><select data-hosting-field="php">';

		foreach ( $limits['php_versions'] as $version ) {
			echo '<option value="' . esc_attr( $version ) . '">' . esc_html( 'PHP ' . $version ) . '</option>';
		}

		echo '</select></label><button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="add_site">' . esc_html__( 'Add website', 'beltoft-services' ) . '</button></div>';
		self::panel_close();
	}

	/**
	 * The Files (SFTP) panel: where to connect, a password (shown once), and public SSH keys. Setting either needs the
	 * emailed code, like the other credentials that stay valid (API tokens, the domain transfer code).
	 *
	 * @param Resource             $r     Hosting resource.
	 * @param array<string, mixed> $files HostingActions::files().
	 * @param bool                 $ready Whether the account takes changes.
	 */
	private static function print_files( Resource $r, array $files, bool $ready ): void {
		if ( '' === (string) $files['host'] || (int) $files['port'] < 1 ) {
			return;
		}

		self::panel_open( esc_html__( 'Files (SFTP)', 'beltoft-services' ), 'beltoft-hosting-files', '', '', ' data-hosting-scope' );
		echo '<dl class="beltoft-hosting-facts">';

		foreach ( array(
			__( 'Host', 'beltoft-services' )      => (string) $files['host'],
			__( 'Port', 'beltoft-services' )      => (string) $files['port'],
			__( 'User name', 'beltoft-services' ) => (string) $files['user'],
		) as $label => $value ) {
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd><code>' . esc_html( $value ) . '</code> <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="copy" data-copy="' . esc_attr( $value ) . '">' . esc_html__( 'Copy', 'beltoft-services' ) . '</button></dd></div>';
		}

		echo '<div><dt>' . esc_html__( 'Access', 'beltoft-services' ) . '</dt><dd>' . esc_html( ! empty( $files['ssh'] ) ? __( 'SFTP, and SSH with WP-CLI. The user name is the same for all your websites.', 'beltoft-services' ) : __( 'SFTP only. The user name is the same for all your websites.', 'beltoft-services' ) ) . '</dd></div>';

		echo '<div><dt>' . esc_html__( 'Password', 'beltoft-services' ) . '</dt><dd>';
		echo '<p class="beltoft-hosting-line">' . esc_html( ! empty( $files['password_set'] ) ? __( 'A password is set. It is not shown again.', 'beltoft-services' ) : __( 'No password is set yet.', 'beltoft-services' ) ) . '</p>';

		if ( $ready ) {
			echo '<button type="button" class="beltoft-hosting-btn" data-hosting-action="files_open" data-target="password" aria-expanded="false">' . esc_html__( 'Set a password', 'beltoft-services' ) . '</button>';
			echo '<div class="beltoft-hosting-secure" data-files-form="password" hidden>';
			self::print_code_field();
			echo '<label class="beltoft-hosting-field"><span>' . esc_html__( 'Your own password, 16 characters or more (optional)', 'beltoft-services' ) . '</span><input type="text" data-hosting-field="sftp_password" autocomplete="off" autocapitalize="off" spellcheck="false"></label>';
			echo '<p class="beltoft-hosting-help">' . esc_html__( 'Leave it empty to get a generated password. It is shown once and is not stored here.', 'beltoft-services' ) . '</p>';
			echo '<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="files_password">' . esc_html__( 'Set password', 'beltoft-services' ) . '</button><button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="files_close">' . esc_html__( 'Cancel', 'beltoft-services' ) . '</button></div></div>';
			echo '<div class="beltoft-hosting-reveal" data-files-reveal hidden><p class="beltoft-hosting-line">' . esc_html__( 'Your password. Copy it now: it is shown once and is not stored.', 'beltoft-services' ) . '</p><code data-files-secret></code> <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="copy_secret">' . esc_html__( 'Copy', 'beltoft-services' ) . '</button></div>';
		}

		echo '</dd></div>';

		echo '<div><dt>' . esc_html__( 'SSH keys', 'beltoft-services' ) . '</dt><dd>';

		if ( array() === $files['keys'] ) {
			echo '<p class="beltoft-hosting-line">' . esc_html__( 'No keys added.', 'beltoft-services' ) . '</p>';
		} else {
			echo '<ul class="beltoft-hosting-names">';

			foreach ( $files['keys'] as $key ) {
				echo '<li><span class="beltoft-hosting-name">' . esc_html( trim( $key['type'] . ' ' . $key['comment'] ) ) . '</span> <span class="beltoft-hosting-note">' . esc_html( $key['fingerprint'] ) . '</span>';

				if ( $ready ) {
					echo ' <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="remove_key" data-fingerprint="' . esc_attr( $key['fingerprint'] ) . '">' . esc_html__( 'Remove', 'beltoft-services' ) . '</button>';
				}

				echo '</li>';
			}

			echo '</ul>';
		}

		if ( $ready ) {
			echo '<button type="button" class="beltoft-hosting-btn" data-hosting-action="files_open" data-target="key" aria-expanded="false">' . esc_html__( 'Add an SSH key', 'beltoft-services' ) . '</button>';
			echo '<div class="beltoft-hosting-secure" data-files-form="key" hidden>';
			self::print_code_field();
			echo '<label class="beltoft-hosting-field beltoft-hosting-field--wide"><span>' . esc_html__( 'Public key', 'beltoft-services' ) . '</span><textarea rows="3" data-hosting-field="ssh_key" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="ssh-ed25519 AAAA..."></textarea></label>';
			echo '<div class="beltoft-hosting-buttons"><button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="files_key">' . esc_html__( 'Add key', 'beltoft-services' ) . '</button><button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="files_close">' . esc_html__( 'Cancel', 'beltoft-services' ) . '</button></div></div>';
		}

		echo '</dd></div></dl>';
		self::panel_close();
	}

	/**
	 * The emailed code field with its Send code button (used by both forms of the Files panel).
	 */
	private static function print_code_field(): void {
		echo '<div class="beltoft-hosting-formrow"><label class="beltoft-hosting-field"><span>' . esc_html__( 'Code from the email', 'beltoft-services' ) . '</span><input type="text" data-hosting-field="code" inputmode="numeric" autocomplete="one-time-code"></label><button type="button" class="beltoft-hosting-btn" data-hosting-action="send_code">' . esc_html__( 'Send code', 'beltoft-services' ) . '</button></div>';
	}

	/**
	 * The Email panel: the Email lite line and, once it exists, the extra storage of its mailboxes.
	 *
	 * @param Resource $r     Hosting resource.
	 * @param bool     $ready Whether the account takes changes.
	 */
	private static function print_email( Resource $r, bool $ready ): void {
		$mail = SiteMail::child_of( $r );

		self::panel_open( esc_html__( 'Email', 'beltoft-services' ), 'beltoft-hosting-email', '', 'beltoft-hosting-mail' );
		echo '<p>';

		if ( $mail ) {
			$mail_domain = \BeltoftServices\Domains\HostName::display( (string) $mail->get( \BeltoftServices\Mail\MailDomain::META_DOMAIN ) );

			echo esc_html( sprintf( '%s: %s', self::included_label( '', $mail ), $mail_domain ) );
			echo ' ' . esc_html(
				sprintf(
					/* translators: %s: the mail domain. */
					__( 'Email lite stays on %s. It does not move when the main domain changes.', 'beltoft-services' ),
					$mail_domain
				)
			);

			$area = \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Email::class );
			$url  = $area->url( $mail->license() );

			// Mail is ready to use (mailboxes can be made), but it does not arrive until the DNS records are in place.
			$dns_url = $area->url( $mail->license(), 'dns' );

			if ( '' !== $dns_url && 'external' === (string) $mail->get( 'mail_mode' ) && ( \BeltoftServices\Mail\MailDns::waiting( $mail ) || \BeltoftServices\Mail\MailField::waiting_for_proof( $mail ) ) ) {
				echo '</p><p class="beltoft-hosting-help">' . esc_html(
					sprintf(
						\BeltoftServices\Mail\MailField::waiting_for_proof( $mail )
							/* translators: %s: the mail domain. */
							? __( 'We are waiting for the proof that %s is yours. Add the record in the DNS records guide.', 'beltoft-services' )
							/* translators: %s: the mail domain. */
							: __( 'Mailboxes can be made now, but mail does not arrive until the DNS records for %s are added.', 'beltoft-services' ),
						$mail_domain
					)
				) . ( \BeltoftServices\Mail\MailHold::held( $mail ) ? ' ' . esc_html__( 'Until the SPF and DKIM records are found, your mailboxes and your websites cannot send mail.', 'beltoft-services' ) : '' ) . ' <a class="beltoft-hosting-link" href="' . esc_url( $dns_url ) . '">' . esc_html__( 'Open the DNS records guide', 'beltoft-services' ) . '</a>';
			}

			if ( '' !== $url ) {
				echo '</p><p><a class="beltoft-hosting-link" href="' . esc_url( $url ) . '">' . esc_html__( 'Manage mailboxes', 'beltoft-services' ) . '</a>';
			}
		} elseif ( null === LiteMail::ready_domain( $r ) ) {
			$sites   = Sites::for_resource( $r->id() );
			$first   = $sites ? (string) ( reset( $sites )['slug'] ?? '' ) : '';
			$waiting = '';

			foreach ( SiteDomains::for_resource( $r->id() ) as $d ) {
				if ( 'preview' !== $d['kind'] && 'active' !== $d['state'] && '' === $waiting ) {
					$waiting = \BeltoftServices\Domains\HostName::display( Addons::registrable( (string) $d['host'] ) ?: (string) $d['host'] );
				}
			}

			echo esc_html__( 'Email lite gives you mailboxes on your own domain, for example info@example.com. It is included with your plan.', 'beltoft-services' ) . '</p>';
			echo '<ol class="beltoft-hosting-steps"><li>';

			if ( '' !== $waiting ) {
				echo esc_html( sprintf( /* translators: %s: domain name. */ __( '%s is connected and waits for its DNS. Follow the DNS steps under the website.', 'beltoft-services' ), $waiting ) );
			} else {
				echo esc_html__( 'Connect your own domain to a website.', 'beltoft-services' );

				if ( '' !== $first && $ready ) {
					echo ' <a class="beltoft-hosting-link" href="#beltoft-site-' . esc_attr( $first ) . '-connect">' . esc_html__( 'Connect a domain', 'beltoft-services' ) . '</a>';
				}
			}

			echo '</li><li>' . esc_html__( 'When the domain is live, the button "Set up email" shows here. Press it.', 'beltoft-services' ) . '</li>';
			echo '<li>' . esc_html__( 'Create your mailboxes under Email in your account.', 'beltoft-services' ) . '</li></ol><p>';
		} else {
			echo esc_html(
				sprintf(
					/* translators: %s: the account's own domain. */
					__( 'Set up Email lite on %s so your websites can send mail. Email is included with your plan; without it a website cannot send mail.', 'beltoft-services' ),
					\BeltoftServices\Domains\HostName::display( (string) LiteMail::ready_domain( $r ) )
				)
			);

			$own_row = SiteDomains::find( (string) LiteMail::ready_domain( $r ) );

			if ( null !== $own_row && 'external' === $own_row['kind'] ) {
				echo '</p><p class="beltoft-hosting-help">' . esc_html(
					sprintf(
						/* translators: %s: the account's own domain. */
						__( 'After you press the button, you get a list of DNS records to add where the DNS of %s is managed. Three are needed: MX for receiving, SPF and DKIM for sending. Three more are recommended.', 'beltoft-services' ),
						\BeltoftServices\Domains\HostName::display( (string) LiteMail::ready_domain( $r ) )
					)
				);
			}

			if ( $ready ) {
				echo '</p><p><button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="enable_mail">' . esc_html__( 'Set up email', 'beltoft-services' ) . '</button>';
			}
		}

		echo '</p>';

		if ( $mail && $ready ) {
			self::print_packs( $r, $mail );
		}

		self::panel_close();
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
				Addons::money( (float) $quote['unit'], (string) ( $quote['currency'] ?? '' ) ),
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
					\BeltoftServices\Domains\HostName::display( $email ),
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
				echo ' <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="add_pack" data-mailbox="' . esc_attr( $email ) . '" data-confirm="' . esc_attr( $confirm ) . '" data-cents="' . (int) round( $quote['now'] * 100 ) . '">' . esc_html__( 'Add 5 GB', 'beltoft-services' ) . '</button>';
			}

			if ( $n - $ending > 0 ) {
				echo ' <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="remove_pack" data-mailbox="' . esc_attr( $email ) . '">' . esc_html__( 'Remove a pack', 'beltoft-services' ) . '</button>';
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

		self::panel_open( esc_html__( 'Backups', 'beltoft-services' ), 'beltoft-hosting-backups', '', 'beltoft-hosting-backups', ' data-hosting-scope' );

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
						'<td><div class="beltoft-hosting-actions"><button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="restore" data-scope="account" data-volid="%1$s">%2$s</button> <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="restore" data-scope="files" data-volid="%1$s">%3$s</button> <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="restore" data-scope="database" data-volid="%1$s">%4$s</button></div></td>',
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
			echo '<div class="beltoft-hosting-formrow">';

			if ( 1 === count( $sites ) ) {
				// One website: nothing to choose, the restore buttons use it.
				echo '<input type="hidden" data-hosting-field="restore_slug" value="' . esc_attr( (string) reset( $sites )['slug'] ) . '">';
			} elseif ( array() !== $sites ) {
				echo '<label class="beltoft-hosting-field"><span>' . esc_html__( 'Website to restore', 'beltoft-services' ) . '</span><select data-hosting-field="restore_slug">';

				foreach ( $sites as $site ) {
					echo '<option value="' . esc_attr( $site['slug'] ) . '">' . esc_html( self::site_name( $site ) ) . '</option>';
				}

				echo '</select></label>';
			}

			echo '<button type="button" class="beltoft-hosting-btn" data-hosting-action="backup_now">' . esc_html__( 'Back up now', 'beltoft-services' ) . '</button></div>';

			if ( null !== $safety ) {
				echo '<p>' . esc_html( sprintf( /* translators: %s: time the safety copy is kept until. */ __( 'A safety copy from before your last restore is kept until %s.', 'beltoft-services' ), wp_date( 'j F Y, H:i', (int) $safety['expires'] ) ) ) . ' <button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="undo">' . esc_html__( 'Undo the last restore', 'beltoft-services' ) . '</button></p>';
			}
		}

		self::panel_close();
	}

	/**
	 * The first-visit list as a panel: a count and "Hide this list" in the heading row, then one row for each item with its
	 * action at the right edge: a link to where it is done on this page, or "Mark as done".
	 *
	 * @param Resource                         $r     Hosting resource.
	 * @param bool                             $ready Whether the account takes changes.
	 * @param array<int, array<string, mixed>> $sites Sites.
	 */
	private static function print_checklist( Resource $r, bool $ready, array $sites ): void {
		$labels = array(
			'domain'   => __( 'Connect your domain', 'beltoft-services' ),
			'email'    => __( 'Set up email', 'beltoft-services' ),
			'build'    => __( 'Build or upload your site', 'beltoft-services' ),
			'backups'  => __( 'Check your backups', 'beltoft-services' ),
			'password' => __( 'Set a WordPress password', 'beltoft-services' ),
		);
		$items  = self::checklist( $r );
		$done   = count( array_filter( $items ) );
		$count  = '<span class="beltoft-hosting-panel__count">' . esc_html( sprintf( /* translators: 1: items done, 2: items in the list. */ __( '%1$d of %2$d done', 'beltoft-services' ), $done, count( $items ) ) ) . '</span>';
		$hide   = $ready ? '<button type="button" class="beltoft-hosting-linkbtn beltoft-hosting-panel__aside" data-hosting-action="dismiss_checklist">' . esc_html__( 'Hide this list', 'beltoft-services' ) . '</button>' : '';

		self::panel_open( esc_html__( 'Get started', 'beltoft-services' ), 'beltoft-hosting-checklist', $count . $hide, 'beltoft-hosting-checklist' );
		echo '<ul>';

		foreach ( $items as $key => $is_done ) {
			echo '<li data-done="' . ( $is_done ? '1' : '0' ) . '"><span class="beltoft-hosting-mark" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $is_done ? __( 'Done:', 'beltoft-services' ) : __( 'To do:', 'beltoft-services' ) ) . '</span><span class="beltoft-hosting-check-label">' . esc_html( $labels[ $key ] ) . '</span>';

			if ( ! $is_done ) {
				if ( 'domain' === $key && array() !== $sites ) {
					echo '<a class="beltoft-hosting-linkbtn beltoft-hosting-check-action" href="#beltoft-site-' . esc_attr( (string) $sites[0]['slug'] ) . '-connect">' . esc_html__( 'Connect a domain', 'beltoft-services' ) . '</a>';
				} elseif ( 'email' === $key ) {
					echo '<a class="beltoft-hosting-linkbtn beltoft-hosting-check-action" href="#beltoft-hosting-email">' . esc_html__( 'Set up email', 'beltoft-services' ) . '</a>';
				} elseif ( $ready && in_array( $key, self::MANUAL_ITEMS, true ) ) {
					echo '<button type="button" class="beltoft-hosting-linkbtn beltoft-hosting-check-action" data-hosting-action="check_item" data-item="' . esc_attr( $key ) . '">' . esc_html__( 'Mark as done', 'beltoft-services' ) . '</button>';
				}
			}

			echo '</li>';
		}

		echo '</ul>';
		self::panel_close();
	}
}
