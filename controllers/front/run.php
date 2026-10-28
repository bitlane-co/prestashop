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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Runs the module's due jobs (Runner): index.php?fc=module&module=bitlane&controller=run&token=…,
 * requested by the module itself (Runner::kick()) or by a cron. It answers
 * before PrestaShop builds a page, so the shop's maintenance mode doesn't
 * stop it, and keeps going when the caller stops waiting.
 *
 * @property Bitlane $module
 */
class BitlaneRunModuleFrontController extends ModuleFrontController
{
    /**
     * @return void
     */
    public function init()
    {
        $runner = Plugin::instance($this->module)->runner();
        if (!hash_equals($runner->secret(), (string) Tools::getValue('token'))) {
            http_response_code(403);
            exit;
        }
        ignore_user_abort(true);
        @set_time_limit(90);
        $ran = $runner->run(60);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['ran' => $ran]);
        exit;
    }
}
