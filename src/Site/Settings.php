<?php

/**
 * Bitlane Search for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from NMC, in the file
 * LICENSE.md that comes with it. Use, copy, modification or distribution of
 * this file or its parts without NMC's written permission is not allowed.
 *
 * @author    Bitlane <https://bitlane.co>
 * @copyright Since 2026 NMC
 * @license   Commercial license, see LICENSE.md
 */

namespace Bitlane\PrestaShop\Site;

use Bitlane\PrestaShop\Support\State;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A shop's choices on the setup screen, kept to show them again and for the
 * sync: the engine, the content and filters, the themes' search fields, the
 * brand, and product_documents, how a product becomes search documents
 * (docs/prestashop-plan.md PS6).
 */
final class Settings
{
    private const NAME = 'settings';

    /**
     * One document per product, its combinations folded in: the fewest
     * documents, but filters combine values of different combinations.
     */
    public const PER_PRODUCT = 'per_product';

    /**
     * One document per combination: colour, size and stock filter exactly,
     * with more documents (counted in the plan's records). The default.
     */
    public const PER_VARIATION = 'per_variation';

    public const PRODUCT_DOCUMENTS = [self::PER_PRODUCT, self::PER_VARIATION];

    public function __construct(private State $state, private Site $site)
    {
    }

    /**
     * The shop's settings, the defaults for what isn't set.
     *
     * @return array{engine_id: ?string, sources: list<string>, outside_widget: list<string>, facets: list<string>, sorting: array<string, list<string>>, search_selector: string, brand_color: string, logo_url: string, product_documents: string, languages: list<string>}
     */
    public function get(int $shopId): array
    {
        return array_merge(
            [
                'engine_id' => null,
                // Sources synced for the shop's own use, which widgets don't search.
                'outside_widget' => [],
                // Each source's sort choices, in order (Site::sorting()); a
                // source without an entry has its kind's defaults.
                'sorting' => [],
                'product_documents' => self::PER_VARIATION,
                // The languages Bitlane was last sent.
                'languages' => [],
            ],
            $this->site->defaultSetup($shopId),
            $this->state->json(self::NAME, $shopId)
        );
    }

    /**
     * What a source's documents depend on, as a short hash: a new one means a
     * full sync of the source. Its picked filters, the languages (a new one
     * has an empty index), and for products how they become documents.
     */
    public function hash(int $shopId, string $source): string
    {
        $settings = $this->get($shopId);
        $facets = array_values(array_intersect($settings['facets'], array_column($this->site->facets([$source]), 'attribute')));

        return md5((string) json_encode([$source, $facets, $settings['languages'], $source === 'product' ? $settings['product_documents'] : null]));
    }

    /**
     * Whether the shop's setup was sent to Bitlane.
     */
    public function saved(int $shopId): bool
    {
        return $this->state->get(self::NAME, $shopId) !== null;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function save(int $shopId, array $settings): void
    {
        $this->state->setJson(self::NAME, $settings, $shopId);
    }
}
