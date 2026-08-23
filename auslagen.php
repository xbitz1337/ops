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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/theme.css">
</head>
<body>
<?php require_once __DIR__ . '/_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main schmal">
  <div class="page-title">Auslagen</div>
  <div class="page-sub">Wer hat wie viel für die Firma vorgestreckt</div>

  <?php if (!empty($offen_je_person)): ?>
  <div class="kpi-row">
    <?php foreach ($offen_je_person as $op): ?>
    <div class="kpi-card">
      <div class="kpi-label">Offen — <?= htmlspecialchars($op['person']) ?></div>
      <div class="kpi-value">€<?= number_format($op['summe'], 2, ',', '.') ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-title">Neue Auslage erfassen</div>
    <div class="row-2">
      <div>
        <label>Person</label>
        <input id="neu-person" list="personen-vorschlaege" placeholder="Name eintragen">
        <datalist id="personen-vorschlaege">
          <option value="Artis">
          <option value="Nour">
        </datalist>
      </div>
      <div>
        <label>Betrag (€)</label>
        <input type="number" step="0.01" id="neu-betrag">
      </div>
    </div>
    <label>Grund</label>
    <input id="neu-grund" placeholder="z.B. Starlink Pickup Göteborg, Amex privat belastet">
    <label>Datum</label>
    <input type="date" id="neu-datum" value="<?= date('Y-m-d') ?>">
    <button class="btn-primary" onclick="auslageAnlegen()">+ Erfassen</button>
  </div>

  <div class="filter-tabs">
    <a href="auslagen.php" class="filter-tab <?= !$status_filter ? 'active' : '' ?>">Alle</a>
    <a href="auslagen.php?status=offen" class="filter-tab <?= $status_filter==='offen'?'active':'' ?>">⏳ Offen</a>
    <a href="auslagen.php?status=erstattet" class="filter-tab <?= $status_filter==='erstattet'?'active':'' ?>">✓ Erstattet</a>
  </div>

  <?php if (empty($auslagen)): ?>
    <div class="empty">Keine Einträge</div>
  <?php else: ?>
    <?php foreach ($auslagen as $a): ?>
    <div class="karte <?= $a['status'] ?>" id="auslage-<?= $a['id'] ?>">
      <div class="kopf">
        <div>
          <span class="badge"><?= htmlspecialchars($a['person'] === 'qaf' ? 'Artis' : ($a['person'] === 'qns' ? 'Nour' : $a['person'])) ?></span>
          <span class="datum-klein"><?= date('d.m.Y', strtotime($a['datum'])) ?></span>
        </div>
        <div class="betrag <?= $a['status']==='erstattet'?'erstattet':'' ?>">€<?= number_format($a['betrag'], 2, ',', '.') ?></div>
      </div>
      <div class="grund"><?= htmlspecialchars($a['grund']) ?></div>
      <div class="meta">
        <?= $status_labels[$a['status']] ?>
        <?= $a['erstattet_am'] ? ' am ' . date('d.m.Y', strtotime($a['erstattet_am'])) : '' ?>
      </div>
      <div class="aktionen">
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
