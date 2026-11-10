<?php
/**
 * The one gate that decides whether a live client may be built.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

use BeltoftServices\Proxmox\ProxmoxSettings;

defined( 'ABSPATH' ) || exit;

/**
 * The live/fake decision shared by the Proxmox and Hosting client factories (Domains and Mail use
 * constant_true() only). It builds nothing: a factory asks it, and stays the only place a client is built.
 * A live client needs the settings to say "live" AND the wp-config.php constant to be true; the two
 * factories that need an ownership tag also refuse while no explicit site tag is set. No filter can switch it on.
 */
final class LiveGate {

	/**
	 * Name of the wp-config.php constant that allows live calls.
	 *
	 * @var string
	 */
	private string $constant;

	/**
	 * Prefix of the id in the site tag alert.
	 *
	 * @var string
	 */
	private string $alert_prefix;

	/**
	 * Retry text with one %s for the id.
	 *
	 * @var string
	 */
	private string $retry_text;

	/**
	 * Constructor.
	 *
	 * @param string $constant     Name of the wp-config.php constant that allows live calls.
	 * @param string $alert_prefix Put before the id in the site tag alert.
	 * @param string $retry_text   Admin text with one %s for the id, returned by gate().
	 */
	public function __construct( string $constant, string $alert_prefix, string $retry_text ) {
		$this->constant     = $constant;
		$this->alert_prefix = $alert_prefix;
		$this->retry_text   = $retry_text;
	}

	/**
	 * Whether a constant is defined and exactly true.
	 *
	 * @param string $name Constant name.
	 */
	public static function constant_true( string $name ): bool {
		return defined( $name ) && true === constant( $name );
	}

	/**
	 * Whether wp-config.php allows live calls.
	 */
	public function live_allowed(): bool {
		return self::constant_true( $this->constant );
	}

	/**
	 * Is this live (and allowed to be) but without an explicit site tag? Its ownership tag would be a
	 * guess, so nothing may be done to it.
	 *
	 * @param array<string, mixed> $settings Cluster or node settings.
	 * @param bool                 $allowed  Whether wp-config.php allows live calls.
	 */
	public function untagged( array $settings, bool $allowed ): bool {
		return $allowed && 'live' === ( $settings['client'] ?? 'fake' ) && '' === ProxmoxSettings::explicit_site_tag();
	}

	/**
	 * The retry text when the settings are live without a site tag (and the admin is alerted, once a
	 * day), otherwise null.
	 *
	 * @param string               $id       Cluster or node id.
	 * @param array<string, mixed> $settings Cluster or node settings.
	 * @param bool                 $allowed  Whether wp-config.php allows live calls.
	 */
	public function gate( string $id, array $settings, bool $allowed ): ?string {
		if ( ! $this->untagged( $settings, $allowed ) ) {
			return null;
		}

		\BeltoftServices\Alerts::site_tag( $this->alert_prefix . $id );

		return sprintf( $this->retry_text, $id );
	}

	/**
	 * Which client the settings get: live only when they say so AND the allowance is true.
	 *
	 * @param array<string, mixed> $settings Cluster or node settings.
	 * @param bool                 $allowed  Whether wp-config.php allows live calls.
	 * @return string 'live' or 'fake'.
	 */
	public function kind( array $settings, bool $allowed ): string {
		return $allowed && 'live' === ( $settings['client'] ?? 'fake' ) ? 'live' : 'fake';
	}
}
