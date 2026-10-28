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

/*
 * Bitlane search for PrestaShop 8 and 9 (docs/prestashop-plan.md).
 *
 * This file is read by any PHP the shop runs, so it stays PHP 7 syntax: a
 * shop below PHP 8.1 gets install()'s message instead of a parse error. The
 * code in src/ needs PHP 8.1.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

class Bitlane extends Module
{
    /**
     * Where new versions are announced (Support\Updates); releases write their
     * environment's CDN here (.github/deploy/prestashop.sh).
     */
    public const UPDATES_URL = 'https://cdn.bitlane.co/prestashop/info.json';

    public function __construct()
    {
        $this->name = 'bitlane';
        $this->tab = 'search_filter';
        // Releases write the tag's version here (.github/deploy/prestashop.sh).
        $this->version = '0.3.0';
        $this->author = 'Bitlane';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        // The Bitlane screen, a top-level item of the back office's Improve section.
        $this->tabs = [
            [
                'class_name' => 'AdminBitlane',
                'parent_class_name' => 'IMPROVE',
                'name' => 'Bitlane',
                // Not a Material Icons name: the menu prints it as the icon's
                // text and its mi-bitlane class, which hookDisplayBackOfficeHeader() draws.
                'icon' => 'bitlane',
                'visible' => true,
                'wording' => 'Bitlane',
                'wording_domain' => 'Modules.Bitlane.Admin',
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('Bitlane Search', [], 'Modules.Bitlane.Admin');
        $this->description = $this->trans('Fast search for your shop: connect to Bitlane, sync your catalogue and add the search widget.', [], 'Modules.Bitlane.Admin');
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function install()
    {
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $this->_errors[] = $this->trans('Bitlane needs PHP 8.1 or newer.', [], 'Modules.Bitlane.Admin');

            return false;
        }

        return parent::install() && Bitlane\PrestaShop\Plugin::instance($this)->installer()->install();
    }

    public function uninstall()
    {
        return Bitlane\PrestaShop\Plugin::instance($this)->installer()->uninstall() && parent::uninstall();
    }

    /**
     * Bitlane's mark as its menu item's icon: tabs only take a Material
     * Icons name, so the icon "bitlane" (class mi-bitlane) hides its text and
     * shows the mark, a mask in the menu's own color. The Bitlane screen's
     * breadcrumb prints the icon too, without the class: it's hidden there.
     *
     * @return string
     */
    public function hookDisplayBackOfficeHeader()
    {
        $icon = $this->getPathUri() . 'views/img/menu-icon.svg';

        return '<style>.material-icons.mi-bitlane{font-size:0;width:20px;height:20px;'
            . 'background-color:currentColor;-webkit-mask:url(' . $icon . ') center/18px no-repeat;mask:url(' . $icon . ') center/18px no-repeat}'
            . 'body.adminbitlane .breadcrumb-current>i.material-icons{display:none}</style>';
    }

    /*
     * The catalogue's changes (Bitlane\PrestaShop\Sync\Changes): what changed
     * is queued as jobs at the end of the request, which the cron runs
     * (php bin/console bitlane:run). Each takes the hook's parameters. Hooks
     * added here are also listed in src/Support/Installer.php and, for
     * installed shops, an upgrade/ script.
     */

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectProductAddAfter($params)
    {
        $this->changed('product', $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectProductUpdateAfter($params)
    {
        $this->changed('product', $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectProductDeleteAfter($params)
    {
        $this->changed('product', $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionProductUpdate($params)
    {
        $this->changed('product', isset($params['id_product']) ? $params['id_product'] : 0);
    }

    /**
     * The product page's save (PrestaShop 8's new page and 9's): features,
     * the cover image and tags are written past the ObjectModel hooks.
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionAfterUpdateProductFormHandler($params)
    {
        $this->changed('product', isset($params['id']) ? $params['id'] : 0);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionAfterCreateProductFormHandler($params)
    {
        $this->changed('product', isset($params['id']) ? $params['id'] : 0);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectCombinationAddAfter($params)
    {
        $this->changed('product', $params['object']->id_product);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectCombinationUpdateAfter($params)
    {
        $this->changed('product', $params['object']->id_product);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectCombinationDeleteAfter($params)
    {
        $this->changed('product', $params['object']->id_product);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionUpdateQuantity($params)
    {
        $this->changed('product', isset($params['id_product']) ? $params['id_product'] : 0);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectImageAddAfter($params)
    {
        $this->changed('product', $params['object']->id_product);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectImageUpdateAfter($params)
    {
        $this->changed('product', $params['object']->id_product);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectImageDeleteAfter($params)
    {
        $this->changed('product', $params['object']->id_product);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectSpecificPriceAddAfter($params)
    {
        $this->plugin()->changes()->specificPrice($params['object']);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectSpecificPriceUpdateAfter($params)
    {
        $this->plugin()->changes()->specificPrice($params['object']);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectSpecificPriceDeleteAfter($params)
    {
        $this->plugin()->changes()->specificPrice($params['object']);
    }

    /**
     * An order changes its products' sales count.
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionValidateOrder($params)
    {
        if (isset($params['order']) && $params['order'] instanceof Order) {
            $this->plugin()->changes()->add('product', array_column($params['order']->getProducts(), 'product_id'));
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectCategoryAddAfter($params)
    {
        $this->changed('category', $params['object']->id);
    }

    /**
     * A category renamed or moved: its products' values change too.
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectCategoryUpdateAfter($params)
    {
        $this->changed('category', $params['object']->id);
        $this->plugin()->changes()->productsOf('category', (int) $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectCategoryDeleteAfter($params)
    {
        $this->changed('category', $params['object']->id);
        $this->plugin()->changes()->productsOf('category', (int) $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectManufacturerAddAfter($params)
    {
        $this->changed('brand', $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectManufacturerUpdateAfter($params)
    {
        $this->changed('brand', $params['object']->id);
        $this->plugin()->changes()->productsOf('manufacturer', (int) $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectManufacturerDeleteAfter($params)
    {
        $this->changed('brand', $params['object']->id);
        $this->plugin()->changes()->productsOf('manufacturer', (int) $params['object']->id);
    }

    /**
     * An attribute value (a colour, a size) renamed or deleted.
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectProductAttributeUpdateAfter($params)
    {
        $this->plugin()->changes()->productsOf('attribute', (int) $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectProductAttributeDeleteAfter($params)
    {
        $this->plugin()->changes()->productsOf('attribute', (int) $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectAttributeGroupUpdateAfter($params)
    {
        $this->plugin()->changes()->productsOf('attribute_group', (int) $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectFeatureUpdateAfter($params)
    {
        $this->plugin()->changes()->productsOf('feature', (int) $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectFeatureValueUpdateAfter($params)
    {
        $this->plugin()->changes()->productsOf('feature_value', (int) $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectCMSAddAfter($params)
    {
        $this->changed('cms', $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectCMSUpdateAfter($params)
    {
        $this->changed('cms', $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectCMSDeleteAfter($params)
    {
        $this->changed('cms', $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectStoreAddAfter($params)
    {
        $this->changed('store', $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectStoreUpdateAfter($params)
    {
        $this->changed('store', $params['object']->id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionObjectStoreDeleteAfter($params)
    {
        $this->changed('store', $params['object']->id);
    }

    /**
     * A module's own source reports changed items (added, changed or gone):
     * Hook::exec('actionBitlaneItemsChanged', ['source' => 'blog', 'ids' => [3, 4]]).
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionBitlaneItemsChanged($params)
    {
        if (isset($params['source'], $params['ids']) && is_string($params['source']) && is_array($params['ids'])) {
            $this->plugin()->changes()->add($params['source'], $params['ids']);
        }
    }

    /**
     * The search widget's snippet on the shop's pages.
     *
     * @param array<string, mixed> $params
     *
     * @return string
     */
    public function hookDisplayHeader($params)
    {
        $controller = isset($this->context->controller->php_self) ? (string) $this->context->controller->php_self : '';

        return $this->plugin()->storefront()->snippet((int) $this->context->shop->id, $controller);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionCartUpdateQuantityBefore($params)
    {
        $this->plugin()->events()->cartUpdated($params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        $this->plugin()->events()->orderStateChanged($params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionOrderSlipAdd($params)
    {
        $this->plugin()->events()->slipAdded($params);
    }

    /**
     * Module Manager's catalogue: this module's newer version, which its
     * Upgrade button downloads and installs.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int, array<string, string>>
     */
    public function hookActionListModules($params)
    {
        return $this->plugin()->updates()->listing();
    }

    /**
     * The module's Configure button opens the Bitlane screen.
     *
     * @return void
     */
    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminBitlane'));
    }

    /**
     * @param string $source
     * @param int|string $id
     *
     * @return void
     */
    private function changed($source, $id)
    {
        $this->plugin()->changes()->add($source, [(int) $id]);
    }

    /**
     * @return Bitlane\PrestaShop\Plugin
     */
    private function plugin()
    {
        return Bitlane\PrestaShop\Plugin::instance($this);
    }
}
