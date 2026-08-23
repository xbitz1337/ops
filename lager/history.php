<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Lager-Modul v2 — Report-Historie
 * Automatische Reports (die verlässliche Jahresbasis) stehen geschützt in
 * einem eigenen Bereich ohne Löschmöglichkeit. Manuelle Reports stehen separat
 * und können im Löschmodus entfernt werden.
 */

require_once __DIR__ . '/../config.php';
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
<style>
    body { font-family: -apple-system, 'Helvetica Neue', Arial, sans-serif; background: #F5F5F7; margin: 0; padding: 32px; color: #1D1D1F; }
    .topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
    h1 { font-size: 22px; margin-bottom: 4px; }
    .sub { color: #86868B; font-size: 13px; }
    .back-link { color:#0071E3; text-decoration:none; font-size:13px; font-weight:500; }
    .back-link:hover { text-decoration:underline; }

    section { margin-bottom: 36px; }
    .section-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:10px; }
    .section-title { font-size:16px; font-weight:700; display:flex; align-items:center; gap:8px; }
    .protected-badge { display:inline-flex; align-items:center; gap:4px; background:#E8F5E9; color:#2E7D32; font-size:11px; font-weight:600; padding:3px 10px; border-radius:10px; }
    .section-sub { font-size:12px; color:#86868B; }

    .loeschmodus-btn {
        background:#fff; border:1px solid #E5E5E7; color:#D70015; padding:9px 16px; border-radius:8px;
        font-size:13px; font-weight:600; cursor:pointer;
    }
    .loeschmodus-btn.aktiv { background:#D70015; color:#fff; border-color:#D70015; }

    table { width: 100%; background: #fff; border-collapse: collapse; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    th { background: #FAFAFA; text-align: left; padding: 12px 16px; font-size: 11px; text-transform: uppercase; color: #86868B; border-bottom: 1px solid #EEE; }
    td { padding: 12px 16px; border-bottom: 1px solid #F2F2F2; font-size: 13px; }
    tr:last-child td { border-bottom: none; }
    .kategorie-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; background: #FBF6EF; color: #A85C32; }
    .ek-wert { font-weight: 600; }
    .aktion { color: #0071E3; text-decoration: none; font-weight: 500; margin-right:14px; font-size:12.5px; }
    .aktion:hover { text-decoration: underline; }
    .delete-btn { display:none; background:none; border:1px solid #FFCDD2; color:#D70015; padding:5px 10px; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer; }
    .delete-btn.sichtbar { display:inline-block; }
    .empty-hint { text-align:center; color:#86868B; padding:32px; background:#fff; border-radius:10px; font-size:13px; }

    .automatisch-panel { background:#fff; border-radius:10px; overflow:hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 3px solid #2E7D32; }

    @media(max-width:600px) {
      body { padding:16px; }
      table, thead, tbody, th, td, tr { display:block; }
      thead { display:none; }
      tr { background:#fff; margin-bottom:10px; border-radius:10px; padding:10px 14px; box-shadow:0 1px 3px rgba(0,0,0,0.06); }
      td { border:none; padding:4px 0; }
      td:before { content: attr(data-label); display:block; font-size:9px; color:#86868B; text-transform:uppercase; }
    }
</style>
</head>
<body>

<div class="topbar">
  <div>
    <h1>Lagerreport-Historie</h1>
    <div class="sub">Automatische Reports sind geschützt · manuelle Reports könnt ihr bei Bedarf löschen</div>
  </div>
  <div style="display:flex; gap:10px; align-items:center;">
    <a href="../dashboard.php#lager" class="back-link">← Lager</a>
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
