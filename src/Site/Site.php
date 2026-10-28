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

namespace Bitlane\PrestaShop\Site;

use Bitlane\PrestaShop\Sources\Sources;
use Bitlane\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A shop as the setup screen offers it (docs/prestashop-plan.md PS5, PS10,
 * PS11): its address, languages, currency, the content it can search with
 * their counts (built in and modules' own, Sources), and the filters each
 * has. Every shop of a multistore install is a site of its own in Bitlane.
 */
final class Site
{
    public function __construct(private Translator $translator, private Sources $sources)
    {
    }

    /**
     * The shop's address, with its virtual path, without a trailing slash; by
     * its SSL setting, not the request's (Shop::getBaseURL() gives http on
     * the command line).
     */
    public function url(int $shopId): string
    {
        $shop = new \Shop($shopId);
        $ssl = (bool) \Configuration::get('PS_SSL_ENABLED', null, null, $shopId);

        return rtrim(($ssl ? 'https://' . $shop->domain_ssl : 'http://' . $shop->domain) . $shop->getBaseURI(), '/');
    }

    public function name(int $shopId): string
    {
        $name = trim((string) (new \Shop($shopId))->name);

        return $name !== '' ? $name : (string) parse_url($this->url($shopId), PHP_URL_HOST);
    }

    /**
     * The shop's active languages as pages declare them (<html lang>: the
     * locale, "en-US"), the default first.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function languages(int $shopId): array
    {
        $default = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $shopId);
        $languages = array_map(
            // "Italiano (Italian)" as "Italiano".
            static fn (array $language) => ['id' => (int) $language['id_lang'], 'code' => (string) $language['locale'], 'name' => (string) preg_replace('/\s*\([^)]*\)$/', '', (string) $language['name'])],
            \Language::getLanguages(true, $shopId)
        );
        usort($languages, static fn ($a, $b) => (int) ($b['id'] === $default) <=> (int) ($a['id'] === $default));

        return $languages;
    }

    /**
     * The currency of the prices: the shop's default (multi-currency is out
     * of v1, PS11).
     */
    public function currency(int $shopId): string
    {
        return (string) (new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $shopId)))->iso_code;
    }

    /**
     * How the shop shows prices in a language (PrestaShop's CLDR data and the
     * currency's display precision), which the widget follows. Null outside
     * a back-office or front request, where PrestaShop has no container to
     * read CLDR data from.
     *
     * @return array{position: string, thousand_sep: string, decimal_sep: string, decimals: int}|null
     */
    public function currencyFormat(int $shopId, int $languageId): ?array
    {
        $context = clone \Context::getContext();
        $context->language = new \Language($languageId);
        $currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $shopId));
        try {
            $specification = \Tools::getContextLocale($context)->getPriceSpecification((string) $currency->iso_code);
        } catch (\Throwable) {
            return null;
        }
        $symbols = $specification->getSymbolsByNumberingSystem('latn');
        // "¤#,##0.00", "#,##0.00 ¤" (a no-break space), "¤ #,##0.00"…
        $pattern = explode(';', (string) $specification->getPositivePattern())[0];
        $at = mb_strpos($pattern, '¤');
        $spaced = (bool) preg_match('/¤[\s\x{00A0}\x{202F}]|[\s\x{00A0}\x{202F}]¤/u', $pattern);
        $position = ($at === 0 ? 'left' : 'right') . ($spaced ? '_space' : '');

        return [
            'position' => $position,
            'thousand_sep' => (string) $symbols->getGroup(),
            'decimal_sep' => (string) $symbols->getDecimal(),
            'decimals' => max(0, min(4, (int) $currency->precision)),
        ];
    }

    /**
     * The content that can be searched, products first, with how many a
     * shopper can find.
     *
     * @return list<array{id: string, label: string, kind: string, count: int}>
     */
    public function sources(int $shopId): array
    {
        return array_values(array_map(static fn ($source) => [
            'id' => $source->id(),
            'label' => $source->label(),
            'kind' => $source->kind(),
            'count' => $source->count($shopId),
        ], $this->sources->all()));
    }

    /**
     * The filters the given content can have, each with the sources that
     * have it, named in a language (the employee's by default), in the
     * sources' order (Source::facets()).
     *
     * @param string[] $sourceIds
     *
     * @return list<array{attribute: string, label: string, sources: list<string>}>
     */
    public function facets(array $sourceIds, ?int $languageId = null): array
    {
        $languageId ??= (int) \Context::getContext()->language->id;

        $facets = [];
        foreach ($this->sources->all() as $id => $source) {
            if (!in_array($id, $sourceIds, true)) {
                continue;
            }
            foreach ($source->facets($languageId) as $attribute => $label) {
                $facets[$attribute] ??= ['attribute' => (string) $attribute, 'label' => $label, 'sources' => []];
                $facets[$attribute]['sources'][] = (string) $id;
            }
        }

        return array_values($facets);
    }

    /**
     * The widget's sort choices a source can have, by its kind, and those it
     * starts with. The ids are Bitlane's (server/integration/presets.go),
     * which names them in the widget.
     *
     * @return array<string, array{choices: list<array{id: string, label: string}>, defaults: list<string>}>
     */
    public function sorting(): array
    {
        $both = [
            ['id' => 'relevance', 'label' => $this->translator->trans('Relevance', [], 'Modules.Bitlane.Admin')],
            ['id' => 'newest', 'label' => $this->translator->trans('Newest', [], 'Modules.Bitlane.Admin')],
            ['id' => 'oldest', 'label' => $this->translator->trans('Oldest', [], 'Modules.Bitlane.Admin')],
            ['id' => 'title_asc', 'label' => $this->translator->trans('Name: A to Z', [], 'Modules.Bitlane.Admin')],
        ];

        return [
            'products' => [
                'choices' => array_merge($both, [
                    ['id' => 'price_asc', 'label' => $this->translator->trans('Price: low to high', [], 'Modules.Bitlane.Admin')],
                    ['id' => 'price_desc', 'label' => $this->translator->trans('Price: high to low', [], 'Modules.Bitlane.Admin')],
                    ['id' => 'best_selling', 'label' => $this->translator->trans('Best selling', [], 'Modules.Bitlane.Admin')],
                    ['id' => 'top_rated', 'label' => $this->translator->trans('Top rated', [], 'Modules.Bitlane.Admin')],
                ]),
                'defaults' => ['relevance', 'price_asc', 'price_desc', 'newest', 'best_selling'],
            ],
            'other' => ['choices' => $both, 'defaults' => ['relevance', 'newest']],
        ];
    }

    /**
     * The shop's logo (Design › Theme & Logo), for the widget; empty without one.
     */
    public function logoUrl(int $shopId): string
    {
        $logo = (string) \Configuration::get('PS_LOGO', null, null, $shopId);

        return $logo !== '' ? (string) \Context::getContext()->link->getMediaLink(_PS_IMG_ . $logo) : '';
    }

    /**
     * What the setup screen starts with: products and categories, the
     * filters shoppers use most (the price first), the themes' search fields
     * and the shop's logo.
     *
     * @return array{sources: list<string>, facets: list<string>, search_selector: string, brand_color: string, logo_url: string}
     */
    public function defaultSetup(int $shopId): array
    {
        $sources = ['product', 'category'];
        $facets = array_values(array_filter(
            array_column($this->facets($sources), 'attribute'),
            static fn ($attribute) => in_array($attribute, ['price', 'category', 'brand'], true) || str_starts_with($attribute, 'attribute_')
        ));

        return [
            'sources' => $sources,
            'facets' => $facets,
            // Classic's search field, and Hummingbird's with its mobile search
            // button (neither theme has a submit button).
            'search_selector' => '#search_widget input[name="s"], #ps_searchbar_input, [data-bs-target="#searchCanvas"]',
            'brand_color' => '',
            'logo_url' => $this->logoUrl($shopId),
        ];
    }
}
