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

namespace Bitlane\PrestaShop\Sync;

use Bitlane\PrestaShop\Site\Settings;
use Bitlane\PrestaShop\Site\Site;
use Bitlane\PrestaShop\Sources\Context;
use Bitlane\PrestaShop\Sources\Source;
use Bitlane\PrestaShop\Sources\Sources;
use Bitlane\PrestaShop\Support\BitlaneError;
use Bitlane\PrestaShop\Support\ShopContext;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Writes a source's items to its index in each of the shop's languages,
 * as the WordPress plugin does: their documents are upserted (stamped with
 * the run, with only the picked filters), then what the items no longer have
 * there is deleted. A product's documents share its parent_id, so the ones
 * it had are found with it (a combination removed, the product hidden); any
 * other item has one document, its id.
 */
final class Indexer
{
    public function __construct(
        private Sources $sources,
        private Settings $settings,
        private Site $site,
        private Credentials $credentials,
        private Meilisearch $meilisearch,
        private ShopContext $shopContext,
    ) {
    }

    /**
     * Syncs a source's items in every language whose index exists (a language
     * just added has none until Bitlane sets it up; its full sync follows). A
     * full sync doesn't look for documents an item no longer has: its sweep
     * deletes them.
     *
     * @param list<string> $ids
     */
    public function sync(int $shopId, string $sourceId, array $ids, string $run, bool $full = false): void
    {
        $settings = $this->settings->get($shopId);
        $source = $this->sources->get($sourceId);
        if (!$source || !in_array($sourceId, $settings['sources'], true) || !$ids) {
            return;
        }
        $ids = array_values(array_unique(array_map('strval', $ids)));
        $facets = array_values(array_intersect($settings['facets'], array_column($this->site->facets([$sourceId]), 'attribute')));

        foreach ($this->site->languages($shopId) as $language) {
            try {
                $index = $this->credentials->index($shopId, $sourceId, $language['code']);
            } catch (BitlaneError $error) {
                if ($error->kind === 'no_index') {
                    continue;
                }
                throw $error;
            }
            $context = new Context($shopId, $language['id'], $language['code'], $facets, $settings['product_documents']);
            try {
                $docs = $this->shopContext->in($shopId, $language['id'], fn () => $this->documents($source, $ids, $context, $run));
                $this->meilisearch->add($index, $docs);
                if (!$full) {
                    $this->deleteOthers($source, $index, $ids, array_column($docs, 'id'));
                }
            } catch (BitlaneError $error) {
                // The write key may have been replaced: the next try reads it again.
                if ($error->status === 401 || $error->status === 403) {
                    $this->credentials->forget($shopId);
                }
                throw $error;
            }
        }
    }

    /**
     * The items' documents as they're sent: the run, and only the picked filters.
     *
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function documents(Source $source, array $ids, Context $context, string $run): array
    {
        $byItem = $source->documents($ids, $context);
        $products = $source->kind() === Source::PRODUCTS;
        $picked = array_flip($context->facets);
        $docs = [];
        foreach ($ids as $item) {
            $itemDocs = array_values(array_filter($byItem[$item] ?? [], 'is_array'));
            foreach ($products ? $itemDocs : array_slice($itemDocs, 0, 1) as $doc) {
                $doc['id'] = $products ? (string) ($doc['id'] ?? $item) : $item;
                if ($products) {
                    $doc['parent_id'] = (string) ($doc['parent_id'] ?? $item);
                }
                foreach (['facets', 'product_facets', 'available'] as $field) {
                    if (isset($doc[$field])) {
                        $doc[$field] = (object) array_intersect_key((array) $doc[$field], $picked);
                    }
                }
                $doc['sync_run'] = $run;
                $docs[] = $doc;
            }
        }

        return $docs;
    }

    /**
     * Deletes what the items had and no longer have. Which of them the index
     * holds is read first, so most calls are a read, not a write.
     *
     * @param array{url: string, key: string, index: string} $index
     * @param list<string> $ids
     * @param list<string> $kept the documents just sent
     */
    private function deleteOthers(Source $source, array $index, array $ids, array $kept): void
    {
        $products = $source->kind() === Source::PRODUCTS;
        $gone = $products ? $ids : array_values(array_diff($ids, $kept));
        if (!$gone) {
            return;
        }
        if ($products) {
            $quoted = array_map(static fn ($item) => (string) json_encode($item), $ids);
            $existing = $this->meilisearch->documentIds($index, 'parent_id IN [' . implode(', ', $quoted) . ']');
        } else {
            $existing = $this->meilisearch->present($index, false, $gone)['items'];
        }
        $this->meilisearch->delete($index, array_values(array_diff($existing, $kept)));
    }
}
