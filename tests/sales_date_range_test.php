<?php
// Rapport de vente : filtre par intervalle de dates. Lancer : php -d zend.assertions=1 -d assert.exception=1 tests/sales_date_range_test.php
function decrypt($x){return $x;}
include __DIR__ . '/../include/mikhmon_compat.php';
$r = mikhmon_sale_range('2026-11-30', '2026-11-21');
assert($r === array('2026-11-21', '2026-11-30'));
assert(mikhmon_sale_range('nov', '2026-11-30') === false);
assert(mikhmon_sale_range_owners('2026-11-21', '2027-01-05') === array('nov2026', 'dec2026', 'jan2027'));
assert(mikhmon_sale_ymd('nov/01/2026') === '20261101');
assert(mikhmon_sale_ymd('2026-11-25') === '20261125');
$rows = array(
  array('name' => 'nov/20/2026-|-10:00-|-a-|-200'),
  array('name' => 'nov/21/2026-|-10:00-|-b-|-200'),
  array('name' => '2026-11-30-|-10:00-|-c-|-700'),
  array('name' => 'dec/01/2026-|-10:00-|-d-|-200'),
);
$f = mikhmon_filter_sales_range($rows, '2026-11-21', '2026-11-30');
assert(count($f) === 2 && strpos($f[0]['name'], 'nov/21') === 0 && strpos($f[1]['name'], '2026-11-30') === 0);
echo "OK\n";
