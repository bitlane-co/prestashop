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

namespace Bitlane\PrestaShop\Http;

use Bitlane\PrestaShop\Connection\BitlaneApi;
use Bitlane\PrestaShop\Connection\ConnectionStore;
use Bitlane\PrestaShop\Connection\OAuthClient;
use Bitlane\PrestaShop\Queue\Jobs;
use Bitlane\PrestaShop\Queue\Runner;
use Bitlane\PrestaShop\Site\Settings;
use Bitlane\PrestaShop\Site\Site;
use Bitlane\PrestaShop\Storefront\Storefront;
use Bitlane\PrestaShop\Support\BitlaneError;
use Bitlane\PrestaShop\Support\ShopCleanup;
use Bitlane\PrestaShop\Support\Translator;
use Bitlane\PrestaShop\Support\Updates;
use Bitlane\PrestaShop\Sync\Sync;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the Bitlane screen asks for (AdminBitlaneController's JSON actions),
 * for one shop: the grant is the install's, each shop is a site of its own
 * in Bitlane (docs/prestashop-plan.md PS4). Tokens and keys never leave the
 * server.
 */
final class ConnectionActions
{
    public function __construct(
        private ConnectionStore $connection,
        private OAuthClient $oauth,
        private BitlaneApi $api,
        private Site $site,
        private Settings $settings,
        private Translator $translator,
        private Sync $sync,
        private Runner $runner,
        private Jobs $jobs,
        private Storefront $storefront,
        private Updates $updates,
        private ShopCleanup $cleanup,
    ) {
    }

    /**
     * Everything the screen shows for a shop; without one (all shops in a
     * multistore install), the shops to pick from.
     *
     * @return array<string, mixed>
     */
    public function status(?int $shopId): array
    {
        $connection = $this->connection->get();
        $integration = null;
        $error = null;

        $integrationId = $shopId ? $this->connection->integrationId($shopId) : null;
        if (!empty($connection['refresh_token']) && $integrationId) {
            try {
                $integration = $this->api->request('GET', '/api/integrations/' . rawurlencode($integrationId));
            } catch (BitlaneError $e) {
                if ($e->status === 404) {
                    // The shop belongs to another connection now: it connects again.
                    $this->connection->setIntegrationId((int) $shopId, null);
                    $integrationId = null;
                } else {
                    $error = $e->getMessage();
                }
            }
            // A refused token may have ended the connection meanwhile.
            $connection = $this->connection->get();
            $integrationId = $this->connection->integrationId($shopId);
        }
        $connected = !empty($connection['refresh_token']);
        $ready = $connected && $shopId && ($integration['setup_state'] ?? '') === 'ready';
        if ($ready) {
            $this->sync->ready($shopId, (string) ($integration['engine_id'] ?? ''));
            $this->storefront->remember($shopId, $integration);
        }

        return [
            'connected' => $connected,
            'revoked' => !empty($connection['revoked']),
            'needs_project' => $connected && $shopId && !$integrationId,
            'integration' => $integration,
            'error' => $error,
            'app_url' => $connected ? $this->oauth->appUrl() : null,
            'shop' => $shopId ? $this->shop($shopId) : null,
            'shops' => array_map(fn (array $shop) => [
                'id' => (int) $shop['id_shop'],
                'name' => (string) $shop['name'],
                'url' => $this->site->url((int) $shop['id_shop']),
                'connected' => $connected && $this->connection->integrationId((int) $shop['id_shop']) !== null,
            ], array_values(\Shop::getShops(false))),
            'settings' => $shopId ? $this->settings->get($shopId) : null,
            'sync' => $ready ? $this->sync->summary($shopId) : null,
            // The widget on the shop's pages: the switches and the widget found.
            'storefront' => $ready ? $this->storefront->get($shopId) : null,
            // A newer version of the module, to download and upload.
            'update' => $this->updates->available(),
            // The cron that runs the sync, and whether it does.
            'runner' => $connected ? [
                'cron' => $this->runner->cronLine(),
                'url' => $this->runner->url(),
                'last_run' => $this->runner->lastRun() ?: null,
                'waiting_since' => $this->runner->waitingSince() ?: null,
                // Jobs due for a quarter of an hour: no cron runs them.
                'late' => $this->runner->waitingSince() > 0 && $this->runner->waitingSince() < time() - 900,
                'failed' => $shopId ? $this->jobs->failed($shopId) : 0,
            ] : null,
        ];
    }

    /**
     * Syncs a source again, or every chosen one.
     *
     * @return array<string, mixed> the status
     */
    public function resync(int $shopId, ?string $source): array
    {
        $this->sync->resync($shopId, $source);

        return $this->status($shopId);
    }

    /**
     * The storefront's switches: the widget on the pages, tracking waiting
     * for consent.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed> the status
     */
    public function storefront(int $shopId, array $input): array
    {
        $this->storefront->configure($shopId, !empty($input['enabled']), !empty($input['consent']));

        return $this->status($shopId);
    }

    /**
     * Runs waiting jobs for a few seconds: the screen does it while it's open,
     * so a shop that can't start a runner itself still syncs.
     *
     * @return array<string, mixed> the status
     */
    public function run(int $shopId): array
    {
        $this->runner->run(8);

        return $this->status($shopId);
    }

    /**
     * The shop's jobs for the Jobs tab: waiting, running and failed (a week).
     *
     * @return array{jobs: list<array<string, mixed>>, waiting: int, failed: int}
     */
    public function jobs(int $shopId): array
    {
        return ['jobs' => $this->jobs->list($shopId), 'waiting' => $this->jobs->waiting($shopId), 'failed' => $this->jobs->failed($shopId)];
    }

    /**
     * Runs a failed job again.
     *
     * @return array{jobs: list<array<string, mixed>>, waiting: int, failed: int}
     */
    public function jobAgain(int $shopId, int $id): array
    {
        $this->jobs->again($shopId, $id);

        return $this->jobs($shopId);
    }

    /**
     * Deletes a job that isn't running.
     *
     * @return array{jobs: list<array<string, mixed>>, waiting: int, failed: int}
     */
    public function jobDelete(int $shopId, int $id): array
    {
        $this->jobs->delete($shopId, $id);

        return $this->jobs($shopId);
    }

    /**
     * The consent page's URL, to start a connection from this shop.
     */
    public function authorize(int $shopId, int $employeeId): string
    {
        return $this->oauth->authorizeUrl($employeeId, $shopId);
    }

    /**
     * The user's projects, for a shop without a site in Bitlane yet (a grant
     * on one project lists that one only).
     *
     * @return list<array{id: string, title: string}>
     */
    public function projects(): array
    {
        $projects = $this->api->request('GET', '/api/projects');

        return array_map(static fn (array $project) => [
            'id' => (string) $project['id'],
            'title' => (string) ($project['title'] ?? $project['name'] ?? $project['id']),
        ], array_values($projects['data'] ?? []));
    }

    /**
     * Connects the shop in Bitlane (the grant's site for its address, created
     * or found), in the grant's project or the given one.
     */
    public function connectShop(int $shopId, ?string $projectId = null): void
    {
        $body = ['platform' => 'prestashop', 'site_url' => $this->site->url($shopId), 'site_name' => $this->site->name($shopId)];
        if ($projectId) {
            $body['project_id'] = $projectId;
        }
        $integration = $this->api->request('POST', '/api/integrations', $body);
        $this->connection->setIntegrationId($shopId, (string) $integration['id']);
    }

    /**
     * The project's engines, for the setup.
     *
     * @return list<array{id: string, title: string, status: string}>
     */
    public function engines(int $shopId): array
    {
        $project = $this->project($shopId);
        $engines = $this->api->request('GET', '/api/engines', null, $project ? ['project_id' => $project] : []);

        return array_map(static fn (array $engine) => [
            'id' => (string) $engine['id'],
            'title' => (string) $engine['title'],
            'status' => (string) $engine['status'],
        ], array_values($engines['data'] ?? []));
    }

    /**
     * Saves the shop's setup and sends it to Bitlane, which sets the shop up
     * in the background: engine, indexes, keys and widget.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed> the status
     */
    public function setup(int $shopId, array $input): array
    {
        $integrationId = $this->connection->integrationId($shopId);
        if (!$integrationId) {
            throw new BitlaneError('not_connected', $this->translator->trans('Connect the shop to Bitlane first.', [], 'Modules.Bitlane.Admin'), 409);
        }

        $kinds = array_column($this->site->sources($shopId), 'kind', 'id');
        $sources = array_values(array_intersect($this->strings($input['sources'] ?? []), array_keys($kinds)));
        if (!$sources) {
            throw new BitlaneError('no_sources', $this->translator->trans('Choose at least one kind of content to search.', [], 'Modules.Bitlane.Admin'), 400);
        }
        $outside = array_values(array_intersect($this->strings($input['outside_widget'] ?? []), $sources));
        $offered = array_column($this->site->facets($sources), 'sources', 'attribute');
        $facets = array_values(array_intersect($this->strings($input['facets'] ?? []), array_keys($offered)));
        $sorting = $this->sorting($sources, $kinds, is_array($input['sorting'] ?? null) ? $input['sorting'] : []);
        $engineId = is_string($input['engine_id'] ?? null) && $input['engine_id'] !== '' ? $input['engine_id'] : null;
        $selector = trim(strip_tags((string) ($input['search_selector'] ?? '')));
        $color = preg_match('/^#(?:[0-9a-f]{3}){1,2}$/i', (string) ($input['brand_color'] ?? '')) ? (string) $input['brand_color'] : '';
        $logo = filter_var((string) ($input['logo_url'] ?? ''), FILTER_VALIDATE_URL) && preg_match('#^https?://#', (string) $input['logo_url']) ? (string) $input['logo_url'] : '';
        $documents = in_array($input['product_documents'] ?? null, Settings::PRODUCT_DOCUMENTS, true) ? (string) $input['product_documents'] : Settings::PER_VARIATION;

        $languages = $this->site->languages($shopId);
        $currency = $this->site->currency($shopId);
        $labels = [];
        foreach ($languages as $language) {
            foreach ($this->site->facets($sources, $language['id']) as $facet) {
                $labels[$facet['attribute']][$language['code']] = $facet['label'];
            }
        }

        $setup = [
            // Each with its own indexes, all in the shop's currency, shown as
            // the shop shows prices in that language.
            'languages' => array_map(fn (array $language) => array_filter([
                'code' => $language['code'],
                'currency' => $currency,
                'format' => $this->site->currencyFormat($shopId, $language['id']),
            ]), $languages),
            // Each source has its own index; the widget searches those not
            // kept for the shop's own use.
            'sources' => array_map(static fn (string $id) => [
                'id' => $id,
                'kind' => $kinds[$id],
                'widget' => !in_array($id, $outside, true),
                'sorting' => $sorting[$id],
            ], $sources),
            // In the order chosen (the widget shows them so), each a filter
            // of its sources' indexes only.
            'facets' => array_map(static fn (string $attribute) => [
                'attribute' => $attribute,
                'labels' => $labels[$attribute] ?? new \stdClass(),
                'sources' => $offered[$attribute],
            ], $facets),
        ];
        if ($selector !== '') {
            $setup['search_selector'] = $selector;
        }
        if ($engineId) {
            $setup['engine_id'] = $engineId;
        }
        // Bitlane uses them for a widget without colours or a logo only.
        $brand = array_filter(['primary' => $color, 'logo_url' => $logo]);
        if ($brand) {
            $setup['brand'] = $brand;
        }

        $this->api->request('PUT', '/api/integrations/' . rawurlencode($integrationId) . '/setup', $setup);
        $this->settings->save($shopId, [
            'engine_id' => $engineId,
            'sources' => $sources,
            'outside_widget' => $outside,
            'facets' => $facets,
            'sorting' => $sorting,
            'search_selector' => $selector,
            'brand_color' => $color,
            'logo_url' => $logo,
            'product_documents' => $documents,
            'languages' => array_column($languages, 'code'),
        ]);

        return $this->status($shopId);
    }

    /**
     * Disconnects the shop: Bitlane deletes its keys; the engine, indexes and
     * documents stay. The shop forgets everything about Bitlane (ShopCleanup),
     * setup choices included. The grant ends with the install's last
     * connected shop, which cleans every shop the same way.
     */
    public function disconnect(int $shopId): void
    {
        $integrationId = $this->connection->integrationId($shopId);
        if ($integrationId) {
            try {
                $this->api->request('DELETE', '/api/integrations/' . rawurlencode($integrationId));
            } catch (BitlaneError) {
                // Gone already, or Bitlane can't be reached: the shop forgets it anyway.
            }
        }
        // Everything the module kept for the shop: connecting again starts afresh.
        $this->cleanup->shop($shopId);
        $others = array_filter(\Shop::getShops(false, null, true), fn ($id) => $this->connection->integrationId((int) $id) !== null);
        if (!$others) {
            $this->oauth->revoke();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function shop(int $shopId): array
    {
        $sources = $this->site->sources($shopId);

        return [
            'id' => $shopId,
            'name' => $this->site->name($shopId),
            'url' => $this->site->url($shopId),
            'languages' => array_map(static fn ($l) => ['code' => $l['code'], 'name' => $l['name']], $this->site->languages($shopId)),
            'currency' => $this->site->currency($shopId),
            'sources' => $sources,
            'facets' => $this->site->facets(array_column($sources, 'id')),
            'sorting' => $this->site->sorting(),
            'logo_url' => $this->site->logoUrl($shopId),
        ];
    }

    /**
     * The project of the shop's site, else the grant's.
     */
    private function project(int $shopId): ?string
    {
        $integrationId = $this->connection->integrationId($shopId);
        if ($integrationId) {
            $integration = $this->api->request('GET', '/api/integrations/' . rawurlencode($integrationId));
            if (!empty($integration['project_id'])) {
                return (string) $integration['project_id'];
            }
        }
        $project = $this->connection->get()['project_id'] ?? null;

        return is_string($project) ? $project : null;
    }

    /**
     * Each source's sort choices: those asked for that its kind has, in their
     * order, else its kind's defaults.
     *
     * @param list<string> $sources
     * @param array<string, string> $kinds source => its kind
     * @param array<mixed> $chosen source => the choices asked for
     *
     * @return array<string, list<string>>
     */
    private function sorting(array $sources, array $kinds, array $chosen): array
    {
        $offered = $this->site->sorting();
        $sorting = [];
        foreach ($sources as $source) {
            $kind = $offered[$kinds[$source]];
            $sorting[$source] = isset($chosen[$source]) && is_array($chosen[$source])
                ? array_values(array_intersect($this->strings($chosen[$source]), array_column($kind['choices'], 'id')))
                : $kind['defaults'];
        }

        return $sorting;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $list): array
    {
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
