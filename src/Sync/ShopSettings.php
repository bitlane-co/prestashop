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

namespace Bitlane\PrestaShop\Sync;

use Bitlane\PrestaShop\Site\Settings;
use Bitlane\PrestaShop\Site\Site;
use Bitlane\PrestaShop\Support\State;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Shop settings that change every document, which no hook announces
 * (docs/prestashop-plan.md Part 3): prices and stock as guests see them
 * change every product's, URLs every source's (the shop's own address
 * included: a shop moved to another domain or path). Their hash is compared
 * on back-office pages (at most hourly) and by the daily check; a change
 * starts the full syncs it needs, and a new address or name goes to Bitlane
 * too (SiteDetails).
 */
final class ShopSettings
{
    private const NAME = 'shop_settings';

    /** What every product's documents depend on. */
    private const PRODUCTS = [
        'PS_TAX', 'PS_CATALOG_MODE', 'PS_STOCK_MANAGEMENT', 'PS_ORDER_OUT_OF_STOCK', 'PS_DISP_UNAVAILABLE_ATTR',
        'PS_CURRENCY_DEFAULT', 'PS_COUNTRY_DEFAULT', 'PS_UNIDENTIFIED_GROUP', 'HOME_FEATURED_CAT',
    ];

    /** What every document's link and image depend on. */
    private const LINKS = ['PS_REWRITING_SETTINGS', 'PS_SSL_ENABLED', 'PS_ROUTE_product_rule', 'PS_ROUTE_category_rule', 'PS_ROUTE_cms_rule', 'PS_ROUTE_manufacturer_rule'];

    public function __construct(
        private State $state,
        private Settings $settings,
        private FullSync $full,
        private Site $site,
        private SiteDetails $siteDetails,
    ) {
    }

    /**
     * Starts the full syncs the shop's changed settings need; at most hourly
     * unless $now.
     */
    public function check(int $shopId, bool $now = true): void
    {
        $kept = $this->state->json(self::NAME, $shopId);
        if (!$now && (int) ($kept['at'] ?? 0) > time() - 3600) {
            return;
        }
        $this->siteDetails->check($shopId);
        $hashes = ['products' => $this->hash($shopId, self::PRODUCTS, true), 'links' => $this->hash($shopId, self::LINKS, false)];
        $this->state->setJson(self::NAME, $hashes + ['at' => time()], $shopId);
        if (!isset($kept['products'])) {
            return;
        }
        $sources = $this->settings->get($shopId)['sources'];
        if ($kept['links'] !== $hashes['links']) {
            array_map(fn ($source) => $this->full->start($shopId, $source), $sources);
        } elseif ($kept['products'] !== $hashes['products'] && in_array('product', $sources, true)) {
            $this->full->start($shopId, 'product');
        }
    }

    /**
     * Notes the settings as synced: the shop's documents were just made for them.
     */
    public function remember(int $shopId): void
    {
        $this->state->setJson(self::NAME, [
            'products' => $this->hash($shopId, self::PRODUCTS, true),
            'links' => $this->hash($shopId, self::LINKS, false),
            'at' => time(),
        ], $shopId);
    }

    /**
     * @param list<string> $keys
     */
    private function hash(int $shopId, array $keys, bool $products): string
    {
        $values = array_map(static fn ($key) => \Configuration::get($key, null, null, $shopId), $keys);
        if (!$products) {
            // The shop's own address: its domain and path (a multistore shop's /it-store).
            $values[] = $this->site->url($shopId);
        }
        if ($products) {
            // The visitors' group shows prices with or without tax, and image types decide the cards' images.
            $values[] = \Group::getPriceDisplayMethod((int) \Configuration::get('PS_UNIDENTIFIED_GROUP', null, null, $shopId));
            $values[] = array_column(\ImageType::getImagesTypes('products'), 'width', 'name');
        }

        return md5((string) json_encode($values));
    }
}
