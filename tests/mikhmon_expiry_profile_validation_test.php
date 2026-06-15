<?php
require_once __DIR__ . '/../include/mikhmon_compat.php';

if (function_exists('mikhmon_validate_hotspot_profile_expiry')) {
  $valid = mikhmon_validate_hotspot_profile_expiry('rem', ' 1H ');
  if ($valid['ok'] !== true || $valid['expmode'] !== 'rem' || $valid['validity'] !== '1h') {
    fwrite(STDERR, 'valid expiry profile input was not normalized' . PHP_EOL);
    exit(1);
  }

  $none = mikhmon_validate_hotspot_profile_expiry('0', '');
  if ($none['ok'] !== true || $none['validity'] !== '') {
    fwrite(STDERR, 'non-expiring profile should not require validity' . PHP_EOL);
    exit(1);
  }

  $badMode = mikhmon_validate_hotspot_profile_expiry('bad', '1h');
  if ($badMode['ok'] !== false) {
    fwrite(STDERR, 'invalid expiry mode must be rejected' . PHP_EOL);
    exit(1);
  }

  $badDuration = mikhmon_validate_hotspot_profile_expiry('rem', '1d; /system reset-configuration');
  if ($badDuration['ok'] !== false) {
    fwrite(STDERR, 'unsafe profile validity must be rejected' . PHP_EOL);
    exit(1);
  }
} else {
  fwrite(STDERR, 'mikhmon_validate_hotspot_profile_expiry missing' . PHP_EOL);
  exit(1);
}

if (function_exists('mikhmon_expire_action_for_mode')) {
  if (mikhmon_expire_action_for_mode('remc') !== 'remove') {
    fwrite(STDERR, 'remc should remove expired users' . PHP_EOL);
    exit(1);
  }
  if (mikhmon_expire_action_for_mode('ntfc') !== 'set limit-uptime=1s') {
    fwrite(STDERR, 'ntfc should mark expired users by uptime' . PHP_EOL);
    exit(1);
  }
  if (mikhmon_expire_action_for_mode('0') !== '') {
    fwrite(STDERR, 'non-expiring mode should not have an expire action' . PHP_EOL);
    exit(1);
  }
} else {
  fwrite(STDERR, 'mikhmon_expire_action_for_mode missing' . PHP_EOL);
  exit(1);
}

$profileAdd = file_get_contents(__DIR__ . '/../hotspot/adduserprofile.php');
$profileEdit = file_get_contents(__DIR__ . '/../hotspot/userprofilebyname.php');
if (strpos($profileAdd, '$monid =') === false) {
  fwrite(STDERR, 'add profile must initialize scheduler id before using it' . PHP_EOL);
  exit(1);
}
if (strpos($profileAdd, 'graceperiod') !== false || strpos($profileEdit, 'graceperiod') !== false || strpos($profileEdit, '$getgracep') !== false) {
  fwrite(STDERR, 'unused grace period fields must not remain in profile expiration forms' . PHP_EOL);
  exit(1);
}

$expiredCases = array(
  array('may/01/2026 10:00:00', '2026-05-01', '10:00:00', true),
  array('may/01/2026 10:00:30', '2026-05-01', '10:00:05', false),
  array('2026-05-01 09:59:59', '2026-05-01', '10:00:00', true),
  array('2026-05-01 10:00:01', '2026-05-01', '10:00:00', false),
  array('ticket batch', '2026-05-01', '10:00:00', false),
);
foreach ($expiredCases as $case) {
  $actual = mikhmon_expiry_comment_is_expired($case[0], $case[1], $case[2]);
  if ($actual !== $case[3]) {
    fwrite(STDERR, 'expiry comment check failed for ' . $case[0] . PHP_EOL);
    exit(1);
  }
}

echo "mikhmon_expiry_profile_validation_test passed\n";
