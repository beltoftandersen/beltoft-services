<?php
/**
 * Wiring.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks every part of the plugin into WordPress, once its dependencies are present.
 */
final class Plugin {

	/**
	 * The REST namespace of this plugin's routes (the core pages that name a route use it; Api\Routes::NAMESPACE is the same value).
	 */
	public const REST_NAMESPACE = 'beltoft-services/v1';

	/**
	 * The oldest Beltoft Licenses this release is tested with (0.8.6 closed a license leak, 0.8.8 hides child licenses from the account tabs).
	 */
	public const MIN_LICENSES = '0.8.8';

	/**
	 * The oldest WooCommerce this release is tested with (the header's WC requires at least).
	 */
	public const MIN_WOOCOMMERCE = '9.0';

	/**
	 * Whether the last boot registered the plugin's parts.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Runs on plugins_loaded, priority 20.
	 */
	public static function boot(): void {
		self::$booted = false;

		if ( ! self::requirements_met() ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_missing' ) );
			return;
		}

		remove_action( 'admin_notices', array( __CLASS__, 'notice_missing' ) );

		// Ordering: schemas first (later registrations may read their tables), then Settings (the option and
		// settings API the feature settings screens extend), then Product and Jobs (core), then the drivers'
		// settings and connections, then the feature parts. Features add to core filters and actions; core
		// never names a feature.
		Proxmox\Vm\Schema::maybe_upgrade();
		Hosting\Schema::maybe_upgrade();
		Domains\DnsSchema::maybe_upgrade();
		Oauth\Schema::maybe_upgrade();

		Settings::register();

		Product::register();
		LineNames::register();

		Jobs::register();
		Background::register();

		// Driver settings screens add their tabs to Settings::register()'s filters, so they come after it.
		Http\Connections::register();
		Http\ConnectionsScreen::register();
		Proxmox\Pve\ProxmoxSettings::register();
		Proxmox\Pve\ResetStepUp::register();
		Hosting\HostingSettings::register();
		Hosting\NodesScreen::register();
		Hosting\Stock::register();
		Hosting\HostingCapacity::register();
		Hosting\SiteField::register();
		Hosting\SiteNewDomain::register();
		Hosting\CartRule::register();
		Hosting\Languages::register();
		Hosting\HostingSwitch::register();
		Hosting\Addons::register();
		Hosting\HostingAccount::register();
		Children::register();
		Domains\DomainSettings::register();
		Ssl\SslSettings::register();
		Ssl\Keys::register();
		Ssl\Panels::register();
		Ssl\SslField::register();
		Ssl\SslRenewals::register();
		Ssl\SslRoutes::register();
		Ssl\SslJobs::register();
		Storage\StorageSwitch::register();
		Storage\StorageRoutes::register();
		Storage\StoragePanel::register();
		Licences\KeyCatalog::register();
		Licences\KeyOrder::register();
		Licences\KeyField::register();
		Licences\KeyMove::register();
		Mail\MailSettings::register();
		Mail\MailPacks::register();
		Mail\MailSetup::register();
		Bricks\DocsElements::register();
		Mail\MailDns::register();
		Mail\MailHold::register();
		Mail\EmailValues::register();
		Hosting\EmailValues::register();
		Ssl\EmailValues::register();
		Mail\MailJobs::register();
		Mail\MailRoutes::register();
		Hosting\HostingRoutes::register();
		Hosting\HostingUsage::register();
		Hosting\HostingGuest::register();
		Hosting\HostingBackups::register();
		Hosting\HostingRestore::register();
		Hosting\NodeHealth::register();
		Hosting\ParkedEdge::register();
		Hosting\HostingRefresh::register();
		Hosting\DomainSweep::register();
		Hosting\SiteFacts::register();
		Hosting\SiteCron::register();
		Hosting\Actions\Steps::register();
		Hosting\MalwareScan::register();
		Hosting\RestoreTest::register();
		Mail\MailField::register();
		Mail\MailSwitch::register();
		Mail\MailAccess::register();
		Mail\Mailboxes::register();
		Mail\MailDomains::register();
		Mail\MailWake::register();
		add_action( 'beltoft_services_account_assets', array( Mail\MailCard::class, 'early' ), 20, 2 );
		add_action( 'beltoft_services_account_assets', array( Hosting\HostingAccount::class, 'style' ), 20, 0 );
		add_filter( 'woocommerce_account_menu_items', array( Account\Email::class, 'menu' ), 100 );
		add_filter( 'woocommerce_account_menu_items', array( Account\Ssl::class, 'menu' ), 101 );
		add_filter( 'woocommerce_account_menu_items', array( Account\Licences::class, 'menu' ), 102 );
		add_filter( 'woocommerce_account_menu_items', array( Account\Storage::class, 'menu' ), 103 );
		add_filter( 'woocommerce_account_menu_items', array( Account\Dedicated::class, 'menu' ), 104 );
		Domains\Search::register();
		Bricks\Integration::register();
		Domains\Cart::register();
		Domains\PendingLines::register();
		Domains\TldRules::register();
		Domains\DkAgreement::register();
		Domains\OrderDomain::register();
		Domains\OrderWhois::register();
		Domains\Meta::register();
		Domains\Renewals::register();
		Domains\Actions::register();
		Domains\AuthCodeRoute::register();
		Domains\TransferDns::register();
		Domains\TransferFix::register();
		Domains\DnsAdopt::register();
		StepUp::register();
		Domains\DnsClaimJobs::register();
		Domains\DnsReleaseJob::register();
		Domains\DnsCancel::register();
		ThankYou::register();
		Domains\DnsClaimRoutes::register();
		Domains\DnsClaimPanel::register();
		Domains\Panels::register();
		Domains\RecordRoutes::register();
		Domains\Sync::register();
		Domains\Unpaid::register();
		Domains\Security::register();
		Domains\EmailDetails::register();
		Proxmox\Pve\ClustersScreen::register();
		Proxmox\Vm\ServerProgress::register();
		Apps\AppProgress::register();
		Status::register();
		Checkout::register();
		IntroCoupon::register();
		RenewalReminder::register();
		Billing\ExistingFlow::register();
		Billing\SubscriptionNames::register();
		Billing\LineOptions::register();
		Billing\SwitchCurrency::register();
		Billing\CostGuardCart::register();
		BillingRequirements::register();
		Account\Tabs::register();
		Account\Slugs::register();
		Account\Servers::register();
		Account\ServiceArea::register();
		Account\DomainDanger::register();
		Account\ServerPicker::register();
		Account\DbPicker::register();
		Account\Overview::register();
		Account\Billing::register();
		Account\PageHead::register();
		Account\UsageView::register();

		Lifecycle::register();
		Retention::register();
		Cli\Commands::register();

		Account\Panel::register();
		Api\Routes::register();
		Api\Services::register();
		Api\Activity::register();
		Api\OpenApi::register();
		Api\Reference::register();
		Api\Tokens::register();
		Oauth\Server::register();
		Mcp\Access::register();
		Mcp\DedicatedTools::register();
		Mcp\Endpoint::register();
		Account\ApiTokens::register();
		Account\Flash::register();
		// Feature hooks into the core's extension points (retention, state_changed, parking, failure mail).
		Proxmox\Vm\CoreHooks::register();
		Proxmox\Vm\RevealRoute::register();
		Proxmox\Console\ConsoleRoute::register();
		Proxmox\Console\ConsoleAuth::register();
		Proxmox\Console\ConsoleTab::register();
		Apps\AppReveal::register();
		Apps\AppInstall::register();
		Apps\AppDomainRelease::register();
		Apps\AppOwnDomain::register();
		Apps\AppWww::register();
		Apps\AppStatus::register();
		Monitoring\MetricsRoute::register();
		Proxmox\Network\VmGuest::register();
		Proxmox\Network\Flows::register();
		Monitoring\LiveUsage::register();
		Proxmox\Network\FirewallRoute::register();
		Proxmox\Network\FirewallTab::register();
		Proxmox\Network\NetworkTab::register();
		Proxmox\Vm\Switches::register();
		Proxmox\Vm\Upgrades::register();
		Proxmox\Vm\Credentials::register();
		Proxmox\Vm\ServerName::register();
		Proxmox\Vm\SshKeyField::register();
		Proxmox\Vm\OsField::register();
		Proxmox\Db\DbTermsField::register();
		Proxmox\Vm\Tiles::register();
		Apps\AppField::register();
		Apps\AppLicence::register();
		Apps\AppLicenceCart::register();
		Apps\PleskLicence::register();
		Apps\AppReinstallPaid::register();
		Apps\AppReinstall::register();
		Apps\AppTab::register();
		Proxmox\Backup\BackupField::register();
		Proxmox\Db\DbIpField::register();
		Proxmox\Db\DbDiskField::register();
		Proxmox\Disk\DiskMeta::register();
		// The cascade runs after the server's own clean-up (CoreHooks, Credentials), as the three old cascades did.
		Proxmox\Addon\AddonCascade::register();
		Proxmox\Addon\AddonCompanion::register();
		Domains\CartPair::register();
		Proxmox\Disk\DiskCascade::register();
		add_action( Jobs::SWEEP, array( Proxmox\Pve\DiskCapacity::class, 'sweep' ) );
		Proxmox\Disk\DiskCart::register();
		Proxmox\Snapshot\SnapshotMeta::register();
		Proxmox\Snapshot\SnapshotCart::register();
		Proxmox\Disk\DiskField::register();
		Proxmox\Vm\Actions::register();
		Proxmox\Db\DbActions::register();
		Proxmox\Db\DbRoutes::register();
		Proxmox\Db\DbAccessRoute::register();
		Proxmox\Db\DbNeedsServer::register();
		Proxmox\Db\DbNetGuard::register();
		Proxmox\Vm\Panels::register();
		Proxmox\Vm\EmailDetails::register();
		Proxmox\Vm\ProxmoxJobs::register();
		Proxmox\Vm\Stock::register();
		Proxmox\Network\IpPoolScreen::register();
		Admin\Panel::register();
		Admin\Screen::register();
		Admin\LicenceServiceColumn::register();
		Emails\Emails::register();
		Usage\UsageDisk::register();
		Usage\CoreHooks::register();
		Usage\Meter::register();
		Usage\Counted\Views::register();
		Usage\Threshold::register();
		Usage\Account::register();
		Usage\Cart::register();
		Usage\Trusted::register();
		Usage\Summary::register();
		Usage\Breakdown::register();
		Usage\BillingField::register();
		Usage\Catalog::register();
		Domains\PriceTags::register();
		Usage\Renewal::register();
		Usage\Emails::register();
		Usage\Minimum::register();
		Usage\InvoiceView::register();
		Card\Card::register();
		Card\DeleteGuard::register();
		GiftCredit\GiftCredit::register();
		Storage\StorageSettings::register();
		ManualServer\ManualServerSettings::register();

		// Later tasks register their parts here.
		self::$booted = true;
	}

	/**
	 * Whether the plugin's parts were registered by the last boot.
	 */
	public static function booted(): bool {
		return self::$booted;
	}

	/**
	 * Loads the plugin's translations (init, so the front end and the admin both have them).
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'beltoft-services', false, dirname( plugin_basename( BELTOFT_SERVICES_FILE ) ) . '/languages' );
	}

	/**
	 * Whether every dependency is present.
	 */
	public static function requirements_met(): bool {
		return array() === self::missing();
	}

	/**
	 * Human names of the missing dependencies, including a database server that is too old.
	 *
	 * @return string[]
	 */
	public static function missing(): array {
		$missing = array();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$missing[] = 'WooCommerce';
		} elseif ( defined( 'WC_VERSION' ) && version_compare( (string) WC_VERSION, self::MIN_WOOCOMMERCE, '<' ) ) {
			$missing[] = 'WooCommerce ' . self::MIN_WOOCOMMERCE . ' or newer';
		}

		if ( ! class_exists( 'WC_Subscriptions' ) && ! function_exists( 'wcs_get_subscription' ) ) {
			$missing[] = 'WooCommerce Subscriptions';
		}

		if ( ! class_exists( \BeltoftLicenses\Licenses::class ) || ! method_exists( \BeltoftLicenses\Licenses::class, 'add_resource' ) || ! defined( 'BELTOFT_LICENSES_VERSION' ) || version_compare( (string) BELTOFT_LICENSES_VERSION, self::MIN_LICENSES, '<' ) ) {
			$missing[] = 'Beltoft Licenses ' . self::MIN_LICENSES . ' or newer';
		}

		global $wpdb;

		/**
		 * Filters the database server version string the requirement check reads
		 * (wpdb::db_server_info(), e.g. "8.0.36" or "5.5.5-10.11.14-MariaDB").
		 *
		 * @param string $info Server version string.
		 */
		$database = self::unsupported_database( (string) apply_filters( 'beltoft_services_database_server_info', (string) $wpdb->db_server_info() ) );

		if ( null !== $database ) {
			$missing[] = $database;
		}

		/**
		 * Filters the list of missing dependencies.
		 *
		 * @param string[] $missing Human names of missing dependencies.
		 */
		$missing = apply_filters( 'beltoft_services_dependencies', $missing );

		return array_values( is_array( $missing ) ? $missing : array() );
	}

	/**
	 * Why the database server cannot run the plugin, or null when it can. The resource lock
	 * is taken again inside a job that holds it, which needs re-entrant GET_LOCK: MySQL 5.7.5
	 * or MariaDB 10.0.2 and newer. An unreadable version string is not refused.
	 *
	 * @param string $info Server version string (wpdb::db_server_info()).
	 */
	public static function unsupported_database( string $info ): ?string {
		$mariadb = false !== stripos( $info, 'mariadb' );

		// MariaDB may report itself behind a "5.5.5-" prefix for old MySQL clients.
		if ( $mariadb ) {
			$info = (string) preg_replace( '/^5\.5\.5-/', '', $info );
		}

		if ( ! preg_match( '/^(\d+\.\d+(?:\.\d+)?)/', $info, $match ) ) {
			return null;
		}

		$version = $match[1];
		$minimum = $mariadb ? '10.0.2' : '5.7.5';

		if ( version_compare( $version, $minimum, '>=' ) ) {
			return null;
		}

		return sprintf( 'MySQL 5.7.5 or MariaDB 10.0.2 or newer, for re-entrant named locks (this server runs %s %s)', $mariadb ? 'MariaDB' : 'MySQL', $version );
	}

	/**
	 * Tells admins what is missing.
	 */
	public static function notice_missing(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma separated plugin names. */
					__( 'Beltoft Services is inactive until these are active: %s.', 'beltoft-services' ),
					implode( ', ', self::missing() )
				)
			)
		);
	}
}
