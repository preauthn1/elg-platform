<?php

declare(strict_types=1);

use Xtheme\ArtisanUI\Http\Middleware\AuthorizeArtisanUI;

return [
    /*
    |--------------------------------------------------------------------------
    | Artisan UI Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Artisan UI will be accessible from. If this
    | setting is null, Artisan UI will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Artisan UI Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where the Artisan UI pages will be accessible from.
    | All Artisan UI routes will be prefixed by this path. E.g. "/artisan",
    | "/artisan/migrate". Feel free to change this to anything you like.
    |
    */

    'path' => 'artisan',

    /*
    |--------------------------------------------------------------------------
    | Artisan UI Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached to each Artisan UI route, allowing
    | you to add your own middleware to this list or change any of the
    | existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web', AuthorizeArtisanUI::class],

    /*
    |--------------------------------------------------------------------------
    | Artisan UI Command Whitelist
    |--------------------------------------------------------------------------
    |
    | When this value is null, all artisan commands are available in Artisan
    | UI. When set to an array, only the listed command names will be visible
    | and executable from the UI. You may also use prefix patterns such as
    | "cache:*" or "queue:*".
    |
    */

    // 'command_whitelist' => null,

    // Example:
    'command_whitelist' => [
        'update_game_list',
        'summary_bet_record',
        'cache:*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Artisan UI Command Blacklist
    |--------------------------------------------------------------------------
    |
    | Command names listed here will never be visible or executable in
    | Artisan UI. This applies even if a command is present in the whitelist.
    | You may also use prefix patterns like "db:*" or "migrate:*".
    |
    */

    'command_blacklist' => [
        'migrate:*',
        'db:*',
        'tinker',
    ],

    /*
    |--------------------------------------------------------------------------
    | Boolean Switch Size
    |--------------------------------------------------------------------------
    |
    | Controls the size of boolean switch components in the detail page.
    | Supported values: sm, md, lg.
    |
    */

    'switch_size' => 'md',
];
