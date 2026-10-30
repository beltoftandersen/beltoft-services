<?php
/** The app choice when a server is reinstalled. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Account\Servers;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A server with an app is reinstalled with the same app and version (the default), another version
 * that can still be ordered, or no app. A server without an app is offered the apps that checkout
 * would offer on its cluster and plan (default: no app, as it is); an app picked there is posted as
 * `app/version`, sets Ubuntu 24.04 as at checkout, and may choose a domain for supported apps.
 * A version that has been retired is never offered; if the server
 * has one, the current version is preselected and the form says why. The field is posted as
 * `app_version`: `keep`, a version id, or `none`.
 */
final class AppReinstall {

	public const NONE = 'none';
	public const KEEP = 'keep';
	public const HANDLE = 'beltoft-services-app-reinstall';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_reinstall_fields', array( __CLASS__, 'fields' ) );
		add_action( 'beltoft_services_reinstall_confirm', array( __CLASS__, 'confirm' ) );
		add_filter( 'beltoft_services_reinstall_check', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'beltoft_services_reinstall_meta', array( __CLASS__, 'meta' ), 10, 3 );
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'assets' ), 25, 2 );
	}

	/** Load the reinstall domain controls only on a server page. */
	public static function assets( $parent, $endpoint = null ): void {
		if ( ! is_string( $parent ) || ( is_string( $endpoint ) && Servers::slug() !== $endpoint ) ) {
			return;
		}
		$asked = Servers::requested();
		$r = $asked['id'] > 0 ? Resource::for_license( (int) $asked['id'] ) : null;
		if ( ! $r || (int) ( $r->license()['user_id'] ?? 0 ) !== get_current_user_id() || null === self::options( $r ) ) {
			return;
		}
		wp_enqueue_style( AppTab::HANDLE, plugins_url( 'assets/app-tab.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/app-tab.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/app-reinstall.js', BELTOFT_SERVICES_FILE ), array( Tiles::HANDLE ), \BeltoftServices\Assets::version( 'assets/app-reinstall.js' ), true );
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

		if ( null === $options || ! isset( $options['choices'][ $raw ] ) || 1 !== preg_match( '#^([a-z0-9-]+)/([a-zA-Z0-9.-]+)$#D', $raw, $m ) ) {
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
			'app_domain_error'   => null,
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

		$record  = VmRecords::for_resource( $resource_id );
		$cluster = $record ? (array) Clusters::get( $record['cluster'] ) : array();
		$os      = $record ? Apps::os_template( $cluster ) : 0;

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

				$usable   = '' !== $first;
				$current  = reset( $entry['versions'] );
				$families = Apps::families( array_column( $entry['versions'], 'version' ) );
				$items[]  = array(
					'value'    => $usable ? $first : (string) array_key_first( $entry['versions'] ),
					'name'     => (string) ( $entry['def']['name'] ?? $app_id ),
					'line'     => (string) ( $entry['def']['summary'] ?? '' ),
					'runs'     => Apps::runs_on( $cluster, $families ),
					'logo'     => Tiles::app_logo( (array) $entry['def'] ),
					'disabled' => ! $usable,
					'why'      => $usable ? '' : AppField::needs( (array) $current['version'] ),
					'attrs'    => array(
						'data-versions' => (string) wp_json_encode( $versions ),
						'data-families' => implode( ',', $families ),
						'data-dropped'  => Apps::dropped_text( $cluster, $families, (string) ( $entry['def']['name'] ?? $app_id ) ),
					) + ( ! empty( $entry['def']['domain'] ) ? array( 'data-domain' => '1' ) : array() ),
				);
			}
		} else {
			$versions = array();
			$value    = '';
			$fulls    = array();

			foreach ( $options['choices'] as $choice => $label ) {
				if ( self::NONE === (string) $choice ) {
					continue;
				}

				$versions[] = array( 'value' => (string) $choice, 'label' => (string) $label );
				$fulls[]    = self::KEEP === (string) $choice ? Apps::version( (string) $r->get( 'app_id' ), (string) $r->get( 'app_version' ) ) : Apps::version( (string) $r->get( 'app_id' ), (string) $choice );
				$value      = '' === $value || (string) $choice === $options['selected'] ? (string) $choice : $value;
			}

			if ( array() !== $versions ) {
				$families = Apps::families( $fulls );
				$items[]  = array(
					'value'   => $value,
					'name'    => (string) $options['app']['name'],
					'line'    => (string) ( $options['app']['summary'] ?? '' ),
					'runs'    => Apps::runs_on( $cluster, $families ),
					'logo'    => Tiles::app_logo( (array) $options['app'] ),
					'checked' => self::NONE !== $options['selected'],
					'attrs'   => array(
						'data-versions' => (string) wp_json_encode( $versions ),
						'data-families' => implode( ',', $families ),
						'data-dropped'  => Apps::dropped_text( $cluster, $families, (string) $options['app']['name'] ),
					) + ( ! empty( $options['app']['domain'] ) ? array( 'data-domain' => '1' ) : array() ),
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
				'attrs'  => array(
					'data-os' => (string) $os,
				),
			)
		);

		echo '<p class="form-row" data-tiles-version-row hidden><label for="beltoft-vm-app-version-' . (int) $resource_id . '">' . esc_html__( 'Version', 'beltoft-services' ) . '</label>';
		echo '<select id="beltoft-vm-app-version-' . (int) $resource_id . '" data-tiles-version></select></p>';
		self::domain_fields( $r, $options );
		echo '</div>';

		if ( $options['retired'] ) {
			echo '<p class="description">' . esc_html__( 'The version on this server can no longer be installed. The current version is selected instead.', 'beltoft-services' ) . '</p>';
		}

	}

	/** Domain controls shared by same-app and plain-server reinstall choices. */
	private static function domain_fields( Resource $r, array $options ): void {
		$user = (int) ( $r->license()['user_id'] ?? 0 );
		$zones = AppDomains::zones( $user );
		$supports = ! empty( $options['app']['domain'] );
		foreach ( (array) ( $options['apps'] ?? array() ) as $entry ) {
			$supports = $supports || ! empty( $entry['def']['domain'] );
		}
		if ( ! $zones || ! $supports ) {
			return;
		}

		$current = self::current_domain( $r );
		$default = '' === $current ? 'none' : 'keep';
		$id = 'beltoft-app-domain-' . $r->id();
		$made = '' !== (string) $r->get( 'app_domain' ) && true === $r->get( 'app_domain_made' );
		echo '<fieldset class="beltoft-services-app-reinstall__domain" data-app-domain data-current-domain="' . esc_attr( $current ) . '"' . ( $made ? ' data-domain-made="1"' : '' ) . ( $options['plain'] || self::NONE === $options['selected'] ? ' hidden' : '' ) . '><legend>' . esc_html__( 'Domain', 'beltoft-services' ) . '</legend>';
		if ( '' !== $current ) {
			echo '<label class="beltoft-services-app-reinstall__choice"><input type="radio" name="app_domain_mode" value="keep" checked> ';
			/* translators: %s: existing app domain. */
			echo esc_html( sprintf( __( 'Keep %s', 'beltoft-services' ), $current ) );
			echo '<span class="description">' . esc_html__( 'The record stays as it is.', 'beltoft-services' ) . '</span></label>';
		}
		echo '<label class="beltoft-services-app-reinstall__choice"><input type="radio" name="app_domain_mode" value="change"> ' . esc_html__( 'Use another domain', 'beltoft-services' );
		echo '<span class="description">' . esc_html__( 'Adds an A record to one of your domains on our name servers, and the site is served with HTTPS. Your other records are not changed.', 'beltoft-services' ) . '</span></label>';
		echo '<div class="beltoft-services-app-reinstall__domain-fields" data-app-domain-fields hidden>';
		echo '<label for="' . esc_attr( $id . '-zone' ) . '">' . esc_html__( 'Domain', 'beltoft-services' ) . '</label><select id="' . esc_attr( $id . '-zone' ) . '" name="app_zone">';
		foreach ( $zones as $zone ) {
			echo '<option value="' . esc_attr( $zone ) . '">' . esc_html( $zone ) . '</option>';
		}
		echo '</select><label for="' . esc_attr( $id . '-sub' ) . '">' . esc_html__( 'Subdomain', 'beltoft-services' ) . '</label>';
		echo '<input type="text" id="' . esc_attr( $id . '-sub' ) . '" name="app_sub" maxlength="120" placeholder="www" autocomplete="off">';
		echo '<span class="description">' . esc_html__( 'Leave empty to use the domain itself.', 'beltoft-services' ) . '</span></div>';
		echo '<label class="beltoft-services-app-reinstall__choice"><input type="radio" name="app_domain_mode" value="none"' . checked( $default, 'none', false ) . '> ' . esc_html__( 'No domain', 'beltoft-services' );
		echo '<span class="description">' . esc_html__( 'The app uses the server address.', 'beltoft-services' ) . '</span></label>';
		echo '</fieldset>';
		if ( $made ) {
			/* translators: %s: the old app domain. */
			echo '<p class="description beltoft-services-app-reinstall__removal" data-app-domain-removal hidden>' . esc_html( sprintf( __( 'We remove the A record we made for %s when the reinstall starts. If you changed that record, or DNS hosting for the domain has stopped, we leave it.', 'beltoft-services' ), $current ) ) . '</p>';
		}
	}

	/** Adds the old-record consequence to the existing reinstall confirmation dialog. */
	public static function confirm( int $resource_id ): void {
		$r = $resource_id > 0 ? Resource::find( $resource_id ) : null;
		if ( ! $r || (int) ( $r->license()['user_id'] ?? 0 ) !== get_current_user_id() || true !== $r->get( 'app_domain_made' ) || '' === (string) $r->get( 'app_domain' ) ) {
			return;
		}
		/* translators: %s: the old app domain. */
		echo '<p data-app-domain-confirm hidden>' . esc_html( sprintf( __( 'We remove the A record we made for %s. If you changed that record, or DNS hosting for the domain has stopped, we leave it.', 'beltoft-services' ), (string) $r->get( 'app_domain' ) ) ) . '</p>';
	}

	/** A published name takes precedence over a requested name. */
	private static function current_domain( Resource $r ): string {
		$published = (string) $r->get( 'app_domain' );
		return '' !== $published ? $published : (string) $r->get( 'app_domain_want' );
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

	/** The effective domain choice, including a same-name change that is really a keep. */
	private static function domain_choice( Resource $r, array $args, array $app, bool $check_dns = true ) {
		$current = self::current_domain( $r );
		$raw = $args['app_domain_mode'] ?? '';
		$mode = '' === $raw ? ( '' === $current ? 'none' : 'keep' ) : $raw;

		if ( ! $app ) {
			return array( 'mode' => 'none', 'zone' => '', 'fqdn' => '' );
		}
		if ( empty( $app['domain'] ) ) {
			if ( in_array( $raw, array( 'change', 'keep' ), true ) ) {
				return __( 'This app does not use a domain at install. You can add one later.', 'beltoft-services' );
			}
			return array( 'mode' => 'none', 'zone' => '', 'fqdn' => '' );
		}
		if ( 'none' === $mode ) {
			return array( 'mode' => 'none', 'zone' => '', 'fqdn' => '' );
		}
		if ( 'keep' === $mode ) {
			return '' === $current ? __( 'This server has no domain to keep.', 'beltoft-services' ) : array( 'mode' => 'keep', 'zone' => (string) $r->get( 'app_zone' ), 'fqdn' => $current );
		}
		if ( 'change' !== $mode ) {
			return __( 'Choose a domain option.', 'beltoft-services' );
		}

		$ip = IpPool::for_resource( $r->id() );
		$choice = AppDomains::choose( $app, (string) ( $args['app_zone'] ?? '' ), $args['app_sub'] ?? '', (int) ( $r->license()['user_id'] ?? 0 ), $check_dns, (string) ( $ip['address'] ?? '' ) );
		if ( is_string( $choice ) ) {
			return $choice;
		}
		return $current === $choice['fqdn'] ? array( 'mode' => 'keep', 'zone' => (string) $r->get( 'app_zone' ), 'fqdn' => $current ) : array( 'mode' => 'change', 'zone' => $choice['zone'], 'fqdn' => $choice['fqdn'] );
	}

	/** Refuse a removal that cannot safely remember the old record. */
	private static function domain_release_refusal( Resource $r, array $choice ): ?string {
		$published = (string) $r->get( 'app_domain' );
		if ( '' === $published || true !== $r->get( 'app_domain_made' ) || '' === (string) $r->get( 'app_zone' ) || 'keep' === $choice['mode'] ) {
			return null;
		}
		$ip = IpPool::for_resource( $r->id() );
		if ( '' === (string) ( $ip['address'] ?? $r->get( 'ip' ) ) ) {
			return __( 'The old domain record cannot be checked right now. Try again later.', 'beltoft-services' );
		}
		$pending = (array) $r->get( 'app_domain_old' );
		return count( $pending ) >= 3 ? __( 'The old domain records are still being checked. Try again later.', 'beltoft-services' ) : null;
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
			$why = self::plain_check( $why, $r, $args );
			if ( null !== $why ) {
				return $why;
			}
			$pick = self::plain_pick( $r, $args['app_version'] ?? '' );
			$choice = self::domain_choice( $r, $args, is_array( $pick ) ? (array) Apps::definition( $pick[0] ) : array() );
			return is_string( $choice ) ? $choice : self::domain_release_refusal( $r, $choice );
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
			return self::domain_release_refusal( $r, array( 'mode' => 'none' ) );
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

		$choice = self::domain_choice( $r, $args, (array) $options['app'] );
		return is_string( $choice ) ? $choice : self::domain_release_refusal( $r, $choice );
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

	/** Domain target and an exact old A-record deletion request, stored without doing DNS work. */
	private static function domain_meta( Resource $r, array $args, array $app ): array {
		$choice = self::domain_choice( $r, $args, $app, false );
		if ( is_string( $choice ) || null !== self::domain_release_refusal( $r, $choice ) ) {
			return array();
		}

		$meta = array( 'app_domain_error' => null, 'app_domain_tries' => null );
		$published = (string) $r->get( 'app_domain' );
		if ( 'keep' === $choice['mode'] ) {
			if ( '' !== (string) $r->get( 'app_domain_error' ) ) {
				$meta['app_domain'] = null;
				$meta['app_domain_want'] = $choice['fqdn'];
			}
			return $meta;
		}

		$meta['app_domain'] = null;
		$meta['app_domain_made'] = null;
		$meta['app_domain_want'] = 'change' === $choice['mode'] ? $choice['fqdn'] : null;
		$meta['app_zone'] = 'change' === $choice['mode'] ? $choice['zone'] : null;

		if ( '' !== $published && true !== $r->get( 'app_domain_made' ) ) {
			$meta['app_domain_left'] = array( 'zone' => (string) $r->get( 'app_zone' ), 'name' => $published );
		} else {
			$meta['app_domain_left'] = null;
		}
		if ( '' !== $published && true === $r->get( 'app_domain_made' ) && '' !== (string) $r->get( 'app_zone' ) ) {
			$ip = IpPool::for_resource( $r->id() );
			$old = (array) $r->get( 'app_domain_old' );
			$old[] = array(
				'zone' => (string) $r->get( 'app_zone' ),
				'name' => $published,
				'ip' => (string) ( $ip['address'] ?? $r->get( 'ip' ) ),
				'tries' => 0,
			);
			$meta['app_domain_old'] = array_slice( $old, 0, 3 );
		}
		return $meta;
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

			if ( ! is_array( $pick ) ) {
				return null === $pick && ( '' !== self::current_domain( $r ) || $r->get( 'app_domain_old' ) ) ? array_merge( $meta, self::domain_meta( $r, $args, array() ) ) : $meta;
			}
			if ( $os <= 0 || null === Apps::version( $pick[0], $pick[1] ) ) {
				return $meta;
			}

			// The app runs on Ubuntu 24.04, as at checkout, whatever system was posted.
			return array_merge( $meta, self::fresh(), self::domain_meta( $r, $args, (array) Apps::definition( $pick[0] ) ), array( 'app_id' => $pick[0], 'app_version' => $pick[1], OsField::META => $os ) );
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
		$meta = array_merge( $meta, self::fresh() );

		if ( '' === $version ) {
			return array_merge(
				$meta,
				self::domain_meta( $r, $args, array() ),
				array(
					'app_id'          => '',
					'app_version'     => '',
				)
			);
		}

		$meta['app_version'] = $version;

		return array_merge( $meta, self::domain_meta( $r, $args, (array) $options['app'] ) );
	}
}
