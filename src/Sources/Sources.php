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

use Bitlane\PrestaShop\Documents\ProductDocuments;
use Bitlane\PrestaShop\Support\Hooks;
use Bitlane\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The sources of documents by id: products, categories, CMS pages, brands
 * and stores. A module adds its own (a blog, a table of its own) from the
 * hook actionBitlaneDocumentSources: 'sources' by reference, a list of
 * Source objects.
 */
final class Sources
{
    /** @var array<string, Source>|null */
    private ?array $sources = null;

    public function __construct(private Translator $translator)
    {
    }

    /**
     * @return array<string, Source>
     */
    public function all(): array
    {
        if ($this->sources === null) {
            $sources = Hooks::filter('actionBitlaneDocumentSources', 'sources', [
                new ProductSource(new ProductDocuments($this->translator), $this->translator),
                new CategorySource($this->translator),
                new CmsSource($this->translator),
                new BrandSource($this->translator),
                new StoreSource($this->translator),
            ]);
            $this->sources = [];
            foreach (is_array($sources) ? $sources : [] as $source) {
                if ($source instanceof Source && preg_match('/^[a-z0-9_-]{1,64}$/', $source->id())) {
                    $this->sources[$source->id()] = $source;
                }
            }
        }

        return $this->sources;
    }

    public function get(string $id): ?Source
    {
        return $this->all()[$id] ?? null;
    }
}
