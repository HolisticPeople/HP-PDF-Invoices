<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assets = file_get_contents($root . '/includes/Assets.php');
$admin = file_get_contents($root . '/includes/Admin.php');
$plugin = file_get_contents($root . '/hp-pdf-invoices.php');

if (!is_string($assets) || !is_string($admin) || !is_string($plugin)) {
    fwrite(STDERR, 'Could not read HP Invoices source files.' . PHP_EOL);
    exit(1);
}

if (strpos($assets, "'woocommerce_page_wc-orders'") === false) {
    fwrite(STDERR, 'HP Invoices assets should still load its invoice controls on WooCommerce orders screens.' . PHP_EOL);
    exit(1);
}

if (strpos($assets, 'hp_zen_enqueue_admin_surface') !== false) {
    fwrite(STDERR, 'HP Invoices must not own the WooCommerce orders-list HP-Zen runtime opt-in.' . PHP_EOL);
    exit(1);
}

if (strpos($admin, "do_action( 'hp_zen_enqueue_admin_surface', 'hp-pdf-invoices' );") === false) {
    fwrite(STDERR, 'HP Invoices settings screen should keep its own HP-Zen admin surface opt-in.' . PHP_EOL);
    exit(1);
}

if (strpos($plugin, 'Version:     3.0.4') === false || strpos($plugin, "define( 'HP_PDFI_VERSION', '3.0.4' );") === false) {
    fwrite(STDERR, 'HP Invoices release metadata should be 3.0.4.' . PHP_EOL);
    exit(1);
}

echo "HP Invoices admin Zen ownership contract passed.\n";
