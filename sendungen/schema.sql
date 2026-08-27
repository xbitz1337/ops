-- NA Ops Hub — Sendungen (manuelle Frachtverfolgung)
--
-- Einmalig in eurer Datenbank ausführen (z.B. über phpMyAdmin → SQL-Tab
-- eures Hosters einfügen und ausführen). Danach funktioniert die
-- "Sendungen"-Karte auf dem Dashboard.
--
-- Aktuell rein manuell gepflegt (Status per Dropdown selbst setzen).
-- Live-Statusabfrage bei der Spedition (z.B. über 17TRACK) ist als
-- späterer Ausbau vorgesehen, dafür würden weitere Spalten ergänzt
-- (z.B. externe Tracking-ID, letzter Abruf).

CREATE TABLE IF NOT EXISTS sendungen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    typ ENUM('intern', 'kunde') NOT NULL DEFAULT 'intern',
    spediteur VARCHAR(100) NOT NULL,
    tracking_nummer VARCHAR(100) NULL,
    inhalt VARCHAR(255) NOT NULL,
    ziel VARCHAR(255) NULL,
    status ENUM('unterwegs', 'zugestellt', 'verzoegert', 'zoll') NOT NULL DEFAULT 'unterwegs',
    erstellt_von VARCHAR(50) NULL,
    erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
