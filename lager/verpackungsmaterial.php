<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('lager');
$user = currentUser();
$pdo = db();
$kann_bearbeiten = hat_modul_zugriff('lager', 'bearbeiten');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    if (!$kann_bearbeiten) { echo json_encode(['error' => 'Kein Bearbeiten-Recht']); exit; }
    $action = $_POST['action'] ?? '';

    if ($action === 'material_anlegen') {
        $name = trim($_POST['name'] ?? '');
        $einheit = $_POST['einheit'] ?? 'stueck';
        $bestand = (float)($_POST['bestand'] ?? 0);
        $mindestbestand = (float)($_POST['mindestbestand'] ?? 0);
        if (!in_array($einheit, ['stueck', 'meter', 'gramm'])) $einheit = 'stueck';
        if (!$name) { echo json_encode(['error' => 'Name fehlt']); exit; }

        $pdo->prepare("INSERT INTO verpackungsmaterial (name, einheit, bestand, mindestbestand) VALUES (?,?,?,?)")
            ->execute([$name, $einheit, $bestand, $mindestbestand]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'material_nachbuchen') {
        $material_id = (int)($_POST['material_id'] ?? 0);
        $menge = (float)($_POST['menge'] ?? 0);
        if (!$material_id || $menge <= 0) { echo json_encode(['error' => 'Ungültig']); exit; }

        $pdo->prepare("
            INSERT INTO verpackung_bewegungen (material_id, typ, menge, notiz, erfasst_von)
            VALUES (?, 'zugang_einkauf', ?, 'Manuell nachgebucht', ?)
        ")->execute([$material_id, $menge, $user['username'] === 'qaf' ? 'qaf' : 'qns']);
        $pdo->prepare("UPDATE verpackungsmaterial SET bestand = bestand + ? WHERE id = ?")->execute([$menge, $material_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'rezept_speichern') {
        $produkt_id = (int)($_POST['produkt_id'] ?? 0);
        $material_id = (int)($_POST['material_id'] ?? 0);
        $menge_je_verkauf = (float)($_POST['menge_je_verkauf'] ?? 0);
        if (!$produkt_id || !$material_id) { echo json_encode(['error' => 'Ungültig']); exit; }

        if ($menge_je_verkauf <= 0) {
            $pdo->prepare("DELETE FROM produkt_verpackung_rezept WHERE produkt_id = ? AND material_id = ?")->execute([$produkt_id, $material_id]);
        } else {
            $pdo->prepare("
                INSERT INTO produkt_verpackung_rezept (produkt_id, material_id, menge_je_verkauf)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE menge_je_verkauf = VALUES(menge_je_verkauf)
            ")->execute([$produkt_id, $material_id, $menge_je_verkauf]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'barcode_speichern') {
        $produkt_id = (int)($_POST['produkt_id'] ?? 0);
        $barcode = trim($_POST['barcode'] ?? '') ?: null;
        $pdo->prepare("UPDATE lager_produkte SET barcode = ? WHERE id = ?")->execute([$barcode, $produkt_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

$materialien = $pdo->query("SELECT * FROM verpackungsmaterial WHERE aktiv = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$produkte = $pdo->query("SELECT id, name, barcode FROM lager_produkte WHERE aktiv = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$alle_rezepte = $pdo->query("SELECT * FROM produkt_verpackung_rezept")->fetchAll(PDO::FETCH_ASSOC);
$rezept_je_produkt = [];
foreach ($alle_rezepte as $r) { $rezept_je_produkt[$r['produkt_id']][$r['material_id']] = $r['menge_je_verkauf']; }

$einheit_labels = ['stueck' => 'Stk', 'meter' => 'm', 'gramm' => 'g'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verpackungsmaterial — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --orange: #FBBF24; --red: #F87171;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .main { max-width:900px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; color:#F5F6FA; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-bottom:20px; }

  .tab-nav { display:flex; gap:6px; margin-bottom:20px; }
  .tab-btn { font-family:var(--mono); font-size:10px; padding:9px 16px; cursor:pointer; border:1px solid rgba(124,124,255,0.25); background:none; color:var(--text-dim); }
  .tab-btn.active { background:rgba(148,148,255,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }
  .tab-content { display:none; }
  .tab-content.active { display:block; }

  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; margin-bottom:16px; }
  .panel-title { font-family:var(--mono); font-size:11px; color:var(--blue-bright); margin-bottom:14px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:6px; display:block; }
  .field-input, .field-select { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:9px 11px; outline:none; margin-bottom:12px; }
  .row-3 { display:grid; grid-template-columns:1.4fr 1fr 1fr 1fr; gap:10px; }
  .btn { font-family:var(--mono); font-size:10px; padding:9px 16px; cursor:pointer; border:1px solid; background:none; }
  .btn-primary { color:var(--blue-bright); border-color:rgba(148,148,255,0.4); }

  .material-karte { display:flex; justify-content:space-between; align-items:center; padding:14px; border:1px solid rgba(124,124,255,0.15); margin-bottom:8px; }
  .material-karte.niedrig { border-color:rgba(251,191,36,0.4); background:rgba(251,191,36,0.06); }
  .material-name { font-weight:700; font-size:14px; }
  .material-meta { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-top:3px; }
  .material-bestand { font-family:var(--mono); font-size:18px; font-weight:700; }
  .material-bestand.niedrig { color:var(--orange); }
  .nachbuchen-form { display:flex; gap:6px; align-items:center; }
  .nachbuchen-form input { width:70px; }

  .produkt-rezept-block { border:1px solid rgba(124,124,255,0.15); margin-bottom:8px; }
  .produkt-rezept-kopf { padding:12px 14px; background:rgba(20,22,31,0.6); cursor:pointer; display:flex; justify-content:space-between; align-items:center; }
  .produkt-rezept-body { display:none; padding:14px; }
  .produkt-rezept-body.open { display:block; }
  .rezept-zeile { display:flex; justify-content:space-between; align-items:center; padding:6px 0; font-size:12px; }
  .rezept-zeile input { width:80px; }
  .empty { text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); padding:24px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Verpackungsmaterial</div>
  <div class="page-sub">// FOLIE, KARTONS, FÜLLMATERIAL — RESSOURCEN NEBEN DEN PRODUKTEN</div>

  <div class="tab-nav">
    <button class="tab-btn active" onclick="tabWechseln('bestand', this)">📦 Bestand</button>
    <button class="tab-btn" onclick="tabWechseln('rezepte', this)">🧾 Rezepte pro Produkt</button>
  </div>

  <div class="tab-content active" id="tab-bestand">
    <?php if ($kann_bearbeiten): ?>
    <div class="panel">
      <div class="panel-title">// NEUES MATERIAL ANLEGEN</div>
      <div class="row-3">
        <div><label class="field-label">Name</label><input class="field-input" id="neu-name" placeholder="z.B. Folie"></div>
        <div><label class="field-label">Einheit</label>
          <select class="field-select" id="neu-einheit">
            <option value="stueck">Stück</option>
            <option value="meter">Meter</option>
            <option value="gramm">Gramm</option>
          </select>
        </div>
        <div><label class="field-label">Bestand</label><input class="field-input" type="number" step="0.01" id="neu-bestand" value="0"></div>
        <div><label class="field-label">Mindestbestand</label><input class="field-input" type="number" step="0.01" id="neu-mindestbestand" value="0"></div>
      </div>
      <button class="btn btn-primary" onclick="materialAnlegen()">+ Anlegen</button>
    </div>
    <?php endif; ?>

    <?php if (empty($materialien)): ?>
      <div class="empty">// NOCH KEIN VERPACKUNGSMATERIAL ANGELEGT</div>
    <?php else: ?>
      <?php foreach ($materialien as $m): $niedrig = $m['mindestbestand'] > 0 && $m['bestand'] <= $m['mindestbestand']; ?>
      <div class="material-karte <?= $niedrig ? 'niedrig' : '' ?>" id="mat-<?= $m['id'] ?>">
        <div>
          <div class="material-name"><?= htmlspecialchars($m['name']) ?><?= $niedrig ? ' ⚠️' : '' ?></div>
          <div class="material-meta">Mindestbestand: <?= $m['mindestbestand'] ?> <?= $einheit_labels[$m['einheit']] ?></div>
        </div>
        <div style="display:flex; align-items:center; gap:16px;">
          <div class="material-bestand <?= $niedrig ? 'niedrig' : '' ?>"><?= number_format($m['bestand'], $m['einheit'] === 'gramm' ? 0 : 2) ?> <?= $einheit_labels[$m['einheit']] ?></div>
          <?php if ($kann_bearbeiten): ?>
          <div class="nachbuchen-form">
            <input type="number" step="0.01" id="nachbuchen-<?= $m['id'] ?>" placeholder="+Menge">
            <button class="btn btn-primary" onclick="materialNachbuchen(<?= $m['id'] ?>)">✓</button>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="tab-content" id="tab-rezepte">
    <div class="panel">
      <div class="panel-title">// WELCHES PRODUKT BRAUCHT WELCHES MATERIAL</div>
      <div style="font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:14px;">Menge auf 0 setzen, um eine Zuordnung wieder zu entfernen. Änderungen speichern sich automatisch.</div>
    </div>

    <?php foreach ($produkte as $p): ?>
    <div class="produkt-rezept-block">
      <div class="produkt-rezept-kopf" onclick="toggleProdukt(<?= $p['id'] ?>)">
        <span><?= htmlspecialchars($p['name']) ?></span>
        <span style="font-family:var(--mono); font-size:9px; color:var(--text-dim);"><?= $p['barcode'] ? '📷 ' . htmlspecialchars($p['barcode']) : 'Kein Barcode' ?></span>
      </div>
      <div class="produkt-rezept-body" id="rezept-body-<?= $p['id'] ?>">
        <?php if ($kann_bearbeiten): ?>
        <label class="field-label">Barcode (für den Scanner)</label>
        <div style="display:flex; gap:8px; margin-bottom:16px;">
          <input class="field-input" style="margin-bottom:0;" type="text" id="barcode-<?= $p['id'] ?>" value="<?= htmlspecialchars($p['barcode'] ?? '') ?>" placeholder="EAN/Barcode-Nummer">
          <button class="btn btn-primary" onclick="barcodeSpeichern(<?= $p['id'] ?>)">Speichern</button>
        </div>
        <?php endif; ?>

        <div class="field-label">Verpackungsmaterial-Rezept</div>
        <?php if (empty($materialien)): ?>
          <div style="font-family:var(--mono); font-size:10px; color:var(--text-dim);">Erst Material im Bestand-Tab anlegen.</div>
        <?php else: ?>
          <?php foreach ($materialien as $m): $aktuelle_menge = $rezept_je_produkt[$p['id']][$m['id']] ?? 0; ?>
          <div class="rezept-zeile">
            <span><?= htmlspecialchars($m['name']) ?> (<?= $einheit_labels[$m['einheit']] ?>/Verkauf)</span>
            <?php if ($kann_bearbeiten): ?>
              <input type="number" step="0.01" class="field-input" style="margin-bottom:0;" value="<?= $aktuelle_menge ?>" onchange="rezeptSpeichern(<?= $p['id'] ?>, <?= $m['id'] ?>, this.value)">
            <?php else: ?>
              <span><?= $aktuelle_menge ?></span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
function tabWechseln(tab, btn) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  btn.classList.add('active');
}
function toggleProdukt(id) {
  document.getElementById('rezept-body-' + id)?.classList.toggle('open');
}

async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('verpackungsmaterial.php', { method:'POST', body:fd });
  return res.json();
}

async function materialAnlegen() {
  const res = await api({
    action: 'material_anlegen',
    name: document.getElementById('neu-name').value,
    einheit: document.getElementById('neu-einheit').value,
    bestand: document.getElementById('neu-bestand').value,
    mindestbestand: document.getElementById('neu-mindestbestand').value,
  });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function materialNachbuchen(id) {
  const menge = document.getElementById('nachbuchen-' + id).value;
  if (!menge || menge <= 0) { alert('Menge eingeben'); return; }
  const res = await api({ action: 'material_nachbuchen', material_id: id, menge });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function rezeptSpeichern(produktId, materialId, menge) {
  const res = await api({ action: 'rezept_speichern', produkt_id: produktId, material_id: materialId, menge_je_verkauf: menge });
  if (res.error) { alert(res.error); }
}

async function barcodeSpeichern(produktId) {
  const barcode = document.getElementById('barcode-' + produktId).value.trim();
  const res = await api({ action: 'barcode_speichern', produkt_id: produktId, barcode });
  if (res.error) { alert(res.error); return; }
  alert('✓ Gespeichert');
}
</script>
</div>
</body>
</html>
