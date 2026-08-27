<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/theme.css">
<style>
  .container { max-width:900px; margin:0 auto; padding:28px 20px 60px; }

  .jahr-block { margin-bottom:8px; }
  .jahr-header {
    display:flex; align-items:center; gap:10px; padding:14px 4px; cursor:pointer;
    font-size:18px; font-weight:700; color:var(--text-bright); user-select:none;
  }
  .jahr-header .chevron { font-size:13px; color:var(--text-dim); transition:transform 0.15s; }
  .jahr-header.open .chevron { transform:rotate(90deg); }
  .jahr-count { font-size:12px; color:var(--text-dim); font-weight:500; }
  .jahr-body { display:none; padding-left:6px; }
  .jahr-body.open { display:block; }

  .monat-block { margin-bottom:6px; }
  .monat-header {
    display:flex; align-items:center; gap:8px; padding:10px 8px; cursor:pointer;
    font-size:14px; font-weight:600; color:var(--text); user-select:none;
    border-radius:10px;
  }
  .monat-header:hover { background:var(--card2); }
  .monat-header .chevron { font-size:11px; color:var(--text-dim); transition:transform 0.15s; }
  .monat-header.open .chevron { transform:rotate(90deg); }
  .monat-count { font-size:11px; color:var(--text-dim); font-weight:500; }
  .monat-body { display:none; padding:6px 0 6px 20px; }
  .monat-body.open { display:block; }

  .doc-card {
    background:var(--card); border:1px solid var(--line); border-radius:14px;
    padding:14px 16px; margin-bottom:8px; display:flex; align-items:center; gap:14px;
  }
  .doc-icon { font-size:18px; flex-shrink:0; }
  .doc-info { flex:1; min-width:0; }
  .doc-betreff { font-size:13.5px; font-weight:600; margin-bottom:3px; }
  .doc-meta { font-size:11px; color:var(--text-dim); display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .verfasser-badge {
    display:inline-block; padding:2px 8px; border-radius:9px; font-size:10px;
    font-weight:600; background:var(--accent-soft); color:var(--accent);
  }
  .signatur-badge {
    display:inline-block; padding:2px 8px; border-radius:9px; font-size:10px;
    font-weight:600; background:var(--green-soft); color:var(--green);
  }
  .doc-actions { display:flex; gap:8px; flex-shrink:0; flex-wrap:wrap; justify-content:flex-end; }
  .doc-btn {
    text-decoration:none; font-size:11.5px; font-weight:600; padding:7px 13px; border-radius:9px;
  }
  .doc-btn.ansehen { background:var(--card2); color:var(--text); }
  .doc-btn.ansehen:hover { background:var(--line); }
  .doc-btn.download { background:var(--accent); color:#fff; }
  .doc-btn.download:hover { opacity:0.9; }
  .empty { text-align:center; padding:60px 20px; color:var(--text-dim); font-size:14px; }

  @media(max-width:600px) {
    .container { padding:20px 14px; }
    h1, .page-title { font-size:20px; }

    .jahr-header { font-size:16px; padding:12px 2px; }
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
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="container">
  <div class="page-title">Alle erstellten Dokumente</div>
  <div class="page-sub">Nach Jahr und Monat sortiert</div>

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