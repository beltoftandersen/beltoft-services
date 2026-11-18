<?php
/**
 * The one place a rescue shell is built.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

defined( 'ABSPATH' ) || exit;

/**
 * Real ssh only when the dedicated client is live (setting live, the real host, and the constant): the fake client and the test server (staging)
 * never get a real shell, so nothing ever connects to an address a fake Robot made up. A filter may inject another shell for a test.
 */
final class RescueShells {

	/** The shell for this request. */
	public static function for(): RescueShell {
		$shell = 'live' === DedicatedClients::kind() ? new SshRescueShell() : new FakeRescueShell();
		/**
		 * The rescue shell the reinstall uses. A filter may only replace it with a fake or test shell.
		 *
		 * @param RescueShell $shell Shell.
		 */
		$filtered = apply_filters( 'beltoft_services_dedicated_shell', $shell );

		return $filtered instanceof RescueShell && ! $filtered instanceof SshRescueShell ? $filtered : $shell;
	}
}
