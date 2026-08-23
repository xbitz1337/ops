<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
$pdo = db();
$kategorien = $pdo->query('SELECT * FROM lager_kategorien ORDER BY sortierung ASC')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Report erstellen — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #0f1923; --navy2: #152236; --blue-mid: #1e3a5f;
    --blue-accent: #2d6aad; --blue-bright: #4a9edd;
    --text: #c8dff0; --text-dim: #5a7a9a;
    --green: #2ecc71; --orange: #e67e22; --red: #e74c3c; --terracotta:#c88b5a;
    --mono: 'Share Tech Mono', monospace; --sans: 'Exo 2', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(15,25,35,0.97); border-bottom:1px solid rgba(45,106,173,0.2); z-index:100; }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; letter-spacing:1px; }
  .back-link:hover { text-decoration:underline; }
  .topbar-title { font-size:14px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; }

  .main { max-width:640px; margin:0 auto; padding:28px 20px 60px; }
  .page-title { font-size:22px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); letter-spacing:2px; margin-top:4px; margin-bottom:24px; }

  .panel { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:20px; margin-bottom:16px; }
  .panel-title { font-family:var(--mono); font-size:11px; letter-spacing:3px; text-transform:uppercase; color:var(--blue-bright); margin-bottom:14px; display:flex; align-items:center; gap:8px; }
  .panel-title::before { content:'//'; color:var(--blue-accent); }

  .preset-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:16px; }
  .preset-btn {
    padding:12px 8px; border:1px solid rgba(45,106,173,0.25); background:rgba(15,25,35,0.6);
    color:var(--text); font-family:var(--mono); font-size:11px; letter-spacing:1px; cursor:pointer; text-align:center;
  }
  .preset-btn:hover { border-color:var(--blue-bright); }
  .preset-btn.active { background:rgba(74,158,221,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }

  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); letter-spacing:2px; text-transform:uppercase; margin-bottom:6px; display:block; }
  .field-input, .field-select {
    width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25);
    color:var(--text); font-family:var(--mono); font-size:13px; padding:10px 12px; outline:none;
    margin-bottom:14px;
  }
  .field-input:focus { border-color:var(--blue-bright); }
  .custom-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

  .btn-generate {
    width:100%; background:none; border:1px solid var(--blue-bright); color:var(--blue-bright);
    padding:14px; font-family:var(--mono); font-size:13px; letter-spacing:2px; text-transform:uppercase;
    cursor:pointer; margin-top:6px;
  }
  .btn-generate:hover { background:rgba(74,158,221,0.1); }
  .zusammenfassung { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-top:14px; text-align:center; }
  .zusammenfassung strong { color:var(--blue-bright); }
</style>
</head>
<body>

<div class="topbar">
  <a href="../dashboard.php#lager" class="back-link">← Lager</a>
  <div class="topbar-title">Report erstellen</div>
  <a href="history.php" class="back-link">Historie →</a>
</div>

<div class="main">
  <div class="page-title">Lagerreport</div>
  <div class="page-sub">// ZEITRAUM WÄHLEN — VORDEFINIERT ODER EIGEN</div>

  <div class="panel">
    <div class="panel-title">Zeitraum</div>
    <div class="preset-grid">
      <button type="button" class="preset-btn" data-preset="monat">Aktueller Monat</button>
      <button type="button" class="preset-btn" data-preset="3monate">Letzte 3 Monate</button>
      <button type="button" class="preset-btn" data-preset="6monate">Letzte 6 Monate</button>
      <button type="button" class="preset-btn" data-preset="jahr">Letzte 12 Monate</button>
      <button type="button" class="preset-btn" data-preset="kalenderjahr">Kalenderjahr <?= date('Y') ?></button>
      <button type="button" class="preset-btn active" data-preset="eigen">Eigener Zeitraum</button>
    </div>

    <div class="custom-row">
      <div>
        <label class="field-label">Von</label>
        <input type="date" class="field-input" id="von">
      </div>
      <div>
        <label class="field-label">Bis</label>
        <input type="date" class="field-input" id="bis">
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-title">Kategorie</div>
    <label class="field-label">Nur diese Kategorie einbeziehen (optional)</label>
    <select class="field-select" id="kategorie">
      <option value="">Alle Kategorien</option>
      <?php foreach ($kategorien as $k): ?>
        <option value="<?= htmlspecialchars($k['name']) ?>"><?= htmlspecialchars($k['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <button class="btn-generate" onclick="reportErstellen()">↓ Report erstellen &amp; herunterladen</button>
  <div class="zusammenfassung" id="zusammenfassung"></div>
</div>

<script>
function heute() { return new Date().toISOString().slice(0,10); }
function vorMonaten(n) {
  const d = new Date();
  d.setMonth(d.getMonth() - n);
  return d.toISOString().slice(0,10);
}

function setzePreset(preset) {
  document.querySelectorAll('.preset-btn').forEach(b => b.classList.toggle('active', b.dataset.preset === preset));
  const vonEl = document.getElementById('von');
  const bisEl = document.getElementById('bis');

  const heuteDatum = new Date();
  if (preset === 'monat') {
    vonEl.value = heuteDatum.toISOString().slice(0,8) + '01';
    bisEl.value = heute();
  } else if (preset === '3monate') {
    vonEl.value = vorMonaten(3);
    bisEl.value = heute();
  } else if (preset === '6monate') {
    vonEl.value = vorMonaten(6);
    bisEl.value = heute();
  } else if (preset === 'jahr') {
    vonEl.value = vorMonaten(12);
    bisEl.value = heute();
  } else if (preset === 'kalenderjahr') {
    vonEl.value = heuteDatum.getFullYear() + '-01-01';
    bisEl.value = heute();
  }
  aktualisiereZusammenfassung();
}

document.querySelectorAll('.preset-btn').forEach(btn => {
  btn.addEventListener('click', () => setzePreset(btn.dataset.preset));
});
document.getElementById('von').addEventListener('change', () => {
  document.querySelectorAll('.preset-btn').forEach(b => b.classList.toggle('active', b.dataset.preset === 'eigen'));
  aktualisiereZusammenfassung();
});
document.getElementById('bis').addEventListener('change', () => {
  document.querySelectorAll('.preset-btn').forEach(b => b.classList.toggle('active', b.dataset.preset === 'eigen'));
  aktualisiereZusammenfassung();
});

function aktualisiereZusammenfassung() {
  const von = document.getElementById('von').value;
  const bis = document.getElementById('bis').value;
  const el = document.getElementById('zusammenfassung');
  if (von && bis) {
    const fmt = (d) => { const [y,m,t] = d.split('-'); return `${t}.${m}.${y}`; };
    el.innerHTML = `Zeitraum: <strong>${fmt(von)} – ${fmt(bis)}</strong>`;
  } else {
    el.innerHTML = '';
  }
}

// Initial: aktueller Monat als Standard
setzePreset('monat');

function reportErstellen() {
  const von = document.getElementById('von').value;
  const bis = document.getElementById('bis').value;
  const kategorie = document.getElementById('kategorie').value;

  if (!von || !bis) { alert('Bitte Zeitraum auswählen'); return; }
  if (von > bis) { alert('"Von" muss vor "Bis" liegen'); return; }

  const params = new URLSearchParams({ von, bis });
  if (kategorie) params.set('kategorie', kategorie);

  window.location.href = 'report_pdf.php?' + params.toString();
}
</script>
</body>
</html>
