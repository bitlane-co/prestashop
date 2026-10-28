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

namespace Bitlane\PrestaShop\Sources;

use Bitlane\PrestaShop\Documents\ProductDocuments;
use Bitlane\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The shop's products (ProductDocuments): those a guest may find are walked,
 * the others give no documents.
 */
final class ProductSource implements Source
{
    public function __construct(private ProductDocuments $builder, private Translator $translator)
    {
    }

    public function id(): string
    {
        return 'product';
    }

    public function label(): string
    {
        return $this->translator->trans('Products', [], 'Modules.Bitlane.Admin');
    }

    public function kind(): string
    {
        return self::PRODUCTS;
    }

    public function count(int $shopId): int
    {
        return (int) \Db::getInstance()->getValue('SELECT COUNT(*) ' . $this->from($shopId), false);
    }

    /**
     * Price, availability, categories, brand, each attribute group (colours,
     * sizes: combinations) and each feature.
     */
    public function facets(int $languageId): array
    {
        $locale = (string) (new \Language($languageId))->locale;
        $facets = [
            'price' => $this->translator->trans('Price', [], 'Modules.Bitlane.Admin', $locale),
            ProductDocuments::STOCK_STATUS => $this->translator->trans('Availability', [], 'Modules.Bitlane.Admin', $locale),
            'category' => $this->translator->trans('Category', [], 'Modules.Bitlane.Admin', $locale),
            'brand' => $this->translator->trans('Brand', [], 'Modules.Bitlane.Admin', $locale),
        ];
        foreach (\AttributeGroup::getAttributesGroups($languageId) as $group) {
            $facets['attribute_' . $group['id_attribute_group']] = (string) $group['public_name'];
        }
        foreach (\Feature::getFeatures($languageId) as $feature) {
            $facets['feature_' . $feature['id_feature']] = (string) $feature['name'];
        }

        return $facets;
    }

    public function ids(int $shopId, string $after, int $limit): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT id_product ' . $this->from($shopId) . ' AND id_product > ' . (int) $after
            . ' ORDER BY id_product LIMIT ' . $limit,
            true,
            false
        ) ?: [];

        return array_map('strval', array_column($rows, 'id_product'));
    }

    public function documents(array $ids, Context $context): array
    {
        $documents = [];
        foreach ($ids as $id) {
            $documents[$id] = $this->builder->build((int) $id, $context);
        }

        return $documents;
    }

    public function modified(int $shopId, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT id_product, date_upd FROM ' . _DB_PREFIX_ . 'product WHERE id_product IN (' . implode(',', array_map('intval', $ids)) . ')',
            true,
            false
        ) ?: [];

        return array_map(static fn ($date) => (int) strtotime((string) $date), array_column($rows, 'date_upd', 'id_product'));
    }

    /**
     * The products a guest may find, for a SELECT.
     */
    private function from(int $shopId): string
    {
        return 'FROM ' . _DB_PREFIX_ . 'product_shop'
            . ' WHERE id_shop = ' . $shopId . " AND active = 1 AND visibility IN ('both', 'search')";
    }
}
