<?php
/*
 * Gestion des vendeurs - MIKHMON
 * Accessible uniquement par l'administrateur.
 */
session_start();
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
    header("Location:./admin.php?id=login");
    exit;
}

include_once('./lib/routeros_api.class.php');
include_once('./lib/formatbytesbites.php');
include('./include/sellers_config.php');
include('./include/managers_config.php');
include('./include/config.php');
include_once('./include/csrf.php');
include_once('./include/transfer_log.php');
include_once('./include/seller_ticket_helper.php');

$session = isset($_GET['session']) ? $_GET['session'] : '';
include('./include/readcfg.php');
if (empty($mikhmon_router_session_valid)) {
    ob_end_clean();
    $missingSession = rawurlencode((string)$session);
    header("Location:./admin.php?id=sessions&missing-session=" . $missingSession);
    exit;
}

$sellers_file  = './include/sellers_config.php';
$managers_file = './include/managers_config.php';
$msg           = '';
$msg_mgr       = '';
$transfer_msg   = '';
$transfer_error = '';
$transfer_log_msg   = '';
$transfer_log_error = '';
$force_active_tab   = '';

// ── Stock de tous les vendeurs (tickets non utilisés) ────────────────────────
$allSellerStock  = array(); // ['sellerKey']['profile'] = count
$allStockUsers   = array(); // users non utilisés assignés à un vendeur
$globalStock     = array(); // ['profile'] = count  (non assignés)
$globalStockIds  = array(); // ['profile'] = ['.id', ...]  (pour distribution)

if (!empty($iphost)) {
    $API_ms = new RouterosAPI();
    $API_ms->debug = false;
    if ($API_ms->connect($iphost, $userhost, decrypt($passwdhost))) {
        $unusedAll = $API_ms->comm("/ip/hotspot/user/print", array("?uptime" => "0s"));
        if (is_array($unusedAll)) {
            foreach ($sellers_data as $sk => $sd) {
                $allSellerStock[$sk] = array();
            }
            foreach ($unusedAll as $u) {
                $cmt  = strtolower(trim(isset($u['comment']) ? $u['comment'] : ''));
                $prof = isset($u['profile']) ? $u['profile'] : '(unknown)';
                $assigned = false;
                foreach ($sellers_data as $sk => $sd) {
                    $sfx = '-' . strtolower($sk);
                    if ($cmt === strtolower($sk) || substr($cmt, -strlen($sfx)) === $sfx) {
                        if (!isset($allSellerStock[$sk][$prof])) $allSellerStock[$sk][$prof] = 0;
                        $allSellerStock[$sk][$prof]++;
                        $allStockUsers[] = $u;
                        $assigned = true;
                        break;
                    }
                }
                // Ticket non assigné → stock global
                if (!$assigned && isset($u['.id'])) {
                    if (!isset($globalStock[$prof]))    $globalStock[$prof]    = 0;
                    if (!isset($globalStockIds[$prof])) $globalStockIds[$prof] = array();
                    $globalStock[$prof]++;
                    $globalStockIds[$prof][] = $u['.id'];
                }
            }
        }
    }
}

// ── Transfert admin ──────────────────────────────────────────────────────────
if (isset($_POST['admin_transfer']) && !empty($sellers_data)) {
    csrf_guard();
    $src    = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['src_seller']));
    $dst    = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['dst_seller']));
    $tprof  = trim($_POST['transfer_profile']);
    $tqty   = max(1, (int)$_POST['transfer_qty']);

    if (!isset($sellers_data[$src]) || !isset($sellers_data[$dst]) || $src === $dst) {
        $transfer_error = isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Select valid source and destination vendors.';
    } elseif ($tprof === '') {
        $transfer_error = isset($_transfer_select_profile) ? $_transfer_select_profile : 'Select a profile.';
    } elseif (!isset($allSellerStock[$src][$tprof]) || $allSellerStock[$src][$tprof] < $tqty) {
        $transfer_error = isset($_transfer_insufficient) ? $_transfer_insufficient : 'Insufficient stock.';
    } else {
        if (!isset($API_ms)) { $API_ms = new RouterosAPI(); $API_ms->debug = false; $API_ms->connect($iphost, $userhost, decrypt($passwdhost)); }
        $done = 0;
        $srcKey = strtolower($src);
        $sfxKey = '-' . $srcKey;
        foreach ($allStockUsers as $u) {
            if ($done >= $tqty) break;
            if ((isset($u['profile']) && $u['profile'] === $tprof)) {
                $cmt = strtolower(trim(isset($u['comment']) ? $u['comment'] : ''));
                if ($cmt === $srcKey || substr($cmt, -strlen($sfxKey)) === $sfxKey) {
                    $API_ms->comm("/ip/hotspot/user/set", array(
                        ".id" => $u['.id'],
                        "comment" => mikhmon_comment_assign_seller(isset($u['comment']) ? $u['comment'] : '', $dst, $sellers_data)
                    ));
                    $done++;
                }
            }
        }
        $transfer_msg = $done . ' ' . (isset($_transfer_done) ? $_transfer_done : 'ticket(s) transferred to') . ' <b>' . htmlspecialchars($sellers_data[$dst]['name']) . '</b>';
        if ($done > 0) {
            log_transfer(
                $src, $sellers_data[$src]['name'],
                $dst, $sellers_data[$dst]['name'],
                $tprof, $done,
                'admin', $_SESSION['mikhmon'] ?? 'admin'
            );
        }
        // Refresh stock counts
        if ($done > 0) {
            $allSellerStock[$src][$tprof] -= $done;
            if ($allSellerStock[$src][$tprof] <= 0) unset($allSellerStock[$src][$tprof]);
            if (!isset($allSellerStock[$dst][$tprof])) $allSellerStock[$dst][$tprof] = 0;
            $allSellerStock[$dst][$tprof] += $done;
        }
    }
}

// ── Distribution globale (stock non assigné → vendeurs) ─────────────────────
$bulk_msg = '';
if (isset($_POST['bulk_distribute']) && !empty($sellers_data)) {
    csrf_guard();
    $dist_profile = trim(isset($_POST['dist_profile']) ? $_POST['dist_profile'] : '');
    $vendor_qty   = (isset($_POST['vendor_qty']) && is_array($_POST['vendor_qty'])) ? $_POST['vendor_qty'] : array();

    if ($dist_profile !== '' && !empty($vendor_qty)) {
        if (!isset($API_ms)) {
            $API_ms = new RouterosAPI(); $API_ms->debug = false;
            $API_ms->connect($iphost, $userhost, decrypt($passwdhost));
        }
        // Recalculer les IDs non assignés pour ce profil (données fraîches)
        $freshUnused = $API_ms->comm("/ip/hotspot/user/print", array("?uptime" => "0s", "?profile" => $dist_profile));
        $freshUsers = array();
        if (is_array($freshUnused)) {
            foreach ($freshUnused as $u) {
                if (!isset($u['.id'])) continue;
                $cmt = strtolower(trim(isset($u['comment']) ? $u['comment'] : ''));
                $assigned = false;
                foreach ($sellers_data as $sk => $sd) {
                    $sfx = '-' . strtolower($sk);
                    if ($cmt === strtolower($sk) || substr($cmt, -strlen($sfx)) === $sfx) {
                        $assigned = true; break;
                    }
                }
                if (!$assigned) $freshUsers[] = $u;
            }
        }

        $pointer = 0;
        $ok_parts  = array();
        $err_parts = array();

        foreach ($vendor_qty as $vk => $qty) {
            $vk  = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$vk);
            $qty = max(0, (int)$qty);
            if ($qty <= 0 || !isset($sellers_data[$vk])) continue;

            $done = 0;
            while ($done < $qty && $pointer < count($freshUsers)) {
                $targetUser = $freshUsers[$pointer];
                $API_ms->comm("/ip/hotspot/user/set", array(
                    ".id" => $targetUser['.id'],
                    "comment" => mikhmon_comment_assign_seller(isset($targetUser['comment']) ? $targetUser['comment'] : '', $vk, $sellers_data)
                ));
                $pointer++; $done++;
            }
            if ($done > 0) {
                log_transfer('(global)', 'Stock global', $vk, $sellers_data[$vk]['name'], $dist_profile, $done, 'admin', isset($_SESSION['mikhmon']) ? $_SESSION['mikhmon'] : 'admin');
                if (!isset($allSellerStock[$vk][$dist_profile])) $allSellerStock[$vk][$dist_profile] = 0;
                $allSellerStock[$vk][$dist_profile] += $done;
                $ok_parts[] = '<b>' . $done . '</b> → ' . htmlspecialchars($sellers_data[$vk]['name']);
            }
            if ($done < $qty) {
                $err_parts[] = isset($_transfer_insufficient_for)
                    ? sprintf($_transfer_insufficient_for, htmlspecialchars($sellers_data[$vk]['name']), $qty - $done)
                    : 'Insufficient stock for <b>' . htmlspecialchars($sellers_data[$vk]['name']) . '</b> (missing ' . ($qty - $done) . ')';
            }
        }
        // Mettre à jour le stock global en mémoire
        if (!empty($ok_parts) && isset($globalStock[$dist_profile])) {
            $globalStock[$dist_profile] -= $pointer;
            if ($globalStock[$dist_profile] <= 0) unset($globalStock[$dist_profile]);
            // Retirer les IDs utilisés de globalStockIds
            if (isset($globalStockIds[$dist_profile])) {
                $globalStockIds[$dist_profile] = array_slice($globalStockIds[$dist_profile], $pointer);
            }
        }
        if (!empty($ok_parts)) {
            $bulk_msg .= '<div class="bg-success" style="padding:10px 14px;border-radius:5px;margin-bottom:8px;"><i class="fa fa-check-circle"></i> Distribution [' . htmlspecialchars($dist_profile) . '] : ' . implode(', ', $ok_parts) . '</div>';
        }
        if (!empty($err_parts)) {
            $bulk_msg .= '<div class="bg-danger" style="padding:10px 14px;border-radius:5px;margin-bottom:8px;"><i class="fa fa-ban"></i> ' . implode('<br>', $err_parts) . '</div>';
        }
    }
}

// ── Journal des transferts : suppression admin ─────────────────────────────
if (isset($_POST['delete_transfer_log']) || isset($_POST['clear_transfer_logs'])) {
    csrf_guard();
    $force_active_tab = 'transfers';

    if (isset($_POST['clear_transfer_logs'])) {
        if (clear_transfer_logs()) {
            $transfer_log_msg = '<div class="bg-warning" style="padding:8px;border-radius:5px;"><i class="fa fa-trash"></i> '
                . (isset($_transfer_log_clear_done) ? $_transfer_log_clear_done : 'Transfer log cleared.') . '</div>';
        } else {
            $transfer_log_error = isset($_transfer_log_delete_error) ? $_transfer_log_delete_error : 'Unable to update the transfer log.';
        }
    } elseif (isset($_POST['delete_transfer_log'])) {
        $logRow = isset($_POST['log_row']) ? (int)$_POST['log_row'] : -1;
        if ($logRow < 0) {
            $transfer_log_error = isset($_transfer_log_delete_error) ? $_transfer_log_delete_error : 'Unable to update the transfer log.';
        } elseif (delete_transfer_log_entry($logRow)) {
            $transfer_log_msg = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> '
                . (isset($_transfer_log_delete_done) ? $_transfer_log_delete_done : 'Transfer log entry deleted.') . '</div>';
        } else {
            $transfer_log_error = isset($_transfer_log_delete_error) ? $_transfer_log_delete_error : 'Unable to update the transfer log.';
        }
    }
}

// ── Ajouter un vendeur ───────────────────────────────────────────────────────
if (isset($_POST['add_seller']) || isset($_POST['change_pass']) || isset($_POST['add_manager']) || isset($_POST['change_manager_pass']) || isset($_GET['del_seller'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_guard();
}

function mikhmon_default_account($accounts, $usernamePrefix, $displayPrefix) {
    $next = 1;
    if (is_array($accounts)) {
        foreach (array_keys($accounts) as $accountKey) {
            if (preg_match('/^' . preg_quote($usernamePrefix, '/') . '([0-9]+)$/i', $accountKey, $matches)) {
                $next = max($next, ((int)$matches[1]) + 1);
            }
        }
    }

    $suffix = str_pad((string)$next, 2, '0', STR_PAD_LEFT);
    $username = strtolower($usernamePrefix . $suffix);

    return array(
        'username' => $username,
        'password' => $username . '@123',
        'name' => $displayPrefix . ' ' . $suffix,
    );
}
if (isset($_POST['add_seller'])) {
    $new_user    = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['new_user']));
    $new_pass    = trim($_POST['new_pass']);
    $new_name    = htmlspecialchars(trim($_POST['new_name']));
    $new_session = trim($_POST['new_session']);

    if ($new_user == '' || $new_pass == '' || $new_name == '') {
        $msg = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . $_required_fields_msg . '</div>';
    } elseif (isset($sellers_data[$new_user])) {
        $msg = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . $_seller_exists . '</div>';
    } else {
        $encrypted_pass = encrypt($new_pass);
        $line = '$sellers_data[\'' . $new_user . '\'] = array(\'password\' => \'' . $encrypted_pass . '\', \'name\' => \'' . $new_name . '\', \'session\' => \'' . $new_session . '\', \'commission\' => 10);' . "\n";
        file_put_contents($sellers_file, $line, FILE_APPEND);
        $msg = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> ' . $_seller . ' <b>' . $new_user . '</b> OK.<br><small>' . $_seller_id . ': <b>' . htmlspecialchars($new_user) . '</b> | ' . $_password . ': <b>' . htmlspecialchars($new_pass) . '</b></small></div>';
        // Recharger la config
        include($sellers_file);
    }
}

// ── Supprimer un vendeur ─────────────────────────────────────────────────────
if (isset($_GET['del_seller'])) {
    $del = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['del_seller']);
    if ($del != '') {
        $fc = file($sellers_file);
        $f  = fopen($sellers_file, 'w');
        foreach ($fc as $line) {
            if (strpos($line, '$sellers_data[\'' . $del . '\']') === false) {
                fputs($f, $line);
            }
        }
        fclose($f);
        $msg = '<div class="bg-warning" style="padding:8px;border-radius:5px;"><i class="fa fa-trash"></i> ' . $_seller . ' <b>' . htmlspecialchars($del) . '</b>.</div>';
        // Recharger
        $sellers_data = array();
        include($sellers_file);
    }
}

// ── Modifier le mot de passe d'un vendeur ────────────────────────────────────
if (isset($_POST['change_pass'])) {
    $cp_user = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['cp_user']));
    $cp_pass = trim($_POST['cp_pass']);
    if ($cp_user != '' && $cp_pass != '' && isset($sellers_data[$cp_user])) {
        $encrypted_new = encrypt($cp_pass);
        $curr_comm = isset($sellers_data[$cp_user]['commission']) ? (int)$sellers_data[$cp_user]['commission'] : 0;
        $fc = file($sellers_file);
        $f  = fopen($sellers_file, 'w');
        foreach ($fc as $line) {
            if (strpos($line, '$sellers_data[\'' . $cp_user . '\']') !== false) {
                $line = '$sellers_data[\'' . $cp_user . '\'] = array(\'password\' => \'' . $encrypted_new
                    . '\', \'name\' => \'' . $sellers_data[$cp_user]['name']
                    . '\', \'session\' => \'' . $sellers_data[$cp_user]['session']
                    . '\', \'commission\' => ' . $curr_comm . ');' . "\n";
            }
            fputs($f, $line);
        }
        fclose($f);
        $msg = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> ' . $_password . ' ' . $_seller . ' <b>' . htmlspecialchars($cp_user) . '</b> OK.</div>';
        $sellers_data[$cp_user]['password'] = $encrypted_new;
    }
}

// ── Modifier la commission d'un vendeur ──────────────────────────────────────
if (isset($_POST['set_commission'])) {
    csrf_guard();
    $sc_user = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['sc_user']));
    $sc_rate = max(0, min(100, (int)$_POST['sc_rate']));
    if ($sc_user != '' && isset($sellers_data[$sc_user])) {
        $fc = file($sellers_file);
        $f  = fopen($sellers_file, 'w');
        foreach ($fc as $line) {
            if (strpos($line, '$sellers_data[\'' . $sc_user . '\']') !== false) {
                if (preg_match("/'commission'\s*=>\s*\d+/", $line)) {
                    $line = preg_replace("/'commission'\s*=>\s*\d+/", "'commission' => " . $sc_rate, $line);
                } else {
                    $line = rtrim(rtrim($line), ');') . ", 'commission' => " . $sc_rate . ");\n";
                }
            }
            fputs($f, $line);
        }
        fclose($f);
        $msg = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> Commission <b>' . htmlspecialchars($sc_user) . '</b> → <b>' . $sc_rate . '%</b></div>';
        $sellers_data[$sc_user]['commission'] = $sc_rate;
    }
}

// ── Ajouter un gérant ────────────────────────────────────────────────────────
if (isset($_POST['add_manager'])) {
    $nmu = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['nm_user']));
    $nmp = trim($_POST['nm_pass']);
    $nmn = htmlspecialchars(trim($_POST['nm_name']));
    $nms = trim($_POST['nm_session']);
    if ($nmu == '' || $nmp == '' || $nmn == '') {
        $msg_mgr = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . $_required_fields_msg . '</div>';
    } elseif (isset($managers_data[$nmu])) {
        $msg_mgr = '<div class="bg-danger" style="padding:8px;border-radius:5px;"><i class="fa fa-ban"></i> ' . (isset($_manager_exists) ? $_manager_exists : 'Already exists.') . '</div>';
    } else {
        $ep = encrypt($nmp);
        file_put_contents($managers_file, '$managers_data[\'' . $nmu . '\'] = array(\'password\' => \'' . $ep . '\', \'name\' => \'' . $nmn . '\', \'session\' => \'' . $nms . '\');' . "\n", FILE_APPEND);
        $msg_mgr = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> ' . (isset($_manager) ? $_manager : 'Manager') . ' <b>' . $nmu . '</b> OK.<br><small>' . $_seller_id . ': <b>' . htmlspecialchars($nmu) . '</b> | ' . $_password . ': <b>' . htmlspecialchars($nmp) . '</b></small></div>';
        include($managers_file);
    }
}
// ── Supprimer un gérant ───────────────────────────────────────────────────────
if (isset($_GET['del_manager'])) {
    $dm = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['del_manager']);
    if ($dm != '') {
        $fc = file($managers_file);
        $f  = fopen($managers_file, 'w');
        foreach ($fc as $ln) {
            if (strpos($ln, '$managers_data[\'' . $dm . '\']') === false) fputs($f, $ln);
        }
        fclose($f);
        $msg_mgr = '<div class="bg-warning" style="padding:8px;border-radius:5px;"><i class="fa fa-trash"></i> ' . (isset($_manager) ? $_manager : 'Manager') . ' <b>' . htmlspecialchars($dm) . '</b>.</div>';
        $managers_data = array();
        include($managers_file);
    }
}
// ── Modifier le mot de passe d'un gérant ─────────────────────────────────────
if (isset($_POST['change_manager_pass'])) {
    $cmu = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['cmp_user']));
    $cmp = trim($_POST['cmp_pass']);
    if ($cmu != '' && $cmp != '' && isset($managers_data[$cmu])) {
        $en  = encrypt($cmp);
        $fc  = file($managers_file);
        $f   = fopen($managers_file, 'w');
        foreach ($fc as $ln) {
            if (strpos($ln, '$managers_data[\'' . $cmu . '\']') !== false) {
                $ln = '$managers_data[\'' . $cmu . '\'] = array(\'password\' => \'' . $en . '\', \'name\' => \'' . $managers_data[$cmu]['name'] . '\', \'session\' => \'' . $managers_data[$cmu]['session'] . '\');' . "\n";
            }
            fputs($f, $ln);
        }
        fclose($f);
        $msg_mgr = '<div class="bg-success" style="padding:8px;border-radius:5px;"><i class="fa fa-check"></i> ' . $_password . ' <b>' . htmlspecialchars($cmu) . '</b> OK.</div>';
        $managers_data[$cmu]['password'] = $en;
    }
}

// ── Lister les sessions disponibles ─────────────────────────────────────────
$available_sessions = array();
foreach (file('./include/config.php') as $line) {
    $sesname = explode("'", $line)[1];
    if ($sesname != '' && $sesname != 'mikhmon') {
        $available_sessions[] = $sesname;
    }
}
$available_sessions = array_unique($available_sessions);
$defaultSellerAccount = mikhmon_default_account($sellers_data, 'vendeur', 'Vendeur');
$defaultManagerAccount = mikhmon_default_account($managers_data, 'gerant', 'Gerant');
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'sellers';
if ($force_active_tab !== '') {
    $active_tab = $force_active_tab;
}
$adminDashboardUrl = $session !== '' ? './?session=' . urlencode($session) : './admin.php?id=sessions';
?>
<style>
/* ── Onglets Vendeurs / Gérants / Transferts ── */
.ms-tab-bar { display:flex; gap:10px; border-bottom:2px solid #ddd; margin-bottom:18px; overflow-x:auto; overflow-y:hidden; padding-bottom:8px; -webkit-overflow-scrolling:touch; scrollbar-width:thin; }
.ms-tab-btn { flex:0 0 auto; min-width:132px; white-space:nowrap; padding:10px 12px; border:none; background:none; font-weight:bold; font-size:13px; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; transition:color .15s,border-color .15s; outline:none; }
.ms-tab-btn i { display:block; font-size:16px; margin-bottom:3px; }
.ms-tab-sellers.ms-active  { color:#27ae60; border-bottom-color:#27ae60; }
.ms-tab-managers.ms-active { color:#8e44ad; border-bottom-color:#8e44ad; }
.ms-tab-transfers.ms-active{ color:#e67e22; border-bottom-color:#e67e22; }
.ms-tab-btn:not(.ms-active) { color:#6b7280; }
.ms-tab-section { display:none; }
.ms-tab-section.ms-active  { display:block; }
@media(max-width:750px){ .ms-tab-bar{margin:0 -4px 18px;padding:0 4px 8px;} .ms-tab-btn{min-width:116px;font-size:12px;padding:9px 10px;} }
@media(max-width:480px){ .ms-tab-btn{font-size:11px;min-width:102px;padding:8px 10px;} }
.admin-transfer-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 14px;
}
.admin-transfer-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.transfer-label {
    font-weight: bold;
    font-size: 13px;
    color: #555;
}
.table-responsive { overflow-x: auto; }
@media (max-width: 600px) {
    .admin-transfer-grid { grid-template-columns: 1fr; }
}
</style>

<div class="row portal-admin-shell">
<div class="col-12">
<div class="card">
<div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
  <h3 style="margin:0;"><i class="fa fa-users"></i> <?= $_manage_sellers ?></h3>
  <a href="<?= $adminDashboardUrl ?>" class="btn bg-primary">
    <i class="fa fa-dashboard"></i> <?= $_dashboard ?>
  </a>
</div>
<div class="card-body">

<?= $msg ?>

<!-- ── Instructions ── -->
<div class="portal-help-box" style="background:#fff3cd;color:#243447;padding:14px 16px;margin-bottom:15px;border-left:5px solid #ffc107;border-radius:4px;line-height:1.7;">
  <b style="color:#243447;font-size:14px;"><i class="fa fa-info-circle" style="color:#e6a800;"></i>&nbsp; <?= $_how_it_works ?></b>
  <ol style="margin:8px 0 0 20px;padding:0;color:#243447;font-size:13px;line-height:1.8;">
    <li><?= $_seller_step1 ?></li>
    <li><?= $_seller_step2 ?></li>
    <li><?= $_seller_step3 ?></li>
  </ol>
</div>

<!-- ── Barre d'onglets ── -->
<div class="ms-tab-bar">
  <button id="mstab-sellers" onclick="msTab('sellers')" type="button"
          class="ms-tab-btn ms-tab-sellers<?= $active_tab==='sellers' ? ' ms-active' : '' ?>">
    <i class="fa fa-users"></i>
    <?= isset($_sellers) ? $_sellers : 'Vendors' ?>
  </button>
  <button id="mstab-managers" onclick="msTab('managers')" type="button"
          class="ms-tab-btn ms-tab-managers<?= $active_tab==='managers' ? ' ms-active' : '' ?>">
    <i class="fa fa-briefcase"></i>
    <?= isset($_managers) ? $_managers : 'Managers' ?>
  </button>
  <button id="mstab-transfers" onclick="msTab('transfers')" type="button"
          class="ms-tab-btn ms-tab-transfers<?= $active_tab==='transfers' ? ' ms-active' : '' ?>">
    <i class="fa fa-exchange"></i>
    <?= isset($_transfer_logs) ? $_transfer_logs : 'Transfers' ?>
  </button>
</div>

<!-- ── Section Vendeurs ── -->
<div id="ms-section-sellers" class="ms-tab-section<?= $active_tab==='sellers' ? ' ms-active' : '' ?>">

<!-- ── Liste des vendeurs ── -->
<div class="card box-bordered" style="margin-bottom:15px;">
  <div class="card-header"><h4><i class="fa fa-list"></i> <?= $_registered_sellers ?></h4></div>
  <div class="card-body">
    <?php if (empty($sellers_data)): ?>
      <p class="text-center"><i class="fa fa-info-circle"></i> <?= $_no_seller_registered ?></p>
    <?php else: ?>
    <div class="table-responsive portal-table-wrap">
    <table class="table table-bordered table-hover portal-table-min-lg">
      <thead class="thead-light">
        <tr>
          <th style="color:#e74c3c;"><b><?= $_seller_id ?></b></th>
          <th style="color:#e74c3c;"><b><?= $_seller_display_name ?></b></th>
          <th style="color:#e74c3c;"><b><?= $_seller_session_router ?></b></th>
          <th class="text-center" style="color:#e74c3c;"><b><i class="fa fa-percent"></i> Commission</b></th>
          <th style="color:#e74c3c;"><b><?= $_seller_link ?></b></th>
          <th style="color:#e74c3c;"><b><?= $_action ?></b></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sellers_data as $su => $sd): ?>
        <?php $su_rate = isset($sd['commission']) ? (int)$sd['commission'] : 0; ?>
        <tr>
          <td><code><?= htmlspecialchars($su) ?></code></td>
          <td><?= htmlspecialchars($sd['name']) ?></td>
          <td><span class="badge"><?= htmlspecialchars($sd['session']) ?></span></td>
          <td class="text-center">
            <span style="color:#8e44ad;font-weight:bold;"><?= $su_rate ?>%</span>
            <a href="#commission_<?= htmlspecialchars($su) ?>" class="btn btn-sm" style="background:#f3e8fd;color:#8e44ad;border:1px solid #ce93d8;margin-left:4px;padding:2px 7px;font-size:11px;" title="Modifier la commission">
              <i class="fa fa-edit"></i>
            </a>
          </td>
          <td>
            <a href="../sellers.php" target="_blank" style="font-size:12px;">
              <i class="fa fa-external-link"></i> sellers.php
            </a>
          </td>
          <td>
            <a href="?id=sellers&session=<?= $session ?>&del_seller=<?= urlencode($su) ?>"
               onclick="return confirm('<?= isset($_delete) ? addslashes($_delete) : 'Delete' ?> <?= htmlspecialchars($su) ?> ?')"
               class="btn bg-danger btn-sm" title="<?= isset($_delete) ? $_delete : 'Delete' ?>">
              <i class="fa fa-trash"></i>
            </a>
            <a href="#chgpass_<?= htmlspecialchars($su) ?>" class="btn bg-warning btn-sm" title="<?= $_password ?>">
              <i class="fa fa-key"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- Formulaires changement de mot de passe -->
    <?php foreach ($sellers_data as $su => $sd): ?>
    <div class="modal-window" id="chgpass_<?= htmlspecialchars($su) ?>" aria-hidden="true">
      <div>
        <header><h1><i class="fa fa-key"></i> <?= $_password ?> — <?= htmlspecialchars($su) ?></h1></header>
        <a style="font-weight:bold;" href="#" title="<?= isset($_close) ? $_close : 'Close' ?>" class="modal-close">X</a>
        <form autocomplete="off" method="post" action="">
          <?= csrf_field() ?>
          <table class="table">
            <tr>
              <td><?= $_new_password ?? $_password ?></td>
              <td>
                <input class="form-control" id="seller-pass-<?= htmlspecialchars($su) ?>" type="password" name="cp_pass" required>
                <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;cursor:pointer;">
                  <input type="checkbox" onclick="msTogglePassword('seller-pass-<?= htmlspecialchars($su) ?>', this)">
                  <?= isset($_show_password) ? $_show_password : 'Afficher le mot de passe' ?>
                </label>
              </td>
            </tr>
            <tr>
              <td colspan="2">
                <input type="hidden" name="cp_user" value="<?= htmlspecialchars($su) ?>">
                <button type="submit" name="change_pass" class="btn bg-primary">
                  <i class="fa fa-save"></i> <?= $_save ?>
                </button>
              </td>
            </tr>
          </table>
        </form>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Formulaires commission -->
    <?php foreach ($sellers_data as $su => $sd): ?>
    <?php $su_rate = isset($sd['commission']) ? (int)$sd['commission'] : 0; ?>
    <div class="modal-window" id="commission_<?= htmlspecialchars($su) ?>" aria-hidden="true">
      <div>
        <header><h1><i class="fa fa-percent"></i> Commission — <?= htmlspecialchars($su) ?></h1></header>
        <a style="font-weight:bold;" href="#" title="<?= isset($_close) ? $_close : 'Close' ?>" class="modal-close">X</a>
        <form autocomplete="off" method="post" action="">
          <?= csrf_field() ?>
          <table class="table">
            <tr>
              <td>Taux de commission (%)</td>
              <td>
                <input class="form-control" type="number" name="sc_rate" min="0" max="100" value="<?= $su_rate ?>" required style="max-width:120px;">
                <small style="color:#888;display:block;margin-top:4px;">0 = pas de commission · max 100%</small>
              </td>
            </tr>
            <tr>
              <td colspan="2">
                <input type="hidden" name="sc_user" value="<?= htmlspecialchars($su) ?>">
                <button type="submit" name="set_commission" class="btn bg-primary">
                  <i class="fa fa-save"></i> <?= $_save ?>
                </button>
              </td>
            </tr>
          </table>
        </form>
      </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
  </div>
</div>

<!-- ── Distribution globale ── -->
<?php if (!empty($globalStock) && !empty($sellers_data)): ?>
<div class="card box-bordered" style="margin-bottom:15px;border-left:4px solid #e67e22;">
  <div class="card-header" style="background:linear-gradient(90deg,#fff3e0,#fff);">
    <h4 style="color:#e67e22;"><i class="fa fa-random"></i>
      <?= isset($_transfer_stock) ? $_transfer_stock : 'Transfer Stock' ?> —
      <small style="font-weight:normal;font-size:13px;"><?= isset($_global_stock_unassigned) ? $_global_stock_unassigned : 'Global unassigned stock' ?></small>
    </h4>
  </div>
  <div class="card-body">
    <?= $bulk_msg ?>

    <!-- Sélection du profil -->
    <p style="font-size:13px;color:#666;margin-bottom:10px;">
      <i class="fa fa-info-circle" style="color:#e67e22;"></i>
      Choisissez un profil puis saisissez la quantité à attribuer à chaque vendeur.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;" id="distProfBtns">
      <?php foreach ($globalStock as $prof => $total): ?>
      <button onclick="distSelectProfile(<?= htmlspecialchars(json_encode($prof)) ?>, <?= (int)$total ?>)"
              id="distprof-<?= htmlspecialchars(preg_replace('/[^a-z0-9_]/i','-',$prof)) ?>"
              type="button" class="btn btn-sm dist-prof-btn"
              style="background:#f8f9fa;border:2px solid #e67e22;font-weight:bold;border-radius:20px;padding:5px 14px;">
        <i class="fa fa-tag"></i> <?= htmlspecialchars($prof) ?>
        <span style="background:#e67e22;color:#fff;border-radius:10px;padding:1px 7px;margin-left:5px;font-size:12px;"><?= $total ?></span>
      </button>
      <?php endforeach; ?>
    </div>

    <form id="bulkDistForm" method="post" action="?id=sellers&session=<?= htmlspecialchars($session) ?>" style="display:none;">
      <?= csrf_field() ?>
      <input type="hidden" name="bulk_distribute" value="1">
      <input type="hidden" name="dist_profile" id="distProfileInput" value="">

      <!-- Infos profil sélectionné -->
      <div style="padding:10px 14px;background:#fff3e0;border-radius:6px;border-left:3px solid #e67e22;margin-bottom:14px;font-size:14px;">
        <i class="fa fa-tag" style="color:#e67e22;"></i>
        Profil : <strong id="distCurrentProf"></strong> &nbsp;|&nbsp;
        <?= isset($_stock_available) ? $_stock_available : 'Available stock' ?> : <strong id="distAvail" style="color:#e67e22;"></strong> tickets &nbsp;|&nbsp;
        Saisi : <strong id="distSaisie" style="color:#333;">0</strong>
        / <span id="distSaisieMax" style="color:#e67e22;font-weight:bold;">0</span>
      </div>

      <!-- Tableau vendeurs -->
      <div class="table-responsive" style="max-width:560px;">
      <table class="table table-bordered portal-table-min-sm">
        <thead class="thead-light">
          <tr>
            <th><i class="fa fa-user"></i> Vendeur</th>
            <th style="width:170px;"><i class="fa fa-sort-numeric-asc"></i> Quantité</th>
            <th style="width:90px;" class="text-center">Alloué</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sellers_data as $sk => $sd): ?>
          <?php $safeKey = preg_replace('/[^a-z0-9_]/i','-', $sk); ?>
          <tr>
            <td>
              <b><?= htmlspecialchars($sd['name']) ?></b><br>
              <small><code style="color:#888;"><?= htmlspecialchars($sk) ?></code></small>
            </td>
            <td>
              <div style="display:flex;align-items:center;gap:6px;">
                <input type="number" name="vendor_qty[<?= htmlspecialchars($sk) ?>]"
                       id="distqty-<?= $safeKey ?>"
                       class="form-control dist-qty-input" data-key="<?= $safeKey ?>"
                       min="0" value="0" style="width:90px;"
                       oninput="distUpdateTotal()">
                <div style="display:flex;flex-direction:column;gap:2px;">
                  <button type="button" onclick="distMax('<?= $safeKey ?>')"
                          style="font-size:10px;padding:1px 6px;background:#e67e22;color:#fff;border:none;border-radius:3px;cursor:pointer;" title="Max">MAX</button>
                  <button type="button" onclick="distReset('<?= $safeKey ?>')"
                          style="font-size:10px;padding:1px 6px;background:#eee;color:#555;border:none;border-radius:3px;cursor:pointer;" title="Reset">0</button>
                </div>
              </div>
            </td>
            <td class="text-center" id="distalloc-<?= $safeKey ?>" style="color:#aaa;font-weight:bold;">—</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="font-weight:bold;background:#f9f9f9;">
            <td>Total</td>
            <td>
              <span id="distGrandTotal" style="color:#e67e22;font-size:16px;">0</span>
              / <span id="distGrandMax" style="color:#e67e22;">0</span>
            </td>
            <td></td>
          </tr>
        </tfoot>
      </table>
      </div>

      <button type="submit" id="distSubmitBtn" class="btn" disabled
              style="background:#e67e22;color:#fff;font-weight:bold;padding:10px 24px;font-size:15px;">
        <i class="fa fa-random"></i> Distribuer
      </button>
      <button type="button" onclick="distReset('__all__')"
              style="margin-left:8px;background:#eee;color:#555;border:none;border-radius:5px;padding:10px 16px;cursor:pointer;">
        <i class="fa fa-undo"></i> Réinitialiser
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Transfert de stock admin ── -->
<div class="card box-bordered" style="margin-bottom:15px;">
  <div class="card-header"><h4><i class="fa fa-exchange"></i> <?= isset($_transfer_stock) ? $_transfer_stock : 'Transfer Stock' ?></h4></div>
  <div class="card-body">

    <?php if ($transfer_msg): ?>
      <div class="bg-success" style="padding:10px 14px;border-radius:5px;margin-bottom:12px;">
        <i class="fa fa-check-circle"></i> <?= $transfer_msg ?>
      </div>
    <?php endif; ?>
    <?php if ($transfer_error): ?>
      <div class="bg-danger" style="padding:10px 14px;border-radius:5px;margin-bottom:12px;">
        <i class="fa fa-ban"></i> <?= htmlspecialchars($transfer_error) ?>
      </div>
    <?php endif; ?>

    <?php if (empty($sellers_data) || count($sellers_data) < 2): ?>
      <p class="text-center" style="color:#888;">
        <i class="fa fa-info-circle"></i>
        <?= isset($_no_seller_registered) ? $_no_seller_registered : 'At least two vendors required.' ?>
      </p>
    <?php else: ?>

    <!-- Stock par vendeur -->
    <div style="overflow-x:auto;margin-bottom:18px;">
    <table class="table table-bordered portal-table-min-sm" style="max-width:600px;font-size:13px;">
      <thead class="thead-light">
        <tr>
          <th><?= isset($_seller) ? $_seller : 'Vendor' ?></th>
          <th><?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?></th>
          <th class="text-center"><?= isset($_seller_qty) ? $_seller_qty : 'Qty' ?></th>
        </tr>
      </thead>
      <tbody>
      <?php
        $hasAny = false;
        foreach ($allSellerStock as $sk => $profiles):
            if (empty($profiles)) continue;
            $hasAny = true;
            $first = true;
            $rowspan = count($profiles);
            foreach ($profiles as $prof => $qty):
      ?>
        <tr>
          <?php if ($first): ?>
          <td rowspan="<?= $rowspan ?>" style="vertical-align:middle;font-weight:bold;">
            <?= htmlspecialchars($sellers_data[$sk]['name']) ?>
            <br><small class="portal-muted-light" style="color:#999;font-weight:normal;"><code><?= htmlspecialchars($sk) ?></code></small>
          </td>
          <?php $first = false; endif; ?>
          <td><?= htmlspecialchars($prof) ?></td>
          <td class="text-center"><b><?= $qty ?></b></td>
        </tr>
      <?php endforeach; endforeach; ?>
      <?php if (!$hasAny): ?>
        <tr><td colspan="3" class="text-center" style="color:#888;">
          <i class="fa fa-info-circle"></i> <?= isset($_transfer_no_stock) ? $_transfer_no_stock : 'No unused tickets available.' ?>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>

    <!-- Formulaire transfert admin -->
    <?php if ($hasAny): ?>
    <form method="post" action="?id=sellers&session=<?= htmlspecialchars($session) ?>" style="max-width:560px;" id="adminTransferForm">
      <?= csrf_field() ?>
      <input type="hidden" name="admin_transfer" value="1">
      <p style="color:#666;font-size:13px;margin-bottom:12px;">
        <i class="fa fa-info-circle"></i>
        <?= isset($_transfer_info) ? $_transfer_info : 'Select a profile, a quantity and the receiving vendor.' ?>
      </p>

      <div class="admin-transfer-grid">
        <!-- Vendeur source -->
        <div class="admin-transfer-group">
          <label class="transfer-label"><i class="fa fa-user"></i> <?= isset($_transfer_from) ? $_transfer_from : 'From' ?></label>
          <select name="src_seller" class="form-control" id="srcSeller" onchange="updateAdminProfiles()" required>
            <option value=""><?= isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Select vendor' ?></option>
            <?php foreach ($sellers_data as $sk => $sd): ?>
              <?php if (!empty($allSellerStock[$sk])): ?>
              <option value="<?= htmlspecialchars($sk) ?>"><?= htmlspecialchars($sd['name']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Profil -->
        <div class="admin-transfer-group">
          <label class="transfer-label"><i class="fa fa-tag"></i> <?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?></label>
          <select name="transfer_profile" class="form-control" id="adminTransferProf" required>
            <option value=""><?= isset($_transfer_select_profile) ? $_transfer_select_profile : 'Select profile' ?></option>
          </select>
        </div>

        <!-- Quantité -->
        <div class="admin-transfer-group">
          <label class="transfer-label"><i class="fa fa-sort-numeric-asc"></i> <?= isset($_transfer_qty) ? $_transfer_qty : 'Quantity' ?></label>
          <input type="number" name="transfer_qty" class="form-control" min="1" value="1" required>
        </div>

        <!-- Vendeur cible -->
        <div class="admin-transfer-group">
          <label class="transfer-label"><i class="fa fa-arrow-right"></i> <?= isset($_transfer_to) ? $_transfer_to : 'Transfer to' ?></label>
          <select name="dst_seller" class="form-control" required>
            <option value=""><?= isset($_transfer_select_vendor) ? $_transfer_select_vendor : 'Select vendor' ?></option>
            <?php foreach ($sellers_data as $sk => $sd): ?>
              <option value="<?= htmlspecialchars($sk) ?>"><?= htmlspecialchars($sd['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <button type="submit" class="btn bg-primary" style="margin-top:6px;">
        <i class="fa fa-exchange"></i> <?= isset($_transfer_submit) ? $_transfer_submit : 'Transfer' ?>
      </button>
    </form>

    <script>
    var adminStock = <?= json_encode($allSellerStock) ?>;
    function updateAdminProfiles() {
        var src  = document.getElementById('srcSeller').value;
        var sel  = document.getElementById('adminTransferProf');
        sel.innerHTML = '<option value=""><?= addslashes(isset($_transfer_select_profile) ? $_transfer_select_profile : 'Select profile') ?></option>';
        if (src && adminStock[src]) {
            for (var prof in adminStock[src]) {
                sel.innerHTML += '<option value="' + prof + '">' + prof + ' (' + adminStock[src][prof] + ')</option>';
            }
        }
    }
    </script>
    <?php endif; // hasAny ?>

    <?php endif; // sellers count ?>
  </div>
</div>

<!-- ── Ajouter un vendeur ── -->
<div class="card box-bordered">
  <div class="card-header"><h4><i class="fa fa-user-plus"></i> <?= $_add_seller ?></h4></div>
  <div class="card-body">
    <div class="bg-light" style="padding:10px 14px;border-left:4px solid #27ae60;border-radius:5px;margin-bottom:12px;">
      <b><?= isset($_default_credentials) ? $_default_credentials : 'Default credentials' ?></b><br>
      <?= $_seller_id ?>: <code><?= htmlspecialchars($defaultSellerAccount['username']) ?></code> |
      <?= $_password ?>: <code><?= htmlspecialchars($defaultSellerAccount['password']) ?></code><br>
      <small><?= isset($_default_credentials_note) ? $_default_credentials_note : 'Admin can change these values before creation and update the password later.' ?></small>
    </div>
    <form autocomplete="off" method="post" action="">
      <?= csrf_field() ?>
      <table class="table">
        <tr>
          <td class="align-middle"><?= $_seller_id ?> <small>(a-z, 0-9, _)</small></td>
          <td>
            <input class="form-control" type="text" name="new_user"
                   pattern="[a-zA-Z0-9_]+" title="a-z, 0-9, _"
                   value="<?= htmlspecialchars($defaultSellerAccount['username']) ?>" required>
          </td>
        </tr>
        <tr>
          <td class="align-middle"><?= $_password ?></td>
          <td>
            <input class="form-control" id="seller-new-pass" type="password" name="new_pass" value="<?= htmlspecialchars($defaultSellerAccount['password']) ?>" required>
            <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;cursor:pointer;">
              <input type="checkbox" onclick="msTogglePassword('seller-new-pass', this)">
              <?= isset($_show_password) ? $_show_password : 'Afficher le mot de passe' ?>
            </label>
          </td>
        </tr>
        <tr>
          <td class="align-middle"><?= $_seller_display_name ?></td>
          <td><input class="form-control" type="text" name="new_name" value="<?= htmlspecialchars($defaultSellerAccount['name']) ?>" required></td>
        </tr>
        <tr>
          <td class="align-middle"><?= $_seller_session_router ?></td>
          <td>
            <select class="form-control" name="new_session" required>
              <?php foreach ($available_sessions as $sn): ?>
                <option value="<?= htmlspecialchars($sn) ?>"><?= htmlspecialchars($sn) ?></option>
              <?php endforeach; ?>
              <?php if (empty($available_sessions)): ?>
                <option value=""><?= $_no_session_available ?></option>
              <?php endif; ?>
            </select>
          </td>
        </tr>
        <tr>
          <td colspan="2">
            <button type="submit" name="add_seller" class="btn bg-primary">
              <i class="fa fa-save"></i> <?= $_add_seller ?>
            </button>
          </td>
        </tr>
      </table>
    </form>
  </div>
</div>

</div><!-- /ms-section-sellers -->

<!-- ── Section Gérants ── -->
<div id="ms-section-managers" class="ms-tab-section<?= $active_tab==='managers' ? ' ms-active' : '' ?>">

<!-- ── Gestion des Gérants (admin uniquement) ── -->
<div class="card box-bordered" style="border-left:4px solid #8e44ad;">
  <div class="card-header" style="background:linear-gradient(90deg,#f3e8fd,#fff);">
    <h4 style="color:#8e44ad;"><i class="fa fa-briefcase"></i> <?= isset($_manage_managers) ? $_manage_managers : 'Manage Managers' ?></h4>
  </div>
  <div class="card-body">

    <?= $msg_mgr ?>

    <div style="background:#f8f1ff;color:#4a235a;padding:12px 14px;margin-bottom:15px;border-left:4px solid #8e44ad;border-radius:4px;">
      <b><i class="fa fa-info-circle"></i> Portail gérant</b><br>
      Le gérant supervise les vendeurs, voit les ventes, les commissions et le stock, puis gère les transferts de lots.
      Son accès est distinct du vendeur et son portail s’ouvre sur <code>manager.php</code>.
    </div>

    <!-- Liste des gérants -->
    <div class="card box-bordered" style="margin-bottom:15px;">
      <div class="card-header"><h5><i class="fa fa-list"></i> <?= isset($_registered_managers) ? $_registered_managers : 'Registered Managers' ?></h5></div>
      <div class="card-body">
        <?php if (empty($managers_data)): ?>
          <p class="text-center" style="color:#888;"><i class="fa fa-info-circle"></i> <?= isset($_no_manager_registered) ? $_no_manager_registered : 'No manager registered.' ?></p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-bordered table-hover portal-table-min-lg">
          <thead class="thead-light">
            <tr>
              <th><?= isset($_seller_id) ? $_seller_id : 'Identifier' ?></th>
              <th><?= isset($_seller_display_name) ? $_seller_display_name : 'Display Name' ?></th>
              <th><?= isset($_seller_session_router) ? $_seller_session_router : 'Session (router)' ?></th>
              <th><?= isset($_manager_portal) ? $_manager_portal : 'Manager Portal' ?></th>
              <th style="color:#e74c3c;"><b><?= isset($_action) ? $_action : 'Action' ?></b></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($managers_data as $mu => $md): ?>
            <tr>
              <td><code><?= htmlspecialchars($mu) ?></code></td>
              <td><?= htmlspecialchars($md['name']) ?></td>
              <td><span class="badge"><?= htmlspecialchars($md['session']) ?></span></td>
              <td>
                <a href="../manager.php?action=dashboard" target="_blank" style="font-size:12px;">
                  <i class="fa fa-external-link"></i> manager.php
                </a>
              </td>
              <td>
                <a href="?id=sellers&session=<?= $session ?>&tab=managers&del_manager=<?= urlencode($mu) ?>"
                   onclick="return confirm('<?= isset($_delete) ? addslashes($_delete) : 'Delete' ?> <?= htmlspecialchars($mu) ?> ?')"
                   class="btn bg-danger btn-sm" title="<?= isset($_delete) ? $_delete : 'Delete' ?>">
                  <i class="fa fa-trash"></i>
                </a>
                <a href="#chgpass_mgr_<?= htmlspecialchars($mu) ?>" class="btn bg-warning btn-sm" title="<?= $_password ?>">
                  <i class="fa fa-key"></i>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>

        <!-- Modals changement mot de passe gérant -->
        <?php foreach ($managers_data as $mu => $md): ?>
        <div class="modal-window" id="chgpass_mgr_<?= htmlspecialchars($mu) ?>" aria-hidden="true">
          <div>
            <header><h1><i class="fa fa-key"></i> <?= $_password ?> — <?= htmlspecialchars($mu) ?></h1></header>
            <a style="font-weight:bold;" href="#" title="<?= isset($_close) ? $_close : 'Close' ?>" class="modal-close">X</a>
            <form autocomplete="off" method="post" action="?id=sellers&session=<?= urlencode($session) ?>&tab=managers">
              <?= csrf_field() ?>
              <table class="table">
                <tr>
                  <td><?= $_password ?></td>
                  <td>
                    <input class="form-control" id="manager-pass-<?= htmlspecialchars($mu) ?>" type="password" name="cmp_pass" required>
                    <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;cursor:pointer;">
                      <input type="checkbox" onclick="msTogglePassword('manager-pass-<?= htmlspecialchars($mu) ?>', this)">
                      <?= isset($_show_password) ? $_show_password : 'Afficher le mot de passe' ?>
                    </label>
                  </td>
                </tr>
                <tr>
                  <td colspan="2">
                    <input type="hidden" name="cmp_user" value="<?= htmlspecialchars($mu) ?>">
                    <button type="submit" name="change_manager_pass" class="btn bg-primary">
                      <i class="fa fa-save"></i> <?= $_save ?>
                    </button>
                  </td>
                </tr>
              </table>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Ajouter un gérant -->
    <div class="card box-bordered">
      <div class="card-header"><h5><i class="fa fa-user-plus"></i> <?= isset($_add_manager) ? $_add_manager : 'Add Manager' ?></h5></div>
      <div class="card-body">
        <div class="bg-light" style="padding:10px 14px;border-left:4px solid #8e44ad;border-radius:5px;margin-bottom:12px;">
          <b><?= isset($_default_credentials) ? $_default_credentials : 'Default credentials' ?></b><br>
          <?= isset($_seller_id) ? $_seller_id : 'Identifier' ?>: <code><?= htmlspecialchars($defaultManagerAccount['username']) ?></code> |
          <?= $_password ?>: <code><?= htmlspecialchars($defaultManagerAccount['password']) ?></code><br>
          <small><?= isset($_default_credentials_note) ? $_default_credentials_note : 'Admin can change these values before creation and update the password later.' ?></small>
        </div>
        <form autocomplete="off" method="post" action="?id=sellers&session=<?= urlencode($session) ?>&tab=managers">
          <?= csrf_field() ?>
          <table class="table">
            <tr>
              <td class="align-middle"><?= isset($_seller_id) ? $_seller_id : 'Identifier' ?> <small>(a-z, 0-9, _)</small></td>
              <td><input class="form-control" type="text" name="nm_user" pattern="[a-zA-Z0-9_]+" value="<?= htmlspecialchars($defaultManagerAccount['username']) ?>" required></td>
            </tr>
            <tr>
              <td class="align-middle"><?= $_password ?></td>
              <td>
                <input class="form-control" id="manager-new-pass" type="password" name="nm_pass" value="<?= htmlspecialchars($defaultManagerAccount['password']) ?>" required>
                <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:12px;cursor:pointer;">
                  <input type="checkbox" onclick="msTogglePassword('manager-new-pass', this)">
                  <?= isset($_show_password) ? $_show_password : 'Afficher le mot de passe' ?>
                </label>
              </td>
            </tr>
            <tr>
              <td class="align-middle"><?= isset($_seller_display_name) ? $_seller_display_name : 'Display Name' ?></td>
              <td><input class="form-control" type="text" name="nm_name" value="<?= htmlspecialchars($defaultManagerAccount['name']) ?>" required></td>
            </tr>
            <tr>
              <td class="align-middle"><?= isset($_seller_session_router) ? $_seller_session_router : 'Session' ?></td>
              <td>
                <select class="form-control" name="nm_session" required>
                  <?php foreach ($available_sessions as $sn): ?>
                    <option value="<?= htmlspecialchars($sn) ?>"><?= htmlspecialchars($sn) ?></option>
                  <?php endforeach; ?>
                  <?php if (empty($available_sessions)): ?>
                    <option value=""><?= isset($_no_session_available) ? $_no_session_available : 'No session' ?></option>
                  <?php endif; ?>
                </select>
              </td>
            </tr>
            <tr>
              <td colspan="2">
                <button type="submit" name="add_manager" class="btn" style="background:#8e44ad;color:#fff;">
                  <i class="fa fa-save"></i> <?= isset($_add_manager) ? $_add_manager : 'Add Manager' ?>
                </button>
              </td>
            </tr>
          </table>
        </form>
      </div>
    </div>

  </div><!-- card-body gérants -->
</div><!-- card gérants -->

</div><!-- /ms-section-managers -->

<!-- ── Section Transferts ── -->
<div id="ms-section-transfers" class="ms-tab-section<?= $active_tab==='transfers' ? ' ms-active' : '' ?>">

<!-- ── Journal des transferts récents (admin) ── -->
<?php $recentLogs = get_transfer_logs(10); ?>
<div class="card box-bordered" style="margin-top:15px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <h4 style="margin:0;"><i class="fa fa-history"></i> <?= isset($_transfer_logs) ? $_transfer_logs : 'Recent Transfers' ?> <small>(<?= count($recentLogs) ?>)</small></h4>
    <?php if (!empty($recentLogs)): ?>
      <form method="post" action="?id=sellers&session=<?= urlencode($session) ?>&tab=transfers" onsubmit="return confirm('<?= isset($_transfer_log_clear_confirm) ? addslashes($_transfer_log_clear_confirm) : 'Vider tout le journal des transferts ?' ?>');" style="margin:0;">
        <?= csrf_field() ?>
        <button type="submit" name="clear_transfer_logs" class="btn bg-danger btn-sm">
          <i class="fa fa-trash"></i> <?= isset($_transfer_log_clear) ? $_transfer_log_clear : 'Vider le journal' ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?= $transfer_log_msg ?>
    <?php if ($transfer_log_error): ?>
      <div class="bg-danger" style="padding:8px;border-radius:5px;margin-bottom:10px;"><i class="fa fa-ban"></i> <?= htmlspecialchars($transfer_log_error) ?></div>
    <?php endif; ?>
    <?php if (empty($recentLogs)): ?>
      <p class="text-center portal-empty-note" style="padding:20px;margin:0;">
        <i class="fa fa-info-circle"></i> <?= isset($_transfer_log_empty) ? $_transfer_log_empty : 'No transfers recorded yet.' ?>
      </p>
    <?php else: ?>
    <div class="table-responsive portal-table-wrap">
    <table class="table table-bordered portal-table-min-md" style="font-size:12px;">
      <thead class="thead-light">
        <tr>
          <th><?= isset($_date) ? $_date : 'Date' ?></th>
          <th><?= isset($_transfer_from_col) ? $_transfer_from_col : 'From' ?></th>
          <th>→</th>
          <th><?= isset($_transfer_to) ? $_transfer_to : 'To' ?></th>
          <th><?= isset($_transfer_profile) ? $_transfer_profile : 'Profile' ?></th>
          <th class="text-center"><?= isset($_transfer_qty) ? $_transfer_qty : 'Qty' ?></th>
          <th><?= isset($_transfer_by) ? $_transfer_by : 'By' ?></th>
          <th class="text-center"><?= isset($_action) ? $_action : 'Action' ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentLogs as $log): ?>
        <tr>
          <td style="white-space:nowrap;font-size:11px;"><?= htmlspecialchars($log['ts']) ?></td>
          <td><b><?= htmlspecialchars($log['from']) ?></b></td>
          <td class="portal-empty-note" style="color:#aaa;">→</td>
          <td><b><?= htmlspecialchars($log['to']) ?></b></td>
          <td><?= htmlspecialchars($log['profile']) ?></td>
          <td class="text-center"><b><?= (int)$log['qty'] ?></b></td>
          <td>
            <span style="font-size:10px;padding:1px 6px;border-radius:8px;font-weight:bold;color:#fff;background:<?= $log['by_role']==='admin' ? '#007bff' : ($log['by_role']==='manager' ? '#8e44ad' : '#27ae60') ?>;">
              <?= htmlspecialchars($log['by_role']) ?>
            </span>
          </td>
          <td class="text-center">
            <form method="post" action="?id=sellers&session=<?= urlencode($session) ?>&tab=transfers" onsubmit="return confirm('<?= isset($_transfer_log_delete_confirm) ? addslashes($_transfer_log_delete_confirm) : 'Supprimer cette entrée du journal ?' ?>');" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="log_row" value="<?= isset($log['_row']) ? (int)$log['_row'] : -1 ?>">
              <button type="submit" name="delete_transfer_log" class="btn bg-danger btn-sm" title="<?= isset($_transfer_log_delete_one) ? $_transfer_log_delete_one : 'Supprimer cette entrée' ?>">
                <i class="fa fa-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

</div><!-- /ms-section-transfers -->

<!-- Confirmation modal admin transfer -->
<div id="adminConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;justify-content:center;align-items:center;padding:16px;">
  <div style="background:#fff;border-radius:10px;padding:28px 24px;max-width:380px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.2);text-align:center;">
    <h3 style="margin:0 0 8px;font-size:17px;"><i class="fa fa-exchange" style="color:#007bff;"></i> <?= isset($_transfer_submit) ? $_transfer_submit : 'Transfer' ?></h3>
    <p id="adminConfirmBody" style="color:#555;margin-bottom:20px;font-size:15px;line-height:1.5;"></p>
    <div style="display:flex;gap:10px;justify-content:center;">
      <button id="adminConfirmCancel" style="flex:1;padding:10px;border:none;border-radius:6px;font-size:15px;font-weight:bold;cursor:pointer;background:#eee;color:#555;">
        <i class="fa fa-times"></i> <?= isset($_cancel) ? $_cancel : 'Cancel' ?>
      </button>
      <button id="adminConfirmOk" style="flex:1;padding:10px;border:none;border-radius:6px;font-size:15px;font-weight:bold;cursor:pointer;background:#007bff;color:#fff;">
        <i class="fa fa-check"></i> <?= isset($_confirm) ? $_confirm : 'Confirm' ?>
      </button>
    </div>
  </div>
</div>
<script>
(function(){
  var form  = document.getElementById('adminTransferForm');
  var modal = document.getElementById('adminConfirmModal');
  if (!form || !modal) return;
  var pending = null;
  form.addEventListener('submit', function(e){
    var src   = document.getElementById('srcSeller');
    var prof  = document.getElementById('adminTransferProf');
    var qty   = form.querySelector('[name="transfer_qty"]');
    var dst   = form.querySelector('[name="dst_seller"]');
    if (!src?.value || !prof?.value || !dst?.value) return;
    e.preventDefault();
    var srcName = src.options[src.selectedIndex].text;
    var dstName = dst.options[dst.selectedIndex].text;
    document.getElementById('adminConfirmBody').innerHTML =
      '<b>'+qty.value+'</b> ticket(s) ['+prof.value+']<br>'+srcName+' → <b>'+dstName+'</b>';
    modal.style.display = 'flex';
    pending = form;
  });
  document.getElementById('adminConfirmOk').addEventListener('click', function(){
    modal.style.display='none'; if(pending) pending.submit();
  });
  document.getElementById('adminConfirmCancel').addEventListener('click', function(){
    modal.style.display='none'; pending=null;
  });
  modal.addEventListener('click', function(e){ if(e.target===modal){modal.style.display='none';pending=null;}});
})();

/* ── Distribution globale ── */
var distGlobalStock = <?= json_encode($globalStock) ?>;
var distCurrentMax  = 0;

function distSelectProfile(prof, total) {
  document.getElementById('distProfileInput').value = prof;
  document.getElementById('distCurrentProf').textContent = prof;
  document.getElementById('distAvail').textContent = total;
  document.getElementById('distSaisieMax').textContent = total;
  document.getElementById('distGrandMax').textContent = total;
  distCurrentMax = total;

  // Afficher le formulaire
  document.getElementById('bulkDistForm').style.display = '';

  // Reset inputs
  document.querySelectorAll('.dist-qty-input').forEach(function(i){ i.value = 0; });
  document.querySelectorAll('[id^="distalloc-"]').forEach(function(el){ el.textContent = '—'; el.style.color='#aaa'; });
  document.getElementById('distGrandTotal').textContent = '0';
  document.getElementById('distSaisie').textContent = '0';
  document.getElementById('distSubmitBtn').disabled = true;

  // Surbrillance du bouton profil actif
  document.querySelectorAll('.dist-prof-btn').forEach(function(b){
    b.style.background = '#f8f9fa'; b.style.color = '#333';
  });
  var safeProf = prof.replace(/[^a-z0-9_]/gi, '-');
  var activeBtn = document.getElementById('distprof-' + safeProf);
  if (activeBtn) { activeBtn.style.background = '#e67e22'; activeBtn.style.color = '#fff'; }
}

function distUpdateTotal() {
  var total = 0;
  document.querySelectorAll('.dist-qty-input').forEach(function(inp) {
    var v = parseInt(inp.value || 0, 10);
    if (isNaN(v) || v < 0) { inp.value = 0; v = 0; }
    total += v;
    var key = inp.getAttribute('data-key');
    var allocEl = document.getElementById('distalloc-' + key);
    if (allocEl) {
      allocEl.textContent = v > 0 ? v : '—';
      allocEl.style.color = v > 0 ? '#27ae60' : '#aaa';
    }
  });
  var gtEl = document.getElementById('distGrandTotal');
  gtEl.textContent = total;
  gtEl.style.color = total > distCurrentMax ? '#dc3545' : '#e67e22';
  document.getElementById('distSaisie').textContent = total;
  document.getElementById('distSaisie').style.color = total > distCurrentMax ? '#dc3545' : '#333';
  document.getElementById('distSubmitBtn').disabled = (total <= 0 || total > distCurrentMax);
}

function distMax(key) {
  // Remplir le max restant disponible dans ce champ
  var usedByOthers = 0;
  document.querySelectorAll('.dist-qty-input').forEach(function(inp) {
    if (inp.getAttribute('data-key') !== key) usedByOthers += parseInt(inp.value || 0, 10);
  });
  var remaining = Math.max(0, distCurrentMax - usedByOthers);
  var el = document.getElementById('distqty-' + key);
  if (el) { el.value = remaining; distUpdateTotal(); }
}

function distReset(key) {
  if (key === '__all__') {
    document.querySelectorAll('.dist-qty-input').forEach(function(i){ i.value = 0; });
    distUpdateTotal();
  } else {
    var el = document.getElementById('distqty-' + key);
    if (el) { el.value = 0; distUpdateTotal(); }
  }
}

/* ── Tab switcher ── */
function msTogglePassword(fieldId, checkbox) {
  var input = document.getElementById(fieldId);
  if (!input) return;
  input.type = checkbox && checkbox.checked ? 'text' : 'password';
}

function msTab(tab) {
  var sections = ['sellers','managers','transfers'];
  sections.forEach(function(t){
    var sec = document.getElementById('ms-section-'+t);
    var btn = document.getElementById('mstab-'+t);
    if (!sec || !btn) return;
    if (t === tab) {
      sec.classList.add('ms-active');
      btn.classList.add('ms-active');
    } else {
      sec.classList.remove('ms-active');
      btn.classList.remove('ms-active');
    }
  });
  // Update URL without reload
  var url = new URL(window.location.href);
  url.searchParams.set('tab', tab);
  history.replaceState(null, '', url.toString());
}
</script>

</div>
</div>
</div>
</div>
