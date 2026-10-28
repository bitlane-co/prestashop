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

use Bitlane\PrestaShop\Sources\Context;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A product's card images, read once for all its documents: a combination's
 * first image, else the product's cover.
 */
final class ProductImages
{
    /** @var array<int, list<array{id_image: int|string, legend?: string}>> */
    private array $combinations;

    private int $cover;

    public function __construct(private \Product $product, private Context $context)
    {
        $this->combinations = $product->getCombinationImages($context->languageId) ?: [];
        $cover = \Product::getCover((int) $product->id);
        $this->cover = (int) ($cover['id_image'] ?? 0);
    }

    /**
     * The image of a combination (0: the product).
     *
     * @return array<string, string|int>|null
     */
    public function of(int $ipa): ?array
    {
        $image = $this->combinations[$ipa][0] ?? null;
        $imageId = (int) ($image['id_image'] ?? $this->cover);
        $types = $this->context->catalog->imageTypes('products', 250);
        if (!$imageId || !$types) {
            return null;
        }
        $link = \Context::getContext()->link;
        $rewrite = (string) $this->product->link_rewrite;
        $legend = (string) ($image['legend'] ?? '');

        return Fields::image(static fn (string $type) => $link->getImageLink($rewrite, (string) $imageId, $type), $types['shown'], $types['types'], $legend !== '' ? $legend : (string) $this->product->name);
    }
}
