<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('umsatz');
$pdo = db();

$zeitraum = $_GET['zeitraum'] ?? '12monate';
$bis = date('Y-m-d');
$von = match ($zeitraum) {
    '3monate' => date('Y-m-d', strtotime('-3 months')),
    '6monate' => date('Y-m-d', strtotime('-6 months')),
    'jahr' => date('Y-01-01'),
    default => date('Y-m-d', strtotime('-12 months')),
};

// Umsatz je Monat (netto, aus tatsächlichen Verkäufen im Lager-Modul)
$monatlich = $pdo->prepare("
    SELECT DATE_FORMAT(bewegt_am, '%Y-%m') AS monat,
           SUM(menge * COALESCE(verkaufspreis_brutto, 0)) / 1.19 AS umsatz_netto,
           SUM(menge) AS stueck
    FROM lager_bewegungen
    WHERE typ = 'abgang_verkauf' AND bewegt_am BETWEEN :von AND :bis
    GROUP BY monat ORDER BY monat ASC
");
$monatlich->execute([':von' => $von, ':bis' => $bis]);
$monatsdaten = $monatlich->fetchAll(PDO::FETCH_ASSOC);

// Umsatz je Kanal
$je_kanal = $pdo->prepare("
    SELECT verkaufskanal, SUM(menge * COALESCE(verkaufspreis_brutto, 0)) / 1.19 AS umsatz_netto, SUM(menge) AS stueck
    FROM lager_bewegungen
    WHERE typ = 'abgang_verkauf' AND bewegt_am BETWEEN :von AND :bis
    GROUP BY verkaufskanal ORDER BY umsatz_netto DESC
");
$je_kanal->execute([':von' => $von, ':bis' => $bis]);
$kanaldaten = $je_kanal->fetchAll(PDO::FETCH_ASSOC);

// Alle Produkte im Zeitraum — mit Wareneinsatz, Rohertrag und Reingewinn,
// gruppiert nach Kategorie (CozyCore/NA Commerce/Sonstiges).
// Wareneinsatz kommt jetzt direkt aus den beim Verkauf exakt gespeicherten
// FIFO-Stückkosten je Bewegung (ek_preis_netto auf abgang_verkauf) — kein
// nachträglicher Durchschnitt mehr nötig, das ist die tatsächliche Zahl.
$alle_produkte_stmt = $pdo->prepare("
    SELECT p.id, p.name, k.name AS kategorie, k.sortierung,
           SUM(b.menge) AS stueck,
           SUM(b.menge * COALESCE(b.verkaufspreis_brutto, 0)) / 1.19 AS umsatz_netto,
           SUM(b.menge * COALESCE(b.ek_preis_netto, 0)) AS wareneinsatz
    FROM lager_bewegungen b
    JOIN lager_produkte p ON p.id = b.produkt_id
    JOIN lager_kategorien k ON k.id = p.kategorie_id
    WHERE b.typ = 'abgang_verkauf' AND b.bewegt_am BETWEEN :von AND :bis
    GROUP BY p.id, p.name, k.name, k.sortierung ORDER BY k.sortierung, umsatz_netto DESC
");
$alle_produkte_stmt->execute([':von' => $von, ':bis' => $bis]);
$alle_produkte_verkauf = $alle_produkte_stmt->fetchAll(PDO::FETCH_ASSOC);

$STEUERSATZ_UMSATZ = 0.30;
$gesamt_wareneinsatz = 0;
$gesamt_rohertrag = 0;
$gesamt_reingewinn = 0;

foreach ($alle_produkte_verkauf as &$pv) {
    $pv['rohertrag'] = $pv['umsatz_netto'] - $pv['wareneinsatz'];
    $pv['ruecklage'] = max(0, $pv['rohertrag'] * $STEUERSATZ_UMSATZ);
    $pv['reingewinn'] = $pv['rohertrag'] - $pv['ruecklage'];

    $gesamt_wareneinsatz += $pv['wareneinsatz'];
    $gesamt_rohertrag += $pv['rohertrag'];
    $gesamt_reingewinn += $pv['reingewinn'];
}
unset($pv);

$nach_kategorie_verkauf = [];
foreach ($alle_produkte_verkauf as $pv) { $nach_kategorie_verkauf[$pv['kategorie']][] = $pv; }

// KPI-Kacheln
$gesamt_umsatz = array_sum(array_column($monatsdaten, 'umsatz_netto'));
$gesamt_stueck = array_sum(array_column($monatsdaten, 'stueck'));
$anzahl_monate = max(1, count($monatsdaten));
$schnitt_pro_monat = $gesamt_umsatz / $anzahl_monate;

$max_umsatz = max(array_column($monatsdaten, 'umsatz_netto') ?: [0]);

$kanal_labels = ['amazon' => 'Amazon', 'ebay' => 'eBay', 'tiktok' => 'TikTok Shop', 'direktverkauf' => 'Direktverkauf', 'sonstiges' => 'Sonstiges'];
$kanal_farben = ['amazon' => '#e67e22', 'ebay' => '#4a9edd', 'tiktok' => '#c8c8c8', 'direktverkauf' => '#2ecc71', 'sonstiges' => '#5a7a9a'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Umsatz — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #0f1923; --navy2: #152236; --blue-mid: #1e3a5f;
    --blue-accent: #2d6aad; --blue-bright: #4a9edd;
    --text: #c8dff0; --text-dim: #5a7a9a; --green: #2ecc71; --orange: #e67e22;
    --mono: 'Share Tech Mono', monospace; --sans: 'Exo 2', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(15,25,35,0.97); border-bottom:1px solid rgba(45,106,173,0.2); z-index:100; }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; letter-spacing:1px; }
  .topbar-title { font-size:14px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; }

  .main { max-width:1200px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:24px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); letter-spacing:2px; margin-top:4px; margin-bottom:20px; }

  .zeitraum-tabs { display:flex; gap:8px; margin-bottom:24px; flex-wrap:wrap; }
  .zeitraum-tab { font-family:var(--mono); font-size:11px; letter-spacing:1px; padding:8px 16px; border:1px solid rgba(45,106,173,0.3); color:var(--text-dim); text-decoration:none; text-transform:uppercase; }
  .zeitraum-tab.active { background:rgba(74,158,221,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }

  .kpi-row { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:28px; }
  @media(max-width:700px) { .kpi-row { grid-template-columns:1fr; } }
  .kpi-card { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:20px; position:relative; overflow:hidden; }
  .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,var(--blue-accent),var(--blue-bright)); }
  .kpi-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); letter-spacing:2px; text-transform:uppercase; margin-bottom:10px; }
  .kpi-value { font-size:30px; font-weight:700; color:#e8f4ff; }
  .kpi-value.green { color:var(--green); }

  .panel { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:22px; margin-bottom:20px; }
  .panel-title { font-family:var(--mono); font-size:11px; letter-spacing:3px; text-transform:uppercase; color:var(--blue-bright); margin-bottom:20px; }

  .balken-chart { display:flex; align-items:flex-end; gap:8px; height:200px; padding-top:20px; }
  .balken-wrap { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; }
  .balken { width:100%; background:linear-gradient(180deg,var(--blue-bright),var(--blue-accent)); min-height:2px; position:relative; }
  .balken-wert { font-family:var(--mono); font-size:9px; color:var(--blue-bright); margin-bottom:4px; white-space:nowrap; }
  .balken-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-top:8px; }

  .kanal-liste { display:flex; flex-direction:column; gap:12px; }
  .kanal-zeile { display:flex; align-items:center; gap:12px; }
  .kanal-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
  .kanal-name { font-family:var(--mono); font-size:12px; width:110px; flex-shrink:0; }
  .kanal-bar-wrap { flex:1; height:8px; background:rgba(45,106,173,0.15); border-radius:4px; overflow:hidden; }
  .kanal-bar { height:100%; border-radius:4px; }
  .kanal-wert { font-family:var(--mono); font-size:11px; color:var(--text-dim); width:100px; text-align:right; flex-shrink:0; }

  .top-tabelle { width:100%; border-collapse:collapse; }
  .top-tabelle th { text-align:left; padding:8px 10px; font-family:var(--mono); font-size:9px; color:var(--text-dim); text-transform:uppercase; border-bottom:1px solid rgba(45,106,173,0.2); }
  .top-tabelle td { padding:10px; font-size:13px; border-bottom:1px solid rgba(45,106,173,0.1); }
  .top-tabelle .text-right { text-align:right; font-family:var(--mono); }
  .empty { font-family:var(--mono); font-size:11px; color:var(--text-dim); text-align:center; padding:30px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Umsatz-Übersicht</div>
  <div class="page-sub">// AUS TATSÄCHLICHEN LAGER-VERKÄUFEN — NETTO, OHNE 19% USt</div>

  <div class="zeitraum-tabs">
    <a href="?zeitraum=3monate" class="zeitraum-tab <?= $zeitraum === '3monate' ? 'active' : '' ?>">3 Monate</a>
    <a href="?zeitraum=6monate" class="zeitraum-tab <?= $zeitraum === '6monate' ? 'active' : '' ?>">6 Monate</a>
    <a href="?zeitraum=12monate" class="zeitraum-tab <?= $zeitraum === '12monate' ? 'active' : '' ?>">12 Monate</a>
    <a href="?zeitraum=jahr" class="zeitraum-tab <?= $zeitraum === 'jahr' ? 'active' : '' ?>">Kalenderjahr <?= date('Y') ?></a>
  </div>

  <div class="kpi-row" style="grid-template-columns:repeat(4,1fr);">
    <div class="kpi-card">
      <div class="kpi-label">Umsatz gesamt (netto)</div>
      <div class="kpi-value">€<?= number_format($gesamt_umsatz, 0, ',', '.') ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Wareneinsatz</div>
      <div class="kpi-value" style="color:var(--orange);">€<?= number_format($gesamt_wareneinsatz, 0, ',', '.') ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Rohertrag</div>
      <div class="kpi-value">€<?= number_format($gesamt_rohertrag, 0, ',', '.') ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Reingewinn (nach 30% Rücklage)</div>
      <div class="kpi-value green">€<?= number_format($gesamt_reingewinn, 0, ',', '.') ?></div>
    </div>
  </div>
  <style>@media(max-width:900px){ .kpi-row { grid-template-columns:1fr 1fr !important; } }</style>

  <div class="panel">
    <div class="panel-title">Umsatz je Monat</div>
    <?php if (empty($monatsdaten)): ?>
      <div class="empty">// KEINE VERKÄUFE IM ZEITRAUM</div>
    <?php else: ?>
    <div class="balken-chart">
      <?php foreach ($monatsdaten as $m):
          $hoehe = $max_umsatz > 0 ? max(2, ($m['umsatz_netto'] / $max_umsatz) * 160) : 2;
          $monat_label = date('M Y', strtotime($m['monat'] . '-01'));
      ?>
      <div class="balken-wrap">
        <div class="balken-wert">€<?= number_format($m['umsatz_netto'], 0, ',', '.') ?></div>
        <div class="balken" style="height:<?= $hoehe ?>px;"></div>
        <div class="balken-label"><?= $monat_label ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-title">Umsatz je Kanal</div>
    <?php if (empty($kanaldaten)): ?>
      <div class="empty">// KEINE VERKÄUFE IM ZEITRAUM</div>
    <?php else: ?>
    <div class="kanal-liste">
      <?php foreach ($kanaldaten as $k):
          $pct = $gesamt_umsatz > 0 ? ($k['umsatz_netto'] / $gesamt_umsatz) * 100 : 0;
          $farbe = $kanal_farben[$k['verkaufskanal']] ?? '#5a7a9a';
      ?>
      <div class="kanal-zeile">
        <div class="kanal-dot" style="background:<?= $farbe ?>;"></div>
        <div class="kanal-name"><?= $kanal_labels[$k['verkaufskanal']] ?? $k['verkaufskanal'] ?></div>
        <div class="kanal-bar-wrap"><div class="kanal-bar" style="width:<?= $pct ?>%; background:<?= $farbe ?>;"></div></div>
        <div class="kanal-wert">€<?= number_format($k['umsatz_netto'], 0, ',', '.') ?> (<?= round($pct) ?>%)</div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-title">Produkte im Zeitraum — Umsatz &amp; Gewinn</div>
    <?php if (empty($nach_kategorie_verkauf)): ?>
      <div class="empty">// KEINE VERKÄUFE IM ZEITRAUM</div>
    <?php else: ?>
      <?php foreach ($nach_kategorie_verkauf as $kategorie => $items): ?>
      <div style="font-family:var(--mono); font-size:10px; letter-spacing:2px; text-transform:uppercase; color:var(--blue-bright); margin:16px 0 8px; padding-bottom:6px; border-bottom:1px dashed rgba(45,106,173,0.2);"><?= htmlspecialchars($kategorie) ?></div>
      <table class="top-tabelle">
        <thead>
          <tr><th>Produkt</th><th class="text-right">Stk</th><th class="text-right">Umsatz</th><th class="text-right">Wareneinsatz</th><th class="text-right">Rohertrag</th><th class="text-right">Reingewinn</th></tr>
        </thead>
        <tbody>
          <?php foreach ($items as $t): ?>
          <tr>
            <td><?= htmlspecialchars($t['name']) ?></td>
            <td class="text-right"><?= $t['stueck'] ?></td>
            <td class="text-right">€<?= number_format($t['umsatz_netto'], 2, ',', '.') ?></td>
            <td class="text-right" style="color:var(--orange);">€<?= number_format($t['wareneinsatz'], 2, ',', '.') ?></td>
            <td class="text-right">€<?= number_format($t['rohertrag'], 2, ',', '.') ?></td>
            <td class="text-right" style="color:var(--green); font-weight:700;">€<?= number_format($t['reingewinn'], 2, ',', '.') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endforeach; ?>
      <div style="font-family:var(--mono); font-size:8px; color:var(--text-dim); margin-top:12px;">
        Reingewinn = Umsatz netto − Wareneinsatz (Ø-EK) − 30% Steuerrücklage. Näherungsrechnung, keine exakte FIFO-Zuordnung.
      </div>
    <?php endif; ?>
  </div>
</div>

</div>
</body>
</html>
