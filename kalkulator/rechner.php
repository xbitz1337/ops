<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('kalkulator');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kalkulator — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #0f1923; --navy2: #152236; --blue-mid: #1e3a5f;
    --blue-accent: #2d6aad; --blue-bright: #4a9edd;
    --text: #c8dff0; --text-dim: #5a7a9a;
    --green: #2ecc71; --orange: #e67e22; --red: #e74c3c;
    --mono: 'Share Tech Mono', monospace; --sans: 'Exo 2', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  body::before {
    content:''; position:fixed; inset:0;
    background-image: linear-gradient(rgba(45,106,173,0.05) 1px,transparent 1px), linear-gradient(90deg,rgba(45,106,173,0.05) 1px,transparent 1px);
    background-size:40px 40px; pointer-events:none; z-index:0;
  }

  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(15,25,35,0.97); border-bottom:1px solid rgba(45,106,173,0.2); z-index:100; }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; letter-spacing:1px; }
  .back-link:hover { text-decoration:underline; }
  .topbar-title { font-size:14px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; }

  .main { max-width:1100px; margin:0 auto; padding:28px 20px 60px; position:relative; z-index:2; }
  .page-title { font-size:22px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); letter-spacing:2px; margin-top:4px; margin-bottom:24px; }

  .layout { display:grid; grid-template-columns:380px 1fr; gap:20px; }
  @media(max-width:900px) { .layout { grid-template-columns:1fr; } }

  .panel { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:20px; }
  .panel-title { font-family:var(--mono); font-size:11px; letter-spacing:3px; text-transform:uppercase; color:var(--blue-bright); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  .panel-title::before { content:'//'; color:var(--blue-accent); }

  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); letter-spacing:2px; text-transform:uppercase; margin-bottom:6px; display:block; }
  .field-input, .field-select {
    width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25);
    color:var(--text); font-family:var(--mono); font-size:14px; padding:10px 12px; outline:none;
    margin-bottom:16px;
  }
  .field-input:focus { border-color:var(--blue-bright); }

  .checkbox-row { display:flex; align-items:center; gap:8px; margin-bottom:16px; cursor:pointer; }
  .checkbox-row input[type=checkbox] { width:16px; height:16px; accent-color:var(--blue-bright); cursor:pointer; }
  .checkbox-row label { font-family:var(--mono); font-size:11px; color:var(--text); cursor:pointer; }
  .checkbox-hint { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-top:-12px; margin-bottom:16px; padding-left:24px; }

  .plattform-checks { display:flex; flex-direction:column; gap:10px; margin-bottom:16px; }
  .plattform-check { display:flex; align-items:center; gap:10px; padding:10px 12px; border:1px solid rgba(45,106,173,0.2); cursor:pointer; }
  .plattform-check:hover { border-color:rgba(45,106,173,0.4); }
  .plattform-check input { width:16px; height:16px; accent-color:var(--blue-bright); cursor:pointer; }
  .plattform-check label { font-family:var(--mono); font-size:12px; letter-spacing:1px; cursor:pointer; flex:1; }
  .plattform-check .satz { font-family:var(--mono); font-size:9px; color:var(--text-dim); }

  .versand-row { display:flex; gap:8px; margin-bottom:16px; }
  .versand-row .field-input { margin-bottom:0; flex:1; }
  .versand-row .mwst-toggle {
    display:flex; align-items:center; gap:6px; font-family:var(--mono); font-size:9px;
    color:var(--text-dim); white-space:nowrap; padding:0 8px; border:1px solid rgba(45,106,173,0.25);
  }
  .versand-row .mwst-toggle input { accent-color:var(--blue-bright); }

  /* ERGEBNISSE */
  .ergebnis-grid { display:grid; grid-template-columns:1fr; gap:16px; }
  .ergebnis-grid.mehrere { grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); }
  .plattform-ergebnis { background:rgba(15,25,35,0.6); border:1px solid rgba(45,106,173,0.25); padding:18px; }
  .plattform-ergebnis-name { font-family:var(--mono); font-size:13px; letter-spacing:2px; text-transform:uppercase; color:var(--blue-bright); margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid rgba(45,106,173,0.2); }
  .ez { display:flex; justify-content:space-between; padding:5px 0; font-size:12px; }
  .ez .label { color:var(--text-dim); font-family:var(--mono); font-size:10px; }
  .ez .wert { font-family:var(--mono); font-weight:600; }
  .ez.negativ .wert { color:var(--red); }
  .ez.rohertrag { border-top:1px solid rgba(45,106,173,0.15); margin-top:6px; padding-top:8px; }
  .ez.ruecklage .wert { color:var(--orange); }
  .ez.gewinn { border-top:1px solid rgba(45,106,173,0.15); margin-top:8px; padding-top:10px; }
  .ez.gewinn .wert { color:var(--green); font-size:18px; font-weight:700; }
  .ez.gewinn .label { font-size:11px; letter-spacing:1px; }
  .fixkosten-hinweis { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-top:10px; }

  .empty-state { font-family:var(--mono); font-size:11px; color:var(--text-dim); letter-spacing:2px; text-align:center; padding:60px 20px; border:1px dashed rgba(45,106,173,0.25); }

  @media(max-width:600px) {
    .plattform-checks { flex-direction:row; flex-wrap:wrap; }
    .plattform-check { flex:1 1 45%; }
    .versand-row { flex-direction:column; }
  }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Gewinn-Kalkulator</div>
  <div class="page-sub">// SCHNELLE WAS-WÄRE-WENN-KALKULATION — UNABHÄNGIG VOM LAGERBESTAND</div>

  <div class="layout">
    <!-- EINGABE -->
    <div class="panel">
      <div class="panel-title">Eingaben</div>

      <label class="field-label">VK-Preis (brutto, €)</label>
      <input type="number" step="0.01" class="field-input" id="vk" placeholder="0.00" oninput="berechnen()">

      <label class="field-label">EK-Preis (brutto, wie tatsächlich bezahlt, €)</label>
      <input type="number" step="0.01" class="field-input" id="ek" placeholder="0.00" oninput="berechnen()">

      <div class="checkbox-row" onclick="document.getElementById('vorsteuer').click()">
        <input type="checkbox" id="vorsteuer" checked onclick="event.stopPropagation(); berechnen()">
        <label onclick="event.stopPropagation()">Vorsteuer aus EK abziehbar</label>
      </div>
      <div class="checkbox-hint">Normalfall bei GmbH-Einkäufen mit Rechnung. Deaktivieren bei Kleinunternehmer-Lieferanten oder wenn keine Vorsteuer gezogen werden kann — dann zählt der volle EK-Betrag als Kosten.</div>

      <label class="field-label">Verkaufsplattform(en)</label>
      <div class="plattform-checks">
        <div class="plattform-check" onclick="toggleCheckbox('pf-amazon')">
          <input type="checkbox" id="pf-amazon" checked onclick="event.stopPropagation(); berechnen()">
          <label onclick="event.stopPropagation()">Amazon</label>
          <span class="satz">15% + 39€/Mon</span>
        </div>
        <div class="plattform-check" onclick="toggleCheckbox('pf-ebay')">
          <input type="checkbox" id="pf-ebay" onclick="event.stopPropagation(); berechnen()">
          <label onclick="event.stopPropagation()">eBay</label>
          <span class="satz">11% + 5% Anzeige</span>
        </div>
        <div class="plattform-check" onclick="toggleCheckbox('pf-tiktok')">
          <input type="checkbox" id="pf-tiktok" onclick="event.stopPropagation(); berechnen()">
          <label onclick="event.stopPropagation()">TikTok Shop</label>
          <span class="satz">8%</span>
        </div>
      </div>

      <label class="field-label">Versandkosten (€)</label>
      <div class="versand-row">
        <input type="number" step="0.01" class="field-input" id="versand" value="3.50" oninput="berechnen()">
        <label class="mwst-toggle">
          <input type="checkbox" id="versand-mwst" checked onchange="berechnen()">
          inkl. MwSt
        </label>
      </div>

      <label class="field-label">Steuerrücklage für Jahresende (%)</label>
      <input type="number" step="1" class="field-input" id="ruecklage" value="30" oninput="berechnen()">
      <div class="checkbox-hint" style="margin-top:-10px;">GmbH: KSt 15% + Soli ~0,8% + GewSt (Hebesatz-abhängig) ≈ 30% als Richtwert.</div>
    </div>

    <!-- ERGEBNISSE -->
    <div>
      <div class="panel-title" style="margin-bottom:16px;">Ergebnis</div>
      <div id="ergebnis-container">
        <div class="empty-state">// VK- UND EK-PREIS EINTRAGEN FÜR KALKULATION</div>
      </div>
    </div>
  </div>
</div>

<script>
const GEBUEHREN = {
  amazon: { prozent: 0.15, monatlich_fix: 39.00, label: 'Amazon' },
  ebay:   { prozent: 0.11, anzeige_prozent: 0.05, label: 'eBay' },
  tiktok: { prozent: 0.08, label: 'TikTok Shop' },
};

function toggleCheckbox(id) {
  const cb = document.getElementById(id);
  cb.checked = !cb.checked;
  berechnen();
}

function eur(n) {
  return n.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';
}

function berechnen() {
  const vk = parseFloat(document.getElementById('vk').value);
  const ekInput = parseFloat(document.getElementById('ek').value);
  const vorsteuerAbziehbar = document.getElementById('vorsteuer').checked;
  const versandInput = parseFloat(document.getElementById('versand').value) || 0;
  const versandInklMwst = document.getElementById('versand-mwst').checked;
  const ruecklageProzent = (parseFloat(document.getElementById('ruecklage').value) || 0) / 100;

  const container = document.getElementById('ergebnis-container');

  if (!vk || vk <= 0 || !ekInput || ekInput <= 0) {
    container.innerHTML = '<div class="empty-state">// VK- UND EK-PREIS EINTRAGEN FÜR KALKULATION</div>';
    return;
  }

  const ekNetto = vorsteuerAbziehbar ? ekInput / 1.19 : ekInput;
  const versandNetto = versandInklMwst ? versandInput / 1.19 : versandInput;
  const vkNetto = vk / 1.19;

  const ausgewaehlt = [];
  if (document.getElementById('pf-amazon').checked) ausgewaehlt.push('amazon');
  if (document.getElementById('pf-ebay').checked) ausgewaehlt.push('ebay');
  if (document.getElementById('pf-tiktok').checked) ausgewaehlt.push('tiktok');

  if (ausgewaehlt.length === 0) {
    container.innerHTML = '<div class="empty-state">// MINDESTENS EINE PLATTFORM AUSWÄHLEN</div>';
    return;
  }

  const grid = document.createElement('div');
  grid.className = 'ergebnis-grid' + (ausgewaehlt.length > 1 ? ' mehrere' : '');

  ausgewaehlt.forEach(key => {
    const g = GEBUEHREN[key];
    let gebuehr = vk * g.prozent;
    if (g.anzeige_prozent) gebuehr += vk * g.anzeige_prozent;

    const rohertrag = vkNetto - gebuehr - versandNetto - ekNetto;
    const ruecklage = rohertrag > 0 ? rohertrag * ruecklageProzent : 0;
    const gewinn = rohertrag - ruecklage;

    const card = document.createElement('div');
    card.className = 'plattform-ergebnis';
    card.innerHTML = `
      <div class="plattform-ergebnis-name">${g.label}</div>
      <div class="ez"><span class="label">Erlös netto (VK ohne USt)</span><span class="wert">${eur(vkNetto)}</span></div>
      <div class="ez"><span class="label">Plattformgebühr${g.anzeige_prozent ? ' (inkl. Anzeige)' : ''}</span><span class="wert">− ${eur(gebuehr)}</span></div>
      <div class="ez"><span class="label">Versand (netto)</span><span class="wert">− ${eur(versandNetto)}</span></div>
      <div class="ez"><span class="label">EK-Kosten${vorsteuerAbziehbar ? ' (netto, VSt abgezogen)' : ' (voll, keine VSt)'}</span><span class="wert">− ${eur(ekNetto)}</span></div>
      <div class="ez rohertrag ${rohertrag < 0 ? 'negativ' : ''}"><span class="label">Rohertrag</span><span class="wert">${eur(rohertrag)}</span></div>
      <div class="ez ruecklage"><span class="label">Steuerrücklage (${(ruecklageProzent*100).toFixed(0)}%)</span><span class="wert">− ${eur(ruecklage)}</span></div>
      <div class="ez gewinn ${gewinn < 0 ? 'negativ' : ''}"><span class="label">Reiner Gewinn</span><span class="wert">${eur(gewinn)}</span></div>
      ${g.monatlich_fix ? `<div class="fixkosten-hinweis">+ ${eur(g.monatlich_fix)}/Monat Fixkosten (nicht pro Stück gerechnet)</div>` : ''}
    `;
    grid.appendChild(card);
  });

  container.innerHTML = '';
  container.appendChild(grid);
}

berechnen();
</script>
</div>
</body>
</html>
