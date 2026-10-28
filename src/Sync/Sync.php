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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A shop's sync as the Bitlane screen and the console see it: when a source
 * syncs in full (Bitlane made a setup ready that changed it; Resync), where
 * each is, and stopping it all when the shop disconnects.
 */
final class Sync
{
    public function __construct(
        private FullSync $full,
        private Check $check,
        private ShopSettings $shopSettings,
        private SyncState $state,
        private Settings $settings,
        private Credentials $credentials,
        private Jobs $jobs,
    ) {
    }

    /**
     * Bitlane made the shop's setup ready: each source the setup changed (new,
     * other filters, languages, the products' mode) syncs in full with fresh
     * credentials, and every source when the shop moved to another engine
     * ($engineId, the site's in Bitlane: the new one's indexes start empty);
     * sources no longer chosen are forgotten (Bitlane deleted their indexes).
     * The daily check runs from then on.
     */
    public function ready(int $shopId, string $engineId = ''): void
    {
        // Once a day: a check already queued is kept.
        $this->check->schedule($shopId);
        // Settings that change every document: at most hourly.
        $this->shopSettings->check($shopId, false);
        $chosen = $this->settings->get($shopId)['sources'];
        // Another engine (the setup moved the shop): its indexes start empty.
        $moved = $engineId !== '' && !in_array($this->state->engine($shopId), ['', $engineId], true);
        if ($engineId !== '' && $this->state->engine($shopId) !== $engineId) {
            $this->state->setEngine($shopId, $engineId);
        }
        $changed = $moved ? $chosen : array_values(array_filter($chosen, fn ($source) => ($this->state->source($shopId, $source)['setup'] ?? '') !== $this->settings->hash($shopId, $source)));
        foreach (array_diff(array_keys($this->state->get($shopId)['sources'] ?? []), $chosen) as $removed) {
            $this->state->updateSource($shopId, (string) $removed, null);
        }
        if (!$changed) {
            return;
        }
        $this->credentials->forget($shopId);
        $this->shopSettings->remember($shopId);
        foreach ($changed as $source) {
            $this->full->start($shopId, $source);
            $this->state->updateSource($shopId, $source, ['setup' => $this->settings->hash($shopId, $source)]);
        }
    }

    /**
     * Syncs a source again, or every chosen one.
     */
    public function resync(int $shopId, ?string $source = null): void
    {
        $chosen = $this->settings->get($shopId)['sources'];
        $this->credentials->forget($shopId);
        foreach ($source === null ? $chosen : array_intersect([$source], $chosen) as $id) {
            $this->full->start($shopId, $id);
        }
    }

    /**
     * Where each chosen source is, the changes waiting, and the last error.
     *
     * @return array{sources: list<array{id: string, running: bool, done: int, total: int, started_at: ?int, finished_at: ?int}>, queued: int, error: array{message: string, at: int}|null}
     */
    public function summary(int $shopId): array
    {
        $state = $this->state->get($shopId);
        $sources = [];
        foreach ($this->settings->get($shopId)['sources'] as $id) {
            $source = (array) ($state['sources'][$id] ?? []);
            $sources[] = [
                'id' => $id,
                'running' => isset($source['started_at']) && empty($source['finished_at']),
                'done' => (int) ($source['done'] ?? 0),
                'total' => (int) ($source['total'] ?? 0),
                'started_at' => isset($source['started_at']) ? (int) $source['started_at'] : null,
                'finished_at' => isset($source['finished_at']) ? (int) $source['finished_at'] : null,
            ];
        }

        return [
            'sources' => $sources,
            'queued' => $this->jobs->waiting($shopId, [Changes::JOB, Changes::PRODUCTS_OF]),
            'error' => $state['error'] ?? null,
        ];
    }
}
