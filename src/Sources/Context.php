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

use Bitlane\PrestaShop\Documents\Catalog;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What documents are built for: a shop, one of its languages (its id, and its
 * code as the setup sent it, "en-US"), the filters the merchant picked for
 * the source, and how products become documents. The catalogue's names and
 * images for that shop and language are read once (Catalog).
 */
final class Context
{
    public readonly Catalog $catalog;

    /**
     * @param list<string> $facets the picked filters (attributes) this source has
     * @param string $productDocuments Settings::PER_VARIATION or PER_PRODUCT
     */
    public function __construct(
        public readonly int $shopId,
        public readonly int $languageId,
        public readonly string $language,
        public readonly array $facets,
        public readonly string $productDocuments,
    ) {
        $this->catalog = new Catalog($shopId, $languageId);
    }
}
