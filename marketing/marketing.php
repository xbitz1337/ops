<?php
require_once __DIR__ . '/../config.php';
requireLogin();
$user = currentUser();

// ── API HANDLER (AJAX Requests) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'kanal_add') {
        $name = trim($_POST['name'] ?? '');
        if (!$name) { echo json_encode(['error' => 'Name fehlt']); exit; }
        $stmt = db()->prepare('INSERT INTO marketing_channels (name, ziel_umsatz, ziel_bestellungen, notizen) VALUES (?,?,?,?)');
        $stmt->execute([
            $name,
            (float)($_POST['ziel_umsatz'] ?? 0),
            (int)($_POST['ziel_bestellungen'] ?? 0),
            trim($_POST['notizen'] ?? ''),
        ]);
        echo json_encode(['success' => true, 'id' => db()->lastInsertId()]);
        exit;
    }

    if ($action === 'kanal_delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE marketing_channels SET aktiv = 0 WHERE id = ?')->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'fortschritt_update') {
        $channelId = (int)($_POST['channel_id'] ?? 0);
        $monat = date('Y-m-01');
        if (!$channelId) { echo json_encode(['error' => 'Kanal fehlt']); exit; }
        $stmt = db()->prepare(
            'INSERT INTO marketing_progress (channel_id, monat, ist_umsatz, ist_bestellungen)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE ist_umsatz = VALUES(ist_umsatz), ist_bestellungen = VALUES(ist_bestellungen)'
        );
        $stmt->execute([
            $channelId, $monat,
            (float)($_POST['ist_umsatz'] ?? 0),
            (int)($_POST['ist_bestellungen'] ?? 0),
        ]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'content_add') {
        $titel = trim($_POST['titel'] ?? '');
        if (!$titel) { echo json_encode(['error' => 'Titel fehlt']); exit; }
        $stmt = db()->prepare(
            'INSERT INTO marketing_content (channel_id, titel, typ, status, geplant_am, notizen, zustaendig)
             VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (int)($_POST['channel_id'] ?? 0),
            $titel,
            $_POST['typ'] ?? 'Video',
            'Idee',
            !empty($_POST['geplant_am']) ? $_POST['geplant_am'] : null,
            trim($_POST['notizen'] ?? ''),
            $_POST['zustaendig'] ?? 'beide',
        ]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'content_status') {
        db()->prepare('UPDATE marketing_content SET status = ? WHERE id = ?')
            ->execute([$_POST['status'] ?? 'Idee', (int)($_POST['id'] ?? 0)]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'content_delete') {
        db()->prepare('DELETE FROM marketing_content WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

// ── DATEN LADEN ──────────────────────────────────────────────────────────────
$monat = date('Y-m-01');

$channels = db()->query("
    SELECT c.*,
           COALESCE(p.ist_umsatz, 0)       AS ist_umsatz,
           COALESCE(p.ist_bestellungen, 0) AS ist_bestellungen
    FROM marketing_channels c
    LEFT JOIN marketing_progress p ON p.channel_id = c.id AND p.monat = '$monat'
    WHERE c.aktiv = 1
    ORDER BY c.id ASC
")->fetchAll();

$content = db()->query("
    SELECT mc.*, ch.name AS channel_name
    FROM marketing_content mc
    JOIN marketing_channels ch ON ch.id = mc.channel_id
    ORDER BY FIELD(mc.status,'In Arbeit','Geplant','Idee','Veröffentlicht'), mc.geplant_am IS NULL, mc.geplant_am ASC
")->fetchAll();

$statuses = ['Idee', 'In Arbeit', 'Geplant', 'Veröffentlicht'];
$content_offen = count(array_filter($content, fn($c) => $c['status'] !== 'Veröffentlicht'));
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NA Ops Hub — Marketing/Strategie</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy:        #14161F;
    --navy2:       #1D2030;
    --blue-mid:    #262A3D;
    --blue-accent: #7C7CFF;
    --blue-bright: #9494FF;
    --text:        #E4E6F0;
    --text-dim:    #8B8FA8;
    --green:       #4ADE80;
    --orange:      #FBBF24;
    --red:         #F87171;
    --terracotta:  #D9A066;
    --mono:        'Inter', -apple-system, sans-serif;
    --sans:        'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; overflow-x:hidden; }
.topbar { position:fixed; top:0; left:0; right:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); z-index:100; }
  .topbar-left { display:flex; align-items:center; gap:14px; }
  .logo-mark { width:32px; height:32px; border-radius:10px; background:rgba(124,124,255,0.15); display:flex; align-items:center; justify-content:center; }
  .logo-mark span { font-size:12px; font-weight:700; color:var(--blue-bright); }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }
  .topbar-right { display:flex; align-items:center; gap:16px; font-family:var(--mono); font-size:10px; color:var(--text-dim); }
  .status-dot { display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--green); margin-right:5px; animation:pulse 2s infinite; }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.3} }
  .user-badge { background:rgba(124,124,255,0.15); border:1px solid rgba(124,124,255,0.3); padding:4px 10px; color:var(--blue-bright); }
  .logout-btn { background:none; border:none; font-family:var(--mono); font-size:10px; color:var(--text-dim); cursor:pointer; transition:color 0.2s; }
  .logout-btn:hover { color:var(--red); }

  .sidebar { position:fixed; top:48px; left:0; bottom:0; width:200px; background:rgba(20,22,31,0.92); border-right:1px solid rgba(124,124,255,0.15); z-index:50; padding:20px 0; display:flex; flex-direction:column; gap:2px; }
  .nav-section { font-family:var(--mono); font-size:9px; color:var(--text-dim); padding:12px 18px 6px; }
  .nav-item { display:flex; align-items:center; gap:10px; padding:10px 18px; font-family:var(--mono); font-size:11px; color:var(--text-dim); cursor:pointer; transition:all 0.15s; border-left:2px solid transparent; }
  .nav-item:hover { color:var(--text); background:rgba(124,124,255,0.08); }
  .nav-item.active { color:var(--blue-bright); background:rgba(148,148,255,0.08); border-left-color:var(--blue-bright); }
  .nav-icon { font-size:13px; width:16px; text-align:center; }
  .nav-badge { margin-left:auto; background:var(--blue-accent); color:#fff; font-size:9px; padding:1px 6px; border-radius:2px; }
  .nav-spacer { flex:1; }

  .main { margin-left:200px; margin-top:48px; padding:24px; position:relative; z-index:2; }

  .page-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
  .page-title { font-size:24px; font-weight:700; color:#F5F6FA; }
  .page-sub { font-family:var(--mono); font-size:12px; color:var(--text-dim); margin-top:4px; }
  .page-actions { display:flex; gap:8px; flex-wrap:wrap; }

  .grid-cards { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:14px; margin-bottom:16px; }

  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); position:relative; margin-bottom:16px; border-radius:20px; }
.panel-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid rgba(124,124,255,0.15); flex-wrap:wrap; gap:8px; }
  .panel-title { font-family:var(--mono); font-size:13px; color:var(--blue-bright); display:flex; align-items:center; gap:8px; }
  .panel-body { padding:16px 18px; }

  .btn { font-family:var(--mono); font-size:12px; padding:9px 16px; cursor:pointer; border:1px solid; transition:all 0.2s; background:none; border-radius:12px; }
  .btn-primary { color:var(--blue-bright); border-color:rgba(148,148,255,0.4); }
  .btn-primary:hover { background:rgba(148,148,255,0.1); border-color:var(--blue-bright); }
  .btn-danger { color:var(--red); border-color:rgba(248,113,113,0.3); }
  .btn-danger:hover { background:rgba(248,113,113,0.1); }
  .btn-sm { padding:6px 11px; font-size:11px; }

  .kanal-card { background:rgba(20,22,31,0.6); border:1px solid rgba(124,124,255,0.2); padding:16px 18px; }
  .kanal-name { font-size:16px; font-weight:600; color:var(--text); margin-bottom:12px; }
  .kanal-row { margin:12px 0; }
  .kanal-row .label { display:flex; justify-content:space-between; font-family:var(--mono); font-size:11px; color:var(--text-dim); margin-bottom:6px; }
  .kanal-row .label span:last-child { color:var(--blue-bright); }
  .bar-bg { height:7px; background:rgba(124,124,255,0.15); overflow:hidden; }
  .bar-fill { height:100%; background:var(--blue-accent); }
  .bar-fill.ok { background:var(--green); }
  .bar-fill.warn { background:var(--orange); }
  .kanal-notes { font-family:var(--mono); font-size:11px; color:var(--text-dim); margin-top:10px; padding-top:8px; border-top:1px dashed rgba(124,124,255,0.2); line-height:1.6; }
  .kanal-actions { display:flex; gap:6px; margin-top:12px; }

  .kanban { display:grid; grid-template-columns:repeat(4, 1fr); gap:14px; }
  .kanban-col { background:rgba(20,22,31,0.6); border:1px solid rgba(124,124,255,0.2); padding:12px; min-height:100px; }
  .kanban-col h4 { font-family:var(--mono); font-size:11px; color:var(--text-dim); margin:0 0 10px; padding-bottom:6px; border-bottom:1px dashed rgba(124,124,255,0.2); }
  .content-item { background:rgba(29,32,48,0.9); border:1px solid rgba(124,124,255,0.15); padding:12px; margin-bottom:8px; }
  .content-item .c-titel { font-size:14px; font-weight:600; color:var(--text); }
  .content-item .c-meta { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-top:8px; display:flex; justify-content:space-between; align-items:center; }
  .tag { display:inline-block; padding:2px 8px; background:rgba(124,124,255,0.15); border:1px solid rgba(124,124,255,0.25); color:var(--blue-bright); font-family:var(--mono); font-size:10px; }
  select.status-select { background:none; border:1px solid rgba(124,124,255,0.2); color:var(--text-dim); font-family:var(--mono); font-size:11px; padding:4px 6px; cursor:pointer; width:100%; margin-top:8px; }
  select.status-select option { background:var(--navy2); }
  @media(max-width:900px) { .kanban { grid-template-columns:1fr 1fr; } }

  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:200; align-items:center; justify-content:center; }
  .modal-overlay.open { display:flex; }
  .modal { background:var(--navy2); border:1px solid rgba(124,124,255,0.3); width:100%; max-width:480px; margin:20px; position:relative; max-height:90vh; overflow-y:auto; border-radius:20px; }
.modal-header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid rgba(124,124,255,0.15); position:sticky; top:0; background:var(--navy2); z-index:2; }
  .modal-title { font-family:var(--mono); font-size:13px; color:var(--blue-bright); }
  .modal-close { background:none; border:none; color:var(--text-dim); font-size:18px; cursor:pointer; transition:color 0.2s; }
  .modal-close:hover { color:var(--red); }
  .modal-body { padding:20px; display:flex; flex-direction:column; gap:14px; }
  .modal-footer { padding:16px 20px; border-top:1px solid rgba(124,124,255,0.15); display:flex; justify-content:flex-end; gap:10px; }

  .field-label { font-family:var(--mono); font-size:11px; color:var(--text-dim); margin-bottom:7px; display:flex; align-items:center; gap:6px; }
  .field-input, .field-select, .field-textarea {
    width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25);
    color:var(--text); font-family:var(--mono); font-size:14px; padding:10px 12px; outline:none;
    transition:border-color 0.2s; border-radius:12px; }
  .field-input:focus, .field-select:focus, .field-textarea:focus { border-color:var(--blue-bright); }
  .field-select option { background:var(--navy2); }
  .field-textarea { resize:vertical; min-height:70px; border-radius:12px; }

  .toast { position:fixed; bottom:24px; right:24px; background:var(--navy2); border:1px solid rgba(124,124,255,0.3); border-left:3px solid var(--green); padding:12px 20px; font-family:var(--mono); font-size:13px; z-index:300; opacity:0; transform:translateY(10px); transition:all 0.3s; pointer-events:none; }
  .toast.show { opacity:1; transform:translateY(0); }
  .toast.error { border-left-color:var(--red); }

  .empty { font-family:var(--mono); font-size:13px; color:var(--text-dim); text-align:center; padding:30px; }

  .mobile-nav {
    display:none; position:fixed; bottom:0; left:0; right:0; height:60px;
    background:rgba(20,22,31,0.98); border-top:1px solid rgba(124,124,255,0.25);
    z-index:150; padding-bottom: env(safe-area-inset-bottom, 0px);
  }
  .mobile-nav-inner { display:flex; height:60px; }
  .mobile-nav-item {
    flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:3px; color:var(--text-dim); font-family:var(--mono); font-size:8px;
    cursor:pointer; position:relative;
    text-decoration:none; border:none; background:none;
  }
  .mobile-nav-item .mn-icon { font-size:18px; line-height:1; }
  .mobile-nav-item.active { color:var(--blue-bright); }
  .mobile-nav-item .mn-badge { position:absolute; top:4px; right:22%; background:var(--red); color:#fff; font-size:8px; padding:1px 4px; border-radius:6px; font-family:var(--mono); }

  @media(max-width:768px) {
    .sidebar { display:none; }
    .mobile-nav { display:block; }
    .main { margin-left:0; padding:14px; padding-bottom:80px; }
    .topbar { padding:0 12px; }
    .topbar-right .user-badge { display:none; }
    .topbar-title { font-size:12px; }
    .logo-mark { width:26px; height:26px; }
    .kanban { grid-template-columns:1fr; }
    .page-header { flex-direction:column; align-items:stretch; }
    .page-actions .btn { flex:1; text-align:center; border-radius:12px; }
    .modal { margin:12px; max-height:85vh; border-radius:20px; }
  }
</style>
</head>
<body>
<div class="scanlines"></div>

<!-- TOPBAR -->
<div class="topbar">
  <div class="topbar-left">
    <div class="logo-mark"><span>NA</span></div>
    <div class="topbar-title">NA Ops Hub</div>
  </div>
  <div class="topbar-right">
    <span><span class="status-dot"></span>ONLINE</span>
    <span id="clock">--:--:--</span>
    <div class="user-badge"><?= strtoupper($user['username']) ?> // <?= strtoupper($user['name']) ?></div>
    <form method="post" action="auth.php" style="display:inline;">
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="logout-btn">[ LOGOUT ]</button>
    </form>
  </div>
</div>

<!-- SIDEBAR -->
<div class="sidebar">
  <div class="nav-section">Navigation</div>
  <div class="nav-item" onclick="window.location.href='../dashboard.php'"><span class="nav-icon">▦</span> Dashboard</div>
  <div class="nav-item" onclick="window.location.href='../dashboard.php#lager'"><span class="nav-icon">◫</span> Lager</div>
  <div class="nav-item" onclick="window.location.href='../dashboard.php#todos'"><span class="nav-icon">◈</span> Aufgaben</div>
  <div class="nav-item active"><span class="nav-icon">📈</span> Marketing <?php if ($content_offen > 0): ?><span class="nav-badge"><?= $content_offen ?></span><?php endif; ?></div>
  <div class="nav-spacer"></div>
  <div class="nav-item" onclick="window.location.href='../documents/documents.php'"><span class="nav-icon">📄</span> Dokumente</div>
  <div class="nav-section">System</div>
  <div class="nav-item" style="color:var(--text-dim);font-size:9px;">v1.0.0 — MARKETING-MODUL</div>
</div>

<!-- MOBILE BOTTOM NAV -->
<div class="mobile-nav">
  <div class="mobile-nav-inner">
    <button class="mobile-nav-item" onclick="window.location.href='../dashboard.php'">
      <span class="mn-icon">▦</span><span>Dashboard</span>
    </button>
    <button class="mobile-nav-item" onclick="window.location.href='../dashboard.php#lager'">
      <span class="mn-icon">◫</span><span>Lager</span>
    </button>
    <button class="mobile-nav-item active">
      <span class="mn-icon">📈</span><span>Marketing</span>
      <?php if ($content_offen > 0): ?><span class="mn-badge"><?= $content_offen ?></span><?php endif; ?>
    </button>
    <button class="mobile-nav-item" onclick="window.location.href='../documents/documents.php'">
      <span class="mn-icon">📄</span><span>Dokumente</span>
    </button>
  </div>
</div>

<!-- MAIN -->
<div class="main">

  <div class="page-header">
    <div>
      <div class="page-title">Marketing / Strategie</div>
      <div class="page-sub">Kanäle, Ziele &amp; Content-Kalender — <?= date('F Y') ?></div>
    </div>
    <div class="page-actions">
      <button class="btn btn-primary" onclick="openModal('modal-kanal-add')">+ KANAL</button>
      <button class="btn btn-primary" onclick="openModal('modal-content-add')">+ CONTENT</button>
    </div>
  </div>

  <!-- ── KANÄLE & ZIELE ── -->
  <div class="panel">
    <div class="panel-header"><div class="panel-title">Kanäle &amp; Ziele</div></div>
    <div class="panel-body">
      <?php if (empty($channels)): ?>
        <div class="empty">Keine Kanäle — Kanal hinzufügen</div>
      <?php else: ?>
        <div class="grid-cards">
          <?php foreach ($channels as $c):
              $pctUmsatz = $c['ziel_umsatz'] > 0 ? min(100, round($c['ist_umsatz'] / $c['ziel_umsatz'] * 100)) : 0;
              $pctBest   = $c['ziel_bestellungen'] > 0 ? min(100, round($c['ist_bestellungen'] / $c['ziel_bestellungen'] * 100)) : 0;
              $cls = fn($p) => $p >= 100 ? 'ok' : ($p >= 50 ? 'warn' : '');
          ?>
          <div class="kanal-card" id="kanal-<?= $c['id'] ?>">
            <div class="kanal-name"><?= htmlspecialchars($c['name']) ?></div>

            <div class="kanal-row">
              <div class="label"><span>Umsatz</span><span>€<?= number_format($c['ist_umsatz'],0,',','.') ?> / €<?= number_format($c['ziel_umsatz'],0,',','.') ?></span></div>
              <div class="bar-bg"><div class="bar-fill <?= $cls($pctUmsatz) ?>" style="width:<?= $pctUmsatz ?>%"></div></div>
            </div>

            <div class="kanal-row">
              <div class="label"><span>Bestellungen</span><span><?= $c['ist_bestellungen'] ?> / <?= $c['ziel_bestellungen'] ?></span></div>
              <div class="bar-bg"><div class="bar-fill <?= $cls($pctBest) ?>" style="width:<?= $pctBest ?>%"></div></div>
            </div>

            <?php if (!empty($c['notizen'])): ?>
              <div class="kanal-notes">💬 <?= htmlspecialchars($c['notizen']) ?></div>
            <?php endif; ?>

            <div class="kanal-actions">
              <button class="btn btn-primary btn-sm" onclick="openFortschritt(<?= $c['id'] ?>, <?= $c['ist_umsatz'] ?>, <?= $c['ist_bestellungen'] ?>, '<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>')">± FORTSCHRITT</button>
              <button class="btn btn-danger btn-sm" onclick="deleteKanal(<?= $c['id'] ?>)">✕</button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── CONTENT-KALENDER ── -->
  <div class="panel">
    <div class="panel-header"><div class="panel-title">Content-Kalender</div></div>
    <div class="panel-body">
      <div class="kanban">
        <?php foreach ($statuses as $status): ?>
          <div class="kanban-col">
            <h4><?= $status ?></h4>
            <?php foreach ($content as $item): if ($item['status'] !== $status) continue; ?>
              <div class="content-item" id="content-<?= $item['id'] ?>">
                <div class="c-titel"><?= htmlspecialchars($item['titel']) ?></div>
                <div class="c-meta">
                  <span class="tag"><?= htmlspecialchars($item['channel_name']) ?></span>
                  <span><?= $item['geplant_am'] ? date('d.m.', strtotime($item['geplant_am'])) : '' ?></span>
                  <button class="btn btn-danger btn-sm" style="padding:2px 6px;" onclick="deleteContent(<?= $item['id'] ?>)">✕</button>
                </div>
                <select class="status-select" onchange="updateContentStatus(<?= $item['id'] ?>, this.value)">
                  <?php foreach ($statuses as $s): ?>
                    <option value="<?= $s ?>" <?= $s === $item['status'] ? 'selected' : '' ?>><?= strtoupper($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div><!-- /main -->

<!-- ── MODALS ── -->

<!-- Kanal hinzufügen -->
<div class="modal-overlay" id="modal-kanal-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Kanal hinzufügen</div>
      <button class="modal-close" onclick="closeModal('modal-kanal-add')">✕</button>
    </div>
    <div class="modal-body">
      <div><div class="field-label">Kanal-Name</div><input class="field-input" id="k-name" type="text" placeholder="z.B. Instagram Ads"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div><div class="field-label">Umsatzziel (€/Monat)</div><input class="field-input" id="k-umsatz" type="number" step="0.01" value="0"></div>
        <div><div class="field-label">Bestellziel (/Monat)</div><input class="field-input" id="k-bestellungen" type="number" value="0"></div>
      </div>
      <div><div class="field-label">Notizen / Strategie</div><textarea class="field-textarea" id="k-notizen" placeholder="Strategie für diesen Kanal..."></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);" onclick="closeModal('modal-kanal-add')">ABBRECHEN</button>
      <button class="btn btn-primary" onclick="addKanal()">SPEICHERN</button>
    </div>
  </div>
</div>

<!-- Fortschritt eintragen -->
<div class="modal-overlay" id="modal-fortschritt">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="fortschritt-title">Fortschritt</div>
      <button class="modal-close" onclick="closeModal('modal-fortschritt')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="f-channel-id">
      <div><div class="field-label">Ist-Umsatz diesen Monat (€)</div><input class="field-input" id="f-umsatz" type="number" step="0.01"></div>
      <div><div class="field-label">Ist-Bestellungen diesen Monat</div><input class="field-input" id="f-bestellungen" type="number"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);" onclick="closeModal('modal-fortschritt')">ABBRECHEN</button>
      <button class="btn btn-primary" onclick="saveFortschritt()">SPEICHERN</button>
    </div>
  </div>
</div>

<!-- Content hinzufügen -->
<div class="modal-overlay" id="modal-content-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Content hinzufügen</div>
      <button class="modal-close" onclick="closeModal('modal-content-add')">✕</button>
    </div>
    <div class="modal-body">
      <div><div class="field-label">Titel</div><input class="field-input" id="c-titel" type="text" placeholder="z.B. Cozy Room Setup Reel"></div>
      <div><div class="field-label">Kanal</div>
        <select class="field-select" id="c-channel">
          <?php foreach ($channels as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div><div class="field-label">Typ</div>
          <select class="field-select" id="c-typ">
            <option>Video</option><option>Post</option><option>Ad</option><option>Story</option><option>Sonstiges</option>
          </select>
        </div>
        <div><div class="field-label">Zuständig</div>
          <select class="field-select" id="c-zustaendig">
            <option value="beide" selected>Beide</option>
            <option value="artis">Artis</option>
            <option value="nour">Nour</option>
          </select>
        </div>
      </div>
      <div><div class="field-label">Geplant für</div><input class="field-input" id="c-datum" type="date"></div>
      <div><div class="field-label">Notiz / Hook-Idee</div><textarea class="field-textarea" id="c-notizen" placeholder="z.B. 'POV: Dir ist ständig kalt im Bett'"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);" onclick="closeModal('modal-content-add')">ABBRECHEN</button>
      <button class="btn btn-primary" onclick="addContent()">SPEICHERN</button>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<script>
function showToast(msg, error=false) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast' + (error ? ' error' : '') + ' show';
  setTimeout(() => t.classList.remove('show'), 3000);
}

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if(e.target === o) o.classList.remove('open'); });
});

async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('marketing.php', { method:'POST', body:fd });
  return res.json();
}

function updateClock() {
  const now = new Date();
  document.getElementById('clock').textContent = now.toTimeString().slice(0,8);
}
updateClock(); setInterval(updateClock, 1000);

async function addKanal() {
  const name = document.getElementById('k-name').value.trim();
  if (!name) { showToast('Name fehlt', true); return; }
  const res = await api({
    action: 'kanal_add', name,
    ziel_umsatz: document.getElementById('k-umsatz').value,
    ziel_bestellungen: document.getElementById('k-bestellungen').value,
    notizen: document.getElementById('k-notizen').value,
  });
  if (res.error) { showToast(res.error, true); return; }
  showToast('Kanal hinzugefügt');
  closeModal('modal-kanal-add');
  setTimeout(() => location.reload(), 800);
}

async function deleteKanal(id) {
  if (!confirm('Kanal wirklich entfernen?')) return;
  const res = await api({ action:'kanal_delete', id });
  if (res.error) { showToast(res.error, true); return; }
  document.getElementById('kanal-'+id)?.remove();
  showToast('Kanal entfernt');
}

function openFortschritt(channelId, umsatz, bestellungen, name) {
  document.getElementById('f-channel-id').value = channelId;
  document.getElementById('f-umsatz').value = umsatz;
  document.getElementById('f-bestellungen').value = bestellungen;
  document.getElementById('fortschritt-title').textContent = 'Fortschritt — '+name;
  openModal('modal-fortschritt');
}

async function saveFortschritt() {
  const res = await api({
    action: 'fortschritt_update',
    channel_id: document.getElementById('f-channel-id').value,
    ist_umsatz: document.getElementById('f-umsatz').value,
    ist_bestellungen: document.getElementById('f-bestellungen').value,
  });
  if (res.error) { showToast(res.error, true); return; }
  showToast('Fortschritt gespeichert');
  closeModal('modal-fortschritt');
  setTimeout(() => location.reload(), 800);
}

async function addContent() {
  const titel = document.getElementById('c-titel').value.trim();
  if (!titel) { showToast('Titel fehlt', true); return; }
  const res = await api({
    action: 'content_add', titel,
    channel_id: document.getElementById('c-channel').value,
    typ: document.getElementById('c-typ').value,
    zustaendig: document.getElementById('c-zustaendig').value,
    geplant_am: document.getElementById('c-datum').value,
    notizen: document.getElementById('c-notizen').value,
  });
  if (res.error) { showToast(res.error, true); return; }
  showToast('Content hinzugefügt');
  closeModal('modal-content-add');
  setTimeout(() => location.reload(), 800);
}

async function updateContentStatus(id, status) {
  const res = await api({ action:'content_status', id, status });
  if (res.error) { showToast('Fehler', true); return; }
  showToast('Status aktualisiert');
  setTimeout(() => location.reload(), 600);
}

async function deleteContent(id) {
  if (!confirm('Eintrag wirklich löschen?')) return;
  await api({ action:'content_delete', id });
  document.getElementById('content-'+id)?.remove();
  showToast('Eintrag gelöscht');
}
</script>
</body>
</html>