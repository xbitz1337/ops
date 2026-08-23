<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Bestellungen-Modul — Kanal-API-Anbindungen (VORBEREITET)
 *
 * Enthält:
 * 1. Fertige, sofort nutzbare Funktion zur automatischen Produkt-Zuordnung
 *    per SKU — funktioniert bereits jetzt (unabhängig von den externen APIs).
 * 2. Platzhalter-Funktionen für eBay/Amazon/TikTok, die befüllt werden,
 *    sobald die jeweiligen API-Zugänge/App-Freigaben da sind.
 */

/**
 * Ordnet eine eingehende Bestellung automatisch einem Lagerprodukt zu,
 * indem die externe SKU (aus eBay/Amazon/TikTok) mit dem passenden
 * SKU-Feld am Produkt abgeglichen wird. Gibt die produkt_id zurück,
 * oder null, falls keine Übereinstimmung gefunden wurde (dann muss
 * die Zuordnung manuell erfolgen).
 */
function produkt_per_sku_finden(PDO $pdo, string $kanal, string $sku): ?int
{
    $spalte = match ($kanal) {
        'ebay' => 'ebay_sku',
        'amazon' => 'amazon_sku',
        'tiktok' => 'tiktok_sku',
        default => null,
    };
    if (!$spalte || trim($sku) === '') return null;

    $stmt = $pdo->prepare("SELECT id FROM lager_produkte WHERE $spalte = ? AND aktiv = 1 LIMIT 1");
    $stmt->execute([$sku]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/**
 * Legt eine neue Bestellung aus einem generischen Import-Datensatz an
 * (egal ob eBay/Amazon/TikTok) und ordnet automatisch das Produkt zu,
 * falls die SKU bekannt ist. Nutzbar von allen drei ebay_*/amazon_*/
 * tiktok_orders_abrufen()-Funktionen unten.
 */
function bestellung_importieren(PDO $pdo, array $daten): int
{
    // Duplikate vermeiden — falls die externe Order-ID schon importiert wurde
    if (!empty($daten['externe_order_id'])) {
        $check = $pdo->prepare("SELECT id FROM bestellungen WHERE externe_order_id = ? AND kanal = ?");
        $check->execute([$daten['externe_order_id'], $daten['kanal']]);
        $vorhanden = $check->fetchColumn();
        if ($vorhanden) return (int)$vorhanden;
    }

    $produkt_id = null;
    if (!empty($daten['sku'])) {
        $produkt_id = produkt_per_sku_finden($pdo, $daten['kanal'], $daten['sku']);
    }

    $stmt = $pdo->prepare("
        INSERT INTO bestellungen
            (externe_order_id, ist_test, kanal, status, kaeufer_name, versand_strasse,
             versand_plz, versand_ort, versand_land, produkt_id, artikel_name, menge,
             verkaufspreis_brutto, bestelldatum)
        VALUES (?, 0, ?, 'neu', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $daten['externe_order_id'] ?? null,
        $daten['kanal'],
        $daten['kaeufer_name'] ?? '',
        $daten['versand_strasse'] ?? '',
        $daten['versand_plz'] ?? '',
        $daten['versand_ort'] ?? '',
        $daten['versand_land'] ?? 'Deutschland',
        $produkt_id,
        $daten['artikel_name'] ?? '',
        $daten['menge'] ?? 1,
        $daten['verkaufspreis_brutto'] ?? null,
        $daten['bestelldatum'] ?? date('Y-m-d H:i:s'),
    ]);

    require_once __DIR__ . '/../telegram/notify.php';
    $kanal_label = ucfirst($daten['kanal']);
    telegram_senden("📦 Neue Bestellung ({$kanal_label}): " . ($daten['artikel_name'] ?? '?') . " x" . ($daten['menge'] ?? 1) . " — " . ($daten['kaeufer_name'] ?? '?'));

    return (int)$pdo->lastInsertId();
}


// ============================================================
// EBAY — vollständig angebunden (Production)
// ============================================================
require_once __DIR__ . '/ebay_config.php';

/**
 * Holt einen gültigen Access Token — nutzt einen zwischengespeicherten
 * Token, falls der noch nicht abgelaufen ist, sonst wird über den
 * langlebigen Refresh Token automatisch ein neuer geholt (kein manueller
 * Login nötig, der Refresh Token gilt ca. 18 Monate).
 */
function ebay_access_token_holen(): ?string
{
    $cache_datei = __DIR__ . '/ebay_token_cache.json';

    if (file_exists($cache_datei)) {
        $cache = json_decode(file_get_contents($cache_datei), true);
        if ($cache && isset($cache['access_token'], $cache['gueltig_bis']) && $cache['gueltig_bis'] > time() + 60) {
            return $cache['access_token']; // Noch gültig, direkt nutzen
        }
    }

    // Neuen Access Token über den Refresh Token holen
    $basic_auth = base64_encode(EBAY_CLIENT_ID . ':' . EBAY_CLIENT_SECRET);
    $ch = curl_init('https://api.ebay.com/identity/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $basic_auth,
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => EBAY_REFRESH_TOKEN,
            'scope' => EBAY_SCOPES,
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $antwort = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log('[eBay] Token-Erneuerung fehlgeschlagen: ' . $antwort);
        return null;
    }

    $daten = json_decode($antwort, true);
    if (!isset($daten['access_token'])) {
        error_log('[eBay] Unerwartete Token-Antwort: ' . $antwort);
        return null;
    }

    file_put_contents($cache_datei, json_encode([
        'access_token' => $daten['access_token'],
        'gueltig_bis' => time() + ($daten['expires_in'] ?? 7200),
    ]));

    return $daten['access_token'];
}

/**
 * Holt neue Bestellungen von eBay (Sell Fulfillment API) und trägt sie in
 * bestellungen ein. Ordnet automatisch das Lager-Produkt zu, wenn die
 * eBay-SKU mit lager_produkte.ebay_sku übereinstimmt.
 */
function ebay_orders_abrufen(PDO $pdo): array
{
    $token = ebay_access_token_holen();
    if (!$token) {
        return ['neu_importiert' => 0, 'hinweis' => 'Konnte keinen eBay-Access-Token holen — siehe Error-Log'];
    }

    $ch = curl_init('https://api.ebay.com/sell/fulfillment/v1/order?filter=orderfulfillmentstatus:%7BNOT_STARTED%7C IN_PROGRESS%7D&limit=50');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $antwort = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log('[eBay] Order-Abruf fehlgeschlagen (' . $http_code . '): ' . $antwort);
        return ['neu_importiert' => 0, 'hinweis' => 'eBay-API-Fehler, siehe Error-Log'];
    }

    $daten = json_decode($antwort, true);
    $orders = $daten['orders'] ?? [];
    $importiert = 0;

    foreach ($orders as $order) {
        $order_id = $order['orderId'] ?? null;
        if (!$order_id) continue;

        $kaeufer = $order['buyer']['username'] ?? 'eBay-Käufer';
        $adresse = $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo'] ?? [];
        $strasse = $adresse['contactAddress']['addressLine1'] ?? '';
        $plz = $adresse['contactAddress']['postalCode'] ?? '';
        $ort = $adresse['contactAddress']['city'] ?? '';
        $land = $adresse['contactAddress']['countryCode'] ?? 'DE';
        $name = $adresse['fullName'] ?? $kaeufer;

        // Jede Position (Line Item) einzeln als eigene Bestellung importieren,
        // damit die Lager-Zuordnung pro Artikel sauber funktioniert
        foreach ($order['lineItems'] ?? [] as $item) {
            // SKU (Custom Label) nutzen, falls vorhanden — sonst automatisch
            // auf die Artikel-ID zurückfallen (die ist immer vorhanden und
            // ändert sich nie, im Gegensatz zur optionalen SKU)
            $sku = $item['sku'] ?? '';
            if ($sku === '') {
                $sku = $item['legacyItemId'] ?? '';
            }
            $titel = $item['title'] ?? 'eBay-Artikel';
            $menge = (int)($item['quantity'] ?? 1);
            $preis = (float)($item['lineItemCost']['value'] ?? 0);

            $neue_id = bestellung_importieren($pdo, [
                'externe_order_id' => $order_id . '-' . ($item['lineItemId'] ?? uniqid()),
                'kanal' => 'ebay',
                'sku' => $sku,
                'kaeufer_name' => $name,
                'versand_strasse' => $strasse,
                'versand_plz' => $plz,
                'versand_ort' => $ort,
                'versand_land' => $land,
                'artikel_name' => $titel,
                'menge' => $menge,
                'verkaufspreis_brutto' => $preis,
                'bestelldatum' => $order['creationDate'] ?? date('Y-m-d H:i:s'),
            ]);
            if ($neue_id) $importiert++;
        }
    }

    return ['neu_importiert' => $importiert, 'hinweis' => "eBay-Sync erfolgreich, {$importiert} neue Position(en)"];
}

/**
 * Meldet eine Sendungsnummer zurück an eBay, damit der Käufer den Status
 * auch in seinem eigenen eBay-Account sieht.
 */
function ebay_tracking_zurueckmelden(string $ebay_order_id, string $sendungsnummer, string $carrier = 'DPD'): bool
{
    $token = ebay_access_token_holen();
    if (!$token) return false;

    // Bei zusammengesetzter ID (order-lineitem) den reinen Order-Teil verwenden
    $reine_order_id = explode('-', $ebay_order_id)[0] ?? $ebay_order_id;

    $body = json_encode([
        'lineItems' => [], // leer = alle Positionen der Bestellung als versendet markieren
        'shippedDate' => date('c'),
        'shippingCarrierCode' => $carrier,
        'trackingNumber' => $sendungsnummer,
    ]);

    $ch = curl_init("https://api.ebay.com/sell/fulfillment/v1/order/{$reine_order_id}/shipping_fulfillment");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 15,
    ]);
    $antwort = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) return true;

    error_log("[eBay] Tracking-Rückmeldung fehlgeschlagen ({$http_code}): {$antwort}");
    return false;
}


// ============================================================
// AMAZON — TODO nach Erhalt der Amazon SP-API-Zugangsdaten ausfüllen
// ============================================================
define('AMAZON_CLIENT_ID', '');
define('AMAZON_CLIENT_SECRET', '');
define('AMAZON_REFRESH_TOKEN', '');
define('AMAZON_SELLER_ID', '');

function amazon_orders_abrufen(PDO $pdo): array
{
    // TODO: LWA-Token holen (Login with Amazon)
    // TODO: GET /orders/v0/orders (Amazon Selling Partner API)
    // TODO: Für jede Bestellung bestellung_importieren($pdo, [...]) mit kanal='amazon' aufrufen
    return ['neu_importiert' => 0, 'hinweis' => 'Amazon-API noch nicht angebunden'];
}

function amazon_tracking_zurueckmelden(string $externe_order_id, string $sendungsnummer): bool
{
    // TODO: POST /orders/v0/orders/{orderId}/shipmentConfirmation
    return false;
}


// ============================================================
// TIKTOK SHOP — TODO nach Erhalt der TikTok-Shop-API-Zugangsdaten ausfüllen
// ============================================================
define('TIKTOK_APP_KEY', '');
define('TIKTOK_APP_SECRET', '');
define('TIKTOK_ACCESS_TOKEN', '');
define('TIKTOK_SHOP_ID', '');

function tiktok_orders_abrufen(PDO $pdo): array
{
    // TODO: GET /order/202309/orders (TikTok Shop Partner API)
    // TODO: Für jede Bestellung bestellung_importieren($pdo, [...]) mit kanal='tiktok' aufrufen
    return ['neu_importiert' => 0, 'hinweis' => 'TikTok-Shop-API noch nicht angebunden'];
}

function tiktok_tracking_zurueckmelden(string $externe_order_id, string $sendungsnummer): bool
{
    // TODO: POST /fulfillment/202309/orders/tracking
    return false;
}


// ============================================================
// SAMMEL-SYNC — später per Cron-Job alle 15 Min aufrufen, sobald
// mindestens eine der drei APIs angebunden ist
// ============================================================
function alle_kanaele_synchronisieren(PDO $pdo): array
{
    return [
        'ebay' => ebay_orders_abrufen($pdo),
        'amazon' => amazon_orders_abrufen($pdo),
        'tiktok' => tiktok_orders_abrufen($pdo),
    ];
}