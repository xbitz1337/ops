<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('dokumente');
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dokumente — NA Ops Hub</title>
<style>
  :root {
    --ink: #E4E6F0;
    --ink-dim: #8B8FA8;
    --ink-mid: #ABAFC4;
    --line: #2E3248;
    --bg: #14161F;
    --panel: #1D2030;
    --panel2: #262A3D;
    --accent: #7C7CFF;
    --accent-hover: #9494FF;
    --font: 'Inter', -apple-system, sans-serif;
    /* Brief-/Signatur-Vorschau bleibt bewusst wie echtes weißes Papier —
       unabhängig vom dunklen App-Theme, siehe .preview-* / .signatur-* unten. */
    --paper-ink: #1D1D1F;
    --paper-ink-dim: #86868B;
    --paper-ink-mid: #4B4B4D;
    --paper-line: #E5E5E7;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:var(--font); background:var(--bg); color:var(--ink); min-height:100vh; }

  .topbar {
    height:56px; display:flex; align-items:center; justify-content:space-between;
    padding:0 28px; background:var(--panel); border-bottom:1px solid var(--line); position:sticky; top:0; z-index:10;
  }
  .topbar-left { display:flex; align-items:center; gap:14px; }
  .topbar-title { font-size:15px; font-weight:600; }
  .back-link { font-size:13px; color:var(--accent); text-decoration:none; }
  .back-link:hover { text-decoration:underline; }

  .layout { display:grid; grid-template-columns:1fr 1fr; gap:0; max-width:1400px; margin:0 auto; min-height:calc(100vh - 56px); }
  @media(max-width:1000px) { .layout { grid-template-columns:1fr; } }

  .form-side { padding:36px 40px; border-right:1px solid var(--line); }
  .preview-side { padding:36px 40px; background:#FAFAFA; }

  h1 { font-size:24px; font-weight:700; margin-bottom:6px; }
  .subtitle { font-size:13px; color:var(--ink-dim); margin-bottom:28px; }

  .field-group { margin-bottom:20px; }
  label { display:block; font-size:12px; font-weight:600; color:var(--ink-mid); margin-bottom:6px; }
  select, input[type=text], input[type=date], textarea {
    width:100%; border:1px solid var(--line); border-radius:12px; padding:10px 12px;
    font-family:var(--font); font-size:13.5px; color:var(--ink); background:var(--panel2);
    outline:none; transition:border-color 0.15s;
  }
  select:focus, input:focus, textarea:focus { border-color:var(--accent); }
  textarea { resize:vertical; min-height:80px; line-height:1.5; }
  textarea#brieftext { min-height:260px; }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .hint { font-size:11px; color:var(--ink-dim); margin-top:5px; }

  .btn-generate {
    width:100%; background:var(--accent); color:#fff; border:none; border-radius:12px;
    padding:14px; font-size:14.5px; font-weight:600; cursor:pointer; margin-top:8px;
    transition:opacity 0.15s;
  }
  .btn-generate:hover { opacity:0.9; }

  .btn-delete {
    width:100%; background:var(--panel2); color:var(--red, #F87171); border:1px solid rgba(248,113,113,0.35); border-radius:12px;
    padding:12px; font-size:13.5px; font-weight:600; cursor:pointer; margin-top:10px;
    transition:background 0.15s;
  }
  .btn-delete:hover { background:rgba(248,113,113,0.1); }

  .verfasser-row { display:flex; gap:8px; }
  .verfasser-btn {
    flex:1; padding:10px; border:1px solid var(--line); border-radius:10px;
    background:var(--panel2); font-family:var(--font); font-size:13px; font-weight:500;
    color:var(--ink-mid); cursor:pointer; transition:all 0.15s;
  }
  .verfasser-btn:hover { border-color:var(--accent); }
  .verfasser-btn.active { background:var(--accent); border-color:var(--accent); color:#fff; }

  .signatur-pad-wrap {
    border:1px solid var(--paper-line); border-radius:10px; background:#fff; overflow:hidden;
  }
  #signatur-canvas { width:100%; height:150px; display:block; touch-action:none; cursor:crosshair; }
  .signatur-actions { display:flex; justify-content:space-between; align-items:center; padding:8px 12px; border-top:1px solid var(--paper-line); background:#FAFAFA; }
  .signatur-status { font-size:11px; color:var(--paper-ink-dim); }
  .signatur-status.signiert { color:#1a7f37; font-weight:600; }
  .btn-clear-signatur { background:none; border:none; color:var(--accent); font-size:12px; cursor:pointer; font-weight:500; }
  .btn-clear-signatur:hover { text-decoration:underline; }

  @media(max-width:600px) {
    .topbar { padding:0 14px; }
    .topbar-title { font-size:13px; }
    .back-link { font-size:12px; }
    .form-side, .preview-side { padding:20px 16px; }
    h1 { font-size:20px; }
    .verfasser-row { flex-wrap:wrap; }
    .verfasser-btn { flex:1 1 100px; font-size:12px; padding:9px 6px; }
    .preview-page { padding:26px 20px; }
    .preview-logo-zeile img { height:60px; }
    .preview-logo-zeile .fallback { font-size:20px; }
  }

  /* Brief-Vorschau: bewusst helles "Papier", siehe --paper-* oben */
  .preview-page {
    background:#fff; border:1px solid var(--paper-line); border-radius:12px;
    box-shadow:0 2px 20px rgba(0,0,0,0.06); padding:50px 44px;
    font-size:11.5px; line-height:1.6; min-height:600px; color:var(--paper-ink);
  }
  .preview-logo-zeile { display:flex; align-items:center; border-bottom:1px solid var(--paper-line); padding-bottom:14px; margin-bottom:6px; }
  .preview-logo-zeile .fallback { font-size:30px; font-weight:600; }
  .preview-logo-zeile img { height:95px; }
  .preview-absender { font-size:8px; color:var(--paper-ink-dim); margin-bottom:44px; }
  .preview-empfaenger { margin-bottom:36px; white-space:pre-wrap; font-size:11px; }
  .preview-datum { text-align:right; font-size:10.5px; color:var(--paper-ink-mid); margin-bottom:30px; }
  .preview-betreff { font-size:13px; font-weight:600; margin-bottom:18px; }
  .preview-text { white-space:pre-wrap; font-size:11.5px; }
  .preview-unterschrift { margin-top:60px; }
  .preview-unterschrift-linie { width:200px; border-top:1px solid var(--paper-ink); margin-top:50px; padding-top:6px; font-size:10px; color:var(--paper-ink-mid); }
  .preview-label { font-size:10px; color:var(--ink-dim); letter-spacing:1px; text-transform:uppercase; margin-bottom:12px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="layout">
  <div class="form-side">
    <h1>Dokument erstellen</h1>
    <div class="subtitle">Vorlage wählen oder frei schreiben — Briefkopf NA Commerce Solutions GmbH</div>
    <div style="margin-bottom:20px; display:flex; gap:16px; flex-wrap:wrap;">
      <a href="documents_history.php" style="font-size:13px; color:var(--accent); text-decoration:none;">📜 Historie ansehen →</a>
      <a href="../eigenbeleg/eigenbeleg.php" style="font-size:13px; color:var(--accent); text-decoration:none;">🚗 Eigenbeleg (Fahrtkosten) erstellen →</a>
    </div>

    <form id="doc-form" action="document_pdf.php" method="POST">
      <input type="hidden" name="dokument_id" id="dokument_id" value="">
      <div class="field-group">
        <label>Vorlage</label>
        <select id="vorlage-select" onchange="vorlageAnwenden()">
          <option value="leer">Formloser Brief (leer)</option>
          <option value="zahlungsbestaetigung">Zahlungsbestätigung</option>
          <option value="auftragsbestaetigung">Auftragsbestätigung</option>
          <option value="mahnung">Zahlungserinnerung</option>
          <option value="geschaeftsbeziehung">Bestätigung Geschäftsbeziehung</option>
          <option value="kuendigungsbestaetigung">Kündigungsbestätigung</option>
          <option value="darlehensvertrag">Darlehensvertrag</option>
          <option value="vollmacht_de">Vollmacht (Deutsch)</option>
          <option value="vollmacht_en">Vollmacht (Englisch)</option>
          <option value="vollmacht_beide">Vollmacht (Deutsch + Englisch)</option>
        </select>
      </div>

      <!-- DARLEHEN-ASSISTENT: nur sichtbar, wenn Vorlage "Darlehensvertrag" gewählt ist -->
      <div id="darlehen-assistent" style="display:none; background:rgba(124,124,255,0.1); border:1px solid rgba(124,124,255,0.3); border-radius:12px; padding:16px; margin-bottom:20px;">
        <div style="font-size:12px; font-weight:700; color:var(--accent); margin-bottom:12px;">📋 Darlehen-Angaben — füllt automatisch den Vertragstext</div>

        <div class="row-2">
          <div class="field-group" style="margin-bottom:12px;">
            <label>Darlehensgeber</label>
            <select id="dl-geber-wahl" onchange="darlehenPersonWaehlen('geber')">
              <option value="firma">NA Commerce Solutions GmbH</option>
              <option value="artis">Artis Freibergs</option>
              <option value="nour">Mhd Nour Shaaban</option>
              <option value="sonstige">Sonstige Person/Firma...</option>
            </select>
          </div>
          <div class="field-group" style="margin-bottom:12px;">
            <label>Darlehensnehmer</label>
            <select id="dl-nehmer-wahl" onchange="darlehenPersonWaehlen('nehmer')">
              <option value="firma">NA Commerce Solutions GmbH</option>
              <option value="artis">Artis Freibergs</option>
              <option value="nour">Mhd Nour Shaaban</option>
              <option value="sonstige" selected>Sonstige Person/Firma...</option>
            </select>
          </div>
        </div>

        <div class="row-2">
          <div class="field-group" style="margin-bottom:12px;">
            <label>Name (Darlehensgeber)</label>
            <input type="text" id="dl-geber-name" oninput="darlehenTextBauen()">
          </div>
          <div class="field-group" style="margin-bottom:12px;">
            <label>Name (Darlehensnehmer)</label>
            <input type="text" id="dl-nehmer-name" oninput="darlehenTextBauen()">
          </div>
        </div>

        <div class="row-2">
          <div class="field-group" style="margin-bottom:12px;">
            <label>Adresse (Darlehensgeber)</label>
            <input type="text" id="dl-geber-adresse" oninput="darlehenTextBauen()">
          </div>
          <div class="field-group" style="margin-bottom:12px;">
            <label>Adresse (Darlehensnehmer)</label>
            <input type="text" id="dl-nehmer-adresse" oninput="darlehenTextBauen()">
          </div>
        </div>

        <div class="row-2">
          <div class="field-group" style="margin-bottom:12px;">
            <label>Darlehensbetrag (€)</label>
            <input type="text" id="dl-betrag" placeholder="z.B. 5000" oninput="darlehenTextBauen()">
          </div>
          <div class="field-group" style="margin-bottom:12px;">
            <label>Auszahlungsdatum</label>
            <input type="date" id="dl-auszahlung" onchange="darlehenTextBauen()">
          </div>
        </div>

        <div class="field-group" style="margin-bottom:12px;">
          <label>Rückzahlungsart</label>
          <select id="dl-rueckzahlungsart" onchange="darlehenRueckzahlungsartUmschalten()">
            <option value="einmalig">Einmalig zum Fälligkeitsdatum</option>
            <option value="raten">In monatlichen Raten</option>
          </select>
        </div>

        <div id="dl-einmalig-block">
          <div class="field-group" style="margin-bottom:12px;">
            <label>Rückzahlungsdatum (spätestens)</label>
            <input type="date" id="dl-rueckzahlung" onchange="darlehenTextBauen()">
          </div>
        </div>

        <div id="dl-raten-block" style="display:none;">
          <div class="row-2">
            <div class="field-group" style="margin-bottom:12px;">
              <label>Ratenbetrag (€/Monat)</label>
              <input type="text" id="dl-ratenbetrag" oninput="darlehenTextBauen()">
            </div>
            <div class="field-group" style="margin-bottom:12px;">
              <label>Erste Rate ab</label>
              <input type="date" id="dl-erste-rate" onchange="darlehenTextBauen()">
            </div>
          </div>
        </div>

        <div class="row-2">
          <div class="field-group" style="margin-bottom:12px;">
            <label>Zinssatz</label>
            <select id="dl-zinssatz" onchange="darlehenTextBauen()">
              <option value="0">0 % — zinslos</option>
              <option value="2">2 % p.a.</option>
              <option value="3" selected>3 % p.a.</option>
              <option value="4">4 % p.a.</option>
              <option value="5">5 % p.a.</option>
              <option value="sonstiger">Anderer Satz...</option>
            </select>
          </div>
          <div class="field-group" id="dl-zins-sonstiger-block" style="margin-bottom:12px; display:none;">
            <label>Zinssatz (%, manuell)</label>
            <input type="text" id="dl-zinssatz-manuell" oninput="darlehenTextBauen()">
          </div>
          <div class="field-group" style="margin-bottom:12px;">
            <label>Kündigungsfrist</label>
            <select id="dl-kuendigungsfrist" onchange="darlehenTextBauen()">
              <option value="14">14 Tage</option>
              <option value="30" selected>30 Tage</option>
              <option value="60">60 Tage</option>
              <option value="90">90 Tage</option>
            </select>
          </div>
        </div>

        <div class="field-group" style="margin-bottom:0;">
          <label>Sicherheiten</label>
          <select id="dl-sicherheiten" onchange="darlehenSicherheitenUmschalten()">
            <option value="keine">Keine Sicherheiten vereinbart</option>
            <option value="sonstige">Sonstige (frei eintragen)</option>
          </select>
          <input type="text" id="dl-sicherheiten-text" placeholder="z.B. Sicherungsübereignung von..." oninput="darlehenTextBauen()" style="display:none; margin-top:8px;">
        </div>
      </div>

      <div class="field-group">
        <label>Wer schreibt diesen Brief?</label>
        <div class="verfasser-row">
          <button type="button" class="verfasser-btn active" data-verfasser="artis" onclick="verfasserWaehlen('artis')">Artis</button>
          <button type="button" class="verfasser-btn" data-verfasser="nour" onclick="verfasserWaehlen('nour')">Nour</button>
          <button type="button" class="verfasser-btn" data-verfasser="beide" onclick="verfasserWaehlen('beide')">Beide</button>
        </div>
        <input type="hidden" name="verfasser" id="verfasser" value="artis">
      </div>

      <div class="field-group">
        <label>Empfänger (Name, Adresse — mehrzeilig)</label>
        <textarea name="empfaenger" id="empfaenger" placeholder="Firma / Name&#10;Straße Nr.&#10;PLZ Ort" oninput="livePreview()"></textarea>
      </div>

      <div class="row-2">
        <div class="field-group">
          <label>Ort</label>
          <input type="text" name="ort" id="ort" value="Bremen" oninput="livePreview()">
        </div>
        <div class="field-group">
          <label>Datum</label>
          <input type="date" name="datum" id="datum" oninput="livePreview()">
        </div>
      </div>

      <div class="field-group">
        <label>Betreff (optional)</label>
        <input type="text" name="betreff" id="betreff" placeholder="z.B. Zahlungsbestätigung Rechnung Nr. ..." oninput="livePreview()">
      </div>

      <div class="field-group">
        <label>Brieftext</label>
        <textarea name="brieftext" id="brieftext" placeholder="Sehr geehrte Damen und Herren,&#10;&#10;..." oninput="livePreview()"></textarea>
        <div class="hint">Frei editierbar — auch nach Vorlagenauswahl kannst du alles anpassen. Bei "Darlehensvertrag" bitte alle [Platzhalter] in eckigen Klammern durch die echten Angaben ersetzen.</div>
      </div>

      <div class="row-2">
        <div class="field-group">
          <label>Unterzeichner</label>
          <input type="text" name="unterzeichner" id="unterzeichner" value="Artis Freibergs" oninput="livePreview()">
        </div>
        <div class="field-group">
          <label>Funktion (optional)</label>
          <input type="text" name="unterzeichner_titel" id="unterzeichner_titel" value="Geschäftsführer" oninput="livePreview()">
        </div>
      </div>

      <div class="field-group">
        <label>Unterschrift</label>
        <div class="verfasser-row">
          <button type="button" class="verfasser-btn active" data-modus="keine" onclick="signaturModusWaehlen('keine')">Keine (Freiraum)</button>
          <button type="button" class="verfasser-btn" data-modus="zeichnen" onclick="signaturModusWaehlen('zeichnen')">Jetzt zeichnen</button>
          <button type="button" class="verfasser-btn" data-modus="gespeichert" onclick="signaturModusWaehlen('gespeichert')">Gespeicherte Unterschrift</button>
        </div>
        <input type="hidden" name="signatur_modus" id="signatur_modus" value="keine">

        <div id="signatur-pad-block" style="display:none; margin-top:10px;">
          <div class="signatur-pad-wrap">
            <canvas id="signatur-canvas"></canvas>
            <div class="signatur-actions">
              <span class="signatur-status" id="signatur-status">Noch keine Unterschrift</span>
              <button type="button" class="btn-clear-signatur" onclick="signaturLoeschen()">Löschen</button>
            </div>
          </div>
          <input type="hidden" name="signatur_data" id="signatur_data" value="">
          <input type="hidden" name="ort_datum_inline" id="ort_datum_inline" value="0">
          <div class="hint">Mit Finger oder Maus direkt hier unterschreiben.</div>
        </div>

        <div id="signatur-gespeichert-hinweis" style="display:none; margin-top:8px;" class="hint">
          Es wird automatisch die hinterlegte Unterschrift von <strong id="gespeichert-wer">Artis</strong> verwendet, passend zur Auswahl "Wer schreibt diesen Brief?" oben.
        </div>

        <div class="hint" style="margin-top:6px; color:#FBBF24;">
          ⚠️ Hinweis: Eine eingefügte Unterschrift ersetzt keine eigenhändige/qualifizierte elektronische Signatur. Für formbedürftige Dokumente (§126 BGB) rechtlich nicht ausreichend — für formlose Geschäftsbriefe unproblematisch. Beim Darlehensvertrag unterschreibt die Gegenseite weiterhin von Hand auf der freien Zeile im Text.
        </div>
      </div>

      <button type="submit" class="btn-generate">PDF erzeugen &amp; herunterladen</button>
      <button type="button" class="btn-delete" id="btn-delete-doc" style="display:none;" onclick="dokumentLoeschen()">🗑 Dieses Dokument löschen</button>
    </form>
    <input type="hidden" id="lade-dokument-id" value="">
  </div>

  <div class="preview-side">
    <div class="preview-label">Vorschau (Näherung — finales Layout im PDF)</div>
    <div class="preview-page">
      <div class="preview-logo-zeile">
        <span class="fallback">NA Commerce Solutions GmbH</span>
      </div>
      <div class="preview-absender">NA Commerce Solutions GmbH · Am Bahndamm 7 · 28719 Bremen</div>

      <div class="preview-empfaenger" id="pv-empfaenger">Empfänger erscheint hier</div>
      <div class="preview-datum" id="pv-datum">Bremen, <?= date('d.m.Y') ?></div>
      <div class="preview-betreff" id="pv-betreff" style="display:none;"></div>
      <div class="preview-text" id="pv-text">Brieftext erscheint hier, sobald du zu schreiben beginnst.</div>

      <div class="preview-unterschrift">
        <div class="preview-unterschrift-linie" id="pv-unterschrift">Artis Freibergs — Geschäftsführer</div>
      </div>
    </div>
  </div>
</div>

<script>
const VERFASSER_NAMEN = {
  artis: 'Artis Freibergs',
  nour: 'Mhd Nour Shaaban',
  beide: 'Artis Freibergs, Mhd Nour Shaaban'
};

function verfasserWaehlen(key) {
  document.getElementById('verfasser').value = key;
  document.querySelectorAll('.verfasser-row .verfasser-btn[data-verfasser]').forEach(b => {
    b.classList.toggle('active', b.dataset.verfasser === key);
  });
  document.getElementById('unterzeichner').value = VERFASSER_NAMEN[key];
  document.getElementById('gespeichert-wer').textContent =
    key === 'artis' ? 'Artis' : key === 'nour' ? 'Nour' : 'Artis und Nour';
  livePreview();
}

function signaturModusWaehlen(modus) {
  document.getElementById('signatur_modus').value = modus;
  document.querySelectorAll('.verfasser-row .verfasser-btn[data-modus]').forEach(b => {
    b.classList.toggle('active', b.dataset.modus === modus);
  });
  document.getElementById('signatur-pad-block').style.display = (modus === 'zeichnen') ? 'block' : 'none';
  document.getElementById('signatur-gespeichert-hinweis').style.display = (modus === 'gespeichert') ? 'block' : 'none';
  if (modus === 'zeichnen') canvasGroesseSetzen();
}

const canvas = document.getElementById('signatur-canvas');
const ctx = canvas.getContext('2d');
let zeichnet = false;
let hatGezeichnet = false;

function canvasGroesseSetzen() {
  const rect = canvas.getBoundingClientRect();
  const ratio = window.devicePixelRatio || 1;
  canvas.width = rect.width * ratio;
  canvas.height = rect.height * ratio;
  ctx.scale(ratio, ratio);
  ctx.strokeStyle = '#1D1D1F';
  ctx.lineWidth = 2;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
}
canvasGroesseSetzen();
window.addEventListener('resize', () => { canvasGroesseSetzen(); if (!hatGezeichnet) return; });

function position(e) {
  const rect = canvas.getBoundingClientRect();
  const point = e.touches ? e.touches[0] : e;
  return { x: point.clientX - rect.left, y: point.clientY - rect.top };
}

function zeichnenStart(e) {
  zeichnet = true;
  hatGezeichnet = true;
  const p = position(e);
  ctx.beginPath();
  ctx.moveTo(p.x, p.y);
  e.preventDefault();
}
function zeichnenBewegen(e) {
  if (!zeichnet) return;
  const p = position(e);
  ctx.lineTo(p.x, p.y);
  ctx.stroke();
  e.preventDefault();
}
function zeichnenEnde() {
  if (!zeichnet) return;
  zeichnet = false;
  document.getElementById('signatur_data').value = canvas.toDataURL('image/png');
  const status = document.getElementById('signatur-status');
  status.textContent = '✓ Unterschrift erfasst';
  status.classList.add('signiert');
}

canvas.addEventListener('mousedown', zeichnenStart);
canvas.addEventListener('mousemove', zeichnenBewegen);
canvas.addEventListener('mouseup', zeichnenEnde);
canvas.addEventListener('mouseleave', zeichnenEnde);
canvas.addEventListener('touchstart', zeichnenStart);
canvas.addEventListener('touchmove', zeichnenBewegen);
canvas.addEventListener('touchend', zeichnenEnde);

function signaturLoeschen() {
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  hatGezeichnet = false;
  document.getElementById('signatur_data').value = '';
  const status = document.getElementById('signatur-status');
  status.textContent = 'Noch keine Unterschrift';
  status.classList.remove('signiert');
}

const VORLAGEN = {
  leer: {
    betreff: '',
    text: 'Sehr geehrte Damen und Herren,\n\n\n\nMit freundlichen Grüßen'
  },
  zahlungsbestaetigung: {
    betreff: 'Zahlungsbestätigung',
    text: 'Sehr geehrte Damen und Herren,\n\nhiermit bestätigen wir den Erhalt Ihrer Zahlung in Höhe von [Betrag] € vom [Datum] zu Rechnung/Referenz [Nummer].\n\nDie Zahlung wurde ordnungsgemäß verbucht.\n\nMit freundlichen Grüßen'
  },
  auftragsbestaetigung: {
    betreff: 'Auftragsbestätigung',
    text: 'Sehr geehrte Damen und Herren,\n\nhiermit bestätigen wir Ihre Bestellung vom [Datum] über folgende Position(en):\n\n- [Artikel, Menge, Preis]\n\nDie Lieferung erfolgt voraussichtlich bis zum [Datum].\n\nMit freundlichen Grüßen'
  },
  mahnung: {
    betreff: 'Zahlungserinnerung zu Rechnung Nr. [Nummer]',
    text: 'Sehr geehrte Damen und Herren,\n\nunseren Unterlagen zufolge steht die Zahlung zu obiger Rechnung vom [Datum] über [Betrag] € noch aus.\n\nWir bitten um Ausgleich des offenen Betrags bis zum [Datum]. Sollte die Zahlung bereits erfolgt sein, betrachten Sie dieses Schreiben bitte als gegenstandslos.\n\nMit freundlichen Grüßen'
  },
  geschaeftsbeziehung: {
    betreff: 'Bestätigung der Geschäftsbeziehung',
    text: 'Sehr geehrte Damen und Herren,\n\nhiermit bestätigen wir, dass zwischen der NA Commerce Solutions GmbH und [Name] seit [Datum] eine aktive Geschäftsbeziehung besteht.\n\nFür Rückfragen stehen wir gerne zur Verfügung.\n\nMit freundlichen Grüßen'
  },
  kuendigungsbestaetigung: {
    betreff: 'Bestätigung Ihrer Kündigung',
    text: 'Sehr geehrte Damen und Herren,\n\nhiermit bestätigen wir den Erhalt Ihrer Kündigung vom [Datum] zum [Datum].\n\nDas Vertragsverhältnis endet damit fristgerecht zum genannten Termin.\n\nMit freundlichen Grüßen'
  },
  darlehensvertrag: {
    betreff: 'Darlehensvertrag',
    text: 'zwischen\n\n[Name Darlehensgeber], [Adresse Darlehensgeber]\n\u2013 nachfolgend "Darlehensgeber" genannt \u2013\n\nund\n\n[Name Darlehensnehmer], [Adresse Darlehensnehmer]\n\u2013 nachfolgend "Darlehensnehmer" genannt \u2013\n\nwird folgender Darlehensvertrag geschlossen:\n\n\u00a7 1 Darlehenssumme und Auszahlung\nDer Darlehensgeber gew\u00e4hrt dem Darlehensnehmer ein Darlehen in H\u00f6he von [Betrag] \u20ac (in Worten: [Betrag in Worten] Euro). Die Auszahlung erfolgt am [Auszahlungsdatum] durch \u00dcberweisung auf das Konto des Darlehensnehmers.\n\n\u00a7 2 R\u00fcckzahlung\nDas Darlehen ist sp\u00e4testens bis zum [R\u00fcckzahlungsdatum] in voller H\u00f6he zur\u00fcckzuzahlen. [Bei Ratenzahlung: Die R\u00fcckzahlung erfolgt in monatlichen Raten von [Ratenbetrag] \u20ac ab dem [Datum erste Rate].]\n\n\u00a7 3 Verzinsung\nDas Darlehen wird mit [Zinssatz] % p.a. verzinst. [Falls zinslos: Das Darlehen wird zinslos gew\u00e4hrt.] Die Zinsen sind [zusammen mit der R\u00fcckzahlung f\u00e4llig / j\u00e4hrlich zum ... zu zahlen].\n\n\u00a7 4 Vorzeitige K\u00fcndigung\nBeide Parteien k\u00f6nnen das Darlehen mit einer Frist von [K\u00fcndigungsfrist] Tagen zur R\u00fcckzahlung f\u00e4llig stellen bzw. vorzeitig zur\u00fcckzahlen.\n\n\u00a7 5 Sicherheiten\n[Keine Sicherheiten vereinbart. / Als Sicherheit dient: ...]\n\n\u00a7 6 Schlussbestimmungen\n\u00c4nderungen und Erg\u00e4nzungen dieses Vertrags bed\u00fcrfen der Schriftform. Sollte eine Bestimmung dieses Vertrags unwirksam sein, bleibt die Wirksamkeit der \u00fcbrigen Bestimmungen unber\u00fchrt. Es gilt deutsches Recht.\n\n\n\n\n_______________________________\nOrt, Datum, Unterschrift Darlehensgeber\n\n\n\n\n_______________________________\nOrt, Datum, Unterschrift Darlehensnehmer'
  },
  vollmacht_de: {
    betreff: 'Vollmacht',
    text: 'Hiermit bevollm\u00e4chtige ich,\n\n[Name Vollmachtgeber], geboren am [Geburtsdatum], wohnhaft in [Adresse Vollmachtgeber],\n\ndie/den\n\n[Name Bevollm\u00e4chtigter], geboren am [Geburtsdatum], wohnhaft in [Adresse Bevollm\u00e4chtigter],\n\nmich in folgenden Angelegenheiten zu vertreten:\n\n[Umfang der Vollmacht eintragen, z.B. "Abholung von Paketen und Sendungen", "Vertretung gegen\u00fcber Beh\u00f6rden und \u00c4mtern", "s\u00e4mtliche Bankgesch\u00e4fte auf dem Konto Nr. ..."]\n\nDiese Vollmacht gilt ab dem [Datum] und ist g\u00fcltig bis [Datum] / bis auf Widerruf.\n\nDer Bevollm\u00e4chtigte ist berechtigt, in meinem Namen und auf meine Rechnung im oben genannten Umfang zu handeln.\n\n\n\n\n_______________________________\nOrt, Datum, Unterschrift Vollmachtgeber\n\n\n\n\n_______________________________\nOrt, Datum, Unterschrift Bevollm\u00e4chtigter (zur Kenntnisnahme)'
  },
  vollmacht_en: {
    betreff: 'Power of Attorney',
    text: 'I, [Name of Principal], date of birth [Date of Birth], residing at [Address of Principal],\n\nhereby grant power of attorney to\n\n[Name of Attorney-in-Fact], date of birth [Date of Birth], residing at [Address of Attorney-in-Fact],\n\nto represent me in the following matters:\n\n[Scope of authority, e.g. "collection of parcels and mail", "representation before public authorities", "all banking transactions on account no. ..."]\n\nThis power of attorney is valid from [Date] until [Date] / until revoked in writing.\n\nThe attorney-in-fact is authorized to act on my behalf and for my account within the scope described above.\n\n\n\n\n_______________________________\nPlace, Date, Signature of Principal\n\n\n\n\n_______________________________\nPlace, Date, Signature of Attorney-in-Fact (for acknowledgement)'
  },
  vollmacht_beide: {
    betreff: 'Vollmacht / Power of Attorney',
    text: 'DEUTSCH\n\nHiermit bevollm\u00e4chtige ich,\n\n[Name Vollmachtgeber], geboren am [Geburtsdatum], wohnhaft in [Adresse Vollmachtgeber],\n\ndie/den\n\n[Name Bevollm\u00e4chtigter], geboren am [Geburtsdatum], wohnhaft in [Adresse Bevollm\u00e4chtigter],\n\nmich in folgenden Angelegenheiten zu vertreten:\n\n[Umfang der Vollmacht eintragen]\n\nDiese Vollmacht gilt ab dem [Datum] und ist g\u00fcltig bis [Datum] / bis auf Widerruf.\n\n\n\u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014 \u2014\n\nENGLISH\n\nI, [Name of Principal], date of birth [Date of Birth], residing at [Address of Principal],\n\nhereby grant power of attorney to\n\n[Name of Attorney-in-Fact], date of birth [Date of Birth], residing at [Address of Attorney-in-Fact],\n\nto represent me in the following matters:\n\n[Scope of authority]\n\nThis power of attorney is valid from [Date] until [Date] / until revoked in writing.\n\n\n\n\n_______________________________\nOrt, Datum, Unterschrift Vollmachtgeber\nPlace, Date, Signature of Principal\n\n\n\n\n_______________________________\nOrt, Datum, Unterschrift Bevollm\u00e4chtigter\nPlace, Date, Signature of Attorney-in-Fact'
  }
};

function vorlageAnwenden() {
  const key = document.getElementById('vorlage-select').value;
  const v = VORLAGEN[key];
  if (!v) return;
  document.getElementById('betreff').value = v.betreff;

  const assistent = document.getElementById('darlehen-assistent');
  if (key === 'darlehensvertrag') {
    assistent.style.display = 'block';
    darlehenTextBauen();
  } else {
    assistent.style.display = 'none';
    document.getElementById('brieftext').value = v.text;
    livePreview();
  }
}

// ── DARLEHEN-ASSISTENT ───────────────────────────────────────────────────────
const DARLEHEN_PERSONEN = {
  firma: { name: 'NA Commerce Solutions GmbH', adresse: 'Am Bahndamm 7, 28719 Bremen' },
  artis: { name: 'Artis Freibergs', adresse: '' },
  nour: { name: 'Mhd Nour Shaaban', adresse: '' },
};

function darlehenPersonWaehlen(seite) {
  const wahl = document.getElementById('dl-' + seite + '-wahl').value;
  const nameFeld = document.getElementById('dl-' + seite + '-name');
  const adresseFeld = document.getElementById('dl-' + seite + '-adresse');
  if (wahl !== 'sonstige' && DARLEHEN_PERSONEN[wahl]) {
    nameFeld.value = DARLEHEN_PERSONEN[wahl].name;
    adresseFeld.value = DARLEHEN_PERSONEN[wahl].adresse;
  } else {
    nameFeld.value = '';
    adresseFeld.value = '';
  }
  darlehenTextBauen();
}

function darlehenRueckzahlungsartUmschalten() {
  const raten = document.getElementById('dl-rueckzahlungsart').value === 'raten';
  document.getElementById('dl-einmalig-block').style.display = raten ? 'none' : 'block';
  document.getElementById('dl-raten-block').style.display = raten ? 'block' : 'none';
  darlehenTextBauen();
}

function darlehenSicherheitenUmschalten() {
  const sonstige = document.getElementById('dl-sicherheiten').value === 'sonstige';
  document.getElementById('dl-sicherheiten-text').style.display = sonstige ? 'block' : 'none';
  darlehenTextBauen();
}

document.getElementById('dl-zinssatz').addEventListener('change', function() {
  document.getElementById('dl-zins-sonstiger-block').style.display = (this.value === 'sonstiger') ? 'block' : 'none';
  darlehenTextBauen();
});

function formatEuro(wert) {
  const n = parseFloat((wert || '0').toString().replace(',', '.'));
  if (isNaN(n)) return wert || '[Betrag]';
  return n.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function formatDatumLang(iso) {
  if (!iso) return '[Datum]';
  const [y,m,d] = iso.split('-');
  return `${d}.${m}.${y}`;
}

function darlehenTextBauen() {
  const geberName = document.getElementById('dl-geber-name').value.trim() || '[Name Darlehensgeber]';
  const geberAdresse = document.getElementById('dl-geber-adresse').value.trim() || '[Adresse Darlehensgeber]';
  const nehmerName = document.getElementById('dl-nehmer-name').value.trim() || '[Name Darlehensnehmer]';
  const nehmerAdresse = document.getElementById('dl-nehmer-adresse').value.trim() || '[Adresse Darlehensnehmer]';
  const betrag = formatEuro(document.getElementById('dl-betrag').value);
  const auszahlung = formatDatumLang(document.getElementById('dl-auszahlung').value);

  const rueckzahlungsart = document.getElementById('dl-rueckzahlungsart').value;
  let rueckzahlungText;
  if (rueckzahlungsart === 'raten') {
    const ratenbetrag = formatEuro(document.getElementById('dl-ratenbetrag').value);
    const ersteRate = formatDatumLang(document.getElementById('dl-erste-rate').value);
    rueckzahlungText = `Die Rückzahlung erfolgt in monatlichen Raten von ${ratenbetrag} € ab dem ${ersteRate}, bis das Darlehen vollständig getilgt ist.`;
  } else {
    const rueckzahlung = formatDatumLang(document.getElementById('dl-rueckzahlung').value);
    rueckzahlungText = `Das Darlehen ist spätestens bis zum ${rueckzahlung} in voller Höhe zurückzuzahlen.`;
  }

  const zinssatzWahl = document.getElementById('dl-zinssatz').value;
  let zinsText;
  if (zinssatzWahl === '0') {
    zinsText = 'Das Darlehen wird zinslos gewährt.';
  } else {
    const satz = zinssatzWahl === 'sonstiger'
      ? (document.getElementById('dl-zinssatz-manuell').value.trim() || '[Zinssatz]')
      : zinssatzWahl;
    zinsText = `Das Darlehen wird mit ${satz} % p.a. verzinst. Die Zinsen sind zusammen mit der Rückzahlung fällig.`;
  }

  const kuendigungsfrist = document.getElementById('dl-kuendigungsfrist').value;

  const sicherheitenWahl = document.getElementById('dl-sicherheiten').value;
  const sicherheitenText = sicherheitenWahl === 'sonstige'
    ? (document.getElementById('dl-sicherheiten-text').value.trim() || '[Sicherheiten eintragen]')
    : 'Es werden keine Sicherheiten vereinbart.';

  // ── Wer sind WIR in diesem Vertrag? ────────────────────────────────────────
  // "Sonstige" = externe Gegenseite (unterschreibt von Hand). Alles andere
  // (Firma/Artis/Nour) = wir — bekommt unten automatisch die echten
  // digitalen Unterschriften statt einer leeren Linie.
  const geberIstWir = document.getElementById('dl-geber-wahl').value !== 'sonstige';
  const nehmerIstWir = document.getElementById('dl-nehmer-wahl').value !== 'sonstige';
  // Falls beide Seiten "wir" sind (z.B. Artis leiht Nour), zählt vorrangig der Nehmer als "wir" —
  // typischer Fall bei euch. Sonst: wer immer nicht "Sonstige" ist.
  const wirSindGeber = geberIstWir && !nehmerIstWir;
  const wirSindNehmer = nehmerIstWir; // deckt auch den Fall ab, dass beide "wir" sind

  const externeZeile = (bezeichnung, name) =>
    `_______________________________\nOrt, Datum, Unterschrift ${bezeichnung} (${name})`;

  let geberZeile, nehmerZeile;
  if (wirSindGeber) {
    geberZeile = null; // wir — kein Text nötig, kommt automatisch neben den echten Unterschriften unten
    nehmerZeile = externeZeile('Darlehensnehmer', nehmerName);
  } else if (wirSindNehmer) {
    nehmerZeile = null;
    geberZeile = externeZeile('Darlehensgeber', geberName);
  } else {
    geberZeile = externeZeile('Darlehensgeber', geberName);
    nehmerZeile = externeZeile('Darlehensnehmer', nehmerName);
  }

  const bloecke = [];
  if (geberZeile) bloecke.push(geberZeile);
  if (nehmerZeile) bloecke.push(nehmerZeile);
  const unterschriftenText = bloecke.join('\n\n\n\n');

  // Passende Verfasser-/Signatur-Einstellungen automatisch setzen, damit die
  // echten Unterschriften unten im PDF erscheinen — direkt in derselben
  // Zeile wie "Ort, den Datum" (kein manuelles Klicken nötig)
  const wirSindDabei = wirSindGeber || wirSindNehmer;
  document.getElementById('ort_datum_inline').value = wirSindDabei ? '1' : '0';
  if (wirSindDabei) {
    verfasserWaehlen('beide');
    signaturModusWaehlen('gespeichert');
  }

  const text = `zwischen

${geberName}, ${geberAdresse}
– nachfolgend "Darlehensgeber" genannt –

und

${nehmerName}, ${nehmerAdresse}
– nachfolgend "Darlehensnehmer" genannt –

wird folgender Darlehensvertrag geschlossen:

§ 1 Darlehenssumme und Auszahlung
Der Darlehensgeber gewährt dem Darlehensnehmer ein Darlehen in Höhe von ${betrag} €. Die Auszahlung erfolgt am ${auszahlung} durch Überweisung auf das Konto des Darlehensnehmers.

§ 2 Rückzahlung
${rueckzahlungText}

§ 3 Verzinsung
${zinsText}

§ 4 Vorzeitige Kündigung
Beide Parteien können das Darlehen mit einer Frist von ${kuendigungsfrist} Tagen zur Rückzahlung fällig stellen bzw. vorzeitig zurückzahlen.

§ 5 Sicherheiten
${sicherheitenText}

§ 6 Schlussbestimmungen
Änderungen und Ergänzungen dieses Vertrags bedürfen der Schriftform. Sollte eine Bestimmung dieses Vertrags unwirksam sein, bleibt die Wirksamkeit der übrigen Bestimmungen unberührt. Es gilt deutsches Recht.



${unterschriftenText}`;

  document.getElementById('brieftext').value = text;
  livePreview();
}

document.getElementById('datum').value = new Date().toISOString().slice(0,10);

function formatDatum(iso) {
  if (!iso) return '';
  const [y,m,d] = iso.split('-');
  return `${d}.${m}.${y}`;
}

function livePreview() {
  const empfaenger = document.getElementById('empfaenger').value.trim();
  const ort = document.getElementById('ort').value.trim() || 'Bremen';
  const datum = document.getElementById('datum').value;
  const betreff = document.getElementById('betreff').value.trim();
  const text = document.getElementById('brieftext').value.trim();
  const unterzeichner = document.getElementById('unterzeichner').value.trim();
  const titel = document.getElementById('unterzeichner_titel').value.trim();

  document.getElementById('pv-empfaenger').textContent = empfaenger || 'Empfänger erscheint hier';
  document.getElementById('pv-datum').textContent = `${ort}, ${formatDatum(datum)}`;

  const betreffEl = document.getElementById('pv-betreff');
  if (betreff) {
    betreffEl.style.display = 'block';
    betreffEl.textContent = betreff;
  } else {
    betreffEl.style.display = 'none';
  }

  document.getElementById('pv-text').textContent = text || 'Brieftext erscheint hier, sobald du zu schreiben beginnst.';
  document.getElementById('pv-unterschrift').textContent = titel ? `${unterzeichner} — ${titel}` : unterzeichner;
}

vorlageAnwenden();

(async function ladeZumBearbeiten() {
  const params = new URLSearchParams(window.location.search);
  const editId = params.get('edit_id');
  if (!editId) return;

  try {
    const res = await fetch('document_get.php?id=' + editId);
    const doc = await res.json();
    if (doc.error) return;

    document.getElementById('vorlage-select').value = 'leer';
    document.getElementById('empfaenger').value = doc.empfaenger || '';
    document.getElementById('ort').value = doc.ort || 'Bremen';
    if (doc.dokument_datum) document.getElementById('datum').value = doc.dokument_datum;
    document.getElementById('betreff').value = doc.betreff || '';
    document.getElementById('brieftext').value = doc.brieftext || '';
    if (doc.unterzeichner) document.getElementById('unterzeichner').value = doc.unterzeichner;
    if (doc.unterzeichner_titel) document.getElementById('unterzeichner_titel').value = doc.unterzeichner_titel;
    verfasserWaehlen(doc.verfasser || 'artis');

    document.querySelector('h1').textContent = 'Dokument bearbeiten';
    document.querySelector('.subtitle').textContent = 'Änderungen anpassen — beim Erzeugen entsteht ein neues Dokument, das Original bleibt unverändert.';

    document.getElementById('lade-dokument-id').value = editId;
    document.getElementById('dokument_id').value = editId;
    document.getElementById('btn-delete-doc').style.display = 'block';
    document.querySelector('.btn-generate').textContent = 'Dokument aktualisieren';

    livePreview();
  } catch (e) {
    console.error('Konnte Dokument nicht laden:', e);
  }
})();

async function dokumentLoeschen() {
  const id = document.getElementById('lade-dokument-id').value;
  if (!id) return;

  if (!confirm('Dieses Dokument wirklich unwiderruflich löschen? Die Datei wird ebenfalls entfernt.')) return;

  const fd = new FormData();
  fd.append('id', id);
  const res = await fetch('document_delete.php', { method: 'POST', body: fd });
  const data = await res.json();

  if (data.error) {
    alert('Fehler beim Löschen: ' + data.error);
    return;
  }

  window.location.href = 'documents_history.php';
}
</script>
</div>
</body>
</html>