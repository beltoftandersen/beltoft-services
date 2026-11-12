<?php
/**
 * What a customer can do with a hosting account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Hosting\Actions\AddonActions;
use BeltoftServices\Hosting\Actions\CareActions;
use BeltoftServices\Hosting\Actions\CountryActions;
use BeltoftServices\Hosting\Actions\FilesActions;
use BeltoftServices\Hosting\Actions\PhpActions;
use BeltoftServices\Hosting\Actions\ToolActions;
use BeltoftServices\Hosting\Actions\SiteActions;
use BeltoftServices\Hosting\Actions\StagingActions;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The customer operations behind the account page and the REST routes: sites, domains, PHP version and limits,
 * cache purge, the WordPress login link, Email lite, backups. The work lives in four groups under Hosting\Actions
 * (SiteActions, PhpActions, ToolActions, AddonActions, CareActions); this class builds them from the same Resource and forwards
 * each call by name, so the routes, the account page, the add-ons, the CLI and the MCP tools keep one entry point.
 * Results are arrays; a refusal is a WP_Error with a short code (invalid, limit, exists, not_found, busy, failed,
 * not_ready) and words for the customer. Nothing an agent says is passed on.
 */
final class HostingActions {

	/**
	 * Websites and their domain names.
	 *
	 * @var SiteActions
	 */
	private $sites;

	/**
	 * PHP settings, cache purge, login link.
	 *
	 * @var PhpActions
	 */
	private $php;

	/**
	 * The one-time links to the file manager and the database tool.
	 *
	 * @var ToolActions
	 */
	private $tools;

	/**
	 * Add-ons and Email lite.
	 *
	 * @var AddonActions
	 */
	private $addons;

	/**
	 * The country block of a website.
	 *
	 * @var CountryActions
	 */
	private $country;

	/**
	 * The staging copy of a website.
	 *
	 * @var StagingActions
	 */
	private $staging;

	/**
	 * Backups, restore, undo, checklist.
	 *
	 * @var CareActions
	 */
	private $care;

	/**
	 * Where the staging copy of a website is now.
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error state, busy, note, failed, host.
	 */
	public function staging( string $slug ) {
		return $this->staging->staging( $slug );
	}

	/**
	 * Makes the staging copy of a website (in the background).
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_staging( string $slug ) {
		return $this->staging->create_staging( $slug );
	}

	/**
	 * Makes the staging copy again from the website (in the background).
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function refresh_staging( string $slug ) {
		return $this->staging->refresh_staging( $slug );
	}

	/**
	 * Pushes the staging copy to the website (in the background), after a safety point.
	 *
	 * @param string $slug       Website.
	 * @param bool   $files_only Files only.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function push_staging( string $slug, bool $files_only = false ) {
		return $this->staging->push_staging( $slug, $files_only );
	}

	/**
	 * Puts the website back as it was before the last push (in the background).
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function undo_staging( string $slug ) {
		return $this->staging->undo_staging( $slug );
	}

	/**
	 * Deletes the staging copy of a website (in the background).
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_staging( string $slug ) {
		return $this->staging->delete_staging( $slug );
	}

	/**
	 * A one-time WordPress sign-in address of the staging copy.
	 *
	 * @param string $slug Website.
	 * @return array<string, mixed>|\WP_Error url.
	 */
	public function staging_login( string $slug ) {
		return $this->staging->staging_login( $slug );
	}

	/**
	 * File access: SFTP details and password, SSH keys.
	 *
	 * @var FilesActions
	 */
	private $files;

	/**
	 * @param Resource $r Hosting resource.
	 */
	public function __construct( Resource $r ) {
		$this->files  = new FilesActions( $r );
		$this->sites  = new SiteActions( $r );
		$this->php    = new PhpActions( $r );
		$this->tools  = new ToolActions( $r );
		$this->addons = new AddonActions( $r );
		$this->country = new CountryActions( $r );
		$this->staging = new StagingActions( $r );
		$this->care   = new CareActions( $r );
	}

	/**
	 * The sites of the account with their names and settings.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sites(): array {
		return $this->sites->sites();
	}

	/**
	 * The ceilings of the plan, for the forms.
	 *
	 * @return array<string, mixed>
	 */
	public function limits(): array {
		return $this->sites->limits();
	}

	/**
	 * Adds a website: a record, its name, its place on the agent and the edge.
	 *
	 * @param string $slug    The name used in the preview address.
	 * @param string $label   A title.
	 * @param string $php     PHP version ('' for the default).
	 * @param bool   $install Install WordPress.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function add_site( string $slug, string $label = '', string $php = '', bool $install = true ) {
		return $this->sites->add_site( $slug, $label, $php, $install );
	}

	/**
	 * Removes a website: its files and database go, its names too. The last website cannot be removed.
	 *
	 * @param string $slug    Site.
	 * @param string $confirm Must be the site name.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_site( string $slug, string $confirm ) {
		return $this->sites->remove_site( $slug, $confirm );
	}

	/**
	 * Adds a domain to a website.
	 *
	 * @param string $slug Site.
	 * @param string $host Domain name.
	 * @return array<string, mixed>|\WP_Error Kind and state, and for an external name what to point it at.
	 */
	public function add_domain( string $slug, string $host ) {
		return $this->sites->add_domain( $slug, $host );
	}

	/**
	 * Removes a domain from a website (the preview name stays).
	 *
	 * @param string $host  Domain name.
	 * @param bool   $final True when its paid add-on has ended (the renewal): the names go now, whatever the line says.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_domain( string $host, bool $final = false ) {
		return $this->sites->remove_domain( $host, $final );
	}

	/**
	 * Chooses which name of a with-and-without-www pair is the main address; the other one redirects to it (301).
	 *
	 * @param string $host The name that becomes the main address.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_main_address( string $host ) {
		return $this->sites->set_main_address( $host );
	}

	/**
	 * Moves WordPress itself to one of the website's addresses (background job; see SiteWordPress).
	 *
	 * @param string $slug Site.
	 * @param string $host The address, or '' for the main address.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function wordpress_address( string $slug, string $host = '' ) {
		return $this->sites->wordpress_address( $slug, $host );
	}

	/**
	 * Sends the account's current routes to the edge (a name became active or stopped being).
	 *
	 * @return true|\WP_Error
	 */
	public function sync_edge() {
		return $this->sites->sync_edge();
	}

	/**
	 * Changes the PHP version and the memory and upload ceilings of a site, never above the plan.
	 *
	 * @param string   $slug      Site.
	 * @param string   $php       Version.
	 * @param int|null $memory_mb memory_limit in MB, or null to keep.
	 * @param int|null $upload_mb Upload size in MB, or null to keep.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_php( string $slug, string $php, ?int $memory_mb = null, ?int $upload_mb = null ) {
		return $this->php->set_php( $slug, $php, $memory_mb, $upload_mb );
	}

	/**
	 * Goes back to the PHP version a site ran on before the automatic switch, for the 7 days after it. The old
	 * version may be out of support: the site stays on it, with a warning in the account, until the customer moves it.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function php_rollback( string $slug ) {
		return $this->php->php_rollback( $slug );
	}

	/**
	 * Moves PHP workers to a site. The sites together stay within the plan's ceiling, each has at least 2.
	 *
	 * @param string $slug    Site.
	 * @param int    $workers Workers.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_workers( string $slug, int $workers ) {
		return $this->php->set_workers( $slug, $workers );
	}

	/**
	 * Clears the page cache of a site.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function purge_cache( string $slug ) {
		return $this->php->purge_cache( $slug );
	}

	/**
	 * Puts the caches of a site in place on the node (staff, command line).
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function cache_setup( string $slug ) {
		return $this->php->cache_setup( $slug );
	}

	/**
	 * What the node says about the caches of a site.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function cache_status( string $slug ) {
		return $this->php->cache_status( $slug );
	}

	/**
	 * A one-time sign-in address for the site's WordPress.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error url.
	 */
	public function login_link( string $slug ) {
		return $this->php->login_link( $slug );
	}

	/**
	 * A one-time address that opens WordPress's own "choose a new password" screen for the website's administrator.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error url, and user and email of the administrator when the node says.
	 */
	public function password_link( string $slug ) {
		return $this->php->password_link( $slug );
	}

	/**
	 * A one-time address that opens the file manager of the website, on its own address.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error url.
	 */
	public function files_link( string $slug ) {
		return $this->tools->files_link( $slug );
	}

	/**
	 * A one-time address that opens the database tool of the website, on its own address.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error url.
	 */
	public function database_link( string $slug ) {
		return $this->tools->database_link( $slug );
	}

	/**
	 * The country block of a website: the countries, where it applies, the ready lists.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function country_block( string $slug ) {
		return $this->country->country_block( $slug );
	}

	/**
	 * Sets the country block of a website (an empty list turns it off). Asks for a confirmation when the country of the
	 * person asking is on the list.
	 *
	 * @param string   $slug      Site.
	 * @param string[] $countries Country codes.
	 * @param string   $scope     site or login.
	 * @param bool     $confirmed The person confirmed that their own country is on the list.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_country_block( string $slug, array $countries, string $scope, bool $confirmed = false ) {
		return $this->country->set_country_block( $slug, $countries, $scope, $confirmed );
	}

	/**
	 * What the page shows about file access: where to connect, the keys and whether a password was ever set.
	 *
	 * @return array<string, mixed>
	 */
	public function files(): array {
		return $this->files->files();
	}

	/**
	 * Sets the SFTP password: the one given (16 characters or more), or a generated one. Returned once.
	 *
	 * @param string $chosen The customer's own password, or '' for a generated one.
	 * @return array<string, mixed>|\WP_Error password, host, port, user.
	 */
	public function set_sftp_password( string $chosen = '' ) {
		return $this->files->set_sftp_password( $chosen );
	}

	/**
	 * Adds a public SSH key.
	 *
	 * @param string $key A public key line.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function add_ssh_key( string $key ) {
		return $this->files->add_ssh_key( $key );
	}

	/**
	 * Removes a public SSH key by its fingerprint.
	 *
	 * @param string $fingerprint SHA256:... as shown.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_ssh_key( string $fingerprint ) {
		return $this->files->remove_ssh_key( $fingerprint );
	}

	/**
	 * Buys an add-on: an extra domain or 5 GB of storage for one Email lite mailbox. Nothing is bought until the
	 * customer has confirmed the price; the line is added once the order for the time until the renewal is paid.
	 *
	 * @param string $kind    domain or mail_pack.
	 * @param string $target  The domain name, or the mailbox address.
	 * @param int    $confirm_cents The amount charged now that the customer saw and accepted, in cents; 0 when not confirmed.
	 * @param string $host    For a domain: the name to add once the order is paid through the pay link.
	 * @param string $slug    For a domain: the website of that name.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function buy_addon( string $kind, string $target, int $confirm_cents, string $host = '', string $slug = '' ) {
		return $this->addons->buy_addon( $kind, $target, $confirm_cents, $host, $slug );
	}

	/**
	 * Ends one storage pack of a mailbox: it leaves at the next renewal and the storage stays until then.
	 *
	 * @param string $mailbox Mailbox address.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_pack( string $mailbox ) {
		return $this->addons->remove_pack( $mailbox );
	}

	/**
	 * Makes Email lite for the account.
	 *
	 * @param string $domain The mail domain.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function enable_mail( string $domain ) {
		return $this->addons->enable_mail( $domain );
	}

	/**
	 * Points the websites' mail at the Email lite domain (the hidden system mailbox).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function use_email_for_sites() {
		return $this->addons->use_email_for_sites();
	}

	/**
	 * The backups the account has (the stored listing), the safety point and the last restore.
	 *
	 * @return array<string, mixed>
	 */
	public function backups(): array {
		return $this->care->backups();
	}

	/**
	 * Back up now.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function backup_now() {
		return $this->care->backup_now();
	}

	/**
	 * Starts a restore.
	 *
	 * @param string $scope account, files or database.
	 * @param string $volid Backup.
	 * @param string $slug  Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function restore( string $scope, string $volid, string $slug = '' ) {
		return $this->care->restore( $scope, $volid, $slug );
	}

	/**
	 * Goes back to the safety point.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function undo() {
		return $this->care->undo();
	}

	/**
	 * Ticks a getting-started item the customer does by hand, or hides the list.
	 *
	 * @param string $item build, backups, or '' with $dismiss.
	 * @param bool   $dismiss Hide the list.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function checklist( string $item, bool $dismiss = false ) {
		return $this->care->checklist( $item, $dismiss );
	}
}
