# Bitlane for PrestaShop

Fast, typo-tolerant search for your PrestaShop shop, from [Bitlane](https://bitlane.co). Click **Connect**, approve the shop in Bitlane, and Bitlane creates the search engine, an index for each kind of content and language, and the search widget, then keeps them in sync as your catalogue changes.

- Products, categories, CMS pages, brands and stores, in every language of the shop.
- Each shop of a multistore install, set up on its own under one connection.
- The search widget in your shop.
- Additions to the cart, paid orders, credit slips and cancellations reported for Bitlane's conversion reports; the widget can wait for your visitors' consent.

## Requirements

- PrestaShop 8.0 to 9.x
- PHP 8.1 or later
- A cron job for the sync, or the Bitlane screen left open

## Install

Download [bitlane.zip](https://cdn.bitlane.co/prestashop/bitlane.zip) and upload it under **Modules → Module Manager → Upload a module**.

Or with Composer, in your shop's root:

```bash
composer require bitlane/prestashop
```

The module goes in `modules/bitlane` (through `composer/installers`); install it from **Module Manager**, or with `php bin/console prestashop:module install bitlane`.

Then open **Improve → Bitlane**, click **Connect**, sign in to Bitlane (or create an account), approve the shop, and choose what to search.

## Update

The Bitlane screen and Module Manager tell you when a new version is out, and Module Manager's **Upgrade** button installs it. With Composer, run `composer update bitlane/prestashop`, then upgrade the module in Module Manager (or `php bin/console prestashop:module upgrade bitlane`). Your connection, settings and sync stay.

## Help

Questions and bugs: open an issue in [this repository](https://github.com/bitlane-co/prestashop/issues), or write to us from [bitlane.co](https://bitlane.co). This repository holds each release as it ships; the module is developed elsewhere, so pull requests can't be merged here: describe the change in an issue instead.

## License

Commercial (NMC): see [LICENSE.md](LICENSE.md).
