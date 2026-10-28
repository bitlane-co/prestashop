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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * php bin/console bitlane:run [--seconds=50]: runs the module's due jobs for up
 * to that long (one runner at a time). The cron's command, every minute.
 */
final class RunCommand extends BitlaneCommand
{
    protected function configure(): void
    {
        $this->setName('bitlane:run')
            ->setDescription('Runs the Bitlane module’s waiting jobs: the catalogue’s sync.')
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'How long to run at most', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $plugin = $this->plugin($output);
        if ($plugin === null) {
            return 1;
        }
        $ran = $plugin->runner()->run(max(1, (int) $input->getOption('seconds')));
        $output->writeln($ran . ' jobs run.');

        return 0;
    }
}
