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
 * alert that could not be sent, and the main IP addresses. Filtered by driver, state and failed-only; searched
 * by address, name, customer, domain and number (Screen::search_ids()).
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
			'ip'       => __( 'IP', 'beltoft-services' ),
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
		$page  = Screen::page( Screen::filters(), ( $this->get_pagenum() - 1 ) * $per, $per );
		$total = $page['total'];

		$this->problems = Alerts::problems();
		$this->items    = $page['items'];

		// One read of the page's customers, not one per row.
		$user_ids = array();
		foreach ( $this->items as $item ) {
			$user_ids[] = (int) ( $item->license()['user_id'] ?? 0 );
		}
		$user_ids = array_values( array_filter( array_unique( $user_ids ) ) );
		if ( $user_ids ) {
			cache_users( $user_ids );
		}

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
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $state ), selected( $filters['state'], $state, false ), esc_html( Resource::state_label( $state ) ) );
		}
		echo '</select>';

		printf( '<label><input type="checkbox" name="failed" value="1" %1$s> %2$s</label> ', checked( $filters['failed'], true, false ), esc_html__( 'Failed only', 'beltoft-services' ) );
		printf( '<label><input type="checkbox" name="deleted" value="1" %1$s> %2$s</label> ', checked( $filters['deleted'], true, false ), esc_html__( 'Include deleted', 'beltoft-services' ) );
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
				$html = sprintf(
					'<a href="%1$s"><code>%2$s</code></a>',
					esc_url( \BeltoftLicenses\Admin\Screen::url( array( 'license' => (int) ( $license['id'] ?? 0 ) ) ) ),
					esc_html( (string) ( $license['license_key'] ?? '#' . ( $license['id'] ?? '' ) ) )
				);

				if ( '' !== $item->ended_at() ) {
					$when = mysql2date( get_option( 'date_format' ), get_date_from_gmt( $item->ended_at() ) );
					/* translators: %s: the date the service was deleted. */
					$html .= '<br><strong class="bs-deleted">' . esc_html( sprintf( __( 'Deleted %s', 'beltoft-services' ), $when ) ) . '</strong>';
				}

				return $html;
			case 'customer':
				return $this->customer( $license );
			case 'ip':
				return $this->addresses( $item );
			case 'driver':
				$driver = Registry::get( $item->get( 'driver' ) );
				return esc_html( $driver ? $driver->label() : $item->get( 'driver' ) );
			case 'state':
				return esc_html( Resource::state_label( (string) $item->get( 'state' ) ) );
			case 'desired':
				return esc_html( $item->get( 'desired' ) );
			case 'attempts':
				return esc_html( (string) $item->get( 'attempts' ) );
			case 'error':
				$html = esc_html( $item->get( 'last_error' ) );

				if ( '' === $html && '' !== (string) $item->get( 'waiting' ) ) {
					/* translators: %s: what the service waits for. */
					$html = esc_html( sprintf( __( 'Waiting: %s', 'beltoft-services' ), (string) $item->get( 'waiting' ) ) );
				}

				if ( isset( $this->problems[ $item->id() ] ) ) {
					$html .= '<br><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__( 'The failure alert could not be sent.', 'beltoft-services' );
				}

				return $html;
		}

		return '';
	}

	/**
	 * Name and email of the customer, the name linked to the user edit screen.
	 *
	 * @param array<string, mixed> $license License row.
	 */
	private function customer( array $license ): string {
		$email = esc_html( (string) ( $license['email'] ?? '' ) );
		$user  = (int) ( $license['user_id'] ?? 0 ) > 0 ? get_userdata( (int) $license['user_id'] ) : false;

		if ( ! $user ) {
			return $email;
		}

		$name = trim( (string) get_user_meta( $user->ID, 'billing_first_name', true ) . ' ' . (string) get_user_meta( $user->ID, 'billing_last_name', true ) );
		$name = '' !== $name ? $name : $user->display_name;

		return sprintf( '<a href="%1$s">%2$s</a><br>%3$s', esc_url( get_edit_user_link( $user->ID ) ), esc_html( $name ), $email );
	}

	/**
	 * The IP addresses a service holds (ip, ip6 in its meta), each once.
	 *
	 * @param Resource $r Resource.
	 */
	private function addresses( Resource $r ): string {
		$found = array();

		foreach ( array( 'ip', 'ip6' ) as $key ) {
			$value = $r->get( $key );

			if ( is_string( $value ) && '' !== $value ) {
				$found[ $value ] = '<code>' . esc_html( $value ) . '</code>';
			}
		}

		return implode( '<br>', $found );
	}
}
