<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('steuer');
$pdo = db();

const STEUERSATZ = 0.30; // GmbH: KSt 15% + Soli ~0,8% + GewSt (Hebesatz-abhängig) ≈ 30%

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'einzahlung_add') {
        $betrag = (float)($_POST['betrag'] ?? 0);
        $datum = $_POST['datum'] ?? date('Y-m-d');
        $notiz = trim($_POST['notiz'] ?? '');
        if ($betrag <= 0) { echo json_encode(['error' => 'Betrag muss größer 0 sein']); exit; }
        $pdo->prepare("INSERT INTO steuerruecklage_einzahlungen (betrag, datum, notiz) VALUES (?, ?, ?)")
            ->execute([$betrag, $datum, $notiz]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'einzahlung_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM steuerruecklage_einzahlungen WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

// Geschätzter Rohertrag im laufenden Kalenderjahr (Umsatz netto - Wareneinsatz)
// Wareneinsatz kommt jetzt direkt aus den beim Verkauf exakt gespeicherten
// FIFO-Stückkosten je Bewegung — kein Durchschnitt mehr nötig.
$jahr_von = date('Y-01-01');
$jahr_bis = date('Y-m-d');

$verkaeufe = $pdo->prepare("
    SELECT SUM(b.menge * COALESCE(b.verkaufspreis_brutto, 0)) / 1.19 AS umsatz_netto,
           SUM(b.menge * COALESCE(b.ek_preis_netto, 0)) AS wareneinsatz
    FROM lager_bewegungen b
    JOIN lager_produkte p ON p.id = b.produkt_id
    WHERE b.typ = 'abgang_verkauf' AND b.bewegt_am BETWEEN :von AND :bis
");
$verkaeufe->execute([':von' => $jahr_von, ':bis' => $jahr_bis]);
$row = $verkaeufe->fetch(PDO::FETCH_ASSOC);
$rohertrag_gesamt = ($row['umsatz_netto'] ?? 0) - ($row['wareneinsatz'] ?? 0);

$geschaetzte_ruecklage = max(0, $rohertrag_gesamt * STEUERSATZ);

// Tatsächlich zurückgelegt (alle Einzahlungen im laufenden Jahr)
$einzahlungen = $pdo->query("SELECT * FROM steuerruecklage_einzahlungen WHERE YEAR(datum) = YEAR(CURDATE()) ORDER BY datum DESC")->fetchAll(PDO::FETCH_ASSOC);
$tatsaechlich_zurueckgelegt = array_sum(array_column($einzahlungen, 'betrag'));

$differenz = $geschaetzte_ruecklage - $tatsaechlich_zurueckgelegt;
$fortschritt_pct = $geschaetzte_ruecklage > 0 ? min(100, ($tatsaechlich_zurueckgelegt / $geschaetzte_ruecklage) * 100) : 100;
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Steuerrücklage — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --orange: #FBBF24; --red: #F87171;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }

  .main { max-width:700px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:22px; font-weight:700; color:#F5F6FA; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-bottom:24px; }

  .kpi-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:20px; }
  @media(max-width:500px) { .kpi-row { grid-template-columns:1fr; } }
  .kpi-card { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:18px; border-radius:18px; }
  .kpi-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:8px; }
  .kpi-value { font-size:26px; font-weight:700; color:#F5F6FA; }
  .kpi-value.green { color:var(--green); }
  .kpi-value.orange { color:var(--orange); }
  .kpi-value.red { color:var(--red); }

  .fortschritt-panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; margin-bottom:20px; }
  .fortschritt-bar-wrap { height:14px; background:rgba(124,124,255,0.15); border-radius:7px; overflow:hidden; margin:12px 0; }
  .fortschritt-bar { height:100%; border-radius:7px; background:linear-gradient(90deg, var(--blue-accent), var(--green)); }
  .fortschritt-text { font-family:var(--mono); font-size:11px; color:var(--text-dim); text-align:center; }

  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; margin-bottom:16px; border-radius:20px; }
  .panel-title { font-family:var(--mono); font-size:11px; color:var(--blue-bright); margin-bottom:16px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:6px; display:block; }
  .field-input { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:10px 12px; outline:none; margin-bottom:12px; border-radius:12px; }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .btn-add { width:100%; background:none; border:1px solid var(--blue-bright); color:var(--blue-bright); padding:12px; font-family:var(--mono); font-size:12px; cursor:pointer; }
  .btn-add:hover { background:rgba(148,148,255,0.1); }

  .einzahlung-zeile { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid rgba(124,124,255,0.1); font-size:13px; }
  .einzahlung-zeile:last-child { border-bottom:none; }
  .einzahlung-betrag { font-family:var(--mono); color:var(--green); font-weight:600; }
  .loeschen-btn { background:none; border:none; color:var(--red); cursor:pointer; font-size:14px; }
  .empty { text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); padding:20px; }
  .hinweis { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-top:10px; line-height:1.5; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Steuerrücklage <?= date('Y') ?></div>
  <div class="page-sub">Geschätzt aus tatsächlichem Rohertrag (30% Richtwert)</div>

  <div class="kpi-row">
    <div class="kpi-card">
      <div class="kpi-label">Geschätzter Rohertrag (Jahr)</div>
      <div class="kpi-value">€<?= number_format($rohertrag_gesamt, 0, ',', '.') ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Geschätzte Rücklage (30%)</div>
      <div class="kpi-value orange">€<?= number_format($geschaetzte_ruecklage, 0, ',', '.') ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Tatsächlich zurückgelegt</div>
      <div class="kpi-value green">€<?= number_format($tatsaechlich_zurueckgelegt, 0, ',', '.') ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Differenz</div>
      <div class="kpi-value <?= $differenz > 0 ? 'red' : 'green' ?>">€<?= number_format(abs($differenz), 0, ',', '.') ?> <?= $differenz > 0 ? 'fehlt' : 'über Plan' ?></div>
    </div>
  </div>

  <div class="fortschritt-panel">
    <div class="panel-title">Fortschritt</div>
    <div class="fortschritt-bar-wrap"><div class="fortschritt-bar" style="width:<?= $fortschritt_pct ?>%;"></div></div>
    <div class="fortschritt-text"><?= round($fortschritt_pct) ?>% der geschätzt benötigten Rücklage zurückgelegt</div>
    <div class="hinweis">Basiert auf 30% des bisherigen Rohertrags — ein Richtwert (KSt 15% + Soli + GewSt), keine verbindliche Steuerberechnung. Für die genaue Höhe fragt euren Steuerberater.</div>
  </div>

  <div class="panel">
    <div class="panel-title">Einzahlung erfassen</div>
    <div class="row-2">
      <div>
        <label class="field-label">Betrag (€)</label>
        <input class="field-input" type="number" step="0.01" id="e-betrag">
      </div>
      <div>
        <label class="field-label">Datum</label>
        <input class="field-input" type="date" id="e-datum" value="<?= date('Y-m-d') ?>">
      </div>
    </div>
    <label class="field-label">Notiz (optional)</label>
    <input class="field-input" type="text" id="e-notiz" placeholder="z.B. Überweisung auf Rücklagenkonto">
    <button class="btn-add" onclick="einzahlungHinzufuegen()">+ Einzahlung erfassen</button>
  </div>

  <div class="panel">
    <div class="panel-title">Einzahlungen <?= date('Y') ?></div>
    <?php if (empty($einzahlungen)): ?>
      <div class="empty">Noch keine Einzahlungen erfasst</div>
    <?php else: ?>
      <?php foreach ($einzahlungen as $e): ?>
      <div class="einzahlung-zeile" id="einzahlung-<?= $e['id'] ?>">
        <div>
          <div><?= date('d.m.Y', strtotime($e['datum'])) ?><?= $e['notiz'] ? ' — ' . htmlspecialchars($e['notiz']) : '' ?></div>
        </div>
        <div style="display:flex; align-items:center; gap:12px;">
          <span class="einzahlung-betrag">€<?= number_format($e['betrag'], 2, ',', '.') ?></span>
          <button class="loeschen-btn" onclick="einzahlungLoeschen(<?= $e['id'] ?>)">✕</button>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('steuer.php', { method:'POST', body:fd });
  return res.json();
}

async function einzahlungHinzufuegen() {
  const betrag = document.getElementById('e-betrag').value;
  const datum = document.getElementById('e-datum').value;
  const notiz = document.getElementById('e-notiz').value;
  const res = await api({ action: 'einzahlung_add', betrag, datum, notiz });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function einzahlungLoeschen(id) {
  if (!confirm('Diese Einzahlung wirklich löschen?')) return;
  const res = await api({ action: 'einzahlung_loeschen', id });
  if (res.error) { alert(res.error); return; }
  document.getElementById('einzahlung-' + id)?.remove();
}
</script>
</div>
</body>
</html>
