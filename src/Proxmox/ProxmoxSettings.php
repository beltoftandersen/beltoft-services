<?php
/**
 * Settings → Services → Proxmox.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The flat Proxmox settings, added to the generic settings page through its filters. The list of
 * clusters has its own tab (ClustersScreen), because Settings takes flat fields only.
 */
final class ProxmoxSettings {

	public const TAB = 'proxmox';

	/**
	 * Allowed range (min, max) of each number.
	 *
	 * @var array<string, array{0:int,1:int}>
	 */
	private const RANGES = array(
		'shutdown_timeout' => array( 10, 3600 ),
		'task_wait'        => array( 1, 120 ),
		'abuse_cpu'        => array( 1, 100 ),
		'abuse_hours'      => array( 1, 168 ),
		'monitor_disk_percent' => array( 50, 99 ),
		'monitor_disk_urgent_percent' => array( 60, 100 ),
		'monitor_cpu_percent' => array( 50, 100 ),
		'monitor_cpu_hours' => array( 1, 24 ),
		'monitor_cooldown_days' => array( 1, 90 ),
		'host_reserve_gb'  => array( 0, 1024 ),
		'disk_reserve_gb' => array( 0, 10000 ),
		'disk_promise_percent' => array( 1, 100 ),
		'disk_max_extra' => array( 1, 30 ),
		'snapshot_free_slots' => array( 0, 50 ),
		'snapshot_free_days' => array( 1, 365 ),
		'snapshot_paid_days' => array( 1, 365 ),
		'snapshot_max_slots' => array( 1, 50 ),
		'snapshot_reserve_gb' => array( 0, 10000 ),
	);

	/**
	 * Hooks the tab, the fields and the extra bounds check.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_settings_tabs', array( __CLASS__, 'add_tab' ) );
		add_filter( 'beltoft_services_settings_sections', array( __CLASS__, 'add_section' ) );
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'add_fields' ) );
		// After Settings::sanitize() (priority 10, added by register_setting): the bounds and the site tag format.
		add_action( 'admin_notices', array( __CLASS__, 'untagged_notice' ) );
		add_filter( 'sanitize_option_' . Settings::OPTION, array( __CLASS__, 'sanitize' ), 20 );
	}

	/**
	 * Ids of the live clusters that have no site tag: nothing is done to them until it is set.
	 *
	 * @return string[]
	 */
	public static function untagged_clusters(): array {
		$ids = array();

		foreach ( Clusters::all() as $id => $cluster ) {
			if ( Clients::untagged( $cluster, Clients::live_allowed() ) ) {
				$ids[] = (string) $id;
			}
		}

		return $ids;
	}

	/**
	 * A notice on every admin page while a live cluster has no site tag.
	 */
	public static function untagged_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::print_untagged( self::untagged_clusters() );
	}

	/**
	 * Prints the notice for these clusters (nothing for none).
	 *
	 * @param string[] $ids Cluster ids.
	 */
	public static function print_untagged( array $ids ): void {
		if ( ! $ids ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: cluster ids. */
					__( 'No site tag is set, so nothing is done to the live Proxmox cluster(s) %s: new servers wait and no machine is changed.', 'beltoft-services' ),
					implode( ', ', $ids )
				)
			),
			esc_url( admin_url( 'options-general.php?page=' . Settings::PAGE . '&tab=' . self::TAB ) ),
			esc_html__( 'Set the site tag', 'beltoft-services' )
		);
	}

	/**
	 * Adds the tabs.
	 *
	 * @param mixed $tabs Tabs so far.
	 * @return mixed
	 */
	public static function add_tab( $tabs ) {
		if ( is_array( $tabs ) ) {
			$tabs[ self::TAB ]             = __( 'Proxmox', 'beltoft-services' );
			$tabs[ ClustersScreen::TAB ] = __( 'Proxmox clusters', 'beltoft-services' );
			$tabs[ IpPoolScreen::TAB ]   = __( 'IP pool', 'beltoft-services' );
		}

		return $tabs;
	}

	/**
	 * Adds the section title.
	 *
	 * @param mixed $sections Titles so far.
	 * @return mixed
	 */
	public static function add_section( $sections ) {
		if ( is_array( $sections ) ) {
			$sections[ self::TAB ] = __( 'Proxmox virtual machines', 'beltoft-services' );
		}

		return $sections;
	}

	/**
	 * The default site tag: the host of this site.
	 */
	public static function default_site_tag(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) ? $host : '';
	}

	/**
	 * Whether a value can sit in the ownership line `beltoft-services:<site_tag>:<uuid>`.
	 *
	 * @param string $tag Candidate.
	 */
	private static function valid_tag( string $tag ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $tag );
	}

	/**
	 * The site tag the admin set, or '' when none was set (or the stored one is not usable).
	 * A cluster can only go live with one: the host name is a default for fake clusters.
	 */
	public static function explicit_site_tag(): string {
		$tag = (string) Settings::get( 'site_tag' );

		return self::valid_tag( $tag ) ? $tag : '';
	}

	/**
	 * The site tag used in VM descriptions: the one that was set, otherwise the host name (fake
	 * clusters only). Never empty and never holds a colon or whitespace, whatever is stored.
	 */
	public static function site_tag(): string {
		$tag = self::explicit_site_tag();

		return '' !== $tag ? $tag : self::default_site_tag();
	}

	/**
	 * Adds the fields.
	 *
	 * @param mixed $fields Fields so far.
	 * @return mixed
	 */
	public static function add_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$base = array(
			'tab'     => self::TAB,
			'section' => self::TAB,
		);

		$fields['site_tag']         = $base + array(
			'type'    => 'text',
			'default' => '',
			'label'   => __( 'Site tag', 'beltoft-services' ),
			'desc'    => __( 'Written into the description of every virtual machine this site creates. Only machines that carry this tag are ever changed or deleted. Letters, numbers, dots, dashes and underscores. It cannot be changed while machines exist. A cluster cannot go live until this is set; while it is empty the host name of this site is used, for test clusters only.', 'beltoft-services' ),
		);
		$fields['shutdown_timeout'] = $base + array(
			'type'    => 'number',
			'default' => 120,
			'min'     => self::RANGES['shutdown_timeout'][0],
			'label'   => __( 'Shutdown timeout in seconds', 'beltoft-services' ),
			'desc'    => __( 'How long a pause waits for a clean shutdown before the machine is stopped.', 'beltoft-services' ),
		);
		$fields['task_wait']        = $base + array(
			'type'    => 'number',
			'default' => 25,
			'min'     => self::RANGES['task_wait'][0],
			'label'   => __( 'Seconds to wait for one Proxmox task', 'beltoft-services' ),
			'desc'    => __( 'A step waits this long, then keeps the task and checks it again later. Keep it below the PHP time limit of this server.', 'beltoft-services' ),
		);
		$fields['host_reserve_gb']  = $base + array(
			'type'    => 'number',
			'default' => 4,
			'min'     => self::RANGES['host_reserve_gb'][0],
			'label'   => __( 'Memory kept free on each node, in GB', 'beltoft-services' ),
			'desc'    => __( 'A node counts as full when the memory promised to the virtual machines of this site, plus this reserve, reaches the memory of the node. It leaves room for Proxmox itself and for machines this site did not create.', 'beltoft-services' ),
		);
		$fields['disk_reserve_gb'] = $base + array( 'type' => 'number', 'default' => 200, 'min' => 0, 'label' => __( 'Storage kept free on each node (GB)', 'beltoft-services' ) );
		$fields['disk_promise_percent'] = $base + array( 'type' => 'number', 'default' => 100, 'min' => 1, 'label' => __( 'Maximum promised storage (%)', 'beltoft-services' ) );
		$fields['disk_max_extra'] = $base + array( 'type' => 'number', 'default' => 4, 'min' => 1, 'label' => __( 'Maximum extra disks per server', 'beltoft-services' ) );
		$fields['snapshots_enabled'] = $base + array(
			'type'    => 'checkbox',
			'default' => 0,
			'label'   => __( 'Customer snapshots', 'beltoft-services' ),
			'desc'    => __( 'Let customers take snapshots of their server and roll back to them. Only servers whose disks are all qcow2 get them. Leave it off until the token has the snapshot privileges and the checks on the cluster have passed.', 'beltoft-services' ),
		);
		$fields['snapshot_free_slots'] = $base + array( 'type' => 'number', 'default' => 1, 'min' => self::RANGES['snapshot_free_slots'][0], 'label' => __( 'Free snapshots per server', 'beltoft-services' ) );
		$fields['snapshot_free_days'] = $base + array( 'type' => 'number', 'default' => 7, 'min' => self::RANGES['snapshot_free_days'][0], 'label' => __( 'Days a free snapshot is kept', 'beltoft-services' ) );
		$fields['snapshot_paid_days'] = $base + array( 'type' => 'number', 'default' => 30, 'min' => self::RANGES['snapshot_paid_days'][0], 'label' => __( 'Days a snapshot is kept with paid slots', 'beltoft-services' ) );
		$fields['snapshot_max_slots'] = $base + array( 'type' => 'number', 'default' => 10, 'min' => self::RANGES['snapshot_max_slots'][0], 'label' => __( 'Most snapshots per server', 'beltoft-services' ), 'desc' => __( 'Free and paid slots together never go above this.', 'beltoft-services' ) );
		$fields['snapshot_expired_email'] = $base + array(
			'type'    => 'checkbox',
			'default' => 0,
			'label'   => __( 'Email when a snapshot expires', 'beltoft-services' ),
			'desc'    => __( 'Tell the customer when a snapshot reached the end of its keeping time and was deleted. The emails about a removed disk and fewer slots are always sent.', 'beltoft-services' ),
		);
		$fields['snapshot_reserve_gb'] = $base + array(
			'type'    => 'number',
			'default' => 0,
			'min'     => self::RANGES['snapshot_reserve_gb'][0],
			'label'   => __( 'Storage kept free for snapshots (GB)', 'beltoft-services' ),
			'desc'    => __( 'A snapshot is refused when the storage of the server has less than this free. 0 uses the storage kept free for extra disks.', 'beltoft-services' ),
		);
		$fields['abuse_mode']       = $base + array(
			'type'    => 'select',
			'default' => 'report',
			'options' => array(
				'off'    => __( 'Off', 'beltoft-services' ),
				'report' => __( 'Report only: alert, change nothing', 'beltoft-services' ),
				'pause'  => __( 'Pause the service', 'beltoft-services' ),
			),
			'label'   => __( 'Sustained high CPU', 'beltoft-services' ),
		);
		$fields['abuse_cpu']        = $base + array(
			'type'    => 'number',
			'default' => 90,
			'min'     => self::RANGES['abuse_cpu'][0],
			'label'   => __( 'High CPU means above this percent', 'beltoft-services' ),
		);
		$fields['abuse_hours']      = $base + array(
			'type'    => 'number',
			'default' => 2,
			'min'     => self::RANGES['abuse_hours'][0],
			'label'   => __( 'for at least this many hours', 'beltoft-services' ),
			'desc'    => __( 'One reading covers about an hour, so the hours are counted over several hourly readings: with 1 hour the first alert comes with the second reading, with 3 hours after about three.', 'beltoft-services' ),
		);
		$fields['monitor_alerts'] = $base + array(
			'type' => 'select',
			'default' => 'on',
			'options' => array( 'off' => __( 'Off', 'beltoft-services' ), 'on' => __( 'On', 'beltoft-services' ) ),
			'label' => __( 'Customer monitoring emails', 'beltoft-services' ),
			'desc' => __( 'Email the customer when their server stays down unexpectedly, its disk is nearly full or its CPU stays high. Customers can turn the usage emails off per server; the server down email is always sent.', 'beltoft-services' ),
		);
		$fields['monitor_disk_percent'] = $base + array(
			'type' => 'number',
			'default' => 90,
			'min' => self::RANGES['monitor_disk_percent'][0],
			'label' => __( 'Disk alert threshold, percent', 'beltoft-services' ),
		);
		$fields['monitor_disk_urgent_percent'] = $base + array(
			'type' => 'number',
			'default' => 97,
			'min' => self::RANGES['monitor_disk_urgent_percent'][0],
			'label' => __( 'Urgent disk alert threshold, percent', 'beltoft-services' ),
			'desc' => __( 'A second, stronger email when the disk is almost completely full. It is sent once per episode and re-arms five points lower.', 'beltoft-services' ),
		);
		$fields['monitor_cpu_percent'] = $base + array(
			'type' => 'number',
			'default' => 90,
			'min' => self::RANGES['monitor_cpu_percent'][0],
			'label' => __( 'CPU alert threshold, percent', 'beltoft-services' ),
		);
		$fields['monitor_cpu_hours'] = $base + array(
			'type' => 'number',
			'default' => 3,
			'min' => self::RANGES['monitor_cpu_hours'][0],
			'label' => __( 'CPU alert after this many hours', 'beltoft-services' ),
			'desc' => __( 'Counted over several hourly readings, like the abuse check. Re-arms when the CPU falls below 70 percent.', 'beltoft-services' ),
		);
		$fields['monitor_cooldown_days'] = $base + array(
			'type' => 'number',
			'default' => 7,
			'min' => self::RANGES['monitor_cooldown_days'][0],
			'label' => __( 'Days between two CPU emails for one server', 'beltoft-services' ),
		);

		return $fields;
	}

	/**
	 * Clamps the numbers, checks the mode and the site tag, for the keys that are present.
	 *
	 * @param mixed $value The option value after Settings::sanitize().
	 * @return mixed
	 */
	public static function sanitize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( self::RANGES as $key => $range ) {
			if ( array_key_exists( $key, $value ) ) {
				$value[ $key ] = min( $range[1], max( $range[0], (int) $value[ $key ] ) );
			}
		}

		if ( array_key_exists( 'abuse_mode', $value ) && ! in_array( $value['abuse_mode'], array( 'off', 'report', 'pause' ), true ) ) {
			$value['abuse_mode'] = 'report';
		}
		if ( array_key_exists( 'monitor_alerts', $value ) && ! in_array( $value['monitor_alerts'], array( 'off', 'on' ), true ) ) {
			$value['monitor_alerts'] = 'off';
		}

		// Not usable means not set: it is never replaced by a guess.
		if ( array_key_exists( 'site_tag', $value ) && ! self::valid_tag( (string) $value['site_tag'] ) ) {
			$value['site_tag'] = '';
		}

		// The tag is written into every VM description: changing it would make every existing VM "not ours".
		if ( array_key_exists( 'site_tag', $value ) && (string) $value['site_tag'] !== (string) Settings::get( 'site_tag' ) && (string) $value['site_tag'] !== self::site_tag() && VmRecords::any() ) {
			$value['site_tag'] = (string) Settings::get( 'site_tag' );

			add_settings_error(
				Settings::OPTION,
				'beltoft_services_site_tag_locked',
				__( 'The site tag was not changed: virtual machines already carry it in their description, and a different tag would make this site refuse to manage every one of them. Change it only after every service has been deleted.', 'beltoft-services' )
			);
		}

		return $value;
	}
}
