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
 * The module's hooks for developers (actionBitlane*), PrestaShop's way: the
 * value goes in the hook's parameters by reference, under $name, for modules
 * to change.
 */
final class Hooks
{
    /**
     * Runs the hook and returns the value as the modules left it, of any type
     * (a module may set null): the caller checks it.
     *
     * @param array<string, mixed> $params the hook's other parameters
     */
    public static function filter(string $hook, string $name, mixed $value, array $params = []): mixed
    {
        $params[$name] = &$value;
        \Hook::exec($hook, $params);

        return $value;
    }
}
