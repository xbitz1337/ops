<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Dokumenten-Generator
 * Nimmt Formulardaten entgegen, erzeugt ein PDF im NA-Commerce-Briefkopf-Stil,
 * speichert es in der Historie und bietet es zum Download an.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Nur POST erlaubt.');
}

$pdo = db();

$empfaenger      = trim($_POST['empfaenger'] ?? '');
$ort             = trim($_POST['ort'] ?? 'Bremen');
$datum           = $_POST['datum'] ?? date('Y-m-d');
$betreff         = trim($_POST['betreff'] ?? '');
$brieftext       = trim($_POST['brieftext'] ?? '');
$unterzeichner   = trim($_POST['unterzeichner'] ?? '');
$unterzeichner_titel = trim($_POST['unterzeichner_titel'] ?? '');
$dokument_id     = (int)($_POST['dokument_id'] ?? 0); // falls gesetzt: bestehendes Dokument aktualisieren statt neu anlegen
$verfasser       = $_POST['verfasser'] ?? 'artis';
$signatur_modus  = $_POST['signatur_modus'] ?? 'keine'; // keine | zeichnen | gespeichert
$signatur_data   = trim($_POST['signatur_data'] ?? ''); // base64 PNG aus dem Pad, falls Modus "zeichnen"
$ort_datum_inline = ($_POST['ort_datum_inline'] ?? '0') === '1'; // Ort+Datum in dieselbe Zeile wie die Unterschriften (z.B. Darlehensvertrag)

if (!in_array($verfasser, ['artis', 'nour', 'beide'])) $verfasser = 'artis';
if (!in_array($signatur_modus, ['keine', 'zeichnen', 'gespeichert'])) $signatur_modus = 'keine';

$datum_formatiert = date('d.m.Y', strtotime($datum));

// Logo & gespeicherte Unterschriften laden (gleiche robuste .png/.jpg/.jpeg-Erkennung)
$logo_dir = __DIR__ . '/../assets/';
$find_asset = function (string $basisname) use ($logo_dir): ?array {
    if (!is_dir($logo_dir)) return null;
    foreach (scandir($logo_dir) as $datei) {
        $ohne_endung = pathinfo($datei, PATHINFO_FILENAME);
        $endung = strtolower(pathinfo($datei, PATHINFO_EXTENSION));
        if (strtolower($ohne_endung) === strtolower($basisname) && in_array($endung, ['png','jpg','jpeg'])) {
            $mime = $endung === 'png' ? 'image/png' : 'image/jpeg';
            return ['pfad' => $logo_dir . $datei, 'mime' => $mime];
        }
    }
    return null;
};
$lade_asset_base64 = function (string $basisname) use ($find_asset): string {
    $g = $find_asset($basisname);
    return $g ? 'data:' . $g['mime'] . ';base64,' . base64_encode(file_get_contents($g['pfad'])) : '';
};

$na_logo_base64 = $lade_asset_base64('na-commerce-logo');

// Unterschrift-Blöcke fürs Template zusammenstellen — je nach gewähltem Modus
$unterschrift_bloecke = [];

if ($signatur_modus === 'zeichnen' && !empty($signatur_data) && str_starts_with($signatur_data, 'data:image/png;base64,')) {
    $unterschrift_bloecke[] = ['bild' => $signatur_data, 'name' => $unterzeichner, 'titel' => $unterzeichner_titel];
} elseif ($signatur_modus === 'gespeichert') {
    if ($verfasser === 'beide') {
        $unterschrift_bloecke[] = ['bild' => $lade_asset_base64('unterschrift-artis'), 'name' => 'Artis Freibergs', 'titel' => 'Geschäftsführer'];
        $unterschrift_bloecke[] = ['bild' => $lade_asset_base64('unterschrift-nour'), 'name' => 'Mhd Nour Shaaban', 'titel' => 'Geschäftsführer'];
    } else {
        $bild = $lade_asset_base64('unterschrift-' . $verfasser);
        $unterschrift_bloecke[] = ['bild' => $bild, 'name' => $unterzeichner, 'titel' => $unterzeichner_titel];
    }
} else {
    // Keine Unterschrift — Freiraum zum handschriftlichen Unterschreiben
    $unterschrift_bloecke[] = ['bild' => '', 'name' => $unterzeichner, 'titel' => $unterzeichner_titel];
}

$signatur_vorhanden = $signatur_modus !== 'keine';

ob_start();
include __DIR__ . '/document_template.php';
$html = ob_get_clean();

$options = new Options();
$options->set('defaultFont', 'Helvetica');
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Prüfen: bearbeiten wir ein bestehendes Dokument, oder legen wir ein neues an?
$bestehendes_dokument = null;
if ($dokument_id > 0) {
    $check = $pdo->prepare("SELECT dateipfad FROM dokumente WHERE id = :id");
    $check->execute([':id' => $dokument_id]);
    $bestehendes_dokument = $check->fetch(PDO::FETCH_ASSOC);
}

$betreff_slug = $betreff !== '' ? preg_replace('/[^a-zA-Z0-9]+/', '-', $betreff) : 'Dokument';
$betreff_slug = trim($betreff_slug, '-');

if ($bestehendes_dokument) {
    // AKTUALISIEREN: alte Datei entfernen, neue unter neuem Namen im aktuellen
    // Monatsordner ablegen (Betreff kann sich geändert haben) und DB-Zeile updaten.
    $alte_datei = __DIR__ . '/' . $bestehendes_dokument['dateipfad'];
    if (file_exists($alte_datei)) {
        unlink($alte_datei);
    }

    $jahr = date('Y');
    $monat = date('m');
    $relativer_ordner = "documents/{$jahr}/{$monat}";
    $absoluter_ordner = __DIR__ . '/' . $relativer_ordner;
    if (!is_dir($absoluter_ordner)) {
        mkdir($absoluter_ordner, 0755, true);
    }
    $dateiname = 'NA-Commerce_' . $betreff_slug . '_' . date('Ymd_His') . '.pdf';
    $dateipfad = "{$relativer_ordner}/{$dateiname}";
    file_put_contents($absoluter_ordner . '/' . $dateiname, $dompdf->output());

    $stmt = $pdo->prepare("
        UPDATE dokumente SET
            verfasser = :verfasser, empfaenger = :empfaenger, betreff = :betreff,
            brieftext = :brieftext, ort = :ort, dokument_datum = :datum,
            hat_unterschrift = :hat_unterschrift, unterzeichner = :unterzeichner,
            unterzeichner_titel = :unterzeichner_titel, dateiname = :dateiname,
            dateipfad = :dateipfad, erstellt_am = erstellt_am
        WHERE id = :id
    ");
    $stmt->execute([
        ':verfasser' => $verfasser,
        ':empfaenger' => $empfaenger,
        ':betreff' => $betreff,
        ':brieftext' => $brieftext,
        ':ort' => $ort,
        ':datum' => $datum,
        ':hat_unterschrift' => $signatur_vorhanden ? 1 : 0,
        ':unterzeichner' => $unterzeichner,
        ':unterzeichner_titel' => $unterzeichner_titel,
        ':dateiname' => $dateiname,
        ':dateipfad' => $dateipfad,
        ':id' => $dokument_id,
    ]);

    header('Location: document_result.php?id=' . $dokument_id);
    exit;
}

// NEU ANLEGEN: In Historie ablegen unter documents/JAHR/MONAT/Dateiname.pdf
$jahr = date('Y');
$monat = date('m');
$relativer_ordner = "documents/{$jahr}/{$monat}";
$absoluter_ordner = __DIR__ . '/' . $relativer_ordner;
if (!is_dir($absoluter_ordner)) {
    mkdir($absoluter_ordner, 0755, true);
}

$dateiname = 'NA-Commerce_' . $betreff_slug . '_' . date('Ymd_His') . '.pdf';
$dateipfad = "{$relativer_ordner}/{$dateiname}";

file_put_contents($absoluter_ordner . '/' . $dateiname, $dompdf->output());

$stmt = $pdo->prepare("
    INSERT INTO dokumente (verfasser, empfaenger, betreff, brieftext, ort, dokument_datum, hat_unterschrift, unterzeichner, unterzeichner_titel, dateiname, dateipfad)
    VALUES (:verfasser, :empfaenger, :betreff, :brieftext, :ort, :datum, :hat_unterschrift, :unterzeichner, :unterzeichner_titel, :dateiname, :dateipfad)
");
$stmt->execute([
    ':verfasser' => $verfasser,
    ':empfaenger' => $empfaenger,
    ':betreff' => $betreff,
    ':brieftext' => $brieftext,
    ':ort' => $ort,
    ':datum' => $datum,
    ':hat_unterschrift' => $signatur_vorhanden ? 1 : 0,
    ':unterzeichner' => $unterzeichner,
    ':unterzeichner_titel' => $unterzeichner_titel,
    ':dateiname' => $dateiname,
    ':dateipfad' => $dateipfad,
]);

$neue_id = (int)$pdo->lastInsertId();

// Nicht direkt herunterladen — zur Ergebnis-Seite mit Ansehen/Download-Wahl
header('Location: document_result.php?id=' . $neue_id);
exit;