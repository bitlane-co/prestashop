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
use Bitlane\PrestaShop\Support\BitlaneError;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A source's full sync in a shop, as jobs (type full_sync) one after another,
 * each a batch of items (50 products, 200 of anything else) written in every
 * language, its arguments the cursor (source, run, last id), so a retry redoes
 * exactly that batch. Documents are stamped with a new run; once the source's
 * items are all sent, each language's index loses the documents of another
 * run, which removes what's gone since while the index stays searchable
 * throughout (the WordPress plugin's W6).
 */
final class FullSync
{
    public const JOB = 'full_sync';

    /** A job that starts a source's full sync (args: the source), queued by changes that touch every item. */
    public const START = 'full_sync_start';

    public function __construct(
        private Indexer $indexer,
        private Sources $sources,
        private Settings $settings,
        private Site $site,
        private SyncState $state,
        private Credentials $credentials,
        private Meilisearch $meilisearch,
        private Jobs $jobs,
    ) {
    }

    /**
     * Starts a source's full sync; one still running stops (its jobs see a
     * newer run and end).
     */
    public function start(int $shopId, string $source): string
    {
        $run = SyncState::newRun();
        $counts = array_column($this->site->sources($shopId), 'count', 'id');
        $this->state->updateSource($shopId, $source, [
            'run' => $run,
            'done' => 0,
            'total' => (int) ($counts[$source] ?? 0),
            'started_at' => time(),
            'finished_at' => null,
        ]);
        $this->jobs->push($shopId, self::JOB, [$source, $run, '']);

        return $run;
    }

    /**
     * One batch, then the next is queued.
     *
     * @param array<mixed> $args the source, the run, the last id done
     */
    public function step(int $shopId, array $args): void
    {
        [$sourceId, $run, $after] = array_map('strval', $args + ['', '', '']);
        $state = $this->state->source($shopId, $sourceId);
        $source = $this->sources->get($sourceId);
        if (($state['run'] ?? '') !== $run || !empty($state['finished_at']) || !$source || !in_array($sourceId, $this->settings->get($shopId)['sources'], true)) {
            return;
        }

        $ids = $source->ids($shopId, $after, $source->kind() === Source::PRODUCTS ? 50 : 200);
        if (!$ids) {
            $this->sweep($shopId, $sourceId, $run);
            $this->state->updateSource($shopId, $sourceId, ['finished_at' => time()]);

            return;
        }

        $this->indexer->sync($shopId, $sourceId, $ids, $run, true);
        $this->state->updateSource($shopId, $sourceId, ['done' => (int) ($state['done'] ?? 0) + count($ids)]);
        $this->jobs->push($shopId, self::JOB, [$sourceId, $run, (string) end($ids)]);
    }

    /**
     * Deletes the source's documents of other runs in each language's index.
     */
    private function sweep(int $shopId, string $source, string $run): void
    {
        foreach ($this->site->languages($shopId) as $language) {
            try {
                $index = $this->credentials->index($shopId, $source, $language['code']);
            } catch (BitlaneError $error) {
                if ($error->kind === 'no_index') {
                    continue;
                }
                throw $error;
            }
            $this->meilisearch->deleteWhere($index, 'sync_run != "' . $run . '"');
        }
    }
}
