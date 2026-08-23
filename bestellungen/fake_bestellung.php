<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('bestellungen', 'bearbeiten');
$pdo = db();

$kanal_labels = ['ebay' => 'eBay', 'amazon' => 'Amazon', 'tiktok' => 'TikTok Shop'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $produkt_id = (int)($_POST['produkt_id'] ?? 0);
    $kanal = $_POST['kanal'] ?? 'ebay';
    $kaeufer = trim($_POST['kaeufer_name'] ?? '');
    $strasse = trim($_POST['versand_strasse'] ?? '');
    $plz = trim($_POST['versand_plz'] ?? '');
    $ort = trim($_POST['versand_ort'] ?? '');
    $menge = max(1, (int)($_POST['menge'] ?? 1));
    $preis = (float)($_POST['verkaufspreis_brutto'] ?? 0);

    if (!in_array($kanal, ['ebay', 'amazon', 'tiktok'])) $kanal = 'ebay';

    if ($produkt_id && $kaeufer) {
        $stmt = $pdo->prepare("SELECT name FROM lager_produkte WHERE id = ?");
        $stmt->execute([$produkt_id]);
        $produkt_name = $stmt->fetchColumn();

        $pdo->prepare("
            INSERT INTO bestellungen
                (ist_test, kanal, status, kaeufer_name, versand_strasse, versand_plz, versand_ort,
                 produkt_id, artikel_name, menge, verkaufspreis_brutto, bestelldatum)
            VALUES (1, ?, 'neu', ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([$kanal, $kaeufer, $strasse, $plz, $ort, $produkt_id, $produkt_name, $menge, $preis ?: null]);

        require_once __DIR__ . '/../telegram/notify.php';
        telegram_senden("🧪 [TEST] Neue Bestellung ({$kanal_labels[$kanal]}): {$produkt_name} x{$menge} — {$kaeufer}");

        header('Location: bestellungen.php');
        exit;
    }
}

$produkte = $pdo->query("SELECT id, name, vk_preis_brutto FROM lager_produkte WHERE aktiv = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$fake_namen = ['Max Mustermann', 'Anna Schmidt', 'Jonas Weber', 'Lisa Becker', 'Tom Fischer', 'Julia Hoffmann'];
$fake_strassen = ['Hauptstraße 12', 'Bahnhofstr. 5', 'Gartenweg 8', 'Am Markt 3', 'Schulstraße 21'];
$fake_orte = [['20095', 'Hamburg'], ['10115', 'Berlin'], ['80331', 'München'], ['50667', 'Köln'], ['28195', 'Bremen']];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Test-Bestellung erstellen — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-mid: #262A3D;
    --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --orange: #FBBF24;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }

  .main { max-width:520px; margin:0 auto; padding:28px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; color:#F5F6FA; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--orange); margin-bottom:24px; }

  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:6px; display:block; }
  .field-input, .field-select {
    width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25);
    color:var(--text); font-family:var(--mono); font-size:13px; padding:10px 12px; outline:none;
    margin-bottom:14px;
  }
  .field-input:focus, .field-select:focus { border-color:var(--blue-bright); }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .kanal-row { display:flex; gap:8px; margin-bottom:14px; }
  .kanal-btn { flex:1; padding:10px; border:1px solid rgba(124,124,255,0.25); background:none; color:var(--text-dim); font-family:var(--mono); font-size:11px; cursor:pointer; }
  .kanal-btn.active { background:rgba(148,148,255,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }
  .btn-fake { background:none; border:1px solid var(--orange); color:var(--orange); padding:8px 14px; font-family:var(--mono); font-size:10px; cursor:pointer; margin-bottom:16px; width:100%; }
  .btn-fake:hover { background:rgba(251,191,36,0.1); }
  .btn-submit { width:100%; background:none; border:1px solid var(--blue-bright); color:var(--blue-bright); padding:14px; font-family:var(--mono); font-size:13px; cursor:pointer; margin-top:6px; }
  .btn-submit:hover { background:rgba(148,148,255,0.1); }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Test-Bestellung erstellen</div>
  <div class="page-sub">Simuliert eine eingehende Bestellung für Testzwecke</div>

  <div class="panel">
    <button type="button" class="btn-fake" onclick="zufallsdatenEinfuellen()">🎲 Zufällige Testdaten einfüllen</button>

    <form method="POST">
      <label class="field-label">Kanal</label>
      <div class="kanal-row">
        <button type="button" class="kanal-btn active" data-kanal="ebay" onclick="kanalWaehlen('ebay')">eBay</button>
        <button type="button" class="kanal-btn" data-kanal="amazon" onclick="kanalWaehlen('amazon')">Amazon</button>
        <button type="button" class="kanal-btn" data-kanal="tiktok" onclick="kanalWaehlen('tiktok')">TikTok</button>
      </div>
      <input type="hidden" name="kanal" id="kanal" value="ebay">

      <label class="field-label">Produkt</label>
      <select class="field-select" name="produkt_id" id="produkt_id" required>
        <option value="">— wählen —</option>
        <?php foreach ($produkte as $p): ?>
          <option value="<?= $p['id'] ?>" data-preis="<?= $p['vk_preis_brutto'] ?? '' ?>"><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <label class="field-label">Käufername</label>
      <input class="field-input" type="text" name="kaeufer_name" id="kaeufer_name" required>

      <label class="field-label">Straße + Nr.</label>
      <input class="field-input" type="text" name="versand_strasse" id="versand_strasse">

      <div class="row-2">
        <div>
          <label class="field-label">PLZ</label>
          <input class="field-input" type="text" name="versand_plz" id="versand_plz">
        </div>
        <div>
          <label class="field-label">Ort</label>
          <input class="field-input" type="text" name="versand_ort" id="versand_ort">
        </div>
      </div>

      <div class="row-2">
        <div>
          <label class="field-label">Menge</label>
          <input class="field-input" type="number" name="menge" id="menge" value="1" min="1">
        </div>
        <div>
          <label class="field-label">Verkaufspreis (€, brutto)</label>
          <input class="field-input" type="number" step="0.01" name="verkaufspreis_brutto" id="verkaufspreis_brutto">
        </div>
      </div>

      <button type="submit" class="btn-submit">Test-Bestellung anlegen</button>
    </form>
  </div>
</div>

<script>
const namen = <?= json_encode($fake_namen) ?>;
const strassen = <?= json_encode($fake_strassen) ?>;
const orte = <?= json_encode($fake_orte) ?>;

function kanalWaehlen(k) {
  document.getElementById('kanal').value = k;
  document.querySelectorAll('.kanal-btn').forEach(b => b.classList.toggle('active', b.dataset.kanal === k));
}

document.getElementById('produkt_id').addEventListener('change', function() {
  const preis = this.options[this.selectedIndex]?.dataset.preis;
  if (preis) document.getElementById('verkaufspreis_brutto').value = preis;
});

function zufallsdatenEinfuellen() {
  document.getElementById('kaeufer_name').value = namen[Math.floor(Math.random() * namen.length)];
  document.getElementById('versand_strasse').value = strassen[Math.floor(Math.random() * strassen.length)];
  const ort = orte[Math.floor(Math.random() * orte.length)];
  document.getElementById('versand_plz').value = ort[0];
  document.getElementById('versand_ort').value = ort[1];
  document.getElementById('menge').value = Math.floor(Math.random() * 3) + 1;

  const kanaele = ['ebay', 'amazon', 'tiktok'];
  kanalWaehlen(kanaele[Math.floor(Math.random() * kanaele.length)]);

  const select = document.getElementById('produkt_id');
  if (select.options.length > 1) {
    select.selectedIndex = Math.floor(Math.random() * (select.options.length - 1)) + 1;
    select.dispatchEvent(new Event('change'));
  }
}
</script>
</div>
</body>
</html>
