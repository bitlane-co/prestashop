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

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * php bin/console bitlane:sync [<source>] [--shop=<id>] [--now]: syncs a
 * source again in full, or every chosen one, in every shop in Bitlane or one;
 * --now runs the jobs here instead of leaving them to the cron.
 */
final class SyncCommand extends BitlaneCommand
{
    protected function configure(): void
    {
        $this->setName('bitlane:sync')
            ->setDescription('Syncs a source again in full (product, category, cms, brand, store), or every chosen one.')
            ->addArgument('source', InputArgument::OPTIONAL, 'The source; all chosen ones without')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'A shop’s id; every shop in Bitlane without')
            ->addOption('now', null, InputOption::VALUE_NONE, 'Run the jobs now, until they’re done');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $plugin = $this->plugin($output);
        if ($plugin === null) {
            return 1;
        }
        $shops = $input->getOption('shop') !== null ? [(int) $input->getOption('shop')] : $plugin->changes()->shops();
        $source = $input->getArgument('source');
        foreach ($shops as $shopId) {
            if ($plugin->connection()->integrationId($shopId) === null) {
                $output->writeln(sprintf('<error>Shop %d isn’t in Bitlane.</error>', $shopId));

                return 1;
            }
            $plugin->sync()->resync($shopId, is_string($source) ? $source : null);
            $output->writeln(sprintf('Shop %d: full sync started.', $shopId));
        }
        if ($input->getOption('now')) {
            while (($ran = $plugin->runner()->run(50)) > 0) {
                $output->writeln($ran . ' jobs run.');
            }
        }

        return 0;
    }
}
