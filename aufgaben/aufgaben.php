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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/theme.css">
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Aufgaben</div>
  <div class="page-sub">Kanban · wiederkehrend · tägliche Telegram-Erinnerung um 6 Uhr</div>

  <button class="btn-primary auto" onclick="openModal()">+ Aufgabe erstellen</button>

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
          <div class="empty-spalte">Leer</div>
        <?php endif; ?>
        <?php foreach ($spalten[$key] as $a): ?>
        <div class="aufgaben-karte" id="aufgabe-<?= $a['id'] ?>">
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
              <button class="mini-btn gruen" onclick="statusAendern(<?= $a['id'] ?>, 'erledigt')">✓ Erledigt</button>
            <?php endif; ?>
            <button class="mini-btn rot" onclick="aufgabeLoeschen(<?= $a['id'] ?>)">✕</button>
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
    <div class="modal-titel">Aufgabe erstellen</div>

    <label>Titel</label>
    <input type="text" id="a-titel">

    <label>Beschreibung (optional)</label>
    <textarea id="a-beschreibung"></textarea>

    <div class="row-2">
      <div>
        <label>Priorität</label>
        <select id="a-prioritaet">
          <option value="hoch">🔴 Hoch</option>
          <option value="mittel" selected>🟠 Mittel</option>
          <option value="niedrig">🟢 Niedrig</option>
        </select>
      </div>
      <div>
        <label>Zuweisen an</label>
        <select id="a-assignee">
          <option value="artis">Artis</option>
          <option value="nour">Nour</option>
          <option value="beide" selected>Beide</option>
        </select>
      </div>
    </div>

    <label>Fällig am</label>
    <input type="date" id="a-deadline">

    <label>Wiederholung</label>
    <select id="a-wiederholung" onchange="wiederholungGeaendert()">
      <option value="keine">Keine (einmalig)</option>
      <option value="taeglich">Täglich</option>
      <option value="woechentlich">Wöchentlich</option>
      <option value="monatlich">Monatlich (fester Tag)</option>
      <option value="jaehrlich">Jährlich</option>
    </select>

    <div class="wiederholung-detail" id="detail-monatlich">
      <label>Tag im Monat (1-31, bei kürzeren Monaten automatisch letzter Tag)</label>
      <input type="number" id="a-monatstag" min="1" max="31" value="1">
    </div>

    <div class="wiederholung-detail" id="detail-jaehrlich">
      <label>Datum (Monat-Tag)</label>
      <input type="text" id="a-jahrestag" placeholder="MM-TT, z.B. 12-30">
    </div>

    <div class="modal-aktionen">
      <button class="btn-secondary" onclick="closeModal()">Abbrechen</button>
      <button class="btn-primary auto" onclick="aufgabeErstellen()">Speichern</button>
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
