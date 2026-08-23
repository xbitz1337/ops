<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('clean');
require_once __DIR__ . '/clean_helper.php';
$pdo = db();

$regionen = $pdo->query("SELECT * FROM clean_regionen ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$arten = $pdo->query("SELECT * FROM clean_reinigungsarten ORDER BY sortierung")->fetchAll(PDO::FETCH_ASSOC);
$einheit_labels = ['m2' => 'm²', 'm2_glas' => 'm² Glas', 'stunde' => 'Std.'];
$frequenz_presets = clean_frequenz_presets();
$laufzeit_optionen = clean_laufzeit_optionen();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Clean-Kalkulator — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --orange: #FBBF24; --red:#F87171;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .main { max-width:1050px; margin:0 auto; padding:24px 20px 60px; display:grid; grid-template-columns:1fr 340px; gap:20px; }
  @media(max-width:900px) { .main { grid-template-columns:1fr; } }
  .page-title { font-size:20px; font-weight:700; color:#F5F6FA; margin-bottom:20px; grid-column:1/-1; }

  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; margin-bottom:16px; }
  .panel-title { font-family:var(--mono); font-size:11px; color:var(--blue-bright); margin-bottom:16px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:6px; display:block; }
  .field-input, .field-select { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:10px 12px; outline:none; margin-bottom:14px; }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }

  .art-zeile { border:1px solid rgba(124,124,255,0.2); padding:12px 14px; margin-bottom:10px; }
  .art-zeile.aktiv { border-color:rgba(74,222,128,0.4); background:rgba(74,222,128,0.05); }
  .art-kopf { display:flex; align-items:center; gap:10px; cursor:pointer; }
  .art-kopf input[type=checkbox] { width:18px; height:18px; accent-color:var(--green); }
  .art-name { font-weight:700; font-size:13px; flex:1; }
  .art-range { font-family:var(--mono); font-size:9px; color:var(--text-dim); }
  .art-details { display:none; margin-top:12px; padding-top:12px; border-top:1px dashed rgba(124,124,255,0.2); }
  .art-details.sichtbar { display:block; }
  .art-details .field-input { margin-bottom:10px; padding:7px 10px; font-size:12px; }

  .freq-presets { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
  .freq-btn { font-family:var(--mono); font-size:9px; padding:6px 10px; border:1px solid rgba(124,124,255,0.25); background:none; color:var(--text-dim); cursor:pointer; white-space:nowrap; }
  .freq-btn.active { background:rgba(148,148,255,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }
  .freq-btn .rabatt-tag { color:var(--green); margin-left:4px; }

  .ergebnis-box { position:sticky; top:64px; background:rgba(20,22,31,0.6); border:1px solid rgba(74,222,128,0.3); padding:18px; height:fit-content; }
  .erg-zeile { display:flex; justify-content:space-between; font-family:var(--mono); font-size:12px; padding:6px 0; border-bottom:1px solid rgba(124,124,255,0.08); }
  .erg-zeile .label { color:var(--text-dim); }
  .erg-zeile.final { margin-top:6px; padding-top:12px; border-top:1px solid rgba(74,222,128,0.2); }
  .erg-zeile.final .wert { color:var(--green); font-size:18px; font-weight:700; }
  .aufschluesselung-titel { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin:14px 0 6px; }
  .aufschl-zeile { display:flex; justify-content:space-between; font-family:var(--mono); font-size:10px; padding:3px 0; color:var(--text-dim); }

  .wettbewerb-box { background:rgba(251,191,36,0.1); border:1px solid rgba(251,191,36,0.3); padding:14px; margin-top:14px; }
  .wettbewerb-box .titel { font-family:var(--mono); font-size:9px; color:var(--orange); margin-bottom:8px; }
  .wettbewerb-box .preis { font-size:18px; font-weight:700; color:var(--orange); font-family:var(--mono); }
  .wettbewerb-box .hinweis { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-top:6px; line-height:1.4; }
  .wettbewerb-box .uebernehmen-btn { font-family:var(--mono); font-size:9px; color:var(--green); background:none; border:1px solid var(--green); padding:5px 10px; cursor:pointer; margin-top:8px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Angebots-Kalkulator</div>

  <div>
    <div class="panel">
      <div class="panel-title">// FLÄCHEN &amp; RAHMENDATEN</div>
      <div class="row-2">
        <div><label class="field-label">Fläche (m²)</label><input class="field-input" type="number" id="qm" value="100"></div>
        <div><label class="field-label">Glasfläche (m², für Fensterreinigung)</label><input class="field-input" type="number" id="glas_qm" value="20"></div>
      </div>
      <div class="row-3">
        <div><label class="field-label">Verschmutzung (%)</label><input class="field-input" type="number" id="verschmutzung" value="0"></div>
        <div><label class="field-label">Ziel-Marge (%)</label><input class="field-input" type="number" id="marge" value="25"></div>
        <div><label class="field-label">Mindestmarge (%)</label><input class="field-input" type="number" id="mindestmarge" value="15"></div>
      </div>
      <label class="field-label">Region</label>
      <select class="field-select" id="region">
        <?php foreach ($regionen as $r): ?>
          <option value="<?= $r['id'] ?>" data-zuschlag="<?= $r['fahrtkosten_zuschlag'] ?>"><?= htmlspecialchars($r['name']) ?> (+€<?= number_format($r['fahrtkosten_zuschlag'],2,',','.') ?>)</option>
        <?php endforeach; ?>
      </select>

      <label class="field-label">Vertragslaufzeit</label>
      <select class="field-select" id="laufzeit" style="margin-bottom:0;">
        <?php foreach ($laufzeit_optionen as $l): ?>
          <option value="<?= $l['monate'] ?>" data-rabatt="<?= $l['rabatt'] ?>"><?= $l['label'] ?><?= $l['rabatt'] > 0 ? " (−{$l['rabatt']}% auf Marge)" : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="panel">
      <div class="panel-title">// WETTBEWERBSPREIS (optional)</div>
      <label class="field-label">Was zahlt der Kunde aktuell? (€/Monat)</label>
      <input class="field-input" type="number" step="0.01" id="aktueller_preis" placeholder="leer lassen, falls unbekannt" style="margin-bottom:0;">
    </div>

    <div class="panel">
      <div class="panel-title">// REINIGUNGSARTEN AUSWÄHLEN (mehrere möglich)</div>
      <?php foreach ($arten as $a): $mittelwert = ($a['preis_min'] + $a['preis_max']) / 2; ?>
      <div class="art-zeile" id="art-zeile-<?= $a['id'] ?>">
        <div class="art-kopf" onclick="artUmschalten(<?= $a['id'] ?>)">
          <input type="checkbox" id="art-check-<?= $a['id'] ?>" onclick="event.stopPropagation(); artUmschalten(<?= $a['id'] ?>)">
          <span class="art-name"><?= htmlspecialchars($a['name']) ?></span>
          <span class="art-range">€<?= number_format($a['preis_min'],2,',','.') ?>–<?= number_format($a['preis_max'],2,',','.') ?>/<?= $einheit_labels[$a['einheit']] ?></span>
        </div>
        <div class="art-details" id="art-details-<?= $a['id'] ?>" data-einheit="<?= $a['einheit'] ?>">
          <label class="field-label">Häufigkeit</label>
          <div class="freq-presets" id="freq-presets-<?= $a['id'] ?>">
            <?php foreach ($frequenz_presets as $fp): ?>
              <button type="button" class="freq-btn" data-jahr="<?= $fp['einsaetze_jahr'] ?>" data-rabatt="<?= $fp['rabatt'] ?>" onclick="freqWaehlen(<?= $a['id'] ?>, this)">
                <?= $fp['label'] ?><?php if ($fp['rabatt'] > 0): ?><span class="rabatt-tag">−<?= $fp['rabatt'] ?>%</span><?php endif; ?>
              </button>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="art-einsaetze-<?= $a['id'] ?>" value="52">
          <input type="hidden" id="art-rabatt-<?= $a['id'] ?>" value="0">

          <label class="field-label">Preis/<?= $einheit_labels[$a['einheit']] ?> (€, Liste)</label>
          <input class="field-input" type="number" step="0.01" id="art-preis-<?= $a['id'] ?>" value="<?= number_format($mittelwert,2,'.','') ?>" onchange="berechnen()">

          <?php if ($a['einheit'] === 'stunde'): ?>
          <label class="field-label">Stunden pro Einsatz</label>
          <input class="field-input" type="number" step="0.5" id="art-stunden-<?= $a['id'] ?>" value="2" onchange="berechnen()">
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="ergebnis-box">
    <div class="panel-title" style="color:var(--green);">📊 ERGEBNIS</div>
    <div class="erg-zeile"><span class="label">Basiskosten/Monat</span><span class="wert" id="e-basis">—</span></div>
    <div class="erg-zeile"><span class="label">Kosten gesamt</span><span class="wert" id="e-kosten">—</span></div>
    <div class="erg-zeile"><span class="label">Effektive Marge</span><span class="wert" id="e-marge">—</span></div>
    <div class="erg-zeile final"><span class="label">Angebotspreis/Monat</span><span class="wert" id="e-preis">—</span></div>
    <div class="erg-zeile final"><span class="label">Profit/Monat</span><span class="wert" id="e-profit">—</span></div>

    <div class="wettbewerb-box" id="wettbewerb-box" style="display:none;">
      <div class="titel">💡 Wettbewerbspreis-Vorschlag</div>
      <div class="preis" id="w-preis">—</div>
      <div class="hinweis" id="w-hinweis"></div>
    </div>

    <div class="aufschluesselung-titel">Aufschlüsselung je Art (monatl.)</div>
    <div id="aufschluesselung"></div>
  </div>
</div>

<script>
const ARTEN = <?= json_encode($arten) ?>;
const eur = (n) => n.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';

function artUmschalten(id) {
  const check = document.getElementById('art-check-' + id);
  check.checked = !check.checked;
  document.getElementById('art-zeile-' + id).classList.toggle('aktiv', check.checked);
  document.getElementById('art-details-' + id).classList.toggle('sichtbar', check.checked);
  berechnen();
}

function freqWaehlen(artId, btn) {
  document.querySelectorAll('#freq-presets-' + artId + ' .freq-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('art-einsaetze-' + artId).value = btn.dataset.jahr;
  document.getElementById('art-rabatt-' + artId).value = btn.dataset.rabatt;
  berechnen();
}

['qm','glas_qm','verschmutzung','marge','mindestmarge','region','laufzeit','aktueller_preis'].forEach(id => {
  document.getElementById(id).addEventListener('input', berechnen);
  document.getElementById(id).addEventListener('change', berechnen);
});

let letzterKostenGesamt = 0;
let letztesMindestmarge = 15;

function berechnen() {
  const qm = parseFloat(document.getElementById('qm').value) || 0;
  const glasQm = parseFloat(document.getElementById('glas_qm').value) || 0;
  const verschmutzung = parseFloat(document.getElementById('verschmutzung').value) || 0;
  const marge = parseFloat(document.getElementById('marge').value) || 0;
  const mindestmarge = parseFloat(document.getElementById('mindestmarge').value) || 0;
  const regionOpt = document.getElementById('region').options[document.getElementById('region').selectedIndex];
  const fahrtkosten = parseFloat(regionOpt?.dataset.zuschlag) || 0;
  const laufzeitOpt = document.getElementById('laufzeit').options[document.getElementById('laufzeit').selectedIndex];
  const laufzeitRabatt = parseFloat(laufzeitOpt?.dataset.rabatt) || 0;
  const aktuellerPreis = parseFloat(document.getElementById('aktueller_preis').value) || 0;

  let jahreskostenGesamt = 0;
  const aufschluesselung = [];

  ARTEN.forEach(art => {
    const check = document.getElementById('art-check-' + art.id);
    if (!check || !check.checked) return;

    const preisListe = parseFloat(document.getElementById('art-preis-' + art.id).value) || 0;
    const einsaetze = parseFloat(document.getElementById('art-einsaetze-' + art.id).value) || 0;
    const freqRabatt = parseFloat(document.getElementById('art-rabatt-' + art.id).value) || 0;
    const effektiverPreis = preisListe * (1 - freqRabatt / 100);

    let menge = 0;
    if (art.einheit === 'm2') menge = qm;
    else if (art.einheit === 'm2_glas') menge = glasQm;
    else if (art.einheit === 'stunde') menge = parseFloat(document.getElementById('art-stunden-' + art.id)?.value) || 0;

    const jahreskosten = effektiverPreis * menge * einsaetze;
    jahreskostenGesamt += jahreskosten;
    aufschluesselung.push({ name: art.name, monatskosten: jahreskosten / 12, rabatt: freqRabatt });
  });

  const basisKostenMonat = jahreskostenGesamt / 12;
  const kostenMitVerschmutzung = basisKostenMonat * (1 + verschmutzung / 100);
  const kostenGesamt = kostenMitVerschmutzung + fahrtkosten;

  // Laufzeit-Rabatt wirkt auf die Marge, nie unter die Mindestmarge
  const effektiveMarge = Math.max(mindestmarge, marge - laufzeitRabatt);

  const angebotspreis = kostenGesamt * (1 + effektiveMarge / 100);
  const profit = angebotspreis - kostenGesamt;

  letzterKostenGesamt = kostenGesamt;
  letztesMindestmarge = mindestmarge;

  document.getElementById('e-basis').textContent = eur(basisKostenMonat);
  document.getElementById('e-kosten').textContent = eur(kostenGesamt);
  document.getElementById('e-marge').textContent = effektiveMarge.toFixed(1) + ' %' + (effektiveMarge === mindestmarge && laufzeitRabatt > 0 ? ' (gedeckelt)' : '');
  document.getElementById('e-preis').textContent = eur(angebotspreis);
  document.getElementById('e-profit').textContent = eur(profit);

  const aufschlDiv = document.getElementById('aufschluesselung');
  aufschlDiv.innerHTML = aufschluesselung.length
    ? aufschluesselung.map(a => `<div class="aufschl-zeile"><span>${a.name}${a.rabatt > 0 ? ' (−'+a.rabatt+'%)' : ''}</span><span>${eur(a.monatskosten)}</span></div>`).join('')
    : '<div class="aufschl-zeile"><span>— keine Art gewählt —</span></div>';

  // Wettbewerbspreis-Vorschlag
  const wBox = document.getElementById('wettbewerb-box');
  if (aktuellerPreis > 0 && kostenGesamt > 0) {
    const vorschlag = Math.round(aktuellerPreis * 0.925 * 100) / 100;
    const mindestpreis = Math.round(kostenGesamt * (1 + mindestmarge / 100) * 100) / 100;
    wBox.style.display = 'block';
    if (vorschlag < mindestpreis) {
      document.getElementById('w-preis').textContent = eur(mindestpreis);
      document.getElementById('w-hinweis').textContent = '⚠️ Unterbietung des aktuellen Preises (' + eur(vorschlag) + ') wäre unter eurer Mindestmarge — stattdessen Mindestpreis vorgeschlagen.';
    } else {
      document.getElementById('w-preis').textContent = eur(vorschlag);
      document.getElementById('w-hinweis').textContent = 'Ca. 7,5% günstiger als der bisherige Preis (' + eur(aktuellerPreis) + ') — attraktiv für den Kunden, bleibt aber über eurer Mindestmarge.';
    }
  } else {
    wBox.style.display = 'none';
  }
}
berechnen();
</script>
</div>
</body>
</html>
