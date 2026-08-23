<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('antworten');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'hinzufuegen') {
        $kategorie = trim($_POST['kategorie'] ?? '');
        $titel = trim($_POST['titel'] ?? '');
        $text = trim($_POST['text'] ?? '');
        if (!$kategorie || !$titel || !$text) { echo json_encode(['error' => 'Alle Felder ausfüllen']); exit; }
        $pdo->prepare("INSERT INTO standardantworten (kategorie, titel, text) VALUES (?, ?, ?)")->execute([$kategorie, $titel, $text]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM standardantworten WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

$suche = trim($_GET['suche'] ?? '');
$sql = "SELECT * FROM standardantworten WHERE 1=1";
$params = [];
if ($suche !== '') {
    $sql .= " AND (titel LIKE :s1 OR text LIKE :s2 OR kategorie LIKE :s3)";
    $params[':s1'] = "%$suche%"; $params[':s2'] = "%$suche%"; $params[':s3'] = "%$suche%";
}
$sql .= " ORDER BY kategorie, titel";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$antworten = $stmt->fetchAll(PDO::FETCH_ASSOC);

$gruppiert = [];
foreach ($antworten as $a) { $gruppiert[$a['kategorie']][] = $a; }
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Standardantworten — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --red: #F87171;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); z-index:100; }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }

  .main { max-width:800px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:22px; font-weight:700; color:#F5F6FA; margin-bottom:16px; }

  .toolbar { display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
  .suche-input { flex:1; min-width:200px; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--mono); font-size:12px; padding:10px 12px; outline:none; }
  .btn { font-family:var(--mono); font-size:10px; padding:10px 16px; cursor:pointer; border:1px solid; background:none; border-radius:12px; }
  .btn-primary { color:var(--blue-bright); border-color:rgba(148,148,255,0.4); }
  .btn-primary:hover { background:rgba(148,148,255,0.1); }

  .kategorie-block { margin-bottom:24px; }
  .kategorie-titel { font-family:var(--mono); font-size:11px; color:var(--blue-bright); padding-bottom:8px; margin-bottom:10px; border-bottom:1px dashed rgba(124,124,255,0.2); }

  .antwort-karte { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:14px 16px; margin-bottom:8px; }
  .antwort-titel { font-weight:700; font-size:14px; margin-bottom:6px; }
  .antwort-text { font-size:13px; color:var(--text); line-height:1.5; margin-bottom:10px; white-space:pre-wrap; }
  .antwort-aktionen { display:flex; gap:8px; }
  .mini-btn { font-family:var(--mono); font-size:9px; padding:6px 10px; border:1px solid rgba(124,124,255,0.3); background:none; color:var(--text); cursor:pointer; border-radius:10px; }
  .mini-btn.kopiert { color:var(--green); border-color:var(--green); }
  .mini-btn.loeschen { color:var(--red); border-color:rgba(248,113,113,0.3); }

  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:200; align-items:center; justify-content:center; }
  .modal-overlay.open { display:flex; }
  .modal { background:var(--navy2); border:1px solid rgba(124,124,255,0.3); width:100%; max-width:440px; margin:20px; padding:20px; border-radius:20px; }
  .modal-titel { font-family:var(--mono); font-size:11px; color:var(--blue-bright); margin-bottom:16px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:6px; display:block; }
  .field-input, .field-textarea { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--sans); font-size:13px; padding:10px 12px; outline:none; margin-bottom:14px; border-radius:12px; }
  .field-textarea { min-height:100px; resize:vertical; border-radius:12px; }
  .modal-aktionen { display:flex; gap:10px; justify-content:flex-end; }

  .empty { text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); padding:30px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Standardantworten</div>

  <div class="toolbar">
    <form method="GET" style="display:flex; gap:8px; flex:1;">
      <input class="suche-input" type="text" name="suche" placeholder="🔍 Durchsuchen..." value="<?= htmlspecialchars($suche) ?>">
    </form>
    <button class="btn btn-primary" onclick="openModal()">+ NEUE ANTWORT</button>
  </div>

  <?php if (empty($gruppiert)): ?>
    <div class="empty">Keine Einträge gefunden</div>
  <?php else: ?>
    <?php foreach ($gruppiert as $kategorie => $items): ?>
    <div class="kategorie-block">
      <div class="kategorie-titel"><?= htmlspecialchars($kategorie) ?></div>
      <?php foreach ($items as $a): ?>
      <div class="antwort-karte" id="antwort-<?= $a['id'] ?>">
        <div class="antwort-titel"><?= htmlspecialchars($a['titel']) ?></div>
        <div class="antwort-text"><?= htmlspecialchars($a['text']) ?></div>
        <div class="antwort-aktionen">
          <button class="mini-btn" onclick="textKopieren(this, <?= $a['id'] ?>)">📋 Kopieren</button>
          <button class="mini-btn loeschen" onclick="antwortLoeschen(<?= $a['id'] ?>)">✕ Löschen</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- MODAL: Neue Antwort -->
<div class="modal-overlay" id="modal-neu">
  <div class="modal">
    <div class="modal-titel">Neue Standardantwort</div>
    <label class="field-label">Kategorie</label>
    <input class="field-input" type="text" id="neu-kategorie" placeholder="z.B. Versand, Reklamation, Produktpflege">
    <label class="field-label">Titel</label>
    <input class="field-input" type="text" id="neu-titel" placeholder="Kurzer Titel zum Wiederfinden">
    <label class="field-label">Text</label>
    <textarea class="field-textarea" id="neu-text" placeholder="Der fertige Antworttext..."></textarea>
    <div class="modal-aktionen">
      <button class="btn" style="color:var(--text-dim); border-color:rgba(139,143,168,0.3);" onclick="closeModal()">Abbrechen</button>
      <button class="btn btn-primary" onclick="antwortHinzufuegen()">Speichern</button>
    </div>
  </div>
</div>

<script>
const antwortTexte = <?= json_encode(array_column($antworten, 'text', 'id')) ?>;

async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('antworten.php', { method:'POST', body:fd });
  return res.json();
}

function openModal() { document.getElementById('modal-neu').classList.add('open'); }
function closeModal() { document.getElementById('modal-neu').classList.remove('open'); }

async function antwortHinzufuegen() {
  const kategorie = document.getElementById('neu-kategorie').value.trim();
  const titel = document.getElementById('neu-titel').value.trim();
  const text = document.getElementById('neu-text').value.trim();
  if (!kategorie || !titel || !text) { alert('Bitte alle Felder ausfüllen'); return; }
  const res = await api({ action: 'hinzufuegen', kategorie, titel, text });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function antwortLoeschen(id) {
  if (!confirm('Diese Standardantwort wirklich löschen?')) return;
  const res = await api({ action: 'loeschen', id });
  if (res.error) { alert(res.error); return; }
  document.getElementById('antwort-' + id)?.remove();
}

function textKopieren(btn, id) {
  navigator.clipboard.writeText(antwortTexte[id]).then(() => {
    const original = btn.textContent;
    btn.textContent = '✓ Kopiert!';
    btn.classList.add('kopiert');
    setTimeout(() => { btn.textContent = original; btn.classList.remove('kopiert'); }, 1500);
  });
}
</script>
</div>
</body>
</html>
