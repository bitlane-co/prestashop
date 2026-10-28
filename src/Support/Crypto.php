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
 * Encrypts with libsodium's secretbox, with a key derived from the shop's
 * _COOKIE_KEY_ (app/config/parameters.php): a copy of the database alone
 * doesn't give the tokens away. A new cookie key makes the stored connection
 * unreadable, and the shop connects again.
 */
final class Crypto
{
    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    /**
     * What encrypt() made; null when it can't be read.
     */
    public function decrypt(string $encrypted): ?string
    {
        $raw = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key());

        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        return hash('sha256', 'bitlane-connection|' . _COOKIE_KEY_, true);
    }
}
