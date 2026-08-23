<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('aufgaben');
$pdo = db();
$user = currentUser();

/**
 * Berechnet das nächste Fälligkeitsdatum für eine wiederkehrende Aufgabe.
 * Bei "monatlich" mit Tag > tatsächliche Monatslänge (z.B. 31. im Februar)
 * wird automatisch auf den letzten Tag des Monats gefallen.
 */
function naechste_faelligkeit(string $typ, ?string $aktuelle_faelligkeit, ?int $wochentag, ?int $monatstag, ?string $jahrestag): ?string
{
    $basis = $aktuelle_faelligkeit ? new DateTime($aktuelle_faelligkeit) : new DateTime();

    switch ($typ) {
        case 'taeglich':
            $basis->modify('+1 day');
            return $basis->format('Y-m-d');

        case 'woechentlich':
            $basis->modify('+7 days');
            return $basis->format('Y-m-d');

        case 'monatlich':
            $tag = $monatstag ?: (int)$basis->format('d');
            $naechster_monat = (int)$basis->format('n') + 1;
            $jahr = (int)$basis->format('Y');
            if ($naechster_monat > 12) { $naechster_monat = 1; $jahr++; }
            $letzter_tag_im_monat = (int)date('t', mktime(0, 0, 0, $naechster_monat, 1, $jahr));
            $tatsaechlicher_tag = min($tag, $letzter_tag_im_monat); // Februar-Fall: auf letzten Tag fallen
            return sprintf('%04d-%02d-%02d', $jahr, $naechster_monat, $tatsaechlicher_tag);

        case 'jaehrlich':
            $basis->modify('+1 year');
            if ($jahrestag) {
                [$mm, $dd] = explode('-', $jahrestag);
                $jahr = (int)$basis->format('Y');
                $letzter_tag = (int)date('t', mktime(0, 0, 0, (int)$mm, 1, $jahr));
                $dd = min((int)$dd, $letzter_tag);
                return sprintf('%04d-%02d-%02d', $jahr, (int)$mm, $dd);
            }
            return $basis->format('Y-m-d');

        default:
            return null;
    }
}

// ── API HANDLER ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'aufgabe_add') {
        $titel = trim($_POST['titel'] ?? '');
        if (!$titel) { echo json_encode(['error' => 'Titel fehlt']); exit; }

        $pdo->prepare("
            INSERT INTO aufgaben
                (titel, beschreibung, prioritaet, assignee, deadline, wiederholung_typ,
                 wiederholung_wochentag, wiederholung_tag, wiederholung_monat_tag, erstellt_von)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $titel,
            trim($_POST['beschreibung'] ?? ''),
            $_POST['prioritaet'] ?? 'mittel',
            $_POST['assignee'] ?? 'beide',
            $_POST['deadline'] ?: null,
            $_POST['wiederholung_typ'] ?? 'keine',
            $_POST['wiederholung_wochentag'] !== '' ? (int)$_POST['wiederholung_wochentag'] : null,
            $_POST['wiederholung_tag'] !== '' ? (int)$_POST['wiederholung_tag'] : null,
            $_POST['wiederholung_monat_tag'] ?: null,
            $user['id'],
        ]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'status_aendern') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'offen';

        $stmt = $pdo->prepare("SELECT * FROM aufgaben WHERE id = ?");
        $stmt->execute([$id]);
        $aufgabe = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$aufgabe) { echo json_encode(['error' => 'Aufgabe nicht gefunden']); exit; }

        $erledigt_am = $status === 'erledigt' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE aufgaben SET status = ?, erledigt_am = ? WHERE id = ?")->execute([$status, $erledigt_am, $id]);

        // Bei Erledigung einer wiederkehrenden Aufgabe automatisch die
        // nächste Fälligkeit anlegen
        $neue_id = null;
        if ($status === 'erledigt' && $aufgabe['wiederholung_typ'] !== 'keine') {
            $naechstes_datum = naechste_faelligkeit(
                $aufgabe['wiederholung_typ'], $aufgabe['deadline'],
                $aufgabe['wiederholung_wochentag'], $aufgabe['wiederholung_tag'], $aufgabe['wiederholung_monat_tag']
            );
            $pdo->prepare("
                INSERT INTO aufgaben
                    (titel, beschreibung, prioritaet, assignee, deadline, wiederholung_typ,
                     wiederholung_wochentag, wiederholung_tag, wiederholung_monat_tag, quelle_modul, erstellt_von)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $aufgabe['titel'], $aufgabe['beschreibung'], $aufgabe['prioritaet'], $aufgabe['assignee'],
                $naechstes_datum, $aufgabe['wiederholung_typ'], $aufgabe['wiederholung_wochentag'],
                $aufgabe['wiederholung_tag'], $aufgabe['wiederholung_monat_tag'],
                $aufgabe['quelle_modul'], $aufgabe['erstellt_von'],
            ]);
            $neue_id = $pdo->lastInsertId();
        }

        echo json_encode(['success' => true, 'neue_wiederholung_id' => $neue_id]);
        exit;
    }

    if ($action === 'aufgabe_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM aufgaben WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

// ── DATEN LADEN ──────────────────────────────────────────────────────────────
$aufgaben = $pdo->query("
    SELECT a.*, u.name AS ersteller
    FROM aufgaben a
    LEFT JOIN users u ON a.erstellt_von = u.id
    ORDER BY FIELD(prioritaet,'hoch','mittel','niedrig'), deadline ASC
")->fetchAll(PDO::FETCH_ASSOC);

$spalten = ['offen' => [], 'in_bearbeitung' => [], 'erledigt' => []];
foreach ($aufgaben as $a) { $spalten[$a['status']][] = $a; }

$heute = new DateTime();
foreach ($aufgaben as &$a) {
    $a['tage_bis_faellig'] = null;
    if ($a['deadline'] && $a['status'] !== 'erledigt') {
        $diff = $heute->diff(new DateTime($a['deadline']));
        $a['tage_bis_faellig'] = $diff->invert ? -$diff->days : $diff->days;
    }
}
unset($a);
// Nach der Berechnung erneut in Spalten einsortieren (Referenzen beibehalten)
$spalten = ['offen' => [], 'in_bearbeitung' => [], 'erledigt' => []];
foreach ($aufgaben as $a) { $spalten[$a['status']][] = $a; }

$wochentag_labels = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Aufgaben — NA Ops Hub</title>
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
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(15,25,35,0.97); border-bottom:1px solid rgba(45,106,173,0.2); z-index:100; }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; letter-spacing:1px; }
  .topbar-title { font-size:14px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; }

  .main { max-width:1200px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:22px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); letter-spacing:1px; margin-bottom:20px; }

  .btn { font-family:var(--mono); font-size:10px; letter-spacing:2px; text-transform:uppercase; padding:9px 16px; cursor:pointer; border:1px solid; transition:all 0.2s; background:none; }
  .btn-primary { color:var(--blue-bright); border-color:rgba(74,158,221,0.4); }
  .btn-primary:hover { background:rgba(74,158,221,0.1); }

  .kanban { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-top:20px; }
  @media(max-width:900px) { .kanban { grid-template-columns:1fr; } }

  .spalte { background:rgba(21,34,54,0.6); border:1px solid rgba(45,106,173,0.2); min-height:200px; }
  .spalte-header { padding:14px 16px; border-bottom:1px solid rgba(45,106,173,0.2); font-family:var(--mono); font-size:12px; letter-spacing:2px; text-transform:uppercase; display:flex; justify-content:space-between; align-items:center; }
  .spalte.offen .spalte-header { color:var(--orange); }
  .spalte.in_bearbeitung .spalte-header { color:var(--blue-bright); }
  .spalte.erledigt .spalte-header { color:var(--green); }
  .spalte-count { background:rgba(74,158,221,0.15); color:var(--blue-bright); padding:2px 9px; border-radius:10px; font-size:11px; }
  .spalte-body { padding:12px; display:flex; flex-direction:column; gap:10px; }

  .karte { background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.2); padding:14px 16px; position:relative; }
  .karte-prio { position:absolute; top:0; left:0; width:3px; height:100%; }
  .karte-prio.hoch { background:var(--red); }
  .karte-prio.mittel { background:var(--orange); }
  .karte-prio.niedrig { background:var(--green); }
  .karte-titel { font-weight:700; font-size:14px; margin-bottom:6px; padding-left:8px; }
  .karte-beschreibung { font-size:12px; color:var(--text-dim); margin-bottom:8px; padding-left:8px; }
  .karte-meta { display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding-left:8px; margin-bottom:10px; font-family:var(--mono); font-size:9px; }
  .meta-badge { padding:2px 8px; border-radius:8px; background:rgba(45,106,173,0.15); color:var(--blue-bright); }
  .meta-badge.deadline-warn { background:rgba(231,76,60,0.15); color:var(--red); }
  .meta-badge.wiederholung { background:rgba(46,204,113,0.15); color:var(--green); }
  .meta-badge.quelle { background:rgba(230,126,34,0.15); color:var(--orange); }
  .karte-aktionen { display:flex; gap:6px; flex-wrap:wrap; padding-left:8px; }
  .mini-btn { font-family:var(--mono); font-size:9px; letter-spacing:1px; padding:6px 10px; border:1px solid rgba(45,106,173,0.3); background:none; color:var(--text); cursor:pointer; }
  .mini-btn:hover { background:rgba(74,158,221,0.1); }
  .mini-btn.loeschen { color:var(--red); border-color:rgba(231,76,60,0.3); }
  .empty-spalte { font-family:var(--mono); font-size:10px; color:var(--text-dim); text-align:center; padding:20px 10px; }

  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:200; align-items:center; justify-content:center; }
  .modal-overlay.open { display:flex; }
  .modal { background:var(--navy2); border:1px solid rgba(45,106,173,0.3); width:100%; max-width:480px; margin:20px; max-height:90vh; overflow-y:auto; padding:20px; }
  .modal-titel { font-family:var(--mono); font-size:11px; letter-spacing:2px; color:var(--blue-bright); margin-bottom:16px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); letter-spacing:1px; text-transform:uppercase; margin-bottom:6px; display:block; }
  .field-input, .field-select, .field-textarea { width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--sans); font-size:13px; padding:10px 12px; outline:none; margin-bottom:14px; }
  .field-textarea { min-height:70px; resize:vertical; }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .wiederholung-detail { display:none; border-left:2px solid rgba(45,106,173,0.2); padding-left:12px; margin-bottom:14px; }
  .wiederholung-detail.aktiv { display:block; }
  .wochentag-row { display:flex; gap:4px; margin-bottom:14px; }
  .wochentag-btn { flex:1; padding:8px 0; border:1px solid rgba(45,106,173,0.25); background:none; color:var(--text-dim); font-family:var(--mono); font-size:10px; cursor:pointer; }
  .wochentag-btn.active { background:rgba(74,158,221,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }
  .modal-aktionen { display:flex; gap:10px; justify-content:flex-end; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Aufgaben</div>
  <div class="page-sub">// KANBAN · WIEDERKEHREND · TÄGLICHE TELEGRAM-ERINNERUNG UM 6 UHR</div>

  <button class="btn btn-primary" onclick="openModal()">+ AUFGABE ERSTELLEN</button>

  <div class="kanban">
    <?php
    $spalten_labels = ['offen' => 'Offen', 'in_bearbeitung' => 'In Bearbeitung', 'erledigt' => 'Erledigt'];
    foreach ($spalten_labels as $key => $label):
    ?>
    <div class="spalte <?= $key ?>">
      <div class="spalte-header">
        <span><?= $label ?></span>
        <span class="spalte-count"><?= count($spalten[$key]) ?></span>
      </div>
      <div class="spalte-body">
        <?php if (empty($spalten[$key])): ?>
          <div class="empty-spalte">// LEER</div>
        <?php endif; ?>
        <?php foreach ($spalten[$key] as $a): ?>
        <div class="karte" id="aufgabe-<?= $a['id'] ?>">
          <div class="karte-prio <?= $a['prioritaet'] ?>"></div>
          <div class="karte-titel"><?= htmlspecialchars($a['titel']) ?></div>
          <?php if ($a['beschreibung']): ?><div class="karte-beschreibung"><?= htmlspecialchars($a['beschreibung']) ?></div><?php endif; ?>
          <div class="karte-meta">
            <span class="meta-badge"><?= strtoupper($a['assignee']) ?></span>
            <?php if ($a['deadline']): ?>
              <span class="meta-badge <?= ($a['tage_bis_faellig'] !== null && $a['tage_bis_faellig'] <= 1) ? 'deadline-warn' : '' ?>">
                📅 <?= date('d.m.Y', strtotime($a['deadline'])) ?>
              </span>
            <?php endif; ?>
            <?php if ($a['wiederholung_typ'] !== 'keine'): ?>
              <span class="meta-badge wiederholung">🔁 <?= ucfirst($a['wiederholung_typ']) ?></span>
            <?php endif; ?>
            <?php if ($a['quelle_modul']): ?>
              <span class="meta-badge quelle">⚙ <?= htmlspecialchars($a['quelle_modul']) ?></span>
            <?php endif; ?>
          </div>
          <div class="karte-aktionen">
            <?php if ($key === 'offen'): ?>
              <button class="mini-btn" onclick="statusAendern(<?= $a['id'] ?>, 'in_bearbeitung')">→ In Bearbeitung</button>
            <?php elseif ($key === 'in_bearbeitung'): ?>
              <button class="mini-btn" onclick="statusAendern(<?= $a['id'] ?>, 'erledigt')">✓ Erledigt</button>
            <?php endif; ?>
            <button class="mini-btn loeschen" onclick="aufgabeLoeschen(<?= $a['id'] ?>)">✕</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- MODAL: Neue Aufgabe -->
<div class="modal-overlay" id="modal-aufgabe">
  <div class="modal">
    <div class="modal-titel">// AUFGABE ERSTELLEN</div>

    <label class="field-label">Titel</label>
    <input class="field-input" type="text" id="a-titel">

    <label class="field-label">Beschreibung (optional)</label>
    <textarea class="field-textarea" id="a-beschreibung"></textarea>

    <div class="row-2">
      <div>
        <label class="field-label">Priorität</label>
        <select class="field-select" id="a-prioritaet">
          <option value="hoch">🔴 Hoch</option>
          <option value="mittel" selected>🟠 Mittel</option>
          <option value="niedrig">🟢 Niedrig</option>
        </select>
      </div>
      <div>
        <label class="field-label">Zuweisen an</label>
        <select class="field-select" id="a-assignee">
          <option value="artis">Artis</option>
          <option value="nour">Nour</option>
          <option value="beide" selected>Beide</option>
        </select>
      </div>
    </div>

    <label class="field-label">Fällig am</label>
    <input class="field-input" type="date" id="a-deadline">

    <label class="field-label">Wiederholung</label>
    <select class="field-select" id="a-wiederholung" onchange="wiederholungGeaendert()">
      <option value="keine">Keine (einmalig)</option>
      <option value="taeglich">Täglich</option>
      <option value="woechentlich">Wöchentlich</option>
      <option value="monatlich">Monatlich (fester Tag)</option>
      <option value="jaehrlich">Jährlich</option>
    </select>

    <div class="wiederholung-detail" id="detail-monatlich">
      <label class="field-label">Tag im Monat (1-31, bei kürzeren Monaten automatisch letzter Tag)</label>
      <input class="field-input" type="number" id="a-monatstag" min="1" max="31" value="1">
    </div>

    <div class="wiederholung-detail" id="detail-jaehrlich">
      <label class="field-label">Datum (Monat-Tag)</label>
      <input class="field-input" type="text" id="a-jahrestag" placeholder="MM-TT, z.B. 12-30">
    </div>

    <div class="modal-aktionen">
      <button class="btn" style="color:var(--text-dim);border-color:rgba(90,122,154,0.3);" onclick="closeModal()">Abbrechen</button>
      <button class="btn btn-primary" onclick="aufgabeErstellen()">Speichern</button>
    </div>
  </div>
</div>

<script>
async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('aufgaben.php', { method:'POST', body:fd });
  return res.json();
}

function openModal() { document.getElementById('modal-aufgabe').classList.add('open'); }
function closeModal() { document.getElementById('modal-aufgabe').classList.remove('open'); }

function wiederholungGeaendert() {
  const typ = document.getElementById('a-wiederholung').value;
  document.getElementById('detail-monatlich').classList.toggle('aktiv', typ === 'monatlich');
  document.getElementById('detail-jaehrlich').classList.toggle('aktiv', typ === 'jaehrlich');
}

async function aufgabeErstellen() {
  const titel = document.getElementById('a-titel').value.trim();
  if (!titel) { alert('Titel fehlt'); return; }

  const res = await api({
    action: 'aufgabe_add',
    titel,
    beschreibung: document.getElementById('a-beschreibung').value,
    prioritaet: document.getElementById('a-prioritaet').value,
    assignee: document.getElementById('a-assignee').value,
    deadline: document.getElementById('a-deadline').value,
    wiederholung_typ: document.getElementById('a-wiederholung').value,
    wiederholung_tag: document.getElementById('a-monatstag').value,
    wiederholung_monat_tag: document.getElementById('a-jahrestag').value,
  });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function statusAendern(id, status) {
  const res = await api({ action: 'status_aendern', id, status });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function aufgabeLoeschen(id) {
  if (!confirm('Aufgabe wirklich löschen?')) return;
  const res = await api({ action: 'aufgabe_loeschen', id });
  if (res.error) { alert(res.error); return; }
  document.getElementById('aufgabe-' + id)?.remove();
}
</script>
</div>
</body>
</html>
