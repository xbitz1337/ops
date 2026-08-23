<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/berechtigungen_helper.php';
require_once __DIR__ . '/../admin_helper.php';
$user = currentUser();

if ($user['username'] !== 'qaf') {
    http_response_code(403);
    die('<!DOCTYPE html><html><body style="font-family:-apple-system,sans-serif;background:#14161F;color:#E4E6F0;padding:60px 20px;text-align:center;"><h2>🔒 Kein Zugriff</h2><a href="../dashboard.php" style="color:#9494FF;">← Zurück zum Dashboard</a></body></html>');
}

$pdo = db();

$MODULE = [
    'lager' => 'Lager', 'bestellungen' => 'Bestellungen', 'aufgaben' => 'Aufgaben',
    'dokumente' => 'Dokumente', 'kalkulator' => 'Kalkulator', 'umsatz' => 'Umsatz',
    'export' => 'Export', 'produkttexte' => 'Produkttexte', 'antworten' => 'Antworten',
    'steuer' => 'Steuerrücklage', 'nachricht' => 'Nachricht senden', 'clean' => 'NA Clean Service',
];
$EINSCHRAENKUNGEN = ['preise_verbergen' => 'Preise/Beträge verbergen'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'benutzer_anlegen') {
        $username = strtolower(trim($_POST['username'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $pin = trim($_POST['pin'] ?? '');
        if (!$username || !$name || !$pin) { echo json_encode(['error' => 'Alle Felder ausfüllen']); exit; }
        if (strlen($pin) < 4) { echo json_encode(['error' => 'PIN muss mindestens 4 Zeichen haben']); exit; }

        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetchColumn()) { echo json_encode(['error' => 'Username existiert schon']); exit; }

        $pdo->prepare("INSERT INTO users (username, name, pin) VALUES (?, ?, ?)")
            ->execute([$username, $name, password_hash($pin, PASSWORD_DEFAULT)]);
        aktivitaet_loggen('admin', 'benutzer_anlegen', "Neuer Benutzer angelegt: {$name} (@{$username})");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'rechte_speichern') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $modul = $_POST['modul'] ?? '';
        $zugriff = $_POST['zugriff'] ?? 'kein';
        $einschraenkungen = $_POST['einschraenkungen'] ?? [];
        if (!$user_id || !isset($MODULE[$modul])) { echo json_encode(['error' => 'Ungültig']); exit; }
        if (!in_array($zugriff, ['kein', 'ansehen', 'bearbeiten'])) $zugriff = 'kein';

        $pdo->prepare("
            INSERT INTO benutzer_berechtigungen (user_id, modul, zugriff, einschraenkungen)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE zugriff = VALUES(zugriff), einschraenkungen = VALUES(einschraenkungen)
        ")->execute([$user_id, $modul, $zugriff, json_encode(array_values($einschraenkungen))]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'benutzer_loeschen') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $username_geloescht = $stmt->fetchColumn();
        if ($username_geloescht === 'qaf') { echo json_encode(['error' => 'Der Systemverwalter kann nicht gelöscht werden']); exit; }
        $pdo->prepare("DELETE FROM benutzer_berechtigungen WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
        aktivitaet_loggen('admin', 'benutzer_loeschen', "Benutzer gelöscht: @{$username_geloescht}");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'einstellung_speichern') {
        $schluessel = trim($_POST['schluessel'] ?? '');
        $wert = trim($_POST['wert'] ?? '');
        if (!$schluessel) { echo json_encode(['error' => 'Ungültig']); exit; }
        einstellung_setzen($schluessel, $wert);
        aktivitaet_loggen('admin', 'einstellung_geaendert', "Einstellung '{$schluessel}' geändert auf: {$wert}");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'papierkorb_wiederherstellen') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM papierkorb WHERE id = ?");
        $stmt->execute([$id]);
        $eintrag = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$eintrag) { echo json_encode(['error' => 'Eintrag nicht gefunden']); exit; }

        $daten = json_decode($eintrag['daten_json'], true);
        $spalten = array_keys($daten);
        $platzhalter = array_fill(0, count($spalten), '?');
        $sql = "INSERT INTO `{$eintrag['original_tabelle']}` (`" . implode('`,`', $spalten) . "`) VALUES (" . implode(',', $platzhalter) . ")";

        try {
            $pdo->prepare($sql)->execute(array_values($daten));
            $pdo->prepare("DELETE FROM papierkorb WHERE id = ?")->execute([$id]);
            aktivitaet_loggen('admin', 'papierkorb_wiederhergestellt', "Wiederhergestellt: {$eintrag['beschreibung']} ({$eintrag['modul']})");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Wiederherstellen fehlgeschlagen: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'papierkorb_endgueltig_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT beschreibung, modul FROM papierkorb WHERE id = ?");
        $stmt->execute([$id]);
        $eintrag = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("DELETE FROM papierkorb WHERE id = ?")->execute([$id]);
        if ($eintrag) {
            aktivitaet_loggen('admin', 'papierkorb_endgueltig_geloescht', "Endgültig gelöscht: {$eintrag['beschreibung']} ({$eintrag['modul']})");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

$alle_user = $pdo->query("SELECT id, username, name FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$alle_rechte = $pdo->query("SELECT * FROM benutzer_berechtigungen")->fetchAll(PDO::FETCH_ASSOC);
$rechte_je_user = [];
foreach ($alle_rechte as $r) {
    $rechte_je_user[$r['user_id']][$r['modul']] = [
        'zugriff' => $r['zugriff'],
        'einschraenkungen' => json_decode($r['einschraenkungen'] ?? '[]', true) ?: [],
    ];
}

$EINSTELLUNGEN_LABELS = [
    'steuerruecklage_satz' => ['label' => 'Steuerrücklage-Satz', 'einheit' => '%'],
    'kilometerpauschale' => ['label' => 'Kilometerpauschale', 'einheit' => '€/km'],
    'clean_mindestmarge' => ['label' => 'Mindestmarge NA Clean Service', 'einheit' => '%'],
];
$alle_einstellungen = $pdo->query("SELECT * FROM einstellungen ORDER BY schluessel")->fetchAll(PDO::FETCH_ASSOC);
$papierkorb_eintraege = $pdo->query("SELECT * FROM papierkorb ORDER BY geloescht_am DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$aktivitaeten = $pdo->query("SELECT * FROM aktivitaetslog ORDER BY zeitpunkt DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --red: #F87171; --orange: #FBBF24;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); z-index:100; }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }

  .main { max-width:1100px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:22px; font-weight:700; color:#F5F6FA; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-bottom:24px; }

  .tab-nav { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
  .tab-btn { font-family:var(--mono); font-size:10px; padding:9px 16px; cursor:pointer; border:1px solid rgba(124,124,255,0.25); background:none; color:var(--text-dim); }
  .tab-btn.active { background:rgba(148,148,255,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }
  .tab-content { display:none; }
  .tab-content.active { display:block; }

  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; margin-bottom:20px; }
  .panel-title { font-family:var(--mono); font-size:11px; color:var(--blue-bright); margin-bottom:16px; }

  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:6px; display:block; }
  .field-input { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:10px 12px; outline:none; margin-bottom:12px; }
  .row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }
  .btn { font-family:var(--mono); font-size:10px; padding:10px 16px; cursor:pointer; border:1px solid; background:none; }
  .btn-primary { color:var(--blue-bright); border-color:rgba(148,148,255,0.4); }
  .btn-primary:hover { background:rgba(148,148,255,0.1); }
  .btn-danger { color:var(--red); border-color:rgba(248,113,113,0.3); font-size:9px; padding:5px 10px; }

  .user-block { border:1px solid rgba(124,124,255,0.2); margin-bottom:14px; }
  .user-header { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; background:rgba(20,22,31,0.6); cursor:pointer; }
  .user-name { font-weight:700; font-size:14px; }
  .user-username { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-left:8px; }
  .admin-badge { font-family:var(--mono); font-size:9px; background:rgba(74,222,128,0.15); color:var(--green); padding:2px 8px; border-radius:8px; margin-left:8px; }
  .user-body { display:none; padding:16px; }
  .user-body.open { display:block; }

  table { width:100%; border-collapse:collapse; font-size:12px; }
  th { text-align:left; padding:8px; font-family:var(--mono); font-size:9px; color:var(--text-dim); border-bottom:1px solid rgba(124,124,255,0.15); }
  td { padding:8px; border-bottom:1px solid rgba(124,124,255,0.08); }
  select.mini-select { background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-size:11px; padding:5px 8px; }
  .check-label { font-family:var(--mono); font-size:9px; display:flex; align-items:center; gap:5px; cursor:pointer; }
  .speichern-btn { font-family:var(--mono); font-size:9px; color:var(--blue-bright); background:none; border:1px solid var(--blue-bright); padding:4px 10px; cursor:pointer; }

  .einstellung-zeile { display:flex; justify-content:space-between; align-items:center; padding:12px 0; border-bottom:1px solid rgba(124,124,255,0.1); gap:12px; }
  .einstellung-zeile .info { flex:1; }
  .einstellung-zeile .schluessel-name { font-weight:600; font-size:13px; }
  .einstellung-zeile .beschreibung { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-top:2px; }
  .einstellung-zeile input { width:100px; text-align:right; }

  .papierkorb-zeile, .log-zeile { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid rgba(124,124,255,0.08); gap:10px; }
  .papierkorb-zeile .info, .log-zeile .info { flex:1; }
  .papierkorb-titel { font-weight:600; font-size:13px; }
  .papierkorb-meta, .log-meta { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-top:2px; }
  .modul-badge { font-family:var(--mono); font-size:8px; background:rgba(148,148,255,0.15); color:var(--blue-bright); padding:2px 7px; border-radius:6px; margin-right:6px; }
  .log-aktion { font-family:var(--mono); font-size:8px; background:rgba(251,191,36,0.15); color:var(--orange); padding:2px 7px; border-radius:6px; margin-right:6px; }
  .empty { text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); padding:24px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Admin</div>
  <div class="page-sub">Nur für dich sichtbar (Systemverwalter)</div>

  <div class="tab-nav">
    <button class="tab-btn active" onclick="tabWechseln('benutzer', this)">👤 Benutzer &amp; Rechte</button>
    <button class="tab-btn" onclick="tabWechseln('einstellungen', this)">⚙️ Einstellungen</button>
    <button class="tab-btn" onclick="tabWechseln('papierkorb', this)">🗑 Papierkorb <?= count($papierkorb_eintraege) ? '(' . count($papierkorb_eintraege) . ')' : '' ?></button>
    <button class="tab-btn" onclick="tabWechseln('log', this)">📜 Aktivitätslog</button>
  </div>

  <div class="tab-content active" id="tab-benutzer">
    <div class="panel">
      <div class="panel-title">Neuen Benutzer anlegen</div>
      <div class="row-3">
        <div><label class="field-label">Username</label><input class="field-input" id="neu-username" type="text" placeholder="z.B. mfriedrich"></div>
        <div><label class="field-label">Name</label><input class="field-input" id="neu-name" type="text" placeholder="Max Friedrich"></div>
        <div><label class="field-label">PIN</label><input class="field-input" id="neu-pin" type="text" placeholder="min. 4 Zeichen"></div>
      </div>
      <button class="btn btn-primary" onclick="benutzerAnlegen()">+ Benutzer anlegen</button>
    </div>

    <div class="panel">
      <div class="panel-title">Bestehende Benutzer</div>
      <?php foreach ($alle_user as $u): $ist_admin = $u['username'] === 'qaf'; ?>
      <div class="user-block">
        <div class="user-header" onclick="toggleUser(<?= $u['id'] ?>)">
          <div>
            <span class="user-name"><?= htmlspecialchars($u['name']) ?></span>
            <span class="user-username">@<?= htmlspecialchars($u['username']) ?></span>
            <?php if ($ist_admin): ?><span class="admin-badge">✓ SYSTEMVERWALTER — VOLLER ZUGRIFF</span><?php endif; ?>
          </div>
          <?php if (!$ist_admin): ?>
            <button class="btn btn-danger" onclick="event.stopPropagation(); benutzerLoeschen(<?= $u['id'] ?>, '<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>')">✕ Löschen</button>
          <?php endif; ?>
        </div>
        <?php if (!$ist_admin): ?>
        <div class="user-body" id="user-body-<?= $u['id'] ?>">
          <table>
            <thead><tr><th>Modul</th><th>Zugriff</th><th>Einschränkungen</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($MODULE as $modul_key => $modul_label):
                  $aktuell = $rechte_je_user[$u['id']][$modul_key] ?? ['zugriff' => 'kein', 'einschraenkungen' => []];
              ?>
              <tr>
                <td><?= $modul_label ?></td>
                <td>
                  <select class="mini-select" id="zugriff-<?= $u['id'] ?>-<?= $modul_key ?>">
                    <option value="kein" <?= $aktuell['zugriff'] === 'kein' ? 'selected' : '' ?>>Kein Zugriff</option>
                    <option value="ansehen" <?= $aktuell['zugriff'] === 'ansehen' ? 'selected' : '' ?>>Nur ansehen</option>
                    <option value="bearbeiten" <?= $aktuell['zugriff'] === 'bearbeiten' ? 'selected' : '' ?>>Bearbeiten</option>
                  </select>
                </td>
                <td>
                  <?php foreach ($EINSCHRAENKUNGEN as $e_key => $e_label): ?>
                    <label class="check-label">
                      <input type="checkbox" id="einschr-<?= $u['id'] ?>-<?= $modul_key ?>-<?= $e_key ?>" value="<?= $e_key ?>" <?= in_array($e_key, $aktuell['einschraenkungen']) ? 'checked' : '' ?>>
                      <?= $e_label ?>
                    </label>
                  <?php endforeach; ?>
                </td>
                <td><button class="speichern-btn" onclick="rechteSpeichern(<?= $u['id'] ?>, '<?= $modul_key ?>')">Speichern</button></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="tab-content" id="tab-einstellungen">
    <div class="panel">
      <div class="panel-title">Globale Einstellungen</div>
      <?php foreach ($alle_einstellungen as $e):
          $meta = $EINSTELLUNGEN_LABELS[$e['schluessel']] ?? ['label' => $e['schluessel'], 'einheit' => ''];
      ?>
      <div class="einstellung-zeile">
        <div class="info">
          <div class="schluessel-name"><?= htmlspecialchars($meta['label']) ?></div>
          <div class="beschreibung"><?= htmlspecialchars($e['beschreibung']) ?></div>
        </div>
        <input type="text" class="field-input" id="einst-<?= htmlspecialchars($e['schluessel']) ?>" value="<?= htmlspecialchars($e['wert']) ?>" style="margin-bottom:0;">
        <span style="font-family:var(--mono); font-size:10px; color:var(--text-dim); min-width:50px;"><?= htmlspecialchars($meta['einheit']) ?></span>
        <button class="speichern-btn" onclick="einstellungSpeichern('<?= htmlspecialchars($e['schluessel']) ?>')">Speichern</button>
      </div>
      <?php endforeach; ?>
      <div style="font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-top:14px;">
        ⚠️ Hinweis: Diese Werte werden nur dort angewendet, wo die jeweilige Seite bereits darauf umgestellt wurde. Falls eine Änderung hier keine Wirkung zeigt, sag Bescheid — dann verdrahte ich die entsprechende Stelle nach.
      </div>
    </div>
  </div>

  <div class="tab-content" id="tab-papierkorb">
    <div class="panel">
      <div class="panel-title">Papierkorb (wiederherstellbar)</div>
      <?php if (empty($papierkorb_eintraege)): ?>
        <div class="empty">Leer</div>
      <?php else: ?>
        <?php foreach ($papierkorb_eintraege as $p): ?>
        <div class="papierkorb-zeile" id="pk-<?= $p['id'] ?>">
          <div class="info">
            <span class="modul-badge"><?= htmlspecialchars($p['modul']) ?></span>
            <span class="papierkorb-titel"><?= htmlspecialchars($p['beschreibung']) ?></span>
            <div class="papierkorb-meta">Gelöscht von <?= $p['geloescht_von'] === 'qaf' ? 'Artis' : ($p['geloescht_von'] === 'qns' ? 'Nour' : htmlspecialchars($p['geloescht_von'])) ?> am <?= date('d.m.Y, H:i', strtotime($p['geloescht_am'])) ?></div>
          </div>
          <div style="display:flex; gap:8px;">
            <button class="speichern-btn" onclick="papierkorbWiederherstellen(<?= $p['id'] ?>)">↩ Wiederherstellen</button>
            <button class="btn btn-danger" onclick="papierkorbEndgueltigLoeschen(<?= $p['id'] ?>)">✕ Endgültig löschen</button>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="tab-content" id="tab-log">
    <div class="panel">
      <div class="panel-title">Aktivitätslog (letzte 100)</div>
      <?php if (empty($aktivitaeten)): ?>
        <div class="empty">Noch keine Einträge</div>
      <?php else: ?>
        <?php foreach ($aktivitaeten as $a): ?>
        <div class="log-zeile">
          <div class="info">
            <span class="modul-badge"><?= htmlspecialchars($a['modul']) ?></span>
            <span class="log-aktion"><?= htmlspecialchars($a['aktion']) ?></span>
            <div class="log-meta"><?= htmlspecialchars($a['beschreibung']) ?></div>
          </div>
          <div class="log-meta" style="white-space:nowrap;">
            <?= $a['benutzer'] === 'qaf' ? 'Artis' : ($a['benutzer'] === 'qns' ? 'Nour' : htmlspecialchars($a['benutzer'])) ?> · <?= date('d.m.Y, H:i', strtotime($a['zeitpunkt'])) ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function tabWechseln(tab, btn) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  btn.classList.add('active');
}

async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('benutzerverwaltung.php', { method:'POST', body:fd });
  return res.json();
}

function toggleUser(id) {
  document.getElementById('user-body-' + id)?.classList.toggle('open');
}

async function benutzerAnlegen() {
  const username = document.getElementById('neu-username').value.trim();
  const name = document.getElementById('neu-name').value.trim();
  const pin = document.getElementById('neu-pin').value.trim();
  const res = await api({ action: 'benutzer_anlegen', username, name, pin });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

async function benutzerLoeschen(id, name) {
  if (!confirm(`"${name}" wirklich löschen? Alle Rechte gehen dabei mit verloren.`)) return;
  const res = await api({ action: 'benutzer_loeschen', user_id: id });
  if (res.error) { alert(res.error); return; }
  window.location.reload();
}

const EINSCHRAENKUNGS_KEYS = <?= json_encode(array_keys($EINSCHRAENKUNGEN)) ?>;

async function rechteSpeichern(userId, modul) {
  const zugriff = document.getElementById(`zugriff-${userId}-${modul}`).value;
  const einschraenkungen = EINSCHRAENKUNGS_KEYS.filter(key => {
    const cb = document.getElementById(`einschr-${userId}-${modul}-${key}`);
    return cb && cb.checked;
  });
  const fd = new FormData();
  fd.append('api', 1);
  fd.append('action', 'rechte_speichern');
  fd.append('user_id', userId);
  fd.append('modul', modul);
  fd.append('zugriff', zugriff);
  einschraenkungen.forEach(e => fd.append('einschraenkungen[]', e));
  const res = await fetch('benutzerverwaltung.php', { method:'POST', body:fd });
  const daten = await res.json();
  if (daten.error) { alert(daten.error); return; }
  alert('✓ Gespeichert');
}

async function einstellungSpeichern(schluessel) {
  const wert = document.getElementById('einst-' + schluessel).value.trim();
  const res = await api({ action: 'einstellung_speichern', schluessel, wert });
  if (res.error) { alert(res.error); return; }
  alert('✓ Gespeichert');
}

async function papierkorbWiederherstellen(id) {
  const res = await api({ action: 'papierkorb_wiederherstellen', id });
  if (res.error) { alert(res.error); return; }
  document.getElementById('pk-' + id)?.remove();
}

async function papierkorbEndgueltigLoeschen(id) {
  if (!confirm('Wirklich ENDGÜLTIG löschen? Das kann nicht mehr rückgängig gemacht werden.')) return;
  const res = await api({ action: 'papierkorb_endgueltig_loeschen', id });
  if (res.error) { alert(res.error); return; }
  document.getElementById('pk-' + id)?.remove();
}
</script>
</div>
</body>
</html>
