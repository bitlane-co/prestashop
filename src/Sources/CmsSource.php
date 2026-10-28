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
 * The shop's CMS pages: their category above the title ("Page" for those at
 * the top) and as a filter (cms_category), their text.
 */
final class CmsSource extends OtherSource
{
    public function id(): string
    {
        return 'cms';
    }

    public function label(): string
    {
        return $this->translator->trans('Pages', [], 'Modules.Bitlane.Admin');
    }

    public function facets(int $languageId): array
    {
        return ['cms_category' => $this->translator->trans('Page category', [], 'Modules.Bitlane.Admin', (string) (new \Language($languageId))->locale)];
    }

    protected function table(): array
    {
        return ['table' => 'cms', 'key' => 'id_cms', 'where' => '1'];
    }

    protected function document(int $id, Context $context): ?array
    {
        $page = new \CMS($id, $context->languageId, $context->shopId);
        if (!\Validate::isLoadedObject($page) || !$page->active) {
            return null;
        }
        // The root CMS category ("Home") is no category to a shopper.
        $category = (int) $page->id_cms_category > 1 ? new \CMSCategory((int) $page->id_cms_category, $context->languageId, $context->shopId) : null;
        $categoryName = $category ? (string) $category->name : '';
        $content = (string) $page->content;

        return [
            'title' => (string) $page->meta_title,
            'permalink' => \Context::getContext()->link->getCMSLink($page, null, null, $context->languageId, $context->shopId),
            'type' => $categoryName !== '' ? $categoryName : $this->translator->trans('Page', [], 'Modules.Bitlane.Admin', $this->locale($context)),
            'short_description' => Fields::text((string) $page->meta_description !== '' ? (string) $page->meta_description : $content, 300),
            'content' => Fields::text($content),
            'facets' => (object) ($category ? ['cms_category' => [Fields::value($categoryName, (string) $category->link_rewrite, (int) $category->id)]] : []),
            'menu_order' => (int) $page->position,
        ];
    }
}
