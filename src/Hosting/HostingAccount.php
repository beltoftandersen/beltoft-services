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
use BeltoftServices\Settings;

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
		return array_merge( (array) $purposes, array( HostingRoutes::STEP_PURPOSE, HostingRoutes::DB_PURPOSE ) );
	}

	/**
	 * What the code email says this purpose is for.
	 *
	 * @param mixed  $text    Text so far.
	 * @param string $purpose The purpose.
	 */
	public static function stepup_text( $text, $purpose ): string {
		if ( HostingRoutes::DB_PURPOSE === $purpose ) {
			return __( 'showing the database password of your hosting', 'beltoft-services' );
		}

		return HostingRoutes::STEP_PURPOSE === $purpose ? __( 'setting a password or an SSH key for your hosting files', 'beltoft-services' ) : (string) $text;
	}

	public const HANDLE = 'beltoft-services-hosting';

	/** The checklist items the customer ticks by hand. */
	public const MANUAL_ITEMS = array( 'build', 'backups', 'password' );

	/** Items that are never required: an open one does not keep the list on the page. */
	public const OPTIONAL_ITEMS = array( 'password' );

	/**
	 * The hosting panel's stylesheet.
	 */
	public static function style(): void {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/hosting.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/hosting.css' ) );
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
					'cronNever' => __( 'It has not run yet.', 'beltoft-services' ),
					/* translators: %s: date and time. */
					'cronOk' => __( 'Last run: %s, it ended well.', 'beltoft-services' ),
					/* translators: 1: date and time, 2: the exit code of the job. */
					'cronFailed' => __( 'Last run: %1$s, it failed with exit code %2$s.', 'beltoft-services' ),
					/* translators: %s: date and time. */
					'cronTimedOut' => __( 'Last run: %s, it was stopped because it took too long.', 'beltoft-services' ),
					/* translators: %s: date and time. */
					'cronSkipped' => __( 'Last start: %s, it was skipped because the run before was still going.', 'beltoft-services' ),
					'dbPasswordShown' => __( 'The database password is shown below. Copy it now.', 'beltoft-services' ),
					'copied'    => __( 'Copied.', 'beltoft-services' ),
					'confirmRestore' => __( 'Restore from this backup? A safety copy of your hosting is made first, so you can undo it for 24 hours.', 'beltoft-services' ),
					'confirmPack' => __( 'Remove one extra storage pack from this mailbox? It ends at your next renewal, the storage stays until then, and nothing is refunded.', 'beltoft-services' ),
					'confirmUndo' => __( 'Go back to the safety copy? Changes made since the restore are lost.', 'beltoft-services' ),
					'cachePageOn' => _x( 'On', 'setting state', 'beltoft-services' ),
					'cachePageOff' => __( 'Off, switched off in WordPress', 'beltoft-services' ),
					'cachePageInactive' => __( 'Off, its WordPress plugin is deactivated', 'beltoft-services' ),
					'cachePageMissing' => __( 'Off, its WordPress plugin is not installed', 'beltoft-services' ),
					'cacheObjectOn' => _x( 'On', 'setting state', 'beltoft-services' ),
					'cacheObjectOff' => _x( 'Off', 'setting state', 'beltoft-services' ),
					/* translators: %s: the state of the page cache, for example On. */
					'cacheRowValue' => __( 'Page cache: %s', 'beltoft-services' ),
					'saveAnyway' => __( 'Save anyway', 'beltoft-services' ),
					/* translators: %s: what the account is doing now, for example "adding a website". */
					'waitUntil' => __( 'Wait until %s is done.', 'beltoft-services' ),
					/* translators: %s: what was done, for example "Adding a website". */
					'taskDone' => __( '%s is done.', 'beltoft-services' ),
					'taskLong' => __( 'This takes longer than usual. It goes on in the background.', 'beltoft-services' ),
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

		// The title the customer gave, alone: the website's own address is in the row beside it.
		return $label;
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
	 * The page: the tab strip, the account's task line and notices, then the parts of the tab shown. A page of the
	 * Hosting area (ServiceArea::page()) has tabs, each its own address, and prints only the tab asked for (the
	 * overview when it is none, or one that is not offered). Anywhere else (no page around it) every part follows the
	 * others, with anchors for the links between them. Facts are printed by the server; the changes go through the REST routes.
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
		$data    = array(
			'limits' => $actions->limits(),
			'all'    => $actions->sites(),
		);
		$state   = array( 'task' => Actions\Steps::view( $r ), 'last' => Actions\Steps::last( $r ) );
		$tabs    = self::page_tabs( $r );
		$active  = null === $tabs ? '' : $tabs['active'];
		$nav     = array();

		HostingView::set_page( $tabs );

		try {
			foreach ( null === $tabs ? array() : $tabs['tabs'] as $slug => $title ) {
				$nav[ $slug ] = array( 'title' => $title, 'url' => HostingView::link( (string) $slug ) );
			}

			$body = '';

			foreach ( null === $tabs ? array_keys( HostingView::tabs( $r ) ) : array( $active ) as $slug ) {
				$body .= self::tab( $r, $actions, $data, (string) $slug, null !== $tabs );
			}

			HostingView::render(
				'page',
				array(
					'resource_id' => $r->id(),
					'task_json'   => null === $state['task'] ? '' : (string) wp_json_encode( $state['task'] ),
					'task'        => HostingView::task_line( $state ),
					'notices'     => HostingView::notices( $r ),
					'nav'         => $nav,
					'active'      => $active,
					'body'        => $body,
				)
			);
		} finally {
			HostingView::set_page( null );
		}
	}

	/**
	 * The tabs of the page being printed, or null when the panel is not on a page of the Hosting area.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array{area: \BeltoftServices\Account\ServiceArea, license: array<string, mixed>, tabs: array<string, string>, active: string}|null
	 */
	private static function page_tabs( Resource $r ): ?array {
		$page = \BeltoftServices\Account\ServiceArea::page();

		if ( ! $page || ! ( $page['area'] ?? null ) instanceof \BeltoftServices\Hosting\Hosting ) {
			return null;
		}

		$tabs  = HostingView::tabs( $r );
		$asked = (string) ( $page['tab'] ?? '' );

		return array(
			'area'    => $page['area'],
			'license' => (array) $page['license'],
			'tabs'    => $tabs,
			'active'  => isset( $tabs[ $asked ] ) ? $asked : 'overview',
		);
	}

	/**
	 * The parts of one tab, printed.
	 *
	 * Overview: the account at a glance, the first-visit list and the monitoring. Websites: each website with its
	 * domains, PHP, cache, staging and country block, Add a website and Move a website here. Files: SFTP. Backups and
	 * Email are one panel each.
	 *
	 * @param Resource       $r       Hosting resource.
	 * @param HostingActions $actions Actions of the account.
	 * @param array{limits: array<string, mixed>, all: array<int, array<string, mixed>>} $data The ceilings and the sites, read once.
	 * @param string         $tab     Tab.
	 * @param bool           $tabbed  Whether the page has tabs (no jump links, links go to the tabs).
	 */
	private static function tab( Resource $r, HostingActions $actions, array $data, string $tab, bool $tabbed ): string {
		$limits = $data['limits'];
		$all    = $data['all'];
		$sites  = array_values( array_filter( $all, static fn( $s ) => empty( $s['pending'] ) ) );
		$ready  = 'ready' === $r->get( 'state' ) && 'ready' === $r->get( 'desired' );
		$out    = '';

		if ( 'overview' === $tab ) {
			self::enqueue_monitoring();
			$out .= HostingView::capture( 'overview', HostingView::overview( $r, $limits, ! $tabbed ) );

			if ( self::checklist_visible( $r ) ) {
				$out .= HostingView::capture( 'checklist', HostingView::checklist( $r, $ready, $sites ) );
			}

			$monitoring = HostingView::monitoring( $r, $ready );
			$out       .= null === $monitoring ? '' : HostingView::capture( 'monitoring', $monitoring );
		} elseif ( 'websites' === $tab ) {
			// Whether each website has WordPress: read again in the background when the last reading is a day old.
			if ( $ready && SiteFacts::due( $r, SiteFacts::TAB_AFTER ) ) {
				SiteFacts::ask( $r );
			}

			$pending = array_values( array_filter( $all, static fn( $s ) => ! empty( $s['pending'] ) ) );
			$ending  = Addons::ending_domains( $r );
			$node    = (array) ( Nodes::get( (string) ( Records::for_resource( $r->id() )['node'] ?? '' ) ) ?? array() );
			$views   = array();

			foreach ( $sites as $site ) {
				$views[] = HostingView::site( $r, $site, count( $sites ) > 1, $ready, $ending, $node, $limits );
			}

			$out .= HostingView::capture(
				'websites',
				array(
					'sites'   => $views,
					'pending' => array_map( array( HostingView::class, 'pending_site' ), $pending ),
					'add'     => $ready && ( 0 === $limits['sites'] || $limits['sites_used'] < $limits['sites'] ) ? (array) $limits['php_versions'] : null,
					'move'    => HostingView::move(),
				)
			);
		} elseif ( 'files' === $tab ) {
			$files = HostingView::files( $actions->files(), $ready );
			$out  .= null === $files ? '' : HostingView::capture( 'files', $files );
		} elseif ( 'backups' === $tab ) {
			$backups = HostingView::backups( $r, $sites, $ready );
			$out    .= null === $backups ? '' : HostingView::capture( 'backups', $backups );
		} elseif ( 'email' === $tab ) {
			$out .= HostingView::capture( 'email', HostingView::email( $r, $ready ) );
		}

		return $out;
	}

	/**
	 * The scripts of the monitoring: the charts (the shared script of the server page) and the live bars, with the words of a
	 * hosting account (memory is read by the node agent, not a guest agent).
	 */
	private static function enqueue_monitoring(): void {
		$hint = __( 'Memory could not be read just now. It is tried again every few minutes.', 'beltoft-services' );

		\BeltoftServices\Monitoring\Charts::enqueue(
			\BeltoftServices\Account\Panel::HANDLE,
			array(
				'agentHint' => $hint,
				'alertsOn'  => __( 'Usage emails are on for this hosting.', 'beltoft-services' ),
				'alertsOff' => __( 'Usage emails are off for this hosting.', 'beltoft-services' ),
			)
		);
		\BeltoftServices\Monitoring\LiveUsage::enqueue( $hint );
	}

	/**
	 * The size of a backup for the list: always GB with one decimal, so two backups can be compared at a glance.
	 *
	 * @param int $bytes Bytes the backup holds.
	 */
	public static function backup_size( int $bytes ): string {
		$gb = max( 0, $bytes ) / GB_IN_BYTES;

		if ( $bytes > 0 && $gb < 0.05 ) {
			return __( 'Under 0.1 GB', 'beltoft-services' );
		}

		/* translators: %s: size in gigabytes with one decimal, for example 1.2. */
		return sprintf( __( '%s GB', 'beltoft-services' ), number_format_i18n( $gb, 1 ) );
	}
}
