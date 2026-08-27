<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('lager', 'bearbeiten');
$user = currentUser();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $produkt_id = (int)($_POST['produkt_id'] ?? 0);
    $menge = max(1, (int)($_POST['menge'] ?? 1));
    $land = trim($_POST['land'] ?? '');
    $lieferant = trim($_POST['lieferant'] ?? '');
    $rechnungsnummer = trim($_POST['rechnungsnummer'] ?? '');
    $bruttobetrag = (float)($_POST['bruttobetrag'] ?? 0);
    $mwst_modus = $_POST['mwst_modus'] ?? 'mit_mwst'; // mit_mwst | reverse_charge
    $mwst_satz = $mwst_modus === 'reverse_charge' ? 0.0 : (float)($_POST['mwst_satz'] ?? 0);
    $notiz = trim($_POST['notiz'] ?? '');
    $bewegt_am = $_POST['bewegt_am'] ?? date('Y-m-d');

    if ($produkt_id && $land && $bruttobetrag > 0) {
        if ($mwst_modus === 'reverse_charge') {
            // Reverse Charge / steuerfreie innergemeinschaftliche Lieferung —
            // es wurde gar keine ausländische MwSt berechnet, der eingegebene
            // Betrag IST bereits der Netto-Wareneinsatz. Keine Vorsteuer zum
            // Zurückholen, also auch kein Eintrag im Vorsteuer-Tracking nötig.
            $nettobetrag_gesamt = $bruttobetrag;
            $mwst_betrag = 0.0;
            $ek_preis_lager_je_stueck = round($bruttobetrag / $menge, 4);
        } else {
            // Netto-Anteil wird berechnet und im Vorsteuer-Tracking gemerkt —
            // ABER: solange die Erstattung nicht SICHER ist (Status "Erhalten"),
            // gilt im Lager weiterhin der volle BRUTTO-Betrag als Wareneinsatz.
            // Ihr habt das Geld ja tatsächlich komplett vorgestreckt — die
            // Umstellung auf Netto passiert automatisch erst, sobald die
            // Vorsteuer als "Erhalten" markiert wird (siehe auslaendische_vorsteuer.php).
            $nettobetrag_gesamt = round($bruttobetrag / (1 + $mwst_satz / 100), 2);
            $mwst_betrag = round($bruttobetrag - $nettobetrag_gesamt, 2);
            $ek_preis_lager_je_stueck = round($bruttobetrag / $menge, 4);
        }

        $pdo->beginTransaction();
        try {
            // 1. Normale Lager-Zugangs-Bewegung buchen — EK-Preis ist erstmal
            //    BRUTTO (bzw. Netto bei Reverse Charge, da dort kein Risiko
            //    besteht) — wird bei Erstattung automatisch auf Netto korrigiert
            $notiz_typ = $mwst_modus === 'reverse_charge' ? 'Auslandseinkauf, Reverse Charge' : 'Auslandseinkauf (Brutto vorgestreckt)';
            $notiz_bewegung = "{$notiz_typ} ({$land}" . ($lieferant ? ", {$lieferant}" : '') . ")" . ($notiz ? " — {$notiz}" : '');
            $pdo->prepare("
                INSERT INTO lager_bewegungen (produkt_id, typ, menge, ek_preis_netto, notiz, erfasst_von, bewegt_am)
                VALUES (?, 'zugang_einkauf', ?, ?, ?, ?, ?)
            ")->execute([$produkt_id, $menge, $ek_preis_lager_je_stueck, $notiz_bewegung, $user['username'] === 'qaf' ? 'qaf' : 'qns', $bewegt_am]);
            $bewegung_id = $pdo->lastInsertId();

            // 2. Die zurückzuholende ausländische MwSt separat erfassen —
            //    NUR wenn tatsächlich welche berechnet wurde
            if ($mwst_modus !== 'reverse_charge') {
                $pdo->prepare("
                    INSERT INTO auslaendische_vorsteuer
                        (lager_bewegung_id, produkt_id, land, lieferant, rechnungsnummer, bruttobetrag, mwst_satz, mwst_betrag, nettobetrag, notiz, erfasst_von)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $bewegung_id, $produkt_id, $land, $lieferant, $rechnungsnummer,
                    $bruttobetrag, $mwst_satz, $mwst_betrag, $nettobetrag_gesamt, $notiz,
                    $user['username'] === 'qaf' ? 'qaf' : 'qns',
                ]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die('Fehler beim Speichern: ' . htmlspecialchars($e->getMessage()));
        }

        header('Location: auslaendische_vorsteuer.php');
        exit;
    }
}

$produkte = $pdo->query("SELECT id, name FROM lager_produkte WHERE aktiv = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Auslandseinkauf erfassen — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --orange: #FBBF24;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .main { max-width:640px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; color:#F5F6FA; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--orange); margin-bottom:24px; }

  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; margin-bottom:16px; border-radius:20px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:6px; display:block; }
  .field-input, .field-select { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--sans); font-size:14px; padding:10px 12px; outline:none; margin-bottom:14px; border-radius:12px; }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

  .berechnung-box { background:rgba(251,191,36,0.1); border:1px solid rgba(251,191,36,0.3); padding:14px 16px; margin-bottom:16px; font-family:var(--mono); }
  .berechnung-box .zeile { display:flex; justify-content:space-between; font-size:12px; padding:4px 0; }
  .berechnung-box .zeile.final { border-top:1px solid rgba(251,191,36,0.3); margin-top:6px; padding-top:8px; font-weight:700; color:var(--orange); }
  .berechnung-box .zeile.netto { border-top:1px solid rgba(251,191,36,0.15); margin-top:4px; padding-top:8px; color:var(--green); font-weight:700; }

  .btn-submit { width:100%; background:none; border:1px solid var(--green); color:var(--green); padding:14px; font-family:var(--mono); font-size:13px; cursor:pointer; }
  .btn-submit:hover { background:rgba(74,222,128,0.1); }
  .zurueck-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; display:inline-block; margin-bottom:20px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <a href="auslaendische_vorsteuer.php" class="zurueck-link">← Ausländische Vorsteuer — Übersicht</a>
  <div class="page-title">Auslandseinkauf erfassen</div>
  <div class="page-sub">Bucht Lager-Zugang + trackt die zurückzuholende MwSt</div>

  <form method="POST" id="form">
    <div class="panel">
      <label class="field-label">Produkt</label>
      <select class="field-select" name="produkt_id" id="produkt_id" required>
        <option value="">— wählen —</option>
        <?php foreach ($produkte as $p): ?>
          <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <label class="field-label">Menge (Stück)</label>
      <input class="field-input" type="number" name="menge" id="menge" value="1" min="1">

      <div class="row-2">
        <div>
          <label class="field-label">Land</label>
          <input class="field-input" type="text" name="land" id="land" placeholder="z.B. Schweden" required>
        </div>
        <div>
          <label class="field-label">Lieferant (optional)</label>
          <input class="field-input" type="text" name="lieferant" id="lieferant">
        </div>
      </div>

      <label class="field-label">Rechnungsnummer (optional)</label>
      <input class="field-input" type="text" name="rechnungsnummer" id="rechnungsnummer">

      <label class="field-label">Wie wurde abgerechnet?</label>
      <div class="row-2" style="margin-bottom:14px;">
        <label style="display:flex; align-items:center; gap:8px; font-family:var(--sans); font-size:13px; cursor:pointer; border:1px solid rgba(124,124,255,0.25); padding:10px 12px; background:rgba(20,22,31,0.8);">
          <input type="radio" name="mwst_modus" value="mit_mwst" id="modus-mit-mwst" checked onchange="modusGewechselt()"> Mit ausländischer MwSt
        </label>
        <label style="display:flex; align-items:center; gap:8px; font-family:var(--sans); font-size:13px; cursor:pointer; border:1px solid rgba(124,124,255,0.25); padding:10px 12px; background:rgba(20,22,31,0.8);">
          <input type="radio" name="mwst_modus" value="reverse_charge" id="modus-reverse-charge" onchange="modusGewechselt()"> Reverse Charge (ohne MwSt)
        </label>
      </div>

      <div class="row-2" id="mwst-felder-block">
        <div>
          <label class="field-label" id="brutto-label">Bezahlter Bruttobetrag gesamt (€)</label>
          <input class="field-input" type="number" step="0.01" name="bruttobetrag" id="bruttobetrag" required>
        </div>
        <div id="mwst-satz-wrap">
          <label class="field-label">MwSt-Satz im Land (%)</label>
          <input class="field-input" type="number" step="0.01" name="mwst_satz" id="mwst_satz" placeholder="z.B. 25">
        </div>
      </div>
      <div class="field-label" id="reverse-charge-hinweis" style="display:none; color:var(--green); margin-top:-8px; margin-bottom:14px;">
        // KEINE MWST BERECHNET — DER EINGETRAGENE BETRAG IST BEREITS NETTO, NICHTS ZUM ZURÜCKHOLEN NÖTIG
      </div>

      <label class="field-label">Einkaufsdatum</label>
      <input class="field-input" type="date" name="bewegt_am" id="bewegt_am" value="<?= date('Y-m-d') ?>">

      <label class="field-label">Notiz (optional)</label>
      <input class="field-input" type="text" name="notiz" id="notiz">
    </div>

    <div class="berechnung-box" id="berechnung-box" style="display:none;">
      <div class="zeile"><span>Bruttobetrag bezahlt</span><span id="b-brutto">—</span></div>
      <div class="zeile"><span>Davon ausländische MwSt (zurückholbar)</span><span id="b-mwst">—</span></div>
      <div class="zeile"><span>Wert NACH Erstattung (netto, zur Info)</span><span id="b-netto">—</span></div>
      <div class="zeile final"><span id="je-stueck-label">Gebuchter EK-Preis je Stück (Brutto, vorerst)</span><span id="b-je-stueck">—</span></div>
    </div>

    <button type="submit" class="btn-submit">✓ Einkauf erfassen</button>
  </form>
</div>

<script>
const eur = (n) => n.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';

function modusGewechselt() {
  const reverseCharge = document.getElementById('modus-reverse-charge').checked;
  document.getElementById('mwst-satz-wrap').style.display = reverseCharge ? 'none' : 'block';
  document.getElementById('reverse-charge-hinweis').style.display = reverseCharge ? 'block' : 'none';
  document.getElementById('brutto-label').textContent = reverseCharge ? 'Bezahlter Betrag gesamt (€, netto)' : 'Bezahlter Bruttobetrag gesamt (€)';
  document.getElementById('je-stueck-label').textContent = reverseCharge ? 'Gebuchter EK-Preis je Stück' : 'Gebuchter EK-Preis je Stück (Brutto, vorerst)';
  document.getElementById('mwst_satz').required = !reverseCharge;
  berechnen();
}

function berechnen() {
  const brutto = parseFloat(document.getElementById('bruttobetrag').value) || 0;
  const menge = parseInt(document.getElementById('menge').value) || 1;
  const box = document.getElementById('berechnung-box');
  const reverseCharge = document.getElementById('modus-reverse-charge').checked;

  if (reverseCharge) {
    if (!brutto) { box.style.display = 'none'; return; }
    document.getElementById('b-brutto').textContent = eur(brutto);
    document.getElementById('b-mwst').textContent = eur(0);
    document.getElementById('b-netto').textContent = eur(brutto);
    document.getElementById('b-je-stueck').textContent = eur(brutto / menge);
    box.style.display = 'block';
    return;
  }

  const satz = parseFloat(document.getElementById('mwst_satz').value) || 0;
  if (!brutto || !satz) { box.style.display = 'none'; return; }

  const netto = brutto / (1 + satz / 100);
  const mwst = brutto - netto;
  // Gebucht wird BRUTTO je Stück — Netto ist nur zur Info, was nach
  // erfolgreicher Erstattung als Wareneinsatz übrig bleiben wird
  const jeStueckGebucht = brutto / menge;

  document.getElementById('b-brutto').textContent = eur(brutto);
  document.getElementById('b-mwst').textContent = eur(mwst);
  document.getElementById('b-netto').textContent = eur(netto);
  document.getElementById('b-je-stueck').textContent = eur(jeStueckGebucht);
  box.style.display = 'block';
}

['bruttobetrag','mwst_satz','menge'].forEach(id => document.getElementById(id).addEventListener('input', berechnen));
</script>
</div>
</body>
</html>