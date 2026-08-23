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

    if ($action === 'trip_anlegen') {
        $name = trim($_POST['name'] ?? '');
        if (!$name) { echo json_encode(['error' => 'Name fehlt']); exit; }
        $pdo->prepare("INSERT INTO sammel_trips (name, erstellt_von) VALUES (?, ?)")
            ->execute([$name, $user['username']]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'trip_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['geplant','laufend','abgeschlossen'])) { echo json_encode(['error' => 'Ungültig']); exit; }
        $pdo->prepare("UPDATE sammel_trips SET status = ? WHERE id = ?")->execute([$status, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'trip_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM sammel_trips WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannt']);
    exit;
}

$trips = $pdo->query("
    SELECT t.*, COUNT(s.id) AS anzahl_stopps, SUM(s.erledigt) AS anzahl_erledigt
    FROM sammel_trips t
    LEFT JOIN sammel_trip_stopps s ON s.trip_id = t.id
    GROUP BY t.id
    ORDER BY FIELD(t.status,'laufend','geplant','abgeschlossen'), t.erstellt_am DESC
")->fetchAll(PDO::FETCH_ASSOC);

$status_labels = ['geplant' => '📅 Geplant', 'laufend' => '🚗 Läuft gerade', 'abgeschlossen' => '✓ Abgeschlossen'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sammel-Trips — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/theme.css">
</head>
<body>
<?php require_once __DIR__ . '/_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main schmal">
  <div class="page-title">Sammel-Trips</div>
  <div class="page-sub">Abholtouren planen und abarbeiten</div>

  <div class="panel">
    <input id="neu-name" placeholder="z.B. August SE Trip">
    <button class="btn-primary" onclick="tripAnlegen()">+ Neuen Trip anlegen</button>
  </div>

  <?php if (empty($trips)): ?>
    <div class="empty">Noch kein Trip angelegt</div>
  <?php else: ?>
    <?php foreach ($trips as $t):
        $anzahl = (int)$t['anzahl_stopps'];
        $erledigt = (int)$t['anzahl_erledigt'];
        $prozent = $anzahl > 0 ? round($erledigt / $anzahl * 100) : 0;
    ?>
    <a href="trip_details.php?id=<?= $t['id'] ?>" class="trip-karte">
      <div class="kopf">
        <div class="trip-name"><?= htmlspecialchars($t['name']) ?></div>
        <span class="status-badge status-<?= $t['status'] ?>"><?= $status_labels[$t['status']] ?></span>
      </div>
      <div class="trip-meta"><?= $erledigt ?> von <?= $anzahl ?> Stopps erledigt</div>
      <?php if ($anzahl > 0): ?>
      <div class="fortschritt-bar"><div class="fortschritt-fill" style="width:<?= $prozent ?>%;"></div></div>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('trip.php', { method:'POST', body:fd });
  return res.json();
}

async function tripAnlegen() {
  const name = document.getElementById('neu-name').value.trim();
  if (!name) { alert('Namen eingeben'); return; }
  const res = await api({ action: 'trip_anlegen', name });
  if (res.error) { alert(res.error); return; }
  window.location.href = 'trip_details.php?id=' + res.id;
}
</script>
</div>
</body>
</html>
