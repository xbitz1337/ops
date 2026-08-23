<?php
/**
 * NA Ops Hub — eBay-Zugangsdaten
 * WICHTIG: Diese Datei niemals öffentlich zugänglich machen (wie config.php)!
 */

define('EBAY_CLIENT_ID', 'NACommer-s-PRD-8a4b27176-8a3fd969');
define('EBAY_CLIENT_SECRET', 'PRD-a4b271762cae-78f2-4fa4-bb8e-ece0');
define('EBAY_REFRESH_TOKEN', 'v^1.1#i^1#f^0#I^3#p^3#r^1#t^Ul4xMF81OjAwMkNBOUZBQjBEODMzQjQyMTdFNzgwMEY1ODE3RTQ2XzJfMSNFXjI2MA==');
define('EBAY_RUNAME', 'NA_Commerce_Sol-NACommer-s-PRD--jvoimub');

// Alle Berechtigungen (Scopes), die bei der Einrichtung erteilt wurden.
// Für den Order-Sync wird v.a. sell.fulfillment gebraucht.
define('EBAY_SCOPES', 'https://api.ebay.com/oauth/api_scope https://api.ebay.com/oauth/api_scope/sell.fulfillment https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly https://api.ebay.com/oauth/api_scope/sell.inventory https://api.ebay.com/oauth/api_scope/sell.inventory.readonly');
