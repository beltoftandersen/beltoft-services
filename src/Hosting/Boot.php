<?php
/**
 * Boots the hosting feature from the stages Plugin::boot() announces.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin::boot() fires beltoft_services_boot at each place a feature adds its hooks; this answers each stage with the calls
 * hosting needs there, in the order they had when Plugin::boot() named them. One method per stage: a part that moves
 * out of this class takes its line with it.
 */
final class Boot {

	/**
	 * Listens to the boot stages and to deactivation.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_boot', array( __CLASS__, 'stage' ) );
		add_action( 'beltoft_services_deactivate', array( Addons::class, 'unschedule' ) );
	}

	/**
	 * Runs the calls of one stage.
	 *
	 * @param string $stage Stage name.
	 */
	public static function stage( $stage ): void {
		if ( ! is_string( $stage ) ) {
			return;
		}

		$method = 'stage_' . $stage;

		if ( method_exists( __CLASS__, $method ) ) {
			self::$method();
		}
	}

	/**
	 * Table upgrades, before any registration reads them.
	 */
	private static function stage_schema(): void {
		Schema::maybe_upgrade();
	}

	/**
	 * Settings screens, steps and cart parts.
	 */
	private static function stage_features(): void {
		HostingSettings::register();
		NodesScreen::register();
		Stock::register();
		HostingCapacity::register();
		SiteField::register();
		SiteNewDomain::register();
		SiteDomainStep::register();
		CartRule::register();
		Languages::register();
		HostingSwitch::register();
		Addons::register();
		HostingAccount::register();
		EmailRegistry::register();
	}

	/**
	 * Values for the service emails.
	 */
	private static function stage_email_values(): void {
		EmailValues::register();
	}

	/**
	 * REST routes, jobs and sweeps.
	 */
	private static function stage_routes(): void {
		HostingRoutes::register();
		HostingUsage::register();
		HostingGuest::register();
		HostingBackups::register();
		HostingRestore::register();
		NodeHealth::register();
		ParkedEdge::register();
		HostingRefresh::register();
		DomainSweep::register();
		SiteFacts::register();
		SiteCron::register();
		Actions\Steps::register();
		MalwareScan::register();
		RestoreTest::register();
	}

	/**
	 * Account page styles.
	 */
	private static function stage_account_assets(): void {
		add_action( 'beltoft_services_account_assets', array( HostingAccount::class, 'style' ), 20, 0 );
	}

	/**
	 * Sentences of the activity feed.
	 */
	private static function stage_activity(): void {
		ActivitySentence::register();
	}

	/**
	 * My Account areas.
	 */
	private static function stage_areas(): void {
		Hosting::register();
	}

	/**
	 * Where an order's service goes.
	 */
	private static function stage_order_target(): void {
		OrderTarget::register();
	}

	/**
	 * WP-CLI commands, after Cli\Commands is registered.
	 */
	private static function stage_cli(): void {
		HostingCommand::register();
	}

	/**
	 * MCP tools.
	 */
	private static function stage_mcp(): void {
		McpTools::register();
	}
}
