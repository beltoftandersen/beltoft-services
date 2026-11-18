<?php
/**
 * The controls on the page of a dedicated server in My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Proxmox\Vm\Tiles;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the empty frame of each section; assets/dedicated.js fills it from GET resources/<id>/dedicated (so the page itself makes no call to the
 * supplier) and sends the changes to the routes of ControlRoutes. The reinstall form, with the family tiles of ImageCatalog, is printed here. Nothing
 * a customer reads names the supplier.
 */
final class ControlsPanel {

	/**
	 * Prints the sections for a delivered server of the signed-in owner.
	 *
	 * @param Resource $r Resource.
	 */
	public static function render( Resource $r ): void {
		if ( ServerControls::number( $r ) < 1 ) {
			return;
		}

		self::assets();
		echo '<div class="beltoft-services-dedicated" data-dedicated data-resource="' . esc_attr( (string) $r->id() ) . '" data-name="' . esc_attr( (string) $r->get( 'server_name' ) ) . '">';
		echo '<p class="bs-note" data-ded-loading>' . esc_html__( 'Loading the controls...', 'beltoft-services' ) . '</p>';

		self::section( 'power', __( 'Restart and power', 'beltoft-services' ), __( 'A restart is a software restart. The hardware reset and the power button need a code we email to you. Switch on sends the signal that starts a server that is off.', 'beltoft-services' ) );
		self::section( 'rescue', __( 'Rescue system', 'beltoft-services' ), __( 'A small system that starts from the network instead of your disks, to repair or copy data. With your SSH key you log in as root with the key; without one we show you a password after a code we email to you.', 'beltoft-services' ) );
		self::section( 'rdns', __( 'Reverse DNS', 'beltoft-services' ), __( 'The host name that an address resolves to. You can set it for the IPv4 address and for addresses inside the IPv6 network of the server. The name should point back to the address.', 'beltoft-services' ) );
		self::section( 'firewall', __( 'Firewall', 'beltoft-services' ), __( 'Rules for incoming traffic. A rule set that does not accept SSH (TCP port 22) from anywhere can lock you out: you then need a code we email to you.', 'beltoft-services' ) );

		self::reinstall( $r );
		echo '</div>';
	}

	/**
	 * @param string $key   Key.
	 * @param string $title Title.
	 * @param string $note  A line under the title.
	 */
	private static function section( string $key, string $title, string $note ): void {
		echo '<section class="beltoft-services-dedicated__section" data-ded-section="' . esc_attr( $key ) . '" hidden>';
		echo '<h3>' . esc_html( $title ) . '</h3><p class="bs-note">' . esc_html( $note ) . '</p><div data-ded-body></div></section>';
	}

	/**
	 * The reinstall form.
	 *
	 * @param Resource $r Resource.
	 */
	private static function reinstall( Resource $r ): void {
		$names = DedicatedReinstall::images();
		$state = ServerControls::reinstall_state( $r );

		echo '<section class="beltoft-services-dedicated__section" data-ded-section="reinstall" hidden><h3>' . esc_html__( 'Reinstall', 'beltoft-services' ) . '</h3>';

		if ( 'running' === $state ) {
			echo '<p class="bs-note">' . esc_html__( 'A reinstall is running. It takes about 15 minutes.', 'beltoft-services' ) . '</p></section>';

			return;
		}

		if ( 'failed' === $state ) {
			echo '<p class="bs-note">' . esc_html__( 'The last reinstall did not finish. We have been told. You can try again.', 'beltoft-services' ) . '</p>';
		}

		if ( ! $names ) {
			echo '<p class="bs-note">' . esc_html__( 'A reinstall is not available for this server right now.', 'beltoft-services' ) . '</p></section>';

			return;
		}

		echo '<p class="bs-note">' . esc_html__( 'This erases all disks of the server and installs the system you choose. It cannot be undone. Take a copy of your data first.', 'beltoft-services' ) . '</p>';
		echo '<p class="bs-note">' . esc_html__( 'When it is done you log in as root. If you gave us an SSH key at checkout, the key is installed and there is no password. Otherwise a new root password is shown here after a code we email to you.', 'beltoft-services' ) . '</p>';
		echo '<form data-ded-reinstall>';
		Tiles::print_group(
			array(
				'kind'   => 'os',
				'id'     => 'beltoft-services-ded-image-' . $r->id(),
				'name'   => 'image',
				'legend' => __( 'System', 'beltoft-services' ),
				'items'  => Tiles::family_items( ImageCatalog::entries( $names ), '' ),
			)
		);
		echo '<p><label>' . esc_html__( 'Type the name of the server to confirm', 'beltoft-services' ) . '<br><input type="text" name="confirm" autocomplete="off" size="30" placeholder="' . esc_attr( (string) $r->get( 'server_name' ) ) . '"></label></p>';
		echo '<p><button type="submit" class="button bs-btn bs-btn--danger">' . esc_html__( 'Erase and reinstall', 'beltoft-services' ) . '</button></p>';
		echo '</form></section>';
	}

	/** The script of the controls and the tiles. */
	private static function assets(): void {
		Tiles::enqueue();
		wp_enqueue_style( 'beltoft-services-dedicated', plugins_url( 'assets/dedicated.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/dedicated.css' ) );
		wp_enqueue_script( 'beltoft-services-dedicated', plugins_url( 'assets/dedicated.js', BELTOFT_SERVICES_FILE ), array( \BeltoftServices\Account\Panel::HANDLE, \BeltoftServices\Status::HANDLE, \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/dedicated.js' ), true );
		wp_localize_script(
			'beltoft-services-dedicated',
			'beltoftServicesDedicated',
			array(
				'strings' => array(
					'failed'       => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'limit'        => __( 'Too many requests. Please wait and try again later.', 'beltoft-services' ),
					'working'      => __( 'Working...', 'beltoft-services' ),
					'done'         => __( 'Done.', 'beltoft-services' ),
					'switchOn'     => __( 'Switch on', 'beltoft-services' ),
					'confirmReset' => __( 'Do this now? Anything not saved on the server is lost.', 'beltoft-services' ),
					'rescueBoot'   => __( 'Start the rescue system and restart', 'beltoft-services' ),
					'rescueOn'     => __( 'Switch on for the next start', 'beltoft-services' ),
					'rescueOff'    => __( 'Switch off', 'beltoft-services' ),
					'rescueIs'     => __( 'The rescue system is on. It starts the next time the server starts.', 'beltoft-services' ),
					'rescueIsNot'  => __( 'The rescue system is off.', 'beltoft-services' ),
					'showPassword' => __( 'Show rescue password', 'beltoft-services' ),
					'passwordNote' => __( 'This is the root password of the rescue system. It works until the rescue system is switched off.', 'beltoft-services' ),
					'address'      => __( 'Address', 'beltoft-services' ),
					'name'         => __( 'Host name', 'beltoft-services' ),
					'save'         => __( 'Save', 'beltoft-services' ),
					'remove'       => __( 'Remove', 'beltoft-services' ),
					'ipv6Hint'     => __( 'An address inside', 'beltoft-services' ),
					'fwStatus'     => __( 'Firewall', 'beltoft-services' ),
					'fwActive'     => __( 'Active', 'beltoft-services' ),
					'fwOff'        => __( 'Off', 'beltoft-services' ),
					'fwAdd'        => __( 'Add a rule', 'beltoft-services' ),
					'fwSave'       => __( 'Apply the firewall', 'beltoft-services' ),
					'fwName'       => __( 'Name', 'beltoft-services' ),
					'fwVersion'    => __( 'IP version', 'beltoft-services' ),
					'fwSource'     => __( 'From address', 'beltoft-services' ),
					'fwPort'       => __( 'To port', 'beltoft-services' ),
					'fwProto'      => __( 'Protocol', 'beltoft-services' ),
					'fwAction'     => __( 'Action', 'beltoft-services' ),
					'accept'       => __( 'Accept', 'beltoft-services' ),
					'discard'      => __( 'Discard', 'beltoft-services' ),
					'any'          => __( 'Any', 'beltoft-services' ),
					'notAvailable' => __( 'Not available for this server.', 'beltoft-services' ),
					'reinstalling' => __( 'The reinstall has started. It takes about 15 minutes.', 'beltoft-services' ),
					'chooseSystem' => __( 'Choose a system.', 'beltoft-services' ),
				),
			)
		);
	}
}
