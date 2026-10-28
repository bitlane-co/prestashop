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

namespace Bitlane\PrestaShop\Storefront;

use Bitlane\PrestaShop\Connection\BitlaneApi;
use Bitlane\PrestaShop\Connection\ConnectionStore;
use Bitlane\PrestaShop\Queue\Jobs;
use Bitlane\PrestaShop\Support\BitlaneError;
use Bitlane\PrestaShop\Support\Hooks;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The search widget on the shop's pages (docs/prestashop-plan.md PS13): the
 * snippet from displayHeader, window.BitlaneWidgetConfig, the queue for
 * Bitlane.track() calls made before the loader has run, and the loader as a
 * module script. Pages never call Bitlane: the shop's active widget and its
 * loader are kept in Configuration (BITLANE_STOREFRONT, per shop, which
 * PrestaShop reads once per request), from the integration's details each
 * time the Bitlane screen loads and every hour (job storefront).
 *
 * The merchant switches it off on the screen, developers per page with the
 * hook actionBitlaneShowWidget. With "Wait for consent", tracking starts off
 * until consent: the widget follows Google Consent Mode's analytics_storage,
 * which most consent modules set, on every page; anything else calls
 * Bitlane.consent() itself.
 */
final class Storefront
{
    public const JOB = 'storefront';

    private const KEY = 'BITLANE_STOREFRONT';

    public function __construct(private BitlaneApi $api, private ConnectionStore $connection, private Jobs $jobs)
    {
    }

    /**
     * What's kept for the shop: the merchant's switches and its active widget.
     *
     * @return array{enabled: bool, consent: bool, widget_id: string, widget_title: string, loader_url: string}
     */
    public function get(int $shopId): array
    {
        $stored = json_decode((string) \Configuration::get(self::KEY, null, null, $shopId), true);
        $stored = is_array($stored) ? $stored : [];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? true),
            'consent' => (bool) ($stored['consent'] ?? false),
            'widget_id' => (string) ($stored['widget_id'] ?? ''),
            'widget_title' => (string) ($stored['widget_title'] ?? ''),
            'loader_url' => (string) ($stored['loader_url'] ?? ''),
        ];
    }

    /**
     * The merchant's switches.
     */
    public function configure(int $shopId, bool $enabled, bool $consent): void
    {
        $this->save($shopId, ['enabled' => $enabled, 'consent' => $consent]);
    }

    /**
     * Keeps the shop's first active widget from its integration's details,
     * with the loader from its install snippet, and its hourly refresh.
     *
     * @param array<mixed> $integration GET /api/integrations/:id
     */
    public function remember(int $shopId, array $integration): void
    {
        $widget = ['widget_id' => '', 'widget_title' => '', 'loader_url' => ''];
        foreach ((array) ($integration['widgets'] ?? []) as $candidate) {
            if (($candidate['status'] ?? '') === 'active' && preg_match('/<script[^>]+src="(https:\/\/[^"]+)"/', (string) ($candidate['install_snippet'] ?? ''), $match)) {
                $widget = ['widget_id' => (string) $candidate['id'], 'widget_title' => (string) ($candidate['title'] ?? ''), 'loader_url' => $match[1]];
                break;
            }
        }
        $this->save($shopId, $widget);
        $this->jobs->push($shopId, self::JOB, [], 3600, self::JOB);
    }

    /**
     * The hourly refresh: the widget may have been switched off or on in
     * Bitlane. Bitlane being unreachable keeps what's known (the job retries).
     */
    public function refresh(int $shopId): void
    {
        $id = $this->connection->integrationId($shopId);
        if (!$id || !$this->connection->connected()) {
            $this->forget($shopId);

            return;
        }
        try {
            $this->remember($shopId, $this->api->request('GET', '/api/integrations/' . rawurlencode($id)));
        } catch (BitlaneError $error) {
            if ($error->status === 404) {
                $this->forget($shopId);

                return;
            }
            throw $error;
        }
    }

    /**
     * Bitlane no longer has the shop's site: no widget, and no refresh. The
     * switches stay (a disconnect clears them, clear()).
     */
    public function forget(int $shopId): void
    {
        $this->save($shopId, ['widget_id' => '', 'widget_title' => '', 'loader_url' => '']);
        $this->jobs->cancel($shopId, [self::JOB]);
    }

    /**
     * The shop disconnected for good (ShopCleanup): the switches go too.
     */
    public function clear(int $shopId): void
    {
        $this->jobs->cancel($shopId, [self::JOB]);
        \Db::getInstance()->delete('configuration', "name = '" . self::KEY . "' AND id_shop = " . $shopId);
        \Configuration::resetStaticCache();
    }

    /**
     * The snippet for the page: empty when switched off, without an active
     * widget, or left out by actionBitlaneShowWidget (show by reference, the
     * page's controller).
     */
    public function snippet(int $shopId, string $controller): string
    {
        $stored = $this->get($shopId);
        if (!$stored['enabled'] || $stored['widget_id'] === '' || $stored['loader_url'] === ''
            || !Hooks::filter('actionBitlaneShowWidget', 'show', true, ['controller' => $controller])) {
            return '';
        }

        $config = ['id' => $stored['widget_id']];
        if ($stored['consent']) {
            $config['tracking'] = false;
        }
        $json = (string) json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<script>window.BitlaneWidgetConfig = ' . $json . ';'
            . 'window.Bitlane = window.Bitlane || { q: [], track: function () { this.q.push(arguments) } };</script>' . "\n"
            . '<script type="module" src="' . htmlspecialchars($stored['loader_url'], ENT_QUOTES) . '"></script>' . "\n";
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function save(int $shopId, array $changes): void
    {
        \Configuration::updateValue(self::KEY, (string) json_encode(array_merge($this->get($shopId), $changes)), false, null, $shopId);
    }
}
