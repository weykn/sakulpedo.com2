<?php
// Router für den eingebauten PHP-Server: jede Anfrage – egal ob Seite, Bild
// oder API – läuft zuerst durch die Traffic-Sperre.

require_once __DIR__ . '/api/lib.php';

sp_guard();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Interne Dateien nicht ausliefern.
if (preg_match('#^/(data|router\.php|cleanup\.php|remove\.php|Dockerfile|api/lib\.php)#', $path)) {
    http_response_code(404);
    echo "Not found\n";
    return true;
}

// Alles Übrige übernimmt der eingebaute Server (statische Dateien und PHP).
return false;
