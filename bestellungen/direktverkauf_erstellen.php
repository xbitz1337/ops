<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $produkt_id = (int)($_POST['produkt_id'] ?? 0);
    $kaeufer = trim($_POST['kaeufer_name'] ?? '');
    $menge = max(1, (int)($_POST['menge'] ?? 1));
    $preis = (float)($_POST['verkaufspreis_brutto'] ?? 0);
    $zahlungsart = $_POST['zahlungsart'] ?? 'ueberweisung';
    $zahlungsstatus = $_POST['zahlungsstatus'] ?? 'ausstehend';
    $versand_noetig = isset($_POST['versand_noetig']) ? 1 : 0;
    $ist_reverse_charge = isset($_POST['ist_reverse_charge']) ? 1 : 0;
    $kunde_land = trim($_POST['kunde_land'] ?? '');
    $kunde_ustid = trim($_POST['kunde_ustid'] ?? '');

    if (!in_array($zahlungsart, ['ueberweisung', 'bar', 'paypal', 'sonstiges'])) $zahlungsart = 'sonstiges';
    if (!in_array($zahlungsstatus, ['bezahlt', 'ausstehend'])) $zahlungsstatus = 'ausstehend';
    if (!$ist_reverse_charge) { $kunde_land = null; $kunde_ustid = null; }

    if ($produkt_id && $kaeufer) {
        $stmt = $pdo->prepare("SELECT name FROM lager_produkte WHERE id = ?");
        $stmt->execute([$produkt_id]);
        $produkt_name = $stmt->fetchColumn();

        $pdo->prepare("
            INSERT INTO bestellungen
                (ist_test, kanal, status, kaeufer_name, produkt_id, artikel_name, menge,
                 verkaufspreis_brutto, bestelldatum, zahlungsart, zahlungsstatus, versand_noetig,
                 ist_reverse_charge, kunde_land, kunde_ustid)
            VALUES (0, 'direktverkauf', 'neu', ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)
        ")->execute([$kaeufer, $produkt_id, $produkt_name, $menge, $preis ?: null, $zahlungsart, $zahlungsstatus, $versand_noetig, $ist_reverse_charge, $kunde_land, $kunde_ustid]);

        header('Location: bestellungen.php');
        exit;
    }
}

require_once __DIR__ . '/../lager/fifo_helper.php';

$produkte_roh = $pdo->query("SELECT id, name, vk_preis_brutto, ek_preis_netto FROM lager_produkte WHERE aktiv = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$produkte = [];
foreach ($produkte_roh as $p) {
    $fifo = fifo_lagerwert_berechnen($pdo, $p['id'], null, (float)($p['ek_preis_netto'] ?? 0));
    $p['ek_avg'] = $fifo['ek_avg'];
    $p['bestand'] = $fifo['bestand'];
    $produkte[] = $p;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Direktverkauf erfassen — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --orange: #FBBF24;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }

  .main { max-width:520px; margin:0 auto; padding:28px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; color:#F5F6FA; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--green); margin-bottom:24px; }

  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; margin-bottom:16px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:6px; display:block; }
  .field-input, .field-select { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:10px 12px; outline:none; margin-bottom:14px; }
  .field-input:focus, .field-select:focus { border-color:var(--blue-bright); }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

  .checkbox-row { display:flex; align-items:center; gap:8px; margin-bottom:16px; cursor:pointer; }
  .checkbox-row input { width:16px; height:16px; accent-color:var(--blue-bright); }
  .checkbox-row label { font-family:var(--mono); font-size:11px; cursor:pointer; }

  .reverse-charge-block { display:none; background:rgba(251,191,36,0.08); border:1px solid rgba(251,191,36,0.3); padding:14px 16px; margin-bottom:16px; }
  .reverse-charge-block.sichtbar { display:block; }
  .reverse-charge-hinweis { font-family:var(--mono); font-size:9px; color:var(--orange); margin-bottom:12px; line-height:1.5; }

  .btn-submit { width:100%; background:none; border:1px solid var(--green); color:var(--green); padding:14px; font-family:var(--mono); font-size:13px; cursor:pointer; margin-top:6px; }
  .btn-submit:hover { background:rgba(74,222,128,0.1); }

  .gewinn-box { background:rgba(20,22,31,0.6); border:1px solid rgba(74,222,128,0.3); padding:16px; margin-bottom:16px; display:none; }
  .gewinn-box.sichtbar { display:block; }
  .gewinn-zeile { display:flex; justify-content:space-between; font-family:var(--mono); font-size:11px; padding:4px 0; }
  .gewinn-zeile .label { color:var(--text-dim); }
  .gewinn-zeile.final { border-top:1px solid rgba(74,222,128,0.2); margin-top:6px; padding-top:8px; }
  .gewinn-zeile.final .wert { color:var(--green); font-size:15px; font-weight:700; }

  .barcode-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:500; align-items:center; justify-content:center; }
  .barcode-overlay.open { display:flex; }
  .barcode-box { background:#000; width:100%; max-width:480px; margin:20px; }
  .barcode-kopf { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:var(--navy2); }
  .barcode-close { background:none; border:none; color:var(--text-dim); font-size:16px; cursor:pointer; }
  .barcode-viewport { width:100%; height:320px; position:relative; overflow:hidden; }
  .barcode-status { padding:10px 16px; font-family:var(--mono); font-size:10px; color:var(--text-dim); background:var(--navy2); }
</style>
<script src="https://cdn.jsdelivr.net/npm/@ericblade/quagga2@1.8.2/dist/quagga.min.js"></script>
</head>
<body>

<!-- Barcode-Scan-Overlay -->
<div class="barcode-overlay" id="barcode-scan-overlay">
  <div class="barcode-box">
    <div class="barcode-kopf">
      <span style="font-family:var(--mono); font-size:11px; color:var(--blue-bright); ">// BARCODE SCANNEN</span>
      <button class="barcode-close" onclick="barcodeScanAbbrechen()">✕</button>
    </div>
    <div id="barcode-scan-viewport" class="barcode-viewport"></div>
    <div id="barcode-scan-status" class="barcode-status">Kamera wird gestartet...</div>
  </div>
</div>

<div class="topbar">
  <a href="bestellungen.php" class="back-link">← Bestellungen</a>
  <div class="topbar-title">Direktverkauf</div>
  <div style="width:80px;"></div>
</div>

<div class="main">
  <div class="page-title">Direktverkauf erfassen</div>
  <div class="page-sub">// ECHTER VERKAUF — KEIN TEST</div>

  <div class="panel">
    <form method="POST" id="dv-form">
      <label class="field-label" style="display:flex; justify-content:space-between; align-items:center;">
        <span>Produkt</span>
        <span onclick="barcodeScanStarten('produkt_id')" style="cursor:pointer; color:var(--blue-bright); font-family:var(--mono); font-size:9px;">📷 SCANNEN</span>
      </label>
      <select class="field-select" name="produkt_id" id="produkt_id" required>
        <option value="">— wählen —</option>
        <?php foreach ($produkte as $p): ?>
          <option value="<?= $p['id'] ?>" data-preis="<?= $p['vk_preis_brutto'] ?? '' ?>" data-ek="<?= $p['ek_avg'] ?? 0 ?>" data-bestand="<?= $p['bestand'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['bestand'] ?> Stk auf Lager, Ø EK €<?= number_format($p['ek_avg'] ?? 0, 2, ',', '.') ?>)</option>
        <?php endforeach; ?>
      </select>

      <label class="field-label">Käufername</label>
      <input class="field-input" type="text" name="kaeufer_name" id="kaeufer_name" required>

      <div class="row-2">
        <div>
          <label class="field-label">Menge</label>
          <input class="field-input" type="number" name="menge" id="menge" value="1" min="1">
          <div id="bestand-warnung" style="display:none; font-family:var(--mono); font-size:10px; color:var(--orange); margin-top:-10px; margin-bottom:12px;"></div>
        </div>
        <div>
          <label class="field-label" id="preis-label">Verkaufspreis (€, brutto)</label>
          <input class="field-input" type="number" step="0.01" name="verkaufspreis_brutto" id="verkaufspreis_brutto">
        </div>
      </div>

      <div class="checkbox-row" onclick="document.getElementById('ist_reverse_charge').click()">
        <input type="checkbox" name="ist_reverse_charge" id="ist_reverse_charge" onclick="event.stopPropagation(); reverseChargeUmschalten()">
        <label onclick="event.stopPropagation()">B2B-Kunde im EU-Ausland (Reverse Charge — ohne deutsche MwSt)</label>
      </div>

      <div class="reverse-charge-block" id="reverse-charge-block">
        <div class="reverse-charge-hinweis">
          ⚠️ Reverse Charge gilt nur für B2B-Verkäufe an Unternehmen in anderen EU-Ländern mit gültiger USt-IdNr. Der eingetragene Preis oben gilt dann als NETTO-Preis (keine deutsche MwSt wird berechnet) — bitte auf der Rechnung den Hinweis "Steuerschuldnerschaft des Leistungsempfängers gem. §13b UStG" ergänzen.
        </div>
        <label class="field-label">Land des Kunden</label>
        <input class="field-input" type="text" name="kunde_land" id="kunde_land" placeholder="z.B. Tschechien">
        <label class="field-label">USt-IdNr. des Kunden</label>
        <input class="field-input" type="text" name="kunde_ustid" id="kunde_ustid" placeholder="z.B. CZ12345678" style="margin-bottom:0;">
      </div>

      <div class="row-2">
        <div>
          <label class="field-label">Zahlungsart</label>
          <select class="field-select" name="zahlungsart" id="zahlungsart">
            <option value="ueberweisung">Überweisung</option>
            <option value="bar">Bar</option>
            <option value="paypal">PayPal</option>
            <option value="sonstiges">Sonstiges</option>
          </select>
        </div>
        <div>
          <label class="field-label">Zahlungsstatus</label>
          <select class="field-select" name="zahlungsstatus" id="zahlungsstatus">
            <option value="ausstehend">Ausstehend</option>
            <option value="bezahlt">Bezahlt</option>
          </select>
        </div>
      </div>

      <div class="checkbox-row" onclick="document.getElementById('versand_noetig').click()">
        <input type="checkbox" name="versand_noetig" id="versand_noetig" onclick="event.stopPropagation()">
        <label onclick="event.stopPropagation()">Versand nötig (sonst: direkte Abholung/Übergabe)</label>
      </div>

      <div class="gewinn-box" id="gewinn-box">
        <div class="gewinn-zeile"><span class="label">VK netto</span><span class="wert" id="g-vk">—</span></div>
        <div class="gewinn-zeile"><span class="label">Wareneinsatz</span><span class="wert" id="g-ek">—</span></div>
        <div class="gewinn-zeile"><span class="label">Rohertrag</span><span class="wert" id="g-roh">—</span></div>
        <div class="gewinn-zeile"><span class="label">Steuerrücklage (30%)</span><span class="wert" id="g-ruecklage">—</span></div>
        <div class="gewinn-zeile final"><span class="label">Geschätzter Reingewinn</span><span class="wert" id="g-gewinn">—</span></div>
      </div>

      <button type="submit" class="btn-submit">✓ Direktverkauf erfassen</button>
    </form>
  </div>
</div>

<script>
const produktSelect = document.getElementById('produkt_id');
const preisInput = document.getElementById('verkaufspreis_brutto');
const mengeInput = document.getElementById('menge');

function reverseChargeUmschalten() {
  const aktiv = document.getElementById('ist_reverse_charge').checked;
  document.getElementById('reverse-charge-block').classList.toggle('sichtbar', aktiv);
  document.getElementById('preis-label').textContent = aktiv ? 'Verkaufspreis (€, NETTO — Reverse Charge)' : 'Verkaufspreis (€, brutto)';
  gewinnBerechnen();
}

let fifoAbfrageTimer = null;

async function gewinnBerechnen() {
  const opt = produktSelect.options[produktSelect.selectedIndex];
  const bestand = parseInt(opt?.dataset.bestand) || 0;
  const menge = parseInt(mengeInput.value) || 1;
  const warnEl = document.getElementById('bestand-warnung');
  if (opt?.value && menge > bestand) {
    warnEl.textContent = `⚠️ Nur ${bestand} Stk auf Lager, du trägst ${menge} ein.`;
    warnEl.style.display = 'block';
  } else {
    warnEl.style.display = 'none';
  }
  const preis = parseFloat(preisInput.value) || 0;
  const reverseCharge = document.getElementById('ist_reverse_charge').checked;
  const box = document.getElementById('gewinn-box');

  if (!preis || !opt?.value) { box.classList.remove('sichtbar'); return; }

  // Echte FIFO-Stückkosten vom Server holen (exakt, kein Durchschnitt) —
  // leicht entprellt, damit nicht bei jedem Tastenanschlag ein Request rausgeht
  clearTimeout(fifoAbfrageTimer);
  fifoAbfrageTimer = setTimeout(async () => {
    const fd = new FormData();
    fd.append('api', 1);
    fd.append('action', 'fifo_schaetzen');
    fd.append('produkt_id', opt.value);
    fd.append('menge', menge);
    const res = await fetch('bestellungen.php', { method: 'POST', body: fd });
    const daten = await res.json();
    const ek = daten.stueckkosten || 0;

    const eur = (n) => n.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';
    // Bei Reverse Charge ist der eingetragene Preis bereits NETTO (keine
    // deutsche MwSt wird berechnet) — sonst wie gehabt 19% raus rechnen
    const vkNetto = reverseCharge ? (preis * menge) : ((preis / 1.19) * menge);
    const wareneinsatz = ek * menge;
    const rohertrag = vkNetto - wareneinsatz;
    const ruecklage = Math.max(0, rohertrag * 0.30);
    const gewinn = rohertrag - ruecklage;

    document.getElementById('g-vk').textContent = eur(vkNetto);
    document.getElementById('g-ek').textContent = eur(wareneinsatz);
    document.getElementById('g-roh').textContent = eur(rohertrag);
    document.getElementById('g-ruecklage').textContent = eur(ruecklage);
    document.getElementById('g-gewinn').textContent = eur(gewinn);
    box.classList.add('sichtbar');
  }, 300);
}

produktSelect.addEventListener('change', function() {
  const preis = this.options[this.selectedIndex]?.dataset.preis;
  if (preis) preisInput.value = preis;
  gewinnBerechnen();
});
preisInput.addEventListener('input', gewinnBerechnen);
mengeInput.addEventListener('input', gewinnBerechnen);

// ── BARCODE-SCANNER ──────────────────────────────────────────────────────────
let barcodeZielFeld = null;
let barcodeDetectorAktiv = false;
let barcodeVideoStream = null;

async function barcodeScanStarten(zielFeldId) {
  barcodeZielFeld = zielFeldId;
  document.getElementById('barcode-scan-overlay').classList.add('open');
  document.getElementById('barcode-scan-status').textContent = 'Kamera wird gestartet...';
  const viewport = document.getElementById('barcode-scan-viewport');
  viewport.innerHTML = '';

  if ('BarcodeDetector' in window) {
    try {
      const video = document.createElement('video');
      video.style.width = '100%';
      video.style.height = '100%';
      video.style.objectFit = 'cover';
      video.setAttribute('playsinline', true);
      viewport.appendChild(video);

      barcodeVideoStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      video.srcObject = barcodeVideoStream;
      await video.play();

      const detector = new BarcodeDetector();
      barcodeDetectorAktiv = true;
      document.getElementById('barcode-scan-status').textContent = 'Barcode ins Bild halten...';

      const scanSchleife = async () => {
        if (!barcodeDetectorAktiv) return;
        try {
          const codes = await detector.detect(video);
          if (codes.length > 0) {
            barcodeGefunden(codes[0].rawValue);
            return;
          }
        } catch (e) { /* weiter versuchen */ }
        requestAnimationFrame(scanSchleife);
      };
      scanSchleife();
      return;
    } catch (e) {
      console.warn('Native BarcodeDetector fehlgeschlagen, nutze QuaggaJS', e);
    }
  }

  Quagga.init({
    inputStream: {
      type: 'LiveStream',
      target: viewport,
      constraints: { facingMode: 'environment' },
    },
    decoder: { readers: ['ean_reader', 'code_128_reader', 'upc_reader'] },
  }, function (err) {
    if (err) {
      document.getElementById('barcode-scan-status').textContent = 'Kamera konnte nicht gestartet werden.';
      return;
    }
    Quagga.start();
    document.getElementById('barcode-scan-status').textContent = 'Barcode ins Bild halten...';
  });

  Quagga.onDetected(function (result) {
    barcodeGefunden(result.codeResult.code);
  });
}

async function barcodeGefunden(code) {
  document.getElementById('barcode-scan-status').textContent = 'Gefunden: ' + code + ' — suche Produkt...';
  const fd = new FormData();
  fd.append('api', 1);
  fd.append('action', 'produkt_per_barcode');
  fd.append('barcode', code);
  const res = await fetch('bestellungen.php', { method: 'POST', body: fd }).then(r => r.json());
  barcodeScanAbbrechen();

  if (res.error) { alert(res.error); return; }

  const feld = document.getElementById(barcodeZielFeld);
  if (feld) {
    feld.value = res.produkt_id;
    feld.dispatchEvent(new Event('change'));
  }
}

function barcodeScanAbbrechen() {
  document.getElementById('barcode-scan-overlay').classList.remove('open');
  barcodeDetectorAktiv = false;
  if (barcodeVideoStream) {
    barcodeVideoStream.getTracks().forEach(t => t.stop());
    barcodeVideoStream = null;
  }
  try { Quagga.stop(); } catch (e) {}
}
</script>
</body>
</html>