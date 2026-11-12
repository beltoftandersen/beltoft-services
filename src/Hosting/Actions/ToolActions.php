<?php
/**
 * The one-time links to the file manager and the database tool of a website.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\Sites;

defined( 'ABSPATH' ) || exit;

/**
 * The file manager and the database tool run inside the customer's container, on the website's own address under
 * /.hosting/. The node agent makes a one-time address (60 seconds, one use) that signs the browser in for that tool
 * only; this class asks for it for a website of the account. The address goes to the customer's browser and is never
 * stored or logged here. Reached through the HostingActions facade.
 */
final class ToolActions {

	use Base;

	/** The tools, by the name the agent knows. */
	public const TOOLS = array( 'files', 'database' );

	/**
	 * A one-time address that opens the file manager of the website.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error url.
	 */
	public function files_link( string $slug ) {
		return $this->link( $slug, 'files' );
	}

	/**
	 * A one-time address that opens the database tool of the website.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error url.
	 */
	public function database_link( string $slug ) {
		return $this->link( $slug, 'database' );
	}

	/**
	 * One link.
	 *
	 * @param string $slug Site.
	 * @param string $tool files or database.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function link( string $slug, string $tool ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( null === Sites::find( $this->r->id(), $slug ) ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$out    = $this->call( 'site_tool_' . $tool . '_' . $slug, 'site.tool_link', array( 'vmid' => (int) $record['vmid'], 'slug' => $slug, 'tool' => $tool ) );

		if ( is_wp_error( $out ) ) {
			return $this->error( 'failed', 'files' === $tool ? __( 'The file manager could not be opened.', 'beltoft-services' ) : __( 'The database tool could not be opened.', 'beltoft-services' ) );
		}

		$url = (string) ( $out['url'] ?? '' );

		// The address must be the website's own, on https, with the reserved path of this tool.
		if ( 1 !== preg_match( '#^https://[a-z0-9.-]+/\.hosting/' . $tool . '/\?t=[A-Za-z0-9_-]+$#D', $url ) ) {
			return $this->error( 'failed', 'files' === $tool ? __( 'The file manager could not be opened.', 'beltoft-services' ) : __( 'The database tool could not be opened.', 'beltoft-services' ) );
		}

		return array( 'url' => $url );
	}
}
