<?php
/** The app choice when a server is reinstalled. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A server with an app is reinstalled with the same app and version (the default), another version
 * that can still be ordered, or no app. A server without an app is offered the apps that checkout
 * would offer on its cluster and plan (default: no app, as it is); an app picked there is posted as
 * `app/version`, sets Ubuntu 24.04 as at checkout, and the domain picker is not part of a reinstall.
 * A version that has been retired is never offered; if the server
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

		if ( '' === $id ) {
			return self::plain_options( $r );
		}

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
			'plain'    => false,
			'app'      => $def,
			'choices'  => $choices,
			'selected' => $retired ? ( $current ? $current['version_id'] : self::NONE ) : self::KEEP,
			'retired'  => $retired,
		);
	}

	/**
	 * The apps a server without one can be reinstalled with: what checkout offers on its cluster, with retired
	 * versions left out. A version the plan is too small for is listed but disabled, with the plan it needs.
	 *
	 * @return array{plain: bool, app: array<string, mixed>, choices: array<string, string>, selected: string, retired: bool, groups: array<string, array<string, array{label: string, disabled: bool}>>, cluster: array<string, mixed>, plan: array<string, mixed>}|null
	 */
	private static function plain_options( Resource $r ): ?array {
		$record  = VmRecords::for_resource( $r->id() );
		$cluster = $record ? Clusters::get( $record['cluster'] ) : null;

		if ( null === $cluster || ! $r->accepts_actions() ) {
			return null;
		}

		$offered = Apps::offered( $cluster );

		if ( array() === $offered ) {
			return null;
		}

		$plan            = (array) $r->get( 'plan' );
		$plan['disk_gb'] = Panels::disk_gb( $r );
		$choices         = array( self::NONE => __( 'No app, keep as it is', 'beltoft-services' ) );
		$groups          = array();
		$apps            = array();

		foreach ( $offered as $id => $app ) {
			foreach ( $app['choices'] as $vid => $version ) {
				$why      = Apps::plan_refusal( $version, $plan );
				$name     = Apps::plan_name( $version );
				$label    = Apps::version_label( $version );
				$disabled = null !== $why;

				if ( $disabled && '' !== $name ) {
					/* translators: 1: app version, 2: plan name such as Performance */
					$label = sprintf( __( '%1$s (needs the %2$s plan or larger)', 'beltoft-services' ), $label, $name );
				} elseif ( $disabled ) {
					$label = sprintf( /* translators: %s: app version */ __( '%s (needs a larger plan)', 'beltoft-services' ), $label );
				}

				$apps[ $id ]['def']                                  = $app;
				$apps[ $id ]['versions'][ $id . '/' . $vid ]         = array( 'label' => $label, 'disabled' => $disabled, 'version' => $version );
				$choices[ $id . '/' . $vid ]                       = $label;
				$groups[ (string) ( $app['name'] ?? $id ) ][ $id . '/' . $vid ] = array( 'label' => $label, 'disabled' => $disabled );
			}
		}

		return array(
			'plain'    => true,
			'app'      => array( 'name' => __( 'App', 'beltoft-services' ) ),
			'choices'  => $choices,
			'selected' => self::NONE,
			'retired'  => false,
			'groups'   => $groups,
			'apps'     => $apps,
			'cluster'  => $cluster,
			'plan'     => $plan,
		);
	}

	/**
	 * The app a server without one is to get: [ app id, version id ], null for no app, or false when the choice is not valid.
	 *
	 * @param mixed $raw Posted value.
	 * @return array{0: string, 1: string}|false|null
	 */
	private static function plain_pick( Resource $r, $raw ) {
		if ( ! is_string( $raw ) ) {
			return false;
		}

		if ( in_array( $raw, array( '', self::NONE, self::KEEP ), true ) ) {
			return null;
		}

		$options = self::plain_options( $r );

		if ( null === $options || ! isset( $options['choices'][ $raw ] ) || 1 !== preg_match( '#^([a-z0-9-]+)/([a-zA-Z0-9.-]+)$#', $raw, $m ) ) {
			return false;
		}

		return array( $m[1], $m[2] );
	}

	/** What a fresh install of an app starts from: a new request, no old status, no old login. */
	private static function fresh(): array {
		return array(
			'app_state'          => null,
			'app_step'           => null,
			'app_request'        => null,
			'app_checked_at'     => null,
			'app_started_at'     => null,
			'app_ready_since'    => null,
			'app_domain_tries'   => null,
			'app_domain_alerted' => null,
			'app_installed_at'   => null,
			'app_queue_until'    => null,
			'app_recipe'         => null,
			'app_upstream'       => null,
			'app_reveal'         => '',
			'app_reveal_until'   => 0,
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

		$items = array();

		if ( $options['plain'] ) {
			$items[] = array(
				'value'   => self::NONE,
				'name'    => __( 'No app', 'beltoft-services' ),
				'line'    => __( 'Keep the server as it is', 'beltoft-services' ),
				'logo'    => Tiles::app_logo( array() ),
				'checked' => true,
				'attrs'   => array( 'data-none' => '1' ),
			);

			foreach ( (array) $options['apps'] as $app_id => $entry ) {
				$versions = array();
				$first    = '';

				foreach ( $entry['versions'] as $value => $version ) {
					$versions[] = array( 'value' => (string) $value, 'label' => $version['label'], 'disabled' => $version['disabled'] );
					$first      = '' === $first && ! $version['disabled'] ? (string) $value : $first;
				}

				$usable  = '' !== $first;
				$current = reset( $entry['versions'] );
				$items[] = array(
					'value'    => $usable ? $first : (string) array_key_first( $entry['versions'] ),
					'name'     => (string) ( $entry['def']['name'] ?? $app_id ),
					'line'     => (string) ( $entry['def']['summary'] ?? '' ),
					'logo'     => Tiles::app_logo( (array) $entry['def'] ),
					'disabled' => ! $usable,
					'why'      => $usable ? '' : AppField::needs( (array) $current['version'] ),
					'attrs'    => array( 'data-versions' => (string) wp_json_encode( $versions ) ),
				);
			}
		} else {
			$versions = array();
			$value    = '';

			foreach ( $options['choices'] as $choice => $label ) {
				if ( self::NONE === (string) $choice ) {
					continue;
				}

				$versions[] = array( 'value' => (string) $choice, 'label' => (string) $label );
				$value      = '' === $value || (string) $choice === $options['selected'] ? (string) $choice : $value;
			}

			if ( array() !== $versions ) {
				$items[] = array(
					'value'   => $value,
					'name'    => (string) $options['app']['name'],
					'line'    => (string) ( $options['app']['summary'] ?? '' ),
					'logo'    => Tiles::app_logo( (array) $options['app'] ),
					'checked' => self::NONE !== $options['selected'],
					'attrs'   => array( 'data-versions' => (string) wp_json_encode( $versions ) ),
				);
			}

			$items[] = array(
				'value'   => self::NONE,
				'name'    => __( 'No app', 'beltoft-services' ),
				'line'    => __( 'Just the operating system', 'beltoft-services' ),
				'logo'    => Tiles::app_logo( array() ),
				'checked' => self::NONE === $options['selected'],
				'attrs'   => array( 'data-none' => '1' ),
			);
		}

		// The version select under the tiles has no name: the script writes its choice into the checked tile's value, which is what is posted.
		echo '<div class="beltoft-services-app-reinstall" data-app-reinstall data-tiles-merge>';

		Tiles::print_group(
			array(
				'kind'   => 'app',
				'id'     => 'beltoft-vm-app-' . $resource_id,
				'name'   => 'app_version',
				'legend' => __( 'App', 'beltoft-services' ),
				'items'  => $items,
				'attrs'  => array( 'data-os' => (string) $os ),
			)
		);

		echo '<p class="form-row" data-tiles-version-row hidden><label for="beltoft-vm-app-version-' . (int) $resource_id . '">' . esc_html__( 'Version', 'beltoft-services' ) . '</label>';
		echo '<select id="beltoft-vm-app-version-' . (int) $resource_id . '" data-tiles-version></select></p>';
		echo '</div>';

		if ( $options['retired'] ) {
			echo '<p class="description">' . esc_html__( 'The version on this server can no longer be installed. The current version is selected instead.', 'beltoft-services' ) . '</p>';
		}

		echo '<p class="description">' . esc_html( $options['plain'] ? __( 'Choosing an app installs Ubuntu 24.04, because the app runs on it.', 'beltoft-services' ) : __( 'An app runs on Ubuntu 24.04, so the operating system stays the same while an app is selected.', 'beltoft-services' ) ) . '</p>';
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
		if ( $r instanceof Resource && '' === (string) $r->get( 'app_id' ) ) {
			return self::plain_check( $why, $r, $args );
		}

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

		if ( null === $cluster || null === $full ) {
			return __( 'This app cannot be installed on this server.', 'beltoft-services' );
		}

		if ( Apps::FAMILY !== $family ) {
			return __( 'This app needs Ubuntu 24.04 and cannot be installed here with the chosen system. Choose Ubuntu 24.04, or no app.', 'beltoft-services' );
		}

		$why = Apps::check( $cluster, array_merge( $plan, array( 'template' => $os > 0 ? $os : Apps::os_template( $cluster ) ) ), (string) $r->get( 'app_id' ), $version );

		if ( null !== $why ) {
			return $why . '.';
		}

		return null;
	}

	/**
	 * The check for a server without an app: no choice, or an app that checkout would also accept here.
	 *
	 * @param mixed                $why  Refusal so far.
	 * @param array<string, mixed> $args Arguments.
	 * @return mixed
	 */
	private static function plain_check( $why, Resource $r, array $args ) {
		if ( null !== $why ) {
			return $why;
		}

		$pick = self::plain_pick( $r, $args['app_version'] ?? '' );

		if ( null === $pick ) {
			return null;
		}

		if ( false === $pick ) {
			return __( 'Choose one of the listed app versions.', 'beltoft-services' );
		}

		$options = self::plain_options( $r );
		$full    = Apps::version( $pick[0], $pick[1] );
		$cluster = $options['cluster'];
		$plan    = $options['plan'];

		if ( null === $full ) {
			return __( 'Choose one of the listed app versions.', 'beltoft-services' );
		}

		$refusal = Apps::plan_refusal( $full, $plan );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$families = (array) ( $cluster['template_families'] ?? array() );
		$os       = isset( $args['os'] ) && '' !== $args['os'] ? (int) $args['os'] : 0;

		if ( $os > 0 && Apps::FAMILY !== (string) ( $families[ $os ] ?? '' ) ) {
			return __( 'This app needs Ubuntu 24.04 and cannot be installed here with the chosen system. Choose Ubuntu 24.04, or no app.', 'beltoft-services' );
		}

		$why = Apps::check( $cluster, array_merge( $plan, array( 'template' => Apps::os_template( $cluster ) ) ), $pick[0], $pick[1] );

		return null === $why ? null : $why . '.';
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
		if ( is_array( $meta ) && $r instanceof Resource && '' === (string) $r->get( 'app_id' ) ) {
			$pick   = self::plain_pick( $r, $args['app_version'] ?? '' );
			$record = VmRecords::for_resource( $r->id() );
			$os     = $record ? Apps::os_template( (array) Clusters::get( $record['cluster'] ) ) : 0;

			if ( ! is_array( $pick ) || $os <= 0 || null === Apps::version( $pick[0], $pick[1] ) ) {
				return $meta;
			}

			// The app runs on Ubuntu 24.04, as at checkout, whatever system was posted.
			return array_merge( $meta, self::fresh(), array( 'app_id' => $pick[0], 'app_version' => $pick[1], OsField::META => $os ) );
		}

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
				'app_ready_since'  => null,
				'app_domain_tries' => null,
				'app_domain_alerted' => null,
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
