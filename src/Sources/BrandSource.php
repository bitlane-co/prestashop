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
 * The shop's brands (manufacturers): their logo and descriptions.
 */
final class BrandSource extends OtherSource
{
    public function id(): string
    {
        return 'brand';
    }

    public function label(): string
    {
        return $this->translator->trans('Brands', [], 'Modules.Bitlane.Admin');
    }

    protected function table(): array
    {
        return ['table' => 'manufacturer', 'key' => 'id_manufacturer', 'where' => '1'];
    }

    protected function document(int $id, Context $context): ?array
    {
        $brand = new \Manufacturer($id, $context->languageId);
        if (!\Validate::isLoadedObject($brand) || !$brand->active) {
            return null;
        }
        $link = \Context::getContext()->link;

        return [
            'title' => (string) $brand->name,
            'permalink' => $link->getManufacturerLink($brand, null, $context->languageId, $context->shopId),
            'type' => $this->translator->trans('Brand', [], 'Modules.Bitlane.Admin', $this->locale($context)),
            'short_description' => Fields::text((string) $brand->short_description, 300),
            'image' => $this->image(_PS_MANU_IMG_DIR_ . $id . '.jpg', 'manufacturers', 250, static fn (string $type) => $link->getManufacturerImageLink($id, $type), $context, (string) $brand->name),
            'content' => Fields::text((string) $brand->description),
            'created_at' => (int) strtotime((string) $brand->date_add),
            'modified_at' => (int) strtotime((string) $brand->date_upd),
        ];
    }
}
