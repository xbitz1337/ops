<?php
/**
 * NA Ops Hub — Telegram-Benachrichtigungen — Konfiguration
 *
 * SO RICHTET IHR DEN BOT EIN (dauert ca. 5 Minuten):
 *
 * 1. In Telegram nach "@BotFather" suchen, Chat öffnen
 * 2. "/newbot" senden, einen Namen vergeben (z.B. "NA Ops Hub Bot")
 *    und einen Username (muss auf "bot" enden, z.B. "na_ops_hub_bot")
 * 3. BotFather schickt euch einen TOKEN zurück (sieht aus wie
 *    "123456789:AAAbbbCCCdddEEEfffGGGhhh") — den unten bei
 *    TELEGRAM_BOT_TOKEN eintragen
 * 4. Euren neuen Bot in Telegram suchen (per Username) und "/start" senden
 * 5. Eure Chat-ID herausfinden: im Browser aufrufen
 *    https://api.telegram.org/bot<EUER_TOKEN>/getUpdates
 *    (den echten Token einsetzen), nach "chat":{"id": SUCHEN — diese
 *    Zahl unten bei TELEGRAM_CHAT_ID eintragen
 *
 * Für eine GRUPPE (falls ihr beide Nachrichten bekommen wollt):
 * Bot zur Gruppe hinzufügen, eine Nachricht in der Gruppe schreiben,
 * dann wieder getUpdates aufrufen — die Gruppen-Chat-ID ist negativ
 * (z.B. -123456789), funktioniert genauso.
 */

define('TELEGRAM_BOT_TOKEN', '8967125692:AAHwiK_WzZYR53ZB9_RLKOKBkKsUaMgVHoo'); // TODO: Token von BotFather eintragen
define('TELEGRAM_CHAT_ID', '906208364');   // TODO: eure Chat-ID eintragen
