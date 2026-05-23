<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 */
session_start();
error_reporting(0);
include_once(__DIR__ . '/app_update.php');

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
}

$buildInfo = mikhmon_update_build_info();
?>
<style>
.about-update-hero {
  display:grid;
  grid-template-columns:minmax(0, 1.2fr) minmax(260px, .8fr);
  gap:16px;
  align-items:stretch;
}
.about-update-card {
  border:1px solid #d7deea;
  border-radius:8px;
  background:#fff;
  padding:16px;
  color:#243447;
}
.about-update-card h4 {
  margin:0 0 10px;
  font-size:16px;
  color:#243447;
}
.about-update-card p {
  margin:0 0 10px;
  line-height:1.55;
}
.mikhmon-update-panel {
  border:1px solid #d7deea;
  border-left:4px solid #27ae60;
  border-radius:8px;
  background:#f8fafc;
  padding:14px;
  color:#243447;
}
.mikhmon-update-panel.update-available {
  border-left-color:#f39c12;
  background:#fff8e1;
}
.mikhmon-update-status {
  display:flex;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
  margin-bottom:12px;
}
.mikhmon-update-status span {
  font-weight:bold;
  font-size:15px;
}
.mikhmon-update-status small {
  color:#64748b;
}
.mikhmon-update-meta {
  display:grid;
  grid-template-columns:repeat(3, minmax(0, 1fr));
  gap:10px;
  margin-bottom:12px;
}
.mikhmon-update-meta div {
  background:#fff;
  border:1px solid #e2e8f0;
  border-radius:6px;
  padding:10px;
}
.mikhmon-update-meta b,
.mikhmon-update-command b {
  display:block;
  font-size:11px;
  color:#64748b;
  text-transform:uppercase;
  margin-bottom:5px;
}
.mikhmon-update-meta span {
  display:block;
  font-weight:bold;
  overflow-wrap:anywhere;
}
.mikhmon-update-command {
  background:#243447;
  color:#fff;
  border-radius:6px;
  padding:10px;
}
.mikhmon-update-command b {
  color:#cbd5e1;
}
.mikhmon-update-command code {
  display:block;
  color:#fff;
  white-space:normal;
  overflow-wrap:anywhere;
}
.about-update-steps {
  counter-reset:update-step;
  display:grid;
  gap:10px;
  margin:0;
  padding:0;
  list-style:none;
}
.about-update-steps li {
  counter-increment:update-step;
  display:grid;
  grid-template-columns:38px minmax(0, 1fr);
  gap:10px;
  padding:12px;
  border:1px solid #d7deea;
  border-radius:8px;
  background:#fff;
}
.about-update-steps li:before {
  content:counter(update-step);
  display:flex;
  align-items:center;
  justify-content:center;
  width:32px;
  height:32px;
  border-radius:50%;
  background:#2980b9;
  color:#fff;
  font-weight:bold;
}
.about-update-steps b {
  display:block;
  margin-bottom:3px;
  color:#243447;
}
.about-update-steps span {
  display:block;
  color:#64748b;
  line-height:1.45;
}
.about-update-actions {
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  margin-top:14px;
}
@media screen and (max-width:749px) {
  .about-update-hero,
  .mikhmon-update-meta {
    grid-template-columns:minmax(0, 1fr);
  }
  .about-update-card {
    padding:14px;
  }
  .about-update-actions .btn {
    width:100%;
    text-align:center;
  }
}
</style>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-info-circle"></i> SafeLinkHub Mikhmon</h3>
      </div>
      <div class="card-body">
        <div class="about-update-hero">
          <div class="about-update-card">
            <h4><i class="fa fa-code-fork"></i> Ma chaine de mise a jour</h4>
            <p>
              Cette installation Mikhmon est reliee au depot GitHub
              <b>iamabdoulatif/mikhmonv3-safelinkhub</b>. Chaque correction validee depuis le localhost est poussee sur GitHub,
              puis GitHub Actions construit les images RouterOS et les publie sur DockerHub.
            </p>
            <p>
              Les containers MikroTik distants peuvent ensuite comparer leur build local avec l'image
              <b>latif225/mikhmonv3-safelinkhub:latest</b> et signaler ici quand une nouvelle version est disponible.
            </p>
            <div class="about-update-actions">
              <a class="btn bg-primary" href="https://github.com/iamabdoulatif/mikhmonv3-safelinkhub" target="_blank" rel="noopener">
                <i class="fa fa-github"></i> GitHub
              </a>
              <a class="btn" style="background:#34495e;color:#fff;" href="https://hub.docker.com/r/latif225/mikhmonv3-safelinkhub" target="_blank" rel="noopener">
                <i class="fa fa-cube"></i> DockerHub
              </a>
            </div>
          </div>
          <div>
            <?= mikhmon_render_update_panel(); ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-refresh"></i> Etapes de mes mises a jour construites</h3>
      </div>
      <div class="card-body">
        <ol class="about-update-steps">
          <li>
            <div>
              <b>Modification locale dans MAMP</b>
              <span>Je corrige l'application dans <code>/Applications/MAMP/htdocs/mikhmon</code>, puis je valide avec les tests PHP du dossier <code>tests/</code>.</span>
            </div>
          </li>
          <li>
            <div>
              <b>Publication GitHub</b>
              <span>Le commit est pousse sur la branche <code>main</code> du depot GitHub. Ce push declenche automatiquement le workflow DockerHub.</span>
            </div>
          </li>
          <li>
            <div>
              <b>Construction DockerHub</b>
              <span>GitHub Actions construit les images aplaties MikroTik <code>arm32</code>, <code>armv7</code> et <code>arm64</code>, puis met a jour le tag <code>latest</code>.</span>
            </div>
          </li>
          <li>
            <div>
              <b>Signalement sur les hosts distants</b>
              <span>La page About interroge DockerHub, compare la date de publication avec le build local et affiche l'etat de mise a jour.</span>
            </div>
          </li>
          <li>
            <div>
              <b>Installation sur MikroTik</b>
              <span>Quand une mise a jour est disponible, utiliser la commande RouterOS <code>/container</code> affichee dans le bloc ci-dessus, puis redemarrer le container Mikhmon.</span>
            </div>
          </li>
        </ol>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-certificate"></i> Informations</h3>
      </div>
      <div class="card-body">
        <h3>MIKHMON V<?= htmlspecialchars(isset($_SESSION['v']) ? $_SESSION['v'] : $buildInfo['version']); ?></h3>
        <ul>
          <li>Adaptation : SafeLinkHub / SafeLink Africa</li>
          <li>Image DockerHub : <code><?= htmlspecialchars($buildInfo['image']); ?></code></li>
          <li>Build : <code><?= htmlspecialchars($buildInfo['stamp']); ?></code></li>
          <li>Base originale : Laksamadi Guko, licence GPLv2</li>
          <li>API Class : <a href="https://github.com/BenMenking/routeros-api" target="_blank" rel="noopener">routeros-api</a></li>
        </ul>
      </div>
    </div>
  </div>
</div>
