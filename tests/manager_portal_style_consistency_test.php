<?php

$root = dirname(__DIR__);
$manager = file_get_contents($root . '/manager.php');
$responsive = file_get_contents($root . '/css/mikhmon-responsive.css');
$portalCss = file_get_contents($root . '/css/mikhmon-portal.css');

if ($manager === false || $responsive === false || $portalCss === false) {
    fwrite(STDERR, "could not read manager portal style files\n");
    exit(1);
}

$css = $responsive . "\n" . $portalCss;

function section_between($contents, $startNeedle, $endNeedle)
{
    $start = strpos($contents, $startNeedle);
    $end = strpos($contents, $endNeedle, $start === false ? 0 : $start);
    if ($start === false || $end === false || $end <= $start) {
        return false;
    }
    return substr($contents, $start, $end - $start);
}

$sections = array(
    'accounting' => section_between($manager, "<?php elseif (\$action === 'accounting'): ?>", "<?php elseif (\$action === 'transfer'): ?>"),
    'tickets' => section_between($manager, "<?php elseif (\$action === 'tickets'): ?>", '<script>'),
    'vendors' => section_between($manager, "<?php elseif (\$action === 'vendors'): ?>", "<?php elseif (\$action === 'logs'): ?>"),
);

foreach ($sections as $name => $section) {
    if ($section === false) {
        fwrite(STDERR, "could not isolate manager " . $name . " section\n");
        exit(1);
    }
}

$required = array(
    'accounting' => array('container-fluid manager-accounting-container', 'row manager-accounting-row', 'row mgr-summary-cards manager-accounting-row manager-accounting-summary-row', 'manager-accounting-grid', 'col-3 col-box-6 manager-bootstrap-col'),
    'tickets' => array('manager-tickets-shell', 'container-fluid manager-tickets-container', 'row manager-tickets-row manager-tickets-form-row', 'row manager-tickets-row manager-tickets-summary-row', 'manager-tickets-grid', 'col-3 col-box-6 manager-bootstrap-col'),
    'vendors' => array('manager-vendors-shell', 'container-fluid manager-vendors-container', 'row mgr-summary-cards manager-vendors-row manager-vendors-summary-row', 'manager-vendors-grid', 'col-3 col-box-6 manager-bootstrap-col manager-vendors-summary-col', 'row manager-vendors-row manager-vendors-list-row', 'manager-vendors-list-card', 'row manager-vendors-row manager-vendors-form-row', 'manager-vendors-form-card', 'row manager-vendors-row manager-vendors-form-grid'),
);

foreach ($required as $sectionName => $needles) {
    foreach ($needles as $needle) {
        if (strpos($sections[$sectionName], $needle) === false) {
            fwrite(STDERR, $sectionName . " style hook missing: " . $needle . "\n");
            exit(1);
        }
    }
}

foreach (array('accounting', 'tickets', 'vendors') as $sectionName) {
    foreach (array('bg-blue', 'bg-green', 'bg-yellow', 'bg-red') as $colorClass) {
        if (strpos($sections[$sectionName], 'manager-color-card ' . $colorClass) === false) {
            fwrite(STDERR, $sectionName . " must use manager color card " . $colorClass . "\n");
            exit(1);
        }
    }
}

foreach (array('.manager-accounting-row', '.manager-tickets-row', '.manager-vendors-row', '.manager-color-card', '.manager-bootstrap-col', '.manager-portal .container-fluid', '.manager-portal .mgr-summary-cards.row > .manager-bootstrap-col') as $cssHook) {
    if (strpos($css, $cssHook) === false) {
        fwrite(STDERR, "manager shared style CSS missing: " . $cssHook . "\n");
        exit(1);
    }
}

if (strpos($css, '@media screen and (min-width: 751px)') === false || strpos($css, 'width: 25% !important;') === false) {
    fwrite(STDERR, "manager cards must stay four aligned on desktop\n");
    exit(1);
}
if (strpos($css, '@media screen and (max-width: 750px)') === false || strpos($css, 'width: 50% !important;') === false) {
    fwrite(STDERR, "manager cards must switch to two-by-two on mobile\n");
    exit(1);
}

echo "manager_portal_style_consistency_test passed\n";
