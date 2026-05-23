<?php

$root = dirname(__DIR__);
$files = array(
    'dashboard/home.php' => file_get_contents($root . '/dashboard/home.php'),
    'dashboard/aload.php' => file_get_contents($root . '/dashboard/aload.php'),
);

function findMatchingDivEnd($contents, $needle)
{
    $start = strpos($contents, $needle);
    if ($start === false) {
        return false;
    }
    $tagStart = strrpos(substr($contents, 0, $start), '<div');
    if ($tagStart === false) {
        return false;
    }

    if (!preg_match_all('/<div\b|<\/div>/i', $contents, $matches, PREG_OFFSET_CAPTURE, $tagStart)) {
        return false;
    }

    $depth = 0;
    foreach ($matches[0] as $match) {
        $token = strtolower($match[0]);
        $offset = $match[1];
        if ($token === '<div') {
            $depth++;
            continue;
        }

        $depth--;
        if ($depth === 0) {
            return $offset + strlen($match[0]);
        }
    }

    return false;
}

function assertDashboardRowIsClosedBeforePppoe($path, $contents)
{
    $hotspotId = 'id="r_2" class="row dashboard-hotspot-row';
    $pppoeId = 'id="r_pppoe" class="row dashboard-pppoe-row';
    $hotspotStart = strpos($contents, $hotspotId);
    $pppoeStart = strpos($contents, $pppoeId);

    if ($hotspotStart === false || $pppoeStart === false || $hotspotStart > $pppoeStart) {
        fwrite(STDERR, $path . " must place Hotspot above PPPoE\n");
        exit(1);
    }

    $hotspotEnd = findMatchingDivEnd($contents, $hotspotId);
    if ($hotspotEnd === false || $hotspotEnd > $pppoeStart) {
        fwrite(STDERR, $path . " must close the Hotspot container before rendering PPPoE\n");
        exit(1);
    }
}

function assertPppoeFragmentIsBalanced($path, $contents)
{
    $pppoeId = 'id="r_pppoe" class="row dashboard-pppoe-row';
    $pppoeStart = strpos($contents, $pppoeId);
    if ($pppoeStart === false) {
        fwrite(STDERR, $path . " must render a PPPoE container\n");
        exit(1);
    }

    if (findMatchingDivEnd($contents, $pppoeId) === false) {
        fwrite(STDERR, $path . " must keep the PPPoE fragment HTML balanced\n");
        exit(1);
    }
}

function assertAjaxPppoeHasNoTrailingContainerClose($path, $contents)
{
    if ($path !== 'dashboard/aload.php') {
        return;
    }

    $pppoeId = 'id="r_pppoe" class="row dashboard-pppoe-row';
    $pppoeEnd = findMatchingDivEnd($contents, $pppoeId);
    $nextPhp = strpos($contents, '<?php', strpos($contents, $pppoeId));
    $tail = trim(substr($contents, $pppoeEnd, $nextPhp === false ? null : $nextPhp - $pppoeEnd));

    if ($tail !== '') {
        fwrite(STDERR, $path . " must not close another container after the PPPoE AJAX fragment\n");
        exit(1);
    }
}

foreach ($files as $path => $contents) {
    if ($contents === false) {
        fwrite(STDERR, "could not read " . $path . "\n");
        exit(1);
    }

    $hotspotRow = strpos($contents, 'dashboard-hotspot-row');
    $pppoeRow = strpos($contents, 'dashboard-pppoe-row');
    $pppoeCard = strpos($contents, 'dashboard-pppoe-card');
    $revenuePos = strpos($contents, 'reloadLreport');

    if ($hotspotRow === false || $pppoeRow === false || $pppoeCard === false) {
        fwrite(STDERR, $path . " must render Hotspot and PPPoE in separate dashboard containers\n");
        exit(1);
    }

    if ($hotspotRow > $pppoeRow) {
        fwrite(STDERR, $path . " must place Hotspot above PPPoE\n");
        exit(1);
    }

    if ($revenuePos !== false && $pppoeRow > $revenuePos) {
        fwrite(STDERR, $path . " must keep PPPoE outside the revenue container\n");
        exit(1);
    }

    if (substr_count($contents, 'id="r_pppoe"') !== 1) {
        fwrite(STDERR, $path . " must render exactly one PPPoE dashboard container\n");
        exit(1);
    }

    assertDashboardRowIsClosedBeforePppoe($path, $contents);
    assertPppoeFragmentIsBalanced($path, $contents);
    assertAjaxPppoeHasNoTrailingContainerClose($path, $contents);
}

$css = file_get_contents($root . '/css/mikhmon-responsive.css');
if ($css === false
    || strpos($css, '.dashboard-hotspot-row') === false
    || strpos($css, '.dashboard-pppoe-row') === false
    || strpos($css, '.dashboard-pppoe-card') === false
    || strpos($css, '.seller-portal .main-container > .row') === false
    || strpos($css, '.manager-portal .main-container > .row') === false
    || strpos($css, '.portal-admin-shell > .row') === false) {
    fwrite(STDERR, "dashboard PPPoE responsive CSS missing\n");
    exit(1);
}

$index = file_get_contents($root . '/index.php');
if ($index === false || strpos($index, '#r_pppoe') === false) {
    fwrite(STDERR, "dashboard PPPoE container must refresh independently\n");
    exit(1);
}

$hotspotRefresh = strpos($index, '#r_2');
$pppoeRefresh = strpos($index, '#r_pppoe');
if ($hotspotRefresh === false || $pppoeRefresh === false || $hotspotRefresh > $pppoeRefresh) {
    fwrite(STDERR, "dashboard refresh must update Hotspot before PPPoE\n");
    exit(1);
}

echo "dashboard_pppoe_container_test passed\n";
