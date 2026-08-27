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
<!--
  Eigenständiger Style-Block (Dunkel & Weich): _sidebar.php wird auch von
  Seiten eingebunden, die /assets/theme.css noch NICHT laden (die noch nicht
  migrierten alten Seiten). Damit Topbar/Sidebar dort trotzdem korrekt
  aussehen und nicht ungestylt zusammenbrechen, sind diese Regeln hier
  bewusst dupliziert (identisch zu den Topbar/Sidebar-Regeln in theme.css)
  und ausschließlich auf topbar/sidebar/nav-*-Klassen beschränkt, die sonst
  nirgendwo in den alten Seiten vorkommen — kein Risiko einer Kollision mit
  deren eigenem Style-Block.
-->
<style>
  .topbar {
    position: fixed; top: 0; left: 0; right: 0; height: 56px; display: flex; align-items: center;
    justify-content: space-between; padding: 0 22px; background: #1D2030;
    border-bottom: 1px solid #2E3248; z-index: 100; font-family: 'Inter', -apple-system, sans-serif;
  }
  .topbar-left { display: flex; align-items: center; gap: 12px; }
  .sb-menu-btn {
    display: none; background: none; border: none; cursor: pointer; padding: 6px;
    color: #E4E6F0; font-size: 18px; line-height: 1; border-radius: 8px;
  }
  .sb-menu-btn:active { background: #262A3D; }
  .sb-back-btn {
    display: none; background: none; border: none; cursor: pointer; padding: 6px;
    color: #9494FF; font-size: 20px; line-height: 1; border-radius: 8px; text-decoration: none;
    align-items: center; justify-content: center;
  }
  .sb-back-btn:active { background: #262A3D; }
  .logo-mark {
    width: 32px; height: 32px; border-radius: 10px; background: #7C7CFF22;
    display: flex; align-items: center; justify-content: center; overflow: hidden;
  }
  .logo-mark span { font-size: 12px; font-weight: 700; color: #7C7CFF; }
  .logo-mark img { width: 100%; height: 100%; object-fit: cover; }
  .topbar-title { font-size: 15px; font-weight: 600; color: #F5F6FA; }
  .topbar-right { display: flex; align-items: center; gap: 16px; font-size: 12px; color: #8B8FA8; }
  .status-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #4ADE80; margin-right: 6px; animation: sb-pulse 2s infinite; }
  @keyframes sb-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }
  .user-badge { background: #262A3D; border: 1px solid #2E3248; border-radius: 20px; padding: 5px 12px; color: #E4E6F0; font-weight: 500; }
  .logout-btn { background: none; border: none; font-size: 12px; color: #8B8FA8; cursor: pointer; transition: color 0.15s; font-family: 'Inter', -apple-system, sans-serif; }
  .logout-btn:hover { color: #F87171; }
  @media (max-width: 700px) {
    .topbar-right .user-badge { display: none; }
    .topbar-title { font-size: 13px; }
  }

  .sidebar {
    position: fixed; top: 56px; left: 0; bottom: 0; width: 216px;
    background: #1D2030; border-right: 1px solid #2E3248;
    z-index: 50; padding: 20px 0; display: flex; flex-direction: column; gap: 2px;
    font-family: 'Inter', -apple-system, sans-serif; overflow-y: auto;
    transition: transform 0.2s ease;
  }
  .sidebar .nav-section { font-size: 11px; color: #8B8FA8; letter-spacing: 1px; padding: 14px 20px 6px; font-weight: 600; text-transform: uppercase; }
  .sidebar .nav-item {
    display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 13.5px;
    color: #8B8FA8; cursor: pointer; transition: all 0.15s; font-weight: 500;
    border-left: 3px solid transparent; text-decoration: none;
  }
  .sidebar .nav-item:hover { color: #E4E6F0; background: #262A3D; }
  .sidebar .nav-item.active { color: #7C7CFF; background: #7C7CFF22; border-left-color: #7C7CFF; }
  .sidebar .nav-icon { font-size: 14px; width: 18px; text-align: center; }
  .sidebar .nav-badge { margin-left: auto; background: #7C7CFF; color: #fff; font-size: 11px; font-weight: 600; padding: 1px 7px; border-radius: 10px; }
  .sidebar .nav-badge.urgent { background: #F87171; }
  .sidebar .nav-spacer { flex: 1; }

  .sb-backdrop { display: none; position: fixed; inset: 0; top: 56px; background: rgba(0,0,0,0.5); z-index: 49; }

  .page-content-wrapper { margin-left: 216px; margin-top: 56px; }
  @media (max-width: 900px) {
    .sb-menu-btn { display: inline-flex; align-items: center; justify-content: center; }
    .sb-back-btn.zeigen { display: inline-flex; }
    .sidebar {
      transform: translateX(-100%); box-shadow: 4px 0 24px rgba(0,0,0,0.35);
    }
    .sidebar.offen { transform: translateX(0); }
    .sb-backdrop.offen { display: block; }
    .page-content-wrapper { margin-left: 0; }
  }
</style>

<div class="topbar">
  <div class="topbar-left">
    <button type="button" class="sb-menu-btn" onclick="sbMenuUmschalten()" aria-label="Menü">☰</button>
    <a href="#" class="sb-back-btn" id="sb-back-btn" onclick="history.back(); return false;" aria-label="Zurück">←</a>
    <div class="logo-mark">
      <?php if (file_exists(__DIR__ . '/assets/naopslogo.png')): ?>
        <img src="/assets/naopslogo.png" alt="NA">
      <?php else: ?>
        <span>NA</span>
      <?php endif; ?>
    </div>
    <div class="topbar-title">NA Ops Hub</div>
  </div>
  <div class="topbar-right">
    <span><span class="status-dot"></span>ONLINE</span>
    <span id="sb-clock">--:--:--</span>
    <div class="user-badge"><?= strtoupper($sb_user['username']) ?> // <?= strtoupper($sb_user['name']) ?></div>
    <form method="post" action="/auth.php" style="display:inline;">
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="logout-btn">Logout</button>
    </form>
  </div>
</div>
<div class="sb-backdrop" id="sb-backdrop" onclick="sbMenuSchliessen()"></div>
<script>
  function sbUpdateClock() {
    const el = document.getElementById('sb-clock');
    if (el) el.textContent = new Date().toTimeString().slice(0,8);
  }
  sbUpdateClock();
  setInterval(sbUpdateClock, 1000);

  function sbMenuUmschalten() {
    document.querySelector('.sidebar').classList.toggle('offen');
    document.getElementById('sb-backdrop').classList.toggle('offen');
  }
  function sbMenuSchliessen() {
    document.querySelector('.sidebar').classList.remove('offen');
    document.getElementById('sb-backdrop').classList.remove('offen');
  }
  // Zurück-Pfeil neben dem Logo nur zeigen, wenn diese Seite nicht das
  // Dashboard selbst ist UND es eine Browser-Historie gibt, in die man
  // zurück kann (sonst wäre er wirkungslos).
  if (window.location.pathname !== '/dashboard.php' && history.length > 1) {
    document.getElementById('sb-back-btn').classList.add('zeigen');
  }
</script>

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
  <a href="/marketing/marketing.php" class="nav-item"><span class="nav-icon">📈</span> Marketing</a>
  <a href="/trips/trip.php" class="nav-item"><span class="nav-icon">🚗</span> Sammel-Trips</a>
  <a href="/auslagen/auslagen.php" class="nav-item"><span class="nav-icon">💸</span> Auslagen</a>
  <?php if (hat_modul_zugriff('dokumente')): ?>
  <a href="/documents/documents.php" class="nav-item"><span class="nav-icon">📄</span> Dokumente</a>
  <?php endif; ?>
  <?php if (hat_modul_zugriff('kalkulator')): ?>
  <a href="/kalkulator/rechner.php" class="nav-item"><span class="nav-icon">🧮</span> Kalkulator</a>
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