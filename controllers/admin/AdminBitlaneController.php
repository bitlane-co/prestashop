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

use Bitlane\PrestaShop\Plugin;
use Bitlane\PrestaShop\Support\BitlaneError;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The Bitlane screen (docs/prestashop-plan.md PS12): a legacy admin
 * controller, the same on PrestaShop 8 and 9. It serves the screen's React
 * app (views/src, built into views/js and views/css), Bitlane's OAuth
 * callback, and the app's JSON actions (ajax=1&action=…), with the
 * employee's token and PrestaShop's tab permissions: view to read, edit to
 * change.
 *
 * @property Bitlane $module
 */
class AdminBitlaneController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    /**
     * Bitlane's redirect to the callback can't carry the employee's URL
     * token (a client's redirect URI is fixed): the OAuth state, made for
     * this employee, is its check, and callback() requires them logged in.
     * PrestaShop 9 checks tokens before the controller runs, except for
     * controllers that allow anonymous requests; 8 asks checkToken().
     */
    public function isAnonymousAllowed()
    {
        return $this->isCallback() || parent::isAnonymousAllowed();
    }

    public function checkToken()
    {
        return $this->isCallback() || parent::checkToken();
    }

    public function postProcess()
    {
        if ($this->isCallback()) {
            $this->callback();
        }

        return parent::postProcess();
    }

    private function isCallback(): bool
    {
        return Tools::getValue('oauth') === 'callback' && Tools::getValue('state') && !$_POST;
    }

    /**
     * @return void
     */
    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $path = $this->module->getLocalPath() . 'views/';
        $uri = $this->module->getPathUri() . 'views/';
        if (is_file($path . 'js/admin.js')) {
            $this->addCSS($uri . 'css/admin.css?v=' . filemtime($path . 'css/admin.css'));
            $this->addJS($uri . 'js/admin.js?v=' . filemtime($path . 'js/admin.js'));
        }
    }

    /**
     * @return void
     */
    public function initContent()
    {
        parent::initContent();
        // The callback's outcome, once.
        $state = Plugin::instance($this->module)->state();
        $key = 'notice:' . (int) $this->context->employee->id;
        $notice = $state->json($key);
        $state->set($key, null);
        $this->context->smarty->assign('bitlane_config', json_encode([
            'actions' => $this->context->link->getAdminLink('AdminBitlane') . '&ajax=1',
            // With &setShopContext=s-<id>, the back office switches to that shop (multistore).
            'page' => $this->context->link->getAdminLink('AdminBitlane'),
            'notice' => $notice ?: null,
            'version' => $this->module->version,
            'translations' => Plugin::instance($this->module)->screenTexts()->all(),
            'logo' => $this->module->getPathUri() . 'views/img/logo.svg',
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $this->setTemplate('app.tpl');
    }

    public function ajaxProcessStatus(): void
    {
        $this->respond('view', fn () => $this->actions()->status($this->shopId()));
    }

    public function ajaxProcessAuthorize(): void
    {
        $this->respond('edit', fn () => ['authorize_url' => $this->actions()->authorize($this->requireShop(), (int) $this->context->employee->id)]);
    }

    public function ajaxProcessProjects(): void
    {
        $this->respond('view', fn () => $this->actions()->projects());
    }

    public function ajaxProcessProject(): void
    {
        $this->respond('edit', function () {
            $shopId = $this->requireShop();
            $this->actions()->connectShop($shopId, (string) ($this->input()['project_id'] ?? ''));

            return $this->actions()->status($shopId);
        });
    }

    public function ajaxProcessEngines(): void
    {
        $this->respond('view', fn () => $this->actions()->engines($this->requireShop()));
    }

    public function ajaxProcessSetup(): void
    {
        $this->respond('edit', fn () => $this->actions()->setup($this->requireShop(), $this->input()));
    }

    public function ajaxProcessResync(): void
    {
        $this->respond('edit', function () {
            $source = $this->input()['source'] ?? null;

            return $this->actions()->resync($this->requireShop(), is_string($source) && $source !== '' ? $source : null);
        });
    }

    public function ajaxProcessStorefront(): void
    {
        $this->respond('edit', fn () => $this->actions()->storefront($this->requireShop(), $this->input()));
    }

    public function ajaxProcessRun(): void
    {
        $this->respond('view', fn () => $this->actions()->run($this->requireShop()));
    }

    public function ajaxProcessJobs(): void
    {
        $this->respond('view', fn () => $this->actions()->jobs($this->requireShop()));
    }

    public function ajaxProcessJobAgain(): void
    {
        $this->respond('edit', fn () => $this->actions()->jobAgain($this->requireShop(), (int) ($this->input()['id'] ?? 0)));
    }

    public function ajaxProcessJobDelete(): void
    {
        $this->respond('edit', fn () => $this->actions()->jobDelete($this->requireShop(), (int) ($this->input()['id'] ?? 0)));
    }

    public function ajaxProcessDisconnect(): void
    {
        $this->respond('edit', function () {
            $shopId = $this->requireShop();
            $this->actions()->disconnect($shopId);

            return $this->actions()->status($shopId);
        });
    }

    /**
     * Bitlane's answer to Connect: exchanges the code, connects the shop when
     * the grant names its project, and goes back to the screen with the
     * outcome as a notice.
     */
    private function callback(): void
    {
        $query = [];
        foreach (['code', 'state', 'iss', 'error', 'error_description'] as $key) {
            if (is_string($value = Tools::getValue($key, null)) && $value !== '') {
                $query[$key] = $value;
            }
        }
        $plugin = Plugin::instance($this->module);
        $employee = $this->context->employee;
        if (!$employee || !$employee->isLoggedBack()) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminLogin', false));
        }
        try {
            $shopId = $plugin->oauth()->handleCallback($query, (int) $employee->id);
            if (!empty($plugin->connection()->get()['project_id'])) {
                $plugin->actions()->connectShop($shopId);
            }
            $notice = ['type' => 'success', 'message' => $this->module->getTranslator()->trans('Connected to Bitlane.', [], 'Modules.Bitlane.Admin')];
        } catch (BitlaneError $e) {
            $notice = ['type' => 'error', 'message' => $e->getMessage()];
        }
        $plugin->state()->setJson('notice:' . (int) $employee->id, $notice);
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminBitlane'));
    }

    /**
     * Answers an action as JSON: its result, or {code, message, status,
     * errors} with the error's status (403 without the permission). It ends
     * the request itself: PrestaShop 9 wraps a legacy controller's output in
     * a response of its own, always 200.
     */
    private function respond(string $permission, callable $action): void
    {
        try {
            if (!$this->access($permission)) {
                throw new BitlaneError('forbidden', $this->module->getTranslator()->trans('You do not have permission to do this.', [], 'Modules.Bitlane.Admin'), 403);
            }
            $body = $action();
        } catch (BitlaneError $e) {
            http_response_code($e->status && $e->status >= 400 && $e->status < 500 ? $e->status : 502);
            $body = $e->toArray();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Bitlane: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), 3, null, 'Bitlane');
            http_response_code(500);
            $body = (new BitlaneError('unexpected', $this->module->getTranslator()->trans('Something went wrong. The details are in Advanced Parameters › Logs.', [], 'Modules.Bitlane.Admin')))->toArray();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body);
        exit;
    }

    private function actions(): Bitlane\PrestaShop\Http\ConnectionActions
    {
        return Plugin::instance($this->module)->actions();
    }

    /**
     * The shop the back office is working on; null for all shops or a group
     * of them in a multistore install.
     */
    private function shopId(): ?int
    {
        if (!Shop::isFeatureActive()) {
            return (int) Configuration::get('PS_SHOP_DEFAULT');
        }

        return Shop::getContext() === Shop::CONTEXT_SHOP ? (int) Shop::getContextShopID() : null;
    }

    private function requireShop(): int
    {
        return $this->shopId() ?? throw new BitlaneError('no_shop', $this->module->getTranslator()->trans('Choose a shop first.', [], 'Modules.Bitlane.Admin'), 400);
    }

    /**
     * The action's JSON body.
     *
     * @return array<string, mixed>
     */
    private function input(): array
    {
        $data = json_decode((string) file_get_contents('php://input'), true);

        return is_array($data) ? $data : [];
    }
}
