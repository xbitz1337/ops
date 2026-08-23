<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('dokumente', 'bearbeiten');
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$pdo = db();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    if ($_POST['action'] === 'beleg_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT dateipfad, foto_pfad FROM eigenbelege WHERE id = ?");
        $stmt->execute([$id]);
        $beleg = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($beleg) {
            $pdf_pfad = __DIR__ . '/' . $beleg['dateipfad'];
            if (file_exists($pdf_pfad)) unlink($pdf_pfad);
            if ($beleg['foto_pfad']) {
                $foto_pfad_voll = __DIR__ . '/' . $beleg['foto_pfad'];
                if (file_exists($foto_pfad_voll)) unlink($foto_pfad_voll);
            }
            $pdo->prepare("DELETE FROM eigenbelege WHERE id = ?")->execute([$id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $datum = $_POST['datum'] ?? date('Y-m-d');
    $fahrer = trim($_POST['fahrer'] ?? '');
    $fahrzeug = trim($_POST['fahrzeug'] ?? '');
    $route = trim($_POST['route'] ?? '');
    $strecke_km = (float)($_POST['strecke_km'] ?? 0);
    $zweck = trim($_POST['zweck'] ?? '');
    $kilometersatz = 0.30;
    $gesamtbetrag = round($strecke_km * $kilometersatz, 2);
    $verfasser = $_POST['verfasser'] ?? 'artis';
    $signatur_modus = $_POST['signatur_modus'] ?? 'gespeichert';
    if (!in_array($verfasser, ['artis', 'nour', 'beide'])) $verfasser = 'artis';
    if (!in_array($signatur_modus, ['keine', 'gespeichert'])) $signatur_modus = 'gespeichert';

    // Foto (Google-Maps-Screenshot o.ä.) einlesen und als Base64 fürs PDF vorbereiten
    $foto_base64 = '';
    $foto_relativpfad = null;
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $erlaubt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = $_FILES['foto']['type'];
        if (isset($erlaubt[$mime])) {
            $ordner = __DIR__ . '/eigenbeleg_fotos';
            if (!is_dir($ordner)) mkdir($ordner, 0755, true);
            $dateiname_foto = uniqid('route_') . '.' . $erlaubt[$mime];
            $zielpfad = $ordner . '/' . $dateiname_foto;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $zielpfad)) {
                $foto_relativpfad = 'eigenbeleg_fotos/' . $dateiname_foto;
                $foto_base64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($zielpfad));
            }
        }
    }

    // Fortlaufende Belegnummer
    $jahr = date('Y', strtotime($datum));
    $anzahl = (int)$pdo->query("SELECT COUNT(*) FROM eigenbelege WHERE YEAR(datum) = $jahr")->fetchColumn();
    $belegnummer = "EB-{$jahr}-" . str_pad($anzahl + 1, 3, '0', STR_PAD_LEFT);

    $na_logo_base64 = '';
    $logo_dir = __DIR__ . '/../assets/';
    $lade_asset_base64 = function (string $basisname) use ($logo_dir): string {
        if (!is_dir($logo_dir)) return '';
        foreach (scandir($logo_dir) as $datei) {
            $ohne_endung = pathinfo($datei, PATHINFO_FILENAME);
            $endung = strtolower(pathinfo($datei, PATHINFO_EXTENSION));
            if (strtolower($ohne_endung) === strtolower($basisname) && in_array($endung, ['png','jpg','jpeg'])) {
                $mime = $endung === 'png' ? 'image/png' : 'image/jpeg';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logo_dir . $datei));
            }
        }
        return '';
    };
    $na_logo_base64 = $lade_asset_base64('na-commerce-logo');

    // Unterschrift-Blöcke zusammenstellen — echte gespeicherte Unterschriften,
    // genau wie beim normalen Dokumenten-Generator
    $unterschrift_bloecke = [];
    if ($signatur_modus === 'gespeichert') {
        if ($verfasser === 'beide') {
            $unterschrift_bloecke[] = ['bild' => $lade_asset_base64('unterschrift-artis'), 'name' => 'Artis Freibergs'];
            $unterschrift_bloecke[] = ['bild' => $lade_asset_base64('unterschrift-nour'), 'name' => 'Mhd Nour Shaaban'];
        } else {
            $name = $verfasser === 'artis' ? 'Artis Freibergs' : 'Mhd Nour Shaaban';
            $unterschrift_bloecke[] = ['bild' => $lade_asset_base64('unterschrift-' . $verfasser), 'name' => $name];
        }
    } else {
        // "Ohne Unterschrift" — trotzdem Namen + Freiraum zum handschriftlichen Unterschreiben
        if ($verfasser === 'beide') {
            $unterschrift_bloecke[] = ['bild' => '', 'name' => 'Artis Freibergs'];
            $unterschrift_bloecke[] = ['bild' => '', 'name' => 'Mhd Nour Shaaban'];
        } else {
            $name = $verfasser === 'artis' ? 'Artis Freibergs' : 'Mhd Nour Shaaban';
            $unterschrift_bloecke[] = ['bild' => '', 'name' => $name];
        }
    }

    $datum_formatiert = date('d.m.Y', strtotime($datum));
    $erstellt_formatiert = date('d.m.Y, H:i');

    ob_start();
    ?>
    <html><head><style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #1D1D1F; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; }
        .logo { height: 40px; }
        .firma { text-align: right; font-size: 10px; color: #666; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { color: #666; font-size: 10px; margin-bottom: 24px; }
        table.felder { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.felder th { text-align: left; background: #FAFAFA; padding: 8px 10px; font-size: 10px; text-transform: uppercase; color: #86868B; width: 35%; border-bottom: 1px solid #eee; }
        table.felder td { padding: 8px 10px; border-bottom: 1px solid #eee; }
        .betrag-box { background: #FBF6EF; border: 1px solid #EADFD3; border-radius: 8px; padding: 14px 18px; margin: 20px 0; }
        .betrag-tabelle { width: 100%; border-collapse: collapse; }
        .betrag-tabelle td { padding: 4px 0; font-size: 12px; }
        .betrag-tabelle td.wert { text-align: right; }
        .betrag-tabelle tr.final td { border-top: 1px solid #EADFD3; padding-top: 10px; margin-top: 6px; font-weight: bold; font-size: 15px; color: #A85C32; }
        .foto-block { margin-top: 24px; }
        .foto-titel { font-size: 10px; text-transform: uppercase; color: #86868B; margin-bottom: 8px; }
        .foto-block img { max-width: 100%; max-height: 320px; border: 1px solid #E5E5E7; border-radius: 6px; }
        .unterschrift-block { margin-top: 50px; display: flex; justify-content: space-between; }
        .unterschrift-feld { width: 45%; }
        .unterschrift-feld img { max-height: 60px; margin-bottom: 4px; }
        .unterschrift-linie { border-top: 1px solid #333; padding-top: 6px; font-size: 10px; color: #666; }
    </style></head><body>

    <div class="header">
        <?php if ($na_logo_base64): ?><img class="logo" src="<?= $na_logo_base64 ?>"><?php endif; ?>
        <div class="firma">
            NA Commerce Solutions GmbH<br>
            Am Bahndamm 7, 28719 Bremen
        </div>
    </div>

    <h1>Eigenbeleg - Fahrtkosten</h1>
    <div class="meta">Beleg-Nr. <?= htmlspecialchars($belegnummer) ?> - erstellt am <?= $erstellt_formatiert ?></div>

    <table class="felder">
        <tr><th>Datum der Fahrt</th><td><?= $datum_formatiert ?></td></tr>
        <tr><th>Fahrer</th><td><?= htmlspecialchars($fahrer) ?></td></tr>
        <tr><th>Fahrzeug</th><td><?= htmlspecialchars($fahrzeug) ?></td></tr>
        <tr><th>Route</th><td><?= htmlspecialchars($route) ?></td></tr>
        <tr><th>Gefahrene Strecke</th><td><?= number_format($strecke_km, 2, ',', '.') ?> km</td></tr>
        <tr><th>Zweck der Fahrt</th><td><?= htmlspecialchars($zweck) ?></td></tr>
    </table>

    <div class="betrag-box">
        <table class="betrag-tabelle">
            <tr><td>Gefahrene Strecke</td><td class="wert"><?= number_format($strecke_km, 2, ',', '.') ?> km</td></tr>
            <tr><td>Kilometersatz</td><td class="wert"><?= number_format($kilometersatz, 2, ',', '.') ?> Euro/km</td></tr>
            <tr class="final"><td>Gesamtbetrag</td><td class="wert"><?= number_format($gesamtbetrag, 2, ',', '.') ?> Euro</td></tr>
        </table>
    </div>

    <?php if ($foto_base64): ?>
    <div class="foto-block">
        <div class="foto-titel">Routennachweis (Screenshot)</div>
        <img src="<?= $foto_base64 ?>">
    </div>
    <?php endif; ?>

    <div class="unterschrift-block">
        <?php foreach ($unterschrift_bloecke as $u): ?>
        <div class="unterschrift-feld">
            <?php if ($u['bild']): ?><img src="<?= $u['bild'] ?>"><?php endif; ?>
            <div class="unterschrift-linie"><?= htmlspecialchars($u['name']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    </body></html>
    <?php
    $html = ob_get_clean();

    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $jahr_ordner = date('Y', strtotime($datum));
    $monat_ordner = date('m', strtotime($datum));
    $relativer_ordner = "eigenbelege/{$jahr_ordner}/{$monat_ordner}";
    $absoluter_ordner = __DIR__ . '/' . $relativer_ordner;
    if (!is_dir($absoluter_ordner)) mkdir($absoluter_ordner, 0755, true);

    $dateiname = str_replace(['/', ' '], '-', $belegnummer) . '.pdf';
    $dateipfad = "{$relativer_ordner}/{$dateiname}";
    file_put_contents($absoluter_ordner . '/' . $dateiname, $dompdf->output());

    $pdo->prepare("
        INSERT INTO eigenbelege
            (belegnummer, datum, fahrer, fahrzeug, route, strecke_km, zweck, kilometersatz, gesamtbetrag, foto_pfad, dateiname, dateipfad, erstellt_von)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $belegnummer, $datum, $fahrer, $fahrzeug, $route, $strecke_km, $zweck,
        $kilometersatz, $gesamtbetrag, $foto_relativpfad, $dateiname, $dateipfad, $user['id'],
    ]);

    header('Location: eigenbeleg_pdf_ansehen.php?id=' . $pdo->lastInsertId());
    exit;
}

// Historie der letzten Belege für die Übersicht
$historie = $pdo->query("SELECT * FROM eigenbelege ORDER BY erstellt_am DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Eigenbeleg (Fahrtkosten) — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #0f1923; --navy2: #152236; --blue-accent: #2d6aad; --blue-bright: #4a9edd;
    --text: #c8dff0; --text-dim: #5a7a9a; --green: #2ecc71; --orange: #e67e22;
    --mono: 'Share Tech Mono', monospace; --sans: 'Exo 2', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .main { max-width:640px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#e8f4ff; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--orange); letter-spacing:1px; margin-bottom:24px; }

  .panel { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:20px; margin-bottom:16px; }
  .panel-title { font-family:var(--mono); font-size:11px; letter-spacing:2px; text-transform:uppercase; color:var(--blue-bright); margin-bottom:16px; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); letter-spacing:2px; text-transform:uppercase; margin-bottom:6px; display:block; }
  .field-input, .field-select { width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--sans); font-size:14px; padding:10px 12px; outline:none; margin-bottom:14px; }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

  .betrag-vorschau { background:rgba(230,126,34,0.1); border:1px solid rgba(230,126,34,0.3); padding:14px 16px; margin-bottom:16px; font-family:var(--mono); }
  .betrag-vorschau .zeile { display:flex; justify-content:space-between; font-size:12px; padding:3px 0; }
  .betrag-vorschau .final { border-top:1px solid rgba(230,126,34,0.3); margin-top:6px; padding-top:8px; font-size:16px; font-weight:700; color:var(--orange); }

  .btn-submit { width:100%; background:none; border:1px solid var(--green); color:var(--green); padding:14px; font-family:var(--mono); font-size:13px; letter-spacing:2px; text-transform:uppercase; cursor:pointer; }
  .btn-submit:hover { background:rgba(46,204,113,0.1); }

  .hist-zeile { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid rgba(45,106,173,0.08); font-size:12px; }
  .hist-zeile a { color:var(--blue-bright); text-decoration:none; font-family:var(--mono); font-size:10px; }
  .empty { text-align:center; font-family:var(--mono); font-size:11px; color:var(--text-dim); padding:20px; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Eigenbeleg — Fahrtkosten</div>
  <div class="page-sub">// FÜR PRIVAT GEFAHRENE STRECKEN, KILOMETERSATZ 0,30 €/KM</div>

  <div class="panel">
    <form method="POST" enctype="multipart/form-data" id="eb-form">
      <div class="row-2">
        <div><label class="field-label">Datum der Fahrt</label><input class="field-input" type="date" name="datum" value="<?= date('Y-m-d') ?>" required></div>
        <div>
          <label class="field-label">Fahrer</label>
          <select class="field-select" name="fahrer">
            <option value="Artis Freibergs">Artis Freibergs</option>
            <option value="Mhd Nour Shaaban">Mhd Nour Shaaban</option>
          </select>
        </div>
      </div>

      <label class="field-label">Fahrzeug</label>
      <input class="field-input" type="text" name="fahrzeug" placeholder="z.B. VW Golf, Kennzeichen HB-XX 123">

      <label class="field-label">Route</label>
      <input class="field-input" type="text" name="route" placeholder="z.B. Bremen — Hamburg — Bremen">

      <div class="row-2">
        <div><label class="field-label">Gefahrene Strecke (km)</label><input class="field-input" type="number" step="0.1" name="strecke_km" id="strecke_km" value="0"></div>
        <div><label class="field-label">Kilometersatz</label><input class="field-input" type="text" value="0,30 €/km" disabled></div>
      </div>

      <label class="field-label">Zweck der Fahrt</label>
      <input class="field-input" type="text" name="zweck" placeholder="z.B. Termin bei Lieferant, Materialabholung">

      <label class="field-label">Foto vom Routennachweis (z.B. Google-Maps-Screenshot)</label>
      <input class="field-input" type="file" name="foto" accept="image/*" capture="environment" style="padding:8px 12px;">

      <div class="row-2">
        <div>
          <label class="field-label">Wer unterschreibt?</label>
          <select class="field-select" name="verfasser">
            <option value="artis">Artis</option>
            <option value="nour">Nour</option>
            <option value="beide">Beide</option>
          </select>
        </div>
        <div>
          <label class="field-label">Unterschrift</label>
          <select class="field-select" name="signatur_modus">
            <option value="gespeichert">Gespeicherte Unterschrift</option>
            <option value="keine">Ohne (Freiraum)</option>
          </select>
        </div>
      </div>

      <div class="betrag-vorschau">
        <div class="zeile"><span>Strecke</span><span id="prev-km">0,0 km</span></div>
        <div class="zeile final"><span>Gesamtbetrag</span><span id="prev-betrag">0,00 €</span></div>
      </div>

      <button type="submit" class="btn-submit">✓ Eigenbeleg erstellen</button>
    </form>
  </div>

  <div class="panel">
    <div class="panel-title" style="display:flex; justify-content:space-between; align-items:center;">
      <span>// LETZTE BELEGE</span>
      <a href="eigenbeleg_historie.php" style="font-size:9px; color:var(--blue-bright); text-decoration:none;">Alle ansehen →</a>
    </div>
    <?php if (empty($historie)): ?>
      <div class="empty">// NOCH KEINE BELEGE ERSTELLT</div>
    <?php else: ?>
      <?php foreach ($historie as $h): ?>
      <div class="hist-zeile">
        <div><?= htmlspecialchars($h['belegnummer']) ?> — <?= htmlspecialchars($h['fahrer']) ?> (<?= date('d.m.Y', strtotime($h['datum'])) ?>), <?= number_format($h['strecke_km'],1,',','.') ?> km</div>
        <a href="eigenbeleg_pdf_ansehen.php?id=<?= $h['id'] ?>" target="_blank">PDF ansehen →</a>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
document.getElementById('strecke_km').addEventListener('input', function() {
  const km = parseFloat(this.value) || 0;
  const betrag = km * 0.30;
  document.getElementById('prev-km').textContent = km.toLocaleString('de-DE', {minimumFractionDigits:1}) + ' km';
  document.getElementById('prev-betrag').textContent = betrag.toLocaleString('de-DE', {minimumFractionDigits:2}) + ' €';
});
</script>
</div>
</body>
</html>
