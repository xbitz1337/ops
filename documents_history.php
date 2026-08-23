<?php
date_default_timezone_set('Europe/Berlin');
require_once 'config.php';
requireLogin();
require_once __DIR__ . '/berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('dokumente');
$user = currentUser();
$pdo = db();

$dokumente = $pdo->query("SELECT * FROM dokumente ORDER BY erstellt_am DESC")->fetchAll(PDO::FETCH_ASSOC);

$verfasser_label = ['artis' => 'Artis', 'nour' => 'Nour', 'beide' => 'Beide'];
$monatsnamen = [1=>'Januar',2=>'Februar',3=>'März',4=>'April',5=>'Mai',6=>'Juni',7=>'Juli',8=>'August',9=>'September',10=>'Oktober',11=>'November',12=>'Dezember'];

// Nach Jahr → Monat gruppieren, basierend auf erstellt_am (entspricht der
// Ordnerstruktur documents/JAHR/MONAT/, in der die PDFs tatsächlich liegen)
$gruppiert = [];
foreach ($dokumente as $d) {
    $jahr = date('Y', strtotime($d['erstellt_am']));
    $monat = (int)date('n', strtotime($d['erstellt_am']));
    $gruppiert[$jahr][$monat][] = $d;
}
krsort($gruppiert); // neueste Jahre zuerst
foreach ($gruppiert as $jahr => &$monate) {
    krsort($monate); // neueste Monate zuerst
}
unset($monate);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dokumenten-Historie — NA Ops Hub</title>
<style>
  :root {
    --ink: #1D1D1F; --ink-dim: #86868B; --ink-mid: #4B4B4D;
    --line: #E5E5E7; --bg: #F5F5F7; --accent: #0071E3;
    --font: -apple-system, 'Helvetica Neue', Helvetica, Arial, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:var(--font); background:var(--bg); color:var(--ink); min-height:100vh; }

  .topbar {
    height:56px; display:flex; align-items:center; justify-content:space-between;
    padding:0 28px; background:rgba(255,255,255,0.9); backdrop-filter:blur(12px);
    border-bottom:1px solid var(--line); position:sticky; top:0; z-index:10;
  }
  .back-link { font-size:13px; color:var(--accent); text-decoration:none; }
  .back-link:hover { text-decoration:underline; }
  .topbar-title { font-size:15px; font-weight:600; letter-spacing:-0.2px; }

  .container { max-width:900px; margin:0 auto; padding:36px 24px; }
  h1 { font-size:24px; font-weight:700; letter-spacing:-0.3px; margin-bottom:6px; }
  .subtitle { font-size:13px; color:var(--ink-dim); margin-bottom:28px; }

  .jahr-block { margin-bottom:8px; }
  .jahr-header {
    display:flex; align-items:center; gap:10px; padding:14px 4px; cursor:pointer;
    font-size:19px; font-weight:700; letter-spacing:-0.3px; user-select:none;
  }
  .jahr-header .chevron { font-size:13px; color:var(--ink-dim); transition:transform 0.15s; }
  .jahr-header.open .chevron { transform:rotate(90deg); }
  .jahr-count { font-size:12px; color:var(--ink-dim); font-weight:500; }
  .jahr-body { display:none; padding-left:6px; }
  .jahr-body.open { display:block; }

  .monat-block { margin-bottom:6px; }
  .monat-header {
    display:flex; align-items:center; gap:8px; padding:10px 8px; cursor:pointer;
    font-size:14px; font-weight:600; color:var(--ink-mid); user-select:none;
    border-radius:8px;
  }
  .monat-header:hover { background:#EFEFF1; }
  .monat-header .chevron { font-size:11px; color:var(--ink-dim); transition:transform 0.15s; }
  .monat-header.open .chevron { transform:rotate(90deg); }
  .monat-count { font-size:11px; color:var(--ink-dim); font-weight:500; }
  .monat-body { display:none; padding:6px 0 6px 20px; }
  .monat-body.open { display:block; }

  .doc-card {
    background:#fff; border:1px solid var(--line); border-radius:10px;
    padding:14px 16px; margin-bottom:8px; display:flex; align-items:center; gap:14px;
  }
  .doc-icon { font-size:18px; flex-shrink:0; }
  .doc-info { flex:1; min-width:0; }
  .doc-betreff { font-size:13.5px; font-weight:600; margin-bottom:3px; }
  .doc-meta { font-size:11px; color:var(--ink-dim); display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .verfasser-badge {
    display:inline-block; padding:2px 7px; border-radius:9px; font-size:9.5px;
    font-weight:600; background:#F0F0F2; color:var(--ink-mid);
  }
  .signatur-badge {
    display:inline-block; padding:2px 7px; border-radius:9px; font-size:9.5px;
    font-weight:600; background:#E8F5E9; color:#1a7f37;
  }
  .doc-actions { display:flex; gap:8px; flex-shrink:0; flex-wrap:wrap; justify-content:flex-end; }
  .doc-btn {
    text-decoration:none; font-size:11.5px; font-weight:600; padding:7px 13px; border-radius:7px;
  }
  .doc-btn.ansehen { background:#F0F0F2; color:var(--ink); }
  .doc-btn.ansehen:hover { background:#E5E5E7; }
  .doc-btn.download { background:var(--accent); color:#fff; }
  .doc-btn.download:hover { background:#0077ED; }
  .empty { text-align:center; padding:60px 20px; color:var(--ink-dim); font-size:14px; }

  @media(max-width:600px) {
    .topbar { padding:0 14px; flex-wrap:wrap; height:auto; gap:6px; padding-top:10px; padding-bottom:10px; }
    .topbar-title { order:-1; width:100%; text-align:center; margin-bottom:4px; }
    .container { padding:20px 14px; }
    h1 { font-size:20px; }

    .jahr-header { font-size:17px; padding:12px 2px; }
    .monat-header { padding:9px 6px; font-size:13px; }

    .doc-card { flex-direction:column; align-items:stretch; gap:10px; padding:14px; }
    .doc-icon { display:none; }
    .doc-meta { gap:6px; }
    .doc-actions { width:100%; justify-content:stretch; }
    .doc-btn { flex:1; text-align:center; padding:9px 10px; }
  }
</style>
</head>
<body>

<div class="topbar">
  <a href="documents.php" class="back-link">← Neues Dokument</a>
  <div class="topbar-title">Dokumenten-Historie</div>
  <a href="dashboard.php" class="back-link">Dashboard →</a>
</div>

<div class="container">
  <h1>Alle erstellten Dokumente</h1>
  <div class="subtitle">Nach Jahr und Monat sortiert</div>

  <?php if (empty($gruppiert)): ?>
    <div class="empty">Noch keine Dokumente erstellt.</div>
  <?php else: ?>
    <?php $jahr_index = 0; foreach ($gruppiert as $jahr => $monate): $jahr_index++; $jahr_offen = $jahr_index === 1; ?>
      <?php $jahr_gesamt = array_sum(array_map('count', $monate)); ?>
      <div class="jahr-block">
        <div class="jahr-header <?= $jahr_offen ? 'open' : '' ?>" onclick="toggleJahr(this)">
          <span class="chevron">▸</span>
          <span><?= $jahr ?></span>
          <span class="jahr-count">(<?= $jahr_gesamt ?> Dokument<?= $jahr_gesamt !== 1 ? 'e' : '' ?>)</span>
        </div>
        <div class="jahr-body <?= $jahr_offen ? 'open' : '' ?>">
          <?php $monat_index = 0; foreach ($monate as $monat_nr => $docs): $monat_index++; $monat_offen = $jahr_offen && $monat_index === 1; ?>
            <div class="monat-block">
              <div class="monat-header <?= $monat_offen ? 'open' : '' ?>" onclick="toggleMonat(this)">
                <span class="chevron">▸</span>
                <span><?= $monatsnamen[$monat_nr] ?></span>
                <span class="monat-count">(<?= count($docs) ?>)</span>
              </div>
              <div class="monat-body <?= $monat_offen ? 'open' : '' ?>">
                <?php foreach ($docs as $d): ?>
                  <div class="doc-card">
                    <div class="doc-icon">📄</div>
                    <div class="doc-info">
                      <div class="doc-betreff"><?= htmlspecialchars($d['betreff'] ?: 'Formloser Brief') ?></div>
                      <div class="doc-meta">
                        <span class="verfasser-badge"><?= $verfasser_label[$d['verfasser']] ?? $d['verfasser'] ?></span>
                        <?php if ($d['hat_unterschrift']): ?><span class="signatur-badge">✓ Signiert</span><?php endif; ?>
                        <span><?= $d['ort'] ? htmlspecialchars($d['ort']) . ', ' : '' ?><?= $d['dokument_datum'] ? date('d.m.Y', strtotime($d['dokument_datum'])) : '' ?></span>
                        <span><?= date('d.m.Y, H:i', strtotime($d['erstellt_am'])) ?></span>
                      </div>
                    </div>
                    <div class="doc-actions">
                      <a class="doc-btn ansehen" href="documents.php?edit_id=<?= $d['id'] ?>">Bearbeiten</a>
                      <a class="doc-btn ansehen" href="document_view.php?id=<?= $d['id'] ?>" target="_blank">Ansehen</a>
                      <a class="doc-btn download" href="document_download.php?id=<?= $d['id'] ?>">Download</a>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
function toggleJahr(el) {
  el.classList.toggle('open');
  el.nextElementSibling.classList.toggle('open');
}
function toggleMonat(el) {
  el.classList.toggle('open');
  el.nextElementSibling.classList.toggle('open');
}
</script>

</body>
</html>