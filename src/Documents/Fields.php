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

namespace Bitlane\PrestaShop\Documents;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Text, prices, filter values and images as the document schemas want them
 * (server/widget_public/documents_v1.go).
 */
final class Fields
{
    /**
     * Currencies whose minor unit isn't the cent: their decimals, as Bitlane's
     * server has them (server/world_data/data/currencies.json, num_decimals),
     * which the widget divides prices by; composer check:currencies compares
     * them. Not the currency's precision in PrestaShop, which says how prices
     * show (with 0, €18.00 would be sent as 18 cents).
     */
    public const DECIMALS = [
        'AFN' => 0, 'ALL' => 0, 'AMD' => 0, 'BHD' => 3, 'BIF' => 0, 'CLP' => 0, 'COP' => 0, 'CRC' => 0,
        'DJF' => 0, 'GNF' => 0, 'GYD' => 0, 'IDR' => 0, 'IQD' => 0, 'IRR' => 0, 'ISK' => 0, 'JOD' => 3,
        'JPY' => 0, 'KMF' => 0, 'KPW' => 0, 'KRW' => 0, 'KWD' => 3, 'LAK' => 0, 'LBP' => 0, 'LYD' => 3,
        'MGA' => 0, 'MMK' => 0, 'MNT' => 0, 'MUR' => 0, 'OMR' => 3, 'PKR' => 0, 'PYG' => 0, 'RSD' => 0,
        'RWF' => 0, 'SLL' => 0, 'SOS' => 0, 'SYP' => 0, 'TND' => 3, 'TWD' => 0, 'TZS' => 0, 'UGX' => 0,
        'UZS' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0, 'YER' => 0,
    ];

    /**
     * HTML as plain, searchable text, at most 20,000 characters: Meilisearch
     * searches a field's first words anyway, and documents stay small.
     */
    public static function text(string $html, int $limit = 20000): string
    {
        $text = strip_tags((string) preg_replace('#<(br|/p|/div|/li|/h\d)[^>]*>#i', ' $0', $html));
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_substr($text, 0, $limit);
    }

    /**
     * An amount in the currency's minor unit (cents), as the widget formats
     * prices.
     */
    public static function minor(float $amount, string $currency): int
    {
        return (int) round($amount * (10 ** (self::DECIMALS[strtoupper($currency)] ?? 2)));
    }

    /**
     * A filter value as the widget reads it: "title---slug---id---swatch", the
     * swatch a color or an image's URL, N/A without one.
     */
    public static function value(string $title, string $slug, int|string $id, string $swatch = ''): string
    {
        $title = str_replace('---', '–', html_entity_decode($title, ENT_QUOTES, 'UTF-8'));

        return implode('---', [$title, $slug !== '' ? $slug : \Tools::str2url($title), (string) $id, $swatch !== '' ? $swatch : 'N/A']);
    }

    /**
     * Filter values merged by attribute, each once.
     *
     * @param array<string, list<string>> ...$sets
     *
     * @return array<string, list<string>>
     */
    public static function merge(array ...$sets): array
    {
        $merged = [];
        foreach ($sets as $set) {
            foreach ($set as $attribute => $values) {
                $merged[$attribute] = array_values(array_unique(array_merge($merged[$attribute] ?? [], $values)));
            }
        }

        return $merged;
    }

    /**
     * An image as the cards show it: the URL of each of the entity's image
     * types from the one shown up to three times as wide, as a srcset.
     *
     * @param callable(string): string $url the image's URL in an image type
     * @param array{name: string, width: int, height: int} $shown the type shown
     * @param list<array{name: string, width: int, height: int}> $types the entity's types
     *
     * @return array<string, string|int>
     */
    public static function image(callable $url, array $shown, array $types, string $alt = ''): array
    {
        $srcset = [];
        foreach ($types as $type) {
            if ($type['width'] >= $shown['width'] && $type['width'] <= $shown['width'] * 3) {
                $srcset[$type['width']] = $url($type['name']) . ' ' . $type['width'] . 'w';
            }
        }
        ksort($srcset);

        return array_filter([
            'src' => $url($shown['name']),
            'srcset' => count($srcset) > 1 ? implode(', ', $srcset) : '',
            'sizes' => count($srcset) > 1 ? $shown['width'] . 'px' : '',
            'width' => $shown['width'],
            'height' => $shown['height'],
            'alt' => trim($alt),
        ], static fn ($value) => $value !== '');
    }
}
