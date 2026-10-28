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
 * New versions of the module (docs/prestashop-plan.md PS16). The module
 * reads the info.json each release puts on the CDN (\Bitlane::UPDATES_URL,
 * or BITLANE_UPDATES_URL in config/defines_custom.inc.php) at most once a
 * day, when the Bitlane screen or Module Manager loads. Module Manager then
 * shows its Upgrade button (listing()), which installs the newer zip in
 * place; the screen offers it too.
 */
final class Updates
{
    public function __construct(private State $state, private Http $http, private string $version)
    {
    }

    /**
     * The newer version and its zip; null when this one is the latest or
     * the CDN can't be read.
     *
     * @return array{version: string, package: string}|null
     */
    public function available(): ?array
    {
        // The repository's own (dev/prestashop links it into the local shops):
        // an upgrade would unzip over the sources.
        if ($this->version === '0.0.0') {
            return null;
        }
        $kept = $this->state->json('update');
        if ((int) ($kept['at'] ?? 0) < time() - 86400) {
            $kept = ['at' => time()] + $this->latest();
            $this->state->setJson('update', $kept);
        }
        $version = (string) ($kept['version'] ?? '');
        $package = (string) ($kept['package'] ?? '');

        return $version !== '' && $package !== '' && version_compare($version, $this->version, '>')
            ? ['version' => $version, 'package' => $package]
            : null;
    }

    /**
     * The module in Module Manager's catalogue (the hook actionListModules)
     * when a newer version is out: its Upgrade button then downloads the
     * zip and upgrades in place. PrestaShop names the module after the zip's
     * file name, so the package is <version>/bitlane.zip.
     *
     * @return list<array{name: string, version_available: string, download_url: string}>
     */
    public function listing(): array
    {
        $update = $this->available();

        return $update ? [['name' => 'bitlane', 'version_available' => $update['version'], 'download_url' => $update['package']]] : [];
    }

    /**
     * @return array{version?: string, package?: string}
     */
    private function latest(): array
    {
        $url = defined('BITLANE_UPDATES_URL') ? (string) constant('BITLANE_UPDATES_URL') : \Bitlane::UPDATES_URL;
        try {
            $res = $this->http->request('GET', $url);
        } catch (BitlaneError) {
            return [];
        }
        $body = $res['body'];
        if ($res['status'] !== 200 || !preg_match('/^\d+\.\d+\.\d+$/', (string) ($body['version'] ?? '')) || !preg_match('#^https://#', (string) ($body['package'] ?? ''))) {
            return [];
        }

        return ['version' => (string) $body['version'], 'package' => (string) $body['package']];
    }
}
