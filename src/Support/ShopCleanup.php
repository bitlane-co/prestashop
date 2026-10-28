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
use Bitlane\PrestaShop\Storefront\Storefront;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What a disconnected shop forgets: everything the module keeps for it (its
 * site in Bitlane, setup choices, sync, credentials, the settings and
 * address last seen: every per-shop row of bitlane_state), its jobs, its
 * storefront (the widget and the switches) and its carts' sessions, so
 * connecting again starts from a fresh setup. A shop when it disconnects,
 * every shop when the install's grant ends (the last shop, or Bitlane ended
 * it). What was reported of orders stays: an order isn't reported twice.
 */
final class ShopCleanup
{
    public function __construct(private State $state, private Jobs $jobs, private Storefront $storefront)
    {
    }

    public function shop(int $shopId): void
    {
        $this->jobs->cancel($shopId);
        $this->state->forgetShop($shopId);
        $this->storefront->clear($shopId);
        $p = _DB_PREFIX_;
        \Db::getInstance()->execute(
            "DELETE bc FROM {$p}" . Events::CART_TABLE . " bc JOIN {$p}cart c ON c.id_cart = bc.id_cart WHERE c.id_shop = " . $shopId
        );
    }

    public function everyShop(): void
    {
        foreach (\Shop::getShops(false, null, true) as $shopId) {
            $this->shop((int) $shopId);
        }
    }
}
