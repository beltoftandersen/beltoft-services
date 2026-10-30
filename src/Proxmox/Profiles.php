<?php
/**
 * Chooses the build profile of a resource.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * VpsProfile unless the filter `beltoft_services_build_profile` returns another BuildProfile.
 */
final class Profiles {

	/**
	 * The profile of a resource.
	 *
	 * @param Resource $r Resource.
	 */
	public static function for_resource( Resource $r ): BuildProfile {
		/**
		 * Filters the build profile of a resource.
		 *
		 * @param BuildProfile $profile The default (VpsProfile).
		 * @param Resource     $r       The resource.
		 */
		$profile = apply_filters( 'beltoft_services_build_profile', new VpsProfile(), $r );

		return $profile instanceof BuildProfile ? $profile : new VpsProfile();
	}
}
