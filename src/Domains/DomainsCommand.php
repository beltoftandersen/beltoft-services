<?php
/**
 * Domain and DNS commands: the TLD price table, the registrar balance, linking an existing domain, the DNS check.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Domains\Clients as DomainClients;
use BeltoftServices\Domains\Health as DomainHealth;
use BeltoftServices\Domains\Prices as DomainPrices;
use BeltoftServices\Domains\Search as DomainSearch;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Domain and DNS commands: the TLD price table, the registrar balance, linking an existing domain, the DNS check.
 */
final class DomainsCommand {
	/**
	 * Fetches the registrar TLD table and prints the number saved.
	 *
	 * @subcommand domain-prices
	 */
	public function domain_prices( array $args, array $assoc_args ): void {
		$count = DomainPrices::sync();
		if ( is_wp_error( $count ) ) { \WP_CLI::error( $count->get_error_message() ); }
		\WP_CLI::success( sprintf( '%d TLD prices saved.', $count ) );
	}

	/**
	 * Prints the account balance in the registrar's currency.
	 *
	 * @subcommand domain-balance
	 */
	public function domain_balance( array $args, array $assoc_args ): void {
		$result = DomainClients::registrar()->request( 'GET', '/resellers' );
		if ( is_wp_error( $result ) ) { \WP_CLI::error( $result->get_error_message() ); }
		if ( ! is_numeric( $result['balance'] ?? null ) ) { \WP_CLI::error( 'Registrar did not return a balance.' ); }
		\WP_CLI::log( sprintf( 'Balance: %s %s', (string) $result['balance'], (string) ( $result['currency'] ?? 'EUR' ) ) );
	}

	/**
	 * Link an existing registration or DNS zone to an active license, without ordering anything.
	 *
	 * ## OPTIONS
	 * <license> : License id.
	 * <domain> : Domain name.
	 * [--registrar-id=<id>] : The registrar's numeric domain id (not for --dns-only).
	 * [--dns-only] : Link a DNS zone on our servers instead of a registration.
	 * [--dry-run] : Run every check and print what would be linked, change nothing.
	 *
	 * @subcommand link-domain
	 */
	public function link_domain( array $args, array $assoc_args ): void {
		$license = Licenses::find( (int) ( $args[0] ?? 0 ) );
		$domain = DomainSearch::normalise( (string) ( $args[1] ?? '' ) );
		$dns_only = isset( $assoc_args['dns-only'] );
		$remote_id = (string) ( $assoc_args['registrar-id'] ?? '' );
		if ( ! $license || is_wp_error( $domain ) ) { \WP_CLI::error( 'Give an existing license id and a valid domain.' ); }
		$driver = Product::driver_for( (int) $license['product_id'] );
		if ( ! $driver || $driver->id() !== ( $dns_only ? 'dns' : 'domain' ) ) { \WP_CLI::error( 'The license product has the wrong domain driver.' ); }
		if ( ! $dns_only && ! ctype_digit( $remote_id ) ) { \WP_CLI::error( '--registrar-id must be a numeric domain id.' ); }
		if ( Resource::for_license( (int) $license['id'] ) ) { \WP_CLI::error( 'The license already has a resource.' ); }
		if ( Licenses::STATUS_ACTIVE !== Licenses::effective_status( $license ) ) { \WP_CLI::error( 'The license must be active.' ); }
		foreach ( array( 'domain', 'dns' ) as $kind ) {
			foreach ( Resource::live_ids( $kind ) as $id ) {
				$other = Resource::find( $id );
				if ( $other && $other->get( 'domain' ) === $domain && (int) ( $other->license()['user_id'] ?? 0 ) !== (int) ( $license['user_id'] ?? 0 ) ) { \WP_CLI::error( 'This domain belongs to another customer resource.' ); }
				if ( $other && $other->get( 'domain' ) === $domain && $kind === $driver->kind() ) { \WP_CLI::error( 'This domain already has a resource of this kind.' ); }
			}
		}
		if ( ! $dns_only ) {
			// The id must be this domain's: a wrong id would manage another registration.
			$found = DomainClients::registrar()->request( 'GET', '/domains/' . $remote_id );
			if ( is_wp_error( $found ) ) { \WP_CLI::error( 'The registrar could not confirm the registrar id.' ); }
			$named = isset( $found['full_name'] ) ? (string) $found['full_name'] : trim( (string) ( $found['domain']['name'] ?? '' ) . '.' . (string) ( $found['domain']['extension'] ?? '' ), '.' );
			if ( strtolower( rtrim( $named, '.' ) ) !== $domain ) { \WP_CLI::error( 'The registrar id belongs to a different domain.' ); }
		}
		if ( $dns_only ) {
			$zone = DomainClients::dns()->zone( $domain );
			if ( is_wp_error( $zone ) || null === $zone ) { \WP_CLI::error( 'The DNS zone must exist before linking.' ); }
		}
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			\WP_CLI::success( sprintf( 'Dry run: %s would be linked to license %d as a %s. Nothing was changed.', $domain, (int) $license['id'], $dns_only ? 'DNS zone' : 'registration' ) );
			return;
		}
		$meta = array( 'state' => 'ready', 'desired' => 'ready', 'domain' => $domain, 'mode' => $dns_only ? 'dns' : 'register', 'protected' => true, 'notified' => 'ready', 'steps' => array( 'claim', 'zone', 'delegation' ), 'zone_claimed' => true );
		if ( ! $dns_only ) { $meta['remote_id'] = $remote_id; $meta['steps'] = array( 'contact', 'zone_prepared', 'order', 'active', 'zone', 'dates' ); }
		$variation_id = (int) ( $license['variation_id'] ?? 0 );
		$r = Resource::create( $license, $driver, Product::plan( (int) $license['product_id'], $variation_id > 0 ? $variation_id : null ), $meta );
		if ( is_wp_error( $r ) ) { \WP_CLI::error( $r->get_error_message() ); }
		Events::add( (int) $license['id'], 'resource_linked', sprintf( 'Linked existing domain %s by WP-CLI.', $domain ) );
		\WP_CLI::success( sprintf( 'Linked %s to license %d.', $domain, (int) $license['id'] ) );
	}

	/** Check configured authoritative servers and report zone problems.
	 * @subcommand dns-check
	 */
	public function dns_check( array $args, array $assoc_args ): void {
		$issues = DomainHealth::dns_check();
		if ( is_wp_error( $issues ) ) { \WP_CLI::error( $issues->get_error_message() ); }
		foreach ( $issues as $issue ) { \WP_CLI::warning( $issue ); }
		if ( $issues ) { \WP_CLI::error( count( $issues ) . ' DNS issue(s) found.' ); }
		\WP_CLI::success( 'DNS check passed.' );
	}

	/**
	 * Registers each subcommand under `beltoft-services` when WP-CLI is running.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'beltoft-services domain-prices', array( self::class, 'domain_prices' ) );
			\WP_CLI::add_command( 'beltoft-services domain-balance', array( self::class, 'domain_balance' ) );
			\WP_CLI::add_command( 'beltoft-services link-domain', array( self::class, 'link_domain' ) );
			\WP_CLI::add_command( 'beltoft-services dns-check', array( self::class, 'dns_check' ) );
		}
	}
}
