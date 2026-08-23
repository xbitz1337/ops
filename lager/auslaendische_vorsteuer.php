<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('lager');
$user = currentUser();
$pdo = db();
$kann_bearbeiten = hat_modul_zugriff('lager', 'bearbeiten');
$ist_admin = $user['username'] === 'qaf';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // Bearbeiten eines bestehenden Eintrags — NUR für Admin (Systemverwalter),
    // unabhängig vom normalen "Lager bearbeiten"-Recht
    if ($action === 'eintrag_bearbeiten') {
        if (!$ist_admin) { echo json_encode(['error' => 'Nur der Systemverwalter kann bestehende Einträge bearbeiten']); exit; }

        $id = (int)($_POST['id'] ?? 0);
        $land = trim($_POST['land'] ?? '');
        $lieferant = trim($_POST['lieferant'] ?? '');
        $rechnungsnummer = trim($_POST['rechnungsnummer'] ?? '');
        $bruttobetrag = (float)($_POST['bruttobetrag'] ?? 0);
        $mwst_satz = (float)($_POST['mwst_satz'] ?? 0);
        $menge = (int)($_POST['menge'] ?? 0);

        if (!$id || !$land || $bruttobetrag <= 0) { echo json_encode(['error' => 'Ungültige Eingabe']); exit; }

        $nettobetrag = round($bruttobetrag / (1 + $mwst_satz / 100), 2);
        $mwst_betrag = round($bruttobetrag - $nettobetrag, 2);

        $stmt = $pdo->prepare("SELECT * FROM auslaendische_vorsteuer WHERE id = ?");
        $stmt->execute([$id]);
        $eintrag = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$eintrag) { echo json_encode(['error' => 'Eintrag nicht gefunden']); exit; }

        $pdo->prepare("
            UPDATE auslaendische_vorsteuer
            SET land = ?, lieferant = ?, rechnungsnummer = ?, bruttobetrag = ?, mwst_satz = ?, nettobetrag = ?, mwst_betrag = ?
            WHERE id = ?
        ")->execute([$land, $lieferant, $rechnungsnummer, $bruttobetrag, $mwst_satz, $nettobetrag, $mwst_betrag, $id]);

        // Verknüpfte Lager-Bewegung passend zum aktuellen Status neu berechnen
        // (Brutto solange nicht erstattet, sonst Netto — gleiche Logik wie beim
        // Umschalten auf "Erhalten")
        if ($eintrag['lager_bewegung_id'] && $menge > 0) {
            $ziel_preis = $eintrag['status'] === 'erhalten'
                ? round($nettobetrag / $menge, 4)
                : round($bruttobetrag / $menge, 4);

            $pdo->prepare("UPDATE lager_bewegungen SET menge = ?, ek_preis_netto = ? WHERE id = ?")
                ->execute([$menge, $ziel_preis, $eintrag['lager_bewegung_id']]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if (!$kann_bearbeiten) { echo json_encode(['error' => 'Kein Bearbeiten-Recht']); exit; }

    if ($action === 'status_aendern') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $erlaubt = ['offen', 'beantragt', 'erhalten', 'abgelehnt'];
        if (!in_array($status, $erlaubt)) { echo json_encode(['error' => 'Ungültig']); exit; }

        if ($status === 'beantragt') {
            $pdo->prepare("UPDATE auslaendische_vorsteuer SET status = ?, beantragt_am = CURDATE() WHERE id = ?")->execute([$status, $id]);
        } elseif ($status === 'erhalten') {
            $pdo->prepare("UPDATE auslaendische_vorsteuer SET status = ?, erhalten_am = CURDATE() WHERE id = ?")->execute([$status, $id]);

            // WICHTIG: Jetzt ist die Erstattung sicher — die zugehörige
            // Lager-Bewegung wird von Brutto auf den echten Netto-Wareneinsatz
            // korrigiert (vorher stand da vorsichtshalber der volle vorgestreckte
            // Betrag drin, für den Fall, dass die Erstattung ausbleibt)
            $stmt = $pdo->prepare("SELECT * FROM auslaendische_vorsteuer WHERE id = ?");
            $stmt->execute([$id]);
            $eintrag = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($eintrag && $eintrag['lager_bewegung_id']) {
                $bewegung_stmt = $pdo->prepare("SELECT menge FROM lager_bewegungen WHERE id = ?");
                $bewegung_stmt->execute([$eintrag['lager_bewegung_id']]);
                $menge = (int)$bewegung_stmt->fetchColumn();

                if ($menge > 0) {
                    $netto_je_stueck = round((float)$eintrag['nettobetrag'] / $menge, 4);
                    $pdo->prepare("
                        UPDATE lager_bewegungen
                        SET ek_preis_netto = ?, notiz = CONCAT(notiz, ' — MwSt erstattet, auf Netto korrigiert')
                        WHERE id = ?
                    ")->execute([$netto_je_stueck, $eintrag['lager_bewegung_id']]);
                }
            }
        } else {
            $pdo->prepare("UPDATE auslaendische_vorsteuer SET status = ? WHERE id = ?")->execute([$status, $id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

$status_filter = $_GET['status'] ?? '';
$sql = "
    SELECT v.*, p.name AS produkt_name, b.menge AS bewegung_menge
    FROM auslaendische_vorsteuer v
    LEFT JOIN lager_produkte p ON p.id = v.produkt_id
    LEFT JOIN lager_bewegungen b ON b.id = v.lager_bewegung_id
";
$params = [];
if ($status_filter) { $sql .= " WHERE v.status = ?"; $params[] = $status_filter; }
$sql .= " ORDER BY v.erstellt_am DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$eintraege = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summe_offen = (float)$pdo->query("SELECT COALESCE(SUM(mwst_betrag),0) FROM auslaendische_vorsteuer WHERE status IN ('offen','beantragt')")->fetchColumn();
$summe_erhalten_jahr = (float)$pdo->query("SELECT COALESCE(SUM(mwst_betrag),0) FROM auslaendische_vorsteuer WHERE status = 'erhalten' AND YEAR(erhalten_am) = YEAR(CURDATE())")->fetchColumn();

$status_labels = ['offen' => '⏳ Offen', 'beantragt' => '📤 Beantragt', 'erhalten' => '✓ Erhalten', 'abgelehnt' => '✕ Abgelehnt'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ausländische Vorsteuer — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #0f1923; --navy2: #152236; --blue-accent: #2d6aad; --blue-bright: #4a9edd;
    --text: #c8dff0; --text-dim: #5a7a9a; --green: #2ecc71; --orange: #e67e22; --red:#e74c3c;
    --mono: 'Share Tech Mono', monospace; --sans: 'Exo 2', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .main { max-width:900px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#e8f4ff; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); letter-spacing:1px; margin-bottom:20px; }

  .kpi-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:20px; }
  .kpi-card { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:16px; }
  .kpi-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); text-transform:uppercase; margin-bottom:6px; }
  .kpi-value { font-size:22px; font-weight:700; }

  .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
  .filter-tabs { display:flex; gap:6px; flex-wrap:wrap; }
  .filter-tab { font-family:var(--mono); font-size:10px; padding:7px 12px; border:1px solid rgba(45,106,173,0.25); background:none; color:var(--text-dim); text-decoration:none; }
  .filter-tab.active { background:rgba(74,158,221,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }
  .btn { font-family:var(--mono); font-size:10px; letter-spacing:1px; text-transform:uppercase; padding:9px 16px; cursor:pointer; border:1px solid; background:none; text-decoration:none; display:inline-block; }
  .btn-primary { color:var(--green); border-color:rgba(46,204,113,0.4); }

  .eintrag-karte { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:16px; margin-bottom:10px; }
  .eintrag-kopf { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px; }
  .eintrag-titel { font-weight:700; font-size:14px; }
  .eintrag-meta { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-top:3px; }
  .eintrag-betrag { font-family:var(--mono); font-size:18px; font-weight:700; color:var(--orange); text-align:right; }
  .eintrag-status-row { display:flex; gap:8px; align-items:center; margin-top:10px; flex-wrap:wrap; }
  select.status-select { background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--mono); font-size:10px; padding:6px 10px; }
  .status-badge { font-family:var(--mono); font-size:9px; padding:3px 9px; border-radius:8px; }
  .status-badge.offen { background:rgba(230,126,34,0.15); color:var(--orange); }
  .status-badge.beantragt { background:rgba(74,158,221,0.15); color:var(--blue-bright); }
  .status-badge.erhalten { background:rgba(46,204,113,0.15); color:var(--green); }
  .status-badge.abgelehnt { background:rgba(231,76,60,0.15); color:var(--red); }
  .empty { text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); padding:30px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Ausländische Vorsteuer</div>
  <div class="page-sub">// TRACKING FÜR AUSLANDSEINKÄUFE (SCHWEDEN, EU U.A.)</div>

  <div class="kpi-row">
    <div class="kpi-card">
      <div class="kpi-label">Noch offen / beantragt</div>
      <div class="kpi-value" style="color:var(--orange);">€<?= number_format($summe_offen, 2, ',', '.') ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Erhalten dieses Jahr</div>
      <div class="kpi-value" style="color:var(--green);">€<?= number_format($summe_erhalten_jahr, 2, ',', '.') ?></div>
    </div>
  </div>

  <div class="toolbar">
    <div class="filter-tabs">
      <a href="auslaendische_vorsteuer.php" class="filter-tab <?= !$status_filter ? 'active' : '' ?>">Alle</a>
      <?php foreach ($status_labels as $sk => $sl): ?>
        <a href="auslaendische_vorsteuer.php?status=<?= $sk ?>" class="filter-tab <?= $status_filter === $sk ? 'active' : '' ?>"><?= $sl ?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($kann_bearbeiten): ?>
      <a href="auslaendischer_einkauf.php" class="btn btn-primary">+ AUSLANDSEINKAUF ERFASSEN</a>
    <?php endif; ?>
  </div>

  <?php if (empty($eintraege)): ?>
    <div class="empty">// KEINE EINTRÄGE GEFUNDEN</div>
  <?php else: ?>
    <?php foreach ($eintraege as $e): ?>
    <div class="eintrag-karte" id="eintrag-<?= $e['id'] ?>">
      <div class="eintrag-kopf">
        <div>
          <div class="eintrag-titel"><?= htmlspecialchars($e['produkt_name'] ?? '—') ?> · <?= htmlspecialchars($e['land']) ?></div>
          <div class="eintrag-meta">
            <?= htmlspecialchars($e['lieferant'] ?: '—') ?><?= $e['rechnungsnummer'] ? ' · Rg.-Nr. ' . htmlspecialchars($e['rechnungsnummer']) : '' ?> · <?= date('d.m.Y', strtotime($e['erstellt_am'])) ?>
          </div>
          <div class="eintrag-meta">Brutto €<?= number_format($e['bruttobetrag'],2,',','.') ?> · <?= number_format($e['mwst_satz'],2,',','.') ?>% MwSt</div>
        </div>
        <div class="eintrag-betrag">€<?= number_format($e['mwst_betrag'], 2, ',', '.') ?></div>
      </div>
      <div class="eintrag-status-row">
        <span class="status-badge <?= $e['status'] ?>"><?= $status_labels[$e['status']] ?></span>
        <?php if ($e['beantragt_am']): ?><span class="eintrag-meta">Beantragt: <?= date('d.m.Y', strtotime($e['beantragt_am'])) ?></span><?php endif; ?>
        <?php if ($e['erhalten_am']): ?><span class="eintrag-meta">Erhalten: <?= date('d.m.Y', strtotime($e['erhalten_am'])) ?></span><?php endif; ?>
        <?php if ($kann_bearbeiten): ?>
          <select class="status-select" onchange="statusAendern(<?= $e['id'] ?>, this.value)">
            <?php foreach ($status_labels as $sk => $sl): ?>
              <option value="<?= $sk ?>" <?= $sk === $e['status'] ? 'selected' : '' ?>><?= $sl ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <?php if ($ist_admin): ?>
          <button class="btn" style="color:var(--blue-bright); border-color:rgba(74,158,221,0.4); padding:6px 12px; font-size:9px;"
                  onclick='bearbeitenOeffnen(<?= json_encode([
                      "id" => $e["id"], "land" => $e["land"], "lieferant" => $e["lieferant"],
                      "rechnungsnummer" => $e["rechnungsnummer"], "bruttobetrag" => $e["bruttobetrag"],
                      "mwst_satz" => $e["mwst_satz"], "menge" => $e["bewegung_menge"],
                  ]) ?>)'>✎ Bearbeiten</button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Bearbeiten-Modal, nur Admin -->
<?php if ($ist_admin): ?>
<div id="bearbeiten-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:400; align-items:center; justify-content:center;">
  <div style="background:var(--navy2); border:1px solid rgba(45,106,173,0.3); width:100%; max-width:420px; margin:20px; padding:20px;">
    <div style="font-family:var(--mono); font-size:11px; letter-spacing:2px; color:var(--blue-bright); margin-bottom:16px;">// EINTRAG BEARBEITEN</div>
    <input type="hidden" id="be-id">
    <label class="field-label" style="font-family:var(--mono); font-size:9px; color:var(--text-dim); display:block; margin-bottom:5px;">Land</label>
    <input class="field-input" id="be-land" style="width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:9px 11px; margin-bottom:12px;">
    <label class="field-label" style="font-family:var(--mono); font-size:9px; color:var(--text-dim); display:block; margin-bottom:5px;">Lieferant</label>
    <input class="field-input" id="be-lieferant" style="width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:9px 11px; margin-bottom:12px;">
    <label class="field-label" style="font-family:var(--mono); font-size:9px; color:var(--text-dim); display:block; margin-bottom:5px;">Rechnungsnummer</label>
    <input class="field-input" id="be-rechnungsnummer" style="width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:9px 11px; margin-bottom:12px;">
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
      <div>
        <label class="field-label" style="font-family:var(--mono); font-size:9px; color:var(--text-dim); display:block; margin-bottom:5px;">Menge (Stk)</label>
        <input class="field-input" type="number" id="be-menge" style="width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:9px 11px; margin-bottom:12px;">
      </div>
      <div>
        <label class="field-label" style="font-family:var(--mono); font-size:9px; color:var(--text-dim); display:block; margin-bottom:5px;">Bruttobetrag (€)</label>
        <input class="field-input" type="number" step="0.01" id="be-bruttobetrag" style="width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:9px 11px; margin-bottom:12px;">
      </div>
    </div>
    <label class="field-label" style="font-family:var(--mono); font-size:9px; color:var(--text-dim); display:block; margin-bottom:5px;">MwSt-Satz (%)</label>
    <input class="field-input" type="number" step="0.01" id="be-mwst-satz" style="width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:9px 11px; margin-bottom:16px;">
    <div style="display:flex; gap:10px;">
      <button class="btn" style="flex:1; color:var(--text-dim); border-color:rgba(90,122,154,0.3);" onclick="bearbeitenSchliessen()">Abbrechen</button>
      <button class="btn btn-primary" style="flex:1;" onclick="bearbeitenSpeichern()">✓ Speichern</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
async function statusAendern(id, status) {
  const fd = new FormData();
  fd.append('api', 1); fd.append('action', 'status_aendern'); fd.append('id', id); fd.append('status', status);
  const res = await fetch('auslaendische_vorsteuer.php', { method:'POST', body:fd });
  const daten = await res.json();
  if (daten.error) { alert(daten.error); return; }
  window.location.reload();
}

function bearbeitenOeffnen(eintrag) {
  document.getElementById('be-id').value = eintrag.id;
  document.getElementById('be-land').value = eintrag.land;
  document.getElementById('be-lieferant').value = eintrag.lieferant || '';
  document.getElementById('be-rechnungsnummer').value = eintrag.rechnungsnummer || '';
  document.getElementById('be-menge').value = eintrag.menge;
  document.getElementById('be-bruttobetrag').value = eintrag.bruttobetrag;
  document.getElementById('be-mwst-satz').value = eintrag.mwst_satz;
  document.getElementById('bearbeiten-overlay').style.display = 'flex';
}
function bearbeitenSchliessen() {
  document.getElementById('bearbeiten-overlay').style.display = 'none';
}
async function bearbeitenSpeichern() {
  const fd = new FormData();
  fd.append('api', 1);
  fd.append('action', 'eintrag_bearbeiten');
  fd.append('id', document.getElementById('be-id').value);
  fd.append('land', document.getElementById('be-land').value);
  fd.append('lieferant', document.getElementById('be-lieferant').value);
  fd.append('rechnungsnummer', document.getElementById('be-rechnungsnummer').value);
  fd.append('menge', document.getElementById('be-menge').value);
  fd.append('bruttobetrag', document.getElementById('be-bruttobetrag').value);
  fd.append('mwst_satz', document.getElementById('be-mwst-satz').value);
  const res = await fetch('auslaendische_vorsteuer.php', { method:'POST', body:fd });
  const daten = await res.json();
  if (daten.error) { alert(daten.error); return; }
  window.location.reload();
}
</script>
</div>
</body>
</html>