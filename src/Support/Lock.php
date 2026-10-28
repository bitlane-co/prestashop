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

namespace Bitlane\PrestaShop\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * One request at a time through a piece of work, with MySQL's named locks
 * (GET_LOCK): a name held by one connection, not a table or row lock. The
 * lock goes away with the connection if PHP dies holding it.
 */
final class Lock
{
    /**
     * Runs $work holding the lock, waiting up to $wait seconds for it; without
     * it, runs it anyway (the server's grace window covers a second refresh).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function with(string $name, callable $work, int $wait = 10): mixed
    {
        $db = \Db::getInstance();
        $key = "'" . pSQL('bitlane_' . _DB_NAME_ . '_' . $name) . "'";
        $held = (int) $db->getValue('SELECT GET_LOCK(' . $key . ', ' . $wait . ')', false) === 1;
        try {
            return $work();
        } finally {
            if ($held) {
                $db->getValue('SELECT RELEASE_LOCK(' . $key . ')', false);
            }
        }
    }

    /**
     * Runs $work only if the lock is free now; false when another holds it.
     *
     * @param callable(): void $work
     */
    public function attempt(string $name, callable $work): bool
    {
        $db = \Db::getInstance();
        $key = "'" . pSQL('bitlane_' . _DB_NAME_ . '_' . $name) . "'";
        if ((int) $db->getValue('SELECT GET_LOCK(' . $key . ', 0)', false) !== 1) {
            return false;
        }
        try {
            $work();
        } finally {
            $db->getValue('SELECT RELEASE_LOCK(' . $key . ')', false);
        }

        return true;
    }
}
