<?php
/** Binds each paid license to one chosen order line. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Drivers\Driver;

defined( 'ABSPATH' ) || exit;

final class Meta {
	public static function register(): void {
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 10, 3 );
	}

	public static function initial_meta( array $meta, array $license, Driver $driver ): array {
		if ( ! in_array( $driver->id(), array( 'domain', 'dns' ), true ) ) { return $meta; }
		$order = wc_get_order( (int) ( $license['order_id'] ?? 0 ) );
		if ( ! $order ) { return $meta; }
		$wanted = '';
		$subscription_id = (int) ( $license['subscription_id'] ?? 0 );
		if ( $subscription_id && function_exists( 'wcs_get_subscription' ) ) {
			$subscription = wcs_get_subscription( $subscription_id );
			if ( $subscription ) {
				foreach ( $subscription->get_items() as $sub_item ) {
					$data = $sub_item->get_meta( '_beltoft_services_domain', true );
					if ( is_array( $data ) && ! empty( $data['domain'] ) ) { $wanted = (string) $data['domain']; break; }
				}
			}
		}
		foreach ( $order->get_items() as $item ) {
			$data = $item->get_meta( '_beltoft_services_domain', true );
			if ( ! is_array( $data ) || empty( $data['domain'] ) || ( $wanted && $wanted !== $data['domain'] ) || (int) $item->get_meta( '_beltoft_services_domain_license', true ) > 0 ) { continue; }
			$mode = (string) ( $data['mode'] ?? '' );
			if ( ( 'dns' === $driver->id() ) !== ( 'dns' === $mode ) ) { continue; }
			$item->update_meta_data( '_beltoft_services_domain_license', (int) $license['id'] );
			$item->save();
			$meta['domain'] = (string) $data['domain'];
			$meta['mode'] = $mode;
			if ( 'dns' === $mode ) {
				$claim_id = (int) ( $data['claim_id'] ?? $item->get_meta( '_beltoft_services_dns_claim', true ) );
				if ( $claim_id > 0 ) { $meta['claim_id'] = $claim_id; }
			}
			$auth = $item->get_meta( '_beltoft_services_domain_auth', true );
			if ( 'transfer' === $mode && is_string( $auth ) ) { $meta['auth'] = $auth; }
			$meta['order_item_id'] = (int) $item->get_id();
			break;
		}
		return $meta;
	}
}
