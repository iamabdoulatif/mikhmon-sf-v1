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
$sellers = array('alpha' => array('name' => 'Alpha'), 'beta' => array('name' => 'Beta'));
$sales = array(
  array('name' => 'nov/12/2026-|-08:00-|-1j1-|-200-|-ip-|-mac-|-1d-|-01-JOUR-|-vc-813-10.12.26-alpha'),
  array('name' => 'nov/12/2026-|-09:00-|-1j2-|-500-|-ip-|-mac-|-4d-|-04-JOURS-|-vc-307-10.30.26-beta'),
  array('name' => 'nov/13/2026-|-10:00-|-1j3-|-200-|-ip-|-mac-|-1d-|-01-JOUR-|-vc-813-10.12.26-alpha'),
  array('name' => 'nov/13/2026-|-11:00-|-1j4-|-700-|-ip-|-mac-|-7d-|-01-SEMAINE-|-vc-226-10.25.26'),
);
$a = mikhmon_filter_sales_seller($sales, 'alpha', $sellers);
assert(count($a) === 2 && mikhmon_sales_total($a) == 400.0);
assert(count(mikhmon_filter_sales_seller($sales, 'beta', $sellers)) === 1);
assert(mikhmon_sales_total($sales) == 1600.0);
$b = mikhmon_seller_breakdown($sales, $sellers);
assert(count($b) === 3 && $b[0]['key'] === '' && $b[0]['commission'] == 70.0);
assert($b[1]['key'] === 'beta' && $b[1]['share'] == 31.3 && $b[1]['commission'] == 50.0 && $b[1]['net'] == 450.0);
assert($b[2]['key'] === 'alpha' && $b[2]['count'] === 2 && $b[2]['commission'] == 40.0);
$saas = array_merge($sales, array(array('name' => 'nov/13/2026-|-12:00-|-ab12cd-|-1000-|-ip-|-mac-|-30d-|-01-MOIS-|-')));
$b2 = mikhmon_seller_breakdown($saas, $sellers);
assert(count($b2) === 3 && $b2[0]['total'] == 700.0, 'vente SaaS exclue du récapitulatif vendeurs');
$st = mikhmon_saas_sales_total($saas);
assert($st['count'] === 1 && $st['total'] == 1000.0);
echo "OK\n";
