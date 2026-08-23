<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
$pdo = db();

$produkte = $pdo->query("
    SELECT p.name, k.name AS kategorie,
           COALESCE(SUM(CASE
               WHEN b.typ = 'zugang_einkauf' THEN b.menge
               WHEN b.typ = 'retoure' AND b.wieder_verkaufsfaehig = 1 THEN b.menge
               WHEN b.typ = 'korrektur' THEN b.menge
               WHEN b.typ IN ('abgang_verkauf','abgang_eigenbedarf') THEN -b.menge
               ELSE 0 END), 0) AS bestand,
           COALESCE(
               (SELECT SUM(b2.menge * b2.ek_preis_netto) / NULLIF(SUM(b2.menge), 0)
                FROM lager_bewegungen b2
                WHERE b2.produkt_id = p.id AND b2.typ = 'zugang_einkauf' AND b2.ek_preis_netto IS NOT NULL AND b2.ek_preis_netto > 0),
               p.ek_preis_netto
           ) AS ek_preis_avg,
           p.vk_preis_brutto
    FROM lager_produkte p
    JOIN lager_kategorien k ON k.id = p.kategorie_id
    LEFT JOIN lager_bewegungen b ON b.produkt_id = p.id
    WHERE p.aktiv = 1
    GROUP BY p.id, p.name, k.name, p.ek_preis_netto, p.vk_preis_brutto
    ORDER BY k.sortierung, p.name
")->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="Lagerbestand_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM, damit Excel Umlaute korrekt zeigt
fputcsv($out, ['Produkt', 'Kategorie', 'Bestand (Stk)', 'Ø EK/Stk (€)', 'EK-Wert Bestand (€)', 'VK-Preis (€)'], ';');

foreach ($produkte as $p) {
    fputcsv($out, [
        $p['name'],
        $p['kategorie'],
        $p['bestand'],
        number_format($p['ek_preis_avg'], 2, ',', ''),
        number_format($p['bestand'] * $p['ek_preis_avg'], 2, ',', ''),
        $p['vk_preis_brutto'] ? number_format($p['vk_preis_brutto'], 2, ',', '') : '',
    ], ';');
}

fclose($out);
