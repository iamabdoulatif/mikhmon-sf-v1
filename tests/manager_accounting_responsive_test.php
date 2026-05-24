<?php

$root = dirname(__DIR__);
$manager = file_get_contents($root . '/manager.php');
$responsive = file_get_contents($root . '/css/mikhmon-responsive.css');
$portalCss = file_get_contents($root . '/css/mikhmon-portal.css');

if ($manager === false || $responsive === false || $portalCss === false) {
    fwrite(STDERR, "could not read manager accounting responsive files\n");
    exit(1);
}

$css = $responsive . "\n" . $portalCss;

if (strpos($manager, "\$managerAllowedActions = array('dashboard', 'overview', 'accounting', 'tickets', 'vendors', 'logout')") === false) {
    fwrite(STDERR, "manager accounting route must be available to the manager portal\n");
    exit(1);
}

$managerChecks = array(
    'accounting shell class' => 'mgr-accounting-shell',
    'accounting bootstrap form row' => 'row mgr-accounting-form manager-accounting-row manager-accounting-form-row',
    'accounting bootstrap form column' => 'portal-filter-item col-4 col-box-12 manager-bootstrap-col',
    'start time label' => 'Heure début',
    'end time label' => 'Heure fin',
    'seller accounting label' => 'Compte vendeur',
);

foreach ($managerChecks as $label => $needle) {
    if (strpos($manager, $needle) === false) {
        fwrite(STDERR, $label . " missing from manager.php\n");
        exit(1);
    }
}

$cssChecks = array(
    'mobile accounting shell rule' => '.manager-portal .mgr-accounting-shell',
    'mobile accounting form rule' => '.manager-portal .mgr-accounting-form',
    'mobile accounting actions rule' => '.manager-portal .mgr-accounting-actions',
    'bootstrap column helper rule' => '.manager-portal .manager-bootstrap-col',
    'bootstrap container helper rule' => '.manager-portal .container-fluid',
    'full width accounting buttons' => '.manager-portal .mgr-accounting-actions .btn',
);

foreach ($cssChecks as $label => $needle) {
    if (strpos($css, $needle) === false) {
        fwrite(STDERR, $label . " missing from mikhmon-responsive.css\n");
        exit(1);
    }
}

echo "manager_accounting_responsive_test passed\n";
