<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('bestellungen', 'bearbeiten');
$pdo = db();

if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_GET['reset'])) {
    unset($_SESSION['import_csv_header'], $_SESSION['import_csv_zeilen'], $_SESSION['import_mapping']);
    header('Location: import_historie.php');
    exit;
}

$schritt = $_POST['schritt'] ?? (isset($_SESSION['import_csv_zeilen']) ? '2' : '1');

// ── SCHRITT 1: CSV HOCHLADEN ─────────────────────────────────────────────────
if ($schritt === '1' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $tmp_pfad = $_FILES['csv']['tmp_name'];
    $inhalt = file_get_contents($tmp_pfad);
    // Manche eBay-Exporte nutzen Windows-Zeilenumbrüche oder BOM — beides entfernen
    $inhalt = preg_replace('/^\xEF\xBB\xBF/', '', $inhalt);

    $rohzeilen = preg_split('/\r\n|\r|\n/', trim($inhalt));

    // Trennzeichen automatisch erkennen: eBays deutsche Exporte nutzen ";",
    // englische oft ",". Wir zählen einfach, welches Zeichen in der
    // wahrscheinlichen Kopfzeile öfter vorkommt.
    $test_zeile = $rohzeilen[1] ?? $rohzeilen[0] ?? '';
    $trennzeichen = (substr_count($test_zeile, ';') > substr_count($test_zeile, ',')) ? ';' : ',';

    $alle_zeilen = array_map(fn($z) => str_getcsv($z, $trennzeichen, '"'), $rohzeilen);

    // eBay-Exporte haben oft 1-2 "Müllzeilen" vor der echten Kopfzeile (leer
    // oder nur Trennzeichen) — die echte Kopfzeile ist die erste Zeile mit
    // vielen befüllten Zellen, die z.B. "Bestellnummer" enthält.
    $header_index = null;
    foreach ($alle_zeilen as $idx => $zeile) {
        $gefuellte_zellen = count(array_filter($zeile, fn($z) => trim((string)$z) !== ''));
        $als_text = implode(' ', $zeile);
        if ($gefuellte_zellen >= 5 && (stripos($als_text, 'Bestellnummer') !== false || stripos($als_text, 'Order') !== false || stripos($als_text, 'Artikelnummer') !== false)) {
            $header_index = $idx;
            break;
        }
    }
    // Falls keine erkennbare Kopfzeile gefunden wurde: einfach die erste
    // Zeile mit den meisten befüllten Zellen nehmen
    if ($header_index === null) {
        $max_gefuellt = -1;
        foreach ($alle_zeilen as $idx => $zeile) {
            $gefuellte_zellen = count(array_filter($zeile, fn($z) => trim((string)$z) !== ''));
            if ($gefuellte_zellen > $max_gefuellt) { $max_gefuellt = $gefuellte_zellen; $header_index = $idx; }
        }
    }

    $header = $alle_zeilen[$header_index];
    $daten_zeilen = array_slice($alle_zeilen, $header_index + 1);

    // Komplett leere Zeilen (z.B. eine Trennzeile direkt nach dem Header) raus
    $daten_zeilen = array_values(array_filter($daten_zeilen, function ($z) {
        return count(array_filter($z, fn($c) => trim((string)$c) !== '')) > 1;
    }));

    if (empty($header) || empty($daten_zeilen)) {
        $fehler = 'Datei konnte nicht gelesen werden, keine Kopfzeile/Daten gefunden.';
    } else {
        $_SESSION['import_csv_header'] = $header;
        $_SESSION['import_csv_zeilen'] = $daten_zeilen;
        header('Location: import_historie.php');
        exit;
    }
}

/**
 * eBay-Datumsformat wie "21-Dez-25" oder "15-Jan-26" korrekt parsen —
 * PHPs strtotime() versteht deutsche Monatsnamen wie "Dez"/"Mär"/"Okt" nicht.
 */
function ebay_datum_parsen(string $datum_roh): string {
    $monate = [
        'Jan' => '01', 'Feb' => '02', 'Mär' => '03', 'Mar' => '03', 'Apr' => '04',
        'Mai' => '05', 'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08',
        'Sep' => '09', 'Okt' => '10', 'Oct' => '10', 'Nov' => '11', 'Dez' => '12', 'Dec' => '12',
    ];
    if (preg_match('/^(\d{1,2})-([A-Za-zä]{3})-(\d{2,4})$/u', trim($datum_roh), $m)) {
        $tag = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $monat = $monate[$m[2]] ?? '01';
        $jahr = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
        return "{$jahr}-{$monat}-{$tag} 00:00:00";
    }
    // Fallback: normales strtotime versuchen, sonst heutiges Datum
    $ts = strtotime($datum_roh);
    return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}

/**
 * Errät automatisch, welche CSV-Spalte zu welchem Feld passt, basierend auf
 * typischen eBay-Spaltennamen (DE + EN). Nutzer kann das Ergebnis im Formular
 * trotzdem noch überschreiben, aber standardmäßig ist es schon richtig belegt.
 */
function spalte_erraten(array $header, array $suchbegriffe): ?int {
    foreach ($header as $idx => $spaltenname) {
        foreach ($suchbegriffe as $suchbegriff) {
            if (stripos($spaltenname, $suchbegriff) !== false) {
                return $idx;
            }
        }
    }
    return null;
}

$auto_mapping = [];
if (isset($_SESSION['import_csv_header'])) {
    $h = $_SESSION['import_csv_header'];
    $auto_mapping = [
        'map_datum' => spalte_erraten($h, ['Verkauft am', 'Sale Date', 'Order Date']),
        'map_kaeufer' => spalte_erraten($h, ['Nutzername des Käufers', 'Buyer Username', 'Buyer']),
        'map_artikel' => spalte_erraten($h, ['Angebotstitel', 'Item Title', 'Artikeltitel']),
        'map_sku' => spalte_erraten($h, ['Bestandseinheit', 'Custom Label', 'SKU', 'Artikelnummer', 'Item Number']),
        'map_menge' => spalte_erraten($h, ['Anzahl', 'Quantity', 'Menge']),
        'map_preis' => spalte_erraten($h, ['Verkauft für', 'Sale Price', 'Sold For']),
    ];
}

// ── SCHRITT 3: FINALER IMPORT ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schritt === '3') {
    $zeilen = $_SESSION['import_csv_zeilen'] ?? [];
    $mapping = $_SESSION['import_mapping'] ?? [];
    $produkt_zuordnung = $_POST['produkt'] ?? []; // Index => produkt_id (0 = kein Produkt)

    $importiert = 0;
    $mit_lagerbewegung = 0;

    foreach ($zeilen as $i => $zeile) {
        $get = fn($feld) => isset($mapping[$feld]) && isset($zeile[$mapping[$feld]]) ? trim($zeile[$mapping[$feld]]) : '';

        $kaeufer = $get('kaeufer') ?: 'eBay-Käufer';
        $artikel = $get('artikel') ?: '—';
        $menge = (int)($get('menge') ?: 1);
        $preis_roh = str_replace(',', '.', preg_replace('/[^0-9,\.]/', '', $get('preis')));
        $preis = (float)$preis_roh;
        $datum_roh = $get('datum');
        $datum = $datum_roh ? ebay_datum_parsen($datum_roh) : date('Y-m-d H:i:s');

        $produkt_id = (int)($produkt_zuordnung[$i] ?? 0);
        $produkt_id = $produkt_id > 0 ? $produkt_id : null;

        $pdo->prepare("
            INSERT INTO bestellungen
                (ist_test, kanal, status, kaeufer_name, produkt_id, artikel_name, menge,
                 verkaufspreis_brutto, bestelldatum, versendet_am)
            VALUES (0, 'ebay', 'versendet', ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$kaeufer, $produkt_id, $artikel, $menge, $preis ?: null, $datum, $datum]);

        $importiert++;

        // Nur wenn ein Produkt zugeordnet wurde, zählt der Verkauf auch für
        // Lager/Umsatz-Statistiken mit (braucht eine gültige produkt_id)
        if ($produkt_id) {
            $pdo->prepare("
                INSERT INTO lager_bewegungen
                    (produkt_id, typ, menge, verkaufskanal, verkaufspreis_brutto, bestellnummer, notiz, erfasst_von, bewegt_am)
                VALUES (?, 'abgang_verkauf', ?, 'ebay', ?, ?, 'Historischer Import aus eBay-CSV', 'qaf', ?)
            ")->execute([$produkt_id, $menge, $preis ?: null, 'HIST-' . uniqid(), date('Y-m-d', strtotime($datum))]);
            $mit_lagerbewegung++;
        }
    }

    unset($_SESSION['import_csv_header'], $_SESSION['import_csv_zeilen'], $_SESSION['import_mapping']);

    $ergebnis_text = "{$importiert} Bestellung(en) importiert, davon {$mit_lagerbewegung} mit Produkt-Zuordnung (zählen für Umsatz/Lager).";
}

// ── SCHRITT 2: SPALTEN ZUORDNEN (Formular abgeschickt) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schritt === '2') {
    $_SESSION['import_mapping'] = [
        'datum' => $_POST['map_datum'] !== '' ? (int)$_POST['map_datum'] : null,
        'kaeufer' => $_POST['map_kaeufer'] !== '' ? (int)$_POST['map_kaeufer'] : null,
        'artikel' => $_POST['map_artikel'] !== '' ? (int)$_POST['map_artikel'] : null,
        'sku' => $_POST['map_sku'] !== '' ? (int)$_POST['map_sku'] : null,
        'menge' => $_POST['map_menge'] !== '' ? (int)$_POST['map_menge'] : null,
        'preis' => $_POST['map_preis'] !== '' ? (int)$_POST['map_preis'] : null,
    ];
}

$produkte = $pdo->query("SELECT id, name, ebay_sku FROM lager_produkte WHERE aktiv = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$sku_zu_produkt = [];
foreach ($produkte as $p) {
    if ($p['ebay_sku']) $sku_zu_produkt[$p['ebay_sku']] = $p['id'];
}

$vorschau_zeilen = [];
if (isset($_SESSION['import_mapping'], $_SESSION['import_csv_zeilen']) && empty($ergebnis_text)) {
    $mapping = $_SESSION['import_mapping'];
    foreach ($_SESSION['import_csv_zeilen'] as $i => $zeile) {
        $get = fn($feld) => $mapping[$feld] !== null && isset($zeile[$mapping[$feld]]) ? trim($zeile[$mapping[$feld]]) : '';
        $sku = $get('sku');
        $vorschau_zeilen[] = [
            'index' => $i,
            'datum' => $get('datum'),
            'kaeufer' => $get('kaeufer'),
            'artikel' => $get('artikel'),
            'menge' => $get('menge') ?: 1,
            'preis' => $get('preis'),
            'sku' => $sku,
            'matched_produkt_id' => $sku_zu_produkt[$sku] ?? null,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Historie importieren — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8; --green: #4ADE80; --orange: #FBBF24;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }

  .main { max-width:1000px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:22px; font-weight:700; color:#F5F6FA; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); margin-bottom:24px; }

  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:22px; margin-bottom:20px; border-radius:20px; }
  .panel-title { font-family:var(--mono); font-size:11px; color:var(--blue-bright); margin-bottom:16px; }
  .hinweis-liste { font-size:13px; line-height:1.8; color:var(--text-dim); margin-bottom:18px; }
  .hinweis-liste strong { color:var(--text); }

  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:6px; display:block; }
  .field-input, .field-select { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--sans); font-size:13px; padding:10px 12px; outline:none; margin-bottom:14px; border-radius:12px; }
  .btn { font-family:var(--mono); font-size:11px; padding:11px 20px; cursor:pointer; border:1px solid; background:none; border-radius:12px; }
  .btn-primary { color:var(--blue-bright); border-color:rgba(148,148,255,0.4); }
  .btn-primary:hover { background:rgba(148,148,255,0.1); }

  .mapping-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }

  table { width:100%; border-collapse:collapse; font-size:12px; }
  th { background:rgba(20,22,31,0.8); text-align:left; padding:8px 10px; font-family:var(--mono); font-size:9px; color:var(--text-dim); }
  td { padding:8px 10px; border-bottom:1px solid rgba(124,124,255,0.1); }
  select.mini-select { background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-size:11px; padding:5px 8px; }
  .matched { color:var(--green); font-family:var(--mono); font-size:9px; }
  .unmatched { color:var(--orange); font-family:var(--mono); font-size:9px; }

  .ergebnis-box { background:rgba(74,222,128,0.1); border:1px solid rgba(74,222,128,0.4); padding:18px; font-size:13px; color:var(--green); }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

  <a href="import_historie.php?reset=1" class="back-link">↻ Neu starten</a>
</div>

<div class="main">
  <div class="page-title">Alte eBay-Bestellungen importieren</div>
  <div class="page-sub">CSV-Export aus eurem eBay Seller Hub</div>

  <?php if (!empty($ergebnis_text)): ?>
    <div class="ergebnis-box">✓ Import abgeschlossen: <?= htmlspecialchars($ergebnis_text) ?></div>
    <div style="margin-top:16px;"><a href="import_historie.php" class="btn btn-primary" style="text-decoration:none; display:inline-block;">Weitere Datei importieren</a> &nbsp; <a href="bestellungen.php" class="back-link">Zu den Bestellungen →</a></div>

  <?php elseif (!empty($vorschau_zeilen)): ?>
    <!-- SCHRITT 3: VORSCHAU + PRODUKT-ZUORDNUNG -->
    <div class="panel">
      <div class="panel-title">Vorschau — <?= count($vorschau_zeilen) ?> Zeilen gefunden</div>
      <div class="hinweis-liste">Grün = automatisch per hinterlegter eBay-Artikel-ID/SKU erkannt. Orange = bitte manuell zuordnen (oder "Kein Produkt" lassen — zählt dann nur in der Historie, nicht in Umsatz/Lager).</div>
      <form method="POST" action="import_historie.php">
        <input type="hidden" name="schritt" value="3">
        <div style="max-height:500px; overflow-y:auto;">
        <table>
          <thead><tr><th>Datum</th><th>Käufer</th><th>Artikel</th><th>Menge</th><th>Preis</th><th>Produkt-Zuordnung</th></tr></thead>
          <tbody>
            <?php foreach ($vorschau_zeilen as $z): ?>
            <tr>
              <td><?= htmlspecialchars($z['datum']) ?></td>
              <td><?= htmlspecialchars($z['kaeufer']) ?></td>
              <td><?= htmlspecialchars($z['artikel']) ?></td>
              <td><?= htmlspecialchars($z['menge']) ?></td>
              <td><?= htmlspecialchars($z['preis']) ?></td>
              <td>
                <select class="mini-select" name="produkt[<?= $z['index'] ?>]">
                  <option value="0">— Kein Produkt —</option>
                  <?php foreach ($produkte as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $z['matched_produkt_id'] == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="<?= $z['matched_produkt_id'] ? 'matched' : 'unmatched' ?>">
                  <?= $z['matched_produkt_id'] ? '✓ automatisch erkannt' : ($z['sku'] ? '⚠ SKU „' . htmlspecialchars($z['sku']) . '“ unbekannt' : '⚠ keine SKU in CSV') ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:16px;">✓ Jetzt importieren</button>
      </form>
    </div>

  <?php elseif (isset($_SESSION['import_csv_header'])): ?>
    <!-- SCHRITT 2: SPALTEN ZUORDNEN -->
    <div class="panel">
      <div class="panel-title">Spalten zuordnen</div>
      <div class="hinweis-liste">Die Felder sind schon automatisch anhand eurer Spaltennamen vorausgefüllt — bitte trotzdem kurz prüfen, ob's stimmt, dann ggf. korrigieren.</div>
      <form method="POST" action="import_historie.php">
        <input type="hidden" name="schritt" value="2">
        <div class="mapping-grid">
          <?php
          $felder = [
              'map_datum' => 'Verkaufsdatum',
              'map_kaeufer' => 'Käufername',
              'map_artikel' => 'Artikel-Titel',
              'map_sku' => 'SKU / Artikel-ID (für automatische Zuordnung)',
              'map_menge' => 'Menge',
              'map_preis' => 'Verkaufspreis',
          ];
          foreach ($felder as $feld_name => $label):
          ?>
          <div>
            <label class="field-label"><?= $label ?></label>
            <select class="field-select" name="<?= $feld_name ?>">
              <option value="">— keine —</option>
              <?php foreach ($_SESSION['import_csv_header'] as $idx => $spalte): ?>
                <option value="<?= $idx ?>" <?= ($auto_mapping[$feld_name] ?? null) === $idx ? 'selected' : '' ?>><?= htmlspecialchars($spalte) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary">Weiter zur Vorschau →</button>
      </form>
    </div>

  <?php else: ?>
    <!-- SCHRITT 1: UPLOAD -->
    <div class="panel">
      <div class="panel-title">So exportierst du die Datei bei eBay</div>
      <div class="hinweis-liste">
        1. eBay Seller Hub öffnen → <strong>„Bestellungen" / „Orders"</strong><br>
        2. Zeitraum wählen (z. B. „Letztes Jahr" oder benutzerdefiniert)<br>
        3. Auf <strong>„Als CSV exportieren" / „Export to CSV"</strong> klicken<br>
        4. Heruntergeladene Datei hier hochladen
      </div>
      <form method="POST" action="import_historie.php" enctype="multipart/form-data">
        <input type="hidden" name="schritt" value="1">
        <label class="field-label">CSV-Datei</label>
        <input class="field-input" type="file" name="csv" accept=".csv" required>
        <button type="submit" class="btn btn-primary">Hochladen &amp; weiter →</button>
      </form>
    </div>
  <?php endif; ?>
</div>

</div>
</body>
</html>
