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

    // ── SENDUNGEN (manuelle Frachtverfolgung) ──
    if ($action === 'sendung_add') {
        $spediteur = trim($_POST['spediteur'] ?? '');
        $tracking_nummer = trim($_POST['tracking_nummer'] ?? '');
        $inhalt = trim($_POST['inhalt'] ?? '');
        $ziel = trim($_POST['ziel'] ?? '');
        $typ = ($_POST['typ'] ?? 'intern') === 'kunde' ? 'kunde' : 'intern';
        if (!$spediteur || !$inhalt) { echo json_encode(['error' => 'Spediteur oder Inhalt fehlt']); exit; }
        try {
            db()->prepare("
                INSERT INTO sendungen (typ, spediteur, tracking_nummer, inhalt, ziel, status, erstellt_von)
                VALUES (?, ?, ?, ?, ?, 'unterwegs', ?)
            ")->execute([$typ, $spediteur, $tracking_nummer ?: null, $inhalt, $ziel ?: null, $erfasst_von]);
            echo json_encode(['success' => true, 'id' => db()->lastInsertId()]);
        } catch (\Throwable $e) {
            echo json_encode(['error' => 'Tabelle "sendungen" fehlt noch — siehe sendungen/schema.sql']);
        }
        exit;
    }

    if ($action === 'sendung_status_aendern') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'unterwegs';
        if (!in_array($status, ['unterwegs', 'zugestellt', 'verzoegert', 'zoll'])) { echo json_encode(['error' => 'Ungültiger Status']); exit; }
        db()->prepare('UPDATE sendungen SET status = ? WHERE id = ?')->execute([$status, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'sendung_loeschen') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM sendungen WHERE id = ?')->execute([$id]);
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

// ── ÜBERSICHT-KARTEN: Umsatz diese Woche vs. Vorwoche ───────────────────────
$umsatz_woche = (float) db()->query("
    SELECT COALESCE(SUM(menge * verkaufspreis_brutto), 0) FROM lager_bewegungen
    WHERE typ = 'abgang_verkauf' AND bewegt_am >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
")->fetchColumn();
$umsatz_vorwoche = (float) db()->query("
    SELECT COALESCE(SUM(menge * verkaufspreis_brutto), 0) FROM lager_bewegungen
    WHERE typ = 'abgang_verkauf' AND bewegt_am >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND bewegt_am < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
")->fetchColumn();
$woche_veraenderung = $umsatz_vorwoche > 0 ? round((($umsatz_woche - $umsatz_vorwoche) / $umsatz_vorwoche) * 100, 1) : null;

// ── Verkäufe je Monat (6 Monate) fürs Balkendiagramm ─────────────────────────
$monatsnamen_kurz = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
$monats_umsaetze = [];
for ($i = 5; $i >= 0; $i--) {
    $monat_start = date('Y-m-01', strtotime("-$i months"));
    $monat_ende = date('Y-m-t', strtotime("-$i months"));
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(menge * verkaufspreis_brutto), 0) FROM lager_bewegungen
        WHERE typ = 'abgang_verkauf' AND bewegt_am BETWEEN ? AND ?
    ");
    $stmt->execute([$monat_start, $monat_ende]);
    $monats_umsaetze[] = [
        'label' => $monatsnamen_kurz[(int)date('n', strtotime($monat_start)) - 1],
        'umsatz' => (float) $stmt->fetchColumn(),
    ];
}
$max_monatsumsatz = max(array_column($monats_umsaetze, 'umsatz')) ?: 1;
$peak_index = array_search($max_monatsumsatz, array_column($monats_umsaetze, 'umsatz'));

// ── Letzte 5 Verkäufe ─────────────────────────────────────────────────────
$letzte_verkaeufe = db()->query("
    SELECT b.*, p.name AS produkt_name
    FROM lager_bewegungen b
    JOIN lager_produkte p ON p.id = b.produkt_id
    WHERE b.typ = 'abgang_verkauf'
    ORDER BY b.bewegt_am DESC, b.erstellt_am DESC
    LIMIT 5
")->fetchAll();
$kanal_labels_kurz = ['ebay' => 'eBay', 'amazon' => 'Amazon', 'tiktok' => 'TikTok Shop', 'direktverkauf' => 'Direktverkauf', 'sonstiges' => 'Sonstiges'];

// ── Umsatz-Sparkline (14 Tage, kumuliert) ────────────────────────────────────
$sparkline_werte = [];
$laufsumme = 0;
for ($i = 13; $i >= 0; $i--) {
    $tag = date('Y-m-d', strtotime("-$i days"));
    $stmt = db()->prepare("SELECT COALESCE(SUM(menge * verkaufspreis_brutto), 0) FROM lager_bewegungen WHERE typ = 'abgang_verkauf' AND bewegt_am = ?");
    $stmt->execute([$tag]);
    $laufsumme += (float) $stmt->fetchColumn();
    $sparkline_werte[] = $laufsumme;
}
$spark_min = min($sparkline_werte);
$spark_max = max($sparkline_werte) ?: 1;

// ── Offene Auslagen (Artis/Nour) ──────────────────────────────────────────
$offene_auslagen_summe = 0;
try {
    $offene_auslagen_summe = (float) db()->query("SELECT COALESCE(SUM(betrag),0) FROM auslagen WHERE status = 'offen'")->fetchColumn();
} catch (\Throwable $e) { /* Tabelle evtl. noch nicht angelegt */ }

// ── Sendungen (manuelle Frachtverfolgung) — Tabelle muss einmalig angelegt
// werden (siehe sendungen/schema.sql), daher defensiv per try/catch ──────────
$sendungen = [];
try {
    $sendungen = db()->query("
        SELECT * FROM sendungen
        WHERE status != 'zugestellt'
        ORDER BY erstellt_am DESC
        LIMIT 5
    ")->fetchAll();
} catch (\Throwable $e) { /* Tabelle noch nicht angelegt */ }
$sendung_status_labels = ['unterwegs' => '🚚 Unterwegs', 'zugestellt' => '✓ Zugestellt', 'verzoegert' => '⚠️ Verzögert', 'zoll' => '🛃 Beim Zoll'];
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
<link rel="stylesheet" href="assets/dashboard.css">
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
  <div class="nav-item" onclick="window.location.href='marketing/marketing.php'"><span class="nav-icon">📈</span> Marketing</div>
  <div class="nav-item" onclick="window.location.href='trips/trip.php'"><span class="nav-icon">🚗</span> Sammel-Trips</div>
  <div class="nav-item" onclick="window.location.href='auslagen/auslagen.php'"><span class="nav-icon">💸</span> Auslagen</div>
  <?php if ($zugriff_dokumente): ?>
  <div class="nav-item" onclick="window.location.href='documents/documents.php'"><span class="nav-icon">📄</span> Dokumente</div>
  <?php endif; ?>
  <?php if ($zugriff_kalkulator): ?>
  <div class="nav-item" onclick="window.location.href='kalkulator/rechner.php'"><span class="nav-icon">🧮</span> Kalkulator</div>
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
      <a href="marketing/marketing.php" class="mehr-item"><span class="mehr-icon">📈</span><span>Marketing</span></a>
      <a href="trips/trip.php" class="mehr-item"><span class="mehr-icon">🚗</span><span>Sammel-Trips</span></a>
      <a href="auslagen/auslagen.php" class="mehr-item"><span class="mehr-icon">💸</span><span>Auslagen</span></a>
      <?php if ($zugriff_dokumente): ?><a href="documents/documents.php" class="mehr-item"><span class="mehr-icon">📄</span><span>Dokumente</span></a><?php endif; ?>
      <?php if ($zugriff_kalkulator): ?><a href="kalkulator/rechner.php" class="mehr-item"><span class="mehr-icon">🧮</span><span>Kalkulator</span></a><?php endif; ?>
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

    <div class="ov-grid">
      <!-- Spalte 1: Lagerwert + Umsatz diese Woche -->
      <div class="ov-col">
        <div class="ov-card">
          <div class="ov-label">Lagerwert (EK)</div>
          <div class="ov-sub">Aktueller Bestandswert, FIFO-bewertet</div>
          <div class="ov-virt-card">
            <div class="ov-chip"></div>
            <div class="ov-virt-label">NA Commerce · Lager</div>
            <div class="ov-virt-amount">€<?= number_format($lager_ek_gesamt, 2, ',', '.') ?></div>
            <div class="ov-virt-foot">
              <span><?= $lager_count ?> Produkte aktiv</span>
              <span>Stand <?= date('d.m.Y') ?></span>
            </div>
          </div>
        </div>

        <div class="ov-card">
          <div class="ov-label">Umsatz diese Woche</div>
          <div class="ov-stat-row">
            <div class="ov-stat-value">€<?= number_format($umsatz_woche, 2, ',', '.') ?></div>
            <?php if ($woche_veraenderung === null): ?>
              <span class="ov-badge neutral">Keine Vorwoche</span>
            <?php elseif ($woche_veraenderung >= 0): ?>
              <span class="ov-badge up">▲ <?= $woche_veraenderung ?>%</span>
            <?php else: ?>
              <span class="ov-badge down">▼ <?= abs($woche_veraenderung) ?>%</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Spalte 2: Verkäufe-Chart + letzte Verkäufe -->
      <div class="ov-col">
        <div class="ov-card">
          <div class="ov-chart-head">
            <div>
              <div class="ov-label">Verkäufe</div>
              <div class="ov-sub">Letzte 6 Monate</div>
            </div>
          </div>
          <div class="ov-bars">
            <?php foreach ($monats_umsaetze as $i => $m):
                $hoehe = max(6, round(($m['umsatz'] / $max_monatsumsatz) * 100));
                $ist_peak = $i === $peak_index && $m['umsatz'] > 0;
            ?>
            <div class="ov-bar-col <?= $ist_peak ? 'peak' : '' ?>">
              <?php if ($ist_peak): ?><div class="ov-bump">€<?= number_format($m['umsatz'], 0, ',', '.') ?></div><?php endif; ?>
              <div class="ov-bar" style="height:<?= $hoehe ?>%;"></div>
              <div class="ov-bar-label"><?= $m['label'] ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="ov-card">
          <div class="ov-label">Letzte Verkäufe</div>
          <?php if (empty($letzte_verkaeufe)): ?>
            <div class="ov-empty">Noch keine Verkäufe erfasst</div>
          <?php else: ?>
          <table class="ov-table">
            <thead><tr><th>Produkt</th><th>Kanal</th><th style="text-align:right;">Betrag</th></tr></thead>
            <tbody>
              <?php foreach ($letzte_verkaeufe as $v): ?>
              <tr>
                <td><div class="ov-produkt-zelle"><div class="ov-produkt-icon">📦</div><?= htmlspecialchars($v['produkt_name']) ?></div></td>
                <td style="color:var(--text-dim);"><?= $kanal_labels_kurz[$v['verkaufskanal']] ?? htmlspecialchars($v['verkaufskanal'] ?? '—') ?></td>
                <td class="ov-betrag-zelle">€<?= number_format($v['menge'] * $v['verkaufspreis_brutto'], 2, ',', '.') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- Spalte 3: Sparkline + Sendungen + Auslagen/Team -->
      <div class="ov-col">
        <div class="ov-card">
          <div class="ov-label">Umsatz-Verlauf</div>
          <div class="ov-sub">Letzte 14 Tage, kumuliert</div>
          <div class="ov-stat-value" style="font-size:22px; margin-top:8px;">€<?= number_format(end($sparkline_werte), 2, ',', '.') ?></div>
          <svg class="ov-spark-svg" viewBox="0 0 240 70" preserveAspectRatio="none">
            <?php
            $punkte = [];
            $n = count($sparkline_werte);
            foreach ($sparkline_werte as $i => $wert) {
                $x = $n > 1 ? ($i / ($n - 1)) * 240 : 0;
                $y = $spark_max > $spark_min ? 65 - (($wert - $spark_min) / ($spark_max - $spark_min)) * 55 : 35;
                $punkte[] = round($x, 1) . ',' . round($y, 1);
            }
            ?>
            <polyline points="<?= implode(' ', $punkte) ?>" fill="none" stroke="#9494FF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>

        <div class="ov-card">
          <div class="ov-chart-head">
            <div class="ov-label">Sendungen</div>
            <button class="panel-action" onclick="openModal('modal-sendung-add')" style="font-size:11px;">+ ERFASSEN</button>
          </div>
          <?php if (empty($sendungen)): ?>
            <div class="ov-empty">Keine aktiven Sendungen</div>
          <?php else: ?>
            <?php foreach ($sendungen as $s): ?>
            <div class="ov-sendung-item" id="sendung-<?= $s['id'] ?>">
              <div class="ov-sendung-kopf">
                <div>
                  <div class="ov-sendung-inhalt"><?= htmlspecialchars($s['inhalt']) ?></div>
                  <div class="ov-sendung-meta">
                    <?= htmlspecialchars($s['spediteur']) ?><?= $s['tracking_nummer'] ? ' · ' . htmlspecialchars($s['tracking_nummer']) : '' ?>
                    <?= $s['ziel'] ? ' → ' . htmlspecialchars($s['ziel']) : '' ?>
                  </div>
                </div>
                <button class="ov-sendung-loeschen" onclick="sendungLoeschen(<?= $s['id'] ?>)">✕</button>
              </div>
              <select class="ov-sendung-status" onchange="sendungStatusAendern(<?= $s['id'] ?>, this.value)">
                <?php foreach ($sendung_status_labels as $key => $label): ?>
                  <option value="<?= $key ?>" <?= $s['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div class="ov-card">
          <div class="ov-label">Offene Auslagen</div>
          <div class="ov-stat-value" style="margin-top:8px; font-size:22px; color:<?= $offene_auslagen_summe > 0 ? 'var(--orange)' : 'var(--green)' ?>;">
            €<?= number_format($offene_auslagen_summe, 2, ',', '.') ?>
          </div>
          <div class="ov-label" style="margin-top:20px;">Team</div>
          <div class="ov-avatar-row">
            <div class="ov-avatar a1">A</div>
            <div class="ov-avatar a2">N</div>
          </div>
        </div>
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
          <button class="panel-action" onclick="showPage('lager',null)">GESAMTE VERWALTUNG →</button>
        </div>
        <div class="panel-body">
          <?php if(empty($produkte)): ?>
            <div class="empty">Kein Bestand</div>
          <?php else: ?>
            <?php foreach($produkte as $i => $p): ?>
            <div class="lager-item <?= $i >= 5 ? 'versteckt' : '' ?>" data-lager-vorschau-index="<?= $i ?>">
              <div class="lager-img">
                <?php if($p['foto_url']): ?><img src="<?= htmlspecialchars($p['foto_url']) ?>" alt=""><?php else: ?>📦<?php endif; ?>
              </div>
              <div style="flex:1;">
                <div class="lager-name"><?= htmlspecialchars($p['name']) ?></div>
                <div class="lager-stock">Bestand: <span class="<?= $p['bestand'] <= 5 ? 'low' : '' ?>"><?= $p['bestand'] ?> Stk</span> · <?= htmlspecialchars($p['kategorie_name']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if (count($produkte) > 5): ?>
              <button class="mehr-anzeigen-btn" id="lager-vorschau-mehr-btn" onclick="lagerVorschauMehrAnzeigen()">Mehr anzeigen (<?= count($produkte) - 5 ?>)</button>
            <?php endif; ?>
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

<!-- Sendung Add -->
<div class="modal-overlay" id="modal-sendung-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Sendung erfassen</div>
      <button class="modal-close" onclick="closeModal('modal-sendung-add')">✕</button>
    </div>
    <div class="modal-body">
      <div><div class="field-label">Art</div>
        <select class="field-select" id="sd-typ">
          <option value="intern">Interne Logistik (Warenumzug, Nachschub, FBA …)</option>
          <option value="kunde">Kundenbestellung</option>
        </select>
      </div>
      <div><div class="field-label">Spediteur</div><input class="field-input" id="sd-spediteur" type="text" placeholder="z.B. DSV, DHL, UPS"></div>
      <div><div class="field-label">Tracking-Nummer (optional)</div><input class="field-input" id="sd-tracking" type="text" placeholder="z.B. 1234567890"></div>
      <div><div class="field-label">Inhalt / Ware</div><input class="field-input" id="sd-inhalt" type="text" placeholder="z.B. 1 Palette Wärmflaschen"></div>
      <div><div class="field-label">Ziel / Empfänger (optional)</div><input class="field-input" id="sd-ziel" type="text" placeholder="z.B. Amazon FBA Lager, Kunde XY"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" style="color:var(--text-dim);border-color:rgba(139,143,168,0.3);" onclick="closeModal('modal-sendung-add')">ABBRECHEN</button>
      <button class="btn btn-primary" onclick="sendungErstellen()">SPEICHERN</button>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<script src="assets/dashboard.js"></script>
</body>
</html>