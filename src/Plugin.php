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

namespace Bitlane\PrestaShop;

use Bitlane\PrestaShop\Connection\BitlaneApi;
use Bitlane\PrestaShop\Connection\ConnectionStore;
use Bitlane\PrestaShop\Connection\OAuthClient;
use Bitlane\PrestaShop\Http\ConnectionActions;
use Bitlane\PrestaShop\Http\ScreenTexts;
use Bitlane\PrestaShop\Queue\Jobs;
use Bitlane\PrestaShop\Queue\Runner;
use Bitlane\PrestaShop\Site\Settings;
use Bitlane\PrestaShop\Site\Site;
use Bitlane\PrestaShop\Sources\Sources;
use Bitlane\PrestaShop\Storefront\Events;
use Bitlane\PrestaShop\Storefront\Storefront;
use Bitlane\PrestaShop\Support\Crypto;
use Bitlane\PrestaShop\Support\Http;
use Bitlane\PrestaShop\Support\Installer;
use Bitlane\PrestaShop\Support\Lock;
use Bitlane\PrestaShop\Support\ShopCleanup;
use Bitlane\PrestaShop\Support\ShopContext;
use Bitlane\PrestaShop\Support\State;
use Bitlane\PrestaShop\Support\Translator;
use Bitlane\PrestaShop\Support\Updates;
use Bitlane\PrestaShop\Sync\Changes;
use Bitlane\PrestaShop\Sync\Check;
use Bitlane\PrestaShop\Sync\Credentials;
use Bitlane\PrestaShop\Sync\FullSync;
use Bitlane\PrestaShop\Sync\Indexer;
use Bitlane\PrestaShop\Sync\Meilisearch;
use Bitlane\PrestaShop\Sync\ShopSettings;
use Bitlane\PrestaShop\Sync\SiteDetails;
use Bitlane\PrestaShop\Sync\Sync;
use Bitlane\PrestaShop\Sync\SyncState;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Builds the module's parts with their dependencies, each once per request.
 * PrestaShop has no container shared by 8 and 9 that a module can rely on
 * (docs/prestashop-plan.md PS1), so this is ours.
 */
final class Plugin
{
    private static ?self $instance = null;

    private ?Http $http = null;
    private ?Installer $installer = null;
    private ?State $state = null;
    private ?Translator $translator = null;
    private ?ConnectionStore $connection = null;
    private ?OAuthClient $oauth = null;
    private ?BitlaneApi $api = null;
    private ?Site $site = null;
    private ?Settings $settings = null;
    private ?ScreenTexts $screenTexts = null;
    private ?ConnectionActions $actions = null;
    private ?Jobs $jobs = null;
    private ?Runner $runner = null;
    private ?ShopContext $shopContext = null;
    private ?Sources $sources = null;
    private ?SyncState $syncState = null;
    private ?Credentials $credentials = null;
    private ?Meilisearch $meilisearch = null;
    private ?Indexer $indexer = null;
    private ?FullSync $fullSync = null;
    private ?Changes $changes = null;
    private ?ShopSettings $shopSettings = null;
    private ?SiteDetails $siteDetails = null;
    private ?Check $check = null;
    private ?Sync $sync = null;
    private ?Storefront $storefront = null;
    private ?Events $events = null;
    private ?Updates $updates = null;
    private ?ShopCleanup $shopCleanup = null;

    private function __construct(private \Module $module)
    {
    }

    public static function instance(\Module $module): self
    {
        return self::$instance ??= new self($module);
    }

    /**
     * Forgets the parts: the next instance() builds them again (tests, each
     * with its own fake HTTP).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * HTTP to Bitlane and the engines; tests give a fake one.
     */
    public function http(?Http $http = null): Http
    {
        if ($http !== null) {
            $this->http = $http;
        }

        return $this->http ??= new Http();
    }

    public function installer(): Installer
    {
        return $this->installer ??= new Installer($this->module);
    }

    public function state(): State
    {
        return $this->state ??= new State();
    }

    public function translator(): Translator
    {
        return $this->translator ??= new Translator();
    }

    public function connection(): ConnectionStore
    {
        if ($this->connection === null) {
            $this->connection = new ConnectionStore($this->state(), new Crypto());
            // Built when needed: the cleanup's parts reach the connection too.
            $this->connection->onEnd(fn () => $this->shopCleanup()->everyShop());
        }

        return $this->connection;
    }

    public function shopCleanup(): ShopCleanup
    {
        return $this->shopCleanup ??= new ShopCleanup($this->state(), $this->jobs(), $this->storefront());
    }

    public function oauth(): OAuthClient
    {
        return $this->oauth ??= new OAuthClient(
            $this->connection(),
            $this->state(),
            $this->http(),
            new Lock(),
            $this->translator(),
            (string) $this->module->version,
        );
    }

    public function api(): BitlaneApi
    {
        return $this->api ??= new BitlaneApi($this->oauth(), $this->connection(), $this->http(), $this->translator());
    }

    public function site(): Site
    {
        return $this->site ??= new Site($this->translator(), $this->sources());
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->state(), $this->site());
    }

    public function screenTexts(): ScreenTexts
    {
        return $this->screenTexts ??= new ScreenTexts($this->translator());
    }

    public function jobs(): Jobs
    {
        return $this->jobs ??= new Jobs();
    }

    /**
     * The runner, with each job type's handler (built when a job needs it)
     * and each job's outcome noted for its shop's sync.
     */
    public function runner(): Runner
    {
        if ($this->runner === null) {
            $this->runner = new Runner($this->jobs(), new Lock(), $this->state());
            $this->runner->handle(Changes::JOB, fn (int $shop, array $args) => $this->changes()->run($shop, $args));
            $this->runner->handle(Changes::PRODUCTS_OF, fn (int $shop, array $args) => $this->changes()->runProductsOf($shop, $args));
            $this->runner->handle(FullSync::JOB, fn (int $shop, array $args) => $this->fullSync()->step($shop, $args));
            $this->runner->handle(FullSync::START, function (int $shop, array $args): void {
                $this->fullSync()->start($shop, (string) ($args[0] ?? ''));
            });
            $this->runner->handle(Check::JOB, function (int $shop): void {
                $this->check()->start($shop);
                $this->events()->prune();
            });
            $this->runner->handle(Check::SOURCE_JOB, fn (int $shop, array $args) => $this->check()->batch($shop, $args));
            $this->runner->handle(SiteDetails::JOB, fn (int $shop) => $this->siteDetails()->send($shop));
            $this->runner->handle(Storefront::JOB, fn (int $shop) => $this->storefront()->refresh($shop));
            $this->runner->handle(Events::ADDITION, fn (int $shop, array $args) => $this->events()->sendAdditions($shop, $args));
            $this->runner->handle(Events::PURCHASE, fn (int $shop, array $args) => $this->inShop($shop, fn () => $this->events()->sendPurchase($shop, $args)));
            $this->runner->handle(Events::REFUND, fn (int $shop, array $args) => $this->inShop($shop, fn () => $this->events()->sendRefund($shop, $args)));
            $this->runner->handle(Events::CANCELLATION, fn (int $shop, array $args) => $this->inShop($shop, fn () => $this->events()->sendCancellation($shop, $args)));
            $this->runner->onOutcome(fn (int $shop, $error) => $this->syncState()->error($shop, $error));
        }

        return $this->runner;
    }

    public function shopContext(): ShopContext
    {
        return $this->shopContext ??= new ShopContext();
    }

    /**
     * The order jobs read orders' lines, which PrestaShop rounds at the
     * context currency's precision: the runner's context has no currency
     * (its controller answers before PrestaShop sets one), so they run in the
     * shop's, in its default language.
     */
    private function inShop(int $shop, callable $work): mixed
    {
        return $this->shopContext()->in($shop, (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $shop), $work);
    }

    public function sources(): Sources
    {
        return $this->sources ??= new Sources($this->translator());
    }

    public function syncState(): SyncState
    {
        return $this->syncState ??= new SyncState($this->state());
    }

    public function credentials(): Credentials
    {
        return $this->credentials ??= new Credentials($this->api(), $this->connection(), $this->state(), new Crypto());
    }

    public function meilisearch(): Meilisearch
    {
        return $this->meilisearch ??= new Meilisearch($this->http());
    }

    public function indexer(): Indexer
    {
        return $this->indexer ??= new Indexer($this->sources(), $this->settings(), $this->site(), $this->credentials(), $this->meilisearch(), $this->shopContext());
    }

    public function fullSync(): FullSync
    {
        return $this->fullSync ??= new FullSync($this->indexer(), $this->sources(), $this->settings(), $this->site(), $this->syncState(), $this->credentials(), $this->meilisearch(), $this->jobs());
    }

    public function changes(): Changes
    {
        return $this->changes ??= new Changes($this->jobs(), $this->indexer(), $this->syncState(), $this->settings(), $this->connection());
    }

    public function shopSettings(): ShopSettings
    {
        return $this->shopSettings ??= new ShopSettings($this->state(), $this->settings(), $this->fullSync(), $this->site(), $this->siteDetails());
    }

    public function siteDetails(): SiteDetails
    {
        return $this->siteDetails ??= new SiteDetails($this->state(), $this->site(), $this->api(), $this->connection(), $this->jobs());
    }

    public function check(): Check
    {
        return $this->check ??= new Check($this->sources(), $this->settings(), $this->site(), $this->syncState(), $this->credentials(), $this->meilisearch(), $this->fullSync(), $this->changes(), $this->shopSettings(), $this->jobs());
    }

    public function sync(): Sync
    {
        return $this->sync ??= new Sync($this->fullSync(), $this->check(), $this->shopSettings(), $this->syncState(), $this->settings(), $this->credentials(), $this->jobs());
    }

    public function updates(): Updates
    {
        return $this->updates ??= new Updates($this->state(), $this->http(), (string) $this->module->version);
    }

    public function storefront(): Storefront
    {
        return $this->storefront ??= new Storefront($this->api(), $this->connection(), $this->jobs());
    }

    public function events(): Events
    {
        return $this->events ??= new Events($this->api(), $this->connection(), $this->site(), $this->settings(), $this->jobs());
    }

    public function actions(): ConnectionActions
    {
        return $this->actions ??= new ConnectionActions(
            $this->connection(),
            $this->oauth(),
            $this->api(),
            $this->site(),
            $this->settings(),
            $this->translator(),
            $this->sync(),
            $this->runner(),
            $this->jobs(),
            $this->storefront(),
            $this->updates(),
            $this->shopCleanup(),
        );
    }
}
