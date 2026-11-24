<?php
/**
 * The values array of a service email per driver kind; shared by EmailsTest and the hosting part of its fixture.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Support;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Emails\ServiceEmail;
use BeltoftServices\Resource;

/**
 * Needs the methods of BeltoftServices\Tests\TestCase.
 */
trait EmailValuesGolden {

	/**
	 * The template values of an email sent about a resource of the given driver id.
	 *
	 * @param string $driver Driver id of the resource.
	 * @param string $id     Email id.
	 * @return array<string, mixed>
	 */
	private function values_for( string $driver, string $id = 'beltoft_services_ready' ): array {
		$this->fake_driver( $driver );
		$product  = $this->service_product( $driver );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::find( Resource::create( $license, Registry::get( $driver ), array(), array( 'state' => 'ready' ) )->id() );
		$email    = Emails::get( $id );

		$email->trigger( $resource, array() );

		$property = new \ReflectionProperty( ServiceEmail::class, 'values' );
		$property->setAccessible( true );

		return $property->getValue( $email );
	}

	/**
	 * The values array of a "ready" email for one resource of each of five drivers, with the ids that come from the
	 * test database swapped for tokens. The fixture was made by running this same code on the plugin at 6d95431
	 * (before the values moved into the feature filters), so it holds the old values, not today's.
	 *
	 * @param string[] $drivers Driver kinds.
	 * @return array<string, array<string, mixed>>
	 */
	private function normalised_values( array $drivers ): array {
		$out = array();

		foreach ( $drivers as $driver ) {
			$this->fake_driver( $driver );
			$product = $this->service_product( $driver, array(), array( 'slug' => 'values-' . $driver ) );
			$product->set_name( 'Fixture product' );
			$product->save();
			$license = $this->service_license( $product, array( 'fire' => false ) );
			wp_update_user( array( 'ID' => $license['user_id'], 'display_name' => 'Fixture Customer' ) );
			$resource = Resource::find( Resource::create( $license, Registry::get( $driver ), array(), array( 'state' => 'ready' ) )->id() );
			$email    = Emails::get( 'beltoft_services_ready' );

			// The date comes from the data, so the run does not depend on the day it is made.
			$email->trigger( $resource, array( 'deletes_at' => 1900000000 ) );

			$property = new \ReflectionProperty( ServiceEmail::class, 'values' );
			$property->setAccessible( true );
			$values = $property->getValue( $email );
			ksort( $values );

			$json = (string) wp_json_encode( $values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$json = (string) preg_replace( '/(?<!\d)' . (int) $resource->id() . '(?!\d)/', '{resource}', $json );
			$json = (string) preg_replace( '/(?<!\d)' . (int) $license['id'] . '(?!\d)/', '{license}', $json );

			$out[ $driver ] = json_decode( $json, true );
		}

		return $out;
	}
}
