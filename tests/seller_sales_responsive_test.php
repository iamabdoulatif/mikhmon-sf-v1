<?php

$root = dirname(__DIR__);
$seller = file_get_contents($root . '/sellers.php');
$responsive = file_get_contents($root . '/css/mikhmon-responsive.css');
$portalCss = file_get_contents($root . '/css/mikhmon-portal.css');

if ($seller === false || $responsive === false || $portalCss === false) {
    fwrite(STDERR, "could not read seller sales files\n");
    exit(1);
}

$css = $responsive . "\n" . $portalCss;

$salesStart = strpos($seller, '$totalStockVendor = array_sum($sellerStock);');
$salesEnd = strpos($seller, "<?php endif; // end action=transfer vs sales ?>");
if ($salesStart === false || $salesEnd === false || $salesEnd <= $salesStart) {
    fwrite(STDERR, "could not isolate seller sales block\n");
    exit(1);
}

$sales = substr($seller, $salesStart, $salesEnd - $salesStart);

$requiredHooks = array(
    'seller sales summary row' => 'seller-sales-summary-row',
    'seller sales summary grid' => 'row dashboard-hotspot-grid seller-sales-summary-grid',
    'seller sales bootstrap columns' => 'col-3 col-box-6 seller-bootstrap-col',
    'seller sales table class' => 'portal-sales-table seller-sales-table',
    'seller sales mobile value wrapper' => 'seller-sales-cell-value',
    'seller sales no horizontal labels' => 'data-label="<?= htmlspecialchars($_date) ?>"',
    'seller sales stock card label' => 'seller-sales-stock-list',
);

foreach ($requiredHooks as $label => $needle) {
    if (strpos($sales, $needle) === false) {
        fwrite(STDERR, $label . " missing from seller sales page\n");
        exit(1);
    }
}

foreach (array('bg-blue', 'bg-green', 'bg-yellow', 'bg-red') as $colorClass) {
    if (strpos($sales, 'box ' . $colorClass) === false) {
        fwrite(STDERR, "seller sales summary must use " . $colorClass . "\n");
        exit(1);
    }
}

foreach (array(
    '.seller-bootstrap-col',
    '.seller-sales-summary-grid',
    '.seller-portal .seller-sales-summary-grid > .seller-bootstrap-col',
    '.seller-portal .portal-sales-table td::before',
    '.seller-sales-cell-value',
    '.seller-sales-empty-row',
    'overflow-x: visible !important;',
    'min-width: 0 !important;',
) as $cssHook) {
    if (strpos($css, $cssHook) === false) {
        fwrite(STDERR, "seller sales responsive CSS missing: " . $cssHook . "\n");
        exit(1);
    }
}

if (strpos($css, '@media screen and (min-width: 750px)') === false || strpos($css, 'width: 25% !important;') === false) {
    fwrite(STDERR, "seller sales cards must stay four aligned on desktop\n");
    exit(1);
}

if (strpos($css, '@media screen and (max-width: 750px)') === false || strpos($css, 'width: 50% !important;') === false) {
    fwrite(STDERR, "seller sales cards must switch to two-by-two on mobile\n");
    exit(1);
}

echo "seller_sales_responsive_test passed\n";
