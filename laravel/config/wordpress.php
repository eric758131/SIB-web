<?php

/*
|--------------------------------------------------------------------------
| WordPress database integration
|--------------------------------------------------------------------------
|
| The API reads and writes the site database (DB_DATABASE in .env)
| directly. The table prefix itself is set with DB_PREFIX in .env.
|
*/

return [

    // Prefix used inside meta keys such as "{prefix}capabilities".
    'prefix' => env('DB_PREFIX', 'wp8t_'),

    // Public address of the site, used to build attachment GUIDs and links.
    'site_url' => rtrim(env('WP_SITE_URL', 'https://siblpz.org.bo'), '/'),

    // SureForms form that backs the contact page.
    'contact_form_id' => (int) env('WP_CONTACT_FORM_ID', 1113),

    // Lifetime of admin panel tokens, in days.
    'token_ttl_days' => (int) env('WP_TOKEN_TTL_DAYS', 7),

    // WordPress roles that may sign in to the admin panel, and what they may do.
    'panel_roles' => [
        'administrator' => 'Administrador',
        'editor' => 'Editor',
        'author' => 'Autor',
    ],

];
