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

use Bitlane\PrestaShop\Queue\Jobs;
use Bitlane\PrestaShop\Storefront\Events;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's tables and hooks. PrestaShop doesn't run install() again on an
 * upgrade: a hook or table added later also goes in an upgrade/ script.
 */
final class Installer
{
    /** The hooks the module listens to (its hook*() methods; a test checks they match). */
    public const HOOKS = [
        'displayBackOfficeHeader',
        'actionObjectProductAddAfter', 'actionObjectProductUpdateAfter', 'actionObjectProductDeleteAfter',
        'actionProductUpdate', 'actionAfterUpdateProductFormHandler', 'actionAfterCreateProductFormHandler',
        'actionObjectCombinationAddAfter', 'actionObjectCombinationUpdateAfter', 'actionObjectCombinationDeleteAfter',
        'actionUpdateQuantity',
        'actionObjectImageAddAfter', 'actionObjectImageUpdateAfter', 'actionObjectImageDeleteAfter',
        'actionObjectSpecificPriceAddAfter', 'actionObjectSpecificPriceUpdateAfter', 'actionObjectSpecificPriceDeleteAfter',
        'actionValidateOrder',
        'actionObjectCategoryAddAfter', 'actionObjectCategoryUpdateAfter', 'actionObjectCategoryDeleteAfter',
        'actionObjectManufacturerAddAfter', 'actionObjectManufacturerUpdateAfter', 'actionObjectManufacturerDeleteAfter',
        'actionObjectProductAttributeUpdateAfter', 'actionObjectProductAttributeDeleteAfter',
        'actionObjectAttributeGroupUpdateAfter', 'actionObjectFeatureUpdateAfter', 'actionObjectFeatureValueUpdateAfter',
        'actionObjectCMSAddAfter', 'actionObjectCMSUpdateAfter', 'actionObjectCMSDeleteAfter',
        'actionObjectStoreAddAfter', 'actionObjectStoreUpdateAfter', 'actionObjectStoreDeleteAfter',
        // Called by modules with sources of their own.
        'actionBitlaneItemsChanged',
        // The storefront: the widget, and the events sent from the server.
        'displayHeader', 'actionCartUpdateQuantityBefore', 'actionOrderStatusPostUpdate', 'actionOrderSlipAdd',
        // Module Manager's Upgrade button (Support\Updates).
        'actionListModules',
    ];

    public function __construct(private \Module $module)
    {
    }

    public function install(): bool
    {
        $db = \Db::getInstance();
        $table = ' ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // Small values only (the connection, a shop's settings, states of a
        // few KB): TEXT takes 64 KB, and strict MySQL refuses more.
        $state = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . State::TABLE . '` (
            `id_shop` INT UNSIGNED NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `value` TEXT NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_shop`, `name`)
        )' . $table;

        // Jobs (Queue\Jobs): arguments are ids and cursors, a few KB at most.
        // A job that gave up stays a week with its error (failed_at).
        $jobs = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . Jobs::TABLE . '` (
            `id_job` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `type` VARCHAR(32) NOT NULL,
            `args` TEXT NOT NULL,
            `job_key` VARCHAR(64) NULL,
            `run_at` DATETIME NOT NULL,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `locked_until` DATETIME NULL,
            `lock_token` CHAR(32) NULL,
            `last_error` VARCHAR(1000) NULL,
            `failed_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_job`),
            KEY `run_at` (`run_at`, `id_job`),
            KEY `lock_token` (`lock_token`),
            KEY `shop_type` (`id_shop`, `type`),
            KEY `shop_key` (`id_shop`, `job_key`),
            KEY `failed_at` (`failed_at`)
        )' . $table;

        // The widget's session on each cart a shopper added to (Storefront\Events).
        $carts = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . Events::CART_TABLE . '` (
            `id_cart` INT UNSIGNED NOT NULL,
            `session_id` CHAR(36) NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_cart`),
            KEY `date_upd` (`date_upd`)
        )' . $table;

        // What was reported of each order: its purchase, its cancellation.
        $orders = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . Events::ORDER_TABLE . '` (
            `id_order` INT UNSIGNED NOT NULL,
            `purchase_at` DATETIME NULL,
            `cancel_at` DATETIME NULL,
            PRIMARY KEY (`id_order`)
        )' . $table;

        return $db->execute($state) && $db->execute($jobs) && $this->addJobColumns()
            && $db->execute($carts) && $db->execute($orders)
            && $this->module->registerHook(self::HOOKS);
    }

    /**
     * The failed jobs' columns on a table made before them (the local shops;
     * releases do it in an upgrade/ script).
     */
    private function addJobColumns(): bool
    {
        $db = \Db::getInstance();
        $table = '`' . _DB_PREFIX_ . Jobs::TABLE . '`';
        $columns = array_column($db->executeS('SHOW COLUMNS FROM ' . $table, true, false) ?: [], 'Field');
        if (in_array('failed_at', $columns, true)) {
            return true;
        }

        return $db->execute('ALTER TABLE ' . $table . ' ADD `last_error` VARCHAR(1000) NULL AFTER `lock_token`, ADD `failed_at` DATETIME NULL AFTER `last_error`, ADD KEY `failed_at` (`failed_at`)');
    }

    /**
     * Forgets the connection, settings and waiting jobs. The shops' engines,
     * indexes and documents stay in Bitlane (disconnect a shop there).
     */
    public function uninstall(): bool
    {
        $db = \Db::getInstance();

        \Configuration::deleteByName('BITLANE_STOREFRONT');

        return $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . State::TABLE . '`')
            && $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . Jobs::TABLE . '`')
            && $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . Events::CART_TABLE . '`')
            && $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . Events::ORDER_TABLE . '`');
    }
}
