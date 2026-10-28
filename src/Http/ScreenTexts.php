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

namespace Bitlane\PrestaShop\Http;

use Bitlane\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The Bitlane screen's texts (views/src, t()), translated by PrestaShop in
 * the employee's language and sent with the page: so they're in
 * International › Translations with the module's other texts, which
 * PrestaShop finds by these literal trans() calls. A text the app adds goes
 * here too, and in translations/it-IT; %name% placeholders are left for the
 * app to fill.
 */
final class ScreenTexts
{
    public function __construct(private Translator $translator)
    {
    }

    /**
     * @return array<string, string> each text by its English
     */
    public function all(): array
    {
        $t = $this->translator;

        return [
            'Bitlane' => $t->trans('Bitlane', [], 'Modules.Bitlane.Admin'),
            'Open in Bitlane' => $t->trans('Open in Bitlane', [], 'Modules.Bitlane.Admin'),
            'Save' => $t->trans('Save', [], 'Modules.Bitlane.Admin'),
            'Save and set up' => $t->trans('Save and set up', [], 'Modules.Bitlane.Admin'),
            'Overview' => $t->trans('Overview', [], 'Modules.Bitlane.Admin'),
            'Search' => $t->trans('Search', [], 'Modules.Bitlane.Admin'),
            'Connection' => $t->trans('Connection', [], 'Modules.Bitlane.Admin'),
            'Loading…' => $t->trans('Loading…', [], 'Modules.Bitlane.Admin'),
            'Bitlane could not be reached. Try again in a moment.' => $t->trans('Bitlane could not be reached. Try again in a moment.', [], 'Modules.Bitlane.Admin'),

            // Not connected, a project, a shop.
            'Bitlane ended this shop’s connection, so its search stopped updating. Connect again to resume.' => $t->trans('Bitlane ended this shop’s connection, so its search stopped updating. Connect again to resume.', [], 'Modules.Bitlane.Admin'),
            'Connect your shop to Bitlane' => $t->trans('Connect your shop to Bitlane', [], 'Modules.Bitlane.Admin'),
            'You’ll sign in to Bitlane (or create an account) and allow this shop to use it. Bitlane then sets up:' => $t->trans('You’ll sign in to Bitlane (or create an account) and allow this shop to use it. Bitlane then sets up:', [], 'Modules.Bitlane.Admin'),
            'an engine, or one your project already has' => $t->trans('an engine, or one your project already has', [], 'Modules.Bitlane.Admin'),
            'indexes for your catalogue in %languages%' => $t->trans('indexes for your catalogue in %languages%', [], 'Modules.Bitlane.Admin'),
            'a search widget for your storefront' => $t->trans('a search widget for your storefront', [], 'Modules.Bitlane.Admin'),
            'keys that only reach this shop’s indexes' => $t->trans('keys that only reach this shop’s indexes', [], 'Modules.Bitlane.Admin'),
            'Connect to Bitlane' => $t->trans('Connect to Bitlane', [], 'Modules.Bitlane.Admin'),
            'Choose a project' => $t->trans('Choose a project', [], 'Modules.Bitlane.Admin'),
            'Bitlane project for this shop' => $t->trans('Bitlane project for this shop', [], 'Modules.Bitlane.Admin'),
            'Continue' => $t->trans('Continue', [], 'Modules.Bitlane.Admin'),
            'Choose a shop' => $t->trans('Choose a shop', [], 'Modules.Bitlane.Admin'),
            'Each shop is connected and set up on its own: pick the one to work on.' => $t->trans('Each shop is connected and set up on its own: pick the one to work on.', [], 'Modules.Bitlane.Admin'),
            'Connected' => $t->trans('Connected', [], 'Modules.Bitlane.Admin'),
            'Not connected' => $t->trans('Not connected', [], 'Modules.Bitlane.Admin'),

            // Setting up, the setup form.
            'Setting up' => $t->trans('Setting up', [], 'Modules.Bitlane.Admin'),
            'Bitlane is creating the engine, indexes, keys and search widget. A new engine takes a minute or two.' => $t->trans('Bitlane is creating the engine, indexes, keys and search widget. A new engine takes a minute or two.', [], 'Modules.Bitlane.Admin'),
            'Bitlane could not set this shop up.' => $t->trans('Bitlane could not set this shop up.', [], 'Modules.Bitlane.Admin'),
            'Engine' => $t->trans('Engine', [], 'Modules.Bitlane.Admin'),
            'A new engine, or one your Bitlane project already has.' => $t->trans('A new engine, or one your Bitlane project already has.', [], 'Modules.Bitlane.Admin'),
            'Saving moves the shop to this engine: its indexes are made there and filled again, and the current engine’s are deleted. Search has fewer results until the sync is done.' => $t->trans('Saving moves the shop to this engine: its indexes are made there and filled again, and the current engine’s are deleted. Search has fewer results until the sync is done.', [], 'Modules.Bitlane.Admin'),
            'A new engine' => $t->trans('A new engine', [], 'Modules.Bitlane.Admin'),
            'Content to search' => $t->trans('Content to search', [], 'Modules.Bitlane.Admin'),
            'Each kind gets its own index, kept up to date. Shoppers find those in the search widget in one list of results; the others are for your own use.' => $t->trans('Each kind gets its own index, kept up to date. Shoppers find those in the search widget in one list of results; the others are for your own use.', [], 'Modules.Bitlane.Admin'),
            '%label% (%count%)' => $t->trans('%label% (%count%)', [], 'Modules.Bitlane.Admin'),
            'In the search widget' => $t->trans('In the search widget', [], 'Modules.Bitlane.Admin'),
            'Languages' => $t->trans('Languages', [], 'Modules.Bitlane.Admin'),
            'Each of the shop’s languages gets its own indexes, and the widget searches the one of the page. A language you add later is added on its own.' => $t->trans('Each of the shop’s languages gets its own indexes, and the widget searches the one of the page. A language you add later is added on its own.', [], 'Modules.Bitlane.Admin'),
            'Filters' => $t->trans('Filters', [], 'Modules.Bitlane.Admin'),
            'What shoppers can narrow the results by, in the order they show.' => $t->trans('What shoppers can narrow the results by, in the order they show.', [], 'Modules.Bitlane.Admin'),
            'Sorting' => $t->trans('Sorting', [], 'Modules.Bitlane.Admin'),
            'The choices shoppers can sort the results by, in the order they show. Change them later in Bitlane’s widget designer.' => $t->trans('The choices shoppers can sort the results by, in the order they show. Change them later in Bitlane’s widget designer.', [], 'Modules.Bitlane.Admin'),
            'Drag to change the order' => $t->trans('Drag to change the order', [], 'Modules.Bitlane.Admin'),
            'Products with combinations' => $t->trans('Products with combinations', [], 'Modules.Bitlane.Admin'),
            'One result per combination (recommended)' => $t->trans('One result per combination (recommended)', [], 'Modules.Bitlane.Admin'),
            'Color, size and stock filter exactly: “red, XL, in stock” finds only products with a red XL in stock. Each combination counts as a record in your plan.' => $t->trans('Color, size and stock filter exactly: “red, XL, in stock” finds only products with a red XL in stock. Each combination counts as a record in your plan.', [], 'Modules.Bitlane.Admin'),
            'One result per product' => $t->trans('One result per product', [], 'Modules.Bitlane.Admin'),
            'Fewer records, but filters combine combinations: a red S and a blue XL match “red, XL”, and stock is the product’s.' => $t->trans('Fewer records, but filters combine combinations: a red S and a blue XL match “red, XL”, and stock is the product’s.', [], 'Modules.Bitlane.Admin'),
            'Brand color' => $t->trans('Brand color', [], 'Modules.Bitlane.Admin'),
            'The search widget’s buttons and highlights. Bitlane uses it for a new widget; after that, change the colors in Bitlane.' => $t->trans('The search widget’s buttons and highlights. Bitlane uses it for a new widget; after that, change the colors in Bitlane.', [], 'Modules.Bitlane.Admin'),
            'No color' => $t->trans('No color', [], 'Modules.Bitlane.Admin'),
            'Logo' => $t->trans('Logo', [], 'Modules.Bitlane.Admin'),
            'Shown at the top of the search widget’s filters. Bitlane uses it for a widget without a logo; after that, change it in Bitlane.' => $t->trans('Shown at the top of the search widget’s filters. Bitlane uses it for a widget without a logo; after that, change it in Bitlane.', [], 'Modules.Bitlane.Admin'),
            'Logo address' => $t->trans('Logo address', [], 'Modules.Bitlane.Admin'),
            'Use the shop’s logo' => $t->trans('Use the shop’s logo', [], 'Modules.Bitlane.Admin'),
            'Remove' => $t->trans('Remove', [], 'Modules.Bitlane.Admin'),
            'Search box' => $t->trans('Search box', [], 'Modules.Bitlane.Admin'),
            'CSS selectors for your theme’s search field and buttons, separated by commas: clicking one opens Bitlane’s search.' => $t->trans('CSS selectors for your theme’s search field and buttons, separated by commas: clicking one opens Bitlane’s search.', [], 'Modules.Bitlane.Admin'),

            // The set-up shop.
            'Search widget' => $t->trans('Search widget', [], 'Modules.Bitlane.Admin'),
            'Storefront' => $t->trans('Storefront', [], 'Modules.Bitlane.Admin'),
            'Bitlane %version% is out: click Upgrade on Bitlane in Modules › Module Manager, or download it and upload it there. Your connection, settings and sync stay.' => $t->trans('Bitlane %version% is out: click Upgrade on Bitlane in Modules › Module Manager, or download it and upload it there. Your connection, settings and sync stay.', [], 'Modules.Bitlane.Admin'),
            'Download' => $t->trans('Download', [], 'Modules.Bitlane.Admin'),
            'The search opens from your shop’s search box on every page. Searches, clicks and orders are counted in Bitlane’s analytics.' => $t->trans('The search opens from your shop’s search box on every page. Searches, clicks and orders are counted in Bitlane’s analytics.', [], 'Modules.Bitlane.Admin'),
            'No active widget yet: the search shows on your pages once one is active in Bitlane.' => $t->trans('No active widget yet: the search shows on your pages once one is active in Bitlane.', [], 'Modules.Bitlane.Admin'),
            'Show the search on the storefront' => $t->trans('Show the search on the storefront', [], 'Modules.Bitlane.Admin'),
            'Wait for cookie consent before counting visits' => $t->trans('Wait for cookie consent before counting visits', [], 'Modules.Bitlane.Admin'),
            'Searches still work. Counting starts once the visitor accepts statistics cookies in a banner that uses Google Consent Mode, or when your own script calls Bitlane.consent(true).' => $t->trans('Searches still work. Counting starts once the visitor accepts statistics cookies in a banner that uses Google Consent Mode, or when your own script calls Bitlane.consent(true).', [], 'Modules.Bitlane.Admin'),
            'The widget opens from your theme’s search box. Its look and texts are set in Bitlane.' => $t->trans('The widget opens from your theme’s search box. Its look and texts are set in Bitlane.', [], 'Modules.Bitlane.Admin'),
            '%content% in %languages%' => $t->trans('%content% in %languages%', [], 'Modules.Bitlane.Admin'),
            'Open' => $t->trans('Open', [], 'Modules.Bitlane.Admin'),
            'Engines' => $t->trans('Engines', [], 'Modules.Bitlane.Admin'),
            'Where your catalogue is searched: an index per kind of content and language.' => $t->trans('Where your catalogue is searched: an index per kind of content and language.', [], 'Modules.Bitlane.Admin'),
            'Open engine' => $t->trans('Open engine', [], 'Modules.Bitlane.Admin'),
            'No indexes yet.' => $t->trans('No indexes yet.', [], 'Modules.Bitlane.Admin'),
            'Content' => $t->trans('Content', [], 'Modules.Bitlane.Admin'),
            'Each kind of content has its own index in each of the shop’s languages, kept up to date as it changes.' => $t->trans('Each kind of content has its own index in each of the shop’s languages, kept up to date as it changes.', [], 'Modules.Bitlane.Admin'),
            'Last error: %message% (%date%)' => $t->trans('Last error: %message% (%date%)', [], 'Modules.Bitlane.Admin'),
            'Syncing: %done% of %total%' => $t->trans('Syncing: %done% of %total%', [], 'Modules.Bitlane.Admin'),
            'Synced %date%' => $t->trans('Synced %date%', [], 'Modules.Bitlane.Admin'),
            'Not synced yet' => $t->trans('Not synced yet', [], 'Modules.Bitlane.Admin'),
            'Not in the search widget' => $t->trans('Not in the search widget', [], 'Modules.Bitlane.Admin'),
            'Resync' => $t->trans('Resync', [], 'Modules.Bitlane.Admin'),
            'Resync all' => $t->trans('Resync all', [], 'Modules.Bitlane.Admin'),
            'Changes waiting to sync: %count%' => $t->trans('Changes waiting to sync: %count%', [], 'Modules.Bitlane.Admin'),

            // The Jobs tab.
            'Jobs' => $t->trans('Jobs', [], 'Modules.Bitlane.Admin'),
            'What the sync has to do: changes, full syncs and the daily check. Failed jobs stay a week.' => $t->trans('What the sync has to do: changes, full syncs and the daily check. Failed jobs stay a week.', [], 'Modules.Bitlane.Admin'),
            'Run now' => $t->trans('Run now', [], 'Modules.Bitlane.Admin'),
            'Waiting: %waiting% · Failed: %failed%' => $t->trans('Waiting: %waiting% · Failed: %failed%', [], 'Modules.Bitlane.Admin'),
            'Nothing to do: the sync is up to date.' => $t->trans('Nothing to do: the sync is up to date.', [], 'Modules.Bitlane.Admin'),
            'Error: %message%' => $t->trans('Error: %message%', [], 'Modules.Bitlane.Admin'),
            'Retry' => $t->trans('Retry', [], 'Modules.Bitlane.Admin'),
            'Delete' => $t->trans('Delete', [], 'Modules.Bitlane.Admin'),
            'Failed %date%' => $t->trans('Failed %date%', [], 'Modules.Bitlane.Admin'),
            'Attempt %count%' => $t->trans('Attempt %count%', [], 'Modules.Bitlane.Admin'),
            'Waiting' => $t->trans('Waiting', [], 'Modules.Bitlane.Admin'),
            'Scheduled: %date%' => $t->trans('Scheduled: %date%', [], 'Modules.Bitlane.Admin'),
            'Sync %count% changed items: %source%' => $t->trans('Sync %count% changed items: %source%', [], 'Modules.Bitlane.Admin'),
            'Full sync: %source%' => $t->trans('Full sync: %source%', [], 'Modules.Bitlane.Admin'),
            'Start a full sync: %source%' => $t->trans('Start a full sync: %source%', [], 'Modules.Bitlane.Admin'),
            'Find the products of a changed %what% (#%id%)' => $t->trans('Find the products of a changed %what% (#%id%)', [], 'Modules.Bitlane.Admin'),
            'Daily check' => $t->trans('Daily check', [], 'Modules.Bitlane.Admin'),
            'Refresh the storefront’s widget' => $t->trans('Refresh the storefront’s widget', [], 'Modules.Bitlane.Admin'),
            'Send an addition to a cart' => $t->trans('Send an addition to a cart', [], 'Modules.Bitlane.Admin'),
            'Send order #%id%' => $t->trans('Send order #%id%', [], 'Modules.Bitlane.Admin'),
            'Send credit slip #%id%' => $t->trans('Send credit slip #%id%', [], 'Modules.Bitlane.Admin'),
            'Send cancelled order #%id%' => $t->trans('Send cancelled order #%id%', [], 'Modules.Bitlane.Admin'),
            'Send the shop’s address and name to Bitlane' => $t->trans('Send the shop’s address and name to Bitlane', [], 'Modules.Bitlane.Admin'),
            'Check %source% in %language%' => $t->trans('Check %source% in %language%', [], 'Modules.Bitlane.Admin'),
            'category' => $t->trans('category', [], 'Modules.Bitlane.Admin'),
            'brand' => $t->trans('brand', [], 'Modules.Bitlane.Admin'),
            'attribute value' => $t->trans('attribute value', [], 'Modules.Bitlane.Admin'),
            'attribute' => $t->trans('attribute', [], 'Modules.Bitlane.Admin'),
            'feature' => $t->trans('feature', [], 'Modules.Bitlane.Admin'),
            'feature value' => $t->trans('feature value', [], 'Modules.Bitlane.Admin'),

            // The cron, Disconnect.
            'Background sync' => $t->trans('Background sync', [], 'Modules.Bitlane.Admin'),
            'Changes reach Bitlane through a cron job that runs every minute. Add this line to the server’s crontab:' => $t->trans('Changes reach Bitlane through a cron job that runs every minute. Add this line to the server’s crontab:', [], 'Modules.Bitlane.Admin'),
            'Changes have waited since %date%: check that the cron runs.' => $t->trans('Changes have waited since %date%: check that the cron runs.', [], 'Modules.Bitlane.Admin'),
            'The cron hasn’t run yet: changes wait until it does. While this page is open, it runs them.' => $t->trans('The cron hasn’t run yet: changes wait until it does. While this page is open, it runs them.', [], 'Modules.Bitlane.Admin'),
            'Without shell access, have your hosting panel or PrestaShop’s Cron tasks manager call this address every minute:' => $t->trans('Without shell access, have your hosting panel or PrestaShop’s Cron tasks manager call this address every minute:', [], 'Modules.Bitlane.Admin'),
            'Last run: %date%' => $t->trans('Last run: %date%', [], 'Modules.Bitlane.Admin'),
            'It hasn’t run yet.' => $t->trans('It hasn’t run yet.', [], 'Modules.Bitlane.Admin'),
            '%name% (%url%) is connected to Bitlane.' => $t->trans('%name% (%url%) is connected to Bitlane.', [], 'Modules.Bitlane.Admin'),
            'Disconnect this shop from Bitlane? Its search stops updating; the engine and indexes stay in Bitlane.' => $t->trans('Disconnect this shop from Bitlane? Its search stops updating; the engine and indexes stay in Bitlane.', [], 'Modules.Bitlane.Admin'),
            'Disconnect' => $t->trans('Disconnect', [], 'Modules.Bitlane.Admin'),

            // Engines' and widgets' statuses.
            'Active' => $t->trans('Active', [], 'Modules.Bitlane.Admin'),
            'Draft' => $t->trans('Draft', [], 'Modules.Bitlane.Admin'),
            'Running' => $t->trans('Running', [], 'Modules.Bitlane.Admin'),
            'Starting' => $t->trans('Starting', [], 'Modules.Bitlane.Admin'),
            'Updating' => $t->trans('Updating', [], 'Modules.Bitlane.Admin'),
            'Moving' => $t->trans('Moving', [], 'Modules.Bitlane.Admin'),
            'Failed' => $t->trans('Failed', [], 'Modules.Bitlane.Admin'),
            'Deleting' => $t->trans('Deleting', [], 'Modules.Bitlane.Admin'),
            'Stopped' => $t->trans('Stopped', [], 'Modules.Bitlane.Admin'),
        ];
    }
}
