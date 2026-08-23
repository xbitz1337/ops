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
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #0f1923; --navy2: #152236; --blue-accent: #2d6aad; --blue-bright: #4a9edd;
    --text: #c8dff0; --text-dim: #5a7a9a; --green: #2ecc71; --orange: #e67e22; --red:#e74c3c;
    --mono: 'Share Tech Mono', monospace; --sans: 'Exo 2', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .main { max-width:640px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#e8f4ff; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); letter-spacing:1px; margin-bottom:20px; }

  .panel { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:18px; margin-bottom:18px; }
  .field-input { width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--sans); font-size:14px; padding:12px; outline:none; margin-bottom:10px; }
  .btn { font-family:var(--mono); font-size:11px; letter-spacing:1px; text-transform:uppercase; padding:12px 18px; cursor:pointer; border:1px solid; background:none; text-decoration:none; display:inline-block; text-align:center; }
  .btn-primary { color:var(--green); border-color:rgba(46,204,113,0.4); width:100%; }

  .trip-karte { display:block; background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:16px 18px; margin-bottom:10px; text-decoration:none; color:var(--text); }
  .trip-karte:hover { border-color:var(--blue-bright); }
  .trip-name { font-size:16px; font-weight:700; }
  .trip-meta { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-top:6px; }
  .trip-status-badge { font-family:var(--mono); font-size:9px; padding:3px 9px; border-radius:8px; }
  .status-geplant { background:rgba(74,158,221,0.15); color:var(--blue-bright); }
  .status-laufend { background:rgba(230,126,34,0.15); color:var(--orange); }
  .status-abgeschlossen { background:rgba(46,204,113,0.15); color:var(--green); }
  .fortschritt-bar { height:5px; background:rgba(45,106,173,0.15); margin-top:10px; overflow:hidden; }
  .fortschritt-fill { height:100%; background:var(--green); }
  .empty { text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); padding:30px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Sammel-Trips</div>
  <div class="page-sub">// ABHOLTOUREN PLANEN UND ABARBEITEN</div>

  <div class="panel">
    <input class="field-input" id="neu-name" placeholder="z.B. August SE Trip">
    <button class="btn btn-primary" onclick="tripAnlegen()">+ Neuen Trip anlegen</button>
  </div>

  <?php if (empty($trips)): ?>
    <div class="empty">// NOCH KEIN TRIP ANGELEGT</div>
  <?php else: ?>
    <?php foreach ($trips as $t):
        $anzahl = (int)$t['anzahl_stopps'];
        $erledigt = (int)$t['anzahl_erledigt'];
        $prozent = $anzahl > 0 ? round($erledigt / $anzahl * 100) : 0;
    ?>
    <a href="trip_details.php?id=<?= $t['id'] ?>" class="trip-karte">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <div class="trip-name"><?= htmlspecialchars($t['name']) ?></div>
        <span class="trip-status-badge status-<?= $t['status'] ?>"><?= $status_labels[$t['status']] ?></span>
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