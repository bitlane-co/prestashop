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

use Bitlane\PrestaShop\Plugin;
use PrestaShop\PrestaShop\Adapter\LegacyContextLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's console commands (php bin/console bitlane:…), registered in
 * config/services.yml. Symfony Console 4.4 (PrestaShop 8) and 6.4 (9) alike:
 * names are set in configure(), execute() returns an int.
 */
abstract class BitlaneCommand extends Command
{
    public function __construct(private LegacyContextLoader $contextLoader)
    {
        parent::__construct();
    }

    /**
     * The module's parts, with PrestaShop's legacy context loaded; null (and a
     * message) when the module isn't installed.
     */
    protected function plugin(OutputInterface $output): ?Plugin
    {
        $this->contextLoader->loadGenericContext();
        // An installed module has an id (Module::isInstalled() is deprecated in 9).
        $module = \Module::getInstanceByName('bitlane');
        if (!$module instanceof \Module || !$module->id) {
            $output->writeln('<error>The Bitlane module isn’t installed.</error>');

            return null;
        }

        return Plugin::instance($module);
    }
}
