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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A kind of content whose items become search documents, each source with its
 * own index per language (docs/prestashop-plan.md PS5): products, categories,
 * CMS pages, brands and stores are built in, and modules add theirs from the
 * hook actionBitlaneDocumentSources. Every item is in every language
 * (PrestaShop translates fields in place), so ids don't depend on it. A
 * module reports its items' changes with the hook actionBitlaneItemsChanged.
 */
interface Source
{
    public const PRODUCTS = 'products';
    public const OTHER = 'other';

    /**
     * Lowercase letters, digits, - and _ ("product"): in index names.
     */
    public function id(): string;

    /**
     * Its name on the setup screen ("Stores"), in the employee's language.
     */
    public function label(): string;

    /**
     * Source::PRODUCTS (documents of widget_public.ProductDocumentV1) or
     * Source::OTHER (OtherDocumentV1).
     */
    public function kind(): string;

    /**
     * How many items a shopper can find in the shop (the setup screen, a
     * full sync's progress).
     */
    public function count(int $shopId): int;

    /**
     * The filters its documents can have (each document's
     * facets.<attribute>), attribute => its name in the language; the
     * merchant picks some on the setup screen. A filter another source has
     * too is one filter of both.
     *
     * @return array<string, string>
     */
    public function facets(int $languageId): array;

    /**
     * The shop's item ids for the full sync and the daily check, in a stable
     * order: the next $limit after $after ("" for the first ones). Letters,
     * digits, - and _, up to 128 (they're document ids, or part of them).
     *
     * @return list<string>
     */
    public function ids(int $shopId, string $after, int $limit): array;

    /**
     * Each item's documents in $context's shop and language, by item id;
     * built inside ShopContext::in() for them. An item that's gone or that a
     * guest can't find has none, and what it had is deleted. A products
     * source may give several per item (combinations, sharing parent_id);
     * any other gives at most one, whose id is the item's.
     *
     * @param list<string> $ids
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function documents(array $ids, Context $context): array;

    /**
     * When each item last changed (Unix seconds), for the daily check; empty
     * when the source can't tell.
     *
     * @param list<string> $ids
     *
     * @return array<string, int>
     */
    public function modified(int $shopId, array $ids): array;
}
