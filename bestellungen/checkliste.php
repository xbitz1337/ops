<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('bestellungen', 'bearbeiten');
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT b.*, p.name AS produkt_name, p.foto_url
    FROM bestellungen b
    LEFT JOIN lager_produkte p ON p.id = b.produkt_id
    WHERE b.id = ?
");
$stmt->execute([$id]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$b) { die('Bestellung nicht gefunden.'); }

$kanal_labels = ['ebay' => 'eBay', 'amazon' => 'Amazon', 'tiktok' => 'TikTok Shop', 'direktverkauf' => 'Direktverkauf'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Checkliste — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-mid: #262A3D;
    --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --orange: #FBBF24;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }

  .main { max-width:480px; margin:0 auto; padding:20px 16px 60px; }

  .schritt { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; margin-bottom:16px; }
  .schritt-nr { font-family:var(--mono); font-size:9px; color:var(--blue-bright); margin-bottom:10px; }
  .schritt.done { opacity:0.5; }
  .schritt.done .schritt-nr { color:var(--green); }

  .produkt-box { display:flex; gap:14px; align-items:center; }
  .produkt-img { width:64px; height:64px; background:var(--blue-mid); border:1px solid rgba(124,124,255,0.3); display:flex; align-items:center; justify-content:center; font-size:26px; flex-shrink:0; overflow:hidden; }
  .produkt-img img { width:100%; height:100%; object-fit:cover; }
  .produkt-name { font-size:16px; font-weight:700; margin-bottom:4px; }
  .produkt-menge { font-family:var(--mono); font-size:12px; color:var(--text-dim); }
  .kanal-badge { font-family:var(--mono); font-size:9px; padding:2px 7px; border-radius:8px; background:rgba(148,148,255,0.15); color:var(--blue-bright); margin-left:6px; }

  .adresse-box { font-size:14px; line-height:1.7; }
  .adresse-box .kaeufer { font-weight:700; font-size:16px; margin-bottom:4px; }

  .bestaetigung-box { margin-top:16px; padding:14px; background:rgba(20,22,31,0.6); border:1px solid rgba(124,124,255,0.25); display:flex; align-items:flex-start; gap:10px; cursor:pointer; }
  .bestaetigung-box input[type=checkbox] { width:20px; height:20px; margin-top:1px; accent-color:var(--blue-bright); flex-shrink:0; cursor:pointer; }
  .bestaetigung-text { font-size:13px; line-height:1.5; }

  .btn-gross { width:100%; padding:16px; font-family:var(--mono); font-size:13px; border:1px solid var(--blue-bright); color:var(--blue-bright); background:none; cursor:pointer; margin-top:14px; }
  .btn-gross:hover:not(:disabled) { background:rgba(148,148,255,0.1); }
  .btn-gross:disabled { opacity:0.35; cursor:not-allowed; }
  .btn-gross.gruen { border-color:var(--green); color:var(--green); }
  .btn-gross.gruen:hover:not(:disabled) { background:rgba(74,222,128,0.1); }

  .scan-area { margin-top:14px; }
  #scan-video { width:100%; border:1px solid rgba(124,124,255,0.3); display:none; margin-bottom:10px; }
  #scan-box video, #scan-box canvas { width:100%; border:1px solid rgba(124,124,255,0.3); }
  .field-input {
    width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25);
    color:var(--text); font-family:var(--mono); font-size:14px; padding:12px; outline:none;
    margin-bottom:10px;
  }
  .field-input:focus { border-color:var(--blue-bright); }
  .scan-status { font-family:var(--mono); font-size:10px; color:var(--text-dim); text-align:center; margin-bottom:10px; }
  .scan-status.gefunden { color:var(--green); }

  .fertig-box { text-align:center; padding:40px 20px; }
  .fertig-check { width:60px; height:60px; border-radius:50%; background:rgba(74,222,128,0.12); border:2px solid var(--green); display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:26px; color:var(--green); }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">

  <!-- SCHRITT 1: PRODUKT + BESTÄTIGUNG -->
  <div class="schritt <?= $b['status'] !== 'zu_verpacken' ? 'done' : '' ?>">
    <div class="schritt-nr">Schritt 1 — Richtiges Produkt nehmen</div>
    <div class="produkt-box">
      <div class="produkt-img">
        <?php if ($b['foto_url']): ?><img src="<?= htmlspecialchars($b['foto_url']) ?>"><?php else: ?>📦<?php endif; ?>
      </div>
      <div>
        <div class="produkt-name"><?= htmlspecialchars($b['artikel_name'] ?: $b['produkt_name'] ?: '—') ?><span class="kanal-badge"><?= $kanal_labels[$b['kanal']] ?></span></div>
        <div class="produkt-menge"><?= $b['menge'] ?> Stk<?= $b['verkaufspreis_brutto'] ? ' · €' . number_format($b['verkaufspreis_brutto'], 2, ',', '.') : '' ?></div>
      </div>
    </div>

    <?php if ($b['status'] === 'zu_verpacken'): ?>
    <label class="bestaetigung-box" for="bestaetigung-check">
      <input type="checkbox" id="bestaetigung-check" onchange="bestaetigungGeaendert()">
      <span class="bestaetigung-text">Ich habe <strong><?= htmlspecialchars($b['artikel_name'] ?: $b['produkt_name']) ?></strong> in der richtigen Menge (<strong><?= $b['menge'] ?> Stk</strong>) genommen und kontrolliert.</span>
    </label>
    <?php endif; ?>
  </div>

  <!-- SCHRITT 2: ADRESSE -->
  <div class="schritt <?= $b['status'] !== 'zu_verpacken' ? 'done' : '' ?>">
    <div class="schritt-nr">Schritt 2 — Versandadresse</div>
    <div class="adresse-box">
      <div class="kaeufer"><?= htmlspecialchars($b['kaeufer_name']) ?></div>
      <?= htmlspecialchars($b['versand_strasse']) ?><br>
      <?= htmlspecialchars($b['versand_plz']) ?> <?= htmlspecialchars($b['versand_ort']) ?><br>
      <?= htmlspecialchars($b['versand_land']) ?>
    </div>

    <?php if ($b['status'] === 'zu_verpacken'): ?>
      <div id="dpd-bereich">
        <button class="btn-gross" id="dpd-label-btn" onclick="dpdLabelErstellen()" disabled>🚚 DPD-Label automatisch erstellen</button>
        <div class="scan-status" id="dpd-status"></div>

        <div id="dpd-vorschau" style="display:none; margin-top:12px;">
          <iframe id="dpd-pdf-frame" style="width:100%; height:280px; border:1px solid rgba(124,124,255,0.3); background:#fff;"></iframe>
          <label class="bestaetigung-box" for="dpd-gedruckt-check" style="margin-top:10px;">
            <input type="checkbox" id="dpd-gedruckt-check" onchange="dpdGedrucktGeaendert()">
            <span class="bestaetigung-text">Ich habe das Label ausgedruckt und auf das Paket geklebt.</span>
          </label>
          <button class="btn-gross gruen" id="dpd-weiter-btn" onclick="window.location.reload()" disabled>✓ Weiter</button>
        </div>
      </div>

      <div style="text-align:center; font-family:var(--mono); font-size:9px; color:var(--text-dim); margin:14px 0;">— ODER —</div>

      <button class="btn-gross" id="label-btn" onclick="labelErstellt()" disabled>✓ Label manuell erstellt — weiter</button>
      <div class="scan-status" style="margin-top:8px;">Solange DPD noch nicht verbunden ist: Label wie gewohnt manuell erstellen (z.B. bei eBay), dann hier weiter.</div>
    <?php endif; ?>
  </div>

  <!-- SCHRITT 3: VERSAND -->
  <?php if ($b['status'] === 'versand_vorbereitet'): ?>
  <div class="schritt">
    <div class="schritt-nr">Schritt 3 — Sendungsnummer erfassen &amp; Versand abschließen</div>

    <div class="scan-area">
      <video id="scan-video" playsinline></video>
      <div id="scan-box" style="display:none; margin-bottom:10px; position:relative;"></div>
      <button type="button" class="btn-gross" id="scan-start-btn" onclick="scanStarten()">📷 Barcode scannen</button>
      <div class="scan-status" id="scan-status"></div>
      <input class="field-input" type="text" id="sendungsnummer" placeholder="Oder Sendungsnummer manuell eintragen">
    </div>

    <button class="btn-gross gruen" onclick="versandAbschliessen()">✓ VERSAND ABGESCHLOSSEN</button>
  </div>
  <?php endif; ?>

  <?php if ($b['status'] === 'versendet'): ?>
  <div class="fertig-box">
    <div class="fertig-check">✓</div>
    <div style="font-family:var(--mono); font-size:13px; color:var(--green); margin-bottom:6px;">VERSAND ABGESCHLOSSEN</div>
    <?php if ($b['sendungsnummer']): ?>
      <div style="font-family:var(--mono); font-size:11px; color:var(--text-dim);">📦 <?= htmlspecialchars($b['sendungsnummer']) ?></div>
    <?php endif; ?>
    <a href="bestellungen.php" class="back-link" style="display:block; margin-top:20px;">← Zurück zu allen Bestellungen</a>
  </div>
  <?php endif; ?>

</div>

<script>
const bestellungId = <?= (int)$b['id'] ?>;

function bestaetigungGeaendert() {
  const check = document.getElementById('bestaetigung-check');
  const btn = document.getElementById('label-btn');
  const dpdBtn = document.getElementById('dpd-label-btn');
  if (btn) btn.disabled = !check.checked;
  if (dpdBtn) dpdBtn.disabled = !check.checked;
}

async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('bestellungen.php', { method:'POST', body:fd });
  return res.json();
}

async function labelErstellt() {
  const res = await api({ action:'label_erstellt', id: bestellungId });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function dpdLabelErstellen() {
  const status = document.getElementById('dpd-status');
  const btn = document.getElementById('dpd-label-btn');
  btn.disabled = true;
  status.textContent = 'Label wird erstellt...';

  const res = await api({ action: 'dpd_label_erstellen', id: bestellungId });

  if (!res.erfolgreich) {
    status.textContent = '⚠️ ' + (res.fehler || 'DPD noch nicht verbunden — bitte manuell erstellen (Button unten).');
    btn.disabled = false;
    return;
  }

  // Sobald DPD wirklich angebunden ist, greift ab hier automatisch der
  // Druck-Pflicht-Ablauf — nichts muss dann noch angepasst werden.
  status.textContent = '✓ Label erstellt — Sendungsnr. ' + res.sendungsnummer;
  document.getElementById('dpd-vorschau').style.display = 'block';
  document.getElementById('dpd-pdf-frame').src = 'data:application/pdf;base64,' + res.label_pdf_base64;
  document.getElementById('sendungsnummer-hidden')?.remove();
  btn.style.display = 'none';
}

function dpdGedrucktGeaendert() {
  const check = document.getElementById('dpd-gedruckt-check');
  document.getElementById('dpd-weiter-btn').disabled = !check.checked;
}

async function versandAbschliessen() {
  const sendungsnummer = document.getElementById('sendungsnummer').value.trim();
  const res = await api({ action:'versand_abschliessen', id: bestellungId, sendungsnummer });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

// ── BARCODE-SCAN ─────────────────────────────────────────────────────────────
let quaggaGeladen = false;

async function scanStarten() {
  const status = document.getElementById('scan-status');

  if ('BarcodeDetector' in window) {
    scanMitBarcodeDetector();
    return;
  }

  status.textContent = 'Lade Scanner...';
  if (!quaggaGeladen) {
    await ladeQuaggaJs();
    quaggaGeladen = true;
  }
  scanMitQuagga();
}

function ladeQuaggaJs() {
  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/quagga/0.12.1/quagga.min.js';
    script.onload = resolve;
    script.onerror = reject;
    document.head.appendChild(script);
  });
}

function scanMitQuagga() {
  const status = document.getElementById('scan-status');
  const scanBox = document.getElementById('scan-box');
  scanBox.style.display = 'block';

  Quagga.init({
    inputStream: {
      type: 'LiveStream',
      target: scanBox,
      constraints: { facingMode: 'environment' }
    },
    decoder: {
      readers: ['code_128_reader', 'ean_reader', 'ean_8_reader', 'code_39_reader']
    }
  }, function(err) {
    if (err) {
      status.textContent = 'Kamerazugriff nicht möglich — bitte manuell eintragen.';
      return;
    }
    Quagga.start();
    status.textContent = 'Kamera aktiv — Barcode ins Bild halten...';
  });

  Quagga.onDetected(function(result) {
    const code = result.codeResult.code;
    document.getElementById('sendungsnummer').value = code;
    status.textContent = '✓ Barcode erkannt: ' + code;
    status.classList.add('gefunden');
    Quagga.stop();
    scanBox.style.display = 'none';
  });
}

async function scanMitBarcodeDetector() {
  const status = document.getElementById('scan-status');
  try {
    const video = document.getElementById('scan-video');
    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    video.srcObject = stream;
    video.style.display = 'block';
    await video.play();

    const detector = new BarcodeDetector();
    status.textContent = 'Kamera aktiv — Barcode ins Bild halten...';

    const interval = setInterval(async () => {
      try {
        const codes = await detector.detect(video);
        if (codes.length > 0) {
          document.getElementById('sendungsnummer').value = codes[0].rawValue;
          status.textContent = '✓ Barcode erkannt: ' + codes[0].rawValue;
          status.classList.add('gefunden');
          clearInterval(interval);
          stream.getTracks().forEach(t => t.stop());
          video.style.display = 'none';
        }
      } catch (e) { /* weiterversuchen */ }
    }, 400);
  } catch (e) {
    status.textContent = 'Kamerazugriff nicht möglich — bitte manuell eintragen.';
  }
}
</script>
</div>
</body>
</html>
