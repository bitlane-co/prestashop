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

use Bitlane\PrestaShop\Queue\Jobs;
use Bitlane\PrestaShop\Site\Settings;
use Bitlane\PrestaShop\Site\Site;
use Bitlane\PrestaShop\Sources\Source;
use Bitlane\PrestaShop\Sources\Sources;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The daily check that each index matches its source, as the WordPress
 * plugin's: what no hook saw (imports writing the database directly, the
 * module switched off, jobs that gave up). Each chosen source's ids are walked
 * 1,000 at a time in each language, and Meilisearch says which the index has
 * and when their documents say each changed (modified_at): missing items, and
 * items changed since, sync like any change. If the index then holds more
 * documents than the walk found, some are of items gone, and the source's
 * full sync sweeps them. Ten batches a job; a source in a full sync is
 * skipped. It also queues the sales that start or end in the next day, and
 * full syncs when a shop setting that changes every document changed.
 */
final class Check
{
    public const JOB = 'check';
    public const SOURCE_JOB = 'check_source';

    public function __construct(
        private Sources $sources,
        private Settings $settings,
        private Site $site,
        private SyncState $state,
        private Credentials $credentials,
        private Meilisearch $meilisearch,
        private FullSync $full,
        private Changes $changes,
        private ShopSettings $shopSettings,
        private Jobs $jobs,
    ) {
    }

    /**
     * Queues the next check: the next 2 AM in the shop's timezone, at a random
     * minute of that hour so shops don't all check at once.
     */
    public function schedule(int $shopId): void
    {
        $zone = new \DateTimeZone((string) (\Configuration::get('PS_TIMEZONE', null, null, $shopId) ?: date_default_timezone_get()));
        $at = new \DateTimeImmutable('today 02:00', $zone);
        if ($at->getTimestamp() <= time()) {
            $at = $at->modify('+1 day');
        }
        $this->jobs->push($shopId, self::JOB, [], $at->getTimestamp() - time() + random_int(0, 59) * 60, 'check:' . $at->format('Ymd'));
    }

    /**
     * The check job: tomorrow's, the sales of the next day, the shop's
     * settings, then the walk of each chosen source in each language.
     */
    public function start(int $shopId): void
    {
        $this->schedule($shopId);
        $this->changes->scheduleSales($shopId);
        $this->shopSettings->check($shopId);
        foreach ($this->settings->get($shopId)['sources'] as $source) {
            foreach ($this->site->languages($shopId) as $language) {
                $this->jobs->push($shopId, self::SOURCE_JOB, [$source, $language['code'], '', 0]);
            }
        }
    }

    /**
     * Up to ten batches of a source in a language, then the next job; at the
     * end, the index's documents against those the walk found.
     *
     * @param array<mixed> $args the source, the language, the last id checked, documents found so far
     */
    public function batch(int $shopId, array $args): void
    {
        [$sourceId, $language, $after, $documents] = [(string) ($args[0] ?? ''), (string) ($args[1] ?? ''), (string) ($args[2] ?? ''), (int) ($args[3] ?? 0)];
        $source = $this->sources->get($sourceId);
        $state = $this->state->source($shopId, $sourceId);
        if (!$source || !in_array($sourceId, $this->settings->get($shopId)['sources'], true) || (isset($state['started_at']) && empty($state['finished_at']))) {
            return;
        }
        $index = $this->credentials->index($shopId, $sourceId, $language);
        for ($i = 0; $i < 10; ++$i) {
            $ids = $source->ids($shopId, $after, 1000);
            if (!$ids) {
                if ($this->meilisearch->count($index) > $documents) {
                    $this->full->start($shopId, $sourceId);
                }

                return;
            }
            $present = $this->meilisearch->present($index, $source->kind() === Source::PRODUCTS, $ids);
            $stale = [];
            foreach ($source->modified($shopId, $present['items']) as $item => $changed) {
                if ($changed > ($present['modified'][(string) $item] ?? 0)) {
                    $stale[] = (string) $item;
                }
            }
            foreach (array_chunk(array_merge(array_values(array_diff($ids, $present['items'])), $stale), 50) as $chunk) {
                $this->jobs->push($shopId, Changes::JOB, [$sourceId, $chunk]);
            }
            $documents += $present['documents'];
            $after = (string) end($ids);
        }
        $this->jobs->push($shopId, self::SOURCE_JOB, [$sourceId, $language, $after, $documents]);
    }
}
