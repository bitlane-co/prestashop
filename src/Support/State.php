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
 * What the module keeps, in its own table bitlane_state: a value by name,
 * for the whole install (shop 0) or one shop. Unlike Configuration, which
 * PrestaShop reads once per request, every read here goes to the database,
 * since another request may have refreshed the tokens meanwhile.
 */
final class State
{
    public const TABLE = 'bitlane_state';

    public function get(string $name, int $shopId = 0): ?string
    {
        $value = \Db::getInstance()->getValue(
            'SELECT `value` FROM `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' WHERE `id_shop` = ' . $shopId . " AND `name` = '" . pSQL($name) . "'",
            false
        );

        return is_string($value) ? $value : null;
    }

    /**
     * Stores a value; null removes it.
     */
    public function set(string $name, ?string $value, int $shopId = 0): void
    {
        $db = \Db::getInstance();
        if ($value === null) {
            $db->delete(self::TABLE, '`id_shop` = ' . $shopId . " AND `name` = '" . pSQL($name) . "'");

            return;
        }
        $db->insert(
            self::TABLE,
            ['id_shop' => $shopId, 'name' => pSQL($name), 'value' => pSQL($value, true), 'updated_at' => date('Y-m-d H:i:s')],
            false,
            false,
            \Db::ON_DUPLICATE_KEY
        );
    }

    /**
     * A JSON value, an empty array when there's none.
     *
     * @return array<string, mixed>
     */
    public function json(string $name, int $shopId = 0): array
    {
        $data = json_decode((string) $this->get($name, $shopId), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed>|null $value
     */
    public function setJson(string $name, ?array $value, int $shopId = 0): void
    {
        $this->set($name, $value === null ? null : (string) json_encode($value), $shopId);
    }

    /**
     * Removes a name from the install and every shop.
     */
    public function forget(string $name): void
    {
        \Db::getInstance()->delete(self::TABLE, "`name` = '" . pSQL($name) . "'");
    }

    /**
     * Removes everything kept for a shop (the install's, shop 0, stays).
     */
    public function forgetShop(int $shopId): void
    {
        if ($shopId > 0) {
            \Db::getInstance()->delete(self::TABLE, '`id_shop` = ' . $shopId);
        }
    }

    /**
     * Removes the names that start with $prefix and were last written before $before.
     */
    public function prune(string $prefix, int $before): void
    {
        \Db::getInstance()->delete(
            self::TABLE,
            "`name` LIKE '" . pSQL(addcslashes($prefix, '%_')) . "%' AND `updated_at` < '" . date('Y-m-d H:i:s', $before) . "'"
        );
    }
}
