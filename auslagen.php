<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/config.php';
requireLogin();
require_once __DIR__ . '/berechtigungen/berechtigungen_helper.php';
$user = currentUser();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'auslage_anlegen') {
        $person = trim($_POST['person'] ?? '');
        $betrag = (float)($_POST['betrag'] ?? 0);
        $grund = trim($_POST['grund'] ?? '');
        $datum = $_POST['datum'] ?? date('Y-m-d');
        if (!$person) { echo json_encode(['error' => 'Person fehlt']); exit; }
        if ($betrag <= 0 || !$grund) { echo json_encode(['error' => 'Betrag oder Grund fehlt']); exit; }

        $pdo->prepare("
            INSERT INTO auslagen (person, betrag, grund, datum, erfasst_von)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$person, $betrag, $grund, $datum, $user['username']]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'status_aendern') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['offen', 'erstattet'])) { echo json_encode(['error' => 'Ungültig']); exit; }

        if ($status === 'erstattet') {
            $pdo->prepare("UPDATE auslagen SET status = ?, erstattet_am = CURDATE() WHERE id = ?")->execute([$status, $id]);
        } else {
            $pdo->prepare("UPDATE auslagen SET status = ?, erstattet_am = NULL WHERE id = ?")->execute([$status, $id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'auslage_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM auslagen WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannt']);
    exit;
}

$status_filter = $_GET['status'] ?? '';
$sql = "SELECT * FROM auslagen";
$params = [];
if ($status_filter) { $sql .= " WHERE status = ?"; $params[] = $status_filter; }
$sql .= " ORDER BY status ASC, datum DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$auslagen = $stmt->fetchAll(PDO::FETCH_ASSOC);

$offen_je_person = $pdo->query("
    SELECT person, SUM(betrag) AS summe FROM auslagen WHERE status = 'offen' GROUP BY person ORDER BY summe DESC
")->fetchAll(PDO::FETCH_ASSOC);

$status_labels = ['offen' => '⏳ Offen', 'erstattet' => '✓ Erstattet'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Auslagen — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #0f1923; --navy2: #152236; --blue-accent: #2d6aad; --blue-bright: #4a9edd;
    --text: #c8dff0; --text-dim: #5a7a9a; --green: #2ecc71; --orange: #e67e22; --red:#e74c3c;
    --mono: 'Share Tech Mono', monospace; --sans: 'Exo 2', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .main { max-width:700px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#e8f4ff; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); letter-spacing:1px; margin-bottom:20px; }

  .kpi-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:20px; }
  .kpi-card { background:rgba(21,34,54,0.8); border:1px solid rgba(230,126,34,0.3); padding:16px; }
  .kpi-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); text-transform:uppercase; margin-bottom:6px; }
  .kpi-value { font-size:22px; font-weight:700; color:var(--orange); }

  .panel { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:18px; margin-bottom:18px; }
  .panel-title { font-family:var(--mono); font-size:11px; letter-spacing:2px; text-transform:uppercase; color:var(--blue-bright); margin-bottom:14px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); letter-spacing:1px; text-transform:uppercase; margin-bottom:6px; display:block; }
  .field-input, .field-select { width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--sans); font-size:14px; padding:10px 12px; outline:none; margin-bottom:12px; }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .btn { font-family:var(--mono); font-size:10px; letter-spacing:1px; text-transform:uppercase; padding:10px 16px; cursor:pointer; border:1px solid; background:none; text-decoration:none; display:inline-block; }
  .btn-primary { color:var(--green); border-color:rgba(46,204,113,0.4); width:100%; }

  .filter-tabs { display:flex; gap:6px; margin-bottom:16px; }
  .filter-tab { font-family:var(--mono); font-size:10px; padding:7px 14px; border:1px solid rgba(45,106,173,0.25); background:none; color:var(--text-dim); text-decoration:none; }
  .filter-tab.active { background:rgba(74,158,221,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }

  .auslage-karte { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.15); padding:14px 16px; margin-bottom:8px; }
  .auslage-karte.offen { border-color:rgba(230,126,34,0.35); }
  .auslage-kopf { display:flex; justify-content:space-between; align-items:flex-start; }
  .auslage-person { font-family:var(--mono); font-size:9px; padding:2px 8px; border-radius:8px; background:rgba(74,158,221,0.15); color:var(--blue-bright); margin-right:6px; }
  .auslage-grund { font-weight:600; font-size:14px; margin-top:6px; }
  .auslage-meta { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-top:3px; }
  .auslage-betrag { font-family:var(--mono); font-size:18px; font-weight:700; color:var(--orange); }
  .auslage-betrag.erstattet { color:var(--green); }
  .auslage-aktionen { display:flex; gap:8px; margin-top:10px; }
  .mini-btn { font-family:var(--mono); font-size:9px; padding:6px 10px; border:1px solid rgba(45,106,173,0.3); background:none; color:var(--text); cursor:pointer; }
  .mini-btn.gruen { color:var(--green); border-color:rgba(46,204,113,0.4); }
  .mini-btn.rot { color:var(--red); border-color:rgba(231,76,60,0.3); }
  .empty { text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); padding:24px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Auslagen</div>
  <div class="page-sub">// WER HAT WIE VIEL FÜR DIE FIRMA VORGESTRECKT</div>

  <?php if (!empty($offen_je_person)): ?>
  <div class="kpi-row" style="grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));">
    <?php foreach ($offen_je_person as $op): ?>
    <div class="kpi-card">
      <div class="kpi-label">Offen — <?= htmlspecialchars($op['person']) ?></div>
      <div class="kpi-value">€<?= number_format($op['summe'], 2, ',', '.') ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-title">// NEUE AUSLAGE ERFASSEN</div>
    <div class="row-2">
      <div>
        <label class="field-label">Person</label>
        <input class="field-input" id="neu-person" list="personen-vorschlaege" placeholder="Name eintragen">
        <datalist id="personen-vorschlaege">
          <option value="Artis">
          <option value="Nour">
        </datalist>
      </div>
      <div>
        <label class="field-label">Betrag (€)</label>
        <input class="field-input" type="number" step="0.01" id="neu-betrag">
      </div>
    </div>
    <label class="field-label">Grund</label>
    <input class="field-input" id="neu-grund" placeholder="z.B. Starlink Pickup Göteborg, Amex privat belastet">
    <label class="field-label">Datum</label>
    <input class="field-input" type="date" id="neu-datum" value="<?= date('Y-m-d') ?>">
    <button class="btn btn-primary" onclick="auslageAnlegen()">+ Erfassen</button>
  </div>

  <div class="filter-tabs">
    <a href="auslagen.php" class="filter-tab <?= !$status_filter ? 'active' : '' ?>">Alle</a>
    <a href="auslagen.php?status=offen" class="filter-tab <?= $status_filter==='offen'?'active':'' ?>">⏳ Offen</a>
    <a href="auslagen.php?status=erstattet" class="filter-tab <?= $status_filter==='erstattet'?'active':'' ?>">✓ Erstattet</a>
  </div>

  <?php if (empty($auslagen)): ?>
    <div class="empty">// KEINE EINTRÄGE</div>
  <?php else: ?>
    <?php foreach ($auslagen as $a): ?>
    <div class="auslage-karte <?= $a['status'] ?>" id="auslage-<?= $a['id'] ?>">
      <div class="auslage-kopf">
        <div>
          <span class="auslage-person"><?= htmlspecialchars($a['person'] === 'qaf' ? 'Artis' : ($a['person'] === 'qns' ? 'Nour' : $a['person'])) ?></span>
          <span style="font-family:var(--mono); font-size:9px; color:var(--text-dim);"><?= date('d.m.Y', strtotime($a['datum'])) ?></span>
        </div>
        <div class="auslage-betrag <?= $a['status']==='erstattet'?'erstattet':'' ?>">€<?= number_format($a['betrag'], 2, ',', '.') ?></div>
      </div>
      <div class="auslage-grund"><?= htmlspecialchars($a['grund']) ?></div>
      <div class="auslage-meta">
        <?= $status_labels[$a['status']] ?>
        <?= $a['erstattet_am'] ? ' am ' . date('d.m.Y', strtotime($a['erstattet_am'])) : '' ?>
      </div>
      <div class="auslage-aktionen">
        <?php if ($a['status'] === 'offen'): ?>
          <button class="mini-btn gruen" onclick="statusAendern(<?= $a['id'] ?>, 'erstattet')">✓ Als erstattet markieren</button>
        <?php else: ?>
          <button class="mini-btn" onclick="statusAendern(<?= $a['id'] ?>, 'offen')">↩ Wieder auf offen</button>
        <?php endif; ?>
        <button class="mini-btn rot" onclick="auslageLoeschen(<?= $a['id'] ?>)">✕ Löschen</button>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('auslagen.php', { method:'POST', body:fd });
  return res.json();
}

async function auslageAnlegen() {
  const res = await api({
    action: 'auslage_anlegen',
    person: document.getElementById('neu-person').value,
    betrag: document.getElementById('neu-betrag').value,
    grund: document.getElementById('neu-grund').value,
    datum: document.getElementById('neu-datum').value,
  });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function statusAendern(id, status) {
  const res = await api({ action: 'status_aendern', id, status });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function auslageLoeschen(id) {
  if (!confirm('Eintrag wirklich löschen?')) return;
  const res = await api({ action: 'auslage_loeschen', id });
  if (res.error) { alert(res.error); return; }
  document.getElementById('auslage-' + id)?.remove();
}
</script>
</div>
</body>
</html>