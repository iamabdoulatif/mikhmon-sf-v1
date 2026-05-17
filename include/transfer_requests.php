<?php
/*
 * transfer_requests.php — Demandes de transfert de stock entre vendeurs
 * Stockage : JSONL (une ligne JSON par demande)
 */

define('TRANSFER_REQ_FILE', __DIR__ . '/../logs/transfer_requests.jsonl');

function tr_ensure_dir() {
    $dir = dirname(TRANSFER_REQ_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        @file_put_contents($dir . '/index.php', "<?php header('Location:../');");
    }
}

function tr_generate_id() {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(8));
    }
    return sprintf('%08x%08x', mt_rand(0, 0xffffffff), mt_rand(0, 0xffffffff));
}

function tr_read_all() {
    if (!file_exists(TRANSFER_REQ_FILE)) return [];
    $lines = @file(TRANSFER_REQ_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];
    $result = [];
    foreach ($lines as $line) {
        $d = json_decode($line, true);
        if ($d) $result[] = $d;
    }
    return $result;
}

function tr_write_all(array $records) {
    tr_ensure_dir();
    $content = '';
    foreach ($records as $r) {
        $content .= json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
    @file_put_contents(TRANSFER_REQ_FILE, $content, LOCK_EX);
}

/**
 * Créer une nouvelle demande de transfert.
 * @return string  L'identifiant unique de la demande
 */
function tr_create($from_key, $from_name, $to_key, $to_name, $profile, $qty) {
    tr_ensure_dir();
    $entry = [
        'id'          => tr_generate_id(),
        'ts'          => date('Y-m-d H:i:s'),
        'from_key'    => $from_key,
        'from_name'   => $from_name,
        'to_key'      => $to_key,
        'to_name'     => $to_name,
        'profile'     => $profile,
        'qty'         => (int)$qty,
        'status'      => 'pending',
        'response_ts' => '',
    ];
    @file_put_contents(TRANSFER_REQ_FILE, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    return $entry['id'];
}

/**
 * Retourne les demandes en attente destinées à $to_key (notifications).
 */
function tr_get_pending_for($to_key) {
    $all = tr_read_all();
    return array_values(array_filter($all, function ($r) use ($to_key) {
        return $r['to_key'] === $to_key && $r['status'] === 'pending';
    }));
}

/**
 * Retourne les N dernières demandes envoyées par $from_key.
 */
function tr_get_sent_by($from_key, $limit = 20) {
    $all    = array_reverse(tr_read_all());
    $result = [];
    foreach ($all as $r) {
        if ($r['from_key'] === $from_key) {
            $result[] = $r;
            if (count($result) >= $limit) break;
        }
    }
    return $result;
}

/**
 * Retourne une demande par son identifiant, ou null.
 */
function tr_get_by_id($id) {
    foreach (tr_read_all() as $r) {
        if ($r['id'] === $id) return $r;
    }
    return null;
}

/**
 * Mettre à jour le statut d'une demande (accepted | declined).
 * @return bool  true si la mise à jour a été effectuée
 */
function tr_respond($id, $status, $responder_key) {
    $all   = tr_read_all();
    $found = false;
    foreach ($all as &$r) {
        if ($r['id'] === $id && $r['to_key'] === $responder_key && $r['status'] === 'pending') {
            $r['status']      = $status;
            $r['response_ts'] = date('Y-m-d H:i:s');
            $found            = true;
            break;
        }
    }
    unset($r);
    if ($found) tr_write_all($all);
    return $found;
}
