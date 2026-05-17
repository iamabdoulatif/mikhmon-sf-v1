<?php
require_once __DIR__ . '/../include/mikhmon_compat.php';

$onLogin = mikhmon_build_on_login_script('rem', '1000', '2h30m', '1200', 'Enable', '', '');
$validity = mikhmon_profile_validity_from_on_login($onLogin);
if ($validity !== '2h30m') {
  fwrite(STDERR, 'expected profile validity 2h30m, got ' . $validity . PHP_EOL);
  exit(1);
}

$cases = array(
  '1d' => '1d',
  '2h30m' => '2h30m',
  '00:05:00' => '00:05:00',
  ' 15m ' => '15m',
);
foreach ($cases as $input => $expected) {
  $actual = mikhmon_normalize_routeros_duration($input);
  if ($actual !== $expected) {
    fwrite(STDERR, 'duration normalization failed for ' . $input . ': ' . $actual . PHP_EOL);
    exit(1);
  }
}

$badDuration = mikhmon_normalize_routeros_duration('1d; /system reset-configuration');
if ($badDuration !== '') {
  fwrite(STDERR, 'unsafe duration must be rejected' . PHP_EOL);
  exit(1);
}

$scheduler = mikhmon_ip_binding_scheduler_name('AA:BB:CC:DD:EE:FF');
if ($scheduler !== 'mikhmon-ipbind-AA-BB-CC-DD-EE-FF') {
  fwrite(STDERR, 'unexpected scheduler name: ' . $scheduler . PHP_EOL);
  exit(1);
}

$script = mikhmon_build_ip_binding_expire_script('AA:BB:CC:DD:EE:FF', '10.10.0.44', $scheduler);
$checks = array(
  'removes matching ip binding' => '/ip hotspot ip-binding remove [find where mac-address="AA:BB:CC:DD:EE:FF" and address="10.10.0.44"]',
  'removes matching active session' => '/ip hotspot active remove [find where mac-address="AA:BB:CC:DD:EE:FF"]',
  'removes own scheduler' => '/system scheduler remove [find where name="mikhmon-ipbind-AA-BB-CC-DD-EE-FF" and comment="mikhmon-ipbinding-expire"]',
);
foreach ($checks as $label => $needle) {
  if (strpos($script, $needle) === false) {
    fwrite(STDERR, $label . ' missing from scheduler script' . PHP_EOL);
    exit(1);
  }
}

$error = mikhmon_routeros_response_error(array('!trap' => array(array('message' => 'invalid address'))));
if ($error !== 'invalid address') {
  fwrite(STDERR, 'RouterOS trap message was not detected' . PHP_EOL);
  exit(1);
}

echo "mikhmon_ip_binding_duration_test passed\n";
