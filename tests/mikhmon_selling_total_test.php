<?php
require_once __DIR__ . '/../include/mikhmon_compat.php';

// Simulate selling.php totalresume/dataresume accumulation with formatted prices
$scripts = array(
    array('name' => 'may/09/2026-|-08:00:00-|-u1-|-100-|-10.0.0.2-|-AA-|-5h-|-05-HEURES-|-batch'),
    array('name' => 'may/09/2026-|-09:00:00-|-u2-|-1 500-|-10.0.0.3-|-BB-|-1d-|-01-JOUR-|-batch'),
    array('name' => 'may/10/2026-|-10:00:00-|-u3-|-1.500-|-10.0.0.4-|-CC-|-1w-|-VIP-|-batch'),
    array('name' => 'may/10/2026-|-11:00:00-|-u4-|-200-|-10.0.0.5-|-DD-|-2h-|-VIP-|-batch'),
);

$totalresume = 0.0;
$dataresume = '';
foreach ($scripts as $script) {
    $getname = explode('-|-', $script['name']);
    $_parsedPrice = mikhmon_parse_money_amount($getname[3]);
    $dataresume .= $getname[0] . round($_parsedPrice);
    $totalresume += $_parsedPrice;
}

// total: 100 + 1500 + 1500 + 200 = 3300
if (abs($totalresume - 3300.0) > 0.001) {
    fwrite(STDERR, 'totalresume expected 3300 got ' . $totalresume . PHP_EOL);
    exit(1);
}

// dataresume day split for may/09/2026: 100 + 1500 = 1600
$evalue = explode('may/09/2026', $dataresume);
$dayTotal = 0;
foreach ($evalue as $chunk) {
    $dayTotal += (int) $chunk;
}
if ($dayTotal !== 1600) {
    fwrite(STDERR, 'resume day total for may/09/2026 expected 1600 got ' . $dayTotal . PHP_EOL);
    exit(1);
}

// dataresume day split for may/10/2026: 1500 + 200 = 1700
$evalue2 = explode('may/10/2026', $dataresume);
$dayTotal2 = 0;
foreach ($evalue2 as $chunk) {
    $dayTotal2 += (int) $chunk;
}
if ($dayTotal2 !== 1700) {
    fwrite(STDERR, 'resume day total for may/10/2026 expected 1700 got ' . $dayTotal2 . PHP_EOL);
    exit(1);
}

// XOF format
$formatted = mikhmon_format_money_amount($totalresume, 'XOF', array('indo' => array()));
if ($formatted !== 'XOF 3 300') {
    fwrite(STDERR, 'XOF format expected "XOF 3 300" got "' . $formatted . '"' . PHP_EOL);
    exit(1);
}

echo "mikhmon_selling_total_test passed\n";
