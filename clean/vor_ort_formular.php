<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('clean', 'bearbeiten');
require_once __DIR__ . '/clean_helper.php';
$user = currentUser();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firma = trim($_POST['firma'] ?? '');
    $ansprechpartner = trim($_POST['ansprechpartner'] ?? '');
    $telefon = trim($_POST['telefon'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '');
    $region_id = (int)($_POST['region_id'] ?? 0) ?: null;
    $qm = (int)($_POST['qm'] ?? 0);
    $glas_qm = (int)($_POST['glas_qm'] ?? 0);
    $verschmutzung = (float)($_POST['verschmutzung'] ?? 0);
    $marge = (float)($_POST['marge'] ?? 25);
    $angebotspreis = (float)($_POST['angebotspreis_final'] ?? 0) ?: null;
    $ausgewaehlte_arten = $_POST['ausgewaehlte_arten_json'] ?? '[]';
    $interesse = $_POST['interesse'] ?? null;
    $notizen = trim($_POST['notizen'] ?? '');
    $follow_up = $_POST['follow_up_datum'] ?? null;
    $bonitaet = $_POST['bonitaet'] ?? 'unbekannt';
    $bonitaet_notiz = trim($_POST['bonitaet_notiz'] ?? '');
    $aktueller_preis = (float)($_POST['aktueller_preis'] ?? 0) ?: null;
    $vertragslaufzeit_monate = (int)($_POST['vertragslaufzeit_monate'] ?? 1);
    if (!in_array($bonitaet, ['unbekannt','gruen','gelb','rot'])) $bonitaet = 'unbekannt';

    if ($firma) {
        $pdo->prepare("
            INSERT INTO clean_objekte
                (firma, ansprechpartner, telefon, email, adresse, region_id, qm, glas_qm,
                 verschmutzung_prozent, marge_prozent, angebotspreis, ausgewaehlte_arten,
                 interesse, bonitaet, bonitaet_notiz, aktueller_preis, vertragslaufzeit_monate,
                 notizen, follow_up_datum, status, erstellt_von)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'interessent', ?)
        ")->execute([
            $firma, $ansprechpartner, $telefon, $email, $adresse, $region_id, $qm, $glas_qm,
            $verschmutzung, $marge, $angebotspreis, $ausgewaehlte_arten, $interesse,
            $bonitaet, $bonitaet_notiz ?: null, $aktueller_preis, $vertragslaufzeit_monate,
            $notizen, $follow_up ?: null, $user['id'],
        ]);
        header('Location: pipeline.php');
        exit;
    }
}

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
<title>Vor-Ort-Gespräch — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --orange: #FBBF24; --red: #F87171;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .main { max-width:1200px; margin:0 auto; padding:24px 20px 60px; display:grid; grid-template-columns:1fr 360px; gap:20px; }
  @media(max-width:950px) { .main { grid-template-columns:1fr; } }

  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; margin-bottom:16px; border-radius:20px; }
  .panel-title { font-family:var(--mono); font-size:11px; color:var(--blue-bright); margin-bottom:16px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:6px; display:block; }
  .field-input, .field-select, .field-textarea { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--sans); font-size:14px; padding:10px 12px; outline:none; margin-bottom:14px; border-radius:12px; }
  .field-textarea { min-height:60px; resize:vertical; border-radius:12px; }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }

  .bonitaet-row { display:flex; gap:8px; margin-bottom:12px; }
  .bonitaet-btn { flex:1; padding:10px; border:1px solid rgba(124,124,255,0.25); background:none; color:var(--text-dim); font-family:var(--mono); font-size:16px; cursor:pointer; text-align:center; }
  .bonitaet-btn.gruen.active { background:rgba(74,222,128,0.15); border-color:var(--green); }
  .bonitaet-btn.gelb.active { background:rgba(251,191,36,0.15); border-color:var(--orange); }
  .bonitaet-btn.rot.active { background:rgba(248,113,113,0.15); border-color:var(--red); }
  .check-links { display:flex; gap:14px; margin-bottom:12px; font-family:var(--mono); font-size:10px; }
  .check-links a { color:var(--blue-bright); text-decoration:none; }
  .check-links a:hover { text-decoration:underline; }

  .art-zeile { border:1px solid rgba(124,124,255,0.2); padding:10px 12px; margin-bottom:8px; }
  .art-zeile.aktiv { border-color:rgba(74,222,128,0.4); background:rgba(74,222,128,0.05); }
  .art-kopf { display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12px; }
  .art-kopf input[type=checkbox] { width:16px; height:16px; accent-color:var(--green); }
  .art-name { font-weight:700; flex:1; }
  .art-details { display:none; margin-top:10px; padding-top:10px; border-top:1px dashed rgba(124,124,255,0.2); }
  .art-details.sichtbar { display:block; }
  .art-details .field-input { margin-bottom:8px; padding:6px 8px; font-size:11px; border-radius:12px; }

  .freq-presets { display:flex; flex-wrap:wrap; gap:5px; margin-bottom:8px; }
  .freq-btn { font-family:var(--mono); font-size:8px; padding:5px 8px; border:1px solid rgba(124,124,255,0.25); background:none; color:var(--text-dim); cursor:pointer; white-space:nowrap; }
  .freq-btn.active { background:rgba(148,148,255,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }
  .freq-btn .rabatt-tag { color:var(--green); margin-left:3px; }

  .interesse-row { display:flex; gap:8px; margin-bottom:16px; }
  .interesse-btn { flex:1; padding:10px; border:1px solid rgba(124,124,255,0.25); background:none; color:var(--text-dim); font-family:var(--mono); font-size:11px; cursor:pointer; }
  .interesse-btn.heiss.active { background:rgba(248,113,113,0.15); border-color:var(--red); color:var(--red); }
  .interesse-btn.warm.active { background:rgba(251,191,36,0.15); border-color:var(--orange); color:var(--orange); }
  .interesse-btn.kalt.active { background:rgba(148,148,255,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }

  .btn-submit { width:100%; background:none; border:1px solid var(--green); color:var(--green); padding:15px; font-family:var(--mono); font-size:13px; cursor:pointer; }
  .btn-submit:hover { background:rgba(74,222,128,0.1); }

  .rechner-panel { position:sticky; top:64px; background:rgba(29,32,48,0.9); border:1px solid rgba(74,222,128,0.3); padding:18px; height:fit-content; }
  .rechner-titel { font-family:var(--mono); font-size:11px; color:var(--green); margin-bottom:14px; }
  .erg-zeile { display:flex; justify-content:space-between; font-family:var(--mono); font-size:11px; padding:5px 0; border-bottom:1px solid rgba(124,124,255,0.08); }
  .erg-zeile .label { color:var(--text-dim); }
  .erg-zeile.final { margin-top:6px; padding-top:10px; border-top:1px solid rgba(74,222,128,0.2); }
  .erg-zeile.final .wert { color:var(--green); font-size:16px; font-weight:700; }
  .lohnt-badge { text-align:center; padding:9px; margin-top:12px; font-family:var(--mono); font-size:10px; }
  .lohnt-badge.ja { background:rgba(74,222,128,0.15); color:var(--green); border:1px solid rgba(74,222,128,0.3); }
  .lohnt-badge.nein { background:rgba(248,113,113,0.15); color:var(--red); border:1px solid rgba(248,113,113,0.3); }

  .wettbewerb-box { background:rgba(251,191,36,0.1); border:1px solid rgba(251,191,36,0.3); padding:12px; margin-top:12px; }
  .wettbewerb-box .titel { font-family:var(--mono); font-size:9px; color:var(--orange); margin-bottom:6px; }
  .wettbewerb-box .preis { font-size:16px; font-weight:700; color:var(--orange); font-family:var(--mono); }
  .wettbewerb-box .hinweis { font-family:var(--mono); font-size:8px; color:var(--text-dim); margin-top:5px; line-height:1.4; }
  .wettbewerb-box .uebernehmen-btn { font-family:var(--mono); font-size:9px; color:var(--green); background:none; border:1px solid var(--green); padding:5px 10px; cursor:pointer; margin-top:8px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div>
    <form method="POST" id="form">
      <div class="panel">
        <div class="panel-title">Kontaktdaten</div>
        <label class="field-label">Firma</label>
        <input class="field-input" type="text" name="firma" id="firma" required>
        <div class="check-links">
          <a href="https://www.handelsregister.de" target="_blank">🔍 Handelsregister prüfen →</a>
          <a href="https://www.insolvenzbekanntmachungen.de" target="_blank">🔍 Insolvenzbekanntmachungen →</a>
        </div>
        <div class="row-2">
          <div><label class="field-label">Ansprechpartner</label><input class="field-input" type="text" name="ansprechpartner"></div>
          <div><label class="field-label">Telefon</label><input class="field-input" type="text" name="telefon"></div>
        </div>
        <div class="row-2">
          <div><label class="field-label">E-Mail</label><input class="field-input" type="email" name="email"></div>
          <div><label class="field-label">Adresse</label><input class="field-input" type="text" name="adresse"></div>
        </div>

        <label class="field-label">Bonität (nach eigener Prüfung)</label>
        <div class="bonitaet-row">
          <button type="button" class="bonitaet-btn gruen" data-wert="gruen" onclick="bonitaetWaehlen('gruen')">🟢</button>
          <button type="button" class="bonitaet-btn gelb" data-wert="gelb" onclick="bonitaetWaehlen('gelb')">🟡</button>
          <button type="button" class="bonitaet-btn rot" data-wert="rot" onclick="bonitaetWaehlen('rot')">🔴</button>
        </div>
        <input type="hidden" name="bonitaet" id="bonitaet" value="unbekannt">
        <label class="field-label">Notiz zur Bonitätsprüfung (optional)</label>
        <textarea class="field-textarea" name="bonitaet_notiz" placeholder="z.B. Handelsregister: seit 2015 eingetragen, unauffällig"></textarea>
      </div>

      <div class="panel">
        <div class="panel-title">Flächen &amp; Rahmendaten</div>
        <div class="row-2">
          <div><label class="field-label">Fläche (m²)</label><input class="field-input" type="number" name="qm" id="qm" value="100"></div>
          <div><label class="field-label">Glasfläche (m²)</label><input class="field-input" type="number" name="glas_qm" id="glas_qm" value="20"></div>
        </div>
        <div class="row-3">
          <div><label class="field-label">Verschmutzung (%)</label><input class="field-input" type="number" name="verschmutzung" id="verschmutzung" value="0"></div>
          <div><label class="field-label">Ziel-Marge (%)</label><input class="field-input" type="number" name="marge" id="marge" value="25"></div>
          <div><label class="field-label">Mindestmarge (%)</label><input class="field-input" type="number" id="mindestmarge" value="15"></div>
        </div>
        <label class="field-label">Region</label>
        <select class="field-select" name="region_id" id="region_id">
          <?php foreach ($regionen as $r): ?>
            <option value="<?= $r['id'] ?>" data-zuschlag="<?= $r['fahrtkosten_zuschlag'] ?>"><?= htmlspecialchars($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="field-label">Vertragslaufzeit</label>
        <select class="field-select" name="vertragslaufzeit_monate" id="laufzeit">
          <?php foreach ($laufzeit_optionen as $l): ?>
            <option value="<?= $l['monate'] ?>" data-rabatt="<?= $l['rabatt'] ?>"><?= $l['label'] ?><?= $l['rabatt'] > 0 ? " (−{$l['rabatt']}% auf Marge)" : '' ?></option>
          <?php endforeach; ?>
        </select>
        <label class="field-label">Was zahlt der Kunde aktuell? (€/Monat, optional)</label>
        <input class="field-input" type="number" step="0.01" name="aktueller_preis" id="aktueller_preis" style="margin-bottom:0;">
        <input type="hidden" name="angebotspreis_final" id="angebotspreis_final">
        <input type="hidden" name="ausgewaehlte_arten_json" id="ausgewaehlte_arten_json">
      </div>

      <div class="panel">
        <div class="panel-title">Reinigungsarten (mehrere möglich)</div>
        <?php foreach ($arten as $a): $mittelwert = ($a['preis_min'] + $a['preis_max']) / 2; ?>
        <div class="art-zeile" id="art-zeile-<?= $a['id'] ?>">
          <div class="art-kopf" onclick="artUmschalten(<?= $a['id'] ?>)">
            <input type="checkbox" id="art-check-<?= $a['id'] ?>" onclick="event.stopPropagation(); artUmschalten(<?= $a['id'] ?>)">
            <span class="art-name"><?= htmlspecialchars($a['name']) ?></span>
            <span style="font-family:var(--mono); font-size:9px; color:var(--text-dim);">€<?= number_format($a['preis_min'],2,',','.') ?>–<?= number_format($a['preis_max'],2,',','.') ?>/<?= $einheit_labels[$a['einheit']] ?></span>
          </div>
          <div class="art-details" id="art-details-<?= $a['id'] ?>" data-einheit="<?= $a['einheit'] ?>" data-name="<?= htmlspecialchars($a['name'], ENT_QUOTES) ?>">
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
            <input class="field-input" type="number" step="0.01" id="art-preis-<?= $a['id'] ?>" value="<?= number_format($mittelwert,2,'.','') ?>">
            <?php if ($a['einheit'] === 'stunde'): ?>
            <label class="field-label">Stunden/Einsatz</label>
            <input class="field-input" type="number" step="0.5" id="art-stunden-<?= $a['id'] ?>" value="2">
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="panel">
        <div class="panel-title">Gesprächsnotizen</div>
        <label class="field-label">Interesse-Level</label>
        <div class="interesse-row">
          <button type="button" class="interesse-btn heiss" data-wert="heiss" onclick="interesseWaehlen('heiss')">🔴 Heiß</button>
          <button type="button" class="interesse-btn warm" data-wert="warm" onclick="interesseWaehlen('warm')">🟠 Warm</button>
          <button type="button" class="interesse-btn kalt" data-wert="kalt" onclick="interesseWaehlen('kalt')">🔵 Kalt</button>
        </div>
        <input type="hidden" name="interesse" id="interesse">
        <label class="field-label">Notizen aus dem Gespräch</label>
        <textarea class="field-textarea" name="notizen" placeholder="Wünsche, aktuelle Reinigungsfirma, Besonderheiten..."></textarea>
        <label class="field-label">Follow-up-Termin</label>
        <input class="field-input" type="date" name="follow_up_datum">
      </div>

      <button type="submit" class="btn-submit">✓ Als Lead speichern</button>
    </form>
  </div>

  <div class="rechner-panel">
    <div class="rechner-titel">📊 Live-Kalkulation</div>
    <div class="erg-zeile"><span class="label">Basiskosten/Monat</span><span class="wert" id="e-basis">—</span></div>
    <div class="erg-zeile"><span class="label">Kosten gesamt</span><span class="wert" id="e-kosten">—</span></div>
    <div class="erg-zeile"><span class="label">Effektive Marge</span><span class="wert" id="e-marge">—</span></div>
    <div class="erg-zeile final"><span class="label">Angebotspreis/Monat</span><span class="wert" id="e-preis">—</span></div>
    <div class="erg-zeile final"><span class="label">Profit/Monat</span><span class="wert" id="e-profit">—</span></div>
    <div class="lohnt-badge" id="lohnt-badge" style="display:none;"></div>

    <div class="wettbewerb-box" id="wettbewerb-box" style="display:none;">
      <div class="titel">💡 Wettbewerbspreis-Vorschlag</div>
      <div class="preis" id="w-preis">—</div>
      <div class="hinweis" id="w-hinweis"></div>
      <button type="button" class="uebernehmen-btn" onclick="wettbewerbspreisUebernehmen()">Diesen Preis übernehmen</button>
    </div>
  </div>
</div>

<script>
const ARTEN = <?= json_encode($arten) ?>;
const eur = (n) => n.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';
let angebotspreisUeberschrieben = null;

function bonitaetWaehlen(wert) {
  document.getElementById('bonitaet').value = wert;
  document.querySelectorAll('.bonitaet-btn').forEach(b => b.classList.toggle('active', b.dataset.wert === wert));
}

function interesseWaehlen(wert) {
  document.getElementById('interesse').value = wert;
  document.querySelectorAll('.interesse-btn').forEach(b => b.classList.toggle('active', b.dataset.wert === wert));
}

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

function wettbewerbspreisUebernehmen() {
  const preisText = document.getElementById('w-preis').textContent;
  const preis = parseFloat(preisText.replace(/\./g,'').replace(',','.').replace('€','').trim());
  angebotspreisUeberschrieben = preis;
  document.getElementById('angebotspreis_final').value = preis.toFixed(2);
  document.getElementById('e-preis').textContent = eur(preis) + ' (übernommen)';
  alert('Wettbewerbspreis übernommen: ' + eur(preis));
}

['qm','glas_qm','verschmutzung','marge','mindestmarge','region_id','laufzeit','aktueller_preis'].forEach(id => {
  document.getElementById(id).addEventListener('input', () => { angebotspreisUeberschrieben = null; berechnen(); });
  document.getElementById(id).addEventListener('change', () => { angebotspreisUeberschrieben = null; berechnen(); });
});
document.querySelectorAll('.art-details input[type=number]').forEach(inp => inp.addEventListener('input', () => { angebotspreisUeberschrieben = null; berechnen(); }));

function berechnen() {
  const qm = parseFloat(document.getElementById('qm').value) || 0;
  const glasQm = parseFloat(document.getElementById('glas_qm').value) || 0;
  const verschmutzung = parseFloat(document.getElementById('verschmutzung').value) || 0;
  const marge = parseFloat(document.getElementById('marge').value) || 0;
  const mindestmarge = parseFloat(document.getElementById('mindestmarge').value) || 0;
  const regionOpt = document.getElementById('region_id').options[document.getElementById('region_id').selectedIndex];
  const fahrtkosten = parseFloat(regionOpt?.dataset.zuschlag) || 0;
  const laufzeitOpt = document.getElementById('laufzeit').options[document.getElementById('laufzeit').selectedIndex];
  const laufzeitRabatt = parseFloat(laufzeitOpt?.dataset.rabatt) || 0;
  const aktuellerPreis = parseFloat(document.getElementById('aktueller_preis').value) || 0;

  let jahreskostenGesamt = 0;
  const gewaehlteArten = [];

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
    gewaehlteArten.push({ art_id: art.id, name: art.name, preis: preisListe, einsaetze_pro_jahr: einsaetze, menge, rabatt: freqRabatt });
  });

  document.getElementById('ausgewaehlte_arten_json').value = JSON.stringify(gewaehlteArten);

  const basisKostenMonat = jahreskostenGesamt / 12;
  const kostenMitVerschmutzung = basisKostenMonat * (1 + verschmutzung / 100);
  const kostenGesamt = kostenMitVerschmutzung + fahrtkosten;
  const effektiveMarge = Math.max(mindestmarge, marge - laufzeitRabatt);
  const angebotspreisBerechnet = kostenGesamt * (1 + effektiveMarge / 100);
  const angebotspreis = angebotspreisUeberschrieben !== null ? angebotspreisUeberschrieben : angebotspreisBerechnet;
  const profit = angebotspreis - kostenGesamt;

  if (angebotspreisUeberschrieben === null) {
    document.getElementById('angebotspreis_final').value = angebotspreisBerechnet.toFixed(2);
  }

  document.getElementById('e-basis').textContent = eur(basisKostenMonat);
  document.getElementById('e-kosten').textContent = eur(kostenGesamt);
  document.getElementById('e-marge').textContent = effektiveMarge.toFixed(1) + ' %';
  document.getElementById('e-preis').textContent = eur(angebotspreis);
  document.getElementById('e-profit').textContent = eur(profit);

  const badge = document.getElementById('lohnt-badge');
  if (gewaehlteArten.length > 0) {
    badge.style.display = 'block';
    if (profit >= 50) { badge.className = 'lohnt-badge ja'; badge.textContent = '✓ Lohnt sich gut'; }
    else if (profit >= 0) { badge.className = 'lohnt-badge ja'; badge.textContent = '~ Lohnt sich knapp'; }
    else { badge.className = 'lohnt-badge nein'; badge.textContent = '✕ Lohnt sich nicht — anpassen'; }
  } else {
    badge.style.display = 'none';
  }

  const wBox = document.getElementById('wettbewerb-box');
  if (aktuellerPreis > 0 && kostenGesamt > 0) {
    const vorschlag = Math.round(aktuellerPreis * 0.925 * 100) / 100;
    const mindestpreis = Math.round(kostenGesamt * (1 + mindestmarge / 100) * 100) / 100;
    wBox.style.display = 'block';
    if (vorschlag < mindestpreis) {
      document.getElementById('w-preis').textContent = eur(mindestpreis);
      document.getElementById('w-hinweis').textContent = '⚠️ Unterbietung wäre unter Mindestmarge — Mindestpreis vorgeschlagen.';
    } else {
      document.getElementById('w-preis').textContent = eur(vorschlag);
      document.getElementById('w-hinweis').textContent = 'Ca. 7,5% günstiger als bisher (' + eur(aktuellerPreis) + '), über eurer Mindestmarge.';
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
