<?php
/**
 * WooCommerce → Services list.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Admin;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The resources: license, customer, driver, state, desired state, attempts, last error and an
 * alert that could not be sent. Filtered by driver, state and failed-only.
 */
final class ListTable extends \WP_List_Table {

	/**
	 * Unsent alerts, keyed by resource id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $problems = array();

	/**
	 * Set up.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'service',
				'plural'   => 'services',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'license'  => __( 'License', 'beltoft-services' ),
			'customer' => __( 'Customer', 'beltoft-services' ),
			'driver'   => __( 'Driver', 'beltoft-services' ),
			'state'    => __( 'State', 'beltoft-services' ),
			'desired'  => __( 'Desired', 'beltoft-services' ),
			'attempts' => __( 'Attempts', 'beltoft-services' ),
			'error'    => __( 'Last error', 'beltoft-services' ),
		);
	}

	/**
	 * Loads the current page.
	 */
	public function prepare_items(): void {
		$per   = $this->get_items_per_page( Screen::PER_PAGE_OPTION, 20 );
		$all   = Screen::resources( Screen::filters() );
		$total = count( $all );

		$this->problems        = Alerts::problems();
		$this->items           = array_slice( $all, ( $this->get_pagenum() - 1 ) * $per, $per );
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per,
			)
		);
	}

	/**
	 * Driver, state and failed-only filters.
	 *
	 * @param string $which top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$filters = Screen::filters();

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="bs-driver">' . esc_html__( 'Driver', 'beltoft-services' ) . '</label><select id="bs-driver" name="driver"><option value="">' . esc_html__( 'All drivers', 'beltoft-services' ) . '</option>';
		foreach ( Registry::all() as $id => $driver ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $id ), selected( $filters['driver'], $id, false ), esc_html( $driver->label() ) );
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="bs-state">' . esc_html__( 'State', 'beltoft-services' ) . '</label><select id="bs-state" name="state"><option value="">' . esc_html__( 'All states', 'beltoft-services' ) . '</option>';
		foreach ( Resource::STATES as $state ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $state ), selected( $filters['state'], $state, false ) );
		}
		echo '</select>';

		printf( '<label><input type="checkbox" name="failed" value="1" %1$s> %2$s</label> ', checked( $filters['failed'], true, false ), esc_html__( 'Failed only', 'beltoft-services' ) );
		submit_button( __( 'Filter', 'beltoft-services' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Empty list.
	 */
	public function no_items(): void {
		esc_html_e( 'No services found.', 'beltoft-services' );
	}

	/**
	 * Cells.
	 *
	 * @param mixed  $item        Resource.
	 * @param string $column_name Column.
	 */
	protected function column_default( $item, $column_name ) {
		if ( ! $item instanceof Resource ) {
			return '';
		}

		$license = $item->license();

		switch ( $column_name ) {
			case 'license':
				return sprintf(
					'<a href="%1$s"><code>%2$s</code></a>',
					esc_url( \BeltoftLicenses\Admin\Screen::url( array( 'license' => (int) ( $license['id'] ?? 0 ) ) ) ),
					esc_html( (string) ( $license['license_key'] ?? '#' . ( $license['id'] ?? '' ) ) )
				);
			case 'customer':
				return esc_html( (string) ( $license['email'] ?? '' ) );
			case 'driver':
				$driver = Registry::get( $item->get( 'driver' ) );
				return esc_html( $driver ? $driver->label() : $item->get( 'driver' ) );
			case 'state':
				return esc_html( $item->get( 'state' ) );
			case 'desired':
				return esc_html( $item->get( 'desired' ) );
			case 'attempts':
				return esc_html( (string) $item->get( 'attempts' ) );
			case 'error':
				$html = esc_html( $item->get( 'last_error' ) );

				if ( isset( $this->problems[ $item->id() ] ) ) {
					$html .= '<br><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__( 'The failure alert could not be sent.', 'beltoft-services' );
				}

				return $html;
		}

		return '';
	}
}
