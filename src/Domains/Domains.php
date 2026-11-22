<?php
/**
 * The Domains area of My Account: the list, one page per domain with its own tabs, and the add page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftLicenses\Licenses;
use BeltoftServices\Domains\DnsClaimPanel;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsClaimsFeature;
use BeltoftServices\Domains\DnsSchema;
use BeltoftServices\Domains\HostName;
use BeltoftServices\Resource;
use BeltoftServices\Account\Panel;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Account\Slugs;

defined( 'ABSPATH' ) || exit;

/**
 * Domains (driver kinds `domain` and `dns`) as a service area. Beltoft Licenses prints one card per license on the
 * Domains tab (endpoint `domains`); the area takes over that template call and prints:
 *
 *  - `/my-account/domains/`: one row per domain and DNS hosting service, then the open DNS hosting claims;
 *  - `/my-account/domains/<slug>/` and `/my-account/domains/<slug>/<tab>/`: the domain's page, one address per tab
 *    (dns, nameservers, transfer, danger; anything else is the overview). A license id in place of the slug goes to
 *    the slug address;
 *  - `/my-account/domains/add/`: adding a domain for DNS hosting.
 *
 * An address that is not one of the customer's own domains shows the list with a notice.
 */
final class Domains extends ServiceArea {

	/**
	 * Hooks: the place in the list of areas.
	 */
	public static function register(): void {
		ServiceArea::offer( self::class, 10 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function leads(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function marker(): string {
		return self::ADD_VALUE;
	}

	/**
	 * The tabs of a domain page after the overview, by address part.
	 */
	public const TABS = array( 'dns', 'nameservers', 'security', 'transfer', 'danger' );

	/**
	 * The value after the endpoint that is the add page.
	 */
	public const ADD_VALUE = 'add';

	/**
	 * The domain page being printed (license row, requested tab, resource, area), or null outside a domain page.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function on_page(): ?array {
		$page = ServiceArea::page();

		return null !== $page && ( $page['area'] ?? null ) instanceof self ? $page : null;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_slug(): string {
		return 'domains';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function key(): string {
		return 'domains';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function kinds(): array {
		return array( 'domain', 'dns' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Domains', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function empty_text(): string {
		return __( 'You have no domains yet.', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function all_label(): string {
		return __( 'All domains', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function not_found_text(): string {
		return __( 'That domain was not found. Here are your domains.', 'beltoft-services' );
	}

	/**
	 * The tabs after the overview. The tab strip is printed by the domain page's own template (DomainPage::build), so
	 * the titles only name the built-in addresses.
	 *
	 * {@inheritDoc}
	 */
	protected function page_tabs(): array {
		return array_fill_keys( self::TABS, '' );
	}

	/**
	 * Domains keep the filter name they have always had (singular).
	 *
	 * {@inheritDoc}
	 */
	protected function page_tabs_filter(): string {
		return 'beltoft_services_domain_page_tabs';
	}

	/**
	 * The list keeps its own template (domains.php takes over the licenses template, domains-list.php prints the rows).
	 *
	 * {@inheritDoc}
	 */
	protected function template_name(): string {
		return 'domains.php';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function list_name(): string {
		return 'domains-list.php';
	}

	/**
	 * A domain without a resource, one whose licence ended and whose service is deleted, and a DNS service folded into a registration, are history: not listed, not opened,
	 * and the address says "not found". A domain in state deleted is still listed (its state says Removed).
	 *
	 * {@inheritDoc}
	 */
	protected function visibility( ?Resource $resource ): string {
		// Also an ended licence whose service is deleted (ServiceArea::ended_and_gone()): history, the address says "not found".
		if ( ! $resource || $resource->folded_into() > 0 || self::ended_and_gone( $resource->license(), $resource ) ) {
			return self::HIDDEN;
		}

		return self::SHOWN;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function detail_classes( bool $chrome ): string {
		unset( $chrome );

		return 'beltoft-services-server beltoft-services-domain-page';
	}

	/**
	 * The tabs of a domain page are address parts, not pages below the service that another plugin draws.
	 */
	public function allows_plugin_pages(): bool {
		return false;
	}

	/**
	 * The head of a domain page: the domain name as the title, the state, no kind and no renewal (the Overview tab says both).
	 *
	 * {@inheritDoc}
	 *
	 * @param Resource             $resource Resource.
	 * @param array<string, mixed> $vars     Default values.
	 */
	protected function head_vars( Resource $resource, array $vars ): array {
		$state = (string) $resource->get( 'state' );
		$group = Panel::group( $state, $resource );

		return array(
			'title'        => HostName::display( (string) $resource->get( 'domain' ) ),
			'group'        => $group,
			'badge'        => Panel::label( $state, $resource ),
			'message'      => Panel::message( $state, $resource ),
			'show_message' => 'ready' !== $group,
			'kind'         => '',
			'renews'       => '',
		) + $vars;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param Resource             $resource Resource.
	 */
	public function row( array $view, Resource $resource ): array {
		return DomainRows::row( $view, $resource );
	}

	/**
	 * What the address after the endpoint says: the license id (from the domain's slug, or the number itself), the tab, whether it is the add page, and the parts after the service.
	 *
	 * @return array{id: int, tab: string, add: bool, path: array<int, string>}
	 */
	public function requested(): array {
		$asked = Slugs::parse( $this->slug(), $this->key() );

		return array(
			'id'   => $asked['id'],
			'tab'  => in_array( $asked['second'], $this->tabs(), true ) ? $asked['second'] : '',
			'add'  => self::ADD_VALUE === $asked['first'],
			'path' => $asked['rest'],
		);
	}

	/**
	 * The rows of the list: the customer's open DNS hosting claims first, then one row per domain.
	 *
	 * @param array<int, array<string, mixed>> $views     Domain views by license id.
	 * @param array<int, Resource>             $resources Their resources by license id.
	 * @return array<int, array<string, mixed>>
	 */
	public function rows( array $views, array $resources ): array {
		return array_merge( $this->claim_rows( get_current_user_id() ), parent::rows( $views, $resources ) );
	}

	/**
	 * Rows for the customer's open DNS hosting claims. Empty when the feature is off.
	 *
	 * @param int $user_id Customer.
	 * @return array<int, array<string, string>>
	 */
	public function claim_rows( int $user_id ): array {
		if ( $user_id <= 0 || ! DnsClaimsFeature::enabled() || ! DnsSchema::ready() ) {
			return array();
		}

		$rows = array();

		foreach ( DnsClaims::for_user( $user_id ) as $claim ) {
			if ( ! in_array( $claim['status'], array( 'pending', 'checking', 'verified' ), true ) ) {
				continue;
			}

			$rows[] = array(
				'id'     => 'claim-' . (int) $claim['id'],
				'url'    => $this->url_add(),
				'name'   => HostName::display( (string) $claim['domain'] ),
				'sub'    => __( 'DNS hosting', 'beltoft-services' ),
				'state'  => 'verified' === $claim['status'] ? __( 'Verified, finish adding', 'beltoft-services' ) : __( 'Waiting for verification', 'beltoft-services' ),
				'tone'   => 'busy',
				'renews' => '',
			);
		}

		return $rows;
	}

	/**
	 * The header buttons: label => address. Add a domain needs DNS hosting claims on; Register a domain needs a
	 * search page address in the settings.
	 *
	 * @return array<int, array{label: string, url: string}>
	 */
	public function buttons(): array {
		$buttons = array();

		if ( DnsClaimsFeature::enabled() ) {
			$buttons[] = array(
				'label' => __( 'Add a domain', 'beltoft-services' ),
				'url'   => $this->url_add(),
			);
		}

		$register = $this->url_register();

		if ( '' !== $register ) {
			$buttons[] = array(
				'label' => __( 'Register a domain', 'beltoft-services' ),
				'url'   => $register,
			);
		}

		return $buttons;
	}

	/**
	 * The add page first, else the list or the page.
	 *
	 * @param array<int, array<string, mixed>> $views License views.
	 * @param string                           $empty Text for an empty tab.
	 */
	public function render( array $views, string $empty = '' ): void {
		if ( $this->requested()['add'] ) {
			$this->add();

			return;
		}

		parent::render( $views, $empty );
	}

	/**
	 * The add page: a back link and the DNS hosting claim section.
	 */
	private function add(): void {
		echo '<div class="beltoft-services-server beltoft-services-domains-add">';
		echo '<p class="beltoft-services-server__back"><a href="' . esc_url( $this->url_list() ) . '">&lsaquo; ' . esc_html__( 'All domains', 'beltoft-services' ) . '</a></p>';
		DnsClaimPanel::render();
		echo '</div>';
	}

	/**
	 * The address of the add page.
	 */
	public function url_add(): string {
		return function_exists( 'wc_get_endpoint_url' ) ? wc_get_endpoint_url( $this->slug(), self::ADD_VALUE, wc_get_page_permalink( 'myaccount' ) ) : '';
	}

	/**
	 * The site's page for registering a new domain (setting domain_search_url), else the first domain product, or '' when there is none.
	 */
	public function url_register(): string {
		$url = self::setting_url( 'domain_search_url' );

		if ( '' === $url ) {
			$url = self::product_url( array( 'domain' ) );
		}

		/**
		 * The address of the "Register a domain" button: the domain search page setting, else the first domain
		 * product. Return '' for no button.
		 *
		 * @param string $url Address, or ''.
		 */
		return (string) apply_filters( 'beltoft_services_domains_add_url', $url );
	}

	/**
	 * The page of the domain an order is for: when the order holds exactly one domain and its service exists, that
	 * domain's page, else the list. The thank-you page and the emails link here.
	 *
	 * @param int      $order_id Order id.
	 * @param string[] $names    Domain names on the order.
	 */
	public function url_for_order( int $order_id, array $names ): string {
		$names = array_values( array_unique( array_map( 'strtolower', $names ) ) );

		if ( 1 !== count( $names ) || $order_id <= 0 || ! class_exists( Licenses::class ) ) {
			return $this->url_list();
		}

		foreach ( Licenses::where( 'order_id', $order_id ) as $license ) {
			$resource = $this->is_member( $license ) ? Resource::for_license( (int) $license['id'] ) : null;

			if ( $resource && 0 === $resource->folded_into() && strtolower( (string) $resource->get( 'domain' ) ) === $names[0] ) {
				return $this->url( $license );
			}
		}

		return $this->url_list();
	}
}
