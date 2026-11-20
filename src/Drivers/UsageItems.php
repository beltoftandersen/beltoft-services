<?php
/**
 * A driver whose resources are billed by the pay as you go engine: the one hook-in point of the usage engine.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The usage engine (src/Usage) never names a product. A driver that can be sold pay as you go implements this and the engine asks
 * it: which hourly items its resource is billed for (the ledger item names, quantities, units and rates), the words of those items
 * on an invoice, whether it is a service of its own or an add-on of one, and how it is called on an invoice. `UsageDefaults` gives the defaults of a plain service.
 */
interface UsageItems {

	/**
	 * What a resource is billed for each hour: items with quantity, unit and rate per unit and hour. The rate source and the cap
	 * (the product premium) are the driver's own.
	 *
	 * @param Resource              $r       Resource.
	 * @param \WC_Product_Variation $v       The licence's pay as you go variation.
	 * @param float                 $monthly Its monthly price (the monthly sibling's, excluding tax).
	 * @param float                 $premium Product premium in percent.
	 * @return array<int, array{item: string, quantity: float, unit: string, rate: float}>
	 */
	public function usage_items( Resource $r, \WC_Product_Variation $v, float $monthly, float $premium ): array;

	/**
	 * The words of the items this driver bills: item => label (lowercase, an item name in a sentence), word (capitalised, a line title),
	 * and optionally `plan` (true: the line is titled with the product and plan, as a server or database is) and `subject` (a
	 * format with %s for the service name, "Server %s").
	 *
	 * @return array<string, array{label: string, word: string, plan?: bool, subject?: string}>
	 */
	public function usage_words(): array;

	/**
	 * Is a resource of this driver an add-on that belongs to another service (an extra disk, an address, a licence), not a service of its own?
	 */
	public function usage_is_addon(): bool;

	/**
	 * What this driver adds to the cart breakdown of a pay as you go line, or null for nothing. Keys: `parts` (callable(
	 * \WC_Product_Variation $usage, callable $price ): array{base, ip, storage, backups, gb}, the monthly
	 * amounts of a line this driver sells: it makes the driver a line of the breakdown), `single_line` (the plan and its add-ons are one
	 * ledger item), `data_disk` (the disk is worded as a data disk), and `extra` (an add-on that has a row of its own in the quote of a
	 * new service: key, label, sized, size_key).
	 *
	 * @return array<string, mixed>|null
	 */
	public function usage_breakdown(): ?array;

	/**
	 * How the customer deletes a pay as you go resource of this driver, or null when it cannot be deleted here. Keys: `kind` (the word
	 * pages and tools use), `text`, `billing`, `stopped` (strings), `busy` (why it cannot be deleted while it works), optional
	 * `early` (callable( Resource ): ?string, a refusal checked before busy), `idle` (callable( Resource ): bool, replaces the
	 * ready and nothing-running test), `blocker` (callable( Resource ): ?string, a refusal after it), `word` (callable( Resource ): string,
	 * the word to type, '' for the default) and `licence` (callable( Resource ): string, a line about a licence kept).
	 *
	 * @return array<string, mixed>|null
	 */
	public function usage_delete(): ?array;

	/**
	 * What a resource is called on an invoice, or null for the default (the server's name, else the product's).
	 *
	 * @param Resource $r Resource.
	 */
	public function usage_label( Resource $r ): ?string;

	/**
	 * The stable reference on an invoice line (vm-<uuid>), or null for the default.
	 *
	 * @param Resource $r Resource.
	 */
	public function usage_reference( Resource $r ): ?string;
}
