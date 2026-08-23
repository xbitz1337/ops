<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('bestellungen', 'ansehen');
$pdo = db();

$status_filter = $_GET['status'] ?? '';
$kanal_filter = $_GET['kanal'] ?? '';
$suche = trim($_GET['suche'] ?? '');
$seite = max(1, (int)($_GET['seite'] ?? 1));
$pro_seite = 20;
$offset = ($seite - 1) * $pro_seite;

$where = "WHERE 1=1";
$params = [];
if ($status_filter !== '') { $where .= " AND b.status = :status"; $params[':status'] = $status_filter; }
if ($kanal_filter !== '') { $where .= " AND b.kanal = :kanal"; $params[':kanal'] = $kanal_filter; }
if ($suche !== '') {
    $where .= " AND (b.kaeufer_name LIKE :suche OR b.artikel_name LIKE :suche2 OR b.sendungsnummer LIKE :suche3)";
    $params[':suche'] = '%' . $suche . '%';
    $params[':suche2'] = '%' . $suche . '%';
    $params[':suche3'] = '%' . $suche . '%';
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM bestellungen b $where");
$count_stmt->execute($params);
$gesamt_treffer = (int)$count_stmt->fetchColumn();
$gesamt_seiten = max(1, (int)ceil($gesamt_treffer / $pro_seite));

$sql = "
    SELECT b.*, p.name AS produkt_name
    FROM bestellungen b
    LEFT JOIN lager_produkte p ON p.id = b.produkt_id
    $where
    ORDER BY b.erstellt_am DESC
    LIMIT $pro_seite OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bestellungen = $stmt->fetchAll(PDO::FETCH_ASSOC);

$status_labels = [
    'neu' => 'Neu', 'zu_verpacken' => 'Zu verpacken',
    'versand_vorbereitet' => 'Versand vorbereitet', 'versendet' => 'Versendet',
];
$kanal_labels = ['ebay' => 'eBay', 'amazon' => 'Amazon', 'tiktok' => 'TikTok Shop', 'direktverkauf' => 'Direktverkauf'];

function baue_url($status, $kanal, $suche, $seite) {
    $params = array_filter(['status' => $status, 'kanal' => $kanal, 'suche' => $suche, 'seite' => $seite > 1 ? $seite : null]);
    return 'bestellungen_historie.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bestellungen-Historie — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-mid: #262A3D;
    --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8;
    --green: #4ADE80; --orange: #FBBF24; --red: #F87171; --purple:#B8A6FF;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }

  .main { max-width:1100px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:22px; font-weight:700; color:#F5F6FA; margin-bottom:6px; }
  .treffer-info { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-bottom:16px; }

  .filter-block { margin-bottom:20px; }
  .filter-row { display:flex; gap:8px; margin-bottom:8px; flex-wrap:wrap; align-items:center; }
  .filter-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); width:70px; }
  .filter-chip { font-family:var(--mono); font-size:10px; padding:7px 12px; border:1px solid rgba(124,124,255,0.3); color:var(--text-dim); text-decoration:none; }
  .filter-chip.active { background:rgba(148,148,255,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }
  .filter-chip:hover { border-color:var(--blue-bright); }
  .suche-form { display:flex; gap:8px; flex:1; min-width:200px; margin-top:8px; }
  .suche-input { flex:1; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--mono); font-size:12px; padding:8px 12px; outline:none; }

  table { width:100%; border-collapse:collapse; background:rgba(29,32,48,0.6); border:1px solid rgba(124,124,255,0.2); }
  th { background:rgba(20,22,31,0.8); text-align:left; padding:10px 12px; font-family:var(--mono); font-size:9px; color:var(--text-dim); border-bottom:1px solid rgba(124,124,255,0.2); }
  td { padding:10px 12px; font-size:12px; border-bottom:1px solid rgba(124,124,255,0.1); }
  tr:last-child td { border-bottom:none; }
  .status-badge { font-family:var(--mono); font-size:9px; padding:2px 8px; border:1px solid; }
  .status-badge.neu { color:var(--orange); border-color:rgba(251,191,36,0.3); }
  .status-badge.zu_verpacken { color:var(--blue-bright); border-color:rgba(148,148,255,0.3); }
  .status-badge.versand_vorbereitet { color:var(--purple); border-color:rgba(184,166,255,0.3); }
  .status-badge.versendet { color:var(--green); border-color:rgba(74,222,128,0.3); }
  .kanal-badge { font-family:var(--mono); font-size:9px; padding:2px 7px; border-radius:8px; background:rgba(148,148,255,0.12); color:var(--blue-bright); }
  .test-tag { font-family:var(--mono); font-size:8px; color:var(--orange); margin-left:6px; }
  .empty { text-align:center; padding:40px; font-family:var(--mono); font-size:11px; color:var(--text-dim); }

  .pagination { display:flex; justify-content:center; gap:6px; margin-top:20px; flex-wrap:wrap; }
  .page-link { font-family:var(--mono); font-size:11px; padding:8px 12px; border:1px solid rgba(124,124,255,0.3); color:var(--text-dim); text-decoration:none; }
  .page-link.active { background:rgba(148,148,255,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }
  .page-link:hover { border-color:var(--blue-bright); }

  @media(max-width:700px) {
    table, thead, tbody, th, td, tr { display:block; }
    thead { display:none; }
    tr { background:rgba(29,32,48,0.8); margin-bottom:8px; padding:10px 12px; border:1px solid rgba(124,124,255,0.2); }
    td { border:none; padding:3px 0; }
    td:before { content: attr(data-label); font-family:var(--mono); font-size:8px; color:var(--text-dim); display:block; }
  }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Bestellungen-Historie</div>
  <div class="treffer-info"><?= $gesamt_treffer ?> Treffer · Seite <?= $seite ?> von <?= $gesamt_seiten ?></div>

  <div class="filter-block">
    <div class="filter-row">
      <span class="filter-label">Status</span>
      <a href="<?= baue_url('', $kanal_filter, $suche, 1) ?>" class="filter-chip <?= $status_filter === '' ? 'active' : '' ?>">Alle</a>
      <?php foreach ($status_labels as $key => $label): ?>
        <a href="<?= baue_url($key, $kanal_filter, $suche, 1) ?>" class="filter-chip <?= $status_filter === $key ? 'active' : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
    <div class="filter-row">
      <span class="filter-label">Kanal</span>
      <a href="<?= baue_url($status_filter, '', $suche, 1) ?>" class="filter-chip <?= $kanal_filter === '' ? 'active' : '' ?>">Alle</a>
      <?php foreach ($kanal_labels as $key => $label): ?>
        <a href="<?= baue_url($status_filter, $key, $suche, 1) ?>" class="filter-chip <?= $kanal_filter === $key ? 'active' : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
    <form class="suche-form" method="GET">
      <?php if ($status_filter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>"><?php endif; ?>
      <?php if ($kanal_filter !== ''): ?><input type="hidden" name="kanal" value="<?= htmlspecialchars($kanal_filter) ?>"><?php endif; ?>
      <input class="suche-input" type="text" name="suche" placeholder="🔍 Käufer, Artikel oder Sendungsnummer..." value="<?= htmlspecialchars($suche) ?>">
    </form>
  </div>

  <?php if (empty($bestellungen)): ?>
    <div class="empty">Keine Bestellungen gefunden</div>
  <?php else: ?>
  <table>
    <thead>
      <tr><th>Käufer</th><th>Kanal</th><th>Artikel</th><th>Status</th><th>Sendungsnr.</th><th>Bestelldatum</th><th>Versendet am</th></tr>
    </thead>
    <tbody>
      <?php foreach ($bestellungen as $b): ?>
      <tr>
        <td data-label="Käufer"><?= htmlspecialchars($b['kaeufer_name']) ?><?php if ($b['ist_test']): ?><span class="test-tag">TEST</span><?php endif; ?></td>
        <td data-label="Kanal"><span class="kanal-badge"><?= $kanal_labels[$b['kanal']] ?? $b['kanal'] ?></span></td>
        <td data-label="Artikel"><?= htmlspecialchars($b['artikel_name']) ?> (<?= $b['menge'] ?>x)</td>
        <td data-label="Status"><span class="status-badge <?= $b['status'] ?>"><?= $status_labels[$b['status']] ?></span></td>
        <td data-label="Sendungsnr."><?= $b['sendungsnummer'] ? htmlspecialchars($b['sendungsnummer']) : '—' ?></td>
        <td data-label="Bestelldatum"><?= $b['bestelldatum'] ? date('d.m.Y', strtotime($b['bestelldatum'])) : '—' ?></td>
        <td data-label="Versendet am"><?= $b['versendet_am'] ? date('d.m.Y', strtotime($b['versendet_am'])) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($gesamt_seiten > 1): ?>
  <div class="pagination">
    <?php if ($seite > 1): ?><a href="<?= baue_url($status_filter, $kanal_filter, $suche, $seite - 1) ?>" class="page-link">← Zurück</a><?php endif; ?>
    <?php for ($p = 1; $p <= $gesamt_seiten; $p++): ?>
      <a href="<?= baue_url($status_filter, $kanal_filter, $suche, $p) ?>" class="page-link <?= $p === $seite ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($seite < $gesamt_seiten): ?><a href="<?= baue_url($status_filter, $kanal_filter, $suche, $seite + 1) ?>" class="page-link">Weiter →</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

</div>
</body>
</html>
