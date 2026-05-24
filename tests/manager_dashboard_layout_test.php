<?php

$root = dirname(__DIR__);
$manager = file_get_contents($root . '/manager.php');
$responsive = file_get_contents($root . '/css/mikhmon-responsive.css');

if ($manager === false || $responsive === false) {
    fwrite(STDERR, "could not read manager dashboard files\n");
    exit(1);
}

$dashboardStart = strpos($manager, "<?php elseif (\$action === 'dashboard'): ?>");
$dashboardEnd = strpos($manager, "<?php elseif (\$action === 'overview'): ?>");
if ($dashboardStart === false || $dashboardEnd === false || $dashboardEnd <= $dashboardStart) {
    fwrite(STDERR, "manager dashboard must use a normal dashboard action block\n");
    exit(1);
}

$dashboard = substr($manager, $dashboardStart, $dashboardEnd - $dashboardStart);

$rows = array(
    'manager dashboard row hook' => 'manager-dashboard-row',
    'manager hotspot row' => 'manager-dashboard-hotspot-row',
    'manager vendor row' => 'manager-dashboard-vendor-row',
    'manager role row' => 'manager-dashboard-role-row',
);

foreach ($rows as $label => $needle) {
    if (strpos($dashboard, $needle) === false) {
        fwrite(STDERR, $label . " missing\n");
        exit(1);
    }
}

if (substr_count($dashboard, 'manager-dashboard-row') !== 3) {
    fwrite(STDERR, "manager dashboard must render exactly three named rows\n");
    exit(1);
}

$titles = array('Hotspot', 'Gestion vendeurs', 'Rôle du gérant');
foreach ($titles as $title) {
    if (strpos($dashboard, '<h3><i class="fa ') === false || strpos($dashboard, $title) === false) {
        fwrite(STDERR, "manager dashboard row title missing: " . $title . "\n");
        exit(1);
    }
}

foreach (array('manager-dashboard-hotspot-row', 'manager-dashboard-vendor-row') as $rowClass) {
    $rowStart = strpos($dashboard, $rowClass);
    $nextRow = strpos($dashboard, 'manager-dashboard-row', $rowStart + strlen($rowClass));
    $row = substr($dashboard, $rowStart, $nextRow === false ? null : $nextRow - $rowStart);
    foreach (array('bg-blue', 'bg-green', 'bg-yellow', 'bg-red') as $colorClass) {
        if (strpos($row, $colorClass) === false) {
            fwrite(STDERR, $rowClass . " must use admin card color " . $colorClass . "\n");
            exit(1);
        }
    }
}

foreach (array('.manager-dashboard-row', '.manager-dashboard-grid', '.manager-dashboard-role-row') as $cssHook) {
    if (strpos($responsive, $cssHook) === false) {
        fwrite(STDERR, "manager dashboard responsive CSS missing: " . $cssHook . "\n");
        exit(1);
    }
}

echo "manager_dashboard_layout_test passed\n";
