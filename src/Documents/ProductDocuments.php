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

namespace Bitlane\PrestaShop\Documents;

use Bitlane\PrestaShop\Site\Settings;
use Bitlane\PrestaShop\Sources\Context;
use Bitlane\PrestaShop\Support\Hooks;
use Bitlane\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A product's documents (widget_public.ProductDocumentV1; docs/prestashop-plan.md
 * PS6–PS8), as a guest of the shop sees it in the context's language: one
 * per combination, or one for the whole product, as the setup says; a
 * product without combinations is one. Ids are "<product>-<combination>"
 * ("<product>-0" for a whole product), parent_id the product's.
 *
 * A product a guest can't find has none: inactive, not shown in search
 * (visibility catalog or none), or only in categories closed to visitors.
 * Combinations out of stock are left out when the shop doesn't show them.
 * Prices are left out when the shop hides them (the product's show_price,
 * catalog mode). Developers change a document in the hook
 * actionBitlaneProductDocument (document by reference, null leaves it out).
 */
final class ProductDocuments
{
    /** The availability filter: in stock, on backorder, out of stock. */
    public const STOCK_STATUS = 'stock_status';

    private const STATUS_COLORS = ['instock' => '#16a34a', 'onbackorder' => '#f59e0b', 'outofstock' => '#dc2626'];

    public function __construct(private Translator $translator)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function build(int $id, Context $context): array
    {
        $catalog = $context->catalog;
        $product = new \Product($id, false, $context->languageId, $context->shopId);
        if (!\Validate::isLoadedObject($product) || !$this->searchable($product)) {
            return [];
        }

        // Each combination's attribute values by group.
        $combinations = [];
        foreach ($product->getAttributeCombinations($context->languageId) as $row) {
            $combinations[(int) $row['id_product_attribute']]['reference'] = (string) $row['reference'];
            $combinations[(int) $row['id_product_attribute']]['values'][(int) $row['id_attribute_group']] = (int) $row['id_attribute'];
        }
        $status = $this->stockStatus($product, $context);
        if (!$catalog->showsUnavailableCombinations()) {
            $combinations = array_filter($combinations, static fn ($c, $ipa) => $status($ipa) !== 'outofstock', ARRAY_FILTER_USE_BOTH);
        }

        $features = [];
        foreach (\Product::getFrontFeaturesStatic($context->languageId, $id) as $feature) {
            $features['feature_' . $feature['id_feature']][] = Fields::value((string) $feature['value'], '', (int) $feature['id_feature_value']);
        }
        $own = Fields::merge(
            ['category' => $catalog->categoryValues(array_map('intval', \Product::getProductCategories($id)))],
            $product->id_manufacturer ? ['brand' => [Fields::value($catalog->brand((int) $product->id_manufacturer), '', (int) $product->id_manufacturer)]] : [],
            $features
        );
        $own = array_filter($own);

        $images = new ProductImages($product, $context);
        if (!$combinations) {
            $facets = Fields::merge($own, $this->statusValue($status(0), $context));

            return $this->filtered([$this->document($product, 0, $facets, $facets, $status(0) !== 'outofstock' ? $facets : [], [0], $images, $context)]);
        }

        $choices = [];
        foreach ($combinations as $ipa => $combination) {
            $values = [];
            foreach ($combination['values'] as $group => $attribute) {
                $found = $catalog->attribute($attribute);
                if ($found) {
                    $values['attribute_' . $group] = [Fields::value($found['name'], $found['rewrite'], $attribute, $found['swatch'])];
                }
            }
            $choices[$ipa] = Fields::merge($values, $this->statusValue($status($ipa), $context));
        }
        $all = Fields::merge($own, ...array_values($choices));
        $inStock = array_keys(array_filter($choices, static fn ($c, $ipa) => $status($ipa) !== 'outofstock', ARRAY_FILTER_USE_BOTH));

        if (Settings::PER_PRODUCT === $context->productDocuments) {
            $available = $inStock ? Fields::merge($own, ...array_map(static fn ($ipa) => $choices[$ipa], $inStock)) : [];

            return $this->filtered([$this->document($product, 0, $all, $all, $available, array_keys($combinations), $images, $context)]);
        }

        $documents = [];
        foreach (array_keys($combinations) as $ipa) {
            $facets = Fields::merge($own, $choices[$ipa]);
            $documents[] = $this->document($product, $ipa, $facets, $all, in_array($ipa, $inStock, true) ? $facets : [], [$ipa], $images, $context);
        }

        return $this->filtered($documents);
    }

    /**
     * Whether a guest can find the product: active, shown in search, in a
     * category open to visitors. Developers decide otherwise in the hook
     * actionBitlaneProductIsSearchable (searchable by reference).
     */
    public function searchable(\Product $product): bool
    {
        $searchable = $product->active
            && in_array($product->visibility, ['both', 'search'], true)
            && \Product::checkAccessStatic((int) $product->id, false);

        return (bool) Hooks::filter('actionBitlaneProductIsSearchable', 'searchable', $searchable, ['product' => $product]);
    }

    /**
     * A combination's stock status (0 for the product itself): instock while
     * it has some or the shop doesn't manage stock, onbackorder when it's sold
     * out but orderable, else outofstock.
     *
     * @return callable(int): string
     */
    private function stockStatus(\Product $product, Context $context): callable
    {
        if (!$context->catalog->managesStock()) {
            return static fn (int $ipa) => 'instock';
        }
        $orderable = \Product::isAvailableWhenOutOfStock(\StockAvailable::outOfStock((int) $product->id, $context->shopId));

        return static fn (int $ipa) => \StockAvailable::getQuantityAvailableByProduct((int) $product->id, $ipa, $context->shopId) > 0
            ? 'instock'
            : ($orderable ? 'onbackorder' : 'outofstock');
    }

    /**
     * A stock status as a filter value, named in the document's language.
     *
     * @return array<string, list<string>>
     */
    private function statusValue(string $status, Context $context): array
    {
        $locale = (string) (new \Language($context->languageId))->locale;
        $names = [
            'instock' => $this->translator->trans('In stock', [], 'Modules.Bitlane.Admin', $locale),
            'onbackorder' => $this->translator->trans('On backorder', [], 'Modules.Bitlane.Admin', $locale),
            'outofstock' => $this->translator->trans('Out of stock', [], 'Modules.Bitlane.Admin', $locale),
        ];

        return [self::STOCK_STATUS => [Fields::value($names[$status], $status, $status, self::STATUS_COLORS[$status])]];
    }

    /**
     * A document of the product (combination 0) or one combination.
     *
     * @param array<string, list<string>> $facets the document's values
     * @param array<string, list<string>> $all the whole product's values
     * @param array<string, list<string>> $available its values when in stock, else none
     * @param list<int> $priced the combinations its prices range over (0: the product)
     *
     * @return array<string, mixed>|null
     */
    private function document(\Product $product, int $ipa, array $facets, array $all, array $available, array $priced, ProductImages $images, Context $context): ?array
    {
        $catalog = $context->catalog;
        $id = (int) $product->id;
        $link = \Context::getContext()->link;
        $currency = $catalog->currency();

        $prices = $regular = [];
        foreach ($priced as $at) {
            $combination = $at ?: null;
            $prices[] = Fields::minor((float) \Product::getPriceStatic($id, $catalog->taxIncluded(), $combination, 6, null, false, true), $currency);
            $regular[] = Fields::minor((float) \Product::getPriceStatic($id, $catalog->taxIncluded(), $combination, 6, null, false, false), $currency);
        }
        $showsPrice = $catalog->showsPrices() && $product->show_price;
        $rating = $catalog->rating($id);
        $reference = $ipa ? (string) \Db::getInstance()->getValue('SELECT reference FROM ' . _DB_PREFIX_ . 'product_attribute WHERE id_product_attribute = ' . $ipa, false) : '';

        $doc = [
            'id' => $id . '-' . $ipa,
            'parent_id' => (string) $id,
            'title' => (string) $product->name,
            'sku' => (string) $product->reference,
            'variant_title' => $ipa ? $this->variantTitle($facets, $catalog) : null,
            'variant_sku' => $reference !== '' && $reference !== $product->reference ? $reference : null,
            'codes' => $this->codes($product, $priced),
            'permalink' => $link->getProductLink($product, null, null, null, $context->languageId, $context->shopId, $ipa),
            'image' => $images->of($ipa),
            'brand' => $catalog->brand((int) $product->id_manufacturer) ?: null,
            'price' => $showsPrice ? ['min' => min($prices), 'max' => max($prices)] : null,
            'regular_price' => $showsPrice ? ['min' => min($regular), 'max' => max($regular)] : null,
            'short_description' => Fields::text((string) $product->description_short),
            'description' => Fields::text((string) $product->description),
            'facets' => (object) $facets,
            'product_facets' => (object) $all,
            'available' => (object) $available,
            'flags' => [
                'in_stock' => (bool) $available,
                'on_sale' => (bool) $product->on_sale || min($prices) < min($regular),
                'featured' => $catalog->featured($id),
            ],
            'total_sales' => max(0, (int) \ProductSale::getNbrSales($id)),
            'rating' => $rating['rating'] ?? null,
            'rating_count' => $rating['count'] ?? null,
            'created_at' => (int) strtotime((string) $product->date_add),
            'modified_at' => (int) strtotime((string) $product->date_upd),
            'menu_order' => (int) \Db::getInstance()->getValue(
                'SELECT position FROM ' . _DB_PREFIX_ . 'category_product WHERE id_category = ' . (int) $product->id_category_default . ' AND id_product = ' . $id,
                false
            ),
        ];
        $doc = Hooks::filter('actionBitlaneProductDocument', 'document', array_filter($doc, static fn ($value) => $value !== null && $value !== ''), [
            'product' => $product,
            'id_product_attribute' => $ipa,
            'id_lang' => $context->languageId,
            'id_shop' => $context->shopId,
        ]);

        return is_array($doc) ? $doc : null;
    }

    /**
     * The codes shoppers type (EAN-13, UPC, ISBN, MPN): the product's and
     * those of the combinations the document covers; null without any.
     *
     * @param list<int> $combinations the document's (0: the product alone)
     *
     * @return list<string>|null
     */
    private function codes(\Product $product, array $combinations): ?array
    {
        $codes = [$product->ean13, $product->upc, $product->isbn, $product->mpn];
        $ids = array_filter($combinations);
        if ($ids) {
            $rows = \Db::getInstance()->executeS(
                'SELECT ean13, upc, isbn, mpn FROM ' . _DB_PREFIX_ . 'product_attribute WHERE id_product_attribute IN (' . implode(',', array_map('intval', $ids)) . ')',
                true,
                false
            ) ?: [];
            foreach ($rows as $row) {
                array_push($codes, ...array_values($row));
            }
        }
        $codes = array_values(array_unique(array_filter(array_map(static fn ($code) => trim((string) $code), $codes), static fn ($code) => $code !== '')));

        return $codes ?: null;
    }

    /**
     * Which of the product a combination is: "Red / XL", its values in their
     * groups' order.
     *
     * @param array<string, list<string>> $facets
     */
    private function variantTitle(array $facets, Catalog $catalog): string
    {
        $names = [];
        foreach ($facets as $attribute => $values) {
            if (str_starts_with($attribute, 'attribute_')) {
                $names[$catalog->groupPosition((int) substr($attribute, 10))] = explode('---', $values[0])[0];
            }
        }
        ksort($names);

        return implode(' / ', $names);
    }

    /**
     * @param list<array<string, mixed>|null> $documents
     *
     * @return list<array<string, mixed>>
     */
    private function filtered(array $documents): array
    {
        return array_values(array_filter($documents, 'is_array'));
    }
}
