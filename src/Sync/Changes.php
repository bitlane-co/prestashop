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

use Bitlane\PrestaShop\Connection\ConnectionStore;
use Bitlane\PrestaShop\Queue\Jobs;
use Bitlane\PrestaShop\Site\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The catalogue's changes, from PrestaShop's hooks (Bitlane::hook*()): items
 * that changed during a request are collected and, at its end, queued as
 * jobs of 50 (type sync) for every shop that syncs, which the cron runs
 * (docs/prestashop-plan.md Part 3, "Changes"). What changes many products at
 * once (a category, brand, attribute or feature renamed) queues a job that
 * finds them (type products_of), 500 at a time. A sale that starts or ends
 * later queues a job due then.
 */
final class Changes
{
    public const JOB = 'sync';
    public const PRODUCTS_OF = 'products_of';

    /** @var array<string, array<string, true>> source => item => true */
    private array $pending = [];

    /** @var list<array{string, int}> what changed many products: kind, id */
    private array $fanOut = [];

    private bool $flushing = false;

    public function __construct(
        private Jobs $jobs,
        private Indexer $indexer,
        private SyncState $state,
        private Settings $settings,
        private ConnectionStore $connection,
    ) {
    }

    /**
     * Notes changed items of a source.
     *
     * @param array<int|string> $ids as the source's ids() gives them; 0 is none
     */
    public function add(string $source, array $ids): void
    {
        foreach ($ids as $id) {
            $id = (string) $id;
            // Meilisearch's document ids, as the items' documents have them.
            if ($id !== '0' && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $id)) {
                $this->pending[$source][$id] = true;
            }
        }
        $this->flushAtEnd();
    }

    /**
     * Something products have changed (a category's name, a colour's): its
     * products sync, found in the background.
     *
     * @param string $kind category, manufacturer, attribute, attribute_group, feature or feature_value
     */
    public function productsOf(string $kind, int $id): void
    {
        if ($id > 0) {
            $this->fanOut[] = [$kind, $id];
            $this->flushAtEnd();
        }
    }

    /**
     * A specific price saved or deleted: its product syncs now, and again when
     * the price starts and ends. One for every product (id_product 0) syncs
     * them all.
     */
    public function specificPrice(\SpecificPrice $price): void
    {
        $product = (int) $price->id_product;
        foreach ($this->shops() as $shopId) {
            if (!$product) {
                $this->jobs->push($shopId, FullSync::START, ['product'], 0, 'start:product');
                continue;
            }
            foreach ([$price->from, $price->to] as $at) {
                $time = $at && $at !== '0000-00-00 00:00:00' ? (int) strtotime((string) $at) : 0;
                if ($time > time()) {
                    $this->jobs->push($shopId, self::JOB, ['product', [(string) $product]], $time - time() + 1, 'price:' . $product . ':' . $time);
                }
            }
        }
        if ($product) {
            $this->add('product', [$product]);
        }
    }

    /**
     * The sales that start or end in the next day, for the daily check: a
     * job for each product when its price changes.
     */
    public function scheduleSales(int $shopId): void
    {
        $now = date('Y-m-d H:i:s');
        $day = date('Y-m-d H:i:s', time() + 86400);
        $rows = \Db::getInstance()->executeS(
            'SELECT id_product, `from`, `to` FROM ' . _DB_PREFIX_ . 'specific_price'
            . ' WHERE id_product > 0 AND id_shop IN (0, ' . $shopId . ')'
            . " AND ((`from` > '" . $now . "' AND `from` <= '" . $day . "') OR (`to` > '" . $now . "' AND `to` <= '" . $day . "'))",
            true,
            false
        ) ?: [];
        foreach ($rows as $row) {
            foreach ([$row['from'], $row['to']] as $at) {
                $time = (int) strtotime((string) $at);
                if ($time > time() && $time <= time() + 86400) {
                    $this->jobs->push($shopId, self::JOB, ['product', [(string) $row['id_product']]], $time - time() + 1, 'price:' . $row['id_product'] . ':' . $time);
                }
            }
        }
    }

    /**
     * Queues the request's changes.
     */
    public function flush(): void
    {
        $pending = $this->pending;
        $fanOut = $this->fanOut;
        $this->pending = $this->fanOut = [];
        $this->flushing = false;
        if (!$pending && !$fanOut) {
            return;
        }
        foreach ($this->shops() as $shopId) {
            $chosen = $this->settings->get($shopId)['sources'];
            foreach ($pending as $source => $ids) {
                if (in_array($source, $chosen, true)) {
                    foreach (array_chunk(array_keys($ids), 50) as $chunk) {
                        $this->jobs->push($shopId, self::JOB, [$source, array_map('strval', $chunk)]);
                    }
                }
            }
            if (in_array('product', $chosen, true)) {
                foreach ($fanOut as [$kind, $id]) {
                    $this->jobs->push($shopId, self::PRODUCTS_OF, [$kind, $id, 0]);
                }
            }
        }
    }

    /**
     * A sync job: the items' documents in every language, stamped with the
     * source's current run.
     *
     * @param array<mixed> $args the source, its items
     */
    public function run(int $shopId, array $args): void
    {
        $source = (string) ($args[0] ?? '');
        $this->indexer->sync($shopId, $source, array_map('strval', (array) ($args[1] ?? [])), $this->state->run($shopId, $source));
    }

    /**
     * A products_of job: 500 of the products something is part of, queued to
     * sync, then the next 500.
     *
     * @param array<mixed> $args the kind, its id, the last product id done
     */
    public function runProductsOf(int $shopId, array $args): void
    {
        [$kind, $id, $after] = [(string) ($args[0] ?? ''), (int) ($args[1] ?? 0), (int) ($args[2] ?? 0)];
        $p = _DB_PREFIX_;
        // What has it: tables and their condition, and the product id column.
        [$from, $column] = match ($kind) {
            // A category's subtree: its name is in its children's products' values too.
            'category' => ["{$p}category_product cp JOIN {$p}category c ON c.id_category = cp.id_category"
                . " JOIN {$p}category parent ON parent.id_category = $id AND c.nleft BETWEEN parent.nleft AND parent.nright WHERE 1", 'cp.id_product'],
            'manufacturer' => ["{$p}product WHERE id_manufacturer = $id", 'id_product'],
            'attribute' => ["{$p}product_attribute_combination pac JOIN {$p}product_attribute pa ON pa.id_product_attribute = pac.id_product_attribute"
                . " WHERE pac.id_attribute = $id", 'pa.id_product'],
            'attribute_group' => ["{$p}attribute a JOIN {$p}product_attribute_combination pac ON pac.id_attribute = a.id_attribute"
                . " JOIN {$p}product_attribute pa ON pa.id_product_attribute = pac.id_product_attribute WHERE a.id_attribute_group = $id", 'pa.id_product'],
            'feature' => ["{$p}feature_product WHERE id_feature = $id", 'id_product'],
            'feature_value' => ["{$p}feature_product WHERE id_feature_value = $id", 'id_product'],
            default => [null, null],
        };
        if ($from === null) {
            return;
        }
        $rows = \Db::getInstance()->executeS(
            "SELECT DISTINCT $column AS id_product FROM $from AND $column > $after ORDER BY $column LIMIT 500",
            true,
            false
        ) ?: [];
        $ids = array_map('strval', array_column($rows, 'id_product'));
        foreach (array_chunk($ids, 50) as $chunk) {
            $this->jobs->push($shopId, self::JOB, ['product', $chunk]);
        }
        if (count($ids) === 500) {
            $this->jobs->push($shopId, self::PRODUCTS_OF, [$kind, $id, (int) end($ids)]);
        }
    }

    /**
     * The shops that sync: connected, with a setup Bitlane made ready.
     *
     * @return list<int>
     */
    public function shops(): array
    {
        if (!$this->connection->connected()) {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', \Shop::getShops(false, null, true)),
            fn (int $shopId) => $this->connection->integrationId($shopId) !== null && !empty($this->state->get($shopId)['sources'])
        ));
    }

    private function flushAtEnd(): void
    {
        if (!$this->flushing) {
            $this->flushing = true;
            register_shutdown_function([$this, 'flush']);
        }
    }
}
