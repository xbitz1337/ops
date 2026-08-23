<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
$user = currentUser();
$pdo = db();
$trip_id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'stopp_hinzufuegen') {
        $store_name = trim($_POST['store_name'] ?? '');
        $adresse = trim($_POST['adresse'] ?? '');
        $abhol_link = trim($_POST['abhol_link'] ?? '');
        $bestellnummer = trim($_POST['bestellnummer'] ?? '');
        $notiz = trim($_POST['notiz'] ?? '');
        if (!$store_name) { echo json_encode(['error' => 'Store-Name fehlt']); exit; }

        $max_reihenfolge = (int)$pdo->query("SELECT COALESCE(MAX(reihenfolge),0) FROM sammel_trip_stopps WHERE trip_id = $trip_id")->fetchColumn();

        $pdo->prepare("
            INSERT INTO sammel_trip_stopps (trip_id, reihenfolge, store_name, adresse, abhol_link, bestellnummer, notiz)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$trip_id, $max_reihenfolge + 1, $store_name, $adresse, $abhol_link, $bestellnummer, $notiz]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'stopp_erledigt') {
        $id = (int)($_POST['id'] ?? 0);
        $erledigt = (int)($_POST['erledigt'] ?? 0);
        $pdo->prepare("UPDATE sammel_trip_stopps SET erledigt = ?, erledigt_am = ? WHERE id = ?")
            ->execute([$erledigt, $erledigt ? date('Y-m-d H:i:s') : null, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'stopp_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM sammel_trip_stopps WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'stopp_verschieben') {
        $id = (int)($_POST['id'] ?? 0);
        $richtung = $_POST['richtung'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM sammel_trip_stopps WHERE id = ?");
        $stmt->execute([$id]);
        $aktueller = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$aktueller) { echo json_encode(['error' => 'Nicht gefunden']); exit; }

        $vergleich = $richtung === 'hoch' ? '<' : '>';
        $order = $richtung === 'hoch' ? 'DESC' : 'ASC';
        $nachbar_stmt = $pdo->prepare("
            SELECT * FROM sammel_trip_stopps WHERE trip_id = ? AND reihenfolge $vergleich ?
            ORDER BY reihenfolge $order LIMIT 1
        ");
        $nachbar_stmt->execute([$aktueller['trip_id'], $aktueller['reihenfolge']]);
        $nachbar = $nachbar_stmt->fetch(PDO::FETCH_ASSOC);

        if ($nachbar) {
            $pdo->prepare("UPDATE sammel_trip_stopps SET reihenfolge = ? WHERE id = ?")->execute([$nachbar['reihenfolge'], $aktueller['id']]);
            $pdo->prepare("UPDATE sammel_trip_stopps SET reihenfolge = ? WHERE id = ?")->execute([$aktueller['reihenfolge'], $nachbar['id']]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannt']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM sammel_trips WHERE id = ?");
$stmt->execute([$trip_id]);
$trip = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$trip) { die('Trip nicht gefunden'); }

$stmt = $pdo->prepare("SELECT * FROM sammel_trip_stopps WHERE trip_id = ? ORDER BY reihenfolge ASC");
$stmt->execute([$trip_id]);
$stopps = $stmt->fetchAll(PDO::FETCH_ASSOC);

$gesamt = count($stopps);
$erledigt_anzahl = count(array_filter($stopps, fn($s) => $s['erledigt']));
$prozent = $gesamt > 0 ? round($erledigt_anzahl / $gesamt * 100) : 0;
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($trip['name']) ?> — NA Ops Hub</title>
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
  .zurueck-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; display:inline-block; margin-bottom:16px; }
  .page-title { font-size:20px; font-weight:700; letter-spacing:1px; color:#e8f4ff; margin-bottom:10px; }

  .fortschritt-box { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:16px 18px; margin-bottom:18px; }
  .fortschritt-text { font-family:var(--mono); font-size:12px; color:var(--text-dim); margin-bottom:8px; }
  .fortschritt-bar { height:8px; background:rgba(45,106,173,0.15); overflow:hidden; }
  .fortschritt-fill { height:100%; background:var(--green); transition:width 0.3s; }

  .panel { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:18px; margin-bottom:18px; }
  .panel-title { font-family:var(--mono); font-size:11px; letter-spacing:2px; text-transform:uppercase; color:var(--blue-bright); margin-bottom:14px; }
  .field-input { width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--sans); font-size:14px; padding:11px 12px; outline:none; margin-bottom:10px; }
  .btn { font-family:var(--mono); font-size:11px; letter-spacing:1px; text-transform:uppercase; padding:11px 16px; cursor:pointer; border:1px solid; background:none; text-decoration:none; display:inline-block; text-align:center; }
  .btn-primary { color:var(--green); border-color:rgba(46,204,113,0.4); width:100%; }

  .stopp-karte { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:16px; margin-bottom:10px; display:flex; gap:12px; }
  .stopp-karte.erledigt { opacity:0.45; }
  .stopp-nummer { font-family:var(--mono); font-size:22px; font-weight:700; color:var(--blue-bright); min-width:32px; }
  .stopp-inhalt { flex:1; }
  .stopp-name { font-size:16px; font-weight:700; margin-bottom:2px; }
  .stopp-adresse { font-family:var(--mono); font-size:11px; color:var(--text-dim); }
  .stopp-notiz { font-size:12px; color:var(--text-dim); margin-top:4px; }
  .stopp-aktionen { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }
  .stopp-btn { font-family:var(--mono); font-size:10px; padding:9px 14px; border:1px solid; text-decoration:none; text-align:center; cursor:pointer; background:none; }
  .stopp-btn.navigieren { color:var(--blue-bright); border-color:rgba(74,158,221,0.4); }
  .stopp-btn.abholen { color:var(--orange); border-color:rgba(230,126,34,0.4); }
  .stopp-btn.erledigt-btn { color:var(--green); border-color:rgba(46,204,113,0.4); }
  .stopp-sort { display:flex; flex-direction:column; gap:4px; }
  .sort-btn { background:none; border:1px solid rgba(45,106,173,0.25); color:var(--text-dim); cursor:pointer; width:28px; height:24px; font-size:11px; }
  .sort-btn:hover { color:var(--blue-bright); border-color:var(--blue-bright); }
  .loeschen-btn { background:none; border:none; color:var(--text-dim); cursor:pointer; font-size:14px; align-self:flex-start; }
  .empty { text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); padding:24px; }

  .stopp-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:400; align-items:center; justify-content:center; }
  .stopp-modal-overlay.open { display:flex; }
  .stopp-modal { background:var(--navy2); border:1px solid rgba(45,106,173,0.3); width:100%; max-width:420px; margin:20px; padding:20px; max-height:88vh; overflow-y:auto; }
  .stopp-modal-kopf { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
  .modal-close-btn { background:none; border:none; color:var(--text-dim); font-size:16px; cursor:pointer; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <a href="trip.php" class="zurueck-link">← Alle Trips</a>
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
    <div class="page-title" style="margin-bottom:0;"><?= htmlspecialchars($trip['name']) ?></div>
    <button class="btn btn-primary" style="width:auto; padding:10px 18px;" onclick="stoppFormOeffnen()">+ Stopp</button>
  </div>

  <div class="fortschritt-box">
    <div class="fortschritt-text"><?= $erledigt_anzahl ?> von <?= $gesamt ?> Stopps erledigt (<?= $prozent ?>%)</div>
    <div class="fortschritt-bar"><div class="fortschritt-fill" style="width:<?= $prozent ?>%;"></div></div>
  </div>

  <?php if (empty($stopps)): ?>
    <div class="empty">// NOCH KEINE STOPPS — OBEN "+ STOPP" ANTIPPEN</div>
  <?php else: ?>
    <?php foreach ($stopps as $i => $s): ?>
    <div class="stopp-karte <?= $s['erledigt'] ? 'erledigt' : '' ?>" id="stopp-<?= $s['id'] ?>">
      <div class="stopp-sort">
        <button class="sort-btn" onclick="stoppVerschieben(<?= $s['id'] ?>, 'hoch')" <?= $i === 0 ? 'disabled' : '' ?>>▲</button>
        <button class="sort-btn" onclick="stoppVerschieben(<?= $s['id'] ?>, 'runter')" <?= $i === $gesamt - 1 ? 'disabled' : '' ?>>▼</button>
      </div>
      <div class="stopp-nummer"><?= $i + 1 ?></div>
      <div class="stopp-inhalt">
        <div class="stopp-name"><?= htmlspecialchars($s['store_name']) ?></div>
        <?php if ($s['adresse']): ?><div class="stopp-adresse">📍 <?= htmlspecialchars($s['adresse']) ?></div><?php endif; ?>
        <?php if ($s['bestellnummer']): ?><div class="stopp-adresse">Bestell-Nr.: <?= htmlspecialchars($s['bestellnummer']) ?></div><?php endif; ?>
        <?php if ($s['notiz']): ?><div class="stopp-notiz">💬 <?= htmlspecialchars($s['notiz']) ?></div><?php endif; ?>
        <div class="stopp-aktionen">
          <?php if ($s['adresse']): ?>
            <a class="stopp-btn navigieren" href="https://www.google.com/maps/dir/?api=1&destination=<?= urlencode($s['adresse']) ?>" target="_blank">🧭 Navigieren</a>
          <?php endif; ?>
          <?php if ($s['abhol_link']): ?>
            <a class="stopp-btn abholen" href="<?= htmlspecialchars($s['abhol_link']) ?>" target="_blank">📦 Abholen / QR</a>
          <?php endif; ?>
          <button class="stopp-btn erledigt-btn" onclick="stoppErledigt(<?= $s['id'] ?>, <?= $s['erledigt'] ? 0 : 1 ?>)">
            <?= $s['erledigt'] ? '↩ Rückgängig' : '✓ Erledigt' ?>
          </button>
        </div>
      </div>
      <button class="loeschen-btn" onclick="stoppLoeschen(<?= $s['id'] ?>)">✕</button>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Stopp-Hinzufügen-Modal -->
<div class="stopp-modal-overlay" id="stopp-modal-overlay" onclick="if(event.target===this) stoppFormSchliessen()">
  <div class="stopp-modal">
    <div class="stopp-modal-kopf">
      <span class="panel-title" style="margin-bottom:0;">// NEUEN STOPP HINZUFÜGEN</span>
      <button class="modal-close-btn" onclick="stoppFormSchliessen()">✕</button>
    </div>
    <input class="field-input" id="neu-store" placeholder="Store-Name (z.B. Clas Ohlson Göteborg)">
    <input class="field-input" id="neu-adresse" placeholder="Adresse">
    <input class="field-input" id="neu-link" placeholder="Abholungslink / QR-Code-Link">
    <input class="field-input" id="neu-bestellnummer" placeholder="Bestellnummer (optional)">
    <input class="field-input" id="neu-notiz" placeholder="Notiz (optional)">
    <button class="btn btn-primary" onclick="stoppHinzufuegen()">+ Stopp hinzufügen</button>
  </div>
</div>

<script>
async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('trip_details.php?id=<?= $trip_id ?>', { method:'POST', body:fd });
  return res.json();
}

function stoppFormOeffnen() {
  document.getElementById('stopp-modal-overlay').classList.add('open');
}
function stoppFormSchliessen() {
  document.getElementById('stopp-modal-overlay').classList.remove('open');
}

async function stoppHinzufuegen() {
  const store_name = document.getElementById('neu-store').value.trim();
  if (!store_name) { alert('Store-Name eingeben'); return; }
  const res = await api({
    action: 'stopp_hinzufuegen',
    store_name,
    adresse: document.getElementById('neu-adresse').value,
    abhol_link: document.getElementById('neu-link').value,
    bestellnummer: document.getElementById('neu-bestellnummer').value,
    notiz: document.getElementById('neu-notiz').value,
  });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function stoppErledigt(id, erledigt) {
  const res = await api({ action: 'stopp_erledigt', id, erledigt });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function stoppLoeschen(id) {
  if (!confirm('Stopp wirklich löschen?')) return;
  const res = await api({ action: 'stopp_loeschen', id });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function stoppVerschieben(id, richtung) {
  const res = await api({ action: 'stopp_verschieben', id, richtung });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}
</script>
</div>
</body>
</html>