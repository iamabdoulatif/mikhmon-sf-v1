<?php
/*
 * transfer_log.php — Journal d'audit des transferts de stock
 * Format : une ligne JSON par transfert (JSONL)
 */

define('TRANSFER_LOG_FILE', __DIR__ . '/../logs/transfers.jsonl');

/**
 * Retourne toutes les lignes brutes du journal.
 * @return array<int,string>
 */
function mikhmon_transfer_log_lines() {
    if (!file_exists(TRANSFER_LOG_FILE)) {
        return [];
    }
    $lines = @file(TRANSFER_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return is_array($lines) ? $lines : [];
}

/**
 * Enregistre un transfert dans le journal.
 */
function log_transfer($from_key, $from_name, $to_key, $to_name, $profile, $qty, $by_role, $by_user) {
    $dir = dirname(TRANSFER_LOG_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
        // Protéger le dossier
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        @file_put_contents($dir . '/index.php', "<?php header('Location:../');");
    }
    $entry = json_encode([
        'ts'       => date('Y-m-d H:i:s'),
        'from_key' => $from_key,
        'from'     => $from_name,
        'to_key'   => $to_key,
        'to'       => $to_name,
        'profile'  => $profile,
        'qty'      => (int)$qty,
        'by_role'  => $by_role,
        'by_user'  => $by_user,
    ], JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(TRANSFER_LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Lit les N derniers transferts (ordre : plus récent en premier).
 * @param int    $limit   Nombre max d'entrées
 * @param string $filter_key  Filtrer par from_key ou to_key (optionnel)
 */
function get_transfer_logs($limit = 100, $filter_key = '') {
    $lines = mikhmon_transfer_log_lines();
    if (!$lines) return [];
    $result = [];
    foreach (array_reverse($lines, true) as $row => $line) {
        if (count($result) >= $limit) break;
        $d = json_decode($line, true);
        if (!$d) continue;
        if ($filter_key !== ''
            && (!isset($d['from_key']) || $d['from_key'] !== $filter_key)
            && (!isset($d['to_key']) || $d['to_key'] !== $filter_key)) {
            continue;
        }
        $d['_row'] = (int)$row;
        $result[] = $d;
    }
    return $result;
}

/**
 * Supprime une entrée du journal selon sa ligne d'origine.
 * @param int $rowIndex
 * @return bool
 */
function delete_transfer_log_entry($rowIndex) {
    $rowIndex = (int)$rowIndex;
    $lines = mikhmon_transfer_log_lines();
    if (!isset($lines[$rowIndex])) {
        return false;
    }

    unset($lines[$rowIndex]);
    $payload = empty($lines) ? '' : implode("\n", $lines) . "\n";
    return @file_put_contents(TRANSFER_LOG_FILE, $payload, LOCK_EX) !== false;
}

/**
 * Vide complètement le journal des transferts.
 * @return bool
 */
function clear_transfer_logs() {
    if (!file_exists(TRANSFER_LOG_FILE)) {
        return true;
    }
    return @file_put_contents(TRANSFER_LOG_FILE, '', LOCK_EX) !== false;
}
