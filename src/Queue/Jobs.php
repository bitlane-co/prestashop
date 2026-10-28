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

namespace Bitlane\PrestaShop\Queue;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's background work, in its table bitlane_job (docs/prestashop-plan.md
 * PS9; PrestaShop has nothing like WordPress's Action Scheduler): a shop, a
 * type (what runs it, Runner), arguments in JSON, when it's due and how many
 * attempts it had. A job is claimed by writing a token and a lock time with
 * one UPDATE (MySQL and MariaDB alike), so two runners never take the same
 * one, and a runner that died lets go when its lock time passes.
 *
 * The table holds work to do, not a history: a job that finishes is deleted
 * at once, one that gives up stays a week with its error (failed_at) for the
 * Jobs tab, then prune() deletes it.
 */
final class Jobs
{
    public const TABLE = 'bitlane_job';

    /** How long a failed job stays, in days. */
    private const KEEP_FAILED = 7;

    /**
     * Queues a job, due now or after $delay seconds. With a $key, a job of the
     * shop with that key still waiting is kept instead (the daily check, a
     * sale's start).
     *
     * @param array<mixed> $args
     */
    public function push(int $shopId, string $type, array $args = [], int $delay = 0, ?string $key = null): void
    {
        $db = \Db::getInstance();
        if ($key !== null && $db->getValue('SELECT 1 FROM ' . $this->table() . ' WHERE `id_shop` = ' . $shopId . " AND `job_key` = '" . pSQL($key) . "' AND `failed_at` IS NULL", false)) {
            return;
        }
        $db->insert(self::TABLE, [
            'id_shop' => $shopId,
            'type' => pSQL($type),
            'args' => pSQL((string) json_encode($args), true),
            'job_key' => $key === null ? null : pSQL($key),
            'run_at' => date('Y-m-d H:i:s', time() + $delay),
            'attempts' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ], true, false);
    }

    /**
     * Claims the next due job for $lock seconds.
     *
     * @return array{id: int, shop: int, type: string, args: array<mixed>, attempts: int}|null
     */
    public function claim(int $lock = 300): ?array
    {
        $db = \Db::getInstance();
        $token = bin2hex(random_bytes(16));
        $db->execute(
            'UPDATE ' . $this->table() . " SET `lock_token` = '" . $token . "', `locked_until` = '" . date('Y-m-d H:i:s', time() + $lock) . "'"
            . ' WHERE ' . $this->due() . ' ORDER BY `run_at`, `id_job` LIMIT 1'
        );
        $row = $db->getRow('SELECT * FROM ' . $this->table() . " WHERE `lock_token` = '" . $token . "'", false);
        if (!is_array($row)) {
            return null;
        }
        $args = json_decode((string) $row['args'], true);

        return ['id' => (int) $row['id_job'], 'shop' => (int) $row['id_shop'], 'type' => (string) $row['type'], 'args' => is_array($args) ? $args : [], 'attempts' => (int) $row['attempts']];
    }

    /**
     * A job finished: it goes.
     */
    public function done(int $id): void
    {
        \Db::getInstance()->delete(self::TABLE, '`id_job` = ' . $id);
    }

    /**
     * Runs a job again after $delay seconds, one attempt more, noting why.
     */
    public function retry(int $id, int $delay, string $error): void
    {
        \Db::getInstance()->execute(
            'UPDATE ' . $this->table() . " SET `run_at` = '" . date('Y-m-d H:i:s', time() + $delay) . "', `attempts` = `attempts` + 1,"
            . " `lock_token` = NULL, `locked_until` = NULL, `last_error` = '" . pSQL(mb_substr($error, 0, 1000)) . "'"
            . ' WHERE `id_job` = ' . $id
        );
    }

    /**
     * A job gave up: it stays, with its error, until prune().
     */
    public function fail(int $id, string $error): void
    {
        \Db::getInstance()->execute(
            'UPDATE ' . $this->table() . " SET `failed_at` = '" . date('Y-m-d H:i:s') . "', `attempts` = `attempts` + 1,"
            . " `lock_token` = NULL, `locked_until` = NULL, `last_error` = '" . pSQL(mb_substr($error, 0, 1000)) . "'"
            . ' WHERE `id_job` = ' . $id
        );
    }

    /**
     * Deletes the jobs that failed more than a week ago.
     */
    public function prune(): void
    {
        \Db::getInstance()->delete(self::TABLE, "`failed_at` < '" . date('Y-m-d H:i:s', time() - self::KEEP_FAILED * 86400) . "'");
    }

    /**
     * The shop's jobs for the Jobs tab: running first, then due, then later
     * ones, then failed; at most $limit.
     *
     * @return list<array{id: int, type: string, args: array<mixed>, run_at: int, due: bool, attempts: int, running: bool, failed_at: int|null, error: string|null}>
     */
    public function list(int $shopId, int $limit = 100): array
    {
        $now = date('Y-m-d H:i:s');
        $rows = \Db::getInstance()->executeS(
            'SELECT * FROM ' . $this->table() . ' WHERE `id_shop` = ' . $shopId
            . " ORDER BY `failed_at` IS NOT NULL, (`locked_until` > '" . $now . "') DESC, `run_at`, `id_job` LIMIT " . $limit,
            true,
            false
        ) ?: [];

        return array_map(static function (array $row) use ($now): array {
            $args = json_decode((string) $row['args'], true);

            return [
                'id' => (int) $row['id_job'],
                'type' => (string) $row['type'],
                'args' => is_array($args) ? $args : [],
                'run_at' => (int) strtotime((string) $row['run_at']),
                'due' => $row['run_at'] <= $now,
                'attempts' => (int) $row['attempts'],
                'running' => $row['failed_at'] === null && $row['locked_until'] !== null && $row['locked_until'] > $now,
                'failed_at' => $row['failed_at'] !== null ? (int) strtotime((string) $row['failed_at']) : null,
                'error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            ];
        }, $rows);
    }

    /**
     * How many of the shop's jobs wait to run, of the given types (all
     * without); failed ones don't.
     *
     * @param list<string> $types
     */
    public function waiting(int $shopId, array $types = []): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE `id_shop` = ' . $shopId . ' AND `failed_at` IS NULL'
            . ($types ? " AND `type` IN ('" . implode("', '", array_map('pSQL', $types)) . "')" : ''),
            false
        );
    }

    /**
     * How many of the shop's jobs failed (kept a week).
     */
    public function failed(int $shopId): int
    {
        return (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE `id_shop` = ' . $shopId . ' AND `failed_at` IS NOT NULL', false);
    }

    /**
     * When the oldest due job became due, 0 when none is: the screen warns
     * when jobs wait and no cron runs them.
     */
    public function oldestDue(): int
    {
        return (int) strtotime((string) \Db::getInstance()->getValue('SELECT MIN(`run_at`) FROM ' . $this->table() . ' WHERE ' . $this->due(), false));
    }

    /**
     * Runs one of the shop's failed jobs again, from its first attempt.
     */
    public function again(int $shopId, int $id): void
    {
        \Db::getInstance()->execute(
            'UPDATE ' . $this->table() . " SET `failed_at` = NULL, `attempts` = 0, `run_at` = '" . date('Y-m-d H:i:s') . "'"
            . ' WHERE `id_job` = ' . $id . ' AND `id_shop` = ' . $shopId . ' AND `failed_at` IS NOT NULL'
        );
    }

    /**
     * Deletes one of the shop's jobs that isn't running.
     */
    public function delete(int $shopId, int $id): void
    {
        \Db::getInstance()->delete(
            self::TABLE,
            '`id_job` = ' . $id . ' AND `id_shop` = ' . $shopId . " AND (`locked_until` IS NULL OR `locked_until` < '" . date('Y-m-d H:i:s') . "')"
        );
    }

    /**
     * Drops the shop's jobs, of the given types (all without): it disconnected.
     *
     * @param list<string> $types
     */
    public function cancel(int $shopId, array $types = []): void
    {
        \Db::getInstance()->delete(
            self::TABLE,
            '`id_shop` = ' . $shopId . ($types ? " AND `type` IN ('" . implode("', '", array_map('pSQL', $types)) . "')" : '')
        );
    }

    private function table(): string
    {
        return '`' . _DB_PREFIX_ . self::TABLE . '`';
    }

    /**
     * Due, not failed, not held by a runner.
     */
    private function due(): string
    {
        $now = date('Y-m-d H:i:s');

        return "`failed_at` IS NULL AND `run_at` <= '" . $now . "' AND (`locked_until` IS NULL OR `locked_until` < '" . $now . "')";
    }
}
