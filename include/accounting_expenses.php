<?php

require_once __DIR__ . '/mikhmon_compat.php';

if (!function_exists('mikhmon_accounting_expenses_file')) {
  function mikhmon_accounting_expenses_file()
  {
    return dirname(__DIR__) . '/logs/accounting_expenses.json';
  }

  function mikhmon_accounting_expense_types()
  {
    return array('Facture CIE', 'Facture connexion', 'Achat de materiel', 'Autre');
  }

  function mikhmon_accounting_expense_record($session, $date, $type, $label, $amount)
  {
    $session = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $session);
    $date = mikhmon_accounting_iso_date($date, date('Y-m-d'));
    $types = mikhmon_accounting_expense_types();
    $type = trim((string) $type);
    if (!in_array($type, $types, true)) {
      $type = 'Autre';
    }
    $label = trim(strip_tags((string) $label));
    if ($label === '') {
      $label = $type;
    }
    $amount = max(0, mikhmon_parse_money_amount($amount));

    return array(
      'id' => sha1($session . '|' . $date . '|' . $type . '|' . $label . '|' . $amount . '|' . microtime(true)),
      'session' => $session,
      'date' => $date,
      'type' => $type,
      'label' => $label,
      'amount' => $amount,
      'created_at' => date('Y-m-d H:i:s'),
    );
  }

  function mikhmon_accounting_expenses_load($file = '')
  {
    $file = $file === '' ? mikhmon_accounting_expenses_file() : $file;
    if (!is_file($file)) {
      return array();
    }
    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
      return array();
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
  }

  function mikhmon_accounting_expenses_save($expenses, $file = '')
  {
    $file = $file === '' ? mikhmon_accounting_expenses_file() : $file;
    $dir = dirname($file);
    if (!is_dir($dir)) {
      mkdir($dir, 0775, true);
    }
    if (!is_file($dir . '/.htaccess')) {
      file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    if (!is_file($dir . '/index.php')) {
      file_put_contents($dir . '/index.php', "<?php header('Location:../');");
    }

    return file_put_contents($file, json_encode(array_values($expenses), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
  }

  function mikhmon_accounting_expenses_for_period($expenses, $session, $fromIso, $toIso)
  {
    $session = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $session);
    $fromIso = mikhmon_accounting_iso_date($fromIso);
    $toIso = mikhmon_accounting_iso_date($toIso, $fromIso);
    if ($fromIso === '' || $toIso === '') {
      return array();
    }
    if ($fromIso > $toIso) {
      $tmp = $fromIso;
      $fromIso = $toIso;
      $toIso = $tmp;
    }

    $matches = array();
    foreach ($expenses as $expense) {
      if (!is_array($expense)) {
        continue;
      }
      $expenseSession = isset($expense['session']) ? (string) $expense['session'] : '';
      $expenseDate = mikhmon_accounting_iso_date(isset($expense['date']) ? $expense['date'] : '');
      if ($expenseSession !== $session || $expenseDate === '' || $expenseDate < $fromIso || $expenseDate > $toIso) {
        continue;
      }
      $expense['amount'] = isset($expense['amount']) ? (float) $expense['amount'] : 0.0;
      $matches[] = $expense;
    }

    usort($matches, function ($a, $b) {
      return strcmp($a['date'] . ($a['created_at'] ?? ''), $b['date'] . ($b['created_at'] ?? ''));
    });

    return $matches;
  }

  function mikhmon_accounting_expenses_total($expenses)
  {
    $total = 0.0;
    foreach ($expenses as $expense) {
      if (is_array($expense) && isset($expense['amount'])) {
        $total += (float) $expense['amount'];
      }
    }
    return $total;
  }

  function mikhmon_accounting_net_after_expenses($total, $expenses)
  {
    $net = is_array($total) && isset($total['net']) ? (float) $total['net'] : 0.0;
    return $net - mikhmon_accounting_expenses_total($expenses);
  }
}
