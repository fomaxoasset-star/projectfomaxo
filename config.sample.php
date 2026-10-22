<?php
/*
 * FOMAXO Store System - settings
 *
 * 1. Copy this file and name the copy  config.php
 * 2. Fill in the values below.
 * 3. Never share config.php - it holds your database password and Ziina key.
 *
 * Safer option: put config.php ONE FOLDER ABOVE the app folder
 * (for example next to public_html) and the app will find it there first.
 */

return [
    // ---- Database (Hostinger: Websites > Databases > MySQL Databases) ----
    'db_driver' => 'mysql',            // keep 'mysql' on Hostinger
    'db_host'   => 'localhost',        // on Hostinger this is always localhost
    'db_name'   => 'u123456789_fomaxopos',
    'db_user'   => 'u123456789_posuser',
    'db_pass'   => 'CHANGE-ME',

    // ---- Store details (printed on every receipt) ----
    'store_name'    => 'FOMAXO',
    'store_tagline' => 'For Outstanding Minds, Acquiring Xtraordinary Opulence',
    'store_address' => 'Store address, Sharjah, UAE',
    'store_phone'   => '+971 00 000 0000',
    'store_trn'     => '',             // UAE VAT TRN - printed on receipts if filled in
    'receipt_footer'=> 'Thank you for shopping with FOMAXO',

    // ---- Money ----
    'currency'           => 'AED',
    'vat_rate'           => 5,          // UAE VAT %
    'prices_include_vat' => true,       // true = shelf prices already include VAT
    'default_low_stock'  => 5,          // "running low" warning level for new products
    'staff_max_discount' => 10,         // biggest discount % staff can give (managers: no limit)

    // ---- Full web address of this app, no slash at the end ----
    'base_url' => 'https://pos.fomaxo.com',

    // ---- Ziina (Ziina app/dashboard > Developers > API key) ----
    'ziina_token' => '',                // your Ziina API key
    // 'demo' = practice page, no Ziina account needed (try the app first)
    // 'test' = Ziina test payments with your key, no real money
    // 'live' = real payments
    'ziina_mode'  => 'demo',

    // ---- Products come from the website and stay in sync (every 10 minutes) ----
    'website_products_url' => 'https://fomaxo.com/products.php',
    'website_auto_sync'    => true,

    // ---- FOMAXO Elite tiers: yearly spend in AED ----
    'tiers' => [
        ['name' => 'Imperial', 'min' => 30000],
        ['name' => 'Elite',    'min' => 15000],
        ['name' => 'Gold',     'min' => 7500],
        ['name' => 'Black',    'min' => 4000],
    ],

    // Show errors on screen. Keep false on the live site.
    'debug' => false,
];
