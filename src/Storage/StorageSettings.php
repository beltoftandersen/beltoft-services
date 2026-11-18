<?php
/**
 * Settings, Services, Backup storage.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Crypto;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The tab, the fields and the readers of them. Everything a site decides is a setting here, never code: the provider API
 * address and token, the cost table, the caps, the locations and the host name shown to customers. This file and the other
 * admin-only texts may name the storage provider; nothing a customer reads does.
 */
final class StorageSettings {
	public const TAB = 'storage';

	/** Default retention of a lapsed box, days (owner decided 2026-11-17: 14). */
	public const RETENTION_DEFAULT = 14;

	/** Hooks. */
	public static function register(): void {
		add_filter( 'beltoft_services_drivers', array( __CLASS__, 'add_drivers' ), 8 );
		add_filter( 'beltoft_services_settings_tabs', array( __CLASS__, 'tabs' ) );
		add_filter( 'beltoft_services_settings_sections', array( __CLASS__, 'sections' ) );
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'fields' ) );
		add_filter( 'beltoft_services_setting_bad_lines', array( __CLASS__, 'bad_lines' ), 10, 3 );
		add_filter( 'beltoft_services_cost_guard_drivers', array( __CLASS__, 'guarded_driver' ) );
		add_filter( 'beltoft_services_default_retention', array( __CLASS__, 'retention' ), 10, 2 );
		add_action( 'beltoft_services_settings_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		OrderLine::register();
	}

	/**
	 * Adds the driver to the registry.
	 *
	 * @param mixed $drivers Drivers keyed by id.
	 * @return mixed
	 */
	public static function add_drivers( $drivers ) {
		if ( is_array( $drivers ) && ! isset( $drivers[ StorageDriver::ID ] ) ) {
			$drivers[ StorageDriver::ID ] = new StorageDriver();
		}

		return $drivers;
	}

	/**
	 * The cart refuses a storage line in a currency without a guard rate, like a domain.
	 *
	 * @param mixed $ids Driver ids.
	 * @return mixed
	 */
	public static function guarded_driver( $ids ) {
		return is_array( $ids ) ? array_merge( $ids, array( StorageDriver::ID ) ) : $ids;
	}

	/**
	 * The retention of a storage product whose own field is empty.
	 *
	 * @param mixed $days       Days so far.
	 * @param mixed $product_id Product or variation.
	 * @return mixed
	 */
	public static function retention( $days, $product_id = 0 ) {
		$driver = \BeltoftServices\Product::driver_for( (int) $product_id );

		if ( ! $driver || StorageDriver::ID !== $driver->id() ) {
			return $days;
		}

		$set = Settings::get( 'storage_retention_days' );

		return is_numeric( $set ) ? max( 0, (int) $set ) : self::RETENTION_DEFAULT;
	}

	/**
	 * Is selling backup storage switched on?
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'storage_enabled' );
	}

	/**
	 * The provider API address, '' when unset.
	 */
	public static function api_url(): string {
		return trim( (string) Settings::get( 'storage_api_url' ) );
	}

	/**
	 * The decrypted API token, '' when unset or unreadable. Keep it out of logs, events and mail.
	 */
	public static function api_token(): string {
		$stored = (string) Settings::get( 'storage_api_token' );

		return '' === $stored ? '' : (string) Crypto::decrypt( $stored );
	}

	/**
	 * The cost table: box type => monthly cost in EUR excluding VAT. A type that is not listed (or listed with 0) has no known cost.
	 *
	 * @return array<string, float>
	 */
	public static function costs(): array {
		$out = array();

		foreach ( self::lines( (string) Settings::get( 'storage_cost_table' ) ) as $line ) {
			if ( 1 === preg_match( '/^([a-z0-9_-]{1,40})\s*=\s*(\d+(?:\.\d{1,4})?)$/iD', $line, $m ) && (float) $m[2] > 0 ) {
				$out[ strtolower( $m[1] ) ] = (float) $m[2];
			}
		}

		return $out;
	}

	/**
	 * The monthly cost of a type, or null when it is not in the table.
	 *
	 * @param string $type Box type.
	 */
	public static function cost( string $type ): ?float {
		return self::costs()[ strtolower( $type ) ] ?? null;
	}

	/**
	 * The country name a customer reads for the location (Germany only, owner decided 2026-11-17). A setting, so the text
	 * stays true if the location code is changed.
	 */
	public static function country(): string {
		$name = trim( (string) Settings::get( 'storage_country' ) );

		return '' !== $name ? $name : 'Germany';
	}

	/**
	 * The one location code: the setting, default the German location. There is no choice at checkout.
	 */
	public static function default_location(): string {
		$code = strtolower( trim( (string) Settings::get( 'storage_default_location' ) ) );

		return 1 === preg_match( '/^[a-z0-9_-]{1,20}$/D', $code ) ? $code : 'fsn1';
	}

	/**
	 * Which writer makes the alias record: `cloudflare` (a record in a Cloudflare zone, the default) or `own` (our own name servers).
	 */
	public static function alias_writer(): string {
		return 'own' === (string) Settings::get( 'storage_alias_writer' ) ? 'own' : 'cloudflare';
	}

	/**
	 * The DNS API token for the alias zone, decrypted, '' when unset. Keep it out of logs, events and mail.
	 */
	public static function cf_token(): string {
		$stored = (string) Settings::get( 'storage_cf_token' );

		return '' === $stored ? '' : (string) Crypto::decrypt( $stored );
	}

	/**
	 * The zone id of the alias zone at the DNS provider, '' when unset.
	 */
	public static function cf_zone_id(): string {
		return strtolower( trim( (string) Settings::get( 'storage_cf_zone_id' ) ) );
	}

	/**
	 * Is the host name alias switched on?
	 */
	public static function alias_enabled(): bool {
		return (bool) Settings::get( 'storage_alias_enabled' ) && '' !== self::alias_zone() && '' !== self::alias_pattern();
	}

	/**
	 * The zone of the aliases (it must be on our own name servers), lower case, '' when unset.
	 */
	public static function alias_zone(): string {
		$zone = strtolower( trim( (string) Settings::get( 'storage_alias_zone' ), ". \t" ) );

		return 1 === preg_match( '/^[a-z0-9]([a-z0-9.-]{0,200}[a-z0-9])?$/D', $zone ) ? $zone : '';
	}

	/**
	 * The host name pattern: {n} is the number of the box, {zone} the alias zone. '' when it is not usable.
	 */
	public static function alias_pattern(): string {
		$pattern = strtolower( trim( (string) Settings::get( 'storage_alias_pattern' ) ) );

		return 1 === preg_match( '/^[a-z0-9{}.-]{3,100}$/D', $pattern ) && str_contains( $pattern, '{n}' ) && str_contains( $pattern, '{zone}' ) ? $pattern : '';
	}

	/**
	 * The alias host name of a box (for example backup12.example.com), '' when the alias is off or the pattern is unusable.
	 *
	 * @param int $number The box number (the resource id).
	 */
	public static function alias_name( int $number ): string {
		if ( ! self::alias_enabled() ) {
			return '';
		}

		$name = str_replace( array( '{n}', '{zone}' ), array( (string) $number, self::alias_zone() ), self::alias_pattern() );

		return str_ends_with( $name, '.' . self::alias_zone() ) ? $name : '';
	}

	/**
	 * The host name shown to a customer: our alias once it is in DNS, else the provider's own host name. The filter has the last
	 * word. SMB and WebDAV over TLS check the provider's certificate name, so they are always shown the provider host (see tls_host()).
	 *
	 * @param string $alias    The alias recorded on the box, or ''.
	 * @param string $username Box user name.
	 * @param string $host     The provider's host name.
	 */
	public static function shown_host( string $alias, string $username, string $host ): string {
		$shown = '' !== $alias && self::alias_enabled() ? $alias : $host;

		/**
		 * The host name a customer is shown for a storage box.
		 *
		 * @param string $shown    The name.
		 * @param string $username Box user name.
		 * @param string $host     The provider's own host name.
		 */
		return (string) apply_filters( 'beltoft_services_storage_host', $shown, $username, $host );
	}

	/**
	 * Non empty lines of a text setting.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function lines( string $text ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', $text ) ?: array() ), static fn( $l ) => '' !== $l ) );
	}

	/**
	 * The lines of the cost table and the locations that cannot be read, for the settings screen.
	 *
	 * @param mixed  $bad  Lines so far.
	 * @param mixed  $key  Setting key.
	 * @param mixed  $text New text.
	 * @return mixed
	 */
	public static function bad_lines( $bad, $key = '', $text = '' ) {
		if ( ! is_array( $bad ) || 'storage_cost_table' !== $key ) {
			return $bad;
		}

		$pattern = '/^[a-z0-9_-]{1,40}\s*=\s*\d+(?:\.\d{1,4})?$/iD';

		foreach ( self::lines( (string) $text ) as $line ) {
			if ( 1 !== preg_match( $pattern, $line ) ) {
				$bad[] = $line;
			}
		}

		return $bad;
	}

	/**
	 * Tab.
	 *
	 * @param mixed $tabs Tabs.
	 * @return mixed
	 */
	public static function tabs( $tabs ) {
		if ( is_array( $tabs ) ) {
			$tabs[ self::TAB ] = __( 'Backup storage', 'beltoft-services' );
		}

		return $tabs;
	}

	/**
	 * Section.
	 *
	 * @param mixed $sections Sections.
	 * @return mixed
	 */
	public static function sections( $sections ) {
		if ( is_array( $sections ) ) {
			$sections[ self::TAB ] = __( 'Backup storage', 'beltoft-services' );
		}

		return $sections;
	}

	/**
	 * Fields.
	 *
	 * @param mixed $fields Fields.
	 * @return mixed
	 */
	public static function fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$base = array( 'tab' => self::TAB, 'section' => self::TAB );

		$fields['storage_enabled']          = $base + array( 'type' => 'checkbox', 'default' => 0, 'label' => __( 'Sell backup storage', 'beltoft-services' ), 'desc' => __( 'Off: nothing is created at the storage provider.', 'beltoft-services' ) );
		$fields['storage_client']           = $base + array(
			'type'    => 'select',
			'default' => 'fake',
			'options' => array(
				'fake' => __( 'Fake: nothing real is created', 'beltoft-services' ),
				'live' => __( 'Live: boxes are bought at the provider', 'beltoft-services' ),
			),
			'label'   => __( 'Storage provider connection', 'beltoft-services' ),
			'desc'    => __( 'Live needs this setting, the token below AND the constant BELTOFT_SERVICES_STORAGE_LIVE set to true in wp-config.php. Every box created live costs real money.', 'beltoft-services' ),
		);
		$fields['storage_api_url']          = $base + array( 'type' => 'url', 'default' => 'https://api.hetzner.com/v1', 'label' => __( 'Storage provider API address', 'beltoft-services' ), 'desc' => __( 'https only.', 'beltoft-services' ) );
		$fields['storage_api_token']        = $base + array( 'type' => 'secret', 'default' => '', 'label' => __( 'Storage provider API token', 'beltoft-services' ), 'desc' => __( 'Use a token of a project that holds only storage boxes. It is stored encrypted and never shown again.', 'beltoft-services' ) );
		$fields['storage_default_location'] = $base + array( 'type' => 'text', 'default' => 'fsn1', 'label' => __( 'Location code', 'beltoft-services' ), 'desc' => __( 'The one location boxes are made in (the provider code of the German location). There is no choice at checkout.', 'beltoft-services' ) );
		$fields['storage_country']          = $base + array( 'type' => 'text', 'default' => 'Germany', 'label' => __( 'Country name shown to customers', 'beltoft-services' ), 'desc' => __( 'Must match the location code above.', 'beltoft-services' ) );
		$fields['storage_cost_table']       = $base + array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Monthly cost per box type, in EUR without VAT', 'beltoft-services' ), 'desc' => __( 'One line per type: the provider type name, an equals sign and the monthly cost, for example bx11=3.20. EMPTY BY DEFAULT: a type that is not listed has an unknown cost and no box of it is ever bought. Fill it from the provider price list and re-check it before price changes.', 'beltoft-services' ) );
		$fields['storage_max_boxes']        = $base + array( 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 100000, 'label' => __( 'Most live boxes at once', 'beltoft-services' ), 'desc' => __( 'Applies to live purchases only. 0: none may be bought live.', 'beltoft-services' ) );
		$fields['storage_max_monthly_cost'] = $base + array( 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 1000000, 'label' => __( 'Highest monthly cost of all live boxes, in whole EUR', 'beltoft-services' ), 'desc' => __( 'The sum of the cost table over the live boxes. A new box or a larger type that would pass it is refused and staff are told. 0: none may be bought live.', 'beltoft-services' ) );
		$fields['storage_max_new_per_day']  = $base + array( 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 10000, 'label' => __( 'Most new boxes per day', 'beltoft-services' ), 'desc' => __( 'Applies to live purchases only. 0: none may be bought live.', 'beltoft-services' ) );
		$fields['storage_deletes_enabled']  = $base + array( 'type' => 'checkbox', 'default' => 0, 'label' => __( 'Allow deleting boxes at the provider', 'beltoft-services' ), 'desc' => __( 'Off: a deletion waits and staff are told, and the data stays. A box is also only deleted when the deletion mode under General allows it. A deleted box is gone for good.', 'beltoft-services' ) );
		$fields['storage_retention_days']   = $base + array( 'type' => 'number', 'default' => self::RETENTION_DEFAULT, 'min' => 0, 'max' => 365, 'label' => __( 'Keep a lapsed box for, in days', 'beltoft-services' ), 'desc' => __( 'Used for storage products with no retention of their own. The provider has no real suspend, so a paused box still costs money for these days. 0: never delete.', 'beltoft-services' ) );
		$fields['storage_snapshot_default'] = $base + array(
			'type'    => 'select',
			'default' => 'day',
			'options' => array(
				'off'   => __( 'No automatic snapshots', 'beltoft-services' ),
				'day'   => __( 'Every day', 'beltoft-services' ),
				'week'  => __( 'Every week', 'beltoft-services' ),
				'month' => __( 'Every month', 'beltoft-services' ),
			),
			'label'   => __( 'Automatic snapshots of a new box', 'beltoft-services' ),
		);
		$fields['storage_extra_protocols']  = $base + array( 'type' => 'checkbox', 'default' => 0, 'label' => __( 'Let customers switch on SMB and WebDAV', 'beltoft-services' ), 'desc' => __( 'Off: only the SSH based protocols (SFTP, rsync, borg, restic) are offered. SMB and WebDAV use a certificate of the provider host name, which does not match a host name alias.', 'beltoft-services' ) );
		$fields['storage_alias_enabled']   = $base + array( 'type' => 'checkbox', 'default' => 0, 'label' => __( 'Give each box a host name of our own', 'beltoft-services' ), 'desc' => __( 'OFF by default: customers see the provider host name. On: each new box gets a CNAME to the provider host in the zone below, written through the plugin DNS writer (the live name servers in production), and customers see the alias. Turn it on only when you approve the DNS change. SMB and WebDAV over TLS check the provider certificate name, so for those protocols the connection details always show the provider host, alias or not.', 'beltoft-services' ) );
		$fields['storage_alias_writer']    = $base + array(
			'type'    => 'select',
			'default' => 'cloudflare',
			'options' => array(
				'cloudflare' => __( 'A record in a Cloudflare zone', 'beltoft-services' ),
				'own'        => __( 'A record on our own name servers', 'beltoft-services' ),
			),
			'label'   => __( 'Alias record writer', 'beltoft-services' ),
			'desc'    => __( 'Cloudflare: one CNAME per box in the zone below, never proxied (SSH, rsync and borg cannot pass the proxy). Own: written through the plugin DNS writer, for a zone on our own name servers.', 'beltoft-services' ),
		);
		$fields['storage_alias_dns_client'] = $base + array(
			'type'    => 'select',
			'default' => 'fake',
			'options' => array(
				'fake' => __( 'Fake: nothing is written to DNS', 'beltoft-services' ),
				'live' => __( 'Live: write to the Cloudflare zone', 'beltoft-services' ),
			),
			'label'   => __( 'Cloudflare connection', 'beltoft-services' ),
			'desc'    => __( 'Live needs this setting, the token and zone id below AND the constant BELTOFT_SERVICES_STORAGE_DNS_LIVE set to true in wp-config.php.', 'beltoft-services' ),
		);
		$fields['storage_cf_token']        = $base + array( 'type' => 'secret', 'default' => '', 'label' => __( 'Cloudflare API token', 'beltoft-services' ), 'desc' => __( 'A scoped token with Zone, DNS, Edit on the alias zone only. Stored encrypted and never shown again.', 'beltoft-services' ) );
		$fields['storage_cf_zone_id']      = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Cloudflare zone id', 'beltoft-services' ), 'desc' => __( '32 characters, from the zone overview. The zone name is the alias zone below.', 'beltoft-services' ) );
		$fields['storage_alias_zone']      = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Alias zone', 'beltoft-services' ), 'desc' => __( 'The zone the alias names live in (the zone name at Cloudflare, or a zone on our own name servers), for example example.com.', 'beltoft-services' ) );
		$fields['storage_alias_pattern']   = $base + array( 'type' => 'text', 'default' => 'backup{n}.{zone}', 'label' => __( 'Alias host name pattern', 'beltoft-services' ), 'desc' => __( '{n} is the number of the box and {zone} the alias zone, for example backup{n}.{zone}.', 'beltoft-services' ) );

		return $fields;
	}

	/** The tab. */
	public static function render(): void {
		echo '<form action="options.php" method="post">';
		settings_fields( Settings::PAGE );
		do_settings_sections( Settings::PAGE . '-' . self::TAB );
		submit_button();
		echo '</form>';
	}
}
