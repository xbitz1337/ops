<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('dokumente');
$pdo = db();
$kann_bearbeiten = hat_modul_zugriff('dokumente', 'bearbeiten');

$belege = $pdo->query("SELECT * FROM eigenbelege ORDER BY datum DESC, erstellt_am DESC")->fetchAll(PDO::FETCH_ASSOC);
$gesamt_summe = array_sum(array_column($belege, 'gesamtbetrag'));
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Eigenbeleg-Historie — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --orange: #FBBF24; --red:#F87171;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .main { max-width:900px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; color:#F5F6FA; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-bottom:20px; }

  .summe-box { background:rgba(251,191,36,0.1); border:1px solid rgba(251,191,36,0.3); padding:16px 20px; margin-bottom:20px; font-family:var(--mono); }
  .summe-box .label { font-size:9px; color:var(--text-dim); }
  .summe-box .wert { font-size:22px; font-weight:700; color:var(--orange); }

  .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
  .btn { font-family:var(--mono); font-size:10px; padding:9px 16px; cursor:pointer; border:1px solid; background:none; text-decoration:none; display:inline-block; }
  .btn-primary { color:var(--green); border-color:rgba(74,222,128,0.4); }
  .loeschmodus-btn { color:var(--red); border-color:rgba(248,113,113,0.3); }
  .loeschmodus-btn.aktiv { background:var(--red); color:#fff; }

  table { width:100%; border-collapse:collapse; background:rgba(29,32,48,0.6); border:1px solid rgba(124,124,255,0.2); }
  th { background:rgba(20,22,31,0.8); text-align:left; padding:10px 12px; font-family:var(--mono); font-size:9px; color:var(--text-dim); border-bottom:1px solid rgba(124,124,255,0.2); }
  td { padding:10px 12px; font-size:12px; border-bottom:1px solid rgba(124,124,255,0.1); }
  tr:last-child td { border-bottom:none; }
  .betrag { font-family:var(--mono); color:var(--green); font-weight:700; }
  .aktion { color:var(--blue-bright); text-decoration:none; font-size:11px; margin-right:12px; }
  .aktion:hover { text-decoration:underline; }
  .delete-btn { display:none; background:none; border:1px solid rgba(248,113,113,0.3); color:var(--red); font-family:var(--mono); font-size:9px; padding:4px 9px; cursor:pointer; }
  .delete-btn.sichtbar { display:inline-block; }
  .empty { text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); padding:30px; }

  @media(max-width:700px) {
    table, thead, tbody, th, td, tr { display:block; }
    thead { display:none; }
    tr { background:rgba(29,32,48,0.8); margin-bottom:8px; padding:10px 12px; border:1px solid rgba(124,124,255,0.2); }
    td { border:none; padding:3px 0; }
    td:before { content: attr(data-label); font-family:var(--mono); font-size:8px; color:var(--text-dim); display:block; }
  }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Eigenbeleg-Historie</div>
  <div class="page-sub">Fahrtkosten-Belege</div>

  <div class="summe-box">
    <div class="label">Gesamtsumme aller Belege</div>
    <div class="wert">€<?= number_format($gesamt_summe, 2, ',', '.') ?></div>
  </div>

  <div class="toolbar">
    <a href="eigenbeleg.php" class="btn btn-primary">+ NEUER EIGENBELEG</a>
    <?php if ($kann_bearbeiten): ?>
      <button class="btn loeschmodus-btn" id="loeschmodus-toggle" onclick="toggleLoeschmodus()">🔓 LÖSCHMODUS</button>
    <?php endif; ?>
  </div>

  <?php if (empty($belege)): ?>
    <div class="empty">Noch keine Eigenbelege erstellt</div>
  <?php else: ?>
  <table>
    <thead>
      <tr><th>Beleg-Nr.</th><th>Datum</th><th>Fahrer</th><th>Route</th><th>km</th><th>Betrag</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($belege as $b): ?>
      <tr id="beleg-<?= $b['id'] ?>">
        <td data-label="Beleg-Nr."><?= htmlspecialchars($b['belegnummer']) ?></td>
        <td data-label="Datum"><?= date('d.m.Y', strtotime($b['datum'])) ?></td>
        <td data-label="Fahrer"><?= htmlspecialchars($b['fahrer']) ?></td>
        <td data-label="Route"><?= htmlspecialchars($b['route']) ?></td>
        <td data-label="km"><?= number_format($b['strecke_km'], 1, ',', '.') ?></td>
        <td data-label="Betrag" class="betrag">€<?= number_format($b['gesamtbetrag'], 2, ',', '.') ?></td>
        <td data-label="">
          <a class="aktion" href="eigenbeleg_pdf_ansehen.php?id=<?= $b['id'] ?>" target="_blank">Ansehen</a>
          <a class="aktion" href="eigenbeleg_download.php?id=<?= $b['id'] ?>">Download</a>
          <button class="delete-btn" onclick="belegLoeschen(<?= $b['id'] ?>)">✕ Löschen</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<script>
let loeschmodusAktiv = false;
function toggleLoeschmodus() {
  loeschmodusAktiv = !loeschmodusAktiv;
  const btn = document.getElementById('loeschmodus-toggle');
  btn.classList.toggle('aktiv', loeschmodusAktiv);
  btn.textContent = loeschmodusAktiv ? '🔒 LÖSCHMODUS BEENDEN' : '🔓 LÖSCHMODUS';
  document.querySelectorAll('.delete-btn').forEach(b => b.classList.toggle('sichtbar', loeschmodusAktiv));
}

async function belegLoeschen(id) {
  if (!confirm('Diesen Eigenbeleg wirklich unwiderruflich löschen?')) return;
  const fd = new FormData();
  fd.append('api', 1);
  fd.append('action', 'beleg_loeschen');
  fd.append('id', id);
  const res = await fetch('eigenbeleg.php', { method: 'POST', body: fd });
  const daten = await res.json();
  if (daten.error) { alert(daten.error); return; }
  document.getElementById('beleg-' + id)?.remove();
}
</script>
</div>
</body>
</html>
