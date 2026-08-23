<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Lager-Modul v2
 * Zentrale Funktion zur PDF-Erzeugung. Wird sowohl vom Cron-Job (automatisch
 * am 1. des Monats) als auch vom manuellen "Report generieren"-Button genutzt,
 * damit beide Wege exakt das gleiche PDF erzeugen und in der Historie landen.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Wareneinsatz & Rohertrag je Produkt im Zeitraum — für den erweiterten
 * Lagerreport. Nutzt den gewichteten Durchschnitts-EK (Stand Ende Zeitraum)
 * als Näherung für die Warenkosten der verkauften Stücke (keine exakte
 * FIFO-Zuordnung je Einzelverkauf, aber für die Praxis ausreichend genau).
 */
function lager_wareneinsatz_zeitraum(PDO $pdo, string $von, string $bis): array
{
    // Wareneinsatz kommt jetzt direkt aus den beim Verkauf exakt gespeicherten
    // FIFO-Stückkosten je Bewegung — kein Durchschnitt mehr nötig.
    $sql = "
        SELECT p.id AS produkt_id, p.name, k.name AS kategorie,
               SUM(b.menge) AS menge_verkauft,
               SUM(b.menge * COALESCE(b.verkaufspreis_brutto, 0)) AS umsatz_brutto,
               SUM(b.menge * COALESCE(b.ek_preis_netto, 0)) AS wareneinsatz,
               SUM(b.menge * COALESCE(b.ek_preis_netto, 0)) / NULLIF(SUM(b.menge), 0) AS ek_stueck
        FROM lager_bewegungen b
        JOIN lager_produkte p ON p.id = b.produkt_id
        JOIN lager_kategorien k ON k.id = p.kategorie_id
        WHERE b.typ = 'abgang_verkauf' AND b.bewegt_am BETWEEN :von AND :bis AND p.aktiv = 1
        GROUP BY p.id, p.name, k.name
        ORDER BY k.sortierung, p.name
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':von' => $von, ':bis' => $bis]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['umsatz_netto'] = $row['umsatz_brutto'] / 1.19;
        $row['rohertrag_netto'] = $row['umsatz_netto'] - $row['wareneinsatz'];
    }
    unset($row);

    return $rows;
}

/**
 * Erzeugt den PDF-Report, speichert ihn auf der Platte und trägt ihn in
 * lager_reports ein. Gibt den vollständigen Datensatz zurück.
 *
 * @param string      $von              'YYYY-MM-DD' — Beginn des Zeitraums (für Bewegungs-Summen)
 * @param string      $bis              'YYYY-MM-DD' — Ende des Zeitraums = Stichtag für den Bestand
 * @param string|null $kategorie_filter z.B. 'Wärmflasche', oder null = alle
 * @param string      $erzeugt_durch    'automatisch' | 'manuell'
 */
function lager_report_generieren(
    PDO $pdo,
    string $von,
    string $bis,
    ?string $kategorie_filter = null,
    string $erzeugt_durch = 'manuell'
): array {
    $stichtag = $bis; // Bestand wird zum Ende des gewählten Zeitraums ausgewiesen

    $bestand = lager_bestand_zum_stichtag($pdo, $stichtag);
    if ($kategorie_filter) {
        $bestand = array_values(array_filter(
            $bestand,
            fn($r) => stripos($r['kategorie'], $kategorie_filter) !== false
        ));
    }
    $bewegungen = lager_bewegungen_zeitraum($pdo, $von, $bis);

    $wareneinsatz_zeilen = lager_wareneinsatz_zeitraum($pdo, $von, $bis);
    if ($kategorie_filter) {
        $wareneinsatz_zeilen = array_values(array_filter(
            $wareneinsatz_zeilen,
            fn($r) => stripos($r['kategorie'], $kategorie_filter) !== false
        ));
    }
    $wareneinsatz_gesamt = array_sum(array_column($wareneinsatz_zeilen, 'wareneinsatz'));
    $umsatz_gesamt_netto = array_sum(array_column($wareneinsatz_zeilen, 'umsatz_netto'));
    $rohertrag_gesamt = array_sum(array_column($wareneinsatz_zeilen, 'rohertrag_netto'));

    $gesamt_ek_wert = 0;
    foreach ($bestand as $row) {
        $gesamt_ek_wert += $row['bestand_stueck'] * $row['ek_preis_netto'];
    }

    $verkaeufe_je_kanal = ['amazon' => 0, 'ebay' => 0, 'tiktok' => 0, 'sonstiges' => 0];
    $retouren_anzahl = 0;
    $eigenbedarf_anzahl = 0;
    foreach ($bewegungen as $b) {
        if ($b['typ'] === 'abgang_verkauf') {
            $verkaeufe_je_kanal[$b['verkaufskanal']] += (int)$b['menge'];
        } elseif ($b['typ'] === 'retoure') {
            $retouren_anzahl += (int)$b['menge'];
        } elseif ($b['typ'] === 'abgang_eigenbedarf') {
            $eigenbedarf_anzahl += (int)$b['menge'];
        }
    }

    $nach_kategorie = [];
    foreach ($bestand as $row) {
        $nach_kategorie[$row['kategorie']][] = $row;
    }

    // Kategorie-Name IST direkt die Marken-Gruppe — CozyCore-Branding greift,
    // wenn die Kategorie "CozyCore" heißt (kein Namens-Rateraten mehr nötig)
    $enthaelt_cozycore = false;
    foreach ($bestand as $row) {
        if ($row['kategorie'] === 'CozyCore' && $row['bestand_stueck'] != 0) {
            $enthaelt_cozycore = true;
            break;
        }
    }
    if ($kategorie_filter === 'CozyCore') {
        $enthaelt_cozycore = true;
    }
    $enthaelt_waermflasche = $enthaelt_cozycore; // Variablenname beibehalten, Rest der Datei bleibt unverändert

    $logo_dir = __DIR__ . '/../assets/';

    // Sucht die Logo-Datei unabhängig von Endung (.png/.jpg/.jpeg) und
    // Groß-/Kleinschreibung, damit ein falscher Dateiname nicht zum stillen
    // Fehlen des Logos führt.
    $find_logo = function (string $basisname) use ($logo_dir): ?array {
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

    $logo = function (string $basisname) use ($find_logo): string {
        $gefunden = $find_logo($basisname);
        if (!$gefunden) return '';
        return 'data:' . $gefunden['mime'] . ';base64,' . base64_encode(file_get_contents($gefunden['pfad']));
    };

    $na_logo_base64 = $logo('na-commerce-logo');
    $cozycore_logo_base64 = $enthaelt_waermflasche ? $logo('cozycore-logo') : '';

    $akzent = $enthaelt_waermflasche ? '#A85C32' : '#1D1D1F';
    $akzent_hell = $enthaelt_waermflasche ? '#EADFD3' : '#E5E5E7';
    $akzent_bg = $enthaelt_waermflasche ? '#FBF6EF' : '#F5F5F7';

    $stichtag_formatiert = date('d.m.Y', strtotime($bis));
    $zeitraum_von_formatiert = date('d.m.Y', strtotime($von));
    $zeitraum_bis_formatiert = date('d.m.Y', strtotime($bis));
    $erstellt_formatiert = date('d.m.Y, H:i');

    ob_start();
    include __DIR__ . '/report_template.php'; // reines HTML/CSS-Template, siehe unten
    $html = ob_get_clean();

    $options = new Options();
    $options->set('defaultFont', 'Helvetica');
    $options->set('isRemoteEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Ablage: reports/JAHR/MONAT/Dateiname.pdf (Jahr/Monat vom Ende des Zeitraums)
    $jahr = date('Y', strtotime($bis));
    $monat = date('m', strtotime($bis));
    // Automatische Reports landen in einem eigenen Ordner, getrennt von manuellen —
    // damit sie auch auf Dateisystem-Ebene klar als "geschützte Jahresbasis" erkennbar sind.
    $unterordner = $erzeugt_durch === 'automatisch' ? 'reports/automatisch' : 'reports/manuell';
    $relativer_ordner = "{$unterordner}/{$jahr}/{$monat}";
    $absoluter_ordner = __DIR__ . '/' . $relativer_ordner;
    if (!is_dir($absoluter_ordner)) {
        mkdir($absoluter_ordner, 0755, true);
    }

    $praefix = $kategorie_filter ? str_replace(' ', '', $kategorie_filter) : 'Gesamt';
    $dateiname = "Lagerreport_{$praefix}_" . str_replace('-', '', $von) . '_bis_' . str_replace('-', '', $bis) . '.pdf';
    $dateipfad = "{$relativer_ordner}/{$dateiname}";

    file_put_contents($absoluter_ordner . '/' . $dateiname, $dompdf->output());

    $stmt = $pdo->prepare("
        INSERT INTO lager_reports
            (stichtag, zeitraum_von, zeitraum_bis, kategorie_filter, dateiname,
             dateipfad, gesamt_ek_wert, erzeugt_durch)
        VALUES
            (:stichtag, :von, :bis, :kategorie, :dateiname, :dateipfad, :ek_wert, :erzeugt_durch)
    ");
    $stmt->execute([
        ':stichtag'      => $bis,
        ':von'           => $von,
        ':bis'           => $bis,
        ':kategorie'     => $kategorie_filter,
        ':dateiname'     => $dateiname,
        ':dateipfad'     => $dateipfad,
        ':ek_wert'       => $gesamt_ek_wert,
        ':erzeugt_durch' => $erzeugt_durch,
    ]);

    return [
        'id' => (int)$pdo->lastInsertId(),
        'dateiname' => $dateiname,
        'dateipfad' => $dateipfad,
        'gesamt_ek_wert' => $gesamt_ek_wert,
    ];
}
