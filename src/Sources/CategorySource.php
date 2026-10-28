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

use Bitlane\PrestaShop\Documents\Fields;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The shop's categories (not the root and Home) that visitors may open, as
 * results of their own: "Category", the path above it, its description.
 */
final class CategorySource extends OtherSource
{
    public function id(): string
    {
        return 'category';
    }

    public function label(): string
    {
        return $this->translator->trans('Categories', [], 'Modules.Bitlane.Admin');
    }

    protected function table(): array
    {
        return ['table' => 'category', 'key' => 'id_category', 'where' => 't.level_depth > 1'];
    }

    protected function document(int $id, Context $context): ?array
    {
        $category = new \Category($id, $context->languageId, $context->shopId);
        $visitors = (int) \Configuration::get('PS_UNIDENTIFIED_GROUP', null, null, $context->shopId);
        $open = (bool) \Db::getInstance()->getValue('SELECT 1 FROM ' . _DB_PREFIX_ . 'category_group WHERE id_category = ' . $id . ' AND id_group = ' . $visitors, false);
        if (!\Validate::isLoadedObject($category) || !$category->active || $category->level_depth < 2 || !$open) {
            return null;
        }
        $link = \Context::getContext()->link;
        $rewrite = (string) $category->link_rewrite;

        return [
            'title' => (string) $category->name,
            'permalink' => $link->getCategoryLink($category, null, $context->languageId, null, $context->shopId),
            'type' => $this->translator->trans('Category', [], 'Modules.Bitlane.Admin', $this->locale($context)),
            'subtitle' => $context->catalog->categoryPath($id),
            'short_description' => Fields::text((string) $category->description, 300),
            'image' => $this->image(_PS_CAT_IMG_DIR_ . $id . '.jpg', 'categories', 250, static fn (string $type) => $link->getCatImageLink($rewrite, $id, $type), $context, (string) $category->name),
            'content' => Fields::text((string) $category->description),
            'created_at' => (int) strtotime((string) $category->date_add),
            'modified_at' => (int) strtotime((string) $category->date_upd),
            'menu_order' => (int) $category->position,
        ];
    }
}
