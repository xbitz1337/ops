<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Lager-Modul v2 — Report-Historie
 * Automatische Reports (die verlässliche Jahresbasis) stehen geschützt in
 * einem eigenen Bereich ohne Löschmöglichkeit. Manuelle Reports stehen separat
 * und können im Löschmodus entfernt werden.
 */

require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
$pdo = db();

$stmt = $pdo->query("
    SELECT id, stichtag, zeitraum_von, zeitraum_bis, kategorie_filter, dateiname, dateipfad,
           gesamt_ek_wert, erzeugt_durch, erstellt_am
    FROM lager_reports
    ORDER BY stichtag DESC, erstellt_am DESC
");
$alle = $stmt->fetchAll(PDO::FETCH_ASSOC);
$automatisch = array_values(array_filter($alle, fn($r) => $r['erzeugt_durch'] === 'automatisch'));
$manuell = array_values(array_filter($alle, fn($r) => $r['erzeugt_durch'] === 'manuell'));

function render_report_row($r, $mit_delete) {
    $von = $r['zeitraum_von'] ? date('d.m.Y', strtotime($r['zeitraum_von'])) : '?';
    $bis = date('d.m.Y', strtotime($r['zeitraum_bis'] ?? $r['stichtag']));
    $kategorie = $r['kategorie_filter'] ? htmlspecialchars($r['kategorie_filter']) : 'Gesamt';
    $ek = number_format($r['gesamt_ek_wert'], 2, ',', '.');
    $erstellt = date('d.m.Y, H:i', strtotime($r['erstellt_am']));
    echo "<tr id=\"report-row-{$r['id']}\">";
    echo "<td data-label=\"Zeitraum\">{$von} – {$bis}</td>";
    echo "<td data-label=\"Kategorie\">" . ($r['kategorie_filter'] ? "<span class=\"kategorie-tag\">{$kategorie}</span>" : "Gesamt") . "</td>";
    echo "<td class=\"ek-wert\" data-label=\"EK-Wert Bestand\">{$ek} €</td>";
    echo "<td data-label=\"Generiert am\">{$erstellt}</td>";
    echo "<td data-label=\"\">";
    echo "<a class=\"aktion ansehen\" href=\"report_view.php?id={$r['id']}\" target=\"_blank\">Ansehen</a>";
    echo "<a class=\"aktion download\" href=\"download_report.php?id={$r['id']}\">Download</a>";
    if ($mit_delete) {
        echo "<button class=\"delete-btn\" onclick=\"reportLoeschen({$r['id']})\">Löschen</button>";
    }
    echo "</td></tr>";
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lagerreport-Historie · NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/theme.css">
<style>
    .container { max-width:1000px; margin:0 auto; padding:28px 20px 60px; }
    .top-row { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
    .top-links { display:flex; gap:16px; align-items:center; }
    .back-link { color:var(--accent); text-decoration:none; font-size:13px; font-weight:500; }
    .back-link:hover { text-decoration:underline; }

    section { margin-bottom: 36px; }
    .section-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:10px; }
    .section-title { font-size:16px; font-weight:700; display:flex; align-items:center; gap:8px; color:var(--text-bright); }
    .protected-badge { display:inline-flex; align-items:center; gap:4px; background:var(--green-soft); color:var(--green); font-size:11px; font-weight:600; padding:3px 10px; border-radius:10px; }
    .section-sub { font-size:12px; color:var(--text-dim); }

    .loeschmodus-btn {
        background:var(--card2); border:1px solid var(--line); color:var(--red); padding:9px 16px; border-radius:10px;
        font-size:13px; font-weight:600; cursor:pointer; font-family:var(--font);
    }
    .loeschmodus-btn.aktiv { background:var(--red); color:#fff; border-color:var(--red); }

    table { width: 100%; background: var(--card); border-collapse: collapse; border-radius: 16px; overflow: hidden; border:1px solid var(--line); }
    th { background: var(--card2); text-align: left; padding: 12px 16px; font-size: 11px; color: var(--text-dim); border-bottom: 1px solid var(--line); }
    td { padding: 12px 16px; border-bottom: 1px solid var(--line); font-size: 13px; }
    tr:last-child td { border-bottom: none; }
    .kategorie-tag { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; background: var(--orange-soft); color: var(--orange); }
    .ek-wert { font-weight: 600; }
    .aktion { color: var(--accent); text-decoration: none; font-weight: 500; margin-right:14px; font-size:12.5px; }
    .aktion:hover { text-decoration: underline; }
    .delete-btn { display:none; background:none; border:1px solid var(--red); color:var(--red); padding:5px 10px; border-radius:8px; font-size:11px; font-weight:600; cursor:pointer; }
    .delete-btn.sichtbar { display:inline-block; }
    .empty-hint { text-align:center; color:var(--text-dim); padding:32px; background:var(--card); border:1px solid var(--line); border-radius:16px; font-size:13px; }

    .automatisch-panel { background:var(--card); border:1px solid var(--line); border-radius:16px; overflow:hidden; border-left: 3px solid var(--green); }

    @media(max-width:600px) {
      .container { padding:16px 14px 50px; }
      table, thead, tbody, th, td, tr { display:block; }
      thead { display:none; }
      tr { background:var(--card2); margin-bottom:10px; border-radius:14px; padding:10px 14px; }
      td { border:none; padding:4px 0; }
      td:before { content: attr(data-label); display:block; font-size:10px; color:var(--text-dim); }
    }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="container">
<div class="top-row">
  <div>
    <div class="page-title">Lagerreport-Historie</div>
    <div class="page-sub">Automatische Reports sind geschützt · manuelle Reports könnt ihr bei Bedarf löschen</div>
  </div>
  <div class="top-links">
    <a href="report_new.php" class="back-link">+ Neuer Report</a>
  </div>
</div>

<section>
  <div class="section-header">
    <div class="section-title">🔒 Automatische Reports <span class="protected-badge">✓ Geschützt</span></div>
    <div class="section-sub">Am 1. jeden Monats automatisch erzeugt — bildet eure verlässliche Jahresbasis, kann nicht gelöscht werden</div>
  </div>
  <?php if (empty($automatisch)): ?>
    <div class="empty-hint">Noch keine automatischen Reports vorhanden — der erste entsteht am 1. des nächsten Monats.</div>
  <?php else: ?>
  <div class="automatisch-panel">
    <table>
      <thead><tr><th>Zeitraum</th><th>Kategorie</th><th>EK-Wert Bestand</th><th>Generiert am</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($automatisch as $r) render_report_row($r, false); ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section>
  <div class="section-header">
    <div class="section-title">📄 Manuelle Reports</div>
    <button class="loeschmodus-btn" id="loeschmodus-toggle" onclick="toggleLoeschmodus()">🔓 Löschmodus</button>
  </div>
  <?php if (empty($manuell)): ?>
    <div class="empty-hint">Noch keine manuellen Reports erstellt.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>Zeitraum</th><th>Kategorie</th><th>EK-Wert Bestand</th><th>Generiert am</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($manuell as $r) render_report_row($r, true); ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
</div>
</div>

<script>
let loeschmodusAktiv = false;

function toggleLoeschmodus() {
  loeschmodusAktiv = !loeschmodusAktiv;
  const btn = document.getElementById('loeschmodus-toggle');
  btn.classList.toggle('aktiv', loeschmodusAktiv);
  btn.textContent = loeschmodusAktiv ? '🔒 Löschmodus beenden' : '🔓 Löschmodus';
  document.querySelectorAll('.delete-btn').forEach(b => b.classList.toggle('sichtbar', loeschmodusAktiv));
}

async function reportLoeschen(id) {
  if (!confirm('Diesen Report wirklich unwiderruflich löschen? Die PDF-Datei wird ebenfalls entfernt.')) return;

  const fd = new FormData();
  fd.append('id', id);
  const res = await fetch('lager_report_delete.php', { method: 'POST', body: fd });
  const data = await res.json();

  if (data.error) { alert('Fehler: ' + data.error); return; }
  document.getElementById('report-row-' + id)?.remove();
}
</script>

</body>
</html>
