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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What every product of a shop shares in a language, read once a batch:
 * categories (names, parents), brands, attribute values (names, swatches),
 * image types, and the shop's settings for prices and stock as a guest
 * sees them (docs/prestashop-plan.md PS7).
 */
final class Catalog
{
    /** @var array<int, array{parent: int, name: string, rewrite: string, depth: int}>|null */
    private ?array $categories = null;

    /** @var array<int, array{group: int, name: string, rewrite: string, swatch: string}>|null */
    private ?array $attributes = null;

    /** @var array<int, int>|null attribute group => position */
    private ?array $groups = null;

    /** @var array<int, string> */
    private array $brands = [];

    /** @var array<int, true>|null */
    private ?array $featured = null;

    /** @var array<string, array{name: string, width: int, height: int}> */
    private array $imageTypes = [];

    /** @var array<string, bool> */
    private array $modules = [];

    public function __construct(public readonly int $shopId, public readonly int $languageId)
    {
    }

    /**
     * The shop's currency, prices' too.
     */
    public function currency(): string
    {
        return (string) (new \Currency((int) $this->config('PS_CURRENCY_DEFAULT')))->iso_code;
    }

    /**
     * Whether a guest sees prices with tax (the visitor group's display
     * method, taxes switched on).
     */
    public function taxIncluded(): bool
    {
        return (bool) $this->config('PS_TAX') && !\Group::getPriceDisplayMethod((int) $this->config('PS_UNIDENTIFIED_GROUP'));
    }

    /**
     * Whether the shop shows prices at all (catalog mode hides them).
     */
    public function showsPrices(): bool
    {
        return !$this->config('PS_CATALOG_MODE');
    }

    public function managesStock(): bool
    {
        return (bool) $this->config('PS_STOCK_MANAGEMENT');
    }

    /**
     * Whether combinations out of stock are shown (Product settings,
     * "Display unavailable attributes on the product page").
     */
    public function showsUnavailableCombinations(): bool
    {
        return (bool) $this->config('PS_DISP_UNAVAILABLE_ATTR');
    }

    /**
     * A category and its parents as filter values, without the root and Home.
     *
     * @param list<int> $ids
     *
     * @return list<string>
     */
    public function categoryValues(array $ids): array
    {
        $categories = $this->categories();
        $values = [];
        foreach ($ids as $id) {
            for ($at = $id; isset($categories[$at]) && $categories[$at]['depth'] > 1; $at = $categories[$at]['parent']) {
                $values[$at] = Fields::value($categories[$at]['name'], $categories[$at]['rewrite'], $at);
            }
        }

        return array_values($values);
    }

    /**
     * A category's parents' names from the top, without the root and Home and
     * the category itself: "Clothing › Shirts" for a category under them.
     */
    public function categoryPath(int $id): string
    {
        $categories = $this->categories();
        $names = [];
        for ($at = $categories[$id]['parent'] ?? 0; isset($categories[$at]) && $categories[$at]['depth'] > 1; $at = $categories[$at]['parent']) {
            array_unshift($names, $categories[$at]['name']);
        }

        return implode(' › ', $names);
    }

    public function brand(int $id): string
    {
        if ($id && !isset($this->brands[$id])) {
            $this->brands[$id] = (string) \Manufacturer::getNameById($id);
        }

        return $id ? $this->brands[$id] : '';
    }

    /**
     * An attribute value: its group, name, URL slug and swatch (a colour
     * group's colour, or its texture image's URL).
     *
     * @return array{group: int, name: string, rewrite: string, swatch: string}|null
     */
    public function attribute(int $id): ?array
    {
        if ($this->attributes === null) {
            $this->attributes = [];
            $rows = \Db::getInstance()->executeS(
                'SELECT a.id_attribute, a.id_attribute_group, a.color, ag.is_color_group, al.name'
                . ' FROM ' . _DB_PREFIX_ . 'attribute a'
                . ' JOIN ' . _DB_PREFIX_ . 'attribute_group ag ON ag.id_attribute_group = a.id_attribute_group'
                . ' JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $this->languageId,
                true,
                false
            ) ?: [];
            foreach ($rows as $row) {
                $attribute = (int) $row['id_attribute'];
                $swatch = '';
                if ($row['is_color_group']) {
                    $swatch = is_file(_PS_COL_IMG_DIR_ . $attribute . '.jpg')
                        ? \Context::getContext()->link->getMediaLink(_THEME_COL_DIR_ . $attribute . '.jpg')
                        : (preg_match('/^#[0-9a-f]{3,6}$/i', (string) $row['color']) ? (string) $row['color'] : '');
                }
                $this->attributes[$attribute] = ['group' => (int) $row['id_attribute_group'], 'name' => (string) $row['name'], 'rewrite' => \Tools::str2url((string) $row['name']), 'swatch' => $swatch];
            }
        }

        return $this->attributes[$id] ?? null;
    }

    /**
     * An attribute group's position, for the order of a combination's name.
     */
    public function groupPosition(int $group): int
    {
        if ($this->groups === null) {
            $this->groups = array_map('intval', array_column(\Db::getInstance()->executeS('SELECT id_attribute_group, position FROM ' . _DB_PREFIX_ . 'attribute_group', true, false) ?: [], 'position', 'id_attribute_group'));
        }

        return $this->groups[$group] ?? 0;
    }

    /**
     * Whether the product is in the category ps_featuredproducts shows on the
     * home page, when that module is on.
     */
    public function featured(int $productId): bool
    {
        if ($this->featured === null) {
            $this->featured = [];
            $category = (int) $this->config('HOME_FEATURED_CAT');
            if ($category && $this->moduleActive('ps_featuredproducts')) {
                $ids = \Db::getInstance()->executeS('SELECT id_product FROM ' . _DB_PREFIX_ . 'category_product WHERE id_category = ' . $category, true, false) ?: [];
                $this->featured = array_fill_keys(array_map('intval', array_column($ids, 'id_product')), true);
            }
        }

        return isset($this->featured[$productId]);
    }

    /**
     * The productcomments module's validated grades: their average and count;
     * null without the module or grades.
     *
     * @return array{rating: float, count: int}|null
     */
    public function rating(int $productId): ?array
    {
        if (!$this->moduleActive('productcomments')) {
            return null;
        }
        $row = \Db::getInstance()->getRow(
            'SELECT AVG(grade) AS rating, COUNT(*) AS count FROM ' . _DB_PREFIX_ . 'product_comment'
            . ' WHERE id_product = ' . $productId . ' AND validate = 1 AND deleted = 0',
            false
        );

        return is_array($row) && (int) $row['count'] > 0 ? ['rating' => round((float) $row['rating'], 2), 'count' => (int) $row['count']] : null;
    }

    /**
     * The image type of an entity's cards: the one nearest $width wide.
     *
     * @param string $entity products, categories, manufacturers, stores
     *
     * @return array{shown: array{name: string, width: int, height: int}, types: list<array{name: string, width: int, height: int}>}|null
     */
    public function imageTypes(string $entity, int $width): ?array
    {
        $types = array_values(array_map(
            static fn (array $type) => ['name' => (string) $type['name'], 'width' => (int) $type['width'], 'height' => (int) $type['height']],
            \ImageType::getImagesTypes($entity)
        ));
        if (!$types) {
            return null;
        }
        if (!isset($this->imageTypes[$entity])) {
            usort($types, static fn ($a, $b) => abs($a['width'] - $width) <=> abs($b['width'] - $width));
            $this->imageTypes[$entity] = $types[0];
        }

        return ['shown' => $this->imageTypes[$entity], 'types' => $types];
    }

    /**
     * @return array<int, array{parent: int, name: string, rewrite: string, depth: int}>
     */
    private function categories(): array
    {
        if ($this->categories === null) {
            $rows = \Db::getInstance()->executeS(
                'SELECT c.id_category, c.id_parent, c.level_depth, cl.name, cl.link_rewrite FROM ' . _DB_PREFIX_ . 'category c'
                . ' JOIN ' . _DB_PREFIX_ . 'category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = ' . $this->languageId . ' AND cl.id_shop = ' . $this->shopId,
                true,
                false
            ) ?: [];
            $this->categories = [];
            foreach ($rows as $row) {
                $this->categories[(int) $row['id_category']] = ['parent' => (int) $row['id_parent'], 'name' => (string) $row['name'], 'rewrite' => (string) $row['link_rewrite'], 'depth' => (int) $row['level_depth']];
            }
        }

        return $this->categories;
    }

    /**
     * Whether a module is on in this shop. Not Module::isEnabled(): it keeps
     * its first answer for every shop (and is deprecated in 9 for a service
     * the runner has no container for).
     */
    private function moduleActive(string $name): bool
    {
        if (!isset($this->modules[$name])) {
            $id = (int) \Module::getModuleIdByName($name);
            $this->modules[$name] = $id && (bool) \Db::getInstance()->getValue(
                'SELECT 1 FROM ' . _DB_PREFIX_ . 'module_shop WHERE id_module = ' . $id . ' AND id_shop = ' . $this->shopId,
                false
            );
        }

        return $this->modules[$name];
    }

    private function config(string $key): mixed
    {
        return \Configuration::get($key, null, null, $this->shopId);
    }
}
