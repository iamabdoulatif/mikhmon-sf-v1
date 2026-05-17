<?php
session_id('generate-user-index-include-path-test');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SERVER['REQUEST_URI'] = '/mikhmon/?hotspot-user=generate&session=BAM-TECH';
$_GET = array(
    'hotspot-user' => 'generate',
    'session' => 'BAM-TECH',
);
$_POST = array();

$_SESSION['mikhmon'] = 'mikhmon';
$_SESSION['timezone'] = 'UTC';
$_SESSION['theme'] = 'dark';
$_SESSION['themecolor'] = '#3a4149';
$_SESSION['ubp'] = '';
$_SESSION['vcr'] = '';

$session = 'BAM-TECH';
$currency = 'XOF';
$cekindo = array('indo' => array());
$theme = 'dark';
$themecolor = '#3a4149';

require_once __DIR__ . '/../lib/routeros_api.class.php';
require_once __DIR__ . '/../lib/formatbytesbites.php';

class GenerateUserIndexApiStub {
    public function comm($path, $params = array()) {
        if ($path === '/ip/hotspot/print') {
            return array(array('name' => 'all'));
        }

        if ($path === '/ip/hotspot/user/profile/print') {
            return array(array(
                'name' => 'default',
                'on-login' => ',,100,1d,150,,Disable',
            ));
        }

        return array();
    }
}

$API = new GenerateUserIndexApiStub();

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        fwrite(STDERR, $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] . PHP_EOL);
    }
});

ob_start();
include __DIR__ . '/../hotspot/generateuser.php';
$html = ob_get_clean();

if (!function_exists('mikhmon_format_money_amount')) {
    fwrite(STDERR, "hotspot/generateuser.php did not load mikhmon_compat.php\n");
    exit(1);
}

if (strpos($html, 'name="qty"') === false || strpos($html, 'id="uprof"') === false) {
    fwrite(STDERR, "generate user form did not render in index.php context\n");
    exit(1);
}

echo "generate_user_index_include_path_test passed\n";
