<?php
/**
 * NA Ops Hub — Gemeinsame Sidebar für Desktop, überall einbindbar.
 *
 * Nutzung (nach config.php + requireLogin() + berechtigungen_helper.php):
 *   require_once __DIR__ . '/../_sidebar.php';   // aus einem Unterordner
 *   require_once __DIR__ . '/_sidebar.php';      // aus dem Hauptordner
 *
 * Direkt danach im HTML: <div class="page-content-wrapper"> ... </div>
 * (schiebt den Seiteninhalt neben die Sidebar, siehe CSS unten)
 */

$sb_user = currentUser();
$sb_pdo = db();
$sb_ist_systemverwalter = $sb_user['username'] === 'qaf';

$sb_lager_count = (int)$sb_pdo->query("SELECT COUNT(*) FROM lager_produkte WHERE aktiv = 1")->fetchColumn();
$sb_todo_offen = (int)$sb_pdo->query("SELECT COUNT(*) FROM aufgaben WHERE status != 'erledigt'")->fetchColumn();
?>
<style>
  .topbar {
    position:fixed; top:0; left:0; right:0; height:48px; display:flex; align-items:center;
    justify-content:space-between; padding:0 20px; background:rgba(15,25,35,0.97);
    border-bottom:1px solid rgba(45,106,173,0.2); z-index:100; font-family:'Share Tech Mono', monospace;
  }
  .topbar-left { display:flex; align-items:center; gap:14px; }
  .logo-mark { width:30px; height:30px; background:#1e3a5f; border:1px solid #2d6aad; display:flex; align-items:center; justify-content:center; clip-path:polygon(10% 0%,90% 0%,100% 10%,100% 90%,90% 100%,10% 100%,0% 90%,0% 10%); }
  .logo-mark span { font-size:10px; color:#4a9edd; }
  .topbar-title { font-size:14px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; font-family:'Exo 2',sans-serif; }
  .topbar-right { display:flex; align-items:center; gap:16px; font-size:10px; color:#5a7a9a; }
  .status-dot { display:inline-block; width:6px; height:6px; border-radius:50%; background:#2ecc71; margin-right:5px; animation:sb-pulse 2s infinite; }
  @keyframes sb-pulse { 0%,100%{opacity:1} 50%{opacity:0.3} }
  .user-badge { background:rgba(45,106,173,0.15); border:1px solid rgba(45,106,173,0.3); padding:4px 10px; color:#4a9edd; letter-spacing:2px; }
  .logout-btn { background:none; border:none; font-size:10px; color:#5a7a9a; cursor:pointer; letter-spacing:2px; transition:color 0.2s; font-family:'Share Tech Mono', monospace; }
  .logout-btn:hover { color:#e74c3c; }
  @media(max-width:700px) {
    .topbar-right .user-badge { display:none; }
    .topbar-title { font-size:12px; }
  }
</style>

<div class="topbar">
  <div class="topbar-left">
    <div class="logo-mark"><span>NA</span></div>
    <div class="topbar-title">NA Ops Hub</div>
  </div>
  <div class="topbar-right">
    <span><span class="status-dot"></span>ONLINE</span>
    <span id="sb-clock">--:--:--</span>
    <div class="user-badge"><?= strtoupper($sb_user['username']) ?> // <?= strtoupper($sb_user['name']) ?></div>
    <form method="post" action="/auth.php" style="display:inline;">
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="logout-btn">[ LOGOUT ]</button>
    </form>
  </div>
</div>
<script>
  function sbUpdateClock() {
    const el = document.getElementById('sb-clock');
    if (el) el.textContent = new Date().toTimeString().slice(0,8);
  }
  sbUpdateClock();
  setInterval(sbUpdateClock, 1000);
</script>

<style>
  .sidebar {
    position:fixed; top:48px; left:0; bottom:0; width:200px;
    background:rgba(15,25,35,0.92); border-right:1px solid rgba(45,106,173,0.15);
    z-index:50; padding:20px 0; display:flex; flex-direction:column; gap:2px;
    font-family:'Share Tech Mono', monospace;
    overflow-y:auto;
  }
  .sidebar .nav-section { font-size:9px; color:#5a7a9a; letter-spacing:3px; padding:12px 18px 6px; text-transform:uppercase; }
  .sidebar .nav-item {
    display:flex; align-items:center; gap:10px; padding:10px 18px; font-size:11px;
    color:#5a7a9a; letter-spacing:1px; cursor:pointer; transition:all 0.15s;
    border-left:2px solid transparent; text-transform:uppercase; text-decoration:none;
  }
  .sidebar .nav-item:hover { color:#c8dff0; background:rgba(45,106,173,0.08); }
  .sidebar .nav-item.active { color:#4a9edd; background:rgba(74,158,221,0.08); border-left-color:#4a9edd; }
  .sidebar .nav-icon { font-size:13px; width:16px; text-align:center; }
  .sidebar .nav-badge { margin-left:auto; background:#2d6aad; color:#fff; font-size:9px; padding:1px 6px; border-radius:2px; }
  .sidebar .nav-badge.urgent { background:#e74c3c; }
  .sidebar .nav-spacer { flex:1; }

  .page-content-wrapper { margin-left:200px; margin-top:48px; }
  @media(max-width:900px) {
    .sidebar { display:none; }
    .page-content-wrapper { margin-left:0; }
  }
</style>

<div class="sidebar">
  <div class="nav-section">Navigation</div>
  <a href="/dashboard.php" class="nav-item"><span class="nav-icon">▦</span> Dashboard</a>
  <?php if (hat_modul_zugriff('lager')): ?>
  <a href="/dashboard.php#lager" class="nav-item"><span class="nav-icon">◫</span> Lager <span class="nav-badge"><?= $sb_lager_count ?></span></a>
  <?php endif; ?>
  <?php if (hat_modul_zugriff('aufgaben')): ?>
  <a href="/aufgaben/aufgaben.php" class="nav-item"><span class="nav-icon">◈</span> Aufgaben <?php if($sb_todo_offen > 0): ?><span class="nav-badge urgent"><?= $sb_todo_offen ?></span><?php endif; ?></a>
  <?php endif; ?>
  <?php if (hat_modul_zugriff('bestellungen')): ?>
  <a href="/bestellungen/bestellungen.php" class="nav-item"><span class="nav-icon">📦</span> Bestellungen</a>
  <?php endif; ?>
  <?php if (hat_modul_zugriff('clean')): ?>
  <a href="/clean/pipeline.php" class="nav-item"><span class="nav-icon">🧹</span> NA Clean Service</a>
  <?php endif; ?>
  <div class="nav-spacer"></div>
  <a href="/marketing.php" class="nav-item"><span class="nav-icon">📈</span> Marketing</a>
  <a href="/trip.php" class="nav-item"><span class="nav-icon">🚗</span> Sammel-Trips</a>
  <a href="/auslagen.php" class="nav-item"><span class="nav-icon">💸</span> Auslagen</a>
  <?php if (hat_modul_zugriff('dokumente')): ?>
  <a href="/documents.php" class="nav-item"><span class="nav-icon">📄</span> Dokumente</a>
  <?php endif; ?>
  <?php if (hat_modul_zugriff('kalkulator')): ?>
  <a href="/rechner.php" class="nav-item"><span class="nav-icon">🧮</span> Kalkulator</a>
  <?php endif; ?>
  <?php if (hat_modul_zugriff('umsatz')): ?>
  <a href="/umsatz/dashboard.php" class="nav-item"><span class="nav-icon">📊</span> Umsatz</a>
  <?php endif; ?>
  <?php if (hat_modul_zugriff('nachricht')): ?>
  <a href="/telegram/senden.php" class="nav-item"><span class="nav-icon">💬</span> Nachricht senden</a>
  <?php endif; ?>
  <?php if (hat_modul_zugriff('export')): ?>
  <a href="/export/export.php" class="nav-item"><span class="nav-icon">⬇️</span> Export</a>
  <?php endif; ?>
  <?php if (hat_modul_zugriff('produkttexte')): ?>
  <a href="/produkttexte/produkttexte.php" class="nav-item"><span class="nav-icon">✨</span> Produkttexte</a>
  <?php endif; ?>
  <?php if (hat_modul_zugriff('antworten')): ?>
  <a href="/antworten/antworten.php" class="nav-item"><span class="nav-icon">💭</span> Antworten</a>
  <?php endif; ?>
  <?php if (hat_modul_zugriff('steuer')): ?>
  <a href="/steuer/steuer.php" class="nav-item"><span class="nav-icon">🏦</span> Steuerrücklage</a>
  <?php endif; ?>
  <div class="nav-section">System</div>
  <?php if ($sb_ist_systemverwalter): ?>
  <a href="/berechtigungen/benutzerverwaltung.php" class="nav-item"><span class="nav-icon">🔐</span> Admin</a>
  <?php endif; ?>
</div>