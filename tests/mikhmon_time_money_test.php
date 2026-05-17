<?php
require_once __DIR__ . '/../include/mikhmon_compat.php';

$clock = array(
  'date' => '2026-05-17',
  'time' => '16:40:24',
  'time-zone-name' => 'Africa/Abidjan',
);

if (mikhmon_router_clock_day_key($clock, 'UTC') !== 'may/17/2026') {
  fwrite(STDERR, 'RouterOS ISO clock date was not normalized to the Mikhmon day key' . PHP_EOL);
  exit(1);
}

if (mikhmon_router_clock_display($clock, 'UTC') !== '2026-05-17 16:40:24') {
  fwrite(STDERR, 'RouterOS ISO clock display is incorrect' . PHP_EOL);
  exit(1);
}

$legacyClock = array(
  'date' => 'may/07/2026',
  'time' => '08:09:10',
);

if (mikhmon_router_clock_display($legacyClock, 'UTC') !== '2026-05-07 08:09:10') {
  fwrite(STDERR, 'RouterOS legacy clock display is incorrect' . PHP_EOL);
  exit(1);
}

$cekindo = array('indo' => array('Rp', 'IDR'));
if (mikhmon_format_money_amount(12500, 'XOF', $cekindo) !== 'XOF 12 500') {
  fwrite(STDERR, 'XOF dashboard money must be displayed without decimals and with readable grouping' . PHP_EOL);
  exit(1);
}

if (mikhmon_format_money_amount(12500, 'Rp', $cekindo) !== 'Rp 12.500') {
  fwrite(STDERR, 'Indonesian dashboard money must keep dot grouping' . PHP_EOL);
  exit(1);
}

if (mikhmon_parse_money_amount('1 500') !== 1500.0 || mikhmon_parse_money_amount('1.500') !== 1500.0) {
  fwrite(STDERR, 'Money parser must handle common thousands separators' . PHP_EOL);
  exit(1);
}

echo "mikhmon_time_money_test passed\n";
