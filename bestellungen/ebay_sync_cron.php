<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — eBay-Sync — Cron-Job
 *
 * Cron-Befehl (hPanel), alle 15 Minuten:
 *   */15 * * * *
 *   /usr/bin/php /home/USERNAME/domains/.../public_html/ops/bestellungen/ebay_sync_cron.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/channel_apis.php';

$pdo = db();
$ergebnis = ebay_orders_abrufen($pdo);

error_log('[eBay-Sync] ' . json_encode($ergebnis));
