<?php
require_once __DIR__ . '/../lib/routeros_api.class.php';

$api = new RouterosAPI();
$onLogin = ':put (",remc,10,5m,10,,Enable,"); {:local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment]; :if ($comment = "") do={ :put "ok"; }}';
$response = array(
    '!re',
    '=name=05-MINS',
    '=on-login=' . $onLogin,
    '=comment=Monitor Profile 05-MINS',
    '!done',
);

$parsed = $api->parseResponse($response);

if (!isset($parsed[0]['on-login']) || $parsed[0]['on-login'] !== $onLogin) {
    fwrite(STDERR, "RouterOS API parser truncated a value containing equals signs\n");
    exit(1);
}

if ($parsed[0]['name'] !== '05-MINS' || $parsed[0]['comment'] !== 'Monitor Profile 05-MINS') {
    fwrite(STDERR, "RouterOS API parser broke ordinary key/value parsing\n");
    exit(1);
}

echo "routeros_api_parse_equals_test passed\n";
