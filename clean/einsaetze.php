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

    if ($action === 'einsatz_add') {
        $objekt_id = (int)($_POST['objekt_id'] ?? 0);
        $subunternehmer_id = (int)($_POST['subunternehmer_id'] ?? 0) ?: null;
        $datum = $_POST['datum'] ?? date('Y-m-d');
        $uhrzeit = $_POST['uhrzeit'] ?: null;
        $notiz = trim($_POST['notiz'] ?? '');
        if (!$objekt_id) { echo json_encode(['error' => 'Objekt fehlt']); exit; }
        $pdo->prepare("INSERT INTO clean_einsaetze (objekt_id, subunternehmer_id, datum, uhrzeit, notiz) VALUES (?, ?, ?, ?, ?)")
            ->execute([$objekt_id, $subunternehmer_id, $datum, $uhrzeit, $notiz]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'status_aendern') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'geplant';
        if (!in_array($status, ['geplant', 'erledigt', 'ausgefallen'])) { echo json_encode(['error' => 'Ungültig']); exit; }
        $pdo->prepare("UPDATE clean_einsaetze SET status = ? WHERE id = ?")->execute([$status, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'checkin') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE clean_einsaetze SET checkin_zeit = NOW() WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'zeit' => date('H:i')]);
        exit;
    }

    if ($action === 'checkout') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE clean_einsaetze SET checkout_zeit = NOW(), status = 'erledigt' WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'zeit' => date('H:i')]);
        exit;
    }

    if ($action === 'foto_hinzufuegen') {
        $id = (int)($_POST['id'] ?? 0);
        $url = trim($_POST['url'] ?? '');
        if (!$id || !$url) { echo json_encode(['error' => 'Fehlende Daten']); exit; }
        $stmt = $pdo->prepare("SELECT fotos FROM clean_einsaetze WHERE id = ?");
        $stmt->execute([$id]);
        $fotos = json_decode($stmt->fetchColumn() ?: '[]', true) ?: [];
        $fotos[] = $url;
        $pdo->prepare("UPDATE clean_einsaetze SET fotos = ? WHERE id = ?")->execute([json_encode($fotos), $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'notiz_speichern') {
        $id = (int)($_POST['id'] ?? 0);
        $notiz = trim($_POST['notiz'] ?? '');
        $pdo->prepare("UPDATE clean_einsaetze SET notiz = ? WHERE id = ?")->execute([$notiz, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'einsatz_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM clean_einsaetze WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

$objekte = $pdo->query("SELECT id, firma FROM clean_objekte WHERE status IN ('vertrag','aktiv') ORDER BY firma")->fetchAll(PDO::FETCH_ASSOC);
$subunternehmer = $pdo->query("SELECT id, name FROM clean_subunternehmer WHERE aktiv = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$von = $_GET['von'] ?? date('Y-m-d');
$bis = $_GET['bis'] ?? date('Y-m-d', strtotime('+14 days'));

$stmt = $pdo->prepare("
    SELECT e.*, o.firma, s.name AS subunternehmer_name
    FROM clean_einsaetze e
    JOIN clean_objekte o ON o.id = e.objekt_id
    LEFT JOIN clean_subunternehmer s ON s.id = e.subunternehmer_id
    WHERE e.datum BETWEEN ? AND ?
    ORDER BY e.datum ASC, e.uhrzeit ASC
");
$stmt->execute([$von, $bis]);
$einsaetze = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nach_datum = [];
foreach ($einsaetze as $e) { $nach_datum[$e['datum']][] = $e; }
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Einsatzplanung — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --orange: #FBBF24; --red: #F87171;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); z-index:50; }
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

  .tag-block { margin-bottom:16px; }
  .tag-titel { font-family:var(--mono); font-size:11px; color:var(--blue-bright); padding-bottom:6px; margin-bottom:8px; border-bottom:1px dashed rgba(124,124,255,0.2); }
  .einsatz-karte { background:rgba(20,22,31,0.6); border:1px solid rgba(124,124,255,0.15); padding:12px; margin-bottom:8px; }
  .einsatz-kopf { display:flex; justify-content:space-between; align-items:flex-start; }
  .einsatz-info { flex:1; cursor:pointer; }
  .einsatz-firma { font-weight:700; font-size:13px; }
  .einsatz-meta { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-top:2px; }
  .checkin-status { font-family:var(--mono); font-size:9px; margin-top:6px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  .checkin-badge { padding:2px 8px; border-radius:8px; }
  .checkin-badge.aktiv { background:rgba(74,222,128,0.15); color:var(--green); }
  .checkin-badge.wartend { background:rgba(251,191,36,0.15); color:var(--orange); }
  .mini-btn { font-family:var(--mono); font-size:9px; padding:5px 9px; border:1px solid rgba(124,124,255,0.3); background:none; color:var(--text); cursor:pointer; }
  .mini-btn.checkin { color:var(--green); border-color:rgba(74,222,128,0.3); }
  .mini-btn.checkout { color:var(--orange); border-color:rgba(251,191,36,0.3); }
  .mini-btn.foto { color:var(--blue-bright); border-color:rgba(148,148,255,0.3); }
  .foto-count { font-family:var(--mono); font-size:9px; color:var(--blue-bright); }
  select.status-select { background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--mono); font-size:9px; padding:4px 6px; }
  .loeschen-x { background:none; border:none; color:var(--red); cursor:pointer; font-size:13px; margin-left:6px; }
  .empty { text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); padding:20px; }
  .aktionen-row { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }

  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:200; align-items:center; justify-content:center; }
  .modal-overlay.open { display:flex; }
  .modal { background:var(--navy2); border:1px solid rgba(124,124,255,0.3); width:100%; max-width:480px; margin:20px; padding:20px; max-height:85vh; overflow-y:auto; }
  .modal-titel { font-family:var(--mono); font-size:11px; color:var(--blue-bright); margin-bottom:16px; display:flex; justify-content:space-between; }
  .modal-close { background:none; border:none; color:var(--text-dim); cursor:pointer; font-size:16px; }
  .field-textarea { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--sans); font-size:13px; padding:10px 12px; outline:none; min-height:100px; margin-bottom:14px; }
  .foto-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:14px; }
  .foto-grid img { width:100%; aspect-ratio:1; object-fit:cover; border:1px solid rgba(124,124,255,0.3); }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Einsatzplanung</div>

  <?php if ($kann_bearbeiten): ?>
  <div class="panel">
    <div class="panel-title">Einsatz planen</div>
    <label class="field-label">Objekt</label>
    <select class="field-select" id="objekt_id">
      <option value="">— wählen —</option>
      <?php foreach ($objekte as $o): ?>
        <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['firma']) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="field-label">Subunternehmer</label>
    <select class="field-select" id="subunternehmer_id">
      <option value="">— wählen —</option>
      <?php foreach ($subunternehmer as $s): ?>
        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="row-2">
      <div><label class="field-label">Datum</label><input class="field-input" type="date" id="datum" value="<?= date('Y-m-d') ?>"></div>
      <div><label class="field-label">Uhrzeit (optional)</label><input class="field-input" type="time" id="uhrzeit"></div>
    </div>
    <label class="field-label">Notiz (optional)</label>
    <input class="field-input" type="text" id="notiz">
    <button class="btn btn-primary" onclick="einsatzHinzufuegen()">+ Einsatz einplanen</button>
  </div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-title">Kommende Einsätze (nächste 14 Tage)</div>
    <?php if (empty($nach_datum)): ?>
      <div class="empty">Keine Einsätze geplant</div>
    <?php else: ?>
      <?php foreach ($nach_datum as $datum => $liste): ?>
      <div class="tag-block">
        <div class="tag-titel"><?= date('d.m.Y (D)', strtotime($datum)) ?></div>
        <?php foreach ($liste as $e):
            $fotos = json_decode($e['fotos'] ?? '[]', true) ?: [];
        ?>
        <div class="einsatz-karte" id="einsatz-<?= $e['id'] ?>">
          <div class="einsatz-kopf">
            <div class="einsatz-info" onclick="notizModalOeffnen(<?= $e['id'] ?>, '<?= htmlspecialchars($e['firma'], ENT_QUOTES) ?>', <?= htmlspecialchars(json_encode($e['notiz'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($fotos), ENT_QUOTES) ?>)">
              <div class="einsatz-firma"><?= htmlspecialchars($e['firma']) ?><?= $e['uhrzeit'] ? ' — ' . substr($e['uhrzeit'],0,5) . ' Uhr' : '' ?></div>
              <div class="einsatz-meta"><?= htmlspecialchars($e['subunternehmer_name'] ?? '—') ?><?= $e['notiz'] ? ' · 📝 Notiz vorhanden' : '' ?><?= !empty($fotos) ? ' · 📷 ' . count($fotos) . ' Foto(s)' : '' ?></div>
              <div class="checkin-status">
                <?php if ($e['checkin_zeit']): ?>
                  <span class="checkin-badge aktiv">✓ Check-in <?= date('H:i', strtotime($e['checkin_zeit'])) ?></span>
                <?php endif; ?>
                <?php if ($e['checkout_zeit']): ?>
                  <span class="checkin-badge aktiv">✓ Check-out <?= date('H:i', strtotime($e['checkout_zeit'])) ?></span>
                <?php elseif ($e['checkin_zeit']): ?>
                  <span class="checkin-badge wartend">⏳ Läuft...</span>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($kann_bearbeiten): ?>
              <button class="loeschen-x" onclick="einsatzLoeschen(<?= $e['id'] ?>)">✕</button>
            <?php endif; ?>
          </div>

          <?php if ($kann_bearbeiten): ?>
          <div class="aktionen-row">
            <?php if (!$e['checkin_zeit']): ?>
              <button class="mini-btn checkin" onclick="checkin(<?= $e['id'] ?>)">▶ Check-in</button>
            <?php elseif (!$e['checkout_zeit']): ?>
              <button class="mini-btn checkout" onclick="checkout(<?= $e['id'] ?>)">■ Check-out</button>
            <?php endif; ?>
            <button class="mini-btn foto" onclick="document.getElementById('foto-input-<?= $e['id'] ?>').click()">📷 Foto</button>
            <input type="file" id="foto-input-<?= $e['id'] ?>" accept="image/*" capture="environment" style="display:none;" onchange="fotoHochladen(<?= $e['id'] ?>, this)">
            <select class="status-select" onchange="statusAendern(<?= $e['id'] ?>, this.value)">
              <option value="geplant" <?= $e['status'] === 'geplant' ? 'selected' : '' ?>>Geplant</option>
              <option value="erledigt" <?= $e['status'] === 'erledigt' ? 'selected' : '' ?>>Erledigt</option>
              <option value="ausgefallen" <?= $e['status'] === 'ausgefallen' ? 'selected' : '' ?>>Ausgefallen</option>
            </select>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- NOTIZ + FOTOS MODAL -->
<div class="modal-overlay" id="modal-notiz">
  <div class="modal">
    <div class="modal-titel">
      <span id="notiz-modal-titel">Einsatz-Details</span>
      <button class="modal-close" onclick="modalSchliessen()">✕</button>
    </div>
    <div id="notiz-foto-grid" class="foto-grid"></div>
    <label class="field-label">Notiz</label>
    <textarea class="field-textarea" id="notiz-text"></textarea>
    <?php if ($kann_bearbeiten): ?>
      <button class="btn btn-primary" onclick="notizSpeichern()">Speichern</button>
    <?php endif; ?>
  </div>
</div>

<script>
let aktuelleEinsatzId = null;

async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('einsaetze.php', { method:'POST', body:fd });
  return res.json();
}

async function einsatzHinzufuegen() {
  const objekt_id = document.getElementById('objekt_id').value;
  if (!objekt_id) { alert('Objekt wählen'); return; }
  const res = await api({
    action: 'einsatz_add', objekt_id,
    subunternehmer_id: document.getElementById('subunternehmer_id').value,
    datum: document.getElementById('datum').value,
    uhrzeit: document.getElementById('uhrzeit').value,
    notiz: document.getElementById('notiz').value,
  });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function statusAendern(id, status) {
  await api({ action: 'status_aendern', id, status });
}

async function checkin(id) {
  const res = await api({ action: 'checkin', id });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function checkout(id) {
  const res = await api({ action: 'checkout', id });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function fotoHochladen(id, input) {
  const datei = input.files[0];
  if (!datei) return;
  const fd = new FormData();
  fd.append('foto', datei);
  const uploadRes = await fetch('einsatz_foto_upload.php', { method:'POST', body:fd });
  const uploadDaten = await uploadRes.json();
  if (uploadDaten.error) { alert(uploadDaten.error); return; }

  const res = await api({ action: 'foto_hinzufuegen', id, url: uploadDaten.url });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function einsatzLoeschen(id) {
  if (!confirm('Einsatz löschen?')) return;
  const res = await api({ action: 'einsatz_loeschen', id });
  if (res.error) { alert(res.error); return; }
  document.getElementById('einsatz-' + id)?.remove();
}

function notizModalOeffnen(id, firma, notiz, fotos) {
  aktuelleEinsatzId = id;
  document.getElementById('notiz-modal-titel').textContent = firma;
  document.getElementById('notiz-text').value = notiz || '';
  const grid = document.getElementById('notiz-foto-grid');
  grid.innerHTML = (fotos && fotos.length)
    ? fotos.map(f => `<img src="${f}" onclick="window.open('${f}','_blank')">`).join('')
    : '';
  document.getElementById('modal-notiz').classList.add('open');
}

function modalSchliessen() {
  document.getElementById('modal-notiz').classList.remove('open');
}

async function notizSpeichern() {
  const notiz = document.getElementById('notiz-text').value;
  const res = await api({ action: 'notiz_speichern', id: aktuelleEinsatzId, notiz });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}
</script>
</div>
</body>
</html>
