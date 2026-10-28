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

namespace Bitlane\PrestaShop\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * php bin/console bitlane:status: each shop's connection, its sources' last
 * full sync, the jobs waiting and failed, and the last error.
 */
final class StatusCommand extends BitlaneCommand
{
    protected function configure(): void
    {
        $this->setName('bitlane:status')
            ->setDescription('Shows each shop’s Bitlane connection and sync.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $plugin = $this->plugin($output);
        if ($plugin === null) {
            return 1;
        }
        $connected = $plugin->connection()->connected();
        $runner = $plugin->runner();
        $output->writeln('Connection: ' . ($connected ? 'connected' : 'not connected'));
        $output->writeln('Last run: ' . ($runner->lastRun() ? date('Y-m-d H:i:s', $runner->lastRun()) : 'never'));

        foreach (\Shop::getShops(false) as $shop) {
            $shopId = (int) $shop['id_shop'];
            $integration = $plugin->connection()->integrationId($shopId);
            $output->writeln('');
            $output->writeln(sprintf('<info>%s</info> (shop %d): %s', $shop['name'], $shopId, $integration ? 'in Bitlane as ' . $integration : 'not in Bitlane'));
            if (!$integration) {
                continue;
            }
            $summary = $plugin->sync()->summary($shopId);
            foreach ($summary['sources'] as $source) {
                $output->writeln(sprintf(
                    '  %-10s %s',
                    $source['id'],
                    $source['running']
                        ? sprintf('syncing, %d of %d', $source['done'], $source['total'])
                        : ($source['finished_at'] ? 'synced ' . date('Y-m-d H:i:s', $source['finished_at']) : 'not synced yet')
                ));
            }
            $output->writeln(sprintf('  jobs: %d waiting, %d failed', $plugin->jobs()->waiting($shopId), $plugin->jobs()->failed($shopId)));
            if ($summary['error']) {
                $output->writeln(sprintf('  <comment>last error (%s): %s</comment>', date('Y-m-d H:i:s', $summary['error']['at']), $summary['error']['message']));
            }
        }

        return 0;
    }
}
