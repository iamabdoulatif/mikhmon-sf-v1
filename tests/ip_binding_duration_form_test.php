<?php
session_id('ip-binding-duration-form-test');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION['mikhmon'] = 'mikhmon';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = array();

$session = 'BAM-TECH';
$currency = 'XOF';
$cekindo = array('indo' => array());
$_ip_bindings = 'IP Bindings';
$_name = 'Name';
$_profile = 'Profile';

require_once __DIR__ . '/../include/mikhmon_compat.php';

class IpBindingDurationFormApiStub {
    public function comm($path, $params = array()) {
        if ($path === '/ip/hotspot/user/profile/print') {
            return array(
                array('name' => '1H', 'on-login' => mikhmon_build_on_login_script('rem', '100', '1h', '100', 'Disable', '', '')),
                array('name' => '45M', 'on-login' => mikhmon_build_on_login_script('rem', '100', '45m', '100', 'Disable', '', '')),
            );
        }
        if ($path === '/ip/hotspot/print') {
            return array(array('name' => 'hotspot1'));
        }
        if ($path === '/ip/hotspot/ip-binding/print' && isset($params['count-only'])) {
            return 0;
        }
        if ($path === '/ip/hotspot/ip-binding/print') {
            return array();
        }
        return array();
    }
}

$API = new IpBindingDurationFormApiStub();

ob_start();
include __DIR__ . '/../hotspot/ipbinding.php';
$html = ob_get_clean();

$checks = array(
    'form action' => 'name="add_ip_binding_duration"',
    'responsive panel' => 'ipbind-duration-panel',
    'responsive grid' => 'ipbind-duration-grid',
    'mac field' => 'name="binding_mac"',
    'profile select' => 'id="bindingProfile"',
    'duration field' => 'id="bindingDuration"',
    'profile validity' => 'data-validity="45m"',
    'server option' => 'hotspot1',
);

foreach ($checks as $label => $needle) {
    if (strpos($html, $needle) === false) {
        fwrite(STDERR, $label . ' missing from IP Binding duration form' . PHP_EOL);
        exit(1);
    }
}

echo "ip_binding_duration_form_test passed\n";
