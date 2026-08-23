<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('produkttexte');
$pdo = db();

// ── TEXTBAUSTEIN-GENERATOR (CozyCore-Markenstil) ────────────────────────────
// Warm, modern, gemütlich, vertrauenswürdig, hochwertig — einfach & emotional,
// aber nicht übertrieben. Keine medizinischen Versprechen.
// Falls ihr später einen KI-API-Schlüssel habt (z.B. Anthropic/OpenAI), kann
// diese Funktion durch einen echten API-Call ersetzt werden — der Rest der
// Seite bleibt unverändert.

function cozycore_kategorie_typ(string $kategorie_name): string
{
    $k = mb_strtolower($kategorie_name);
    if (str_contains($k, 'wärmflasche') || str_contains($k, 'waermflasche')) return 'waermflasche';
    if (str_contains($k, 'augenmaske')) return 'augenmaske';
    return 'sonstiges';
}

function cozycore_titel(string $name, string $typ, string $plattform): string
{
    $varianten = match ($typ) {
        'waermflasche' => [
            "{$name} – Wohlige Wärme & Komfort für gemütliche Stunden",
            "{$name} – Dein Begleiter für entspannte Abende",
            "{$name} | Kuschelig warm, hochwertig verarbeitet",
        ],
        'augenmaske' => [
            "{$name} – Weich, dunkel, erholsam",
            "{$name} | Für ruhigen Schlaf und entspannte Pausen",
            "{$name} – Dein kleiner Rückzugsort für unterwegs & zu Hause",
        ],
        default => [
            "{$name} – Komfort für den Alltag",
            "{$name} | Hochwertig, gemütlich, durchdacht",
        ],
    };

    $titel = $varianten[array_rand($varianten)];

    if ($plattform === 'tiktok') {
        // TikTok: kürzer, hookiger, kein Pipe-Format
        $titel = str_replace([' | ', ' – '], ' ', $titel);
        $titel = mb_substr($titel, 0, 60);
    }

    return $titel;
}

function cozycore_bullets(string $typ, array $merkmale): array
{
    $pool = match ($typ) {
        'waermflasche' => [
            "WOHLIGE WÄRME: Hält lange warm und sorgt für Entspannung an kalten Tagen",
            "WEICHER BEZUG: Kuschelig weich und angenehm auf der Haut",
            "VIELSEITIG: Perfekt für kalte Abende, entspannte Stunden oder einfach zum Kuscheln",
            "EINFACHE PFLEGE: Bezug bei 30°C waschbar",
            "SCHÖNES GESCHENK: Ideal als kleine Aufmerksamkeit für Familie und Freunde",
            "MODERNES DESIGN: Passt in jedes gemütliche Zuhause",
        ],
        'augenmaske' => [
            "ANGENEHME DUNKELHEIT: Blockt Licht zuverlässig ab, für erholsame Ruhepausen",
            "WEICHES MATERIAL: Kuschelig weich, ohne Druck auf die Augen",
            "IDEAL UNTERWEGS: Klein und leicht, perfekt für Reisen oder kurze Pausen",
            "ANGENEHMER TRAGEKOMFORT: Sitzt sanft und rutscht nicht",
            "SCHÖNES SET: Passend zur Wärmflasche für den perfekten Wohlfühlmoment",
        ],
        default => [
            "HOHE QUALITÄT: Sorgfältig ausgewählte Materialien",
            "DURCHDACHTES DESIGN: Für den Alltag gemacht",
            "SCHÖNES GESCHENK: Eine kleine Aufmerksamkeit, die Freude macht",
            "EINFACHE HANDHABUNG: Unkompliziert im Alltag",
        ],
    };

    shuffle($pool);
    $bullets = array_slice($pool, 0, 5);

    // Nutzer-Merkmale als zusätzliche Bullets voranstellen, falls angegeben
    foreach (array_reverse($merkmale) as $merkmal) {
        if (trim($merkmal) !== '') {
            array_unshift($bullets, mb_strtoupper($merkmal, 'UTF-8'));
        }
    }

    return array_slice($bullets, 0, 6);
}

function cozycore_beschreibung(string $name, string $typ, array $merkmale): string
{
    $merkmale_satz = !empty(array_filter($merkmale))
        ? ' ' . implode(', ', array_filter($merkmale)) . '.'
        : '';

    $texte = match ($typ) {
        'waermflasche' => [
            "Mit {$name} holst du dir wohlige Wärme und Gemütlichkeit nach Hause. Der weiche Bezug fühlt sich angenehm auf der Haut an und macht kalte Abende gleich viel gemütlicher.{$merkmale_satz} Ob zum Entspannen auf dem Sofa oder als kleines Geschenk für jemanden, den du magst — {$name} sorgt für einen wohligen Moment im Alltag.",
            "Kalte Tage werden mit {$name} gleich angenehmer. Die hochwertige Verarbeitung und der kuschelige Bezug machen sie zu einem treuen Begleiter für entspannte Stunden.{$merkmale_satz} Einfach in der Pflege und schön anzusehen — {$name} passt in jedes gemütliche Zuhause.",
        ],
        'augenmaske' => [
            "{$name} schafft dir kleine Ruheoasen im Alltag. Das weiche Material liegt angenehm auf und blockt Licht zuverlässig ab, egal ob für eine kurze Pause zwischendurch oder eine ruhige Nacht.{$merkmale_satz} Kompakt und leicht — ideal für zu Hause und unterwegs.",
        ],
        default => [
            "{$name} ist durchdacht gemacht, um deinen Alltag ein bisschen angenehmer zu machen.{$merkmale_satz} Hochwertig verarbeitet und mit Liebe zum Detail — genau das richtige für Momente der Ruhe.",
        ],
    };

    return $texte[array_rand($texte)];
}

// ── AKTIONEN ─────────────────────────────────────────────────────────────────
$generiert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generieren'])) {
    $produkt_id = (int)($_POST['produkt_id'] ?? 0);
    $plattform = $_POST['plattform'] ?? 'amazon';
    $merkmale_roh = trim($_POST['merkmale'] ?? '');
    $merkmale = $merkmale_roh !== '' ? array_map('trim', explode(',', $merkmale_roh)) : [];

    $stmt = $pdo->prepare("SELECT p.name, k.name AS kategorie FROM lager_produkte p JOIN lager_kategorien k ON k.id = p.kategorie_id WHERE p.id = ?");
    $stmt->execute([$produkt_id]);
    $produkt = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($produkt) {
        $typ = cozycore_kategorie_typ($produkt['kategorie']);
        $generiert = [
            'titel' => cozycore_titel($produkt['name'], $typ, $plattform),
            'bullets' => cozycore_bullets($typ, $merkmale),
            'beschreibung' => cozycore_beschreibung($produkt['name'], $typ, $merkmale),
        ];

        $pdo->prepare("
            INSERT INTO produkttexte (produkt_id, plattform, titel, bullets, beschreibung, merkmale_eingabe)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $produkt_id, $plattform, $generiert['titel'],
            implode("\n", $generiert['bullets']), $generiert['beschreibung'], $merkmale_roh,
        ]);
    }
}

$produkte = $pdo->query("SELECT id, name FROM lager_produkte WHERE aktiv = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$historie = $pdo->query("
    SELECT t.*, p.name AS produkt_name
    FROM produkttexte t
    LEFT JOIN lager_produkte p ON p.id = t.produkt_id
    ORDER BY t.erstellt_am DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$plattform_labels = ['amazon' => 'Amazon', 'ebay' => 'eBay', 'tiktok' => 'TikTok Shop'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Produkttexte — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #0f1923; --navy2: #152236; --blue-accent: #2d6aad; --blue-bright: #4a9edd;
    --text: #c8dff0; --text-dim: #5a7a9a; --green: #2ecc71; --terracotta: #c88b5a;
    --mono: 'Share Tech Mono', monospace; --sans: 'Exo 2', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(15,25,35,0.97); border-bottom:1px solid rgba(45,106,173,0.2); z-index:100; }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; letter-spacing:1px; }
  .topbar-title { font-size:14px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; }

  .main { max-width:900px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:22px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); letter-spacing:1px; margin-bottom:24px; }

  .panel { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:20px; margin-bottom:20px; }
  .panel-title { font-family:var(--mono); font-size:11px; letter-spacing:2px; text-transform:uppercase; color:var(--blue-bright); margin-bottom:16px; }

  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); letter-spacing:2px; text-transform:uppercase; margin-bottom:6px; display:block; }
  .field-input, .field-select {
    width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25);
    color:var(--text); font-family:var(--sans); font-size:13px; padding:10px 12px; outline:none; margin-bottom:14px;
  }
  .field-input:focus, .field-select:focus { border-color:var(--blue-bright); }
  .plattform-row { display:flex; gap:8px; margin-bottom:14px; }
  .plattform-btn { flex:1; padding:10px; border:1px solid rgba(45,106,173,0.25); background:none; color:var(--text-dim); font-family:var(--mono); font-size:11px; cursor:pointer; }
  .plattform-btn.active { background:rgba(74,158,221,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }

  .btn-generieren { width:100%; background:none; border:1px solid var(--blue-bright); color:var(--blue-bright); padding:13px; font-family:var(--mono); font-size:12px; letter-spacing:2px; text-transform:uppercase; cursor:pointer; }
  .btn-generieren:hover { background:rgba(74,158,221,0.1); }

  .ergebnis-box { background:rgba(15,25,35,0.6); border:1px solid rgba(200,139,90,0.3); padding:20px; margin-top:20px; }
  .ergebnis-label { font-family:var(--mono); font-size:9px; color:var(--terracotta); letter-spacing:2px; text-transform:uppercase; margin-bottom:6px; }
  .ergebnis-titel { font-size:16px; font-weight:700; margin-bottom:16px; }
  .ergebnis-bullets { list-style:none; margin-bottom:16px; }
  .ergebnis-bullets li { padding:6px 0; font-size:13px; border-bottom:1px solid rgba(45,106,173,0.1); }
  .ergebnis-bullets li:before { content:'✓ '; color:var(--green); font-weight:700; }
  .ergebnis-beschreibung { font-size:13px; line-height:1.6; color:var(--text); }
  .copy-btn { font-family:var(--mono); font-size:9px; color:var(--blue-bright); background:none; border:1px solid var(--blue-bright); padding:4px 10px; cursor:pointer; margin-top:8px; }
  .copy-btn:hover { background:rgba(74,158,221,0.1); }

  .historie-item { padding:12px 0; border-bottom:1px solid rgba(45,106,173,0.1); }
  .historie-item:last-child { border-bottom:none; }
  .historie-meta { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:4px; }
  .historie-titel { font-size:13px; font-weight:600; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Produkttext-Generator</div>
  <div class="page-sub">// COZYCORE-MARKENSTIL — TEXTBAUSTEIN-BASIERT, JEDERZEIT NEU GENERIERBAR</div>

  <div class="panel">
    <div class="panel-title">// EINGABE</div>
    <form method="POST">
      <label class="field-label">Produkt</label>
      <select class="field-select" name="produkt_id" required>
        <option value="">— wählen —</option>
        <?php foreach ($produkte as $p): ?>
          <option value="<?= $p['id'] ?>" <?= (isset($_POST['produkt_id']) && $_POST['produkt_id'] == $p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <label class="field-label">Plattform</label>
      <div class="plattform-row">
        <?php foreach ($plattform_labels as $key => $label): ?>
          <button type="button" class="plattform-btn <?= (($_POST['plattform'] ?? 'amazon') === $key) ? 'active' : '' ?>" data-plattform="<?= $key ?>" onclick="plattformWaehlen('<?= $key ?>')"><?= $label ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="plattform" id="plattform-input" value="<?= htmlspecialchars($_POST['plattform'] ?? 'amazon') ?>">

      <label class="field-label">Besondere Merkmale (optional, mit Komma getrennt)</label>
      <input class="field-input" type="text" name="merkmale" placeholder="z.B. Baumwollbezug, waschbar bei 30°C, handgefertigt" value="<?= htmlspecialchars($_POST['merkmale'] ?? '') ?>">

      <button type="submit" name="generieren" value="1" class="btn-generieren">✨ Text generieren</button>
    </form>

    <?php if ($generiert): ?>
    <div class="ergebnis-box">
      <div class="ergebnis-label">Titel</div>
      <div class="ergebnis-titel" id="erg-titel"><?= htmlspecialchars($generiert['titel']) ?></div>

      <div class="ergebnis-label">Bullet Points</div>
      <ul class="ergebnis-bullets" id="erg-bullets">
        <?php foreach ($generiert['bullets'] as $b): ?>
          <li><?= htmlspecialchars($b) ?></li>
        <?php endforeach; ?>
      </ul>

      <div class="ergebnis-label">Beschreibung</div>
      <div class="ergebnis-beschreibung" id="erg-beschreibung"><?= htmlspecialchars($generiert['beschreibung']) ?></div>

      <button class="copy-btn" onclick="allesKopieren()">📋 Alles kopieren</button>
    </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-title">// LETZTE 10 GENERIERTE TEXTE</div>
    <?php if (empty($historie)): ?>
      <div style="font-family:var(--mono); font-size:11px; color:var(--text-dim); text-align:center; padding:20px;">// NOCH NICHTS GENERIERT</div>
    <?php else: ?>
      <?php foreach ($historie as $h): ?>
      <div class="historie-item">
        <div class="historie-meta"><?= htmlspecialchars($h['produkt_name'] ?? '?') ?> · <?= $plattform_labels[$h['plattform']] ?? $h['plattform'] ?> · <?= date('d.m.Y H:i', strtotime($h['erstellt_am'])) ?></div>
        <div class="historie-titel"><?= htmlspecialchars($h['titel']) ?></div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
function plattformWaehlen(k) {
  document.getElementById('plattform-input').value = k;
  document.querySelectorAll('.plattform-btn').forEach(b => b.classList.toggle('active', b.dataset.plattform === k));
}

function allesKopieren() {
  const titel = document.getElementById('erg-titel')?.textContent ?? '';
  const bullets = Array.from(document.querySelectorAll('#erg-bullets li')).map(li => '✓ ' + li.textContent).join('\n');
  const beschreibung = document.getElementById('erg-beschreibung')?.textContent ?? '';
  const text = `${titel}\n\n${bullets}\n\n${beschreibung}`;
  navigator.clipboard.writeText(text).then(() => alert('In Zwischenablage kopiert!'));
}
</script>
</div>
</body>
</html>
