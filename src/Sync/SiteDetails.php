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

use Bitlane\PrestaShop\Connection\BitlaneApi;
use Bitlane\PrestaShop\Connection\ConnectionStore;
use Bitlane\PrestaShop\Queue\Jobs;
use Bitlane\PrestaShop\Site\Site;
use Bitlane\PrestaShop\Support\State;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The shop's address and name in Bitlane. A shop moved to another domain or
 * path (Shop parameters › Multistore, a new domain) keeps its site there:
 * the job site_details sends them to PATCH /api/integrations/:id, which
 * refuses an address another site of the project has (the job then fails,
 * in the Jobs tab). Connecting with the new address would make another site.
 */
final class SiteDetails
{
    public const JOB = 'site_details';

    private const NAME = 'site_details';

    public function __construct(
        private State $state,
        private Site $site,
        private BitlaneApi $api,
        private ConnectionStore $connection,
        private Jobs $jobs,
    ) {
    }

    /**
     * Queues the update when the address or name changed since Bitlane got
     * them; the first look only notes them (connecting sent them).
     */
    public function check(int $shopId): void
    {
        if (!$this->connection->integrationId($shopId)) {
            return;
        }
        $kept = $this->state->json(self::NAME, $shopId);
        if (!$kept) {
            $this->state->setJson(self::NAME, $this->details($shopId), $shopId);
        } elseif ($kept !== $this->details($shopId)) {
            $this->jobs->push($shopId, self::JOB, [], 0, self::JOB);
        }
    }

    /**
     * The job: Bitlane gets them as they are now.
     */
    public function send(int $shopId): void
    {
        $id = $this->connection->integrationId($shopId);
        if (!$id) {
            return;
        }
        $details = $this->details($shopId);
        $this->api->request('PATCH', '/api/integrations/' . rawurlencode($id), $details);
        $this->state->setJson(self::NAME, $details, $shopId);
    }

    /**
     * @return array{site_url: string, site_name: string}
     */
    private function details(int $shopId): array
    {
        return ['site_url' => $this->site->url($shopId), 'site_name' => $this->site->name($shopId)];
    }
}
