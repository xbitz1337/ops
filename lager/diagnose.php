<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('lager');
$pdo = db();

$produkte = $pdo->query("SELECT id, name FROM lager_produkte ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$produkt_id = (int)($_GET['produkt_id'] ?? 0);

$bewegungen = [];
$berechnung = null;

if ($produkt_id) {
    require_once __DIR__ . '/fifo_helper.php';

    $stmt = $pdo->prepare("
        SELECT * FROM lager_bewegungen
        WHERE produkt_id = ?
        ORDER BY bewegt_am ASC, erstellt_am ASC, id ASC
    ");
    $stmt->execute([$produkt_id]);
    $bewegungen = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Nur zur Anzeige "Bestand danach" pro Zeile — reine Mengen-Bewegung,
    // unabhängig von der FIFO-Bewertung
    $laufender_bestand = 0;
    foreach ($bewegungen as &$b) {
        $delta = 0;
        if ($b['typ'] === 'zugang_einkauf') {
            $delta = $b['menge'];
        } elseif ($b['typ'] === 'retoure' && $b['wieder_verkaufsfaehig']) {
            $delta = $b['menge'];
        } elseif ($b['typ'] === 'korrektur') {
            $delta = $b['menge'];
        } elseif (in_array($b['typ'], ['abgang_verkauf', 'abgang_eigenbedarf'])) {
            $delta = -$b['menge'];
        }
        $laufender_bestand += $delta;
        $b['delta'] = $delta;
        $b['bestand_danach'] = $laufender_bestand;
    }
    unset($b);

    // Die tatsächliche Bewertung — GENAU dieselbe Funktion, die auch das
    // Dashboard/die Reports nutzen, damit hier garantiert derselbe Wert steht
    $stmt2 = $pdo->prepare("SELECT ek_preis_netto FROM lager_produkte WHERE id = ?");
    $stmt2->execute([$produkt_id]);
    $produkt_ek_fallback = (float)($stmt2->fetchColumn() ?: 0);

    $fifo = fifo_lagerwert_berechnen($pdo, $produkt_id, null, $produkt_ek_fallback);
    $berechnung = [
        'bestand' => $fifo['bestand'],
        'ek_avg' => $fifo['ek_avg'],
        'ek_wert_bestand' => $fifo['lagerwert'],
        'zugang_summe_menge' => array_sum(array_column(array_filter($bewegungen, fn($b) => $b['typ'] === 'zugang_einkauf' && $b['ek_preis_netto'] > 0), 'menge')),
        'zugang_summe_wert' => array_sum(array_map(fn($b) => $b['menge'] * $b['ek_preis_netto'], array_filter($bewegungen, fn($b) => $b['typ'] === 'zugang_einkauf' && $b['ek_preis_netto'] > 0))),
    ];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lager-Diagnose — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --orange: #FBBF24; --red:#F87171;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }
  .main { max-width:1000px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; color:#F5F6FA; margin-bottom:20px; }
  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; margin-bottom:16px; border-radius:20px; }
  .field-select { width:100%; max-width:400px; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:10px 12px; outline:none; border-radius:12px; }

  .kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:16px; }
  .kpi-card { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:14px; border-radius:18px; }
  .kpi-label { font-family:var(--mono); font-size:8px; color:var(--text-dim); margin-bottom:6px; }
  .kpi-value { font-size:18px; font-weight:700; color:#F5F6FA; }

  table { width:100%; border-collapse:collapse; font-size:11px; }
  th { text-align:left; padding:8px; font-family:var(--mono); font-size:8px; color:var(--text-dim); border-bottom:1px solid rgba(124,124,255,0.15); position:sticky; top:0; background:var(--navy2); }
  td { padding:8px; border-bottom:1px solid rgba(124,124,255,0.08); font-family:var(--mono); }
  .typ-zugang { color:var(--green); }
  .typ-abgang { color:var(--red); }
  .typ-sonst { color:var(--text-dim); }
  .null-preis { color:var(--orange); font-weight:700; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Lager-Diagnose</div>

  <div class="panel">
    <form method="GET">
      <select class="field-select" name="produkt_id" onchange="this.form.submit()">
        <option value="">— Produkt wählen —</option>
        <?php foreach ($produkte as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $produkt_id === $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if ($berechnung): ?>
  <div class="kpi-row">
    <div class="kpi-card"><div class="kpi-label">Bestand (Stück)</div><div class="kpi-value"><?= $berechnung['bestand'] ?></div></div>
    <div class="kpi-card"><div class="kpi-label">Ø EK/Stk</div><div class="kpi-value">€<?= number_format($berechnung['ek_avg'],2,',','.') ?></div></div>
    <div class="kpi-card"><div class="kpi-label">EK-Wert Bestand</div><div class="kpi-value" style="color:var(--green);">€<?= number_format($berechnung['ek_wert_bestand'],2,',','.') ?></div></div>
    <div class="kpi-card"><div class="kpi-label">Zugang gesamt (für Ø-EK berücksichtigt)</div><div class="kpi-value" style="font-size:13px;"><?= $berechnung['zugang_summe_menge'] ?> Stk / €<?= number_format($berechnung['zugang_summe_wert'],2,',','.') ?></div></div>
  </div>

  <div class="panel">
    <div style="font-family:var(--mono); font-size:9px; color:var(--orange); margin-bottom:12px;">⚠️ Orange markierte Zeilen = EK-Preis fehlt oder ist 0 — diese zählen NICHT in den Ø-EK-Preis rein (können die Ursache für einen falschen Wert sein).</div>
    <div style="max-height:600px; overflow-y:auto;">
    <table>
      <thead>
        <tr><th>Datum</th><th>Typ</th><th>Menge</th><th>EK-Preis (Zugang)</th><th>Delta</th><th>Bestand danach</th><th>Notiz</th></tr>
      </thead>
      <tbody>
        <?php foreach ($bewegungen as $b): ?>
        <tr>
          <td><?= date('d.m.Y', strtotime($b['bewegt_am'])) ?></td>
          <td class="<?= str_contains($b['typ'],'zugang') || ($b['typ']==='retoure') ? 'typ-zugang' : (str_contains($b['typ'],'abgang') ? 'typ-abgang' : 'typ-sonst') ?>"><?= $b['typ'] ?></td>
          <td><?= $b['menge'] ?></td>
          <td class="<?= ($b['typ']==='zugang_einkauf' && (!$b['ek_preis_netto'] || $b['ek_preis_netto'] <= 0)) ? 'null-preis' : '' ?>">
            <?= $b['ek_preis_netto'] !== null ? '€' . number_format($b['ek_preis_netto'],2,',','.') : '—' ?>
          </td>
          <td><?= $b['delta'] > 0 ? '+' : '' ?><?= $b['delta'] ?></td>
          <td><?= $b['bestand_danach'] ?></td>
          <td style="font-family:var(--sans); color:var(--text-dim);"><?= htmlspecialchars($b['notiz'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>
</div>

</div>
</body>
</html>
