<?php
session_id('dashboard-home-include-path-test');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION['mikhmon'] = 'mikhmon';

$session = 'MB-TECH';
$currency = 'XOF';
$cekindo = array('indo' => array());
$livereport = 'enable';
$iface = 1;

require_once __DIR__ . '/../lib/formatbytesbites.php';
require_once __DIR__ . '/../lib/routeros_api.class.php';

class DashboardHomeApiStub {
    public function comm($path, $params = array()) {
        if ($path === '/system/clock/print') {
            return array(array(
                'date' => 'may/14/2026',
                'time' => '12:05:45',
                'time-zone-name' => 'UTC',
            ));
        }
        if ($path === '/system/resource/print') {
            return array(array(
                'uptime' => '1d2h3m4s',
                'board-name' => 'RB4011iGS+5HacQ2HnD',
                'version' => '7.22.2',
                'cpu-load' => '1',
                'free-memory' => '12345678',
                'free-hdd-space' => '23456789',
            ));
        }
        if ($path === '/system/routerboard/print') {
            return array(array('model' => 'RB4011'));
        }
        if ($path === '/ip/hotspot/user/print') {
            return '0';
        }
        if ($path === '/ip/hotspot/active/print') {
            return '0';
        }
        if ($path === '/system/script/print') {
            return array();
        }
        if ($path === '/interface/print') {
            return array(array('name' => 'ether1'));
        }
        return array();
    }
}

$API = new DashboardHomeApiStub();

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        fwrite(STDERR, $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] . PHP_EOL);
    }
});

ob_start();
include __DIR__ . '/../dashboard/home.php';
$html = ob_get_clean();

if (!function_exists('mikhmon_normalize_sale_date')) {
    fwrite(STDERR, "dashboard/home.php did not load mikhmon_compat.php\n");
    exit(1);
}

if (strpos($html, 'id="reloadHome"') === false) {
    fwrite(STDERR, "dashboard home did not render the reloadHome container\n");
    exit(1);
}

echo "dashboard_home_include_path_test passed\n";
