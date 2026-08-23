<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('clean');
$pdo = db();
$kann_bearbeiten = hat_modul_zugriff('clean', 'bearbeiten');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    if (!$kann_bearbeiten) { echo json_encode(['error' => 'Kein Bearbeiten-Recht']); exit; }
    $action = $_POST['action'] ?? '';

    if ($action === 'hinzufuegen') {
        $name = trim($_POST['name'] ?? '');
        $kontakt = trim($_POST['kontakt'] ?? '');
        $satz = (float)($_POST['satz_pro_qm'] ?? 0);
        if (!$name) { echo json_encode(['error' => 'Name fehlt']); exit; }
        $pdo->prepare("INSERT INTO clean_subunternehmer (name, kontakt, satz_pro_qm) VALUES (?, ?, ?)")->execute([$name, $kontakt, $satz]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'deaktivieren') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE clean_subunternehmer SET aktiv = 0 WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

$subunternehmer = $pdo->query("SELECT * FROM clean_subunternehmer WHERE aktiv = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Subunternehmer — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --red: #F87171;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }
  .main { max-width:700px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; color:#F5F6FA; margin-bottom:20px; }
  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; margin-bottom:16px; border-radius:20px; }
  .panel-title { font-family:var(--mono); font-size:11px; color:var(--blue-bright); margin-bottom:16px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:6px; display:block; }
  .field-input { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:10px 12px; outline:none; margin-bottom:12px; border-radius:12px; }
  .row-3 { display:grid; grid-template-columns:2fr 2fr 1fr; gap:10px; }
  .btn { font-family:var(--mono); font-size:10px; padding:10px 16px; cursor:pointer; border:1px solid; background:none; border-radius:12px; }
  .btn-primary { color:var(--green); border-color:rgba(74,222,128,0.4); }
  .su-zeile { display:flex; justify-content:space-between; align-items:center; padding:12px 0; border-bottom:1px solid rgba(124,124,255,0.1); }
  .su-name { font-weight:700; }
  .su-meta { font-family:var(--mono); font-size:11px; color:var(--text-dim); }
  .loeschen-btn { background:none; border:1px solid rgba(248,113,113,0.3); color:var(--red); font-family:var(--mono); font-size:9px; padding:5px 10px; cursor:pointer; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Subunternehmer</div>

  <?php if ($kann_bearbeiten): ?>
  <div class="panel">
    <div class="panel-title">Neu hinzufügen</div>
    <div class="row-3">
      <div><label class="field-label">Name</label><input class="field-input" id="neu-name" type="text"></div>
      <div><label class="field-label">Kontakt</label><input class="field-input" id="neu-kontakt" type="text" placeholder="Telefon/E-Mail"></div>
      <div><label class="field-label">€/m²</label><input class="field-input" id="neu-satz" type="number" step="0.01" value="0.50"></div>
    </div>
    <button class="btn btn-primary" onclick="hinzufuegen()">+ Hinzufügen</button>
  </div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-title">Aktive Subunternehmer</div>
    <?php if (empty($subunternehmer)): ?>
      <div style="font-family:var(--mono); font-size:11px; color:var(--text-dim); text-align:center; padding:20px;">Noch keine angelegt</div>
    <?php else: ?>
      <?php foreach ($subunternehmer as $s): ?>
      <div class="su-zeile" id="su-<?= $s['id'] ?>">
        <div>
          <div class="su-name"><?= htmlspecialchars($s['name']) ?></div>
          <div class="su-meta"><?= htmlspecialchars($s['kontakt'] ?: '—') ?> · €<?= number_format($s['satz_pro_qm'],2,',','.') ?>/m²</div>
        </div>
        <?php if ($kann_bearbeiten): ?>
          <button class="loeschen-btn" onclick="deaktivieren(<?= $s['id'] ?>)">Deaktivieren</button>
        <?php endif; ?>
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
  const res = await fetch('subunternehmer.php', { method:'POST', body:fd });
  return res.json();
}

async function hinzufuegen() {
  const name = document.getElementById('neu-name').value.trim();
  const kontakt = document.getElementById('neu-kontakt').value.trim();
  const satz_pro_qm = document.getElementById('neu-satz').value;
  if (!name) { alert('Name fehlt'); return; }
  const res = await api({ action: 'hinzufuegen', name, kontakt, satz_pro_qm });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function deaktivieren(id) {
  if (!confirm('Subunternehmer deaktivieren?')) return;
  const res = await api({ action: 'deaktivieren', id });
  if (res.error) { alert(res.error); return; }
  document.getElementById('su-' + id)?.remove();
}
</script>
</div>
</body>
</html>
