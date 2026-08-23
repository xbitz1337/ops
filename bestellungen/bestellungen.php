<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('bestellungen');
$pdo = db();

$kanal_filter = $_GET['kanal'] ?? '';
$kanal_labels = ['ebay' => 'eBay', 'amazon' => 'Amazon', 'tiktok' => 'TikTok Shop', 'direktverkauf' => 'Direktverkauf'];

require_once __DIR__ . '/../lager/fifo_helper.php';
require_once __DIR__ . '/../verpackung_helper.php';

// ── API HANDLER ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // fifo_schaetzen und produkt_per_barcode sind reine Lesevorgänge (keine
    // Datenänderung) — brauchen nur Ansehen-Recht, alles andere Bearbeiten-Recht
    $mindest_level = in_array($action, ['fifo_schaetzen', 'produkt_per_barcode']) ? 'ansehen' : 'bearbeiten';
    if (!hat_modul_zugriff('bestellungen', $mindest_level)) {
        echo json_encode(['error' => 'Keine Berechtigung für diese Aktion']);
        exit;
    }

    if ($action === 'produkt_per_barcode') {
        $barcode = trim($_POST['barcode'] ?? '');
        if (!$barcode) { echo json_encode(['error' => 'Kein Barcode']); exit; }
        $stmt = $pdo->prepare("SELECT id, name FROM lager_produkte WHERE barcode = ? AND aktiv = 1 LIMIT 1");
        $stmt->execute([$barcode]);
        $produkt = $stmt->fetch();
        if (!$produkt) { echo json_encode(['error' => 'Kein Produkt mit diesem Barcode gefunden']); exit; }
        echo json_encode(['success' => true, 'produkt_id' => $produkt['id'], 'produkt_name' => $produkt['name']]);
        exit;
    }

    if ($action === 'bestellung_annehmen') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE bestellungen SET status = 'zu_verpacken', angenommen_am = NOW() WHERE id = ? AND status = 'neu'")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'fifo_schaetzen') {
        // Live-Vorschau für die Gewinn-Berechnung im Direktverkauf-Formular —
        // exakte FIFO-Stückkosten, bevor der Verkauf überhaupt gebucht wird
        $produkt_id = (int)($_POST['produkt_id'] ?? 0);
        $menge = max(1, (int)($_POST['menge'] ?? 1));
        $fallback = (float)($pdo->query("SELECT ek_preis_netto FROM lager_produkte WHERE id = $produkt_id")->fetchColumn() ?: 0);
        $stueckkosten = fifo_stueckkosten_berechnen($pdo, $produkt_id, $menge, $fallback);
        echo json_encode(['stueckkosten' => $stueckkosten]);
        exit;
    }

    if ($action === 'direktverkauf_abschliessen') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM bestellungen WHERE id = ? AND status = 'neu' AND kanal = 'direktverkauf'");
        $stmt->execute([$id]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b) { echo json_encode(['error' => 'Bestellung nicht gefunden']); exit; }

        $pdo->prepare("UPDATE bestellungen SET status = 'versendet', versendet_am = NOW() WHERE id = ?")->execute([$id]);

        $gewinn_info = null;
        if ($b['produkt_id']) {
            // Exakte FIFO-Stückkosten JETZT berechnen (vor dem Einfügen der
            // eigenen Bewegung!) und direkt mit auf der Bewegung speichern —
            // dann ist der Wareneinsatz für diesen Verkauf für immer exakt
            // dokumentiert, kein Nachrechnen mit Durchschnitt mehr nötig.
            $fallback_stmt = $pdo->prepare("SELECT ek_preis_netto FROM lager_produkte WHERE id = ?");
            $fallback_stmt->execute([$b['produkt_id']]);
            $fallback = (float)($fallback_stmt->fetchColumn() ?: 0);
            $fifo_stueckkosten = fifo_stueckkosten_berechnen($pdo, $b['produkt_id'], $b['menge'], $fallback);

            $pdo->prepare("
                INSERT INTO lager_bewegungen
                    (produkt_id, typ, menge, ek_preis_netto, verkaufskanal, verkaufspreis_brutto, bestellnummer, notiz, erfasst_von, bewegt_am)
                VALUES (?, 'abgang_verkauf', ?, ?, 'direktverkauf', ?, ?, ?, 'qaf', CURDATE())
            ")->execute([
                $b['produkt_id'], $b['menge'], $fifo_stueckkosten, $b['verkaufspreis_brutto'],
                'DIREKT-' . $b['id'],
                'Direktverkauf an ' . $b['kaeufer_name'],
            ]);

            // Verpackungsmaterial laut Rezept automatisch mit abziehen
            verpackung_abziehen($pdo, $b['produkt_id'], $b['menge'], 'DIREKT-' . $b['id'], 'qaf');

            // Reingewinn mit den EXAKTEN FIFO-Kosten berechnen (nicht Durchschnitt)
            $vk_netto = ($b['verkaufspreis_brutto'] ?? 0) / 1.19;
            $wareneinsatz = $fifo_stueckkosten * $b['menge'];
            $rohertrag = $vk_netto - $wareneinsatz;
            $ruecklage = max(0, $rohertrag * 0.30);
            $reingewinn = $rohertrag - $ruecklage;

            $gewinn_info = [
                'vk_netto' => round($vk_netto, 2),
                'wareneinsatz' => round($wareneinsatz, 2),
                'rohertrag' => round($rohertrag, 2),
                'ruecklage' => round($ruecklage, 2),
                'reingewinn' => round($reingewinn, 2),
            ];
        }

        echo json_encode(['success' => true, 'gewinn' => $gewinn_info]);
        exit;
    }

    if ($action === 'dpd_label_erstellen') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM bestellungen WHERE id = ? AND status = 'zu_verpacken'");
        $stmt->execute([$id]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b) { echo json_encode(['error' => 'Bestellung nicht gefunden oder falscher Status']); exit; }

        require_once __DIR__ . '/../dpd/dpd_api.php';
        $ergebnis = dpd_label_erstellen($b);

        if ($ergebnis['erfolgreich']) {
            $pdo->prepare("UPDATE bestellungen SET status = 'versand_vorbereitet', label_erstellt_am = NOW(), sendungsnummer = ? WHERE id = ?")
                ->execute([$ergebnis['sendungsnummer'], $id]);
        }

        echo json_encode($ergebnis);
        exit;
    }

    if ($action === 'label_erstellt') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE bestellungen SET status = 'versand_vorbereitet', label_erstellt_am = NOW() WHERE id = ? AND status = 'zu_verpacken'")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'versand_abschliessen') {
        $id = (int)($_POST['id'] ?? 0);
        $sendungsnummer = trim($_POST['sendungsnummer'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM bestellungen WHERE id = ? AND status = 'versand_vorbereitet'");
        $stmt->execute([$id]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b) { echo json_encode(['error' => 'Bestellung nicht gefunden oder falscher Status']); exit; }

        $pdo->prepare("UPDATE bestellungen SET status = 'versendet', versendet_am = NOW(), sendungsnummer = ? WHERE id = ?")
            ->execute([$sendungsnummer ?: null, $id]);

        // Bei echten (nicht Test-) eBay-Bestellungen die Sendungsnummer direkt
        // an eBay zurückmelden, damit der Käufer sie in seinem Account sieht
        if (!$b['ist_test'] && $b['kanal'] === 'ebay' && $sendungsnummer && $b['externe_order_id']) {
            require_once __DIR__ . '/channel_apis.php';
            ebay_tracking_zurueckmelden($b['externe_order_id'], $sendungsnummer);
        }

        // Automatische Lager-Bewegung buchen, falls das Produkt zugeordnet ist
        if ($b['produkt_id']) {
            $fallback_stmt = $pdo->prepare("SELECT ek_preis_netto FROM lager_produkte WHERE id = ?");
            $fallback_stmt->execute([$b['produkt_id']]);
            $fallback = (float)($fallback_stmt->fetchColumn() ?: 0);
            $fifo_stueckkosten = fifo_stueckkosten_berechnen($pdo, $b['produkt_id'], $b['menge'], $fallback);

            $pdo->prepare("
                INSERT INTO lager_bewegungen
                    (produkt_id, typ, menge, ek_preis_netto, verkaufskanal, verkaufspreis_brutto, bestellnummer, notiz, erfasst_von, bewegt_am)
                VALUES
                    (?, 'abgang_verkauf', ?, ?, ?, ?, ?, ?, 'qaf', CURDATE())
            ")->execute([
                $b['produkt_id'],
                $b['menge'],
                $fifo_stueckkosten,
                $b['kanal'],
                $b['verkaufspreis_brutto'],
                $b['externe_order_id'] ?: ('TEST-' . $b['id']),
                $b['ist_test'] ? 'Automatisch aus Test-Bestellung #' . $b['id'] : 'Automatisch aus ' . ucfirst($b['kanal']) . '-Bestellung',
            ]);

            // Verpackungsmaterial nur bei ECHTEN Verkäufen abziehen, nicht bei Test-Bestellungen
            if (!$b['ist_test']) {
                verpackung_abziehen($pdo, $b['produkt_id'], $b['menge'], $b['externe_order_id'] ?: ('TEST-' . $b['id']), 'qaf');
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'bestellung_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM bestellungen WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['ist_test'] !== 1) {
            echo json_encode(['error' => 'Nur Test-Bestellungen können gelöscht werden']);
            exit;
        }

        // Falls beim Versand bereits eine Lager-Bewegung automatisch gebucht
        // wurde (Bestellnummer 'TEST-<id>'), diese mitentfernen — sonst bleibt
        // der Verkauf im Lager/Umsatz-Modul sichtbar, obwohl die Bestellung
        // gelöscht wurde.
        $pdo->prepare("
            DELETE FROM lager_bewegungen
            WHERE typ = 'abgang_verkauf' AND bestellnummer = ?
        ")->execute(['TEST-' . $id]);

        $pdo->prepare("DELETE FROM bestellungen WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'retoure_erfassen') {
        $id = (int)($_POST['id'] ?? 0);
        $menge = max(1, (int)($_POST['menge'] ?? 1));
        $grund = trim($_POST['grund'] ?? '');
        $wieder_verkaufsfaehig = (int)($_POST['wieder_verkaufsfaehig'] ?? 1);

        $stmt = $pdo->prepare("SELECT * FROM bestellungen WHERE id = ? AND status = 'versendet'");
        $stmt->execute([$id]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b) { echo json_encode(['error' => 'Bestellung nicht gefunden oder noch nicht versendet']); exit; }
        if (!$b['produkt_id']) { echo json_encode(['error' => 'Kein Produkt zugeordnet — Retoure kann nicht automatisch gebucht werden']); exit; }
        if ($menge > $b['menge']) { echo json_encode(['error' => 'Retoure-Menge größer als ursprüngliche Bestellmenge']); exit; }

        $pdo->prepare("
            INSERT INTO lager_bewegungen
                (produkt_id, typ, menge, verkaufskanal, retoure_grund, wieder_verkaufsfaehig, bestellnummer, notiz, erfasst_von, bewegt_am)
            VALUES
                (?, 'retoure', ?, ?, ?, ?, ?, ?, 'qaf', CURDATE())
        ")->execute([
            $b['produkt_id'], $menge, $b['kanal'], $grund ?: null, $wieder_verkaufsfaehig,
            $b['externe_order_id'] ?: ('TEST-' . $b['id']),
            'Retoure zu Bestellung #' . $b['id'] . ' (' . htmlspecialchars($b['kaeufer_name']) . ')',
        ]);

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

// ── DATEN LADEN ──────────────────────────────────────────────────────────────
function lade_spalte(PDO $pdo, string $status, string $kanal_filter, int $limit = 5): array {
    $kanal_bedingung = $kanal_filter !== '' ? "AND b.kanal = :kanal" : "";
    if ($status === 'versendet') {
        $sql = "
            SELECT b.*, p.name AS produkt_name, p.foto_url
            FROM bestellungen b
            LEFT JOIN lager_produkte p ON p.id = b.produkt_id
            WHERE b.status = 'versendet' AND b.versendet_am >= DATE_SUB(NOW(), INTERVAL 7 DAY) $kanal_bedingung
            ORDER BY b.versendet_am DESC
            LIMIT $limit
        ";
    } else {
        $sql = "
            SELECT b.*, p.name AS produkt_name, p.foto_url
            FROM bestellungen b
            LEFT JOIN lager_produkte p ON p.id = b.produkt_id
            WHERE b.status = :status $kanal_bedingung
            ORDER BY b.bestelldatum ASC
            LIMIT $limit
        ";
    }
    $stmt = $pdo->prepare($sql);
    if ($status !== 'versendet') $stmt->bindValue(':status', $status);
    if ($kanal_filter !== '') $stmt->bindValue(':kanal', $kanal_filter);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function zaehle_spalte(PDO $pdo, string $status, string $kanal_filter): int {
    $kanal_bedingung = $kanal_filter !== '' ? "AND kanal = :kanal" : "";
    if ($status === 'versendet') {
        $sql = "SELECT COUNT(*) FROM bestellungen WHERE status = 'versendet' AND versendet_am >= DATE_SUB(NOW(), INTERVAL 7 DAY) $kanal_bedingung";
        $stmt = $pdo->prepare($sql);
    } else {
        $sql = "SELECT COUNT(*) FROM bestellungen WHERE status = :status $kanal_bedingung";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':status', $status);
    }
    if ($kanal_filter !== '') $stmt->bindValue(':kanal', $kanal_filter);
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

$spalten = [];
$spalten_gesamt = [];
foreach (['neu', 'zu_verpacken', 'versand_vorbereitet', 'versendet'] as $s) {
    $spalten[$s] = lade_spalte($pdo, $s, $kanal_filter);
    $spalten_gesamt[$s] = zaehle_spalte($pdo, $s, $kanal_filter);
}

// Aktueller Lagerbestand je Produkt
$lager_bestand = [];
$bestand_rows = $pdo->query("
    SELECT p.id,
           COALESCE(SUM(CASE
               WHEN b.typ = 'zugang_einkauf' THEN b.menge
               WHEN b.typ = 'retoure' AND b.wieder_verkaufsfaehig = 1 THEN b.menge
               WHEN b.typ = 'korrektur' THEN b.menge
               WHEN b.typ IN ('abgang_verkauf','abgang_eigenbedarf') THEN -b.menge
               ELSE 0 END), 0) AS bestand
    FROM lager_produkte p
    LEFT JOIN lager_bewegungen b ON b.produkt_id = p.id
    GROUP BY p.id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($bestand_rows as $row) { $lager_bestand[$row['id']] = (int)$row['bestand']; }

// Offener Gesamtbedarf je Produkt (über alle Kanäle, nicht versendet)
$bedarf_je_produkt = [];
$bedarf_rows = $pdo->query("
    SELECT produkt_id, SUM(menge) AS bedarf
    FROM bestellungen
    WHERE status IN ('neu', 'zu_verpacken', 'versand_vorbereitet') AND produkt_id IS NOT NULL
    GROUP BY produkt_id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($bedarf_rows as $row) { $bedarf_je_produkt[$row['produkt_id']] = (int)$row['bedarf']; }

$produktnamen = [];
if (!empty($bedarf_je_produkt)) {
    $ids = implode(',', array_map('intval', array_keys($bedarf_je_produkt)));
    $namen_rows = $pdo->query("SELECT id, name FROM lager_produkte WHERE id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($namen_rows as $row) { $produktnamen[$row['id']] = $row['name']; }
}

$engpaesse = [];
foreach ($bedarf_je_produkt as $pid => $bedarf) {
    $bestand = $lager_bestand[$pid] ?? 0;
    if ($bedarf > $bestand) {
        $engpaesse[] = ['name' => $produktnamen[$pid] ?? '?', 'bedarf' => $bedarf, 'bestand' => $bestand];
    }
}

$niedrige_bestaende = [];
$alle_produkte_rows = $pdo->query("
    SELECT p.id, p.name,
           COALESCE(SUM(CASE
               WHEN b.typ = 'zugang_einkauf' THEN b.menge
               WHEN b.typ = 'retoure' AND b.wieder_verkaufsfaehig = 1 THEN b.menge
               WHEN b.typ = 'korrektur' THEN b.menge
               WHEN b.typ IN ('abgang_verkauf','abgang_eigenbedarf') THEN -b.menge
               ELSE 0 END), 0) AS bestand,
           COALESCE(SUM(CASE WHEN b.typ = 'zugang_einkauf' THEN b.menge ELSE 0 END), 0) AS insgesamt
    FROM lager_produkte p
    LEFT JOIN lager_bewegungen b ON b.produkt_id = p.id
    WHERE p.aktiv = 1
    GROUP BY p.id, p.name
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($alle_produkte_rows as $row) {
    $schwelle = max(5, (int)round($row['insgesamt'] * 0.10));
    if ($row['bestand'] > 0 && $row['bestand'] <= $schwelle) {
        $niedrige_bestaende[] = ['name' => $row['name'], 'bestand' => $row['bestand']];
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bestellungen — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #0f1923; --navy2: #152236; --blue-mid: #1e3a5f;
    --blue-accent: #2d6aad; --blue-bright: #4a9edd;
    --text: #c8dff0; --text-dim: #5a7a9a;
    --green: #2ecc71; --orange: #e67e22; --red: #e74c3c;
    --mono: 'Share Tech Mono', monospace; --sans: 'Exo 2', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(15,25,35,0.97); border-bottom:1px solid rgba(45,106,173,0.2); z-index:100; }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; letter-spacing:1px; }
  .back-link:hover { text-decoration:underline; }
  .topbar-title { font-size:14px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; }

  .main { max-width:1400px; margin:0 auto; padding:24px 20px 60px; }
  .page-title { font-size:22px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); letter-spacing:2px; margin-top:4px; margin-bottom:6px; }
  .test-hinweis { font-family:var(--mono); font-size:10px; color:var(--orange); margin-bottom:20px; }

  .kanal-tabs { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; }
  .kanal-tab { font-family:var(--mono); font-size:11px; letter-spacing:1px; padding:8px 16px; border:1px solid rgba(45,106,173,0.3); color:var(--text-dim); text-decoration:none; text-transform:uppercase; }
  .kanal-tab.active { background:rgba(74,158,221,0.15); border-color:var(--blue-bright); color:var(--blue-bright); }
  .kanal-tab:hover { border-color:var(--blue-bright); }

  .engpass-banner { background:rgba(231,76,60,0.1); border:1px solid rgba(231,76,60,0.4); padding:14px 16px; margin-bottom:20px; }
  .engpass-titel { font-family:var(--mono); font-size:11px; letter-spacing:1px; color:var(--red); margin-bottom:8px; font-weight:700; }
  .engpass-zeile { font-family:var(--mono); font-size:11px; color:var(--text); padding:2px 0; }
  .engpass-zeile strong { color:var(--red); }
  .niedrig-banner { background:rgba(230,126,34,0.1); border-color:rgba(230,126,34,0.4); }
  .niedrig-titel { color:var(--orange); }
  .niedrig-banner .engpass-zeile strong { color:var(--orange); }

  .page-actions { display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
  .btn { font-family:var(--mono); font-size:10px; letter-spacing:2px; text-transform:uppercase; padding:9px 16px; cursor:pointer; border:1px solid; transition:all 0.2s; background:none; text-decoration:none; display:inline-block; }
  .btn-primary { color:var(--blue-bright); border-color:rgba(74,158,221,0.4); }
  .btn-primary:hover { background:rgba(74,158,221,0.1); }

  .kanban { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; }
  @media(max-width:1000px) { .kanban { grid-template-columns:1fr 1fr; } }
  @media(max-width:600px) { .kanban { grid-template-columns:1fr; } }

  .spalte { background:rgba(21,34,54,0.6); border:1px solid rgba(45,106,173,0.2); min-height:200px; }
  .spalte-header { padding:16px 18px; border-bottom:1px solid rgba(45,106,173,0.2); font-family:var(--mono); font-size:13px; letter-spacing:2px; text-transform:uppercase; display:flex; justify-content:space-between; align-items:center; }
  .spalte-header .count { background:rgba(74,158,221,0.15); color:var(--blue-bright); padding:2px 10px; border-radius:10px; font-size:12px; }
  .spalte.neu .spalte-header { color:var(--orange); }
  .spalte.zu_verpacken .spalte-header { color:var(--blue-bright); }
  .spalte.versand_vorbereitet .spalte-header { color:#a78bfa; }
  .spalte.versendet .spalte-header { color:var(--green); }
  .spalte-body { padding:12px; display:flex; flex-direction:column; gap:10px; }

  .karte { background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.2); padding:14px 16px; font-size:14px; }
  .karte .kaeufer { font-weight:700; font-size:15px; margin-bottom:5px; display:flex; align-items:center; gap:8px; }
  .kanal-badge { font-family:var(--mono); font-size:9px; padding:2px 7px; border-radius:8px; text-transform:uppercase; letter-spacing:0.5px; }
  .kanal-badge.ebay { background:rgba(74,158,221,0.15); color:var(--blue-bright); }
  .kanal-badge.amazon { background:rgba(230,126,34,0.15); color:var(--orange); }
  .kanal-badge.tiktok { background:rgba(255,255,255,0.1); color:#e0e0e0; }
  .kanal-badge.direktverkauf { background:rgba(46,204,113,0.15); color:var(--green); }
  .zahlung-badge { font-family:var(--mono); font-size:9px; padding:2px 7px; border-radius:8px; }
  .zahlung-badge.bezahlt { background:rgba(46,204,113,0.15); color:var(--green); }
  .zahlung-badge.ausstehend { background:rgba(230,126,34,0.15); color:var(--orange); }
  .karte .artikel { font-family:var(--mono); font-size:12px; color:var(--text-dim); margin-bottom:8px; line-height:1.5; }
  .karte .bestand-info { font-family:var(--mono); font-size:11px; padding:2px 0; }
  .karte .bestand-info.ok { color:var(--green); }
  .karte .bestand-info.knapp { color:var(--red); font-weight:700; }
  .karte .test-tag { display:inline-block; background:rgba(230,126,34,0.15); color:var(--orange); font-size:9px; padding:2px 7px; border-radius:8px; }
  .karte-aktionen { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
  .karte-btn { font-family:var(--mono); font-size:11px; letter-spacing:1px; padding:8px 12px; border:1px solid rgba(45,106,173,0.3); background:none; color:var(--text); cursor:pointer; text-decoration:none; }
  .karte-btn:hover { background:rgba(74,158,221,0.1); }
  .karte-btn.checkliste { color:var(--blue-bright); border-color:var(--blue-bright); }
  .karte-btn.loeschen { color:var(--red); border-color:rgba(231,76,60,0.3); }
  .empty-spalte { font-family:var(--mono); font-size:11px; color:var(--text-dim); text-align:center; padding:24px 10px; }
  .mehr-link { display:block; text-align:center; font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; padding:12px; margin-top:4px; border-top:1px dashed rgba(45,106,173,0.2); }
  .mehr-link:hover { text-decoration:underline; }
  .sendungsnr { font-family:var(--mono); font-size:10px; color:var(--green); margin-top:6px; }

  @media(min-width:1100px) {
    .page-title { font-size:26px; }
    .karte { padding:18px 20px; }
    .karte .kaeufer { font-size:17px; }
    .karte .artikel { font-size:13px; }
    .karte .bestand-info { font-size:12px; }
    .karte-btn { font-size:12px; padding:9px 14px; }
    .spalte-header { font-size:14px; padding:18px 20px; }
  }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Bestellungen</div>
  <div class="page-sub">// WORKFLOW: NEU → ZU VERPACKEN → VERSAND VORBEREITET → VERSENDET</div>
  <div class="test-hinweis">⚠️ Amazon/eBay/TikTok-APIs noch nicht angebunden — hier siehst du vorerst nur Test-Bestellungen.</div>

  <div class="kanal-tabs">
    <a href="bestellungen.php" class="kanal-tab <?= $kanal_filter === '' ? 'active' : '' ?>">Alle Kanäle</a>
    <?php foreach ($kanal_labels as $k => $label): ?>
      <a href="bestellungen.php?kanal=<?= $k ?>" class="kanal-tab <?= $kanal_filter === $k ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($engpaesse)): ?>
  <div class="engpass-banner">
    <div class="engpass-titel">⚠️ LAGERENGPASS — reicht nicht für alle offenen Bestellungen</div>
    <?php foreach ($engpaesse as $e): ?>
      <div class="engpass-zeile"><?= htmlspecialchars($e['name']) ?>: <strong><?= $e['bedarf'] ?></strong> bestellt, nur <strong><?= $e['bestand'] ?></strong> auf Lager</div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($niedrige_bestaende)): ?>
  <div class="engpass-banner niedrig-banner">
    <div class="engpass-titel niedrig-titel">📉 NIEDRIGER BESTAND</div>
    <?php foreach ($niedrige_bestaende as $n): ?>
      <div class="engpass-zeile"><?= htmlspecialchars($n['name']) ?>: nur noch <strong><?= $n['bestand'] ?></strong> Stk auf Lager</div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="page-actions">
    <a href="fake_bestellung.php" class="btn btn-primary">+ TEST-BESTELLUNG ERSTELLEN</a>
    <a href="direktverkauf_erstellen.php" class="btn" style="color:var(--green);border-color:rgba(46,204,113,0.4);">+ DIREKTVERKAUF ERFASSEN</a>
    <a href="bestellungen_historie.php" class="btn" style="color:var(--text-dim);border-color:rgba(90,122,154,0.3);">📜 HISTORIE</a>
    <a href="import_historie.php" class="btn" style="color:var(--orange);border-color:rgba(230,126,34,0.4);">📥 ALTE BESTELLUNGEN IMPORTIEREN</a>
  </div>

  <div class="kanban">
    <?php
    $spalten_labels = [
        'neu' => 'Neu',
        'zu_verpacken' => 'Zu verpacken',
        'versand_vorbereitet' => 'Versand vorbereitet',
        'versendet' => 'Versendet (7 Tage)',
    ];
    foreach ($spalten_labels as $key => $label):
    ?>
    <div class="spalte <?= $key ?>" data-status="<?= $key ?>">
      <div class="spalte-header">
        <span><?= $label ?></span>
        <span class="count"><?= $spalten_gesamt[$key] ?></span>
      </div>
      <div class="spalte-body" id="spalte-body-<?= $key ?>">
        <?php if (empty($spalten[$key])): ?>
          <div class="empty-spalte">// LEER</div>
        <?php endif; ?>
        <?php foreach ($spalten[$key] as $b): ?>
        <div class="karte" id="karte-<?= $b['id'] ?>">
          <div class="kaeufer">
            <?= htmlspecialchars($b['kaeufer_name']) ?>
            <span class="kanal-badge <?= $b['kanal'] ?>"><?= $kanal_labels[$b['kanal']] ?></span>
            <?php if ($b['kanal'] === 'direktverkauf' && $b['zahlungsstatus']): ?>
              <span class="zahlung-badge <?= $b['zahlungsstatus'] ?>"><?= $b['zahlungsstatus'] === 'bezahlt' ? '✓ Bezahlt' : '⏳ Ausstehend' ?></span>
            <?php endif; ?>
            <?php if ($b['ist_test']): ?><span class="test-tag">TEST</span><?php endif; ?>
          </div>
          <div class="artikel"><?= htmlspecialchars($b['artikel_name']) ?> · <?= $b['menge'] ?> Stk<?= ($b['verkaufspreis_brutto'] && !hat_einschraenkung('bestellungen','preise_verbergen')) ? ' · €' . number_format($b['verkaufspreis_brutto'], 2, ',', '.') : '' ?></div>
          <?php if ($b['kanal'] === 'direktverkauf' && $b['zahlungsart']): ?>
            <div class="artikel" style="opacity:0.7;">💳 <?= ucfirst($b['zahlungsart']) ?></div>
          <?php endif; ?>
          <?php if ($b['produkt_id'] && $key !== 'versendet'):
              $bestand = $lager_bestand[$b['produkt_id']] ?? 0;
              $reicht = $bestand >= $b['menge'];
          ?>
          <div class="bestand-info <?= $reicht ? 'ok' : 'knapp' ?>">
            <?= $reicht ? '✓' : '⚠️' ?> Lager: <?= $bestand ?> Stk<?= $reicht ? '' : ' — reicht nicht!' ?>
          </div>
          <?php endif; ?>
          <?php if ($b['sendungsnummer']): ?><div class="sendungsnr">📦 <?= htmlspecialchars($b['sendungsnummer']) ?></div><?php endif; ?>

          <div class="karte-aktionen">
            <?php if ($key === 'neu' && $b['kanal'] === 'direktverkauf' && !$b['versand_noetig']): ?>
              <button class="karte-btn" onclick="direktverkaufAbschliessen(<?= $b['id'] ?>)">✓ VERKAUF ABSCHLIESSEN</button>
            <?php elseif ($key === 'neu'): ?>
              <button class="karte-btn" onclick="bestellungAnnehmen(<?= $b['id'] ?>)">✓ ANNEHMEN</button>
            <?php elseif ($key === 'zu_verpacken'): ?>
              <a class="karte-btn checkliste" href="checkliste.php?id=<?= $b['id'] ?>">📋 CHECKLISTE</a>
            <?php elseif ($key === 'versand_vorbereitet'): ?>
              <a class="karte-btn checkliste" href="checkliste.php?id=<?= $b['id'] ?>">📋 VERSAND ABSCHLIESSEN</a>
            <?php elseif ($key === 'versendet' && $b['produkt_id']): ?>
              <button class="karte-btn" onclick="openRetoureModal(<?= $b['id'] ?>, '<?= htmlspecialchars($b['artikel_name'], ENT_QUOTES) ?>', <?= $b['menge'] ?>)">↩ RETOURE</button>
            <?php endif; ?>
            <?php if ($b['ist_test']): ?>
              <button class="karte-btn loeschen" onclick="testBestellungLoeschen(<?= $b['id'] ?>)">✕</button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if ($spalten_gesamt[$key] > 5): ?>
          <a href="bestellungen_historie.php?status=<?= $key ?><?= $kanal_filter ? '&kanal=' . $kanal_filter : '' ?>" class="mehr-link">→ <?= $spalten_gesamt[$key] - 5 ?> weitere ansehen</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- RETOURE-MODAL -->
<div id="retoure-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:200; align-items:center; justify-content:center;">
  <div style="background:var(--navy2); border:1px solid rgba(45,106,173,0.3); width:100%; max-width:420px; margin:20px; padding:20px;">
    <div style="font-family:var(--mono); font-size:11px; letter-spacing:2px; color:var(--blue-bright); margin-bottom:16px;" id="retoure-titel">// RETOURE ERFASSEN</div>
    <input type="hidden" id="retoure-id">

    <label style="font-family:var(--mono); font-size:9px; color:var(--text-dim); letter-spacing:1px; text-transform:uppercase; display:block; margin-bottom:6px;">Menge</label>
    <input type="number" id="retoure-menge" min="1" value="1" style="width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:10px 12px; margin-bottom:14px;">

    <label style="font-family:var(--mono); font-size:9px; color:var(--text-dim); letter-spacing:1px; text-transform:uppercase; display:block; margin-bottom:6px;">Grund</label>
    <input type="text" id="retoure-grund" placeholder="z.B. Kunde nicht zufrieden" style="width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:10px 12px; margin-bottom:14px;">

    <label style="font-family:var(--mono); font-size:9px; color:var(--text-dim); letter-spacing:1px; text-transform:uppercase; display:block; margin-bottom:6px;">Zustand</label>
    <select id="retoure-verkaufsfaehig" style="width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:10px 12px; margin-bottom:16px;">
      <option value="1">Wieder verkaufsfähig — zurück in den Bestand</option>
      <option value="0">Beschädigt — nicht mehr verkaufsfähig</option>
    </select>

    <div style="display:flex; gap:10px; justify-content:flex-end;">
      <button onclick="closeRetoureModal()" style="background:none; border:1px solid rgba(90,122,154,0.3); color:var(--text-dim); font-family:var(--mono); font-size:10px; padding:9px 16px; cursor:pointer;">ABBRECHEN</button>
      <button onclick="retoureBestaetigen()" style="background:none; border:1px solid var(--blue-bright); color:var(--blue-bright); font-family:var(--mono); font-size:10px; padding:9px 16px; cursor:pointer;">BESTÄTIGEN</button>
    </div>
  </div>
</div>

<script>
async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('bestellungen.php', { method:'POST', body:fd });
  return res.json();
}

async function direktverkaufAbschliessen(id) {
  const res = await api({ action: 'direktverkauf_abschliessen', id });
  if (res.error) { alert(res.error); return; }

  if (res.gewinn) {
    const g = res.gewinn;
    const eur = (n) => n.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';
    alert(
      '✓ Verkauf abgeschlossen!\n\n' +
      'VK netto: ' + eur(g.vk_netto) + '\n' +
      'Wareneinsatz: ' + eur(g.wareneinsatz) + '\n' +
      'Rohertrag: ' + eur(g.rohertrag) + '\n' +
      'Steuerrücklage (30%): ' + eur(g.ruecklage) + '\n' +
      '→ Reingewinn: ' + eur(g.reingewinn)
    );
  }
  window.location.reload();
}

async function bestellungAnnehmen(id) {
  const res = await api({ action:'bestellung_annehmen', id });
  if (res.error) { alert(res.error); return; }

  const karte = document.getElementById('karte-' + id);
  if (!karte) return;

  const zielSpalte = document.getElementById('spalte-body-zu_verpacken');
  const leerHinweis = zielSpalte.querySelector('.empty-spalte');
  if (leerHinweis) leerHinweis.remove();

  const aktionenDiv = karte.querySelector('.karte-aktionen');
  const annehmenBtn = aktionenDiv.querySelector('button:not(.loeschen)');
  if (annehmenBtn) {
    const link = document.createElement('a');
    link.className = 'karte-btn checkliste';
    link.href = 'checkliste.php?id=' + id;
    link.textContent = '📋 CHECKLISTE';
    annehmenBtn.replaceWith(link);
  }

  zielSpalte.insertBefore(karte, zielSpalte.firstChild);
  aktualisiereZaehler('neu', -1);
  aktualisiereZaehler('zu_verpacken', +1);
  pruefeLeereSpalten();
}

function aktualisiereZaehler(status, delta) {
  const spalte = document.querySelector('.spalte[data-status="' + status + '"] .count');
  if (spalte) spalte.textContent = parseInt(spalte.textContent) + delta;
}

function pruefeLeereSpalten() {
  document.querySelectorAll('.spalte-body').forEach(body => {
    const hatKarten = body.querySelector('.karte');
    const hatLeerHinweis = body.querySelector('.empty-spalte');
    if (!hatKarten && !hatLeerHinweis) {
      const div = document.createElement('div');
      div.className = 'empty-spalte';
      div.textContent = '// LEER';
      body.insertBefore(div, body.firstChild);
    }
  });
}

async function testBestellungLoeschen(id) {
  if (!confirm('Diese Test-Bestellung wirklich löschen?')) return;
  const res = await api({ action:'bestellung_loeschen', id });
  if (res.error) { alert(res.error); return; }
  document.getElementById('karte-' + id)?.remove();
}

function openRetoureModal(id, artikelName, maxMenge) {
  document.getElementById('retoure-id').value = id;
  document.getElementById('retoure-titel').textContent = '// RETOURE — ' + artikelName.toUpperCase();
  document.getElementById('retoure-menge').value = 1;
  document.getElementById('retoure-menge').max = maxMenge;
  document.getElementById('retoure-grund').value = '';
  document.getElementById('retoure-overlay').style.display = 'flex';
}

function closeRetoureModal() {
  document.getElementById('retoure-overlay').style.display = 'none';
}

async function retoureBestaetigen() {
  const id = document.getElementById('retoure-id').value;
  const menge = document.getElementById('retoure-menge').value;
  const grund = document.getElementById('retoure-grund').value;
  const wieder_verkaufsfaehig = document.getElementById('retoure-verkaufsfaehig').value;

  const res = await api({ action: 'retoure_erfassen', id, menge, grund, wieder_verkaufsfaehig });
  if (res.error) { alert(res.error); return; }
  closeRetoureModal();
  alert('Retoure erfasst und im Lager gebucht.');
}
</script>
</div>
</body>
</html>
