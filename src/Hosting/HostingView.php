<?php
/**
 * The view models of the hosting account page: what each template under templates/account/hosting/ is given.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * HostingAccount assembles the account's data and picks the tab; this class turns the data of one panel into the
 * values its template prints (no markup here), and renders the templates. Nothing in a template reads the account
 * or calls the node: every fact is passed in.
 */
final class HostingView {

	/** The tab addresses the page accepts after the account's address (the overview is the address itself). */
	public const TABS = array( 'websites', 'files', 'backups', 'email' );

	/**
	 * The tab page being printed: area, license, the tabs offered and the one shown. Null when the panel is printed on its
	 * own (a card, or no page around it): then every part follows the others and the links are anchors on the page.
	 *
	 * @var array{area: ServiceArea, license: array<string, mixed>, tabs: array<string, string>, active: string}|null
	 */
	private static ?array $page = null;

	/**
	 * Sets the tab page being printed, or null.
	 *
	 * @param array{area: ServiceArea, license: array<string, mixed>, tabs: array<string, string>, active: string}|null $page Page.
	 */
	public static function set_page( ?array $page ): void {
		self::$page = $page;
	}

	/**
	 * The address of a tab of this account (the overview is '' ), with an optional element id: an anchor on the page when there are no tabs.
	 *
	 * @param string $tab  Tab, 'overview' or one of TABS.
	 * @param string $hash Element id without #, or ''.
	 */
	public static function link( string $tab, string $hash = '' ): string {
		if ( null === self::$page ) {
			return '' === $hash ? '' : '#' . $hash;
		}

		$url = self::$page['area']->url( self::$page['license'], 'overview' === $tab ? '' : $tab );

		return $url . ( '' === $hash ? '' : '#' . $hash );
	}

	/**
	 * Prints a template of templates/account/hosting/.
	 *
	 * @param string               $name Template name without .php.
	 * @param array<string, mixed> $v    Values.
	 */
	public static function render( string $name, array $v = array() ): void {
		( static function ( string $file, array $v ): void {
			include $file;
		} )( BELTOFT_SERVICES_PATH . 'templates/account/hosting/' . $name . '.php', $v );
	}

	/**
	 * A template as a string.
	 *
	 * @param string               $name Template name.
	 * @param array<string, mixed> $v    Values.
	 */
	public static function capture( string $name, array $v = array() ): string {
		ob_start();
		self::render( $name, $v );

		return (string) ob_get_clean();
	}

	/**
	 * The tabs the customer is offered for an account: slug => title, in order. Backups only when they are on.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<string, string>
	 */
	public static function tabs( Resource $r ): array {
		$tabs = array(
			'overview' => __( 'Overview', 'beltoft-services' ),
			'websites' => __( 'Websites', 'beltoft-services' ),
			'files'    => __( 'Files', 'beltoft-services' ),
		);

		if ( HostingBackups::enabled( $r ) ) {
			$tabs['backups'] = __( 'Backups', 'beltoft-services' );
		}

		$tabs['email'] = __( 'Email', 'beltoft-services' );

		return $tabs;
	}

	/**
	 * The line of the account's background task, and the failure of the last change.
	 *
	 * @param array{task: array<string, mixed>|null, last: array<string, mixed>|null} $state Task and last result.
	 * @return array{hidden: bool, step: string, wait: string, failed: string}
	 */
	public static function task_line( array $state ): array {
		$task  = $state['task'];
		$label = null === $task ? '' : (string) $task['label'];
		$first = mb_strtolower( mb_substr( $label, 0, 1 ) ) . mb_substr( $label, 1 );
		$last  = $state['last'];

		return array(
			'hidden' => null === $task,
			'step'   => null === $task ? '' : (string) $task['step_label'],
			/* translators: %s: what the account is doing now, for example "adding a website". */
			'wait'   => null === $task ? '' : sprintf( __( 'Wait until %s is done.', 'beltoft-services' ), $first ),
			'failed' => null === $task && null !== $last && empty( $last['ok'] ) && 'wordpress_address' !== $last['action'] ? trim( (string) $last['label'] . '. ' . (string) $last['text'] ) : '',
		);
	}

	/**
	 * The notices that belong to no website: a hint, a paid domain that is being connected, an unsupported PHP version
	 * and a refused loader file.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<int, array{class: string, text: string}>
	 */
	public static function notices( Resource $r ): array {
		$out = array();

		if ( '' !== SiteNewDomain::hint( $r ) ) {
			$out[] = array( 'class' => 'beltoft-hosting-notice', 'text' => SiteNewDomain::hint( $r ) );
		}

		foreach ( Addons::unconnected_domains( $r ) as $paid ) {
			$out[] = array(
				'class' => 'beltoft-hosting-notice',
				/* translators: %s: domain name. */
				'text'  => sprintf( __( 'Your extra domain %s is paid. It is being connected.', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( $paid ) ),
			);
		}

		foreach ( Addons::included_domains( $r ) as $included ) {
			$out[] = array(
				'class' => 'beltoft-hosting-notice',
				/* translators: %s: domain name. */
				'text'  => sprintf( __( '%s is now your included domain. Its extra domain charge ends at the next renewal.', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( $included ) ),
			);
		}

		foreach ( (array) $r->get( 'php_pinned' ) as $slug => $version ) {
			if ( null !== Sites::find( $r->id(), (string) $slug ) && Config::php_end( (string) $version ) !== null && (int) Config::php_end( (string) $version ) < \BeltoftServices\Jobs::now() ) {
				$out[] = array(
					'class' => 'beltoft-hosting-warning',
					/* translators: 1: website name, 2: PHP version. */
					'text'  => sprintf( __( 'The website %1$s runs on PHP %2$s, which no longer gets security updates. Move it to a supported version as soon as you can.', 'beltoft-services' ), HostingAccount::site_name_of( $r, (string) $slug ), (string) $version ),
				);
			}
		}

		foreach ( HostingUsage::loader_problems( $r ) as $slug => $state ) {
			$out[] = array(
				'class' => 'beltoft-hosting-notice',
				/* translators: 1: website name, 2: state. */
				'text'  => sprintf( __( 'The website %1$s needs the hosting loader file for the sign-in link and website mail (%2$s). Contact support if it keeps showing.', 'beltoft-services' ), HostingAccount::site_name_of( $r, (string) $slug ), $state ),
			);
		}

		return $out;
	}

	/**
	 * The account at a glance: websites, live bars and storage, and the jump links of a page without tabs.
	 *
	 * @param Resource             $r      Hosting resource.
	 * @param array<string, mixed> $limits Ceilings.
	 * @param bool                 $jump   Print the links to the parts further down (only when there are no tabs).
	 * @return array<string, mixed>
	 */
	public static function overview( Resource $r, array $limits, bool $jump ): array {
		$storage = HostingUsage::storage_percent( $r );
		$ready   = 'ready' === $r->get( 'state' );
		$memory  = $ready ? \BeltoftServices\Monitoring\GuestMemory::view( $r->id() ) : null;

		return array(
			'usage_id'  => $ready ? (int) $r->id() : 0,
			'sites'     => 0 === (int) $limits['sites']
				? (string) (int) $limits['sites_used']
				/* translators: 1: websites used, 2: websites allowed. */
				: sprintf( __( '%1$d of %2$d', 'beltoft-services' ), (int) $limits['sites_used'], (int) $limits['sites'] ),
			'ready'     => $ready,
			'mem_state' => null === $memory ? '' : (string) $memory['state'],
			'mem_pct'   => null === $memory ? 0 : (int) ( $memory['percent'] ?? 0 ),
			'mem_text'  => null === $memory ? '' : ( 'ok' === $memory['state'] ? (int) $memory['percent'] . '%' : ( 'no_agent' === $memory['state'] ? "\u{2013}" : "\u{2026}" ) ),
			'storage'   => null === $storage ? null : (int) round( max( 0.0, min( 100.0, (float) $storage ) ) ),
			'jump'      => $jump,
			'backups'   => HostingBackups::enabled( $r ),
		);
	}

	/**
	 * The monitoring panel's values (the arguments of the shared charts panel), or null when the account is not ready.
	 *
	 * @param Resource $r     Hosting resource.
	 * @param bool     $ready Whether the account is ready.
	 * @return array<string, mixed>|null
	 */
	public static function monitoring( Resource $r, bool $ready ): ?array {
		if ( ! $ready ) {
			return null;
		}

		$plan    = Plan::typed( (array) $r->get( 'plan' ) );
		$disk    = ( new HostingGuest() )->disk( $r );
		$pct     = null === $disk ? null : max( 0, min( 100, (int) round( $disk['used'] / $disk['total'] * 100 ) ) );
		$states  = \BeltoftServices\Monitoring\UsageRules::states( $r );
		$storage = (array) $r->get( 'storage' );

		if ( ! empty( $storage['warned'] ) && null !== $pct ) {
			/* translators: 1: percent of the storage in use, 2: date. */
			array_unshift( $states, sprintf( __( 'Storage %1$d percent full since %2$s', 'beltoft-services' ), $pct, wp_date( (string) get_option( 'date_format' ), (int) $storage['warned'] ) ) );
		}

		return array(
			'states'       => $states,
			'alerts_shown' => 'on' === Settings::get( 'monitor_alerts' ),
			'alerts_on'    => \BeltoftServices\Monitoring\UsageRules::wanted( $r ),
			'alerts_label' => __( 'Email me when this hosting is short of CPU, memory or storage', 'beltoft-services' ),
			'disk'         => array(
				'title' => __( 'Storage', 'beltoft-services' ),
				'pct'   => $pct,
				/* translators: %d: percent of the storage in use */
				'aria'  => sprintf( __( 'Storage, %d percent used', 'beltoft-services' ), (int) $pct ),
				'line'  => null !== $disk
					/* translators: 1: used storage in GB (files and databases), 2: storage of the plan in GB. */
					? sprintf( __( 'Storage used: %1$s of %2$s GB', 'beltoft-services' ), number_format_i18n( $disk['used'] / 1073741824, 1 ), number_format_i18n( $disk['total'] / 1073741824, 1 ) )
					/* translators: %d: the plan's storage in GB. */
					: sprintf( __( '%d GB', 'beltoft-services' ), (int) $plan['disk_gb'] ),
				'note'  => __( 'Files and databases, read every hour', 'beltoft-services' ),
			),
		);
	}

	/**
	 * The first-visit list.
	 *
	 * @param Resource                         $r     Hosting resource.
	 * @param bool                             $ready Whether the account takes changes.
	 * @param array<int, array<string, mixed>> $sites Sites.
	 * @return array<string, mixed>
	 */
	public static function checklist( Resource $r, bool $ready, array $sites ): array {
		$labels = array(
			'domain'   => __( 'Connect your domain', 'beltoft-services' ),
			'email'    => __( 'Set up email', 'beltoft-services' ),
			'build'    => __( 'Build or upload your site', 'beltoft-services' ),
			'backups'  => __( 'Check your backups', 'beltoft-services' ),
			'password' => __( 'Set a WordPress password', 'beltoft-services' ),
		);
		$items  = HostingAccount::checklist( $r );
		$rows   = array();

		// The WordPress password is for a website with WordPress. Websites that were not read yet count as WordPress (Sites::has_wordpress()).
		if ( array() !== $sites && array() === array_filter( $sites, static fn( $site ) => ! array_key_exists( 'wordpress', $site ) || ! empty( $site['wordpress'] ) ) ) {
			unset( $items['password'] );
		}

		foreach ( $items as $key => $is_done ) {
			$row = array(
				'done'  => (bool) $is_done,
				'label' => $labels[ $key ],
				'link'  => '',
				'text'  => '',
				'item'  => '',
			);

			if ( ! $is_done ) {
				if ( 'domain' === $key && array() !== $sites ) {
					$row['link'] = self::link( 'websites', 'beltoft-site-' . (string) $sites[0]['slug'] . '-connect' );
					$row['text'] = __( 'Connect a domain', 'beltoft-services' );
				} elseif ( 'email' === $key ) {
					$row['link'] = self::link( 'email', 'beltoft-hosting-email' );
					$row['text'] = __( 'Set up email', 'beltoft-services' );
				} elseif ( $ready && in_array( $key, HostingAccount::MANUAL_ITEMS, true ) ) {
					$row['item'] = (string) $key;
					$row['text'] = __( 'Mark as done', 'beltoft-services' );
				}
			}

			$rows[] = $row;
		}

		$done = count( array_filter( $items ) );

		return array(
			'rows'  => $rows,
			'ready' => $ready,
			/* translators: 1: items done, 2: items in the list. */
			'count' => sprintf( __( '%1$d of %2$d done', 'beltoft-services' ), $done, count( $items ) ),
		);
	}

	/**
	 * A website that is being added.
	 *
	 * @param array<string, mixed> $site Pending site from HostingActions::sites().
	 * @return array<string, string>
	 */
	public static function pending_site( array $site ): array {
		$slug = (string) $site['slug'];

		return array(
			'slug'  => $slug,
			'title' => '' !== (string) $site['label'] ? (string) $site['label'] : $slug,
			'step'  => (string) $site['task']['step_label'],
		);
	}

	/**
	 * What the plan includes in domains, the price of each further one, and which domain is the included one.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function domain_allowance( Resource $r ): string {
		$paid = array();

		foreach ( Addons::lines( $r, Addons::KIND_DOMAIN ) as $item ) {
			if ( 'yes' !== (string) $item->get_meta( Addons::I_INCLUDED ) ) {
				$paid[] = (string) $item->get_meta( Addons::I_TARGET );
			}
		}

		$free = '';

		foreach ( Addons::connected_domains( $r ) as $reg ) {
			if ( ! in_array( $reg, $paid, true ) ) {
				$free = $reg;
				break;
			}
		}

		$quote = Addons::quote( $r, Addons::KIND_DOMAIN );
		$text  = __( 'Your plan includes one domain and all its subdomains.', 'beltoft-services' );

		if ( null !== $quote ) {
			$text .= ' ' . sprintf(
				/* translators: 1: price per month, 2: "incl. VAT" or "excl. VAT". */
				__( 'Each further domain costs %1$s a month, %2$s.', 'beltoft-services' ),
				Addons::shown( (float) $quote['unit'], (string) ( $quote['currency'] ?? '' ) ),
				\BeltoftServices\Billing\TaxNote::label()
			);
		}

		if ( '' !== $free ) {
			/* translators: %s: domain name. */
			$text .= ' ' . sprintf( __( 'Included: %s.', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( $free ) );
		}

		return $text;
	}

	/**
	 * One website: its heading, the domain rows and the values of each section.
	 *
	 * @param Resource             $r      Hosting resource.
	 * @param array<string, mixed> $site   Site from HostingActions::sites().
	 * @param bool                 $many   Whether the account has more than one website.
	 * @param bool                 $ready  Whether the account takes changes.
	 * @param string[]             $ending Registrable domains that end at the next renewal.
	 * @param array<string, mixed> $node   Node settings.
	 * @param array<string, mixed> $limits Ceilings.
	 * @return array<string, mixed>
	 */
	public static function site( Resource $r, array $site, bool $many, bool $ready, array $ending, array $node, array $limits ): array {
		$slug    = (string) $site['slug'];
		$main    = (string) $site['main'];
		$wp      = (array) $site['wp'];
		$has_wp  = ! array_key_exists( 'wordpress', $site ) || (bool) $site['wordpress'];
		$names   = array_filter( $site['domains'], static fn( $d ) => 'preview' !== $d['kind'] );
		$waiting = array() !== array_filter( $names, static fn( $d ) => 'active' !== $d['state'] );
		$own     = array() !== $names;
		$busy    = ! empty( $site['task'] );
		$domains = array();

		foreach ( $site['domains'] as $d ) {
			$roles = array();

			if ( 'preview' === $d['kind'] ) {
				$roles[] = __( 'Temporary address', 'beltoft-services' );
			} elseif ( $d['host'] === $main ) {
				$roles[] = __( 'Main address', 'beltoft-services' );
			}

			if ( $has_wp && $own && $d['host'] === $wp['address'] ) {
				$roles[] = __( 'WordPress address', 'beltoft-services' );
			}

			if ( 'preview' !== $d['kind'] && in_array( Addons::registrable( $d['host'] ), $ending, true ) ) {
				$roles[] = __( 'ends at the next renewal', 'beltoft-services' );
			}

			if ( 'active' === $d['state'] ) {
				$state = array( 'ok', __( 'Connected', 'beltoft-services' ) );
			} elseif ( 'waiting' === $d['state'] ) {
				$state = array( 'warn', __( 'Waiting for DNS', 'beltoft-services' ) );
			} else {
				$state = array( 'muted', __( 'Pending', 'beltoft-services' ) );
			}

			$domains[] = array(
				'host'      => (string) $d['host'],
				'display'   => \BeltoftServices\Domains\HostName::display( $d['host'] ),
				'tone'      => $state[0],
				'state'     => $state[1],
				'roles'     => implode( ', ', $roles ),
				'removable' => $ready && 'preview' !== $d['kind'],
			);
		}

		$marker   = ( (array) $r->get( 'php_rollback' ) )[ $slug ] ?? null;
		$rollback = is_array( $marker ) && (int) ( $marker['until'] ?? 0 ) > \BeltoftServices\Jobs::now() ? (string) $marker['from'] : '';
		$who      = array();

		if ( '' !== (string) $site['admin_user'] ) {
			$who[ __( 'WordPress user', 'beltoft-services' ) ] = (string) $site['admin_user'];

			if ( '' !== (string) $site['admin_email'] ) {
				$who[ __( 'Email address', 'beltoft-services' ) ] = (string) $site['admin_email'];
			}
		}

		return array(
			'slug'        => $slug,
			'has_wp'      => $has_wp,
			'database'    => array_merge( array( 'name' => '', 'user' => '', 'host' => '' ), (array) ( $site['database'] ?? array() ) ),
			'cron'        => SiteCron::view( $site, $ready, $limits, SiteCron::own_names( $r->id(), (array) Sites::find( $r->id(), $slug ) ), (int) ( \BeltoftServices\Resource::find( $r->id() ) ?? $r )->get( SiteCron::UNSUPPORTED ) ),
			'ready'       => $ready,
			'many'        => $many,
			'name'        => HostingAccount::site_name( $site ),
			'main'        => $main,
			'main_show'   => \BeltoftServices\Domains\HostName::display( $main ),
			'status_ok'   => $ready && ! $busy,
			'idle_text'   => $ready ? __( 'Online', 'beltoft-services' ) : __( 'Not ready', 'beltoft-services' ),
			'status_text' => $busy ? (string) $site['task']['step_label'] : ( $ready ? __( 'Online', 'beltoft-services' ) : __( 'Not ready', 'beltoft-services' ) ),
			'domains'     => array(
				'value'   => array() === $names
					? __( 'Temporary address only', 'beltoft-services' )
					/* translators: %d: number of names connected to the website. */
					: sprintf( _n( '%d name connected', '%d names connected', count( $names ), 'beltoft-services' ), count( $names ) ) . ( $waiting ? ', ' . __( 'waiting for DNS', 'beltoft-services' ) : '' ),
				'open'    => $waiting,
				'rows'    => $domains,
				'wp'      => $has_wp ? self::wordpress( $slug, $site, $ready ) : null,
				'pairs'   => array_values( array_filter( array_map( static fn( $pair ) => self::pair( $slug, $pair, $ready, (string) $wp['address'], $has_wp ), (array) $site['pairs'] ) ) ),
				'allowance' => $ready ? self::domain_allowance( $r ) : '',
			),
			'php'         => array(
				'version'  => (string) $site['php'],
				'versions' => (array) $limits['php_versions'],
				'rollback' => $rollback,
			),
			'login'       => array(
				'user'  => (string) $site['admin_user'],
				'who'   => $who,
				'url'   => 'https://' . $wp['address'] . '/wp-login.php',
			),
			'scan'        => self::scan( (array) $site['scan'] ),
			'staging'     => self::staging( $slug, (array) $site['staging'], $ready, $limits, $has_wp ),
			'country'     => self::country( $slug, (array) $site['country'], $ready, $has_wp ),
			'site'        => $site,
			'node'        => $node,
			'resource'    => $r,
			'sftp_link'   => self::link( 'files', 'beltoft-hosting-files' ),
		);
	}

	/**
	 * The result of the last nightly malware scan of a website, or null when it was never scanned.
	 *
	 * @param array<string, mixed> $v MalwareScan::view().
	 * @return array{found: bool, value: string, words: string}|null
	 */
	public static function scan( array $v ): ?array {
		$words = MalwareScan::words( $v );

		if ( '' === $words ) {
			return null;
		}

		$found = 'found' === $v['state'];

		return array(
			'found' => $found,
			'value' => $found ? __( 'Malware found', 'beltoft-services' ) : __( 'Nothing found', 'beltoft-services' ),
			'words' => $words,
		);
	}

	/**
	 * The staging copy of a website.
	 *
	 * @param string               $slug   Site.
	 * @param array<string, mixed> $v      SiteStaging::view().
	 * @param bool                 $ready  Whether the account takes changes.
	 * @param array<string, mixed> $limits Ceilings.
	 * @return array<string, mixed>
	 */
	public static function staging( string $slug, array $v, bool $ready, array $limits, bool $has_wp = true ): array {
		$when = static fn( int $time ): string => wp_date( 'j F Y, H:i', $time );
		$busy = array(
			'creating'   => __( 'The staging copy is being made. This can take a few minutes.', 'beltoft-services' ),
			'refreshing' => __( 'The staging copy is being refreshed from the live website. This can take a few minutes.', 'beltoft-services' ),
			'pushing'    => __( 'The staging copy is being pushed to the live website. The live website may show a maintenance message for a short time.', 'beltoft-services' ),
			'undoing'    => __( 'The last push is being undone. The live website may show a maintenance message for a short time.', 'beltoft-services' ),
			'deleting'   => __( 'The staging copy is being deleted.', 'beltoft-services' ),
		);
		$out  = array(
			'slug'      => $slug,
			'id'        => 'beltoft-staging-' . $slug,
			'wp'        => $has_wp,
			// A website without WordPress has no staging copy to make; one that has a copy (or a push to undo) can still see and delete it.
			'show'      => $has_wp || ! empty( $v['exists'] ) || ! empty( $v['busy'] ) || null !== ( $v['undo'] ?? null ),
			'ready'     => $ready,
			'busy'      => ! empty( $v['busy'] ),
			'busy_text' => (string) ( $busy[ $v['state'] ] ?? '' ),
			'exists'    => ! empty( $v['exists'] ),
			'value'     => ! empty( $v['busy'] ) ? __( 'Working...', 'beltoft-services' ) : ( ! empty( $v['exists'] ) ? __( 'Copy ready', 'beltoft-services' ) : __( 'No staging copy', 'beltoft-services' ) ),
			'host'      => '',
			'host_show' => '',
			'made'      => '',
			'refreshed' => '',
			'confirm_files' => __( 'Push the staging copy to the live website? Its files replace the files of the live website. The database and the media library of the live website stay as they are. A safety point of the live website is made first, and you can undo the push for 7 days.', 'beltoft-services' ),
			'confirm_full'  => __( 'Push the staging copy to the live website? Its files and its database replace those of the live website. Orders, customers, comments and other changes made on the live website since the copy was made are lost. A safety point of the live website is made first, and you can undo the push for 7 days.', 'beltoft-services' ),
			'taken'     => false,
			'can_create' => false,
			'undo'      => null,
		);

		if ( $out['exists'] ) {
			$out['host']      = (string) $v['host'];
			$out['host_show'] = \BeltoftServices\Domains\HostName::display( (string) $v['host'] );
			$out['made']      = $v['at'] > 0 ? $when( (int) $v['at'] ) : '';
			$out['refreshed'] = $v['refreshed'] > 0 ? $when( (int) $v['refreshed'] ) : '';
		} elseif ( $ready && $has_wp ) {
			if ( 'one' === (string) ( $limits['staging'] ?? 'one' ) && (int) ( $limits['staging_used'] ?? 0 ) > 0 ) {
				$out['taken'] = true;
			} else {
				$out['can_create'] = true;
			}
		}

		if ( $ready && null !== $v['undo'] ) {
			$full = 'full' === $v['undo']['scope'];

			$out['undo'] = array(
				/* translators: %s: date and time. */
				'text'    => sprintf( __( 'You can undo the last push until %s.', 'beltoft-services' ), $when( (int) $v['undo']['at'] + SiteStaging::KEEP ) ),
				'confirm' => $full
					? __( 'Undo the last push? The live website goes back to how it was before the push, its files and its database. Orders, comments and other changes made on the live website since the push are lost.', 'beltoft-services' )
					: __( 'Undo the last push? The files of the live website go back to how they were before the push. The database is not changed.', 'beltoft-services' ),
			);
		}

		return $out;
	}

	/**
	 * The country block of a website.
	 *
	 * @param string               $slug  Site.
	 * @param array<string, mixed> $block CountryBlock::view().
	 * @param bool                 $ready Whether the account takes changes.
	 * @return array<string, mixed>
	 */
	public static function country( string $slug, array $block, bool $ready, bool $has_wp = true ): array {
		$names   = CountryBlock::names();
		$lists   = CountryBlock::lists();
		$enabled = ! empty( $block['enabled'] );
		$scope   = (string) $block['scope'];
		$ready_lists = array();

		foreach ( $lists as $list ) {
			if ( isset( $list['help'] ) ) {
				/* translators: 1: the name of a ready list, 2: what it covers. */
				$help = sprintf( __( '%1$s: %2$s', 'beltoft-services' ), $list['label'], $list['help'] );
			} else {
				/* translators: 1: the name of a ready list, 2: its countries. */
				$help = sprintf( __( '%1$s: %2$s.', 'beltoft-services' ), $list['label'], implode( ', ', array_map( static fn( $c ) => $names[ $c ] ?? $c, $list['countries'] ) ) );
			}

			$ready_lists[] = array(
				'codes'      => implode( ' ', $list['countries'] ),
				'scope'      => isset( $list['scope'] ) ? (string) $list['scope'] : '',
				/* translators: %s: the name of a ready list of countries. */
				'label'      => sprintf( __( 'Select: %s', 'beltoft-services' ), $list['label'] ),
				'help'       => $help,
				'scope_help' => isset( $list['scope_help'] ) ? (string) $list['scope_help'] : '',
			);
		}

		$countries = array();

		foreach ( $names as $code => $name ) {
			$countries[] = array(
				'code'    => (string) $code,
				'name'    => (string) $name,
				'lower'   => function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name ),
				'checked' => in_array( $code, array_map( static fn( $c ) => (string) $c['code'], (array) $block['countries'] ), true ),
			);
		}

		return array(
			'slug'      => $slug,
			'id'        => 'beltoft-country-' . $slug,
			'wp'        => $has_wp,
			'ready'     => $ready,
			'enabled'   => $enabled,
			'scope'     => $scope,
			'value'     => $enabled ? __( 'On', 'beltoft-services' ) : __( 'Off', 'beltoft-services' ),
			'applies'   => 'login' === $scope ? __( 'Login and admin only', 'beltoft-services' ) : __( 'Whole website', 'beltoft-services' ),
			'blocked'   => implode( ', ', array_map( static fn( $c ) => (string) $c['name'], (array) $block['countries'] ) ),
			'inactive'  => false === $block['active'],
			'lists'     => $ready_lists,
			'countries' => $countries,
		);
	}

	/**
	 * What the page says about the address WordPress itself uses, and the one button that goes with it, or null when
	 * there is nothing to say (a website without a domain of its own: WordPress is on the temporary address).
	 *
	 * @param string               $slug  Site.
	 * @param array<string, mixed> $site  Site from HostingActions::sites().
	 * @param bool                 $ready Whether the account takes changes.
	 * @return array{line: string, button: array{host: string, confirm: string, text: string}|null}|null
	 */
	public static function wordpress( string $slug, array $site, bool $ready ): ?array {
		$wp      = (array) $site['wp'];
		$main    = (string) $site['main'];
		$preview = (string) $site['preview'];
		$show    = static fn( string $host ): string => \BeltoftServices\Domains\HostName::display( $host );
		$own     = array_values( array_filter( $site['domains'], static fn( $d ) => 'preview' !== $d['kind'] ) );
		$button  = null;
		$line    = '';

		if ( array() === $own && '' === $wp['moving'] && '' === $wp['note'] ) {
			return null;
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
			return null;
		}

		$do = null;

		if ( $ready && null !== $button ) {
			$do = array(
				'host'    => $button[0],
				'confirm' => $button[1],
				/* translators: %s: address. */
				'text'    => sprintf( __( 'Make %s the WordPress address', 'beltoft-services' ), $show( $button[0] ) ),
			);
		}

		if ( $ready && null === $button && '' === $wp['moving'] && '' !== $wp['prev'] && $wp['prev'] !== $wp['address'] ) {
			$do = array(
				'host'    => (string) $wp['prev'],
				/* translators: %s: address. */
				'confirm' => sprintf( __( 'Move WordPress back to %s? A copy of the database is made first, and put back if the change fails.', 'beltoft-services' ), $show( (string) $wp['prev'] ) ),
				/* translators: %s: address. */
				'text'    => sprintf( __( 'Go back to %s', 'beltoft-services' ), $show( (string) $wp['prev'] ) ),
			);
		}

		return array(
			'slug'   => $slug,
			'line'   => $line,
			'button' => $do,
		);
	}

	/**
	 * The control of a with-and-without-www pair, or null until both names point to the hosting.
	 *
	 * @param string               $slug       Site.
	 * @param array<string, mixed> $pair       base, www, main, ready.
	 * @param bool                 $ready      Whether the account takes changes.
	 * @param string               $wp_address The address WordPress uses.
	 * @return array<string, mixed>|null
	 */
	public static function pair( string $slug, array $pair, bool $ready, string $wp_address, bool $has_wp = true ): ?array {
		if ( ! $pair['ready'] ) {
			return null;
		}

		$follows = $has_wp && in_array( $wp_address, array( (string) $pair['base'], (string) $pair['www'] ), true );
		$text    = ! $has_wp ? __( 'The other address redirects to the main address.', 'beltoft-services' ) : ( $follows ? __( 'The other address redirects to the main address. WordPress moves to the main address too.', 'beltoft-services' ) : __( 'The other address redirects to the main address. This does not change the address set in WordPress.', 'beltoft-services' ) );

		if ( '' === (string) $pair['main'] ) {
			$text = __( 'Both addresses show the website. Choose a main address to redirect the other one.', 'beltoft-services' ) . ' ' . $text;
		}

		$hosts = array();

		foreach ( array( (string) $pair['base'], (string) $pair['www'] ) as $host ) {
			$hosts[] = array(
				'host'    => $host,
				'display' => \BeltoftServices\Domains\HostName::display( $host ),
				'checked' => $host === (string) $pair['main'],
			);
		}

		return array(
			'name'  => 'beltoft-main-' . $slug . '-' . preg_replace( '/[^a-z0-9]/', '-', (string) $pair['base'] ),
			'text'  => $text,
			'hosts' => $hosts,
			'ready' => $ready,
		);
	}

	/**
	 * The "Move a website here" panel, or null when the shop has no page for the request.
	 *
	 * @return array{url: string, text: string}|null
	 */
	public static function move(): ?array {
		$url = trim( (string) Settings::get( 'hosting_move_url' ) );
		$url = '' === $url || 0 === strpos( $url, '//' ) ? '' : esc_url_raw( 0 === strpos( $url, '/' ) ? home_url( $url ) : $url );

		/**
		 * The address of the "Request a move" button on the hosting account page. Return '' for no panel.
		 *
		 * @param string $url The setting, as a full address, or ''.
		 */
		$url = (string) apply_filters( 'beltoft_services_hosting_move_url', $url );

		if ( '' === $url ) {
			return null;
		}

		return array(
			'url'  => $url,
			'text' => (bool) Settings::get( 'hosting_move_free' ) ? __( 'Have a website somewhere else? We move it here for you, free of charge.', 'beltoft-services' ) : __( 'Have a website somewhere else? We can move it here for you.', 'beltoft-services' ),
		);
	}

	/**
	 * The Files (SFTP) panel, or null when the node gives no host.
	 *
	 * @param array<string, mixed> $files HostingActions::files().
	 * @param bool                 $ready Whether the account takes changes.
	 * @return array<string, mixed>|null
	 */
	public static function files( array $files, bool $ready ): ?array {
		if ( '' === (string) $files['host'] || (int) $files['port'] < 1 ) {
			return null;
		}

		$keys = array();

		foreach ( $files['keys'] as $key ) {
			$keys[] = array(
				'name'        => trim( $key['type'] . ' ' . $key['comment'] ),
				'fingerprint' => (string) $key['fingerprint'],
			);
		}

		return array(
			'ready'    => $ready,
			'conn'     => array(
				__( 'Host', 'beltoft-services' )      => (string) $files['host'],
				__( 'Port', 'beltoft-services' )      => (string) $files['port'],
				__( 'User name', 'beltoft-services' ) => (string) $files['user'],
			),
			'note'     => ! empty( $files['ssh'] ) ? __( 'SFTP, and SSH with WP-CLI. The user name is the same for all your websites.', 'beltoft-services' ) : __( 'SFTP only. The user name is the same for all your websites.', 'beltoft-services' ),
			'password' => ! empty( $files['password_set'] ) ? __( 'A password is set. It is not shown again.', 'beltoft-services' ) : __( 'No password is set yet.', 'beltoft-services' ),
			'keys'     => $keys,
		);
	}

	/**
	 * The Email panel: three states (Email lite exists, can be set up on the account's own domain, or waits for a domain).
	 *
	 * @param Resource $r     Hosting resource.
	 * @param bool     $ready Whether the account takes changes.
	 * @return array<string, mixed>
	 */
	public static function email( Resource $r, bool $ready ): array {
		$mail = SiteMail::child_of( $r );
		$out  = array( 'ready' => $ready, 'packs' => null );

		if ( $mail ) {
			$mail_domain = \BeltoftServices\Domains\HostName::display( (string) $mail->get( \BeltoftServices\Mail\MailDomain::META_DOMAIN ) );
			$area        = ServiceArea::get( \BeltoftServices\Account\Email::class );
			$url         = $area->url( $mail->license() );
			$dns_url     = $area->url( $mail->license(), 'dns' );
			$proof       = \BeltoftServices\Mail\MailField::waiting_for_proof( $mail );
			$help        = '';

			if ( '' !== $dns_url && 'external' === (string) $mail->get( 'mail_mode' ) && ( \BeltoftServices\Mail\MailDns::waiting( $mail ) || $proof ) ) {
				$help = sprintf(
					$proof
						/* translators: %s: the mail domain. */
						? __( 'We are waiting for the proof that %s is yours. Add the record in the DNS records guide.', 'beltoft-services' )
						/* translators: %s: the mail domain. */
						: __( 'Mailboxes can be made now, but mail does not arrive until the DNS records for %s are added.', 'beltoft-services' ),
					$mail_domain
				);
			}

			$out = $out + array(
				'state'      => 'lite',
				'line'       => sprintf( '%s: %s', HostingAccount::included_label( '', $mail ), $mail_domain ),
				/* translators: %s: the mail domain. */
				'stays'      => sprintf( __( 'Email lite stays on %s. It does not move when the main domain changes.', 'beltoft-services' ), $mail_domain ),
				'help'       => $help,
				'held'       => '' !== $help && \BeltoftServices\Mail\MailHold::held( $mail ),
				'dns_url'    => $dns_url,
				'manage_url' => $url,
			);

			if ( $ready ) {
				$out['packs'] = self::packs( $r, $mail );
			}

			return $out;
		}

		$own = LiteMail::ready_domain( $r );

		if ( null === $own ) {
			$sites   = Sites::for_resource( $r->id() );
			$first   = $sites ? (string) ( reset( $sites )['slug'] ?? '' ) : '';
			$waiting = '';

			foreach ( SiteDomains::for_resource( $r->id() ) as $d ) {
				if ( 'preview' !== $d['kind'] && 'active' !== $d['state'] && '' === $waiting ) {
					$waiting = \BeltoftServices\Domains\HostName::display( Addons::registrable( (string) $d['host'] ) ?: (string) $d['host'] );
				}
			}

			return $out + array(
				'state'        => 'waiting',
				/* translators: %s: domain name. */
				'first_step'   => '' !== $waiting ? sprintf( __( '%s is connected and waits for its DNS. Follow the DNS steps under the website.', 'beltoft-services' ), $waiting ) : __( 'Connect your own domain to a website.', 'beltoft-services' ),
				'connect_link' => '' === $waiting && '' !== $first && $ready ? self::link( 'websites', 'beltoft-site-' . $first . '-connect' ) : '',
			);
		}

		$own_row = SiteDomains::find( (string) $own );

		return $out + array(
			'state'    => 'setup',
			/* translators: %s: the account's own domain. */
			'line'     => sprintf( __( 'Set up Email lite on %s so your websites can send mail. Email is included with your plan; without it a website cannot send mail.', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( (string) $own ) ),
			/* translators: %s: the account's own domain. */
			'external' => null !== $own_row && 'external' === $own_row['kind'] ? sprintf( __( 'After you press the button, you get a list of DNS records to add where the DNS of %s is managed. Three are needed: MX for receiving, SPF and DKIM for sending. Three more are recommended.', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( (string) $own ) ) : '',
		);
	}

	/**
	 * Extra storage per Email lite mailbox, or null when there is nothing to show.
	 *
	 * @param Resource $r    Hosting resource.
	 * @param Resource $mail Email lite resource.
	 * @return array<string, mixed>|null
	 */
	public static function packs( Resource $r, Resource $mail ): ?array {
		$quote = Addons::quote( $r, Addons::KIND_PACK );
		$boxes = \BeltoftServices\Mail\Mailboxes::all( $mail );

		if ( null === $quote || ! is_array( $boxes ) ) {
			return null;
		}

		if ( array() === $boxes ) {
			return array( 'intro' => '', 'rows' => array() );
		}

		$packs = Addons::packs( $r );
		$rows  = array();

		foreach ( $boxes as $box ) {
			$email  = (string) $box['email'];
			$n      = (int) ( $packs[ $email ] ?? 0 );
			$ending = count( array_filter( Addons::lines( $r, Addons::KIND_PACK, $email ), static fn( $i ) => 'yes' === (string) $i->get_meta( Addons::I_ENDING ) ) );

			$rows[] = array(
				'email'  => $email,
				/* translators: 1: mailbox address, 2: size in GB. */
				'name'   => sprintf( __( '%1$s: %2$s GB', 'beltoft-services' ), \BeltoftServices\Domains\HostName::display( $email ), (string) round( (int) $box['quota_mb'] / 1024, 1 ) ),
				/* translators: %d: packs that end at the next renewal. */
				'ending' => $ending > 0 ? sprintf( _n( '(%d pack ends at the next renewal)', '(%d packs end at the next renewal)', $ending, 'beltoft-services' ), $ending ) : '',
				'add'    => $n < Addons::MAX_PACKS,
				'remove' => $n - $ending > 0,
			);
		}

		return array(
			/* translators: 1: price per month and pack, 2: most packs on one mailbox, 3: "incl. VAT" or "excl. VAT". */
			'intro'   => sprintf( __( 'Add 5 GB to a mailbox for %1$s per month (%3$s), up to %2$d packs for each mailbox.', 'beltoft-services' ), Addons::shown( (float) $quote['unit'], (string) ( $quote['currency'] ?? '' ) ), Addons::MAX_PACKS, \BeltoftServices\Billing\TaxNote::label() ),
			'confirm' => Addons::confirm_text( Addons::KIND_PACK, $quote ),
			'cents'   => (int) round( $quote['now'] * 100 ),
			'rows'    => $rows,
		);
	}

	/**
	 * The backups panel, or null when they are off.
	 *
	 * @param Resource                         $r     Hosting resource.
	 * @param array<int, array<string, mixed>> $sites Sites.
	 * @param bool                             $ready Whether the account takes changes.
	 * @return array<string, mixed>|null
	 */
	public static function backups( Resource $r, array $sites, bool $ready ): ?array {
		if ( ! HostingBackups::enabled( $r ) ) {
			return null;
		}

		$safety = HostingRestore::safety( $r );
		$busy   = ( new HostingBackups( $r ) )->active() || HostingRestore::active( $r );
		$rows   = array();

		$stored = HostingBackups::stored( $r );

		foreach ( $stored as $b ) {
			$date = wp_date( 'j F Y, H:i', (int) $b['ctime'] );
			/* translators: %s: date and time of the backup. */
			$ask  = sprintf( __( 'Delete the backup from %s? It cannot be undone and you cannot restore from it afterwards.', 'beltoft-services' ), $date );

			if ( 1 === count( $stored ) ) {
				$ask .= ' ' . __( 'This is your only backup. You will have no backup until the next one is made tonight.', 'beltoft-services' );
			}

			$rows[] = array(
				'date'    => $date,
				'kind'    => 'manual' === $b['kind'] ? __( 'Manual', 'beltoft-services' ) : __( 'Nightly', 'beltoft-services' ),
				'size'    => HostingAccount::backup_size( (int) $b['size'] ),
				'volid'   => (string) $b['volid'],
				'confirm' => $ask,
			);
		}

		$choices = array();

		foreach ( $sites as $site ) {
			$choices[] = array( 'slug' => (string) $site['slug'], 'name' => HostingAccount::site_name( $site ) );
		}

		return array(
			'busy'    => $busy,
			'acts'    => $ready && ! $busy,
			'rows'    => $rows,
			'one'     => 1 === count( $sites ) ? (string) reset( $sites )['slug'] : '',
			'choices' => $choices,
			/* translators: %s: time the safety copy is kept until. */
			'safety'  => null === $safety ? '' : sprintf( __( 'A safety copy from before your last restore is kept until %s.', 'beltoft-services' ), wp_date( 'j F Y, H:i', (int) $safety['expires'] ) ),
		);
	}
}
