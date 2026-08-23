<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('clean');
require_once __DIR__ . '/clean_helper.php';
$pdo = db();
$kann_bearbeiten = hat_modul_zugriff('clean', 'bearbeiten');
$preise_verbergen = hat_einschraenkung('clean', 'preise_verbergen');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    if (!$kann_bearbeiten) { echo json_encode(['error' => 'Kein Bearbeiten-Recht']); exit; }
    $action = $_POST['action'] ?? '';

    if ($action === 'objekt_aktualisieren') {
        if (!$kann_bearbeiten) { echo json_encode(['error' => 'Kein Bearbeiten-Recht']); exit; }
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("
            UPDATE clean_objekte SET
                firma = ?, ansprechpartner = ?, telefon = ?, email = ?, adresse = ?,
                region_id = ?, qm = ?, glas_qm = ?, verschmutzung_prozent = ?, marge_prozent = ?,
                angebotspreis = ?, aktueller_preis = ?, vertragslaufzeit_monate = ?,
                interesse = ?, bonitaet = ?, bonitaet_notiz = ?, notizen = ?, follow_up_datum = ?
            WHERE id = ?
        ")->execute([
            trim($_POST['firma'] ?? ''),
            trim($_POST['ansprechpartner'] ?? ''),
            trim($_POST['telefon'] ?? ''),
            trim($_POST['email'] ?? ''),
            trim($_POST['adresse'] ?? ''),
            (int)($_POST['region_id'] ?? 0) ?: null,
            (int)($_POST['qm'] ?? 0),
            (int)($_POST['glas_qm'] ?? 0),
            (float)($_POST['verschmutzung_prozent'] ?? 0),
            (float)($_POST['marge_prozent'] ?? 0),
            (float)($_POST['angebotspreis'] ?? 0) ?: null,
            (float)($_POST['aktueller_preis'] ?? 0) ?: null,
            (int)($_POST['vertragslaufzeit_monate'] ?? 1),
            $_POST['interesse'] ?: null,
            $_POST['bonitaet'] ?: 'unbekannt',
            trim($_POST['bonitaet_notiz'] ?? '') ?: null,
            trim($_POST['notizen'] ?? ''),
            $_POST['follow_up_datum'] ?: null,
            $id,
        ]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'objekt_details_holen') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT o.*, r.name AS region_name, s.name AS subunternehmer_name
            FROM clean_objekte o
            LEFT JOIN clean_regionen r ON r.id = o.region_id
            LEFT JOIN clean_subunternehmer s ON s.id = o.subunternehmer_id
            WHERE o.id = ?
        ");
        $stmt->execute([$id]);
        $objekt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$objekt) { echo json_encode(['error' => 'Nicht gefunden']); exit; }
        echo json_encode($objekt);
        exit;
    }

    if ($action === 'status_aendern') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'interessent';
        $erlaubt = ['interessent','angebot','verhandlung','vertrag','aktiv','gekuendigt'];
        if (!in_array($status, $erlaubt)) { echo json_encode(['error' => 'Ungültiger Status']); exit; }
        $pdo->prepare("UPDATE clean_objekte SET status = ? WHERE id = ?")->execute([$status, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'objekt_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM clean_objekte WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'testauftrag_erstellen') {
        $region = $pdo->query("SELECT id FROM clean_regionen ORDER BY id LIMIT 1")->fetchColumn();
        $user = currentUser();
        $pdo->prepare("
            INSERT INTO clean_objekte
                (firma, ansprechpartner, telefon, email, adresse, region_id, qm, glas_qm,
                 verschmutzung_prozent, marge_prozent, angebotspreis, interesse, notizen,
                 status, ist_test, erstellt_von)
            VALUES ('Test GmbH (Beispiel)', 'Max Mustermann', '0421 123456', 'test@beispiel.de',
                    'Musterstraße 1, 28195 Bremen', ?, 150, 25, 10, 25, 245.00, 'warm',
                    'Automatisch erzeugter Testauftrag zum Ausprobieren — kann jederzeit gelöscht werden.',
                    'interessent', 1, ?)
        ")->execute([$region, $user['id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

$regionen = $pdo->query("SELECT * FROM clean_regionen ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$laufzeit_optionen = clean_laufzeit_optionen();

$objekte = $pdo->query("
    SELECT o.*, r.name AS region_name, s.name AS subunternehmer_name
    FROM clean_objekte o
    LEFT JOIN clean_regionen r ON r.id = o.region_id
    LEFT JOIN clean_subunternehmer s ON s.id = o.subunternehmer_id
    ORDER BY o.erstellt_am DESC
")->fetchAll(PDO::FETCH_ASSOC);

$status_labels = [
    'interessent' => 'Interessent', 'angebot' => 'Angebot gesendet', 'verhandlung' => 'Verhandlung',
    'vertrag' => 'Vertrag', 'aktiv' => 'Aktiv', 'gekuendigt' => 'Gekündigt',
];
$spalten = array_fill_keys(array_keys($status_labels), []);
foreach ($objekte as $o) { $spalten[$o['status']][] = $o; }

$interesse_icons = ['heiss' => '🔴', 'warm' => '🟠', 'kalt' => '🔵'];
$bonitaet_icons = ['gruen' => '🟢', 'gelb' => '🟡', 'rot' => '🔴', 'unbekannt' => ''];
$frequenz_labels = ['taeglich' => 'Täglich', 'woechentlich' => 'Wöchentlich', '2x_woche' => '2x/Woche', 'monatlich' => 'Monatlich'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NA Clean Service — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --orange: #FBBF24; --red: #F87171; --purple:#B8A6FF;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); z-index:100; }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }

  .main { max-width:1500px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:22px; font-weight:700; color:#F5F6FA; margin-bottom:16px; }

  .page-actions { display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
  .btn { font-family:var(--mono); font-size:10px; padding:9px 16px; cursor:pointer; border:1px solid; background:none; text-decoration:none; display:inline-block; }
  .btn-primary { color:var(--green); border-color:rgba(74,222,128,0.4); }
  .btn-primary:hover { background:rgba(74,222,128,0.1); }
  .btn-secondary { color:var(--blue-bright); border-color:rgba(148,148,255,0.4); }

  .kanban { display:grid; grid-template-columns:repeat(6,1fr); gap:12px; }
  @media(max-width:1300px) { .kanban { grid-template-columns:repeat(3,1fr); } }
  @media(max-width:700px) { .kanban { grid-template-columns:1fr; } }

  .spalte { background:rgba(29,32,48,0.6); border:1px solid rgba(124,124,255,0.2); min-height:150px; }
  .spalte-header { padding:12px 14px; border-bottom:1px solid rgba(124,124,255,0.2); font-family:var(--mono); font-size:10px; display:flex; justify-content:space-between; align-items:center; }
  .spalte.interessent .spalte-header { color:var(--text-dim); }
  .spalte.angebot .spalte-header { color:var(--blue-bright); }
  .spalte.verhandlung .spalte-header { color:var(--orange); }
  .spalte.vertrag .spalte-header { color:var(--purple); }
  .spalte.aktiv .spalte-header { color:var(--green); }
  .spalte.gekuendigt .spalte-header { color:var(--red); }
  .spalte-count { background:rgba(148,148,255,0.15); color:var(--blue-bright); padding:1px 7px; border-radius:8px; font-size:10px; }
  .spalte-body { padding:10px; display:flex; flex-direction:column; gap:8px; }

  .karte { background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.2); padding:12px; font-size:12px; }
  .karte-firma { font-weight:700; font-size:13px; margin-bottom:4px; }
  .test-tag { font-family:var(--mono); font-size:8px; color:var(--orange); background:rgba(251,191,36,0.15); padding:2px 6px; border-radius:6px; margin-left:6px; }
  .karte-meta { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-bottom:6px; line-height:1.6; }
  .karte-preis { font-family:var(--mono); font-size:12px; color:var(--green); font-weight:700; margin-bottom:8px; }
  .karte-aktionen { display:flex; gap:6px; flex-wrap:wrap; }
  select.status-select { background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--mono); font-size:9px; padding:4px 6px; }
  .mini-btn { font-family:var(--mono); font-size:9px; padding:4px 8px; border:1px solid rgba(248,113,113,0.3); background:none; color:var(--red); cursor:pointer; }
  .empty-spalte { font-family:var(--mono); font-size:9px; color:var(--text-dim); text-align:center; padding:16px 8px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Kunden-Pipeline</div>

  <div class="page-actions">
    <?php if ($kann_bearbeiten): ?>
      <a href="vor_ort_formular.php" class="btn btn-primary">+ NEUER LEAD / GESPRÄCH</a>
      <button class="btn" style="color:var(--orange);border-color:rgba(251,191,36,0.4);" onclick="testauftragAnlegen()">🧪 TESTAUFTRAG ANLEGEN</button>
    <?php endif; ?>
    <a href="/kalkulator/rechner.php" class="btn btn-secondary">📊 KALKULATOR</a>
    <a href="subunternehmer.php" class="btn btn-secondary">👷 SUBUNTERNEHMER</a>
    <a href="einsaetze.php" class="btn btn-secondary">📅 EINSÄTZE</a>
    <a href="rechnungen.php" class="btn btn-secondary">🧾 RECHNUNGEN</a>
  </div>

  <div class="kanban">
    <?php foreach ($status_labels as $key => $label): ?>
    <div class="spalte <?= $key ?>">
      <div class="spalte-header">
        <span><?= $label ?></span>
        <span class="spalte-count"><?= count($spalten[$key]) ?></span>
      </div>
      <div class="spalte-body">
        <?php if (empty($spalten[$key])): ?>
          <div class="empty-spalte">// LEER</div>
        <?php endif; ?>
        <?php foreach ($spalten[$key] as $o): ?>
        <div class="karte" id="objekt-<?= $o['id'] ?>" onclick="detailsOeffnen(<?= $o['id'] ?>)" style="cursor:pointer;">
          <div class="karte-firma"><?= $interesse_icons[$o['interesse']] ?? '' ?> <?= htmlspecialchars($o['firma']) ?> <?= $bonitaet_icons[$o['bonitaet'] ?? 'unbekannt'] ?? '' ?><?php if ($o['ist_test']): ?><span class="test-tag">TEST</span><?php endif; ?></div>
          <div class="karte-meta">
            <?= $o['qm'] ?> m² · <?= $frequenz_labels[$o['frequenz']] ?? $o['frequenz'] ?><br>
            <?= htmlspecialchars($o['region_name'] ?? '—') ?>
            <?php if ($o['subunternehmer_name']): ?> · <?= htmlspecialchars($o['subunternehmer_name']) ?><?php endif; ?>
          </div>
          <?php if (!$preise_verbergen && $o['angebotspreis']): ?>
            <div class="karte-preis">€<?= number_format($o['angebotspreis'], 2, ',', '.') ?>/Monat</div>
          <?php endif; ?>
          <?php if ($kann_bearbeiten): ?>
          <div class="karte-aktionen" onclick="event.stopPropagation()">
            <select class="status-select" onchange="statusAendern(<?= $o['id'] ?>, this.value)">
              <?php foreach ($status_labels as $sk => $sl): ?>
                <option value="<?= $sk ?>" <?= $sk === $key ? 'selected' : '' ?>><?= $sl ?></option>
              <?php endforeach; ?>
            </select>
            <button class="mini-btn" onclick="objektLoeschen(<?= $o['id'] ?>)">✕</button>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- DETAIL-MODAL -->
<div class="modal-overlay" id="modal-details" onclick="if(event.target===this) detailsSchliessen()">
  <div class="modal-box">
    <div class="modal-kopf">
      <span id="dm-titel">// LEAD-DETAILS</span>
      <div style="display:flex; gap:10px; align-items:center;">
        <?php if ($kann_bearbeiten): ?>
        <button class="modal-close" id="dm-bearbeiten-btn" onclick="bearbeitenModusUmschalten()" style="font-size:11px; font-family:var(--mono); color:var(--blue-bright);">✎ Bearbeiten</button>
        <?php endif; ?>
        <button class="modal-close" onclick="detailsSchliessen()">✕</button>
      </div>
    </div>
    <div class="modal-inhalt" id="dm-inhalt">
      <div class="dm-lade">Lädt...</div>
    </div>
  </div>
</div>

<style>
  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:200; align-items:center; justify-content:center; }
  .modal-overlay.open { display:flex; }
  .modal-box { background:var(--navy2); border:1px solid rgba(124,124,255,0.3); width:100%; max-width:560px; margin:20px; max-height:88vh; overflow-y:auto; }
  .modal-kopf { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid rgba(124,124,255,0.15); font-family:var(--mono); font-size:11px; color:var(--blue-bright); position:sticky; top:0; background:var(--navy2); }
  .modal-close { background:none; border:none; color:var(--text-dim); cursor:pointer; font-size:16px; }
  .modal-inhalt { padding:20px; }
  .dm-lade { font-family:var(--mono); font-size:11px; color:var(--text-dim); text-align:center; padding:30px; }
  .dm-gruppe { margin-bottom:18px; }
  .dm-gruppe-titel { font-family:var(--mono); font-size:9px; color:var(--blue-bright); border-bottom:1px dashed rgba(124,124,255,0.2); padding-bottom:6px; margin-bottom:8px; }
  .dm-zeile { display:flex; justify-content:space-between; gap:12px; padding:5px 0; font-size:13px; }
  .dm-zeile .dm-label { color:var(--text-dim); font-family:var(--mono); font-size:9px; flex-shrink:0; padding-top:2px; }
  .dm-zeile .dm-wert { text-align:right; }
  .dm-notiz-box { background:rgba(20,22,31,0.6); border:1px solid rgba(124,124,255,0.2); padding:12px 14px; font-size:13px; line-height:1.6; white-space:pre-wrap; }
  .dm-preis { font-family:var(--mono); font-size:20px; font-weight:700; color:var(--green); }
  .dm-badge { font-family:var(--mono); font-size:9px; padding:2px 8px; border-radius:8px; }

  .dm-field-label { font-family:var(--mono); font-size:8px; color:var(--text-dim); margin-bottom:4px; display:block; }
  .dm-input, .dm-select, .dm-textarea { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--sans); font-size:13px; padding:8px 10px; outline:none; margin-bottom:10px; }
  .dm-textarea { min-height:70px; resize:vertical; }
  .dm-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .dm-speichern-btn { width:100%; background:none; border:1px solid var(--green); color:var(--green); padding:12px; font-family:var(--mono); font-size:12px; cursor:pointer; margin-top:6px; }
  .dm-speichern-btn:hover { background:rgba(74,222,128,0.1); }
</style>

<script>
async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('pipeline.php', { method:'POST', body:fd });
  return res.json();
}

async function statusAendern(id, status) {
  const res = await api({ action: 'status_aendern', id, status });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

const STATUS_LABELS = <?= json_encode($status_labels) ?>;
const FREQUENZ_LABELS = <?= json_encode($frequenz_labels) ?>;
const INTERESSE_LABELS = { heiss: '🔴 Heiß', warm: '🟠 Warm', kalt: '🔵 Kalt' };
const BONITAET_LABELS = { gruen: '🟢 Unauffällig', gelb: '🟡 Vorsicht', rot: '🔴 Risiko', unbekannt: '— Nicht geprüft' };
const REGIONEN = <?= json_encode($regionen) ?>;
const LAUFZEIT_OPTIONEN = <?= json_encode($laufzeit_optionen) ?>;
let aktuelleDetailDaten = null;
let bearbeitenModusAktiv = false;

async function detailsOeffnen(id) {
  bearbeitenModusAktiv = false;
  document.getElementById('modal-details').classList.add('open');
  document.getElementById('dm-inhalt').innerHTML = '<div class="dm-lade">Lädt...</div>';
  const btn = document.getElementById('dm-bearbeiten-btn');
  if (btn) btn.textContent = '✎ Bearbeiten';

  const res = await api({ action: 'objekt_details_holen', id });
  if (res.error) { document.getElementById('dm-inhalt').innerHTML = '<div class="dm-lade">Fehler beim Laden</div>'; return; }

  aktuelleDetailDaten = res;
  document.getElementById('dm-titel').textContent = '// ' + res.firma.toUpperCase();
  ansichtRendern();
}

function bearbeitenModusUmschalten() {
  bearbeitenModusAktiv = !bearbeitenModusAktiv;
  const btn = document.getElementById('dm-bearbeiten-btn');
  btn.textContent = bearbeitenModusAktiv ? '👁 Ansehen' : '✎ Bearbeiten';
  if (bearbeitenModusAktiv) { bearbeitenFormularRendern(); } else { ansichtRendern(); }
}

function ansichtRendern() {
  const res = aktuelleDetailDaten;

  const eur = (n) => n === null ? '—' : parseFloat(n).toLocaleString('de-DE', {minimumFractionDigits:2}) + ' €';
  const zeile = (label, wert) => wert ? `<div class="dm-zeile"><span class="dm-label">${label}</span><span class="dm-wert">${wert}</span></div>` : '';

  let arten_html = '';
  try {
    const arten = JSON.parse(res.ausgewaehlte_arten || '[]');
    if (arten.length) {
      arten_html = arten.map(a => `<div class="dm-zeile"><span class="dm-label">${a.name}</span><span class="dm-wert">${a.menge} × €${a.preis} × ${a.einsaetze_pro_jahr}/Jahr</span></div>`).join('');
    }
  } catch(e) {}

  document.getElementById('dm-inhalt').innerHTML = `
    <div class="dm-gruppe">
      <div class="dm-gruppe-titel">Kontakt</div>
      ${zeile('Ansprechpartner', res.ansprechpartner)}
      ${zeile('Telefon', res.telefon)}
      ${zeile('E-Mail', res.email)}
      ${zeile('Adresse', res.adresse)}
      ${zeile('Status', STATUS_LABELS[res.status] || res.status)}
      ${zeile('Interesse', INTERESSE_LABELS[res.interesse] || '—')}
      ${zeile('Bonität', BONITAET_LABELS[res.bonitaet] || '—')}
      ${res.bonitaet_notiz ? `<div class="dm-notiz-box" style="margin-top:8px;">${res.bonitaet_notiz.replace(/</g,'&lt;')}</div>` : ''}
    </div>

    <div class="dm-gruppe">
      <div class="dm-gruppe-titel">Objekt &amp; Kalkulation</div>
      ${zeile('Fläche', res.qm + ' m²' + (res.glas_qm > 0 ? ' (+ ' + res.glas_qm + ' m² Glas)' : ''))}
      ${zeile('Region', res.region_name)}
      ${zeile('Subunternehmer', res.subunternehmer_name)}
      ${zeile('Verschmutzung', res.verschmutzung_prozent + ' %')}
      ${zeile('Marge', res.marge_prozent + ' %')}
      ${zeile('Vertragslaufzeit', res.vertragslaufzeit_monate + ' Monat(e)')}
      ${zeile('Aktueller Preis (bisher)', res.aktueller_preis ? eur(res.aktueller_preis) + '/Monat' : null)}
      ${arten_html}
      <div class="dm-zeile" style="margin-top:10px; padding-top:10px; border-top:1px solid rgba(124,124,255,0.15);">
        <span class="dm-label">Angebotspreis</span>
        <span class="dm-preis">${eur(res.angebotspreis)}/Monat</span>
      </div>
    </div>

    <div class="dm-gruppe">
      <div class="dm-gruppe-titel">Notizen aus dem Gespräch</div>
      <div class="dm-notiz-box">${res.notizen ? res.notizen.replace(/</g,'&lt;').replace(/\n/g,'<br>') : '— keine Notizen —'}</div>
    </div>

    ${res.follow_up_datum ? `<div class="dm-gruppe">
      <div class="dm-gruppe-titel">Follow-up</div>
      <div class="dm-zeile"><span class="dm-label">Termin</span><span class="dm-wert">${new Date(res.follow_up_datum).toLocaleDateString('de-DE')}</span></div>
    </div>` : ''}
  `;
}

function detailsSchliessen() {
  document.getElementById('modal-details').classList.remove('open');
}

function bearbeitenFormularRendern() {
  const res = aktuelleDetailDaten;

  const regionenOptions = REGIONEN.map(r => `<option value="${r.id}" ${String(res.region_id) === String(r.id) ? 'selected' : ''}>${r.name}</option>`).join('');
  const laufzeitOptions = LAUFZEIT_OPTIONEN.map(l => `<option value="${l.monate}" ${String(res.vertragslaufzeit_monate) === String(l.monate) ? 'selected' : ''}>${l.label}</option>`).join('');
  const interesseOptions = Object.entries(INTERESSE_LABELS).map(([k,v]) => `<option value="${k}" ${res.interesse === k ? 'selected' : ''}>${v}</option>`).join('');
  const bonitaetOptions = Object.entries(BONITAET_LABELS).map(([k,v]) => `<option value="${k}" ${res.bonitaet === k ? 'selected' : ''}>${v}</option>`).join('');

  document.getElementById('dm-inhalt').innerHTML = `
    <div class="dm-gruppe">
      <div class="dm-gruppe-titel">Kontakt</div>
      <label class="dm-field-label">Firma</label>
      <input class="dm-input" id="dme-firma" value="${res.firma || ''}">
      <div class="dm-row-2">
        <div><label class="dm-field-label">Ansprechpartner</label><input class="dm-input" id="dme-ansprechpartner" value="${res.ansprechpartner || ''}"></div>
        <div><label class="dm-field-label">Telefon</label><input class="dm-input" id="dme-telefon" value="${res.telefon || ''}"></div>
      </div>
      <div class="dm-row-2">
        <div><label class="dm-field-label">E-Mail</label><input class="dm-input" id="dme-email" value="${res.email || ''}"></div>
        <div><label class="dm-field-label">Adresse</label><input class="dm-input" id="dme-adresse" value="${res.adresse || ''}"></div>
      </div>
      <label class="dm-field-label">Interesse</label>
      <select class="dm-select" id="dme-interesse">${interesseOptions}</select>
      <label class="dm-field-label">Bonität</label>
      <select class="dm-select" id="dme-bonitaet">${bonitaetOptions}</select>
      <label class="dm-field-label">Bonitäts-Notiz</label>
      <textarea class="dm-textarea" id="dme-bonitaet_notiz">${res.bonitaet_notiz || ''}</textarea>
    </div>

    <div class="dm-gruppe">
      <div class="dm-gruppe-titel">Objekt &amp; Kalkulation</div>
      <div class="dm-row-2">
        <div><label class="dm-field-label">Fläche (m²)</label><input class="dm-input" type="number" id="dme-qm" value="${res.qm || 0}"></div>
        <div><label class="dm-field-label">Glasfläche (m²)</label><input class="dm-input" type="number" id="dme-glas_qm" value="${res.glas_qm || 0}"></div>
      </div>
      <label class="dm-field-label">Region</label>
      <select class="dm-select" id="dme-region_id"><option value="">—</option>${regionenOptions}</select>
      <div class="dm-row-2">
        <div><label class="dm-field-label">Verschmutzung (%)</label><input class="dm-input" type="number" id="dme-verschmutzung_prozent" value="${res.verschmutzung_prozent || 0}"></div>
        <div><label class="dm-field-label">Marge (%)</label><input class="dm-input" type="number" id="dme-marge_prozent" value="${res.marge_prozent || 0}"></div>
      </div>
      <label class="dm-field-label">Vertragslaufzeit</label>
      <select class="dm-select" id="dme-vertragslaufzeit_monate">${laufzeitOptions}</select>
      <div class="dm-row-2">
        <div><label class="dm-field-label">Angebotspreis (€/Monat)</label><input class="dm-input" type="number" step="0.01" id="dme-angebotspreis" value="${res.angebotspreis || ''}"></div>
        <div><label class="dm-field-label">Aktueller Preis bisher (€)</label><input class="dm-input" type="number" step="0.01" id="dme-aktueller_preis" value="${res.aktueller_preis || ''}"></div>
      </div>
    </div>

    <div class="dm-gruppe">
      <div class="dm-gruppe-titel">Notizen &amp; Follow-up</div>
      <label class="dm-field-label">Notizen aus dem Gespräch</label>
      <textarea class="dm-textarea" id="dme-notizen">${res.notizen || ''}</textarea>
      <label class="dm-field-label">Follow-up-Termin</label>
      <input class="dm-input" type="date" id="dme-follow_up_datum" value="${res.follow_up_datum || ''}">
    </div>

    <button class="dm-speichern-btn" onclick="detailsSpeichern()">💾 Änderungen speichern</button>
  `;
}

async function detailsSpeichern() {
  const id = aktuelleDetailDaten.id;
  const res = await api({
    action: 'objekt_aktualisieren', id,
    firma: document.getElementById('dme-firma').value,
    ansprechpartner: document.getElementById('dme-ansprechpartner').value,
    telefon: document.getElementById('dme-telefon').value,
    email: document.getElementById('dme-email').value,
    adresse: document.getElementById('dme-adresse').value,
    region_id: document.getElementById('dme-region_id').value,
    qm: document.getElementById('dme-qm').value,
    glas_qm: document.getElementById('dme-glas_qm').value,
    verschmutzung_prozent: document.getElementById('dme-verschmutzung_prozent').value,
    marge_prozent: document.getElementById('dme-marge_prozent').value,
    angebotspreis: document.getElementById('dme-angebotspreis').value,
    aktueller_preis: document.getElementById('dme-aktueller_preis').value,
    vertragslaufzeit_monate: document.getElementById('dme-vertragslaufzeit_monate').value,
    interesse: document.getElementById('dme-interesse').value,
    bonitaet: document.getElementById('dme-bonitaet').value,
    bonitaet_notiz: document.getElementById('dme-bonitaet_notiz').value,
    notizen: document.getElementById('dme-notizen').value,
    follow_up_datum: document.getElementById('dme-follow_up_datum').value,
  });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function testauftragAnlegen() {
  const res = await api({ action: 'testauftrag_erstellen' });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function objektLoeschen(id) {
  if (!confirm('Diesen Eintrag wirklich löschen?')) return;
  const res = await api({ action: 'objekt_loeschen', id });
  if (res.error) { alert(res.error); return; }
  document.getElementById('objekt-' + id)?.remove();
}
</script>
</div>
</body>
</html>