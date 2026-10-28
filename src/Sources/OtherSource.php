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

namespace Bitlane\PrestaShop\Sources;

use Bitlane\PrestaShop\Support\Hooks;
use Bitlane\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A source of "other" documents (widget_public.OtherDocumentV1): the shop's
 * active items of a table, walked by id, each one document whose id is the
 * item's. Developers change a document in the hook actionBitlaneOtherDocument
 * (document by reference, null leaves it out).
 */
abstract class OtherSource implements Source
{
    public function __construct(protected Translator $translator)
    {
    }

    /**
     * The item's table (without prefix) and id column.
     *
     * @return array{table: string, key: string, where: string}
     */
    abstract protected function table(): array;

    /**
     * The item's document in the context's shop and language; null when a
     * guest can't find it.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function document(int $id, Context $context): ?array;

    public function kind(): string
    {
        return self::OTHER;
    }

    public function count(int $shopId): int
    {
        return (int) \Db::getInstance()->getValue('SELECT COUNT(*) ' . $this->from($shopId), false);
    }

    public function facets(int $languageId): array
    {
        return [];
    }

    public function ids(int $shopId, string $after, int $limit): array
    {
        ['key' => $key] = $this->table();
        $rows = \Db::getInstance()->executeS(
            'SELECT t.' . $key . ' ' . $this->from($shopId) . ' AND t.' . $key . ' > ' . (int) $after
            . ' ORDER BY t.' . $key . ' LIMIT ' . $limit,
            true,
            false
        ) ?: [];

        return array_map('strval', array_column($rows, $key));
    }

    public function documents(array $ids, Context $context): array
    {
        $documents = [];
        foreach ($ids as $id) {
            $doc = $this->document((int) $id, $context);
            if ($doc !== null) {
                $doc = Hooks::filter('actionBitlaneOtherDocument', 'document', array_filter($doc + ['id' => $id], static fn ($value) => $value !== null && $value !== '' && $value !== []), [
                    'source' => $this->id(),
                    'id' => (int) $id,
                    'id_lang' => $context->languageId,
                    'id_shop' => $context->shopId,
                ]);
            }
            $documents[$id] = is_array($doc) ? [$doc] : [];
        }

        return $documents;
    }

    public function modified(int $shopId, array $ids): array
    {
        ['table' => $table, 'key' => $key] = $this->table();
        if (!$ids || !in_array('date_upd', array_column(\Db::getInstance()->executeS('SHOW COLUMNS FROM ' . _DB_PREFIX_ . $table, true, false) ?: [], 'Field'), true)) {
            return [];
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT ' . $key . ', date_upd FROM ' . _DB_PREFIX_ . $table . ' WHERE ' . $key . ' IN (' . implode(',', array_map('intval', $ids)) . ')',
            true,
            false
        ) ?: [];

        return array_map(static fn ($date) => (int) strtotime((string) $date), array_column($rows, 'date_upd', $key));
    }

    /**
     * The shop's active items, for a SELECT.
     */
    private function from(int $shopId): string
    {
        ['table' => $table, 'key' => $key, 'where' => $where] = $this->table();

        return 'FROM ' . _DB_PREFIX_ . $table . ' t'
            . ' JOIN ' . _DB_PREFIX_ . $table . '_shop s ON s.' . $key . ' = t.' . $key . ' AND s.id_shop = ' . $shopId
            . ' WHERE t.active = 1 AND ' . $where;
    }

    /**
     * The context's language as a locale ("it-IT"), for the translator.
     */
    protected function locale(Context $context): string
    {
        return (string) (new \Language($context->languageId))->locale;
    }

    /**
     * A file's image in the entity's image type nearest $width, when the file is there.
     *
     * @param callable(string): string $url
     *
     * @return array<string, string|int>|null
     */
    protected function image(string $file, string $entity, int $width, callable $url, Context $context, string $alt): ?array
    {
        $types = $context->catalog->imageTypes($entity, $width);

        return is_file($file) && $types ? \Bitlane\PrestaShop\Documents\Fields::image($url, $types['shown'], $types['types'], $alt) : null;
    }
}
