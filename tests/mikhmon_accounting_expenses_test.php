<?php
require_once __DIR__ . '/../include/mikhmon_compat.php';
require_once __DIR__ . '/../include/accounting_expenses.php';

$expenses = array(
  mikhmon_accounting_expense_record('ALB-TECH', '2026-05-01', 'Facture CIE', 'Facture avril', '1500'),
  mikhmon_accounting_expense_record('ALB-TECH', '2026-05-02', 'Facture connexion', 'Fibre', '2 000'),
  mikhmon_accounting_expense_record('OTHER', '2026-05-02', 'Achat de materiel', 'Cable', '9999'),
  mikhmon_accounting_expense_record('ALB-TECH', '2026-05-04', 'Achat de materiel', 'Routeur', '5000'),
);

$periodExpenses = mikhmon_accounting_expenses_for_period($expenses, 'ALB-TECH', '2026-05-01', '2026-05-03');
if (count($periodExpenses) !== 2) {
  fwrite(STDERR, 'period expenses must include only matching session and dates' . PHP_EOL);
  exit(1);
}

$total = mikhmon_accounting_expenses_total($periodExpenses);
if ($total !== 3500.0) {
  fwrite(STDERR, 'period expenses total expected 3500 got ' . $total . PHP_EOL);
  exit(1);
}

$net = mikhmon_accounting_net_after_expenses(array('net' => 4050), $periodExpenses);
if ($net !== 550.0) {
  fwrite(STDERR, 'net after expenses expected 550 got ' . $net . PHP_EOL);
  exit(1);
}

$types = mikhmon_accounting_expense_types();
foreach (array('Facture CIE', 'Facture connexion', 'Achat de materiel', 'Autre') as $type) {
  if (!in_array($type, $types, true)) {
    fwrite(STDERR, 'missing expense type ' . $type . PHP_EOL);
    exit(1);
  }
}

$managerPage = file_get_contents(__DIR__ . '/../manager.php');
if (strpos($managerPage, 'Dépenses du gérant') === false
    || strpos($managerPage, 'expense_date') === false
    || strpos($managerPage, 'Facture CIE') === false
    || strpos($managerPage, 'Net après dépenses') === false) {
  fwrite(STDERR, 'manager accounting page must expose dated manager expenses and net after expenses' . PHP_EOL);
  exit(1);
}

$responsive = file_get_contents(__DIR__ . '/../css/mikhmon-responsive.css');
if (strpos($responsive, '.manager-portal .mgr-expense-form') === false
    || strpos($responsive, '.manager-portal .mgr-expense-list') === false) {
  fwrite(STDERR, 'manager expenses section must have responsive CSS hooks' . PHP_EOL);
  exit(1);
}

echo "mikhmon_accounting_expenses_test passed\n";
