<?php
/*
 * settings/fraud.php — Page Anti-fraude (admin).
 * Affiche les incidents détectés (un compte hotspot avec ≥2 MACs).
 */
if (empty($_SESSION['mikhmon'])) {
    header('Location: ./admin.php?id=login');
    exit;
}
require_once __DIR__ . '/../include/anti_fraud.php';

// Lance un scan si le routeur est connecté pour cette session
$apiOk = false;
if (isset($API) && !empty($iphost) && !empty($userhost) && !empty($passwdhost)) {
    if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
        try { anti_fraud_scan($API); } catch (Exception $e) {}
        $apiOk = true;
    }
}

$incidents = anti_fraud_load();
$openCount = anti_fraud_count_unack();
?>
<style>
.fraud-card { background:#fff; border-radius:10px; box-shadow:0 4px 14px rgba(15,23,42,.08); margin-bottom:18px; overflow:hidden; }
.fraud-card .fc-h {
  padding:18px 22px;
  background:linear-gradient(135deg,#7f1d1d 0%,#991b1b 35%,#b91c1c 100%);
  color:#fff;
  display:flex; align-items:center; gap:14px;
  border-bottom:3px solid #fde68a;
}
.fraud-card .fc-h .fc-icon-wrap {
  flex:0 0 auto;
  width:42px; height:42px; border-radius:50%;
  background:rgba(255,255,255,.15);
  display:flex; align-items:center; justify-content:center;
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.25);
}
.fraud-card .fc-h .fc-icon-wrap i { font-size:20px; color:#fff; }
.fraud-card .fc-h .fc-title-block { flex:1; min-width:0; line-height:1.25; }
.fraud-card .fc-h .fc-eyebrow {
  display:block;
  font-size:10px; font-weight:700; letter-spacing:1.5px;
  text-transform:uppercase; opacity:.78; margin-bottom:2px;
}
.fraud-card .fc-h h3 {
  margin:0;
  font-size:19px; font-weight:700;
  letter-spacing:.2px;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.fraud-card .fc-h h3 .fc-sep {
  color:#fde68a; margin:0 8px; font-weight:300;
}
.fraud-card .fc-h h3 .fc-sub {
  font-weight:400; opacity:.92;
}
.fraud-card .fc-h .fc-actions { margin-left:auto; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.fraud-card .fc-h .btn-rescan {
  background:#fff; color:#991b1b;
  padding:7px 14px; border-radius:6px;
  font-size:12px; font-weight:700; text-decoration:none;
  display:inline-flex; align-items:center; gap:6px;
  box-shadow:0 2px 4px rgba(0,0,0,.15);
  transition:transform .15s ease, box-shadow .15s ease;
}
.fraud-card .fc-h .btn-rescan:hover { transform:translateY(-1px); box-shadow:0 4px 8px rgba(0,0,0,.18); color:#7f1d1d; }
@media (max-width:600px) {
  .fraud-card .fc-h { padding:14px 16px; gap:10px; }
  .fraud-card .fc-h h3 { font-size:15px; white-space:normal; }
  .fraud-card .fc-h .fc-eyebrow { font-size:9px; letter-spacing:1px; }
  .fraud-card .fc-h .fc-icon-wrap { width:36px; height:36px; }
}
.fraud-badge { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:12px; font-size:11px; font-weight:700; letter-spacing:.3px; }
.fraud-badge.new { background:#fff; color:#991b1b; box-shadow:0 0 0 2px rgba(255,255,255,.35); }
.fraud-badge.new::before { content:""; width:7px; height:7px; border-radius:50%; background:#dc2626; box-shadow:0 0 0 3px rgba(220,38,38,.3); animation:fraud-pulse 1.4s infinite; }
.fraud-badge.acknowledged { background:#fff8e1; color:#ef6c00; }
.fraud-badge.resolved { background:#e8f5e9; color:#2e7d32; }
@keyframes fraud-pulse {
  0%, 100% { box-shadow:0 0 0 3px rgba(220,38,38,.3); }
  50%      { box-shadow:0 0 0 5px rgba(220,38,38,.05); }
}
.fraud-row { padding:14px 16px; border-bottom:1px solid #f4f4f4; }
.fraud-row:last-child { border-bottom:none; }
.fraud-row .fr-top { display:flex; align-items:center; flex-wrap:wrap; gap:10px; }
.fraud-row .fr-user { font-family:monospace; font-weight:bold; color:#1565c0; font-size:14px; }
.fraud-row .fr-meta { font-size:12px; color:#666; }
.fraud-row .fr-macs { margin-top:6px; }
.fraud-row .fr-macs code { display:inline-block; padding:2px 6px; margin:2px; background:#f5f5f5; border-radius:3px; font-size:11px; }
.fraud-row .fr-macs code.locked { background:#e8f5e9; border:1px solid #66bb6a; color:#1b5e20; }
.fraud-row .fr-macs code.attempt { background:#ffebee; border:1px solid #ef5350; color:#b71c1c; }
.fraud-row .fr-macs code.attempt::before { content:"⚠ "; }
.fraud-row .fr-section-label { font-size:10px; font-weight:bold; text-transform:uppercase; color:#64748b; margin-right:6px; }
.fraud-empty { padding:30px; text-align:center; color:#999; }
.btn-fraud { padding:4px 10px; border-radius:4px; font-size:12px; border:none; cursor:pointer; margin-left:4px; }
.btn-fraud.ack { background:#fff8e1; color:#ef6c00; }
.btn-fraud.res { background:#e8f5e9; color:#2e7d32; }
.btn-fraud.lock { background:#ffebee; color:#c62828; }
</style>

<div class="content content-margin">
<div class="row">
<div class="col-12">
<div class="fraud-card">
  <div class="fc-h">
    <div class="fc-icon-wrap"><i class="fa fa-shield"></i></div>
    <div class="fc-title-block">
      <span class="fc-eyebrow">Sécurité &middot; Détection</span>
      <h3>
        Anti-fraude
        <span class="fc-sep">—</span>
        <span class="fc-sub">Tickets utilisés sur plusieurs appareils</span>
      </h3>
    </div>
    <div class="fc-actions">
      <span class="fraud-badge <?= $openCount > 0 ? 'new' : 'resolved' ?>">
        <?= $openCount ?> nouveau<?= $openCount > 1 ? 'x' : '' ?>
      </span>
      <a href="./admin.php?id=fraud&session=<?= urlencode($session ?? '') ?>" class="btn-rescan" title="Relancer un scan complet">
        <i class="fa fa-refresh"></i> Re-scan
      </a>
    </div>
  </div>

  <?php if (empty($incidents)): ?>
    <div class="fraud-empty">
      <i class="fa fa-check-circle" style="color:#2e7d32; font-size:32px; display:block; margin-bottom:8px;"></i>
      <?= isset($_fraud_none_detected) ? $_fraud_none_detected : 'No cases detected.' ?>
      <?= $apiOk ? '' : '<br><small style="color:#c62828;">⚠ ' . (isset($_fraud_offline_warning) ? $_fraud_offline_warning : 'MikroTik unreachable — data may be outdated.') . '</small>' ?>
    </div>
  <?php else: ?>
    <?php foreach ($incidents as $i):
      $st = $i['status'] ?? 'new';
      $macs = $i['macs'] ?? array();
      $lockedMac = isset($i['locked_mac']) ? strtoupper((string)$i['locked_mac']) : '';
      $attempted = isset($i['attempted_macs']) ? $i['attempted_macs'] : array();
      $attemptedMeta = isset($i['attempted_meta']) ? $i['attempted_meta'] : array();
    ?>
      <div class="fraud-row" data-user="<?= htmlspecialchars($i['user']) ?>">
        <div class="fr-top">
          <span class="fr-user"><i class="fa fa-user"></i> <?= htmlspecialchars($i['user']) ?></span>
          <span class="fraud-badge <?= $st ?>"><?= htmlspecialchars(strtoupper($st)) ?></span>
          <?php if (!empty($i['profile'])): ?><span class="fr-meta">profil <b><?= htmlspecialchars($i['profile']) ?></b></span><?php endif; ?>
          <?php if (!empty($i['comment'])): ?><span class="fr-meta">commentaire <i><?= htmlspecialchars($i['comment']) ?></i></span><?php endif; ?>
          <span class="fr-meta"><b><?= count($macs) ?></b> MAC connectés</span>
          <?php if (!empty($attempted)): ?>
            <span class="fr-meta" style="color:#c62828;font-weight:bold;"><i class="fa fa-exclamation-triangle"></i> <?= count($attempted) ?> tentative<?= count($attempted) > 1 ? 's' : '' ?> bloquée<?= count($attempted) > 1 ? 's' : '' ?></span>
          <?php endif; ?>
          <span class="fr-meta">détecté <?= htmlspecialchars($i['first_detected'] ?? '-') ?></span>
        </div>
        <?php if (!empty($macs) || !empty($lockedMac)): ?>
        <div class="fr-macs">
          <span class="fr-section-label">Connectés :</span>
          <?php foreach ($macs as $m):
            $isLocked = ($lockedMac !== '' && strtoupper($m) === $lockedMac);
          ?>
            <code class="<?= $isLocked ? 'locked' : '' ?>" title="<?= $isLocked ? 'MAC verrouillé (premier login)' : '' ?>"><?= htmlspecialchars($m) ?><?php if ($isLocked): ?> 🔒<?php endif; ?></code>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($attempted)): ?>
        <div class="fr-macs">
          <span class="fr-section-label" style="color:#c62828;">📱 Téléphones bloqués (tentative avec MAC ≠ verrouillé) :</span>
          <?php foreach ($attempted as $am):
            $meta = isset($attemptedMeta[$am]) ? $attemptedMeta[$am] : array();
            $ip   = isset($meta['ip']) ? $meta['ip'] : '';
            $last = isset($meta['last_seen']) ? $meta['last_seen'] : '';
          ?>
            <code class="attempt" title="IP <?= htmlspecialchars($ip) ?> · <?= htmlspecialchars($last) ?>"><?= htmlspecialchars($am) ?><?php if ($ip): ?> · <?= htmlspecialchars($ip) ?><?php endif; ?></code>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($st !== 'resolved'): ?>
        <div style="margin-top:8px; text-align:right;">
          <?php if ($st === 'new'): ?>
            <button class="btn-fraud ack" onclick="fraudAct('<?= htmlspecialchars(addslashes($i['user'])) ?>','acknowledged',false)">
              <i class="fa fa-eye"></i> Reconnaître
            </button>
          <?php endif; ?>
          <button class="btn-fraud res" onclick="fraudAct('<?= htmlspecialchars(addslashes($i['user'])) ?>','resolved',false)">
            <i class="fa fa-check"></i> Résoudre
          </button>
          <button class="btn-fraud lock" onclick="fraudAct('<?= htmlspecialchars(addslashes($i['user'])) ?>','resolved',true)">
            <i class="fa fa-ban"></i> Résoudre + Déconnecter (cookies + sessions)
          </button>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>
</div>
</div>
</div>

<script>
function fraudAct(user, status, clear) {
  var fd = new FormData();
  fd.append('user', user);
  fd.append('status', status);
  fd.append('session', <?= json_encode($session ?? '') ?>);
  if (clear) fd.append('clear_cookies', '1');
  fetch('./process/anti_fraud_action.php', { method:'POST', body: fd })
    .then(function(r){ if(!r.ok) throw r; return r.text(); })
    .then(function(){ window.location.reload(); })
    .catch(function(){ alert(<?= json_encode(isset($_fraud_action_failed) ? $_fraud_action_failed : 'Action failed') ?>); });
}
</script>
