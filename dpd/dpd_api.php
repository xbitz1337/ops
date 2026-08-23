<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — DPD Business — Label-API (VORBEREITET, NOCH NICHT AKTIV)
 *
 * Sobald ihr DPD-Business-Kunde seid und die API-Zugangsdaten habt:
 * 1. Zugangsdaten unten eintragen (Delisid, Passwort — bekommt ihr von DPD
 *    nach Registrierung für die "DPD Shipping REST API" bzw. den
 *    "DPD Online Business Versand")
 * 2. dpd_label_erstellen() unten implementieren
 * 3. In der Checkliste (bestellungen/checkliste.php) den "Label erstellt"-
 *    Schritt durch einen echten "DPD-Label automatisch erstellen"-Button
 *    ersetzen, der diese Funktion aufruft und das PDF-Label direkt anzeigt
 *
 * DPD-Doku: https://esolutions.dpd.com (Shipping REST API)
 * Bis dahin bleibt der Ablauf wie gehabt: Label manuell bei DPD/eBay erstellen,
 * Sendungsnummer in der Checkliste eintragen/scannen.
 */

// TODO: nach DPD-Business-Freigabe eintragen
define('DPD_DELIS_ID', '');
define('DPD_PASSWORT', '');
define('DPD_KUNDENNUMMER', '');
define('DPD_ABSENDER_NAME', 'NA Commerce Solutions GmbH');
define('DPD_ABSENDER_STRASSE', 'Am Bahndamm 7');
define('DPD_ABSENDER_PLZ', '28719');
define('DPD_ABSENDER_ORT', 'Bremen');

/**
 * Erstellt ein Versandlabel bei DPD für eine Bestellung und gibt die
 * Sendungsnummer + das Label als PDF (base64) zurück.
 *
 * NOCH NICHT IMPLEMENTIERT — Platzhalter.
 *
 * @param array $bestellung Datensatz aus der bestellungen-Tabelle
 * @return array{erfolgreich: bool, sendungsnummer: ?string, label_pdf_base64: ?string, fehler: ?string}
 */
function dpd_label_erstellen(array $bestellung): array
{
    // TODO: Login-Request (getAuth) mit DPD_DELIS_ID + DPD_PASSWORT
    // TODO: storeOrders-Request mit Absender-/Empfängerdaten aus $bestellung
    // TODO: PDF-Label per printLabel abrufen und als base64 zurückgeben
    // TODO: Sendungsnummer (parcelLabelNumber) extrahieren

    return [
        'erfolgreich' => false,
        'sendungsnummer' => null,
        'label_pdf_base64' => null,
        'fehler' => 'DPD-API noch nicht angebunden — Label bitte manuell erstellen',
    ];
}

/**
 * Storniert ein bereits erstelltes DPD-Label (z.B. bei Fehlbuchung).
 * NOCH NICHT IMPLEMENTIERT.
 */
function dpd_label_stornieren(string $sendungsnummer): bool
{
    // TODO: deleteOrders-Request
    return false;
}
