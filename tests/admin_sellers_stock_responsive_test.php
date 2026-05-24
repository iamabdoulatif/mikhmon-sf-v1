<?php

$root = dirname(__DIR__);
$manage = file_get_contents($root . '/settings/manage_sellers.php');
$responsive = file_get_contents($root . '/css/mikhmon-responsive.css');
$portalCss = file_get_contents($root . '/css/mikhmon-portal.css');

if ($manage === false || $responsive === false || $portalCss === false) {
    fwrite(STDERR, "could not read admin sellers stock files\n");
    exit(1);
}

$css = $responsive . "\n" . $portalCss;

$stockStart = strpos($manage, '<!-- Stock par vendeur -->');
$stockEnd = strpos($manage, '<!-- Formulaire transfert admin -->');
if ($stockStart === false || $stockEnd === false || $stockEnd <= $stockStart) {
    fwrite(STDERR, "could not isolate admin seller stock block\n");
    exit(1);
}

$stock = substr($manage, $stockStart, $stockEnd - $stockStart);

foreach (array(
    'admin seller stock row' => 'row admin-seller-stock-row',
    'admin seller stock column' => 'col-6 col-box-6 admin-seller-stock-col',
    'admin seller stock card' => 'admin-seller-stock-card',
    'admin seller stock profile table' => 'admin-seller-stock-table',
    'admin seller stock value wrapper' => 'admin-seller-stock-value',
    'admin seller stock empty state' => 'admin-seller-stock-empty',
    'admin seller stock total badge' => 'admin-seller-stock-total',
) as $label => $needle) {
    if (strpos($stock, $needle) === false) {
        fwrite(STDERR, $label . " missing from admin sellers stock block\n");
        exit(1);
    }
}

foreach (array(
    '.admin-seller-stock-row',
    '.admin-seller-stock-col',
    '.admin-seller-stock-card',
    '.admin-seller-stock-table',
    '.admin-seller-stock-table td::before',
    '.admin-seller-stock-value',
    '.portal-admin-shell .admin-seller-stock-row.row > .admin-seller-stock-col',
    'width: 50% !important;',
    'width: 100% !important;',
) as $cssHook) {
    if (strpos($css, $cssHook) === false) {
        fwrite(STDERR, "admin sellers stock responsive CSS missing: " . $cssHook . "\n");
        exit(1);
    }
}

echo "admin_sellers_stock_responsive_test passed\n";
