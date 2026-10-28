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

use Bitlane\PrestaShop\Support\BitlaneError;
use Bitlane\PrestaShop\Support\Lock;
use Bitlane\PrestaShop\Support\State;
use Bitlane\PrestaShop\Sync\Retry;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Runs due jobs, one runner at a time (a MySQL named lock), for a time
 * budget and while memory lasts (docs/prestashop-plan.md PS9). A cron starts
 * it, every minute: php bin/console bitlane:run, or, without a shell, the
 * module's front controller run at url(); the Bitlane screen also runs jobs
 * while it's open. Shop requests never run them.
 *
 * A job's type names its handler. A handler that throws BitlaneError is
 * retried with backoff (Retry); one that gives up is kept a week with its
 * error (Jobs::fail(), the Jobs tab), and each run prunes older ones.
 */
final class Runner
{
    /** @var array<string, callable(int, array<mixed>): void> */
    private array $handlers = [];

    /** @var (callable(int, BitlaneError|null): void)|null */
    private $onOutcome;

    public function __construct(private Jobs $jobs, private Lock $lock, private State $state)
    {
    }

    /**
     * @param callable(int, array<mixed>): void $handler takes the shop and the job's arguments
     */
    public function handle(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    /**
     * Told each job's outcome for its shop: null on success, else the error.
     *
     * @param callable(int, BitlaneError|null): void $onOutcome
     */
    public function onOutcome(callable $onOutcome): void
    {
        $this->onOutcome = $onOutcome;
    }

    /**
     * Runs due jobs for up to $seconds; how many ran (0 when another runner is
     * at work).
     */
    public function run(int $seconds): int
    {
        $ran = 0;
        $this->lock->attempt('runner', function () use ($seconds, &$ran) {
            $this->state->set('runner_at', (string) time());
            $this->jobs->prune();
            $until = microtime(true) + $seconds;
            $memory = $this->memoryLimit();
            while (microtime(true) < $until && ($memory <= 0 || memory_get_usage(true) < $memory * 0.7)) {
                $job = $this->jobs->claim();
                if ($job === null) {
                    break;
                }
                $this->execute($job);
                ++$ran;
            }
        });

        return $ran;
    }

    /**
     * The runner's URL for a cron without a shell (a hosting panel,
     * PrestaShop's Cron tasks manager): the front controller run with the
     * install's secret.
     */
    public function url(): string
    {
        $shopId = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $shop = new \Shop($shopId);
        $base = \Configuration::get('PS_SSL_ENABLED', null, null, $shopId) ? 'https://' . $shop->domain_ssl : 'http://' . $shop->domain;

        return $base . $shop->getBaseURI() . 'index.php?fc=module&module=bitlane&controller=run&token=' . $this->secret();
    }

    public function secret(): string
    {
        $secret = (string) $this->state->get('runner_secret');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
            $this->state->set('runner_secret', $secret);
        }

        return $secret;
    }

    /**
     * When a runner last started; 0 never.
     */
    public function lastRun(): int
    {
        return (int) $this->state->get('runner_at');
    }

    /**
     * The cron line for this shop: every minute, its console.
     */
    public function cronLine(): string
    {
        return '* * * * * php ' . rtrim(_PS_ROOT_DIR_, '/') . '/bin/console bitlane:run --seconds=50 >/dev/null 2>&1';
    }

    /**
     * When the oldest job waiting to run became due; 0 when none waits.
     */
    public function waitingSince(): int
    {
        return $this->jobs->oldestDue();
    }

    /**
     * @param array{id: int, shop: int, type: string, args: array<mixed>, attempts: int} $job
     */
    private function execute(array $job): void
    {
        $handler = $this->handlers[$job['type']] ?? null;
        if ($handler === null) {
            $this->jobs->done($job['id']);

            return;
        }
        try {
            $handler($job['shop'], $job['args']);
            $this->jobs->done($job['id']);
            $this->outcome($job['shop'], null);
        } catch (BitlaneError $error) {
            $this->failed($job, $error);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('Bitlane: ' . $job['type'] . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), 3, null, 'Bitlane');
            $this->failed($job, new BitlaneError('unexpected', $e->getMessage()));
        }
    }

    /**
     * @param array{id: int, shop: int, type: string, args: array<mixed>, attempts: int} $job
     */
    private function failed(array $job, BitlaneError $error): void
    {
        $delay = Retry::after($error, $job['attempts']);
        if ($delay === null) {
            $this->jobs->fail($job['id'], $error->getMessage());
        } else {
            $this->jobs->retry($job['id'], $delay, $error->getMessage());
        }
        $this->outcome($job['shop'], $error);
    }

    private function outcome(int $shopId, ?BitlaneError $error): void
    {
        if ($this->onOutcome) {
            ($this->onOutcome)($shopId, $error);
        }
    }

    /**
     * PHP's memory limit in bytes; 0 for none.
     */
    private function memoryLimit(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return 0;
        }
        $units = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824];

        return (int) $limit * ($units[strtolower(substr($limit, -1))] ?? 1);
    }
}
