<?php
/**
 * What the reinstall does inside the rescue system of a dedicated server.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

defined( 'ABSPATH' ) || exit;

/**
 * Plugged in two ways: SshRescueShell (the real thing, root login with the one-time rescue password over ssh) and FakeRescueShell (tests and
 * the staging site, which has no real rescue system). RescueShells::for() picks one. The password is the one the web service returned
 * when the rescue system was activated (Robot webservice, "Boot configuration", POST /boot/{server-number}/rescue: no key, answer has
 * `password`). It is handed in for every call and never stored by an implementation, written to a log or put in an error.
 */
interface RescueShell {

	/**
	 * Does the server answer on the ssh port?
	 *
	 * @param string $ip Address.
	 */
	public function reachable( string $ip ): bool;

	/**
	 * The file names in /root/images of the rescue system (the images installimage offers).
	 *
	 * @param string $ip       Address.
	 * @param string $password Rescue password.
	 * @return string[]|\WP_Error
	 */
	public function images( string $ip, string $password );

	/**
	 * The disks of the server, as /dev paths (the drives installimage is told to use).
	 *
	 * @param string $ip       Address.
	 * @param string $password Rescue password.
	 * @return string[]|\WP_Error
	 */
	public function drives( string $ip, string $password );

	/**
	 * Writes /autosetup (installimage config) and, when given, the file of public keys named in it, then starts `installimage` in the
	 * background (the documented autosetup way: the installer finds /autosetup and asks nothing). Returns at once.
	 *
	 * @param string $ip       Address.
	 * @param string $password Rescue password.
	 * @param string $config   Content of /autosetup. Never holds a password.
	 * @param string $keys     Content of /root/ii-keys (public keys, one per line), or empty.
	 * @return true|\WP_Error
	 */
	public function start( string $ip, string $password, string $config, string $keys );

	/**
	 * How the installation stands: state running, done or failed, and the last lines of its output (no secrets in it).
	 *
	 * @param string $ip       Address.
	 * @param string $password Rescue password.
	 * @return array{state: string, tail: string}|\WP_Error
	 */
	public function status( string $ip, string $password );

	/**
	 * Restarts the server into the system that was installed.
	 *
	 * @param string $ip       Address.
	 * @param string $password Rescue password.
	 * @return true|\WP_Error
	 */
	public function reboot( string $ip, string $password );
}
