<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('clean');
$pdo = db();
$kann_bearbeiten = hat_modul_zugriff('clean', 'bearbeiten');
$preise_verbergen = hat_einschraenkung('clean', 'preise_verbergen');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    if (!$kann_bearbeiten) { echo json_encode(['error' => 'Kein Bearbeiten-Recht']); exit; }
    $action = $_POST['action'] ?? '';

    if ($action === 'rechnung_add') {
        $objekt_id = (int)($_POST['objekt_id'] ?? 0);
        $betrag = (float)($_POST['betrag'] ?? 0);
        $von = $_POST['zeitraum_von'] ?? null;
        $bis = $_POST['zeitraum_bis'] ?? null;
        if (!$objekt_id || $betrag <= 0) { echo json_encode(['error' => 'Objekt/Betrag fehlt']); exit; }

        $jahr = date('Y');
        $anzahl = (int)$pdo->query("SELECT COUNT(*) FROM clean_rechnungen WHERE YEAR(erstellt_am) = $jahr")->fetchColumn();
        $rechnungsnummer = "RG-CLEAN-{$jahr}-" . str_pad($anzahl + 1, 3, '0', STR_PAD_LEFT);

        $pdo->prepare("
            INSERT INTO clean_rechnungen (objekt_id, rechnungsnummer, betrag, zeitraum_von, zeitraum_bis)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$objekt_id, $rechnungsnummer, $betrag, $von ?: null, $bis ?: null]);
        echo json_encode(['success' => true, 'rechnungsnummer' => $rechnungsnummer, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'bezahlt_umschalten') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE clean_rechnungen SET bezahlt = NOT bezahlt WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

$objekte = $pdo->query("SELECT id, firma, angebotspreis FROM clean_objekte WHERE status IN ('vertrag','aktiv') ORDER BY firma")->fetchAll(PDO::FETCH_ASSOC);

$rechnungen = $pdo->query("
    SELECT r.*, o.firma
    FROM clean_rechnungen r
    JOIN clean_objekte o ON o.id = r.objekt_id
    ORDER BY r.erstellt_am DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rechnungen — NA Clean Service</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --orange: #FBBF24;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }
  .main { max-width:800px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; color:#F5F6FA; margin-bottom:20px; }
  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; margin-bottom:16px; }
  .panel-title { font-family:var(--mono); font-size:11px; color:var(--blue-bright); margin-bottom:16px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:6px; display:block; }
  .field-input, .field-select { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:10px 12px; outline:none; margin-bottom:12px; }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .btn { font-family:var(--mono); font-size:10px; padding:10px 16px; cursor:pointer; border:1px solid; background:none; }
  .btn-primary { color:var(--green); border-color:rgba(74,222,128,0.4); }

  .rg-zeile { display:flex; justify-content:space-between; align-items:center; padding:12px 0; border-bottom:1px solid rgba(124,124,255,0.08); font-size:13px; }
  .rg-nummer { font-family:var(--mono); font-size:11px; color:var(--blue-bright); }
  .rg-betrag { font-family:var(--mono); font-weight:700; }
  .bezahlt-badge { font-family:var(--mono); font-size:9px; padding:3px 8px; border-radius:8px; cursor:pointer; }
  .bezahlt-badge.ja { background:rgba(74,222,128,0.15); color:var(--green); }
  .bezahlt-badge.nein { background:rgba(251,191,36,0.15); color:var(--orange); }
  .pdf-link { font-family:var(--mono); font-size:9px; color:var(--blue-bright); text-decoration:none; margin-left:10px; }
  .empty { text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); padding:20px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Rechnungen</div>

  <?php if ($kann_bearbeiten): ?>
  <div class="panel">
    <div class="panel-title">// NEUE RECHNUNG</div>
    <label class="field-label">Objekt</label>
    <select class="field-select" id="objekt_id">
      <option value="">— wählen —</option>
      <?php foreach ($objekte as $o): ?>
        <option value="<?= $o['id'] ?>" data-preis="<?= $o['angebotspreis'] ?? '' ?>"><?= htmlspecialchars($o['firma']) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="field-label">Betrag (€)</label>
    <input class="field-input" type="number" step="0.01" id="betrag">
    <div class="row-2">
      <div><label class="field-label">Zeitraum von</label><input class="field-input" type="date" id="zeitraum_von"></div>
      <div><label class="field-label">Zeitraum bis</label><input class="field-input" type="date" id="zeitraum_bis"></div>
    </div>
    <button class="btn btn-primary" onclick="rechnungErstellen()">+ Rechnung erstellen</button>
  </div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-title">// RECHNUNGSHISTORIE</div>
    <?php if (empty($rechnungen)): ?>
      <div class="empty">// NOCH KEINE RECHNUNGEN</div>
    <?php else: ?>
      <?php foreach ($rechnungen as $r): ?>
      <div class="rg-zeile" id="rg-<?= $r['id'] ?>">
        <div>
          <div><?= htmlspecialchars($r['firma']) ?></div>
          <div class="rg-nummer"><?= htmlspecialchars($r['rechnungsnummer']) ?> · <?= date('d.m.Y', strtotime($r['erstellt_am'])) ?></div>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
          <?php if (!$preise_verbergen): ?><span class="rg-betrag">€<?= number_format($r['betrag'],2,',','.') ?></span><?php endif; ?>
          <span class="bezahlt-badge <?= $r['bezahlt'] ? 'ja' : 'nein' ?>" onclick="bezahltUmschalten(<?= $r['id'] ?>)"><?= $r['bezahlt'] ? '✓ Bezahlt' : '⏳ Offen' ?></span>
          <a class="pdf-link" href="rechnung_pdf.php?id=<?= $r['id'] ?>" target="_blank">PDF</a>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
document.getElementById('objekt_id')?.addEventListener('change', function() {
  const preis = this.options[this.selectedIndex]?.dataset.preis;
  if (preis) document.getElementById('betrag').value = preis;
});

async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('rechnungen.php', { method:'POST', body:fd });
  return res.json();
}

async function rechnungErstellen() {
  const objekt_id = document.getElementById('objekt_id').value;
  const betrag = document.getElementById('betrag').value;
  if (!objekt_id || !betrag) { alert('Objekt und Betrag angeben'); return; }
  const res = await api({
    action: 'rechnung_add', objekt_id, betrag,
    zeitraum_von: document.getElementById('zeitraum_von').value,
    zeitraum_bis: document.getElementById('zeitraum_bis').value,
  });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function bezahltUmschalten(id) {
  const res = await api({ action: 'bezahlt_umschalten', id });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}
</script>
</div>
</body>
</html>
