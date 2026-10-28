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
use Bitlane\PrestaShop\Site\Settings;
use Bitlane\PrestaShop\Site\Site;
use Bitlane\PrestaShop\Support\BitlaneError;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The shop's events, sent to Bitlane from the server (docs/prestashop-plan.md
 * PS14, docs/conversions-plan.md C6, C11): POST /api/integrations/:id/events
 * in the language whose products index holds their documents. Ad blockers
 * can't drop them and shoppers can't fake them, unlike Bitlane.track().
 *
 * The widget's session (its bitlane_session cookie, written only with
 * tracking on) is copied onto the cart (bitlane_cart) whenever the shopper
 * adds to it, because payment modules often create the order in a callback
 * without the shopper's cookies. Each event is a job whose arguments carry
 * its id, so a retry sends the same event:
 * - an addition to the cart (actionCartUpdateQuantityBefore, increases);
 * - a purchase, the first time an order reaches a paid state
 *   (actionOrderStatusPostUpdate), each order of a split cart on its own;
 * - a refund per credit slip of a reported order (actionOrderSlipAdd);
 * - a reported order cancelled: what wasn't refunded yet, once.
 * bitlane_order notes what was reported.
 */
final class Events
{
    public const ADDITION = 'event_cart';
    public const PURCHASE = 'event_purchase';
    public const REFUND = 'event_refund';
    public const CANCELLATION = 'event_cancel';

    public const CART_TABLE = 'bitlane_cart';
    public const ORDER_TABLE = 'bitlane_order';

    public function __construct(
        private BitlaneApi $api,
        private ConnectionStore $connection,
        private Site $site,
        private Settings $settings,
        private Jobs $jobs,
    ) {
    }

    /**
     * A product added to a cart: the session onto the cart, the addition
     * queued.
     *
     * @param array<string, mixed> $params actionCartUpdateQuantityBefore's
     */
    public function cartUpdated(array $params): void
    {
        $cart = $params['cart'] ?? null;
        $product = $params['product'] ?? null;
        $quantity = (int) ($params['quantity'] ?? 0);
        if (!$cart instanceof \Cart || !$cart->id || !$product instanceof \Product || ($params['operator'] ?? 'up') !== 'up' || $quantity <= 0) {
            return;
        }
        $shopId = (int) $cart->id_shop;
        $language = $this->language($shopId, (int) $cart->id_lang);
        if ($language === '' || !$this->connection->integrationId($shopId)) {
            return;
        }

        $session = $this->session();
        if ($session !== '') {
            \Db::getInstance()->insert(self::CART_TABLE, ['id_cart' => (int) $cart->id, 'session_id' => pSQL($session), 'date_upd' => date('Y-m-d H:i:s')], false, false, \Db::ON_DUPLICATE_KEY);
        }
        $this->jobs->push($shopId, self::ADDITION, [$language, [[
            'id' => self::uuid(),
            'type' => 'add_to_cart',
            'session_id' => $session,
            'at' => time(),
            'document_id' => $this->documentId($shopId, (int) $product->id, (int) ($params['id_product_attribute'] ?? 0)),
            'product_id' => (string) $product->id,
            'quantity' => min($quantity, 100000),
            'device' => self::device((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ]]]);
    }

    /**
     * An order's new state: paid for the first time queues its purchase;
     * cancelled after it was reported queues what's left as a refund.
     *
     * @param array<string, mixed> $params actionOrderStatusPostUpdate's
     */
    public function orderStateChanged(array $params): void
    {
        $state = $params['newOrderStatus'] ?? null;
        $order = new \Order((int) ($params['id_order'] ?? 0));
        if (!$state instanceof \OrderState || !\Validate::isLoadedObject($order) || !$this->connection->integrationId((int) $order->id_shop)) {
            return;
        }
        $reported = $this->reported((int) $order->id);
        if ($state->paid && empty($reported['purchase_at'])) {
            $this->jobs->push((int) $order->id_shop, self::PURCHASE, [(int) $order->id, self::uuid()], 0, 'purchase:' . $order->id);
        } elseif ((int) $state->id === (int) \Configuration::get('PS_OS_CANCELED') && !empty($reported['purchase_at']) && empty($reported['cancel_at'])) {
            $this->jobs->push((int) $order->id_shop, self::CANCELLATION, [(int) $order->id, self::uuid()], 0, 'cancel:' . $order->id);
        }
    }

    /**
     * A credit slip made for a reported order: its refund queued. The hook's
     * parameters differ between versions; the order's newest slip is it.
     *
     * @param array<string, mixed> $params actionOrderSlipAdd's
     */
    public function slipAdded(array $params): void
    {
        $order = $params['order'] ?? null;
        if (!$order instanceof \Order || !$order->id || empty($this->reported((int) $order->id)['purchase_at']) || !$this->connection->integrationId((int) $order->id_shop)) {
            return;
        }
        $slip = (int) \Db::getInstance()->getValue('SELECT MAX(id_order_slip) FROM ' . _DB_PREFIX_ . 'order_slip WHERE id_order = ' . (int) $order->id, false);
        if ($slip) {
            $this->jobs->push((int) $order->id_shop, self::REFUND, [$slip, self::uuid()], 0, 'refund:' . $slip);
        }
    }

    /**
     * The additions job: sends them as they were.
     *
     * @param array<mixed> $args the language, the events
     */
    public function sendAdditions(int $shopId, array $args): void
    {
        $this->send($shopId, (string) ($args[0] ?? ''), array_values((array) ($args[1] ?? [])));
    }

    /**
     * The purchase job: the order as it is now, unless it was cancelled
     * meanwhile.
     *
     * @param array<mixed> $args the order, the event's id
     */
    public function sendPurchase(int $shopId, array $args): void
    {
        $order = new \Order((int) ($args[0] ?? 0));
        if (!\Validate::isLoadedObject($order) || !empty($this->reported((int) $order->id)['purchase_at'])
            || (int) $order->getCurrentState() === (int) \Configuration::get('PS_OS_CANCELED')) {
            return;
        }
        $items = [];
        foreach ($order->getProducts() as $line) {
            if ((int) $line['product_quantity'] > 0) {
                $items[] = $this->item($shopId, $line, (int) $line['product_quantity'], (float) $line['unit_price_tax_incl']);
            }
        }
        $this->send($shopId, $this->language($shopId, (int) $order->id_lang), [[
            'id' => (string) ($args[1] ?? self::uuid()),
            'type' => 'purchase',
            'session_id' => $this->cartSession((int) $order->id_cart),
            'at' => (int) strtotime((string) $order->date_add),
            'purchase' => [
                'order_id' => (string) $order->id,
                'currency' => $this->currency($order),
                'value' => round((float) $order->total_paid_tax_incl, 6),
                // Bitlane takes 200.
                'items' => array_slice($items, 0, 200),
            ],
        ]]);
        $this->note((int) $order->id, 'purchase_at');
    }

    /**
     * The refund job: a credit slip's products and amount.
     *
     * @param array<mixed> $args the slip, the event's id
     */
    public function sendRefund(int $shopId, array $args): void
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $slip = $db->getRow("SELECT * FROM {$p}order_slip WHERE id_order_slip = " . (int) ($args[0] ?? 0), false);
        $order = is_array($slip) ? new \Order((int) $slip['id_order']) : null;
        if (!$order || !\Validate::isLoadedObject($order)) {
            return;
        }
        $lines = $db->executeS(
            "SELECT od.product_id, od.product_attribute_id, osd.product_quantity, osd.unit_price_tax_incl FROM {$p}order_slip_detail osd"
            . " JOIN {$p}order_detail od ON od.id_order_detail = osd.id_order_detail WHERE osd.id_order_slip = " . (int) $slip['id_order_slip'],
            true,
            false
        ) ?: [];
        $items = [];
        foreach ($lines as $line) {
            if ((int) $line['product_quantity'] > 0) {
                $items[] = $this->item($shopId, $line, (int) $line['product_quantity'], (float) $line['unit_price_tax_incl']);
            }
        }
        $value = (float) $slip['total_products_tax_incl'] + (float) $slip['total_shipping_tax_incl'];
        $this->sendRefundOf($shopId, $order, (string) $slip['id_order_slip'], $value, $items, (int) strtotime((string) $slip['date_add']), (string) ($args[1] ?? self::uuid()));
    }

    /**
     * The cancellation job: what the order kept after its credit slips, as a
     * refund (id cancelled), once.
     *
     * @param array<mixed> $args the order, the event's id
     */
    public function sendCancellation(int $shopId, array $args): void
    {
        $order = new \Order((int) ($args[0] ?? 0));
        if (!\Validate::isLoadedObject($order) || !empty($this->reported((int) $order->id)['cancel_at'])) {
            return;
        }
        $items = [];
        foreach ($order->getProducts() as $line) {
            $left = (int) $line['product_quantity'] - (int) $line['product_quantity_refunded'];
            if ($left > 0) {
                $items[] = $this->item($shopId, $line, $left, (float) $line['unit_price_tax_incl']);
            }
        }
        $refunded = (float) \Db::getInstance()->getValue('SELECT SUM(total_products_tax_incl + total_shipping_tax_incl) FROM ' . _DB_PREFIX_ . 'order_slip WHERE id_order = ' . (int) $order->id, false);
        $value = max(0.0, (float) $order->total_paid_tax_incl - $refunded);
        if ($value > 0 || $items) {
            $this->sendRefundOf($shopId, $order, 'cancelled', $value, $items, time(), (string) ($args[1] ?? self::uuid()));
        }
        $this->note((int) $order->id, 'cancel_at');
    }

    /**
     * Sessions of carts not touched for 90 days go: their orders are long
     * placed, or never will be.
     */
    public function prune(): void
    {
        \Db::getInstance()->delete(self::CART_TABLE, "date_upd < '" . date('Y-m-d H:i:s', time() - 90 * 86400) . "'");
    }

    /**
     * A product's document id, as the setup makes them: a combination's, or
     * the whole product's (-0).
     */
    public function documentId(int $shopId, int $productId, int $combinationId): string
    {
        if ($this->settings->get($shopId)['product_documents'] === Settings::PER_PRODUCT) {
            return $productId . '-0';
        }
        if (!$combinationId) {
            $combinationId = (int) \Product::getDefaultAttribute($productId);
        }

        return $productId . '-' . $combinationId;
    }

    /**
     * The widget's session from its cookie; empty without one.
     */
    public function session(): string
    {
        $session = (string) ($_COOKIE['bitlane_session'] ?? '');

        return preg_match('/^[0-9a-f-]{36}$/', $session) ? $session : '';
    }

    public static function device(string $userAgent): string
    {
        if (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $userAgent)) {
            return 'tablet';
        }

        return preg_match('/Mobile|iPhone|iPod|Android/i', $userAgent) ? 'mobile' : 'desktop';
    }

    /**
     * @param array<string, mixed> $line an order line (product_id, product_attribute_id)
     *
     * @return array{document_id: string, product_id: string, quantity: int, price: float}
     */
    private function item(int $shopId, array $line, int $quantity, float $price): array
    {
        return [
            'document_id' => $this->documentId($shopId, (int) $line['product_id'], (int) $line['product_attribute_id']),
            // A combination's product: it matches a click on another combination.
            'product_id' => (string) $line['product_id'],
            'quantity' => min($quantity, 100000),
            'price' => round($price, 6),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function sendRefundOf(int $shopId, \Order $order, string $refundId, float $value, array $items, int $at, string $id): void
    {
        $this->send($shopId, $this->language($shopId, (int) $order->id_lang), [[
            'id' => $id,
            'type' => 'refund',
            'session_id' => $this->cartSession((int) $order->id_cart),
            'at' => $at,
            'refund' => [
                'order_id' => (string) $order->id,
                'refund_id' => $refundId,
                'currency' => $this->currency($order),
                'value' => round($value, 6),
                'items' => array_slice($items, 0, 200),
            ],
        ]]);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function send(int $shopId, string $language, array $events): void
    {
        $id = $this->connection->integrationId($shopId);
        if (!$id) {
            throw new BitlaneError('not_connected', 'This shop isn’t connected to Bitlane.', 409);
        }
        if ($language === '' || !$events) {
            return;
        }
        $this->api->request('POST', '/api/integrations/' . rawurlencode($id) . '/events', ['language' => $language, 'events' => $events]);
    }

    /**
     * A language of the shop as the setup sent it ("it-IT"); empty when the
     * setup doesn't have it (Bitlane would refuse its events).
     */
    private function language(int $shopId, int $languageId): string
    {
        $codes = array_column($this->site->languages($shopId), 'code', 'id');
        $code = (string) ($codes[$languageId] ?? '');

        return in_array($code, $this->settings->get($shopId)['languages'], true) ? $code : '';
    }

    private function cartSession(int $cartId): string
    {
        return (string) \Db::getInstance()->getValue('SELECT session_id FROM ' . _DB_PREFIX_ . self::CART_TABLE . ' WHERE id_cart = ' . $cartId, false);
    }

    private function currency(\Order $order): string
    {
        return (string) (new \Currency((int) $order->id_currency))->iso_code;
    }

    /**
     * What was reported of an order.
     *
     * @return array<string, mixed>
     */
    private function reported(int $orderId): array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM ' . _DB_PREFIX_ . self::ORDER_TABLE . ' WHERE id_order = ' . $orderId, false);

        return is_array($row) ? $row : [];
    }

    private function note(int $orderId, string $column): void
    {
        \Db::getInstance()->insert(self::ORDER_TABLE, ['id_order' => $orderId, $column => date('Y-m-d H:i:s')], false, false, \Db::ON_DUPLICATE_KEY);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
