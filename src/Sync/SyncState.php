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

use Bitlane\PrestaShop\Support\BitlaneError;
use Bitlane\PrestaShop\Support\State;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Where a shop's sync is: a small value of the state table, the same size
 * for any catalogue (items wait in bitlane_job; a full sync's cursor travels
 * in its job's arguments). Per source:
 * - run: stamped on every document of the source written (sync_run). A full
 *   sync starts a new one and, at its end, deletes the source's documents with
 *   another, so what changed meanwhile survives it;
 * - setup: the source's setup it was last synced in full for (Settings::hash());
 * - done, total, started_at, finished_at: its last full sync;
 * the last error, until a sync succeeds; and the engine the shop's indexes
 * are on (a new one means syncing everything again, Sync::ready()).
 */
final class SyncState
{
    private const NAME = 'sync';

    public function __construct(private State $state)
    {
    }

    /**
     * @return array{sources?: array<string, array<string, mixed>>, error?: array{message: string, at: int}, engine?: string}
     */
    public function get(int $shopId): array
    {
        return $this->state->json(self::NAME, $shopId);
    }

    /**
     * The engine the shop's indexes are on, as Bitlane last said; empty before.
     */
    public function engine(int $shopId): string
    {
        return (string) ($this->get($shopId)['engine'] ?? '');
    }

    public function setEngine(int $shopId, string $engineId): void
    {
        $state = $this->get($shopId);
        $state['engine'] = $engineId;
        $this->state->setJson(self::NAME, $state, $shopId);
    }

    /**
     * @return array<string, mixed>
     */
    public function source(int $shopId, string $source): array
    {
        return (array) ($this->get($shopId)['sources'][$source] ?? []);
    }

    /**
     * Merges changes into a source's state; a null value removes the key, and
     * null for $changes removes the source.
     *
     * @param array<string, mixed>|null $changes
     */
    public function updateSource(int $shopId, string $source, ?array $changes): void
    {
        $state = $this->get($shopId);
        if ($changes === null) {
            unset($state['sources'][$source]);
        } else {
            $state['sources'][$source] = array_filter(array_merge((array) ($state['sources'][$source] ?? []), $changes), static fn ($value) => $value !== null);
        }
        $this->state->setJson(self::NAME, $state, $shopId);
    }

    /**
     * A source's current run, started when it has none.
     */
    public function run(int $shopId, string $source): string
    {
        $run = (string) ($this->source($shopId, $source)['run'] ?? '');
        if ($run === '') {
            $run = self::newRun();
            $this->updateSource($shopId, $source, ['run' => $run]);
        }

        return $run;
    }

    /**
     * A run id: short, and fine in a Meilisearch filter.
     */
    public static function newRun(): string
    {
        return gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    }

    /**
     * Notes a failure, or clears it after a success.
     */
    public function error(int $shopId, ?BitlaneError $error): void
    {
        $state = $this->get($shopId);
        if ($error === null && !isset($state['error'])) {
            return;
        }
        if ($error) {
            $state['error'] = ['message' => $error->getMessage(), 'at' => time()];
        } else {
            unset($state['error']);
        }
        $this->state->setJson(self::NAME, $state, $shopId);
    }
}
