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

namespace Bitlane\PrestaShop\Sync;

use Bitlane\PrestaShop\Support\BitlaneError;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Retries what can pass later, with backoff: no connection, 429, 401/403 (a
 * replaced write key, read again), and 5xx, 503 with Retry-After while an
 * engine upgrades (as the WordPress plugin does).
 */
final class Retry
{
    /**
     * Seconds until the next attempt, or null to give up (after 8).
     *
     * @param int $attempt attempts so far, 0 after the first
     */
    public static function after(BitlaneError $error, int $attempt): ?int
    {
        if ($attempt >= 8 || $error->kind === 'disconnected' || $error->kind === 'not_connected') {
            return null;
        }
        $status = (int) $error->status;
        if ($status !== 0 && $status !== 401 && $status !== 403 && $status !== 429 && $status < 500) {
            return null;
        }

        return $error->retryAfter > 0 ? $error->retryAfter : min(3600, 30 * (2 ** $attempt));
    }
}
