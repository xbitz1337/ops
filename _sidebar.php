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
      <button type="submit" class="logout-btn">Logout</button>
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