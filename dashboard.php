<?php
date_default_timezone_set('Europe/Berlin');
require_once 'config.php';
requireLogin();
require_once __DIR__ . '/berechtigungen/berechtigungen_helper.php';
$user = currentUser();

// Zugriffs-Flags fürs Ausblenden von Navigation/Bereichen
$zugriff_lager = hat_modul_zugriff('lager');
$zugriff_lager_bearbeiten = hat_modul_zugriff('lager', 'bearbeiten');
$lager_preise_verbergen = hat_einschraenkung('lager', 'preise_verbergen');
$zugriff_bestellungen = hat_modul_zugriff('bestellungen');
$zugriff_aufgaben = hat_modul_zugriff('aufgaben');
$zugriff_dokumente = hat_modul_zugriff('dokumente');
$zugriff_kalkulator = hat_modul_zugriff('kalkulator');
$zugriff_umsatz = hat_modul_zugriff('umsatz');
$zugriff_nachricht = hat_modul_zugriff('nachricht');
$zugriff_export = hat_modul_zugriff('export');
$zugriff_produkttexte = hat_modul_zugriff('produkttexte');
$zugriff_antworten = hat_modul_zugriff('antworten');
$zugriff_steuer = hat_modul_zugriff('steuer');
$zugriff_clean = hat_modul_zugriff('clean');
$ist_systemverwalter = $user['username'] === 'qaf';

// ── API HANDLER (AJAX Requests) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $uid    = $_SESSION['user_id'];

    // Lager-Schreibaktionen absichern — Bearbeiten-Recht nötig
    $lager_schreibaktionen = ['produkt_add', 'produkt_archivieren', 'produkt_bearbeiten', 'produkt_reaktivieren', 'produkt_endgueltig_loeschen', 'produkt_vk_update', 'bewegung_add', 'bewegung_rueckgaengig', 'letzte_bewegung_rueckgaengig'];
    if (in_array($action, $lager_schreibaktionen) && !hat_modul_zugriff('lager', 'bearbeiten')) {
        echo json_encode(['error' => 'Kein Bearbeiten-Recht für das Lager-Modul']);
        exit;
    }
    // Lager-Leseaktionen absichern — mind. Ansehen-Recht nötig
    $lager_leseaktionen = ['lager_verlauf', 'produkt_details_holen', 'produkt_per_barcode'];
    if (in_array($action, $lager_leseaktionen) && !hat_modul_zugriff('lager', 'ansehen')) {
        echo json_encode(['error' => 'Kein Zugriff auf das Lager-Modul']);
        exit;
    }

    // erfasst_von: 'qaf' (Artis) oder 'qns' (Nour) — aus username ableiten, sonst 'qaf' als Fallback
    $erfasst_von = in_array($user['username'] ?? '', ['qaf', 'qns']) ? $user['username'] : 'qaf';

    // ── LAGER: KATEGORIEN ──
    if ($action === 'kategorie_add') {
        $name = trim($_POST['name'] ?? '');
        if (!$name) { echo json_encode(['error' => 'Name fehlt']); exit; }
        db()->prepare('INSERT INTO lager_kategorien (name, sortierung) VALUES (?, 50)')->execute([$name]);
        echo json_encode(['success' => true, 'id' => db()->lastInsertId()]);
        exit;
    }

    // ── LAGER: PRODUKTE ──
    if ($action === 'produkt_add') {
        $name       = trim($_POST['name'] ?? '');
        $kat_id     = (int)($_POST['kategorie_id'] ?? 0);
        $ek         = (float)($_POST['ek_preis_netto'] ?? 0);
        $vst        = (float)($_POST['vorsteuersatz'] ?? 19);
        $foto_url   = trim($_POST['foto_url'] ?? '');
        $ebay_sku   = trim($_POST['ebay_sku'] ?? '');
        $amazon_sku = trim($_POST['amazon_sku'] ?? '');
        $tiktok_sku = trim($_POST['tiktok_sku'] ?? '');
        if (!$name || !$kat_id) { echo json_encode(['error' => 'Name oder Kategorie fehlt']); exit; }
        $stmt = db()->prepare('INSERT INTO lager_produkte (kategorie_id, name, ek_preis_netto, vorsteuersatz, foto_url, ebay_sku, amazon_sku, tiktok_sku) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$kat_id, $name, $ek, $vst, $foto_url ?: null, $ebay_sku ?: null, $amazon_sku ?: null, $tiktok_sku ?: null]);
        echo json_encode(['success' => true, 'id' => db()->lastInsertId()]);
        exit;
    }

    if ($action === 'produkt_archivieren') {
        $id = (int)($_POST['id'] ?? 0);
        // Nur archivieren, wenn wirklich kein Bestand mehr da ist — verhindert
        // versehentliches Verschwinden von Produkten mit noch vorhandener Ware.
        $check = db()->prepare("
            SELECT COALESCE(SUM(CASE
                WHEN typ = 'zugang_einkauf' THEN menge
                WHEN typ = 'retoure' AND wieder_verkaufsfaehig = 1 THEN menge
                WHEN typ = 'korrektur' THEN menge
                WHEN typ IN ('abgang_verkauf','abgang_eigenbedarf') THEN -menge
                ELSE 0 END), 0) AS bestand
            FROM lager_bewegungen WHERE produkt_id = ?
        ");
        $check->execute([$id]);
        $bestand = (int)$check->fetch()['bestand'];
        if ($bestand > 0) {
            echo json_encode(['error' => "Noch $bestand Stk auf Lager — erst abverkaufen/verbrauchen, dann archivieren"]);
            exit;
        }
        db()->prepare('UPDATE lager_produkte SET aktiv = 0 WHERE id = ?')->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'produkt_bearbeiten') {
        $id = (int)($_POST['id'] ?? 0);
        $ek = (float)($_POST['ek_preis_netto'] ?? 0);
        $vk = $_POST['vk_preis_brutto'] !== '' ? (float)$_POST['vk_preis_brutto'] : null;
        $ebay_sku = trim($_POST['ebay_sku'] ?? '');
        $amazon_sku = trim($_POST['amazon_sku'] ?? '');
        $tiktok_sku = trim($_POST['tiktok_sku'] ?? '');
        $barcode = trim($_POST['barcode'] ?? '');
        if (!$id) { echo json_encode(['error' => 'Produkt fehlt']); exit; }

        db()->prepare('
            UPDATE lager_produkte
            SET ek_preis_netto = ?, vk_preis_brutto = ?, ebay_sku = ?, amazon_sku = ?, tiktok_sku = ?, barcode = ?
            WHERE id = ?
        ')->execute([$ek, $vk, $ebay_sku ?: null, $amazon_sku ?: null, $tiktok_sku ?: null, $barcode ?: null, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'produkt_details_holen') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT name, ek_preis_netto, vk_preis_brutto, ebay_sku, amazon_sku, tiktok_sku, barcode FROM lager_produkte WHERE id = ?');
        $stmt->execute([$id]);
        $produkt = $stmt->fetch();
        if (!$produkt) { echo json_encode(['error' => 'Produkt nicht gefunden']); exit; }
        echo json_encode($produkt);
        exit;
    }

    if ($action === 'produkt_reaktivieren') {
        $id = (int)($_POST['id'] ?? 0);
        $neuer_ek = $_POST['neuer_ek'] ?? '';
        db()->prepare('UPDATE lager_produkte SET aktiv = 1 WHERE id = ?')->execute([$id]);
        if ($neuer_ek !== '' && (float)$neuer_ek > 0) {
            db()->prepare('UPDATE lager_produkte SET ek_preis_netto = ? WHERE id = ?')->execute([(float)$neuer_ek, $id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'produkt_endgueltig_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'Produkt fehlt']); exit; }
        // Nur archivierte Produkte dürfen endgültig gelöscht werden — Sicherheitsnetz
        $check = db()->prepare('SELECT aktiv FROM lager_produkte WHERE id = ?');
        $check->execute([$id]);
        $row = $check->fetch();
        if (!$row || (int)$row['aktiv'] === 1) {
            echo json_encode(['error' => 'Nur archivierte Produkte können endgültig gelöscht werden']);
            exit;
        }
        db()->prepare('DELETE FROM lager_bewegungen WHERE produkt_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM lager_produkte WHERE id = ?')->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'produkt_vk_update') {
        $id = (int)($_POST['id'] ?? 0);
        $vk = (float)($_POST['vk_preis_brutto'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'Produkt fehlt']); exit; }
        db()->prepare('UPDATE lager_produkte SET vk_preis_brutto = ? WHERE id = ?')->execute([$vk > 0 ? $vk : null, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── LAGER: BEWEGUNGEN (Herzstück) ──
    if ($action === 'bewegung_add') {
        $produkt_id = (int)($_POST['produkt_id'] ?? 0);
        $typ        = $_POST['typ'] ?? '';
        $menge_input = (int)($_POST['menge'] ?? 0);
        $allowed    = ['zugang_einkauf', 'abgang_verkauf', 'abgang_eigenbedarf', 'retoure', 'korrektur'];

        // Bei "korrektur" ist 0 zulässig (z.B. gezählter Bestand = 0 Stück),
        // bei allen anderen Typen muss die Menge größer 0 sein.
        $menge_ungueltig = ($typ === 'korrektur') ? $menge_input < 0 : $menge_input <= 0;
        if (!$produkt_id || !in_array($typ, $allowed) || $menge_ungueltig) {
            echo json_encode(['error' => 'Ungültige Eingabe']);
            exit;
        }

        // Bei "korrektur" gibt der Nutzer den TATSÄCHLICH GEZÄHLTEN Bestand ein
        // (nicht ein Delta) — wir berechnen automatisch die Differenz zum
        // aktuellen Buchbestand und speichern NUR diese Differenz als Bewegung.
        // So bleibt die Historie korrekt nachvollziehbar (Delta statt Zielwert),
        // während die Eingabe für den Nutzer intuitiv bleibt.
        $menge = $menge_input;
        if ($typ === 'korrektur') {
            $aktuell = db()->prepare("
                SELECT COALESCE(SUM(CASE
                    WHEN typ = 'zugang_einkauf' THEN menge
                    WHEN typ = 'retoure' AND wieder_verkaufsfaehig = 1 THEN menge
                    WHEN typ = 'korrektur' THEN menge
                    WHEN typ IN ('abgang_verkauf','abgang_eigenbedarf') THEN -menge
                    ELSE 0 END), 0) AS bestand
                FROM lager_bewegungen WHERE produkt_id = ?
            ");
            $aktuell->execute([$produkt_id]);
            $aktueller_bestand = (int)$aktuell->fetch()['bestand'];
            $menge = $menge_input - $aktueller_bestand; // kann negativ sein — das ist gewollt

            if ($menge === 0) {
                echo json_encode(['error' => "Gezählter Bestand ($menge_input) entspricht bereits dem Buchbestand — keine Korrektur nötig"]);
                exit;
            }
        }

        $sql = "INSERT INTO lager_bewegungen
            (produkt_id, typ, menge, ek_preis_netto, verkaufskanal, verkaufspreis_brutto,
             bestellnummer, retoure_grund, wieder_verkaufsfaehig, eigenbedarf_grund,
             korrektur_grund, notiz, erfasst_von, bewegt_am)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

        db()->prepare($sql)->execute([
            $produkt_id, $typ, $menge,
            $typ === 'zugang_einkauf' ? (float)($_POST['ek_preis_netto'] ?? 0) : null,
            $typ === 'abgang_verkauf' ? ($_POST['verkaufskanal'] ?? null) : null,
            $typ === 'abgang_verkauf' ? (float)($_POST['verkaufspreis_brutto'] ?? 0) : null,
            $typ === 'abgang_verkauf' ? trim($_POST['bestellnummer'] ?? '') : null,
            $typ === 'retoure' ? trim($_POST['retoure_grund'] ?? '') : null,
            $typ === 'retoure' ? (int)($_POST['wieder_verkaufsfaehig'] ?? 1) : null,
            $typ === 'abgang_eigenbedarf' ? trim($_POST['eigenbedarf_grund'] ?? '') : null,
            $typ === 'korrektur' ? trim($_POST['korrektur_grund'] ?? '') : null,
            trim($_POST['notiz'] ?? ''),
            $erfasst_von,
            $_POST['bewegt_am'] ?? date('Y-m-d'),
        ]);

        // Neuen Bestand zurückgeben, damit UI sich ohne Reload aktualisieren kann
        $neu = db()->prepare("
            SELECT COALESCE(SUM(CASE
                WHEN typ = 'zugang_einkauf' THEN menge
                WHEN typ = 'retoure' AND wieder_verkaufsfaehig = 1 THEN menge
                WHEN typ = 'korrektur' THEN menge
                WHEN typ IN ('abgang_verkauf','abgang_eigenbedarf') THEN -menge
                ELSE 0 END), 0) AS bestand
            FROM lager_bewegungen WHERE produkt_id = ?
        ");
        $neu->execute([$produkt_id]);
        echo json_encode(['success' => true, 'bestand' => (int)$neu->fetch()['bestand']]);
        exit;
    }

    if ($action === 'lager_verlauf') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("
            SELECT * FROM lager_bewegungen
            WHERE produkt_id = ? ORDER BY bewegt_am DESC, erstellt_am DESC LIMIT 30
        ");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($action === 'notiz_anlegen') {
        $text = trim($_POST['text'] ?? '');
        $farbe = $_POST['farbe'] ?? 'gelb';
        if (!in_array($farbe, ['gelb','rosa','blau','gruen'])) $farbe = 'gelb';
        if (!$text) { echo json_encode(['error' => 'Text fehlt']); exit; }
        db()->prepare("INSERT INTO dashboard_notizen (text, farbe, erstellt_von) VALUES (?, ?, ?)")
            ->execute([$text, $farbe, $user['username']]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'notiz_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM dashboard_notizen WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'produkt_per_barcode') {
        $barcode = trim($_POST['barcode'] ?? '');
        if (!$barcode) { echo json_encode(['error' => 'Kein Barcode']); exit; }
        $stmt = db()->prepare("SELECT id, name FROM lager_produkte WHERE barcode = ? AND aktiv = 1 LIMIT 1");
        $stmt->execute([$barcode]);
        $produkt = $stmt->fetch();
        if (!$produkt) { echo json_encode(['error' => 'Kein Produkt mit diesem Barcode gefunden']); exit; }
        echo json_encode(['success' => true, 'produkt_id' => $produkt['id'], 'produkt_name' => $produkt['name']]);
        exit;
    }

    if ($action === 'bewegung_rueckgaengig') {
        require_once __DIR__ . '/admin_helper.php';
        $bewegung_id = (int)($_POST['bewegung_id'] ?? 0);

        $stmt = db()->prepare("
            SELECT b.*, p.name AS produkt_name FROM lager_bewegungen b
            JOIN lager_produkte p ON p.id = b.produkt_id
            WHERE b.id = ?
        ");
        $stmt->execute([$bewegung_id]);
        $bewegung = $stmt->fetch();
        if (!$bewegung) { echo json_encode(['error' => 'Bewegung nicht gefunden']); exit; }

        // Falls diese Bewegung ein Auslandseinkauf war: den zugehörigen
        // Vorsteuer-Tracking-Eintrag mitlöschen, sonst bleibt der als
        // verwaiste Karteileiche in der Übersicht stehen
        $vorsteuer_stmt = db()->prepare("SELECT id, land, mwst_betrag FROM auslaendische_vorsteuer WHERE lager_bewegung_id = ?");
        $vorsteuer_stmt->execute([$bewegung_id]);
        $vorsteuer_eintrag = $vorsteuer_stmt->fetch();
        if ($vorsteuer_eintrag) {
            db()->prepare("DELETE FROM auslaendische_vorsteuer WHERE id = ?")->execute([$vorsteuer_eintrag['id']]);
        }

        db()->prepare("DELETE FROM lager_bewegungen WHERE id = ?")->execute([$bewegung_id]);

        $log_zusatz = $vorsteuer_eintrag ? " (inkl. Vorsteuer-Tracking {$vorsteuer_eintrag['land']}, €{$vorsteuer_eintrag['mwst_betrag']})" : '';
        aktivitaet_loggen(
            'lager',
            'bewegung_rueckgaengig',
            "Bewegung gelöscht: {$bewegung['typ']}, {$bewegung['menge']} Stk, {$bewegung['produkt_name']} (bewegt am {$bewegung['bewegt_am']}){$log_zusatz}"
        );

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'letzte_bewegung_rueckgaengig') {
        require_once __DIR__ . '/admin_helper.php';
        $produkt_id = (int)($_POST['produkt_id'] ?? 0);

        $stmt = db()->prepare("
            SELECT b.*, p.name AS produkt_name FROM lager_bewegungen b
            JOIN lager_produkte p ON p.id = b.produkt_id
            WHERE b.produkt_id = ?
            ORDER BY b.bewegt_am DESC, b.erstellt_am DESC, b.id DESC LIMIT 1
        ");
        $stmt->execute([$produkt_id]);
        $bewegung = $stmt->fetch();
        if (!$bewegung) { echo json_encode(['error' => 'Keine Bewegung zum Rückgängigmachen gefunden']); exit; }

        $vorsteuer_stmt = db()->prepare("SELECT id, land, mwst_betrag FROM auslaendische_vorsteuer WHERE lager_bewegung_id = ?");
        $vorsteuer_stmt->execute([$bewegung['id']]);
        $vorsteuer_eintrag = $vorsteuer_stmt->fetch();
        if ($vorsteuer_eintrag) {
            db()->prepare("DELETE FROM auslaendische_vorsteuer WHERE id = ?")->execute([$vorsteuer_eintrag['id']]);
        }

        db()->prepare("DELETE FROM lager_bewegungen WHERE id = ?")->execute([$bewegung['id']]);

        $log_zusatz = $vorsteuer_eintrag ? " (inkl. Vorsteuer-Tracking {$vorsteuer_eintrag['land']}, €{$vorsteuer_eintrag['mwst_betrag']})" : '';
        aktivitaet_loggen(
            'lager',
            'letzte_bewegung_rueckgaengig',
            "Letzte Bewegung gelöscht: {$bewegung['typ']}, {$bewegung['menge']} Stk, {$bewegung['produkt_name']} (bewegt am {$bewegung['bewegt_am']}){$log_zusatz}"
        );

        echo json_encode(['success' => true, 'produkt_name' => $bewegung['produkt_name']]);
        exit;
    }

    // ── TODOS (unverändert) ──
    if ($action === 'todo_add') {
        $titel    = trim($_POST['titel'] ?? '');
        $desc     = trim($_POST['beschreibung'] ?? '');
        $prio     = $_POST['prioritaet'] ?? 'mittel';
        $assignee = $_POST['assignee'] ?? 'beide';
        $deadline = $_POST['deadline'] ?? null;
        if (!$titel) { echo json_encode(['error' => 'Titel fehlt']); exit; }
        db()->prepare('INSERT INTO aufgaben (titel, beschreibung, prioritaet, assignee, deadline, erstellt_von) VALUES (?,?,?,?,?,?)')
             ->execute([$titel, $desc, $prio, $assignee, $deadline ?: null, $uid]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'todo_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'offen';
        db()->prepare('UPDATE aufgaben SET status = ? WHERE id = ?')->execute([$status, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'todo_delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM aufgaben WHERE id = ?')->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unbekannte Aktion']);
    exit;
}

// ── DATEN LADEN ──────────────────────────────────────────────────────────────
$kategorien = db()->query('SELECT * FROM lager_kategorien ORDER BY sortierung ASC')->fetchAll();

$suche = trim($_GET['suche'] ?? '');
$zeige_archiv = isset($_GET['archiv']);

// Bestand live berechnen (keine gespeicherte Zahl — siehe Lager-Modul v2)
$produkte = db()->query("
    SELECT p.id, p.name, p.foto_url, p.ek_preis_netto, p.vk_preis_brutto, p.vorsteuersatz, p.aktiv,
           k.id AS kategorie_id, k.name AS kategorie_name,
           COALESCE(SUM(CASE
               WHEN b.typ = 'zugang_einkauf' THEN b.menge
               WHEN b.typ = 'retoure' AND b.wieder_verkaufsfaehig = 1 THEN b.menge
               WHEN b.typ = 'korrektur' THEN b.menge
               WHEN b.typ IN ('abgang_verkauf','abgang_eigenbedarf') THEN -b.menge
               ELSE 0 END), 0) AS bestand,
           COALESCE(SUM(CASE WHEN b.typ = 'abgang_verkauf' AND b.bewegt_am >= DATE_FORMAT(NOW(),'%Y-%m-01') THEN b.menge ELSE 0 END), 0) AS verkauft_monat,
           COALESCE(SUM(CASE WHEN b.typ = 'zugang_einkauf' THEN b.menge ELSE 0 END), 0) AS p_insgesamt,
           COALESCE(SUM(CASE WHEN b.typ = 'abgang_verkauf' THEN b.menge ELSE 0 END), 0) AS p_verkauft,
           COALESCE(SUM(CASE WHEN b.typ = 'abgang_eigenbedarf' THEN b.menge ELSE 0 END), 0) AS p_eigenbedarf,
           COALESCE(SUM(CASE WHEN b.typ = 'korrektur' THEN b.menge ELSE 0 END), 0) AS p_korrektur_saldo
    FROM lager_produkte p
    JOIN lager_kategorien k ON k.id = p.kategorie_id
    LEFT JOIN lager_bewegungen b ON b.produkt_id = p.id
    WHERE p.aktiv = " . ($zeige_archiv ? "0" : "1") . "
    " . ($suche !== '' ? "AND p.name LIKE " . db()->quote('%' . $suche . '%') : "") . "
    GROUP BY p.id, p.name, p.foto_url, p.ek_preis_netto, p.vorsteuersatz, p.aktiv, k.id, k.name
    ORDER BY k.sortierung, p.name
")->fetchAll();

// EK-Wert je Produkt: ECHTER FIFO-Restwert der noch vorhandenen Chargen —
// NICHT der Durchschnitt über die gesamte Kaufhistorie (der würde auch
// längst verkaufte Chargen fälschlich mitzählen, siehe Lager-Diagnose-Tool)
require_once __DIR__ . '/lager/fifo_helper.php';
foreach ($produkte as &$p) {
    $fifo = fifo_lagerwert_berechnen(db(), $p['id'], null, (float)($p['ek_preis_netto'] ?? 0));
    $p['ek_preis_avg'] = $fifo['ek_avg'];
    $p['ek_wert_bestand'] = $fifo['lagerwert'];
}
unset($p);

$todos = db()->query('SELECT t.*, u.name as ersteller FROM aufgaben t JOIN users u ON t.erstellt_von = u.id ORDER BY FIELD(prioritaet,"hoch","mittel","niedrig"), deadline ASC')->fetchAll();

// Stats
$todo_offen  = count(array_filter($todos, fn($t) => $t['status'] !== 'erledigt'));
$lager_count = count($produkte);
$lager_ek_gesamt = array_sum(array_column($produkte, 'ek_wert_bestand'));

// Aggregierte Kennzahlen für die 4 Übersichts-Kreise auf der Lager-Seite
$agg = db()->query("
    SELECT
        COALESCE(SUM(CASE WHEN b.typ = 'zugang_einkauf' THEN b.menge ELSE 0 END), 0) AS gesamt_eingekauft,
        COALESCE(SUM(CASE WHEN b.typ = 'abgang_verkauf' THEN b.menge ELSE 0 END), 0) AS gesamt_verkauft,
        COALESCE(SUM(CASE WHEN b.typ = 'abgang_eigenbedarf' THEN b.menge ELSE 0 END), 0) AS gesamt_eigenbedarf
    FROM lager_bewegungen b
    JOIN lager_produkte p ON p.id = b.produkt_id
    WHERE p.aktiv = 1
")->fetch();
$lager_stueck_verfuegbar = array_sum(array_map(fn($p) => $p['bestand'], $produkte));
$lager_gesamt_insgesamt  = (int)$agg['gesamt_eingekauft'];
$lager_gesamt_verkauft   = (int)$agg['gesamt_verkauft'];
$lager_gesamt_eigenbedarf = (int)$agg['gesamt_eigenbedarf'];

// ── HEUTE-WIDGET: Daten für die Tagesübersicht ──────────────────────────────
$heute_aufgaben = db()->query("
    SELECT titel, prioritaet, assignee FROM aufgaben
    WHERE deadline = CURDATE() AND status != 'erledigt'
    ORDER BY FIELD(prioritaet,'hoch','mittel','niedrig')
")->fetchAll();

$heute_ueberfaellig_count = (int)db()->query("
    SELECT COUNT(*) FROM aufgaben WHERE deadline < CURDATE() AND status != 'erledigt'
")->fetchColumn();

$heute_neue_bestellungen = 0;
$bestellungen_tabelle_existiert = db()->query("SHOW TABLES LIKE 'bestellungen'")->fetchColumn();
if ($bestellungen_tabelle_existiert) {
    $heute_neue_bestellungen = (int)db()->query("SELECT COUNT(*) FROM bestellungen WHERE status = 'neu'")->fetchColumn();
}

// Lagerengpässe/niedrige Bestände fürs Widget (leichte Version der Logik aus bestellungen.php)
$heute_engpaesse = [];
$heute_niedrig = [];
$produkte_fuer_widget = db()->query("
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
")->fetchAll();
foreach ($produkte_fuer_widget as $pw) {
    $schwelle = max(5, (int)round($pw['insgesamt'] * 0.10));
    if ($pw['bestand'] > 0 && $pw['bestand'] <= $schwelle) {
        $heute_niedrig[] = $pw['name'];
    }
}

// Deadline warnings (3 Tage)
$today = new DateTime();
foreach ($todos as &$t) {
    $t['days_left'] = null;
    if ($t['deadline'] && $t['status'] !== 'erledigt') {
        $diff = $today->diff(new DateTime($t['deadline']));
        $t['days_left'] = $diff->invert ? -$diff->days : $diff->days;
    }
}
unset($t);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NA Ops Hub — Dashboard</title>
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="NA Ops">
<meta name="theme-color" content="#14161F">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy:        #14161F;
    --navy2:       #1D2030;
    --blue-mid:    #262A3D;
    --blue-accent: #7C7CFF;
    --blue-bright: #9494FF;
    --line:        #2E3248;
    --accent-soft: rgba(124,124,255,0.15);
    --text:        #E4E6F0;
    --text-dim:    #8B8FA8;
    --green:       #4ADE80;
    --orange:      #FBBF24;
    --red:         #F87171;
    --terracotta:  #D9A066;   /* CozyCore-Akzent, nur bei Wärmflasche-Kategorie */
    --mono:        'Inter', -apple-system, sans-serif;
    --sans:        'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; overflow-x:hidden; }

  /* TOPBAR */
  .topbar { position:fixed; top:0; left:0; right:0; height:56px; display:flex; align-items:center; justify-content:space-between; padding:0 22px; background:var(--navy2); border-bottom:1px solid var(--line); z-index:100; }
  .topbar-left { display:flex; align-items:center; gap:12px; }
  .logo-mark { width:32px; height:32px; border-radius:10px; background:var(--accent-soft); display:flex; align-items:center; justify-content:center; }
  .logo-mark span { font-size:12px; font-weight:700; color:var(--blue-accent); }
  .topbar-title { font-size:15px; font-weight:600; color:#F5F6FA; }
  .topbar-right { display:flex; align-items:center; gap:16px; font-size:12px; color:var(--text-dim); }
  .status-dot { display:inline-block; width:7px; height:7px; border-radius:50%; background:var(--green); margin-right:6px; animation:pulse 2s infinite; }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.35} }
  .user-badge { background:var(--blue-mid); border:1px solid var(--line); border-radius:20px; padding:5px 12px; color:var(--text); font-weight:500; }
  .logout-btn { background:none; border:none; font-size:12px; color:var(--text-dim); cursor:pointer; transition:color 0.15s; }
  .logout-btn:hover { color:var(--red); }

  /* SIDEBAR */
  .sidebar { position:fixed; top:56px; left:0; bottom:0; width:216px; background:var(--navy2); border-right:1px solid var(--line); z-index:50; padding:20px 0; display:flex; flex-direction:column; gap:2px; overflow-y:auto; }
  .nav-section { font-size:11px; color:var(--text-dim); letter-spacing:1px; padding:14px 20px 6px; font-weight:600; text-transform:uppercase; }
  .nav-item { display:flex; align-items:center; gap:10px; padding:10px 20px; font-size:13.5px; color:var(--text-dim); cursor:pointer; transition:all 0.15s; font-weight:500; border-left:3px solid transparent; text-decoration:none; }
  .nav-item:hover { color:var(--text); background:var(--blue-mid); }
  .nav-item.active { color:var(--blue-accent); background:var(--accent-soft); border-left-color:var(--blue-accent); }
  .nav-icon { font-size:14px; width:18px; text-align:center; }
  .nav-badge { margin-left:auto; background:var(--blue-accent); color:#fff; font-size:11px; font-weight:600; padding:1px 7px; border-radius:10px; }
  .nav-badge.urgent { background:var(--red); }
  .nav-spacer { flex:1; }

  /* MAIN */
  .main { margin-left:216px; margin-top:56px; padding:28px; position:relative; z-index:2; }
  .page { display:none; animation:fadeUp 0.3s ease; }
  .page.active { display:block; }
  @keyframes fadeUp { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }

  /* PAGE HEADER */
  .page-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
  .page-title { font-size:22px; font-weight:700; color:#F5F6FA; }
  .page-sub { font-size:13px; color:var(--text-dim); margin-top:4px; }
  .page-actions { display:flex; gap:8px; flex-wrap:wrap; }

  /* STATS */
  .stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; }
  .stat-card { background:var(--navy2); border:1px solid var(--line); border-radius:18px; padding:18px 20px; position:relative; overflow:hidden; }
  .stat-label { font-size:11px; color:var(--text-dim); font-weight:500; margin-bottom:8px; }
  .stat-value { font-size:26px; font-weight:700; color:#F5F6FA; line-height:1; }
  .stat-value.green { color:var(--green); }
  .stat-value.orange { color:var(--orange); }
  .stat-detail { font-size:11px; color:var(--text-dim); margin-top:6px; }

  /* GRID */
  .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }

  /* PANEL */
  .panel { background:var(--navy2); border:1px solid var(--line); border-radius:20px; position:relative; margin-bottom:16px; }
  .panel-header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--line); flex-wrap:wrap; gap:8px; }
  .panel-title { font-size:15px; font-weight:600; color:var(--text); display:flex; align-items:center; gap:8px; }
  .panel-action { font-size:12px; color:var(--text-dim); cursor:pointer; transition:color 0.2s; background:none; border:none; font-family:var(--sans); }
  .panel-action:hover { color:var(--blue-bright); }
  .panel-body { padding:18px 20px; }

  /* BUTTONS */
  .btn { font-family:var(--sans); font-size:13px; font-weight:600; padding:9px 16px; border-radius:12px; cursor:pointer; border:1px solid; transition:all 0.15s; background:none; }
  .btn-primary { color:var(--blue-bright); border-color:rgba(124,124,255,0.4); }
  .btn-primary:hover { background:var(--accent-soft); border-color:var(--blue-bright); }
  .btn-terracotta { color:var(--terracotta); border-color:rgba(217,160,102,0.4); }
  .btn-terracotta:hover { background:rgba(217,160,102,0.12); border-color:var(--terracotta); }
  .btn-danger { color:var(--red); border-color:rgba(248,113,113,0.35); }
  .btn-danger:hover { background:rgba(248,113,113,0.12); }
  .btn-sm { padding:6px 11px; font-size:12px; border-radius:10px; }

  /* TODO ITEMS */
  .todo-item { display:flex; align-items:flex-start; gap:12px; padding:14px 0; border-bottom:1px solid var(--line); }
  .todo-item:last-child { border-bottom:none; }
  .todo-priority { width:4px; height:44px; flex-shrink:0; border-radius:4px; margin-top:2px; }
  .todo-priority.hoch { background:var(--red); }
  .todo-priority.mittel { background:var(--orange); }
  .todo-priority.niedrig { background:var(--green); }
  .todo-info { flex:1; }
  .todo-title { font-size:13.5px; font-weight:600; color:var(--text); margin-bottom:4px; }
  .todo-meta { font-size:11px; color:var(--text-dim); display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  .todo-assignee { background:var(--accent-soft); border-radius:8px; padding:2px 9px; font-size:11px; color:var(--blue-bright); white-space:nowrap; }
  .todo-status { font-size:11px; font-weight:600; border-radius:10px; padding:3px 10px; border:1px solid; white-space:nowrap; }
  .todo-status.offen { color:var(--orange); border-color:rgba(251,191,36,0.3); }
  .todo-status.in_bearbeitung { color:var(--blue-bright); border-color:rgba(124,124,255,0.3); }
  .todo-status.erledigt { color:var(--green); border-color:rgba(74,222,128,0.3); }
  .deadline-warn { color:var(--red); }

  /* LAGER ITEMS */
  .kategorie-block { margin-bottom:18px; }
  .kategorie-block:last-child { margin-bottom:0; }
  .kategorie-label { font-size:12px; font-weight:600; padding:6px 0; margin-bottom:6px; border-bottom:1px dashed var(--line); display:flex; align-items:center; justify-content:space-between; }
  .kategorie-label.is-cozycore { color:var(--terracotta); border-bottom-color:rgba(217,160,102,0.3); }
  .kategorie-label.is-other { color:var(--blue-bright); }
  .kategorie-count { font-size:11px; color:var(--text-dim); font-weight:500; }

  .lager-item { display:flex; align-items:flex-start; gap:14px; padding:14px 0; border-bottom:1px solid var(--line); }
  .lager-item:last-child { border-bottom:none; }
  .lager-img { width:48px; height:48px; border-radius:12px; background:var(--blue-mid); display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; overflow:hidden; }
  .lager-img img { width:100%; height:100%; object-fit:cover; }
  .lager-name { font-size:13.5px; font-weight:600; color:var(--text); margin-bottom:3px; }
  .lager-stock { font-size:12px; color:var(--text-dim); }
  .lager-stock span { color:var(--blue-bright); font-weight:600; }
  .lager-stock span.low { color:var(--red); }
  .lager-ekwert { font-size:11px; color:var(--text-dim); margin-top:2px; }

  /* ÜBERSICHTS-KREISE (Lager-Seite) */
  .kreise-row { display:flex; gap:20px; flex-wrap:wrap; justify-content:space-around; padding:20px 10px 24px; }
  .kreis { display:flex; flex-direction:column; align-items:center; gap:10px; }
  .kreis-circle {
    width:110px; height:110px; border-radius:50%;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    border:2px solid var(--line);
    background: radial-gradient(circle at 35% 30%, rgba(124,124,255,0.15), rgba(29,32,48,0.9));
  }
  .kreis-circle .kreis-value { font-size:26px; font-weight:700; color:#F5F6FA; line-height:1; }
  .kreis-circle .kreis-einheit { font-size:10px; color:var(--text-dim); margin-top:4px; }
  .kreis-label { font-size:12px; font-weight:600; color:var(--text-dim); text-align:center; }
  .kreis.insgesamt .kreis-circle { border-color:rgba(124,124,255,0.5); }
  .kreis.insgesamt .kreis-value { color:var(--blue-bright); }
  .kreis.verfuegbar .kreis-circle { border-color:rgba(74,222,128,0.5); }
  .kreis.verfuegbar .kreis-value { color:var(--green); }
  .kreis.verkauft .kreis-circle { border-color:rgba(251,191,36,0.5); }
  .kreis.verkauft .kreis-value { color:var(--orange); }
  .kreis.eigenbedarf .kreis-circle { border-color:rgba(248,113,113,0.5); }
  .kreis.eigenbedarf .kreis-value { color:var(--red); }
  @media(max-width:768px) { .kreise-row { gap:14px; } .kreis-circle { width:88px; height:88px; } .kreis-circle .kreis-value { font-size:20px; } }

  /* MINI-KREISE PRO PRODUKT */
  .mini-kreise-row { display:flex; gap:14px; margin-top:10px; flex-wrap:wrap; }
  .mini-kreis { display:flex; flex-direction:column; align-items:center; gap:5px; }
  .mini-kreis-circle {
    width:56px; height:56px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    border:2px solid var(--line);
    background: radial-gradient(circle at 35% 30%, rgba(124,124,255,0.12), rgba(29,32,48,0.9));
    font-size:15px; font-weight:700; color:#F5F6FA;
  }
  .mini-kreis-label { font-size:10px; font-weight:500; color:var(--text-dim); text-align:center; line-height:1.3; }
  .mini-kreis.insgesamt .mini-kreis-circle { border-color:rgba(124,124,255,0.5); color:var(--blue-bright); }
  .mini-kreis.verfuegbar .mini-kreis-circle { border-color:rgba(74,222,128,0.5); color:var(--green); }
  .mini-kreis.verfuegbar.niedrig .mini-kreis-circle { border-color:rgba(251,191,36,0.7); color:var(--orange); animation:pulse-niedrig 2s infinite; }
  .mini-kreis.verfuegbar.niedrig .mini-kreis-label { color:var(--orange); }
  @keyframes pulse-niedrig { 0%,100%{opacity:1} 50%{opacity:0.6} }
  .mini-kreis.verkauft .mini-kreis-circle { border-color:rgba(251,191,36,0.5); color:var(--orange); }
  .mini-kreis.eigenbedarf .mini-kreis-circle { border-color:rgba(248,113,113,0.5); color:var(--red); }
  .mini-kreis.schwund .mini-kreis-circle { border-color:rgba(150,150,150,0.6); color:#999; background: radial-gradient(circle at 35% 30%, rgba(150,150,150,0.15), rgba(29,32,48,0.9)); }
  .mini-kreis.schwund .mini-kreis-label { color:#e08080; }

  /* VK-PREIS & GEWINN-KALKULATION */
  .vk-row { display:flex; align-items:center; gap:10px; margin-top:10px; flex-wrap:wrap; }
  .vk-input-wrap { display:flex; align-items:center; gap:6px; }
  .vk-input-wrap label { font-size:11px; color:var(--text-dim); font-weight:500; }
  .vk-input { width:90px; background:var(--blue-mid); border:1px solid var(--line); border-radius:10px; color:var(--text); font-family:var(--sans); font-size:13px; padding:7px 9px; outline:none; }
  .vk-input:focus { border-color:var(--blue-accent); }
  .calc-toggle-btn { font-size:12px; font-weight:600; color:var(--green); border:1px solid rgba(74,222,128,0.35); border-radius:10px; background:none; padding:7px 12px; cursor:pointer; }
  .calc-toggle-btn:hover { background:rgba(74,222,128,0.12); }

  .kalkulation-box { display:none; margin-top:14px; width:100%; border-top:1px dashed var(--line); padding-top:14px; }
  .kalkulation-box.open { display:block; }
  .kalkulation-hinweis { font-size:11px; color:var(--text-dim); margin-bottom:12px; }
  .plattform-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
  .plattform-card { background:var(--blue-mid); border:1px solid var(--line); border-radius:14px; padding:14px; }
  .plattform-card .plattform-name { font-size:12px; font-weight:600; color:var(--blue-bright); margin-bottom:8px; border-bottom:1px solid var(--line); padding-bottom:6px; }
  .plattform-zeile { display:flex; justify-content:space-between; font-size:12px; padding:3px 0; }
  .plattform-zeile .label { color:var(--text-dim); font-size:11px; }
  .plattform-zeile .wert { font-weight:600; }
  .plattform-zeile.gewinn .wert { color:var(--green); }
  .plattform-zeile.gewinn { border-top:1px solid var(--line); margin-top:4px; padding-top:6px; }
  .plattform-zeile.ruecklage .wert { color:var(--orange); }
  .plattform-zeile.verbleibt { border-top:1px solid var(--line); margin-top:4px; padding-top:6px; }
  .plattform-zeile.verbleibt .wert { color:var(--green); font-size:14px; }
  .plattform-total { margin-top:10px; padding-top:8px; border-top:1px dashed var(--line); font-size:11px; color:var(--text-dim); }
  .plattform-total .total-wert { color:var(--green); font-size:15px; font-weight:700; display:block; margin-top:2px; }
  .plattform-zeile.negativ .wert { color:var(--red) !important; }
  @media(max-width:768px) { .plattform-grid { grid-template-columns:1fr; } }

  /* MODAL */
  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:200; align-items:center; justify-content:center; }
  .modal-overlay.open { display:flex; }
  .modal { background:var(--navy2); border:1px solid var(--line); border-radius:20px; width:100%; max-width:480px; margin:20px; position:relative; max-height:90vh; overflow-y:auto; }
  .modal-header { display:flex; align-items:center; justify-content:space-between; padding:18px 22px; border-bottom:1px solid var(--line); position:sticky; top:0; background:var(--navy2); border-radius:20px 20px 0 0; z-index:2; }
  .modal-title { font-size:16px; font-weight:600; color:#F5F6FA; }
  .modal-close { background:none; border:none; color:var(--text-dim); font-size:18px; cursor:pointer; transition:color 0.2s; }
  .modal-close:hover { color:var(--red); }
  .modal-body { padding:22px; display:flex; flex-direction:column; gap:14px; }
  .modal-footer { padding:16px 22px; border-top:1px solid var(--line); display:flex; justify-content:flex-end; gap:10px; }

  /* FORM */
  .field-label { font-size:12px; font-weight:500; color:var(--text-dim); margin-bottom:6px; display:flex; align-items:center; gap:6px; }
  .field-input, .field-select, .field-textarea {
    width:100%; background:var(--blue-mid); border:1px solid var(--line); border-radius:12px;
    color:var(--text); font-family:var(--sans); font-size:14px; padding:11px 13px; outline:none;
    transition:border-color 0.15s;
  }
  .field-input:focus, .field-select:focus, .field-textarea:focus { border-color:var(--blue-accent); }
  .field-select option { background:var(--navy2); }
  .field-textarea { resize:vertical; min-height:80px; }
  .field-hint { font-size:11px; color:var(--text-dim); margin-top:-6px; }
  .typ-fields { display:none; flex-direction:column; gap:14px; border-left:2px solid var(--line); padding-left:12px; }
  .typ-fields.active { display:flex; }

  /* TOAST */
  .toast { position:fixed; bottom:24px; right:24px; background:var(--navy2); border:1px solid var(--line); border-left:3px solid var(--green); border-radius:14px; padding:13px 20px; font-size:13px; z-index:300; opacity:0; transform:translateY(10px); transition:all 0.3s; pointer-events:none; }
  .toast.show { opacity:1; transform:translateY(0); }
  .toast.error { border-left-color:var(--red); }

  /* EMPTY STATE */
  .empty { font-size:13px; color:var(--text-dim); text-align:center; padding:30px; }

  /* STATUS SELECT */
  select.status-select { background:none; border:1px solid var(--line); border-radius:8px; color:var(--text-dim); font-family:var(--sans); font-size:11px; padding:4px 8px; cursor:pointer; }
  select.status-select option { background:var(--navy2); }

  /* VERLAUF */
  .verlauf-item { padding:9px 0; border-bottom:1px solid var(--line); font-size:12px; }
  .verlauf-item:last-child { border-bottom:none; }
  .verlauf-typ { display:inline-block; padding:2px 9px; border-radius:8px; border:1px solid; margin-right:8px; font-size:11px; }
  .verlauf-typ.zugang_einkauf { color:var(--green); border-color:rgba(74,222,128,0.3); }
  .verlauf-typ.abgang_verkauf { color:var(--orange); border-color:rgba(251,191,36,0.3); }
  .verlauf-typ.abgang_eigenbedarf { color:var(--blue-bright); border-color:rgba(124,124,255,0.3); }
  .verlauf-typ.retoure { color:var(--red); border-color:rgba(248,113,113,0.3); }
  .verlauf-typ.korrektur { color:var(--text-dim); border-color:var(--line); }

  /* MOBILE BOTTOM NAV — ersetzt die Sidebar auf kleinen Screens */
  .mobile-nav {
    display:none;
    position:fixed; bottom:0; left:0; right:0;
    background:var(--navy2); border-top:1px solid var(--line);
    z-index:150;
    padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 26px);
  }
  .mobile-nav-inner { display:flex; height:60px; overflow:visible; }
  .mobile-nav-item {
    flex:1 0 auto; min-width:58px; display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:3px; color:var(--text-dim); font-size:9.5px; font-weight:500;
    cursor:pointer; position:relative;
    text-decoration:none; border:none; background:none;
  }
  .mobile-nav-item .mn-icon { font-size:18px; line-height:1; }
  .mobile-nav-item.active { color:var(--blue-bright); }
  .mobile-nav-item .mn-badge {
    position:absolute; top:4px; right:22%; background:var(--red); color:#fff;
    font-size:9px; padding:1px 5px; border-radius:8px; font-weight:600;
  }

  /* MITTLERER, HERVORGEHOBENER RUNDER BUTTON (Bestellungen) */
  .mobile-nav-item-fab { position:relative; }
  .mn-icon-fab {
    position:absolute; top:-30px; left:50%; transform:translateX(-50%);
    width:56px; height:56px; border-radius:50%;
    background:linear-gradient(145deg, var(--blue-bright), var(--blue-accent));
    display:flex; align-items:center; justify-content:center;
    font-size:26px; box-shadow:0 4px 14px rgba(124,124,255,0.4), 0 0 0 4px var(--navy);
  }
  .mobile-nav-item-fab span:last-child {
    margin-top:30px; font-size:9px;
  }
  .mobile-nav-item-fab.active .mn-icon-fab {
    background:linear-gradient(145deg, #ABABFF, var(--blue-bright));
  }

  /* MEHR-MENÜ */
  .mehr-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:200;
    align-items:flex-end;
  }
  .mehr-overlay.open { display:flex; }
  .mehr-sheet {
    width:100%; background:var(--navy2); border-top:1px solid var(--line);
    border-radius:20px 20px 0 0; padding:14px 20px calc(20px + env(safe-area-inset-bottom, 0px));
    animation:mehrSlideUp 0.2s ease;
  }
  @keyframes mehrSlideUp { from{transform:translateY(100%)} to{transform:translateY(0)} }
  .mehr-handle { width:36px; height:4px; background:var(--line); border-radius:2px; margin:0 auto 16px; }
  .mehr-titel { font-size:15px; font-weight:600; color:#F5F6FA; margin-bottom:16px; text-align:center; }
  .mehr-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:16px; }
  .mehr-item {
    display:flex; flex-direction:column; align-items:center; gap:6px; padding:16px 6px;
    background:var(--blue-mid); border:1px solid var(--line); border-radius:14px; text-decoration:none;
    color:var(--text); font-size:11px; text-align:center;
  }
  .mehr-icon { font-size:22px; }
  .mehr-schliessen {
    width:100%; padding:13px; background:var(--blue-mid); border:1px solid var(--line); border-radius:14px;
    color:var(--text-dim); font-family:var(--sans); font-size:13px; font-weight:600;
    cursor:pointer;
  }

  @media(max-width:768px) {
    .sidebar { display:none; }
    .mobile-nav { display:block; }
    .main { margin-left:0; padding:16px; padding-bottom:110px; }
    .topbar { padding:0 14px; }
    .topbar-right { gap:8px; }
    .topbar-right .user-badge { display:none; }
    .topbar-title { font-size:13px; }
    .logo-mark { width:28px; height:28px; }
    .stats-row { grid-template-columns:1fr 1fr; gap:10px; }
    .stat-card { padding:14px 16px; }
    .stat-value { font-size:22px; }
    .grid-2 { grid-template-columns:1fr; }
    .page-header { flex-direction:column; align-items:stretch; }
    .page-actions { width:100%; }
    .page-actions .btn, .page-actions a.btn { flex:1; text-align:center; }
    .modal-body > div[style*="grid-template-columns:1fr 1fr"] { grid-template-columns:1fr !important; }
    .vk-row { flex-direction:column; align-items:stretch; }
    .modal { margin:12px; max-height:85vh; }
    .lager-item { flex-wrap:wrap; }
    .lager-item > div[style*="justify-content:flex-end"] { width:100%; justify-content:flex-start !important; margin-top:8px; }
    table { font-size:12px; }
  }

  @media(max-width:420px) {
    .kreise-row { justify-content:center; }
    .kreis-circle { width:78px; height:78px; }
    .mini-kreis-circle { width:48px; height:48px; font-size:13px; }
  }

  /* STICKY NOTES */
  .sticky-notes-bereich { margin-bottom: 20px; }
  .sticky-notes-grid { display: flex; flex-wrap: wrap; gap: 16px; }
  .sticky-note {
    width: 160px; min-height: 130px; padding: 14px 12px 10px;
    font-family: 'Comic Sans MS', 'Segoe Print', cursive, var(--sans);
    font-size: 13px; line-height: 1.4; color: #2a2a2a;
    border-radius: 6px;
    box-shadow: 2px 4px 10px rgba(0,0,0,0.3);
    position: relative; display: flex; flex-direction: column;
    transition: transform 0.15s;
  }
  .sticky-note:nth-child(3n+1) { transform: rotate(-2deg); }
  .sticky-note:nth-child(3n+2) { transform: rotate(1.5deg); }
  .sticky-note:nth-child(3n+3) { transform: rotate(-1deg); }
  .sticky-note:hover { transform: rotate(0deg) scale(1.03); z-index: 5; }
  .sticky-note.farbe-gelb { background: #fff7a3; }
  .sticky-note.farbe-rosa { background: #ffc9de; }
  .sticky-note.farbe-blau { background: #b8e2ff; }
  .sticky-note.farbe-gruen { background: #c3f7c3; }
  .sticky-note-text { flex: 1; word-break: break-word; white-space: pre-wrap; }
  .sticky-note-meta { font-size: 10px; color: rgba(0,0,0,0.45); margin-top: 8px; }
  .sticky-note-close {
    position: absolute; top: 4px; right: 6px; background: none; border: none;
    font-size: 12px; color: rgba(0,0,0,0.3); cursor: pointer; padding: 2px 4px;
  }
  .sticky-note-close:hover { color: rgba(0,0,0,0.7); }
  .sticky-note-neu {
    width: 160px; min-height: 130px; border: 2px dashed var(--line); border-radius: 14px;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    color: var(--text-dim); cursor: pointer; transition: all 0.15s;
  }
  .sticky-note-neu:hover { border-color: var(--blue-bright); color: var(--blue-bright); }

  .sticky-note-modal-overlay {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 500;
    align-items: center; justify-content: center;
  }
  .sticky-note-modal-overlay.open { display: flex; }
  .sticky-note-modal { background: var(--navy2); border: 1px solid var(--line); border-radius: 18px; width: 100%; max-width: 380px; margin: 20px; padding: 22px; }
  .sticky-note-modal textarea {
    width: 100%; min-height: 100px; background: var(--blue-mid); border: 1px solid var(--line); border-radius: 12px;
    color: var(--text); font-family: var(--sans); font-size: 13px; padding: 11px 13px; outline: none;
    resize: vertical; margin-bottom: 14px;
  }
  .sticky-note-farben { display: flex; gap: 8px; margin-bottom: 16px; }
  .sticky-note-farbwahl { width: 34px; height: 34px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; }
  .sticky-note-farbwahl.aktiv { border-color: #fff; }
  .sticky-note-farbwahl.gelb { background: #fff7a3; }
  .sticky-note-farbwahl.rosa { background: #ffc9de; }
  .sticky-note-farbwahl.blau { background: #b8e2ff; }
  .sticky-note-farbwahl.gruen { background: #c3f7c3; }
</style>
<script src="https://cdn.jsdelivr.net/npm/@ericblade/quagga2@1.8.2/dist/quagga.min.js"></script>
</head>
<body>
<div class="scanlines"></div>

<!-- TOPBAR -->
<div class="topbar">
  <div class="topbar-left">
    <div class="logo-mark"><span>NA</span></div>
    <div class="topbar-title">NA Ops Hub</div>
  </div>
  <div class="topbar-right">
    <span><span class="status-dot"></span>ONLINE</span>
    <span id="clock">--:--:--</span>
    <div class="user-badge"><?= strtoupper($user['username']) ?> // <?= strtoupper($user['name']) ?></div>
    <form method="post" action="auth.php" style="display:inline;">
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="logout-btn">Logout</button>
    </form>
  </div>
</div>

<!-- SIDEBAR -->
<div class="sidebar">
  <div class="nav-section">Navigation</div>
  <div class="nav-item active" onclick="showPage('dashboard',this)"><span class="nav-icon">▦</span> Dashboard</div>
  <?php if ($zugriff_lager): ?>
  <div class="nav-item" onclick="showPage('lager',this)"><span class="nav-icon">◫</span> Lager <span class="nav-badge"><?= $lager_count ?></span></div>
  <?php endif; ?>
  <?php if ($zugriff_aufgaben): ?>
  <div class="nav-item" onclick="window.location.href='aufgaben/aufgaben.php'"><span class="nav-icon">◈</span> Aufgaben <?php if($todo_offen > 0): ?><span class="nav-badge urgent"><?= $todo_offen ?></span><?php endif; ?></div>
  <?php endif; ?>
  <?php if ($zugriff_bestellungen): ?>
  <div class="nav-item" onclick="window.location.href='bestellungen/bestellungen.php'"><span class="nav-icon">📦</span> Bestellungen</div>
  <?php endif; ?>
  <?php if ($zugriff_clean): ?>
  <div class="nav-item" onclick="window.location.href='clean/pipeline.php'"><span class="nav-icon">🧹</span> NA Clean Service</div>
  <?php endif; ?>
  <div class="nav-spacer"></div>
  <div class="nav-item" onclick="window.location.href='marketing.php'"><span class="nav-icon">📈</span> Marketing</div>
  <div class="nav-item" onclick="window.location.href='trip.php'"><span class="nav-icon">🚗</span> Sammel-Trips</div>
  <div class="nav-item" onclick="window.location.href='auslagen.php'"><span class="nav-icon">💸</span> Auslagen</div>
  <?php if ($zugriff_dokumente): ?>
  <div class="nav-item" onclick="window.location.href='documents.php'"><span class="nav-icon">📄</span> Dokumente</div>
  <?php endif; ?>
  <?php if ($zugriff_kalkulator): ?>
  <div class="nav-item" onclick="window.location.href='rechner.php'"><span class="nav-icon">🧮</span> Kalkulator</div>
  <?php endif; ?>
  <?php if ($zugriff_umsatz): ?>
  <div class="nav-item" onclick="window.location.href='umsatz/dashboard.php'"><span class="nav-icon">📊</span> Umsatz</div>
  <?php endif; ?>
  <?php if ($zugriff_nachricht): ?>
  <div class="nav-item" onclick="window.location.href='telegram/senden.php'"><span class="nav-icon">💬</span> Nachricht senden</div>
  <?php endif; ?>
  <?php if ($zugriff_export): ?>
  <div class="nav-item" onclick="window.location.href='export/export.php'"><span class="nav-icon">⬇️</span> Export</div>
  <?php endif; ?>
  <?php if ($zugriff_produkttexte): ?>
  <div class="nav-item" onclick="window.location.href='produkttexte/produkttexte.php'"><span class="nav-icon">✨</span> Produkttexte</div>
  <?php endif; ?>
  <?php if ($zugriff_antworten): ?>
  <div class="nav-item" onclick="window.location.href='antworten/antworten.php'"><span class="nav-icon">💭</span> Antworten</div>
  <?php endif; ?>
  <?php if ($zugriff_steuer): ?>
  <div class="nav-item" onclick="window.location.href='steuer/steuer.php'"><span class="nav-icon">🏦</span> Steuerrücklage</div>
  <?php endif; ?>
  <div class="nav-section">System</div>
  <?php if ($ist_systemverwalter): ?>
  <div class="nav-item" onclick="window.location.href='berechtigungen/benutzerverwaltung.php'"><span class="nav-icon">🔐</span> Admin</div>
  <?php endif; ?>
  <div class="nav-item" style="color:var(--text-dim);font-size:9px;">v2.0.0 — LAGER-MODUL v2</div>
</div>

<!-- MOBILE BOTTOM NAV -->
<div class="mobile-nav">
  <div class="mobile-nav-inner">
  <button class="mobile-nav-item active" data-page="dashboard" onclick="showPage('dashboard', null)">
    <span class="mn-icon">▦</span><span>Dashboard</span>
  </button>
  <?php if ($zugriff_lager): ?>
  <button class="mobile-nav-item" data-page="lager" onclick="showPage('lager', null)">
    <span class="mn-icon">◫</span><span>Lager</span>
    <?php if($lager_count > 0): ?><span class="mn-badge"><?= $lager_count ?></span><?php endif; ?>
  </button>
  <?php endif; ?>
  <?php if ($zugriff_bestellungen): ?>
  <button class="mobile-nav-item mobile-nav-item-fab" onclick="window.location.href='bestellungen/bestellungen.php'">
    <span class="mn-icon-fab">📦</span><span>Bestellungen</span>
  </button>
  <?php endif; ?>
  <?php if ($zugriff_aufgaben): ?>
  <button class="mobile-nav-item" onclick="window.location.href='aufgaben/aufgaben.php'">
    <span class="mn-icon">◈</span><span>Aufgaben</span>
    <?php if($todo_offen > 0): ?><span class="mn-badge"><?= $todo_offen ?></span><?php endif; ?>
  </button>
  <?php endif; ?>
  <button class="mobile-nav-item" onclick="toggleMehrMenu()">
    <span class="mn-icon">⋯</span><span>Mehr</span>
  </button>
</div>
</div>

<!-- MEHR-MENÜ (Bottom Sheet) -->
<div class="mehr-overlay" id="mehr-overlay" onclick="closeMehrMenu(event)">
  <div class="mehr-sheet" onclick="event.stopPropagation()">
    <div class="mehr-handle"></div>
    <div class="mehr-titel">Weitere Bereiche</div>
    <div class="mehr-grid">
      <?php if ($zugriff_clean): ?><a href="clean/pipeline.php" class="mehr-item"><span class="mehr-icon">🧹</span><span>Clean Service</span></a><?php endif; ?>
      <a href="marketing.php" class="mehr-item"><span class="mehr-icon">📈</span><span>Marketing</span></a>
      <a href="trip.php" class="mehr-item"><span class="mehr-icon">🚗</span><span>Sammel-Trips</span></a>
      <a href="auslagen.php" class="mehr-item"><span class="mehr-icon">💸</span><span>Auslagen</span></a>
      <?php if ($zugriff_dokumente): ?><a href="documents.php" class="mehr-item"><span class="mehr-icon">📄</span><span>Dokumente</span></a><?php endif; ?>
      <?php if ($zugriff_kalkulator): ?><a href="rechner.php" class="mehr-item"><span class="mehr-icon">🧮</span><span>Kalkulator</span></a><?php endif; ?>
      <?php if ($zugriff_umsatz): ?><a href="umsatz/dashboard.php" class="mehr-item"><span class="mehr-icon">📊</span><span>Umsatz</span></a><?php endif; ?>
      <?php if ($zugriff_nachricht): ?><a href="telegram/senden.php" class="mehr-item"><span class="mehr-icon">💬</span><span>Nachricht</span></a><?php endif; ?>
      <?php if ($zugriff_export): ?><a href="export/export.php" class="mehr-item"><span class="mehr-icon">⬇️</span><span>Export</span></a><?php endif; ?>
      <?php if ($zugriff_produkttexte): ?><a href="produkttexte/produkttexte.php" class="mehr-item"><span class="mehr-icon">✨</span><span>Produkttexte</span></a><?php endif; ?>
      <?php if ($zugriff_antworten): ?><a href="antworten/antworten.php" class="mehr-item"><span class="mehr-icon">💭</span><span>Antworten</span></a><?php endif; ?>
      <?php if ($zugriff_steuer): ?><a href="steuer/steuer.php" class="mehr-item"><span class="mehr-icon">🏦</span><span>Steuerrücklage</span></a><?php endif; ?>
      <?php if ($ist_systemverwalter): ?><a href="berechtigungen/benutzerverwaltung.php" class="mehr-item"><span class="mehr-icon">🔐</span><span>Admin</span></a><?php endif; ?>
    </div>
    <button class="mehr-schliessen" onclick="closeMehrMenu()">Schließen</button>
  </div>
</div>

<!-- MAIN -->
<div class="main">

  <!-- ── DASHBOARD ── -->
  <div class="page active" id="page-dashboard">
    <div class="page-header">
      <div>
        <div class="page-title">Dashboard</div>
        <div class="page-sub">Übersicht — NA Commerce Solutions GmbH</div>
      </div>
      <div style="font-family:var(--mono);font-size:10px;color:var(--text-dim);text-align:right;">
        <div id="date-display"></div>
      </div>
    </div>

    <!-- STICKY NOTES -->
    <?php $alle_notizen = db()->query("SELECT * FROM dashboard_notizen ORDER BY erstellt_am DESC")->fetchAll(); ?>
    <div class="sticky-notes-bereich">
      <div class="sticky-notes-grid" id="sticky-notes-grid">
        <?php foreach ($alle_notizen as $n): ?>
        <div class="sticky-note farbe-<?= $n['farbe'] ?>" id="notiz-<?= $n['id'] ?>">
          <button class="sticky-note-close" onclick="notizLoeschen(<?= $n['id'] ?>)">✕</button>
          <div class="sticky-note-text"><?= nl2br(htmlspecialchars($n['text'])) ?></div>
          <div class="sticky-note-meta"><?= $n['erstellt_von'] === 'qaf' ? 'Artis' : ($n['erstellt_von'] === 'qns' ? 'Nour' : htmlspecialchars($n['erstellt_von'])) ?></div>
        </div>
        <?php endforeach; ?>
        <div class="sticky-note-neu" onclick="stickyNoteFormOeffnen()">
          <span style="font-size:24px;">+</span>
          <span style="font-family:var(--mono); font-size:10px; margin-top:4px;">NOTIZ</span>
        </div>
      </div>
    </div>

    <!-- HEUTE-WIDGET -->
    <div class="panel" style="border-color:rgba(148,148,255,0.35);">
      <div class="panel-header">
        <div class="panel-title">Heute — <?= date('d.m.Y') ?></div>
      </div>
      <div class="panel-body">
        <?php
        $widget_leer = empty($heute_aufgaben) && $heute_neue_bestellungen === 0 && empty($heute_niedrig) && $heute_ueberfaellig_count === 0;
        ?>
        <?php if ($widget_leer): ?>
          <div class="empty">✓ Nichts Dringendes heute — alles im grünen Bereich</div>
        <?php else: ?>
          <div style="display:flex; flex-direction:column; gap:10px;">
            <?php if ($heute_neue_bestellungen > 0): ?>
              <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:18px;">📦</span>
                <span><strong><?= $heute_neue_bestellungen ?></strong> neue Bestellung<?= $heute_neue_bestellungen !== 1 ? 'en' : '' ?> warten auf Annahme</span>
                <a href="bestellungen/bestellungen.php" style="margin-left:auto; font-family:var(--mono); font-size:9px; color:var(--blue-bright); text-decoration:none;">ANSEHEN →</a>
              </div>
            <?php endif; ?>

            <?php foreach ($heute_aufgaben as $ha): ?>
              <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:18px;"><?= $ha['prioritaet'] === 'hoch' ? '🔴' : ($ha['prioritaet'] === 'mittel' ? '🟠' : '🟢') ?></span>
                <span><?= htmlspecialchars($ha['titel']) ?> — <span style="color:var(--text-dim);"><?= strtoupper($ha['assignee']) ?></span></span>
              </div>
            <?php endforeach; ?>

            <?php if ($heute_ueberfaellig_count > 0): ?>
              <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:18px;">⚠️</span>
                <span><strong><?= $heute_ueberfaellig_count ?></strong> überfällige Aufgabe<?= $heute_ueberfaellig_count !== 1 ? 'n' : '' ?></span>
                <a href="aufgaben/aufgaben.php" style="margin-left:auto; font-family:var(--mono); font-size:9px; color:var(--blue-bright); text-decoration:none;">ANSEHEN →</a>
              </div>
            <?php endif; ?>

            <?php if (!empty($heute_niedrig)): ?>
              <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:18px;">📉</span>
                <span>Niedriger Bestand: <strong><?= htmlspecialchars(implode(', ', array_slice($heute_niedrig, 0, 3))) ?></strong><?= count($heute_niedrig) > 3 ? ' +' . (count($heute_niedrig) - 3) . ' weitere' : '' ?></span>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-label">Lager · Produkte</div>
        <div class="stat-value"><?= $lager_count ?></div>
        <div class="stat-detail">Aktive Artikel</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Lager · EK-Wert</div>
        <div class="stat-value green">€<?= number_format($lager_ek_gesamt, 0, ',', '.') ?></div>
        <div class="stat-detail">Aktueller Bestandswert</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Aufgaben · Offen</div>
        <div class="stat-value <?= $todo_offen > 0 ? 'orange' : '' ?>"><?= $todo_offen ?></div>
        <div class="stat-detail">Offene Aufgaben</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Lagerreport</div>
        <div class="stat-value" style="font-size:14px;"><a href="lager/history.php" style="color:var(--blue-bright);text-decoration:none;">HISTORIE →</a></div>
        <div class="stat-detail">Alle bisherigen Reports</div>
      </div>
    </div>

    <div class="grid-2">
      <!-- Recent Todos -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">Aufgaben</div>
          <button class="panel-action" onclick="window.location.href='aufgaben/aufgaben.php'">ALLE →</button>
        </div>
        <div class="panel-body">
          <?php $recent_todos = array_slice(array_filter($todos, fn($t) => $t['status'] !== 'erledigt'), 0, 4); ?>
          <?php if(empty($recent_todos)): ?>
            <div class="empty">Keine offenen Aufgaben</div>
          <?php else: ?>
            <?php foreach($recent_todos as $t): ?>
            <div class="todo-item">
              <div class="todo-priority <?= $t['prioritaet'] ?>"></div>
              <div class="todo-info">
                <div class="todo-title"><?= htmlspecialchars($t['titel']) ?></div>
                <div class="todo-meta">
                  <?php if($t['days_left'] !== null && $t['days_left'] <= 3): ?>
                    <span class="deadline-warn">⚠ <?= $t['days_left'] <= 0 ? 'ÜBERFÄLLIG' : $t['days_left'].' TAG(E)' ?></span>
                  <?php elseif($t['deadline']): ?>
                    <span><?= date('d.m.Y', strtotime($t['deadline'])) ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end;">
                <div class="todo-assignee"><?= strtoupper($t['assignee']) ?></div>
                <div class="todo-status <?= $t['status'] ?>"><?= strtoupper(str_replace('_',' ',$t['status'])) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Recent Lager -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">Lager</div>
          <button class="panel-action" onclick="showPage('lager',null)">ALLE →</button>
        </div>
        <div class="panel-body">
          <?php if(empty($produkte)): ?>
            <div class="empty">Kein Bestand</div>
          <?php else: ?>
            <?php foreach(array_slice($produkte,0,4) as $p): ?>
            <div class="lager-item">
              <div class="lager-img">
                <?php if($p['foto_url']): ?><img src="<?= htmlspecialchars($p['foto_url']) ?>" alt=""><?php else: ?>📦<?php endif; ?>
              </div>
              <div style="flex:1;">
                <div class="lager-name"><?= htmlspecialchars($p['name']) ?></div>
                <div class="lager-stock">Bestand: <span class="<?= $p['bestand'] <= 5 ? 'low' : '' ?>"><?= $p['bestand'] ?> Stk</span> · <?= htmlspecialchars($p['kategorie_name']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ── LAGER ── -->
  <div class="page" id="page-lager">
    <div class="page-header">
      <div><div class="page-title">Lager<?= $zeige_archiv ? ' — Archiv' : '' ?></div><div class="page-sub">Bestandsverwaltung · live berechnet aus Bewegungshistorie</div></div>
      <div class="page-actions">
        <a href="lager/history.php" class="btn" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);text-decoration:none;display:inline-block;">📄 REPORT-HISTORIE</a>
        <a href="lager/report_new.php" class="btn btn-terracotta" style="text-decoration:none;display:inline-block;">↓ REPORT ERSTELLEN</a>
        <a href="lager/auslaendische_vorsteuer.php" class="btn" style="color:var(--orange);border-color:rgba(251,191,36,0.4);text-decoration:none;display:inline-block;">🌍 AUSLÄNDISCHE MWST</a>
        <a href="lager/verpackungsmaterial.php" class="btn" style="color:var(--blue-bright);border-color:rgba(148,148,255,0.4);text-decoration:none;display:inline-block;">📦 VERPACKUNGSMATERIAL</a>
        <a href="lager/verpackungsmaterial.php" class="btn" style="color:var(--blue-bright);border-color:rgba(148,148,255,0.4);text-decoration:none;display:inline-block;">📦 VERPACKUNGSMATERIAL</a>
        <?php if (!$zeige_archiv && $zugriff_lager_bearbeiten): ?>
          <button class="btn btn-primary" onclick="openModal('modal-produkt-add')">+ PRODUKT</button>
          <button class="btn btn-primary" onclick="openBewegungModalGlobal()">± BEWEGUNG ERFASSEN</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="page-actions" style="margin-bottom:16px;">
      <form method="GET" style="display:flex; gap:8px; flex:1;" action="dashboard.php#lager">
        <?php if ($zeige_archiv): ?><input type="hidden" name="archiv" value="1"><?php endif; ?>
        <input type="text" name="suche" class="field-input" style="max-width:280px;" placeholder="🔍 Produkt suchen..." value="<?= htmlspecialchars($suche) ?>">
        <button type="submit" class="btn" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);">SUCHEN</button>
        <?php if ($suche !== ''): ?><a href="dashboard.php<?= $zeige_archiv ? '?archiv=1' : '' ?>#lager" class="btn" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);text-decoration:none;display:inline-flex;align-items:center;">✕</a><?php endif; ?>
      </form>
      <?php if ($zeige_archiv): ?>
        <a href="dashboard.php<?= $suche !== '' ? '?suche=' . urlencode($suche) : '' ?>#lager" class="btn btn-primary" style="text-decoration:none;display:inline-block;">◫ ZU AKTIVEN PRODUKTEN</a>
      <?php else: ?>
        <a href="dashboard.php?archiv=1<?= $suche !== '' ? '&suche=' . urlencode($suche) : '' ?>#lager" class="btn" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);text-decoration:none;display:inline-block;">🗄 ARCHIV ANSEHEN</a>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><?= $zeige_archiv ? 'Archivierte Produkte' : 'Aktueller Bestand' ?></div>
        <?php if (!$zeige_archiv && !$lager_preise_verbergen): ?><span style="font-family:var(--mono);font-size:10px;color:var(--green);">EK-WERT GESAMT: €<?= number_format($lager_ek_gesamt, 2, ',', '.') ?></span><?php endif; ?>
        <?php if ($zeige_archiv): ?><button class="btn btn-danger btn-sm" id="archiv-loeschmodus-btn" onclick="toggleArchivLoeschmodus()">🔓 LÖSCHMODUS</button><?php endif; ?>
      </div>
      <?php if (!$zeige_archiv): ?>
      <div class="kreise-row">
        <div class="kreis insgesamt">
          <div class="kreis-circle">
            <div class="kreis-value"><?= $lager_gesamt_insgesamt ?></div>
            <div class="kreis-einheit">STK</div>
          </div>
          <div class="kreis-label">Insgesamt<br>eingekauft</div>
        </div>
        <div class="kreis verfuegbar">
          <div class="kreis-circle">
            <div class="kreis-value"><?= $lager_stueck_verfuegbar ?></div>
            <div class="kreis-einheit">STK</div>
          </div>
          <div class="kreis-label">Verfügbar<br>jetzt</div>
        </div>
        <div class="kreis verkauft">
          <div class="kreis-circle">
            <div class="kreis-value"><?= $lager_gesamt_verkauft ?></div>
            <div class="kreis-einheit">STK</div>
          </div>
          <div class="kreis-label">Verkauft<br>gesamt</div>
        </div>
        <div class="kreis eigenbedarf">
          <div class="kreis-circle">
            <div class="kreis-value"><?= $lager_gesamt_eigenbedarf ?></div>
            <div class="kreis-einheit">STK</div>
          </div>
          <div class="kreis-label">Eigenbedarf<br>(abgeschrieben)</div>
        </div>
      </div>
      <?php endif; ?>
      <div class="panel-body">
        <?php if ($zeige_archiv): ?>
          <?php if (empty($produkte)): ?>
            <div class="empty">Archiv leer</div>
          <?php else: ?>
            <?php foreach ($produkte as $p): ?>
            <div class="lager-item" id="produkt-<?= $p['id'] ?>">
              <div class="lager-img" style="width:52px;height:52px;opacity:0.5;">
                <?php if($p['foto_url']): ?><img src="<?= htmlspecialchars($p['foto_url']) ?>" alt=""><?php else: ?>📦<?php endif; ?>
              </div>
              <div style="flex:1;">
                <div class="lager-name" style="color:var(--text-dim);"><?= htmlspecialchars($p['name']) ?></div>
                <div class="lager-ekwert"><?= $lager_preise_verbergen ? '' : 'Zuletzt Ø EK/Stk: €' . number_format($p['ek_preis_avg'],2,',','.') . ' · ' ?><?= htmlspecialchars($p['kategorie_name']) ?></div>
              </div>
              <button class="btn btn-primary btn-sm" onclick="openReaktivierenModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')">↺ REAKTIVIEREN</button>
              <button class="btn btn-danger btn-sm archiv-delete-btn" style="display:none;" onclick="produktEndgueltigLoeschen(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')">✕ ENDGÜLTIG LÖSCHEN</button>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php elseif(empty($produkte)): ?>
          <div class="empty">Kein Bestand — Produkt hinzufügen</div>
        <?php else: ?>
          <?php
          // Kategorien SIND direkt die Marken-Gruppen (CozyCore, NA Commerce,
          // Sonstiges, ...) — CozyCore immer zuerst, Rest alphabetisch
          $gruppiert = [];
          foreach ($produkte as $p) { $gruppiert[$p['kategorie_name']][] = $p; }
          uksort($gruppiert, fn($a,$b) => ($a === 'CozyCore' ? -1 : ($b === 'CozyCore' ? 1 : strcasecmp($a, $b))));
          ?>
          <?php foreach($gruppiert as $kat_name => $items):
              $is_cozycore = $kat_name === 'CozyCore';
          ?>
          <div class="kategorie-block">
            <div class="kategorie-label <?= $is_cozycore ? 'is-cozycore' : 'is-other' ?>">
              <span><?= $is_cozycore ? '◆ ' : '' ?><?= htmlspecialchars($kat_name) ?></span>
              <span class="kategorie-count"><?= count($items) ?> Artikel</span>
            </div>
            <?php foreach($items as $p): ?>
            <div class="lager-item" id="produkt-<?= $p['id'] ?>">
              <div class="lager-img" style="width:52px;height:52px;">
                <?php if($p['foto_url']): ?><img src="<?= htmlspecialchars($p['foto_url']) ?>" alt=""><?php else: ?>📦<?php endif; ?>
              </div>
              <div style="flex:1;">
                <div class="lager-name"><?= htmlspecialchars($p['name']) ?></div>
                <?php if (!$lager_preise_verbergen): ?>
                <div class="lager-ekwert">EK-Wert Bestand: €<?= number_format($p['ek_wert_bestand'], 2, ',', '.') ?> · Ø EK/Stk: €<?= number_format($p['ek_preis_avg'],2,',','.') ?></div>
                <?php endif; ?>
                <div class="mini-kreise-row">
                  <div class="mini-kreis insgesamt">
                    <div class="mini-kreis-circle"><?= $p['p_insgesamt'] ?></div>
                    <div class="mini-kreis-label">Insgesamt</div>
                  </div>
                  <?php
                  // Dynamische Niedrigbestand-Schwelle: 10% vom jemals eingekauften
                  // Bestand, mindestens aber 5 Stück — skaliert automatisch mit der
                  // Bestellgröße (bei 10 Stk warnt's bei 5, bei 1000 Stk bei 100).
                  $niedrig_schwelle = max(5, (int)round($p['p_insgesamt'] * 0.10));
                  $ist_niedrig = $p['bestand'] > 0 && $p['bestand'] <= $niedrig_schwelle;
                  ?>
                  <div class="mini-kreis verfuegbar<?= $ist_niedrig ? ' niedrig' : '' ?>">
                    <div class="mini-kreis-circle"><?= $p['bestand'] ?></div>
                    <div class="mini-kreis-label">Verfügbar<?= $ist_niedrig ? '<br>⚠️ Niedrig' : '' ?></div>
                  </div>
                  <div class="mini-kreis verkauft">
                    <div class="mini-kreis-circle"><?= $p['p_verkauft'] ?></div>
                    <div class="mini-kreis-label">Verkauft</div>
                  </div>
                  <div class="mini-kreis eigenbedarf">
                    <div class="mini-kreis-circle"><?= $p['p_eigenbedarf'] ?></div>
                    <div class="mini-kreis-label">Eigenbedarf</div>
                  </div>
                  <?php $p_schwund = $p['p_korrektur_saldo'] < 0 ? abs($p['p_korrektur_saldo']) : 0; ?>
                  <?php if ($p_schwund > 0): ?>
                  <div class="mini-kreis schwund">
                    <div class="mini-kreis-circle">-<?= $p_schwund ?></div>
                    <div class="mini-kreis-label">Schwund<br>(Zählungsdifferenz)</div>
                  </div>
                  <?php endif; ?>
                </div>
                <div class="vk-row">
                  <div class="vk-input-wrap">
                    <label>VK (€, brutto):</label>
                    <input type="number" step="0.01" class="vk-input"
                           id="vk-<?= $p['id'] ?>"
                           value="<?= $p['vk_preis_brutto'] !== null ? htmlspecialchars($p['vk_preis_brutto']) : '' ?>"
                           placeholder="0.00"
                           onchange="saveVkPreis(<?= $p['id'] ?>)"
                           oninput="renderKalkulation(<?= $p['id'] ?>)">
                  </div>
                  <button class="calc-toggle-btn" onclick="toggleKalkulation(<?= $p['id'] ?>)">📊 GEWINN-KALKULATION</button>
                </div>
                <div class="kalkulation-box" id="kalk-box-<?= $p['id'] ?>"
                     data-ek="<?= $p['ek_preis_avg'] ?>"
                     data-bestand="<?= $p['bestand'] ?>">
                  <div class="kalkulation-hinweis">Vereinfachte Kalkulation — keine Steuerberatung. Gebührensätze im Code anpassbar.</div>
                  <div class="plattform-grid" id="kalk-grid-<?= $p['id'] ?>"></div>
                </div>
              </div>
              <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                <button class="btn btn-sm" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);" onclick="openBearbeitenModal(<?= $p['id'] ?>)">✎ BEARBEITEN</button>
                <button class="btn btn-primary btn-sm" onclick="openBewegungModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>', <?= $p['bestand'] ?>)">± BEWEGUNG</button>
                <button class="btn btn-sm" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);" onclick="openVerlauf(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')">VERLAUF</button>
                <span onclick="letzteBewegungRueckgaengig(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')" title="Letzte Bewegung rückgängig machen" style="align-self:center; font-size:13px; opacity:0.3; cursor:pointer; transition:opacity 0.15s; padding:0 4px;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.3">↩</span>
                <button class="btn btn-sm" style="<?= $p['bestand'] > 0 ? 'color:var(--text-dim);border-color:rgba(139,143,168,0.15);opacity:0.4;cursor:not-allowed;' : 'color:var(--orange);border-color:rgba(251,191,36,0.35);' ?>"
                        onclick="archivProdukt(<?= $p['id'] ?>, <?= $p['bestand'] ?>)"
                        <?= $p['bestand'] > 0 ? 'title="Erst Bestand auf 0 bringen (verkaufen/verbrauchen)"' : '' ?>>🗄 ARCHIV</button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── TODOS ── -->
  <div class="page" id="page-todos">
    <div class="page-header">
      <div><div class="page-title">Aufgaben</div><div class="page-sub">Task Management</div></div>
      <button class="btn btn-primary" onclick="openModal('modal-todo-add')">+ AUFGABE ERSTELLEN</button>
    </div>
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Alle Aufgaben</div>
        <div style="display:flex;gap:12px;">
          <span style="font-family:var(--mono);font-size:9px;color:var(--red);letter-spacing:2px;">● HOCH</span>
          <span style="font-family:var(--mono);font-size:9px;color:var(--orange);letter-spacing:2px;">● MITTEL</span>
          <span style="font-family:var(--mono);font-size:9px;color:var(--green);letter-spacing:2px;">● NIEDRIG</span>
        </div>
      </div>
      <div class="panel-body">
        <?php if(empty($todos)): ?>
          <div class="empty">Keine Aufgaben — erstelle eine neue</div>
        <?php else: ?>
          <?php foreach($todos as $t): ?>
          <div class="todo-item">
            <div class="todo-priority <?= $t['prioritaet'] ?>" style="height:52px;"></div>
            <div class="todo-info">
              <div class="todo-title"><?= htmlspecialchars($t['titel']) ?></div>
              <?php if($t['beschreibung']): ?><div style="font-size:12px;color:var(--text-dim);margin:3px 0;"><?= htmlspecialchars($t['beschreibung']) ?></div><?php endif; ?>
              <div class="todo-meta" style="margin-top:4px;">
                <?php if($t['days_left'] !== null && $t['days_left'] <= 3): ?>
                  <span class="deadline-warn">⚠ <?= $t['days_left'] <= 0 ? 'ÜBERFÄLLIG' : 'NOCH '.$t['days_left'].' TAG(E)' ?></span>
                <?php endif; ?>
                <?php if($t['deadline']): ?><span>📅 <?= date('d.m.Y',strtotime($t['deadline'])) ?></span><?php endif; ?>
                <span>VON: <?= strtoupper($t['ersteller']) ?></span>
              </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">
              <div class="todo-assignee"><?= strtoupper($t['assignee']) ?></div>
              <select class="status-select" onchange="updateTodoStatus(<?= $t['id'] ?>, this.value)">
                <option value="offen" <?= $t['status']==='offen'?'selected':'' ?>>OFFEN</option>
                <option value="in_bearbeitung" <?= $t['status']==='in_bearbeitung'?'selected':'' ?>>AKTIV</option>
                <option value="erledigt" <?= $t['status']==='erledigt'?'selected':'' ?>>ERLEDIGT</option>
              </select>
              <button class="btn btn-danger btn-sm" onclick="deleteTodo(<?= $t['id'] ?>)">✕ LÖSCHEN</button>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /main -->

<!-- ── MODALS ── -->

<!-- Produkt hinzufügen -->
<div class="modal-overlay" id="modal-produkt-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Produkt hinzufügen</div>
      <button class="modal-close" onclick="closeModal('modal-produkt-add')">✕</button>
    </div>
    <div class="modal-body">
      <div><div class="field-label">Produktname</div><input class="field-input" id="p-name" type="text" placeholder="z.B. Wärmflasche Premium"></div>
      <div><div class="field-label">Kategorie</div>
        <select class="field-select" id="p-kategorie">
          <?php foreach($kategorien as $k): ?>
            <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="field-hint">"CozyCore" = CozyCore-Branding im Report, alles andere = NA-Branding</div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div><div class="field-label">EK-Preis netto (€)</div><input class="field-input" id="p-ek" type="number" step="0.01" value="0"></div>
        <div><div class="field-label">Vorsteuersatz (%)</div><input class="field-input" id="p-vst" type="number" step="0.01" value="19"></div>
      </div>
      <div><div class="field-label">Produktfoto (optional)</div><input class="field-input" id="p-foto" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" style="padding:8px 12px;cursor:pointer;"></div>

      <div class="field-label" style="margin-top:6px;border-top:1px dashed rgba(124,124,255,0.2);padding-top:14px;">SKUs für automatische Bestellzuordnung (später, optional)</div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
        <div><div class="field-label" style="font-size:8px;">eBay</div><input class="field-input" id="p-ebay-sku" type="text" placeholder="SKU"></div>
        <div><div class="field-label" style="font-size:8px;">Amazon</div><input class="field-input" id="p-amazon-sku" type="text" placeholder="ASIN/SKU"></div>
        <div><div class="field-label" style="font-size:8px;">TikTok</div><input class="field-input" id="p-tiktok-sku" type="text" placeholder="SKU"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);" onclick="closeModal('modal-produkt-add')">ABBRECHEN</button>
      <button class="btn btn-primary" onclick="addProdukt()">SPEICHERN</button>
    </div>
  </div>
</div>

<!-- Bewegung erfassen -->
<div class="modal-overlay" id="modal-bewegung-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="bewegung-title">Bewegung erfassen</div>
      <button class="modal-close" onclick="closeModal('modal-bewegung-add')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="b-produkt-id">
      <div id="b-produkt-select-wrap">
        <div class="field-label" style="display:flex; justify-content:space-between; align-items:center;">
          <span>Produkt</span>
          <span onclick="barcodeScanStarten('b-produkt')" style="cursor:pointer; color:var(--blue-bright); font-family:var(--mono); font-size:9px;">📷 SCANNEN</span>
        </div>
        <select class="field-select" id="b-produkt" onchange="produktGewechselt()">
          <?php foreach($produkte as $p): ?>
            <option value="<?= $p['id'] ?>" data-bestand="<?= $p['bestand'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['kategorie_name']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <div class="field-label">Art der Bewegung</div>
        <select class="field-select" id="b-typ" onchange="toggleTypFields()">
          <option value="zugang_einkauf">Einkauf (Zugang)</option>
          <option value="abgang_verkauf">Verkauf (Abgang)</option>
          <option value="abgang_eigenbedarf">Eigenbedarf (Abgang)</option>
          <option value="retoure">Retoure (Zugang, falls wieder verkaufsfähig)</option>
          <option value="korrektur">Korrektur (z.B. nach Zählung)</option>
        </select>
      </div>

      <div><div class="field-label" id="b-menge-label">Menge (Stück)</div><input class="field-input" id="b-menge" type="number" value="1" min="1"></div>
      <div class="field-hint" id="b-menge-hinweis" style="display:none;"></div>
      <div><div class="field-label">Datum</div><input class="field-input" id="b-datum" type="date"></div>

      <!-- Einkauf -->
      <div class="typ-fields" data-typ="zugang_einkauf">
        <div><div class="field-label">EK-Preis dieser Charge (€/Stk, netto)</div><input class="field-input" id="b-ek" type="number" step="0.01" value="0"></div>
      </div>

      <!-- Verkauf -->
      <div class="typ-fields" data-typ="abgang_verkauf">
        <div><div class="field-label">Verkaufskanal</div>
          <select class="field-select" id="b-kanal">
            <option value="amazon">Amazon</option>
            <option value="ebay">eBay</option>
            <option value="tiktok">TikTok Shop</option>
            <option value="sonstiges">Sonstiges</option>
          </select>
        </div>
        <div><div class="field-label">Verkaufspreis (€, brutto)</div><input class="field-input" id="b-verkaufspreis" type="number" step="0.01" value="0"></div>
        <div><div class="field-label">Bestellnummer (optional)</div><input class="field-input" id="b-bestellnr" type="text"></div>
      </div>

      <!-- Eigenbedarf -->
      <div class="typ-fields" data-typ="abgang_eigenbedarf">
        <div><div class="field-label">Grund</div><input class="field-input" id="b-eigenbedarf-grund" type="text" placeholder="z.B. Produktshooting, Muster"></div>
      </div>

      <!-- Retoure -->
      <div class="typ-fields" data-typ="retoure">
        <div><div class="field-label">Grund</div><input class="field-input" id="b-retoure-grund" type="text" placeholder="z.B. Kunde falsch bestellt"></div>
        <div><div class="field-label">Wieder verkaufsfähig?</div>
          <select class="field-select" id="b-wieder-verkaufsfaehig">
            <option value="1">Ja — zurück in den Bestand</option>
            <option value="0">Nein — beschädigt/nicht mehr verkaufsfähig</option>
          </select>
        </div>
      </div>

      <!-- Korrektur -->
      <div class="typ-fields" data-typ="korrektur">
        <div><div class="field-label">Grund der Korrektur</div><input class="field-input" id="b-korrektur-grund" type="text" placeholder="z.B. Inventurzählung 31.12."></div>
      </div>

      <div><div class="field-label">Notiz (optional)</div><input class="field-input" id="b-notiz" type="text"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);" onclick="closeModal('modal-bewegung-add')">ABBRECHEN</button>
      <button class="btn btn-primary" onclick="addBewegung()">BESTÄTIGEN</button>
    </div>
  </div>
</div>

<!-- Produkt bearbeiten -->
<div class="modal-overlay" id="modal-produkt-bearbeiten">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="bearbeiten-titel">Produkt bearbeiten</div>
      <button class="modal-close" onclick="closeModal('modal-produkt-bearbeiten')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="be-id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div><div class="field-label">EK-Preis netto (€)</div><input class="field-input" id="be-ek" type="number" step="0.01"></div>
        <div><div class="field-label">VK-Preis brutto (€)</div><input class="field-input" id="be-vk" type="number" step="0.01"></div>
      </div>
      <div class="field-label" style="margin-top:6px;border-top:1px dashed rgba(124,124,255,0.2);padding-top:14px;">SKUs für automatische Bestellzuordnung</div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
        <div><div class="field-label" style="font-size:8px;">eBay</div><input class="field-input" id="be-ebay-sku" type="text" placeholder="SKU"></div>
        <div><div class="field-label" style="font-size:8px;">Amazon</div><input class="field-input" id="be-amazon-sku" type="text" placeholder="ASIN/SKU"></div>
        <div><div class="field-label" style="font-size:8px;">TikTok</div><input class="field-input" id="be-tiktok-sku" type="text" placeholder="SKU"></div>
      </div>
      <div class="field-label" style="margin-top:14px;">Barcode (fürs Scannen)</div>
      <input class="field-input" id="be-barcode" type="text" placeholder="z.B. EAN/UPC vom Produkt-Etikett">
    </div>
    <div class="modal-footer">
      <button class="btn" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);" onclick="closeModal('modal-produkt-bearbeiten')">ABBRECHEN</button>
      <button class="btn btn-primary" onclick="produktBearbeitenSpeichern()">SPEICHERN</button>
    </div>
  </div>
</div>

<!-- Produkt reaktivieren -->
<div class="modal-overlay" id="modal-reaktivieren">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="reaktivieren-title">Produkt reaktivieren</div>
      <button class="modal-close" onclick="closeModal('modal-reaktivieren')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="react-id">
      <div><div class="field-label">Neuer EK-Preis (€, optional)</div><input class="field-input" id="react-ek" type="number" step="0.01" placeholder="Leer lassen = bisherigen Wert behalten"></div>
      <div class="field-hint">Falls sich der Einkaufspreis seit der Archivierung geändert hat, hier eintragen. Sonst leer lassen.</div>
    </div>
    <div class="modal-footer">
      <button class="btn" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);" onclick="closeModal('modal-reaktivieren')">ABBRECHEN</button>
      <button class="btn btn-primary" onclick="reaktivierenBestaetigen()">REAKTIVIEREN</button>
    </div>
  </div>
</div>

<!-- Verlauf -->
<div class="modal-overlay" id="modal-verlauf">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <div class="modal-title" id="verlauf-title">Verlauf</div>
      <button class="modal-close" onclick="closeModal('modal-verlauf')">✕</button>
    </div>
    <div class="modal-body" id="verlauf-body" style="max-height:420px;overflow-y:auto;">
      <div class="empty">Wird geladen...</div>
    </div>
  </div>
</div>

<!-- Barcode-Scan-Overlay (wiederverwendbar) -->
<div class="sticky-note-modal-overlay" id="barcode-scan-overlay">
  <div style="background:#000; width:100%; max-width:480px; margin:20px; position:relative;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:var(--navy2);">
      <span style="font-size:13px; color:var(--blue-bright); font-weight:600;">Barcode scannen</span>
      <button class="modal-close" onclick="barcodeScanAbbrechen()">✕</button>
    </div>
    <div id="barcode-scan-viewport" style="width:100%; height:320px; position:relative; overflow:hidden;"></div>
    <div id="barcode-scan-status" style="padding:10px 16px; font-family:var(--mono); font-size:10px; color:var(--text-dim); background:var(--navy2);">Kamera wird gestartet...</div>
  </div>
</div>

<!-- Sticky Note erstellen -->
<div class="sticky-note-modal-overlay" id="sticky-note-modal-overlay" onclick="if(event.target===this) stickyNoteFormSchliessen()">
  <div class="sticky-note-modal">
    <textarea id="sticky-note-text" placeholder="Notiz eintippen..."></textarea>
    <div class="sticky-note-farben">
      <div class="sticky-note-farbwahl gelb aktiv" data-farbe="gelb" onclick="stickyFarbeWaehlen('gelb', this)"></div>
      <div class="sticky-note-farbwahl rosa" data-farbe="rosa" onclick="stickyFarbeWaehlen('rosa', this)"></div>
      <div class="sticky-note-farbwahl blau" data-farbe="blau" onclick="stickyFarbeWaehlen('blau', this)"></div>
      <div class="sticky-note-farbwahl gruen" data-farbe="gruen" onclick="stickyFarbeWaehlen('gruen', this)"></div>
    </div>
    <button class="btn btn-primary" style="width:100%;" onclick="stickyNoteSpeichern()">Anheften</button>
  </div>
</div>

<!-- Todo Add -->
<div class="modal-overlay" id="modal-todo-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Aufgabe erstellen</div>
      <button class="modal-close" onclick="closeModal('modal-todo-add')">✕</button>
    </div>
    <div class="modal-body">
      <div><div class="field-label">Titel</div><input class="field-input" id="t-titel" type="text" placeholder="Aufgabe beschreiben..."></div>
      <div><div class="field-label">Beschreibung (optional)</div><textarea class="field-textarea" id="t-desc" placeholder="Details..."></textarea></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div><div class="field-label">Priorität</div>
          <select class="field-select" id="t-prio">
            <option value="hoch">🔴 Hoch</option>
            <option value="mittel" selected>🟠 Mittel</option>
            <option value="niedrig">🟢 Niedrig</option>
          </select>
        </div>
        <div><div class="field-label">Zuweisen an</div>
          <select class="field-select" id="t-assign">
            <option value="artis">Artis</option>
            <option value="nour">Nour</option>
            <option value="beide" selected>Beide</option>
          </select>
        </div>
      </div>
      <div><div class="field-label">Deadline</div><input class="field-input" id="t-deadline" type="date"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);" onclick="closeModal('modal-todo-add')">ABBRECHEN</button>
      <button class="btn btn-primary" onclick="addTodo()">SPEICHERN</button>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<script>
// ── UTILS ────────────────────────────────────────────────────────────────────
function showToast(msg, error=false) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast' + (error ? ' error' : '') + ' show';
  setTimeout(() => t.classList.remove('show'), 3000);
}

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if(e.target === o) o.classList.remove('open'); });
});

async function api(data) {
  data.api = 1;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const res = await fetch('dashboard.php', { method:'POST', body:fd });
  return res.json();
}

// ── CLOCK ────────────────────────────────────────────────────────────────────
function updateClock() {
  const now = new Date();
  document.getElementById('clock').textContent = now.toTimeString().slice(0,8);
  const el = document.getElementById('date-display');
  if(el) el.textContent = now.toLocaleDateString('de-DE',{weekday:'long',day:'2-digit',month:'2-digit',year:'numeric'});
}
updateClock(); setInterval(updateClock, 1000);

// Datumsfeld für Bewegungen standardmäßig auf heute
document.getElementById('b-datum').value = new Date().toISOString().slice(0,10);

// ── MEHR-MENÜ ────────────────────────────────────────────────────────────────
function toggleMehrMenu() {
  document.getElementById('mehr-overlay').classList.add('open');
}
function closeMehrMenu(event) {
  document.getElementById('mehr-overlay').classList.remove('open');
}

// ── NAV ──────────────────────────────────────────────────────────────────────
function showPage(name, navEl) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById('page-'+name).classList.add('active');
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  if(navEl) navEl.classList.add('active');
  else {
    // Falls kein Nav-Element übergeben wurde (z.B. programmatischer Aufruf),
    // trotzdem den passenden Sidebar-Eintrag markieren.
    document.querySelectorAll('.nav-item').forEach(n => {
      if (n.getAttribute('onclick') === `showPage('${name}',this)`) n.classList.add('active');
    });
  }
  // Mobile Bottom-Nav ebenfalls synchron halten
  document.querySelectorAll('.mobile-nav-item').forEach(n => {
    n.classList.toggle('active', n.dataset.page === name);
  });
  history.replaceState(null, '', '#' + name);
}

// Beim Laden: falls URL-Hash gesetzt ist (z.B. nach Reload von der Lager-Seite
// aus), direkt den richtigen Tab öffnen statt immer auf Dashboard zu landen.
(function initPageFromHash() {
  const hash = location.hash.replace('#', '');
  if (hash && document.getElementById('page-' + hash)) {
    showPage(hash, null);
  }
})();

// Reload-Helfer: merkt sich den aktuellen Tab im Hash, bevor neu geladen wird
function reloadOnCurrentPage() {
  const current = document.querySelector('.page.active')?.id.replace('page-', '') || 'dashboard';
  location.hash = current;
  location.reload();
}

// ── GEWINN-KALKULATION ──────────────────────────────────────────────────────
// Gebührensätze — hier jederzeit anpassen, falls sich Konditionen ändern.
// Alle Prozentsätze werden auf den BRUTTO-Verkaufspreis angewendet (so berechnen
// die Plattformen ihre Gebühren üblicherweise). Amazon-Monatsgebühr ist ein
// Fixkostenblock, wird NICHT pro Stück abgezogen, sondern nur als Hinweis gezeigt.
const GEBUEHREN = {
  amazon: { prozent: 0.15, monatlich_fix: 39.00, label: 'Amazon' },
  ebay:   { prozent: 0.11, anzeige_prozent: 0.05, label: 'eBay' },
  tiktok: { prozent: 0.08, label: 'TikTok Shop' },
  versand_netto: 3.50,       // DPD, ohne USt
  steuerruecklage_prozent: 0.30, // GmbH: KSt 15% + Soli ~0,8% + GewSt Bremen (Hebesatz 460%) ~16,1% ≈ 30-32%
};

function berechneKalkulation(vkBrutto, ekPreisNetto) {
  if (!vkBrutto || vkBrutto <= 0) return null;
  const vkNetto = vkBrutto / 1.19; // 19% USt raus, die geht ans Finanzamt, ist kein Gewinn

  function fuerPlattform(key) {
    const g = GEBUEHREN[key];
    let gebuehr = vkBrutto * g.prozent;
    if (g.anzeige_prozent) gebuehr += vkBrutto * g.anzeige_prozent; // eBay Anzeigegebühr
    const rohertrag = vkNetto - gebuehr - GEBUEHREN.versand_netto - ekPreisNetto;
    const ruecklage = rohertrag > 0 ? rohertrag * GEBUEHREN.steuerruecklage_prozent : 0;
    const verbleibt = rohertrag - ruecklage;
    return { label: g.label, gebuehr, rohertrag, ruecklage, verbleibt, monatlich_fix: g.monatlich_fix || null };
  }

  return {
    amazon: fuerPlattform('amazon'),
    ebay: fuerPlattform('ebay'),
    tiktok: fuerPlattform('tiktok'),
  };
}

function renderKalkulation(produktId) {
  const input = document.getElementById('vk-' + produktId);
  const box = document.getElementById('kalk-box-' + produktId);
  const grid = document.getElementById('kalk-grid-' + produktId);
  if (!input || !box || !grid) return;

  const vk = parseFloat(input.value);
  const ek = parseFloat(box.dataset.ek) || 0;
  const bestand = parseInt(box.dataset.bestand) || 0;

  const erg = berechneKalkulation(vk, ek);
  if (!erg) {
    grid.innerHTML = '<div class="empty" style="grid-column:1/-1;padding:14px;">VK-Preis eintragen für Kalkulation</div>';
    return;
  }

  const eur = (n) => n.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';

  grid.innerHTML = Object.values(erg).map(p => `
    <div class="plattform-card">
      <div class="plattform-name">${p.label}</div>
      <div class="plattform-zeile"><span class="label">Gebühr/Stk</span><span class="wert">${eur(p.gebuehr)}</span></div>
      <div class="plattform-zeile"><span class="label">Versand</span><span class="wert">${eur(GEBUEHREN.versand_netto)}</span></div>
      <div class="plattform-zeile"><span class="label">EK-Wareneinsatz</span><span class="wert">${eur(ek)}</span></div>
      <div class="plattform-zeile gewinn ${p.rohertrag < 0 ? 'negativ' : ''}"><span class="label">Rohertrag/Stk</span><span class="wert">${eur(p.rohertrag)}</span></div>
      <div class="plattform-zeile ruecklage"><span class="label">Steuerrücklage (30%)</span><span class="wert">${eur(p.ruecklage)}</span></div>
      <div class="plattform-zeile verbleibt ${p.verbleibt < 0 ? 'negativ' : ''}"><span class="label">Verbleibt/Stk</span><span class="wert">${eur(p.verbleibt)}</span></div>
      ${p.monatlich_fix ? `<div style="font-family:var(--mono);font-size:8px;color:var(--text-dim);margin-top:6px;">+ ${eur(p.monatlich_fix)}/Monat Fixkosten (nicht pro Stk gerechnet)</div>` : ''}
      <div class="plattform-total">
        Bei Verkauf des gesamten Bestands (${bestand} Stk) über ${p.label}:
        <span class="total-wert">${eur(p.verbleibt * bestand)}</span>
      </div>
    </div>
  `).join('');
}

function toggleKalkulation(produktId) {
  const box = document.getElementById('kalk-box-' + produktId);
  const wurdeGeoeffnet = !box.classList.contains('open');
  box.classList.toggle('open');
  if (wurdeGeoeffnet) renderKalkulation(produktId);
}

async function saveVkPreis(produktId) {
  const input = document.getElementById('vk-' + produktId);
  const res = await api({ action: 'produkt_vk_update', id: produktId, vk_preis_brutto: input.value });
  if (res.error) { showToast(res.error, true); return; }
  showToast('VK-Preis gespeichert');
}

// ── LAGER: PRODUKTE ──────────────────────────────────────────────────────────
async function addProdukt() {
  const name = document.getElementById('p-name').value.trim();
  if(!name) { showToast('Name fehlt', true); return; }

  let foto_url = '';
  const fotoFile = document.getElementById('p-foto').files[0];
  if (fotoFile) {
    const fd = new FormData();
    fd.append('foto', fotoFile);
    const upRes = await fetch('upload.php', { method:'POST', body:fd });
    const upData = await upRes.json();
    if (upData.error) { showToast('Foto: ' + upData.error, true); return; }
    foto_url = upData.url;
  }

  const res = await api({
    action: 'produkt_add', name,
    kategorie_id: document.getElementById('p-kategorie').value,
    ek_preis_netto: document.getElementById('p-ek').value,
    vorsteuersatz: document.getElementById('p-vst').value,
    foto_url,
    ebay_sku: document.getElementById('p-ebay-sku').value,
    amazon_sku: document.getElementById('p-amazon-sku').value,
    tiktok_sku: document.getElementById('p-tiktok-sku').value,
  });
  if(res.error) { showToast(res.error, true); return; }
  showToast('Produkt hinzugefügt');
  closeModal('modal-produkt-add');
  setTimeout(() => reloadOnCurrentPage(), 800);
}

async function archivProdukt(id, bestand) {
  if (bestand > 0) {
    showToast('Erst Bestand auf 0 bringen (verkaufen/verbrauchen)', true);
    return;
  }
  if(!confirm('Produkt ins Archiv verschieben? Historie bleibt erhalten, du kannst es jederzeit wieder reaktivieren.')) return;
  const res = await api({ action:'produkt_archivieren', id });
  if(res.error) { showToast(res.error, true); return; }
  document.getElementById('produkt-'+id)?.remove();
  showToast('Produkt archiviert');
}

let archivLoeschmodusAktiv = false;
function toggleArchivLoeschmodus() {
  archivLoeschmodusAktiv = !archivLoeschmodusAktiv;
  const btn = document.getElementById('archiv-loeschmodus-btn');
  btn.textContent = archivLoeschmodusAktiv ? '🔒 LÖSCHMODUS BEENDEN' : '🔓 LÖSCHMODUS';
  document.querySelectorAll('.archiv-delete-btn').forEach(b => b.style.display = archivLoeschmodusAktiv ? 'inline-block' : 'none');
}

async function produktEndgueltigLoeschen(id, name) {
  if (!confirm('"' + name + '" WIRKLICH ENDGÜLTIG löschen? Das entfernt auch die komplette Bewegungshistorie unwiderruflich — kann NICHT rückgängig gemacht werden!')) return;
  const res = await api({ action: 'produkt_endgueltig_loeschen', id });
  if (res.error) { showToast(res.error, true); return; }
  document.getElementById('produkt-' + id)?.remove();
  showToast('Produkt endgültig gelöscht');
}

async function openBearbeitenModal(id) {
  const res = await api({ action: 'produkt_details_holen', id });
  if (res.error) { showToast(res.error, true); return; }

  document.getElementById('be-id').value = id;
  document.getElementById('bearbeiten-titel').textContent = 'Bearbeiten — ' + res.name;
  document.getElementById('be-ek').value = res.ek_preis_netto ?? '';
  document.getElementById('be-vk').value = res.vk_preis_brutto ?? '';
  document.getElementById('be-ebay-sku').value = res.ebay_sku ?? '';
  document.getElementById('be-amazon-sku').value = res.amazon_sku ?? '';
  document.getElementById('be-tiktok-sku').value = res.tiktok_sku ?? '';
  document.getElementById('be-barcode').value = res.barcode ?? '';
  openModal('modal-produkt-bearbeiten');
}

async function produktBearbeitenSpeichern() {
  const id = document.getElementById('be-id').value;
  const res = await api({
    action: 'produkt_bearbeiten',
    id,
    ek_preis_netto: document.getElementById('be-ek').value,
    vk_preis_brutto: document.getElementById('be-vk').value,
    ebay_sku: document.getElementById('be-ebay-sku').value,
    amazon_sku: document.getElementById('be-amazon-sku').value,
    tiktok_sku: document.getElementById('be-tiktok-sku').value,
    barcode: document.getElementById('be-barcode').value,
  });
  if (res.error) { showToast(res.error, true); return; }
  showToast('Gespeichert');
  closeModal('modal-produkt-bearbeiten');
  setTimeout(() => reloadOnCurrentPage(), 800);
}

function openReaktivierenModal(id, name) {
  document.getElementById('react-id').value = id;
  document.getElementById('react-ek').value = '';
  document.getElementById('reaktivieren-title').textContent = 'Reaktivieren — '+name;
  openModal('modal-reaktivieren');
}

async function reaktivierenBestaetigen() {
  const id = document.getElementById('react-id').value;
  const neuer_ek = document.getElementById('react-ek').value;
  const res = await api({ action:'produkt_reaktivieren', id, neuer_ek });
  if(res.error) { showToast(res.error, true); return; }
  showToast('Produkt reaktiviert');
  closeModal('modal-reaktivieren');
  setTimeout(() => { window.location.href = 'dashboard.php#lager'; }, 800);
}

// ── LAGER: BEWEGUNGEN ───────────────────────────────────────────────────────
let aktuellerBestandFuerModal = 0;

function toggleTypFields() {
  const typ = document.getElementById('b-typ').value;
  document.querySelectorAll('.typ-fields').forEach(el => {
    el.classList.toggle('active', el.dataset.typ === typ);
  });
  const mengeInput = document.getElementById('b-menge');
  const hinweis = document.getElementById('b-menge-hinweis');
  const label = document.getElementById('b-menge-label');
  if (typ === 'korrektur') {
    mengeInput.min = '0';
    label.textContent = 'Gezählter Bestand (IST, Stück)';
    hinweis.style.display = 'block';
    hinweis.textContent = `Aktueller Buchbestand: ${aktuellerBestandFuerModal} Stk — trag hier ein, was du tatsächlich gezählt hast. Die Differenz wird automatisch als Korrektur gebucht.`;
    mengeInput.value = aktuellerBestandFuerModal;
  } else {
    mengeInput.min = '1';
    label.textContent = 'Menge (Stück)';
    if (parseInt(mengeInput.value) < 1) mengeInput.value = 1;
    hinweis.style.display = 'none';
  }
}
toggleTypFields();

function openBewegungModal(produktId, name, bestand) {
  document.getElementById('b-produkt').value = produktId;
  document.getElementById('bewegung-title').textContent = 'Bewegung — '+name;
  aktuellerBestandFuerModal = bestand ?? 0;
  document.getElementById('b-menge').value = 1;
  document.getElementById('b-datum').value = new Date().toISOString().slice(0,10);
  document.getElementById('b-typ').value = 'zugang_einkauf';
  toggleTypFields();
  openModal('modal-bewegung-add');
}

function produktGewechselt() {
  const select = document.getElementById('b-produkt');
  const selectedOption = select.options[select.selectedIndex];
  aktuellerBestandFuerModal = parseInt(selectedOption?.dataset.bestand ?? 0);
  if (document.getElementById('b-typ').value === 'korrektur') toggleTypFields();
}

function openBewegungModalGlobal() {
  const select = document.getElementById('b-produkt');
  select.selectedIndex = 0;
  const bestand = parseInt(select.options[0]?.dataset.bestand ?? 0);
  const name = select.options[0]?.textContent ?? '';
  openBewegungModal(select.value, name, bestand);
}

async function addBewegung() {
  const typ = document.getElementById('b-typ').value;
  const payload = {
    action: 'bewegung_add',
    produkt_id: document.getElementById('b-produkt').value,
    typ,
    menge: document.getElementById('b-menge').value,
    bewegt_am: document.getElementById('b-datum').value,
    notiz: document.getElementById('b-notiz').value,
  };
  if (typ === 'zugang_einkauf') payload.ek_preis_netto = document.getElementById('b-ek').value;
  if (typ === 'abgang_verkauf') {
    payload.verkaufskanal = document.getElementById('b-kanal').value;
    payload.verkaufspreis_brutto = document.getElementById('b-verkaufspreis').value;
    payload.bestellnummer = document.getElementById('b-bestellnr').value;
  }
  if (typ === 'abgang_eigenbedarf') payload.eigenbedarf_grund = document.getElementById('b-eigenbedarf-grund').value;
  if (typ === 'retoure') {
    payload.retoure_grund = document.getElementById('b-retoure-grund').value;
    payload.wieder_verkaufsfaehig = document.getElementById('b-wieder-verkaufsfaehig').value;
  }
  if (typ === 'korrektur') payload.korrektur_grund = document.getElementById('b-korrektur-grund').value;

  const res = await api(payload);
  if(res.error) { showToast(res.error, true); return; }
  showToast('Bewegung erfasst — neuer Bestand: '+res.bestand);
  closeModal('modal-bewegung-add');
  setTimeout(() => reloadOnCurrentPage(), 800);
}

let verlaufAktuellesProdukt = { id: null, name: null };

// ── BARCODE-SCANNER (wiederverwendbar) ──────────────────────────────────────
let barcodeZielFeld = null;
let barcodeDetectorAktiv = false;
let barcodeVideoStream = null;

async function barcodeScanStarten(zielFeldId) {
  barcodeZielFeld = zielFeldId;
  document.getElementById('barcode-scan-overlay').classList.add('open');
  document.getElementById('barcode-scan-status').textContent = 'Kamera wird gestartet...';
  const viewport = document.getElementById('barcode-scan-viewport');
  viewport.innerHTML = '';

  if ('BarcodeDetector' in window) {
    try {
      const video = document.createElement('video');
      video.style.width = '100%';
      video.style.height = '100%';
      video.style.objectFit = 'cover';
      video.setAttribute('playsinline', true);
      viewport.appendChild(video);

      barcodeVideoStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      video.srcObject = barcodeVideoStream;
      await video.play();

      const detector = new BarcodeDetector();
      barcodeDetectorAktiv = true;
      document.getElementById('barcode-scan-status').textContent = 'Barcode ins Bild halten...';

      const scanSchleife = async () => {
        if (!barcodeDetectorAktiv) return;
        try {
          const codes = await detector.detect(video);
          if (codes.length > 0) {
            barcodeGefunden(codes[0].rawValue);
            return;
          }
        } catch (e) { /* weiter versuchen */ }
        requestAnimationFrame(scanSchleife);
      };
      scanSchleife();
      return;
    } catch (e) {
      console.warn('Native BarcodeDetector fehlgeschlagen, nutze QuaggaJS', e);
    }
  }

  Quagga.init({
    inputStream: {
      type: 'LiveStream',
      target: viewport,
      constraints: { facingMode: 'environment' },
    },
    decoder: { readers: ['ean_reader', 'code_128_reader', 'upc_reader'] },
  }, function (err) {
    if (err) {
      document.getElementById('barcode-scan-status').textContent = 'Kamera konnte nicht gestartet werden.';
      return;
    }
    Quagga.start();
    document.getElementById('barcode-scan-status').textContent = 'Barcode ins Bild halten...';
  });

  Quagga.onDetected(function (result) {
    barcodeGefunden(result.codeResult.code);
  });
}

async function barcodeGefunden(code) {
  document.getElementById('barcode-scan-status').textContent = 'Gefunden: ' + code + ' — suche Produkt...';
  const res = await api({ action: 'produkt_per_barcode', barcode: code });
  barcodeScanAbbrechen();

  if (res.error) { showToast(res.error, true); return; }

  const feld = document.getElementById(barcodeZielFeld);
  if (feld) {
    feld.value = res.produkt_id;
    feld.dispatchEvent(new Event('change'));
  }
  showToast(res.produkt_name + ' erkannt');
}

function barcodeScanAbbrechen() {
  document.getElementById('barcode-scan-overlay').classList.remove('open');
  barcodeDetectorAktiv = false;
  if (barcodeVideoStream) {
    barcodeVideoStream.getTracks().forEach(t => t.stop());
    barcodeVideoStream = null;
  }
  try { Quagga.stop(); } catch (e) {}
}

// ── STICKY NOTES ─────────────────────────────────────────────────────────────
let stickyGewaehlteFarbe = 'gelb';

function stickyNoteFormOeffnen() {
  document.getElementById('sticky-note-text').value = '';
  document.getElementById('sticky-note-modal-overlay').classList.add('open');
  document.getElementById('sticky-note-text').focus();
}
function stickyNoteFormSchliessen() {
  document.getElementById('sticky-note-modal-overlay').classList.remove('open');
}
function stickyFarbeWaehlen(farbe, el) {
  stickyGewaehlteFarbe = farbe;
  document.querySelectorAll('.sticky-note-farbwahl').forEach(f => f.classList.remove('aktiv'));
  el.classList.add('aktiv');
}
async function stickyNoteSpeichern() {
  const text = document.getElementById('sticky-note-text').value.trim();
  if (!text) { return; }
  const res = await api({ action: 'notiz_anlegen', text, farbe: stickyGewaehlteFarbe });
  if (res.error) { showToast(res.error, true); return; }
  window.location.reload();
}
async function notizLoeschen(id) {
  if (!confirm('Diese Notiz entfernen?')) return;
  const res = await api({ action: 'notiz_loeschen', id });
  if (res.error) { showToast(res.error, true); return; }
  document.getElementById('notiz-' + id)?.remove();
}

async function openVerlauf(id, name) {
  verlaufAktuellesProdukt = { id, name };
  document.getElementById('verlauf-title').textContent = 'Verlauf — '+name;
  document.getElementById('verlauf-body').innerHTML = '<div class="empty">Wird geladen...</div>';
  openModal('modal-verlauf');
  await verlaufNeuLaden();
}

async function verlaufNeuLaden() {
  const data = await api({ action:'lager_verlauf', id: verlaufAktuellesProdukt.id });
  if(!data.length) { document.getElementById('verlauf-body').innerHTML = '<div class="empty">Kein Verlauf</div>'; return; }
  document.getElementById('verlauf-body').innerHTML = data.map(v => {
    let detail = '';
    if (v.typ === 'abgang_verkauf') detail = `${v.verkaufskanal ?? ''} · ${v.verkaufspreis_brutto ? '€'+v.verkaufspreis_brutto : ''}`;
    if (v.typ === 'zugang_einkauf') detail = v.ek_preis_netto ? 'EK: €'+v.ek_preis_netto+'/Stk' : '';
    if (v.typ === 'retoure') detail = v.retoure_grund ?? '';
    if (v.typ === 'abgang_eigenbedarf') detail = v.eigenbedarf_grund ?? '';
    if (v.typ === 'korrektur') detail = v.korrektur_grund ?? '';
    return `
    <div class="verlauf-item" style="position:relative; padding-right:26px;">
      <span class="verlauf-typ ${v.typ}">${v.typ.replace('_',' ').toUpperCase()}</span>
      <strong>${v.menge} Stk</strong>
      <span style="color:var(--text-dim);margin-left:8px;">${new Date(v.bewegt_am).toLocaleDateString('de-DE')} · ${v.erfasst_von === 'qaf' ? 'Artis' : 'Nour'}</span>
      ${detail ? `<div style="color:var(--text-dim);margin-top:3px;">${detail}</div>` : ''}
      ${v.notiz ? `<div style="color:var(--text-dim);margin-top:2px;">💬 ${v.notiz}</div>` : ''}
      <span onclick="bewegungRueckgaengig(${v.id})" title="Diese Bewegung rückgängig machen" style="position:absolute; top:2px; right:0; font-size:11px; opacity:0.25; cursor:pointer; transition:opacity 0.15s;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.25">↩</span>
    </div>`;
  }).join('');
}

async function letzteBewegungRueckgaengig(produktId, produktName) {
  if (!confirm(`Letzte Lager-Bewegung von "${produktName}" wirklich rückgängig machen?`)) return;
  const res = await api({ action: 'letzte_bewegung_rueckgaengig', produkt_id: produktId });
  if (res.error) { showToast(res.error, true); return; }
  showToast('Letzte Bewegung rückgängig gemacht');
  setTimeout(() => reloadOnCurrentPage(), 1000);
}

async function bewegungRueckgaengig(bewegungId) {
  if (!confirm('Diese Bewegung wirklich rückgängig machen? Sie wird komplett aus der Historie entfernt (wirkt sich auch auf Umsatz/Reports aus).')) return;
  const res = await api({ action: 'bewegung_rueckgaengig', bewegung_id: bewegungId });
  if (res.error) { showToast(res.error, true); return; }
  showToast('Bewegung rückgängig gemacht');
  await verlaufNeuLaden();
  setTimeout(() => reloadOnCurrentPage(), 1000);
}

// ── TODOS ────────────────────────────────────────────────────────────────────
async function addTodo() {
  const titel = document.getElementById('t-titel').value.trim();
  if(!titel) { showToast('Titel fehlt', true); return; }
  const res = await api({ action:'todo_add', titel, beschreibung:document.getElementById('t-desc').value, prioritaet:document.getElementById('t-prio').value, assignee:document.getElementById('t-assign').value, deadline:document.getElementById('t-deadline').value });
  if(res.error) { showToast(res.error, true); return; }
  showToast('Aufgabe erstellt');
  closeModal('modal-todo-add');
  setTimeout(() => reloadOnCurrentPage(), 800);
}

async function updateTodoStatus(id, status) {
  const res = await api({ action:'todo_status', id, status });
  if(res.error) { showToast('Fehler', true); return; }
  showToast('Status aktualisiert');
}

async function deleteTodo(id) {
  if(!confirm('Aufgabe wirklich löschen?')) return;
  await api({ action:'todo_delete', id });
  showToast('Aufgabe gelöscht');
  setTimeout(() => reloadOnCurrentPage(), 800);
}
</script>
</body>
</html>