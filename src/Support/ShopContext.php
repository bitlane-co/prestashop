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

namespace Bitlane\PrestaShop\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Runs work as a shop's guest would see it, in one of its languages: the
 * shop, language, default currency and country, which links, prices and
 * names read from PrestaShop's Context. Jobs run outside a page (the
 * runner, the console), where the context is the default shop's and has no
 * employee: Product::getPriceStatic refuses to work without an employee or
 * a cart, so an empty employee stands in. The context is restored after.
 */
final class ShopContext
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function in(int $shopId, int $languageId, callable $work): mixed
    {
        $context = \Context::getContext();
        $saved = [$context->shop, $context->language, $context->currency, $context->country, $context->link, $context->employee];
        $type = \Shop::getContext();
        $savedShop = match ($type) {
            \Shop::CONTEXT_SHOP => [$type, (int) ($saved[0]->id ?? \Configuration::get('PS_SHOP_DEFAULT'))],
            \Shop::CONTEXT_GROUP => [$type, (int) \Shop::getContextShopGroupID()],
            default => [\Shop::CONTEXT_ALL, null],
        };

        \Shop::setContext(\Shop::CONTEXT_SHOP, $shopId);
        $context->shop = new \Shop($shopId);
        $context->language = new \Language($languageId);
        $context->currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $shopId));
        $context->country = new \Country((int) \Configuration::get('PS_COUNTRY_DEFAULT', null, null, $shopId));
        // Outside a page Link can't tell the protocol, and gives image URLs none.
        $protocol = \Configuration::get('PS_SSL_ENABLED', null, null, $shopId) ? 'https://' : 'http://';
        $context->link = new \Link($protocol, $protocol);
        $context->employee ??= new \Employee();
        try {
            return $work();
        } finally {
            [$context->shop, $context->language, $context->currency, $context->country, $context->link, $context->employee] = $saved;
            \Shop::setContext($savedShop[0], $savedShop[1]);
        }
    }
}
