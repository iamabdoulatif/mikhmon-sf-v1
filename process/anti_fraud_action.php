<?php
/* Action handler — acknowledge / resolve / unlock fraud incident */
session_start();
error_reporting(0);

if (empty($_SESSION['mikhmon'])) {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/../include/anti_fraud.php';

$user   = isset($_POST['user']) ? trim($_POST['user']) : '';
$status = isset($_POST['status']) ? trim($_POST['status']) : '';
$session = isset($_POST['session']) ? trim($_POST['session']) : '';

if ($user === '' || !in_array($status, array('acknowledged', 'resolved'), true)) {
    http_response_code(400);
    exit('bad request');
}

anti_fraud_set_status($user, $status, $_SESSION['mikhmon']);

// Optionally clear cookies for the user — protective measure
if ($status === 'resolved' && !empty($_POST['clear_cookies'])) {
    include_once __DIR__ . '/../include/config.php';
    include_once __DIR__ . '/../include/readcfg.php';
    include_once __DIR__ . '/../lib/routeros_api.class.php';
    if (!empty($session) && !empty($data[$session])) {
        $cfg = $data[$session];
        $iphost     = $cfg[0];
        $userhost   = $cfg[1];
        $passwdhost = $cfg[2];
        $API = new RouterosAPI();
        if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
            $cookies = $API->comm('/ip/hotspot/cookie/print', array('?user' => $user));
            if (is_array($cookies)) {
                foreach ($cookies as $c) {
                    if (!empty($c['.id'])) {
                        $API->comm('/ip/hotspot/cookie/remove', array('.id' => $c['.id']));
                    }
                }
            }
            // Force-logout active sessions
            $active = $API->comm('/ip/hotspot/active/print', array('?user' => $user));
            if (is_array($active)) {
                foreach ($active as $a) {
                    if (!empty($a['.id'])) {
                        $API->comm('/ip/hotspot/active/remove', array('.id' => $a['.id']));
                    }
                }
            }
            $API->disconnect();
        }
    }
}

echo 'ok';
