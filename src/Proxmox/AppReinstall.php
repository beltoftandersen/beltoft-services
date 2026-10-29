<?php
/** The app choice when a server is reinstalled. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A server with an app is reinstalled with the same app and version (the default), another version
 * that can still be ordered, or no app. A server without an app gets no app choice here: apps are
 * chosen when a server is ordered. A version that has been retired is never offered; if the server
 * has one, the current version is preselected and the form says why. The field is posted as
 * `app_version`: `keep`, a version id, or `none`.
 */
final class AppReinstall {

	public const NONE = 'none';
	public const KEEP = 'keep';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_reinstall_fields', array( __CLASS__, 'fields' ) );
		add_filter( 'beltoft_services_reinstall_check', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'beltoft_services_reinstall_meta', array( __CLASS__, 'meta' ), 10, 3 );
	}

	/**
	 * What the form offers for a server: the choices, the preselected one and whether the server's own version is retired.
	 *
	 * @return array{app: array<string, mixed>, choices: array<string, string>, selected: string, retired: bool}|null Null when the server has no app.
	 */
	public static function options( Resource $r ): ?array {
		$id = (string) $r->get( 'app_id' );
		$def = '' === $id ? null : Apps::definition( $id );

		if ( null === $def ) {
			return null;
		}

		$own     = (string) $r->get( 'app_version' );
		$current = Apps::current( $id );
		$retired = null === Apps::version( $id, $own );
		$choices = array();

		if ( ! $retired ) {
			$choices[ self::KEEP ] = __( 'Same app and version', 'beltoft-services' );
		}

		foreach ( (array) $def['versions'] as $version ) {
			$vid  = is_array( $version ) ? (string) ( $version['id'] ?? '' ) : '';
			$full = '' === $vid || $vid === $own ? null : Apps::version( $id, $vid );

			if ( null !== $full ) {
				$choices[ $vid ] = ( $current && $current['version_id'] === $vid ? __( 'Current: ', 'beltoft-services' ) : '' ) . Apps::version_label( $full );
			}
		}

		$choices[ self::NONE ] = __( 'No app, just the operating system', 'beltoft-services' );

		return array(
			'app'      => $def,
			'choices'  => $choices,
			'selected' => $retired ? ( $current ? $current['version_id'] : self::NONE ) : self::KEEP,
			'retired'  => $retired,
		);
	}

	/**
	 * Prints the select inside the reinstall form.
	 *
	 * @param int $resource_id Resource id.
	 */
	public static function fields( int $resource_id ): void {
		$r       = Resource::find( $resource_id );
		$options = $r ? self::options( $r ) : null;

		if ( null === $options ) {
			return;
		}

		$record = VmRecords::for_resource( $resource_id );
		$os     = $record ? Apps::os_template( (array) Clusters::get( $record['cluster'] ) ) : 0;

		echo '<p class="beltoft-services-app-reinstall" data-app-reinstall data-os="' . esc_attr( (string) $os ) . '">';
		echo '<label for="beltoft-vm-app-' . (int) $resource_id . '">' . esc_html( (string) $options['app']['name'] ) . '</label>';
		echo '<select id="beltoft-vm-app-' . (int) $resource_id . '" name="app_version" data-app-reinstall-select>';

		foreach ( $options['choices'] as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, $options['selected'], false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select></p>';

		if ( $options['retired'] ) {
			echo '<p class="description">' . esc_html__( 'The version on this server can no longer be installed. The current version is selected instead.', 'beltoft-services' ) . '</p>';
		}

		echo '<p class="description">' . esc_html__( 'An app runs on Ubuntu 24.04, so the operating system stays the same while an app is selected.', 'beltoft-services' ) . '</p>';
	}

	/**
	 * The version a reinstall would install, or '' for no app; null when the choice is not valid.
	 *
	 * @param array<string, mixed> $options From options().
	 * @param mixed                $raw     Posted value.
	 */
	private static function wanted( Resource $r, array $options, $raw ): ?string {
		$raw = is_string( $raw ) && '' !== $raw ? $raw : self::KEEP;

		if ( self::NONE === $raw ) {
			return '';
		}

		if ( ! isset( $options['choices'][ $raw ] ) ) {
			return null;
		}

		return self::KEEP === $raw ? (string) $r->get( 'app_version' ) : $raw;
	}

	/**
	 * Refuses a choice that is not listed, a plan below the minimum, or an operating system the app cannot run on.
	 *
	 * @param mixed                $why  Refusal so far.
	 * @param mixed                $r    Resource.
	 * @param array<string, mixed> $args Arguments.
	 * @return mixed
	 */
	public static function check( $why, $r, $args ) {
		$options = $r instanceof Resource ? self::options( $r ) : null;

		if ( null !== $why || null === $options ) {
			return $why;
		}

		$version = self::wanted( $r, $options, $args['app_version'] ?? '' );

		if ( null === $version ) {
			return __( 'Choose one of the listed app versions.', 'beltoft-services' );
		}

		if ( '' === $version ) {
			return null;
		}

		$full    = Apps::version( (string) $r->get( 'app_id' ), $version );
		$record  = VmRecords::for_resource( $r->id() );
		$cluster = $record ? Clusters::get( $record['cluster'] ) : null;
		$plan    = (array) $r->get( 'plan' );
		$plan['disk_gb'] = Panels::disk_gb( $r );
		$refusal = null === $full ? null : Apps::plan_refusal( $full, $plan );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$os       = isset( $args['os'] ) && '' !== $args['os'] ? (int) $args['os'] : (int) $r->get( OsField::META );
		$families = null === $cluster ? array() : (array) ( $cluster['template_families'] ?? array() );
		$family   = $os > 0 ? (string) ( $families[ $os ] ?? '' ) : Apps::FAMILY;

		if ( null === $cluster || null === $full || Apps::FAMILY !== $family || null !== Apps::check( $cluster, $plan + array( 'template' => $os > 0 ? $os : Apps::os_template( $cluster ) ), (string) $r->get( 'app_id' ), $version ) ) {
			return __( 'This app needs Ubuntu 24.04 and cannot be installed here with the chosen system. Choose Ubuntu 24.04, or no app.', 'beltoft-services' );
		}

		return null;
	}

	/**
	 * The meta of a reinstall that follows the choice: a fresh install state for the app, or no app.
	 *
	 * @param mixed                $meta Meta so far.
	 * @param mixed                $r    Resource.
	 * @param array<string, mixed> $args Arguments.
	 * @return mixed
	 */
	public static function meta( $meta, $r, $args ) {
		$options = is_array( $meta ) && $r instanceof Resource ? self::options( $r ) : null;

		if ( null === $options ) {
			return $meta;
		}

		$version = self::wanted( $r, $options, $args['app_version'] ?? '' );

		if ( null === $version ) {
			return $meta;
		}

		// The install starts again from nothing: a new request, no old status, no old login.
		$meta = array_merge(
			$meta,
			array(
				'app_state'        => null,
				'app_step'         => null,
				'app_request'      => null,
				'app_checked_at'   => null,
				'app_started_at'   => null,
				'app_installed_at' => null,
				'app_queue_until'  => null,
				'app_recipe'       => null,
				'app_upstream'     => null,
				'app_reveal'       => '',
				'app_reveal_until' => 0,
			)
		);

		if ( '' === $version ) {
			return array_merge(
				$meta,
				array(
					'app_id'          => '',
					'app_version'     => '',
					'app_domain'      => null,
					'app_domain_want' => null,
					'app_domain_error' => null,
					'app_zone'        => null,
				)
			);
		}

		$meta['app_version'] = $version;

		return $meta;
	}
}
