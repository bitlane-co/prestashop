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
 * The shop's physical stores (Shop Parameters › Contact › Stores): the address
 * under the name, the stores page as the link.
 */
final class StoreSource extends OtherSource
{
    public function id(): string
    {
        return 'store';
    }

    public function label(): string
    {
        return $this->translator->trans('Stores', [], 'Modules.Bitlane.Admin');
    }

    protected function table(): array
    {
        return ['table' => 'store', 'key' => 'id_store', 'where' => '1'];
    }

    protected function document(int $id, Context $context): ?array
    {
        $store = new \Store($id, $context->languageId);
        if (!\Validate::isLoadedObject($store) || !$store->active) {
            return null;
        }
        $link = \Context::getContext()->link;
        $name = (string) $store->name;
        $address = trim(implode(', ', array_filter([(string) $store->address1, trim($store->postcode . ' ' . $store->city)])));

        return [
            'title' => $name,
            'permalink' => $link->getPageLink('stores', null, $context->languageId, null, false, $context->shopId),
            'type' => $this->translator->trans('Store', [], 'Modules.Bitlane.Admin', $this->locale($context)),
            'subtitle' => $address,
            'short_description' => Fields::text((string) $store->note, 300),
            'image' => $this->image(_PS_STORE_IMG_DIR_ . $id . '.jpg', 'stores', 250, static fn (string $type) => $link->getStoreImageLink(\Tools::str2url($name), $id, $type), $context, $name),
            'content' => Fields::text($address . ' ' . $store->note),
            'created_at' => (int) strtotime((string) $store->date_add),
            'modified_at' => (int) strtotime((string) $store->date_upd),
        ];
    }
}
