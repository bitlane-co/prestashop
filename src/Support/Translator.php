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
 * The module's texts through PrestaShop's translator, in the employee's
 * language or a given locale (filter names go to Bitlane in each of the
 * shop's languages). Italian ships in translations/it-IT; merchants change
 * any text in International › Translations.
 *
 * Calls are written ->trans('Text', [], 'Modules.Bitlane.Admin'), the domain
 * in full: that's how PrestaShop finds a module's texts for that page.
 */
final class Translator
{
    /**
     * @param array<string, string|int> $params %name% => value
     */
    public function trans(string $text, array $params, string $domain, ?string $locale = null): string
    {
        return \Context::getContext()->getTranslator()->trans($text, $params, $domain, $locale);
    }
}
