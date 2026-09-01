<?php
// Gemeinsame Basis für alle API-Endpunkte:
// Datenzugriff mit Sperren, IP-Ermittlung, Traffic-Sperre und Schreib-Limit.

if (defined('SP_LIB')) { return; }
define('SP_LIB', 1);

define('SP_DATA_DIR',      __DIR__ . '/../data');
define('SP_IP_DIR',        SP_DATA_DIR . '/ip');
define('SP_COMMENTS_FILE', SP_DATA_DIR . '/comments.json');
define('SP_LIKES_FILE',    SP_DATA_DIR . '/likes.json');

// Traffic-Sperre (gilt für jede Art von Anfrage)
define('SP_TRAFFIC_WINDOW', 10);     // Länge des Zeitfensters in Sekunden
define('SP_TRAFFIC_MAX',    40);     // erlaubte Anfragen je Fenster und IP
define('SP_BLOCK_BASE',     60);     // erste Sperre: 1 Minute
define('SP_BLOCK_MAX',      86400);  // Obergrenze: 24 Stunden
define('SP_VIOLATION_TTL',  86400);  // Verstöße nach dieser Ruhezeit vergessen

// Schreib-Limit: maximal 3 Kommentare/Antworten pro Minute
define('SP_WRITE_WINDOW',   60);
define('SP_WRITE_MAX',      3);

// Größenbegrenzungen, damit die Dateien nicht unbegrenzt wachsen
// Body großzügiger als der Inhalt selbst: 2000 Zeichen können als UTF-8 oder
// mit \uXXXX-Escapes deutlich mehr Bytes belegen.
define('SP_MAX_BODY',       32768);
define('SP_MAX_CONTENT',    2000);
define('SP_MAX_NAME',       60);
define('SP_MAX_COMMENTS',   2000);
define('SP_MAX_REPLIES',    200);
define('SP_MAX_LIKE_IPS',   5000);

// Datenmenge je Eintrag, als JSON gemessen: ein einzelner Kommentar bzw. eine
// einzelne Antwort, und ein Kommentar samt allen seinen Antworten.
define('SP_MAX_ENTRY_BYTES',   16384);
define('SP_MAX_COMMENT_BYTES', 65536);

// --------------------------------------------------------------------------
// Grundlagen
// --------------------------------------------------------------------------

function sp_boot(): void
{
    foreach ([SP_DATA_DIR, SP_IP_DIR] as $dir) {
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    }
}

// Echte Besucher-IP – hinter einem Proxy steht in REMOTE_ADDR nur der Proxy,
// dann würden sich alle Besucher ein Limit teilen.
function sp_ip(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    foreach (explode(',', $forwarded) as $candidate) {
        $candidate = trim($candidate);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) { return $candidate; }
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

function sp_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Liest den Request-Body, aber niemals mehr als SP_MAX_BODY Bytes.
function sp_body(): ?array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > SP_MAX_BODY) { return null; }

    $raw = @file_get_contents('php://input', false, null, 0, SP_MAX_BODY + 1);
    if ($raw === false || strlen($raw) > SP_MAX_BODY) { return null; }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function sp_text(?string $value, int $max): string
{
    $value = trim((string) $value);
    // Ungültiges UTF-8 verwerfen: json_encode könnte damit die ganze Datei
    // nicht mehr schreiben.
    if (!mb_check_encoding($value, 'UTF-8')) { return ''; }

    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $max);
}

// Prüft bereits gespeicherten Text gegen dieselben Regeln wie sp_text – ohne
// zu kürzen. Für das Aufräumen von Altbestand.
function sp_is_clean_text($value, int $max): bool
{
    if (!is_string($value) || $value === '') { return false; }
    if (!mb_check_encoding($value, 'UTF-8')) { return false; }
    if (mb_strlen($value) > $max) { return false; }

    return !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value);
}

// Datenmenge eines Eintrags in Bytes, so wie er auf der Platte landet.
function sp_size($value): int
{
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
    return $encoded === false ? PHP_INT_MAX : strlen($encoded);
}

// Kommentar ohne seine Antworten – für die Prüfung des einzelnen Eintrags.
function sp_entry_only(array $entry): array
{
    unset($entry['replies']);
    return $entry;
}

// --------------------------------------------------------------------------
// JSON-Dateien mit Sperre lesen/schreiben – ohne das würden parallele
// Anfragen sich gegenseitig überschreiben und die Datei zerstören.
// --------------------------------------------------------------------------

function sp_read_json(string $file): array
{
    $handle = @fopen($file, 'r');
    if (!$handle) { return []; }

    $data = [];
    if (flock($handle, LOCK_SH)) {
        $raw  = stream_get_contents($handle);
        $data = json_decode((string) $raw, true);
        flock($handle, LOCK_UN);
    }
    fclose($handle);

    return is_array($data) ? $data : [];
}

// Liest die Datei, übergibt sie per Referenz an $mutator und schreibt sie
// zurück. Gibt $mutator false zurück, bleibt die Datei unverändert.
function sp_update_json(string $file, callable $mutator)
{
    sp_boot();

    $handle = @fopen($file, 'c+');
    if (!$handle) { return false; }
    if (!flock($handle, LOCK_EX)) { fclose($handle); return false; }

    $raw  = stream_get_contents($handle);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) { $data = []; }

    $result = $mutator($data);

    if ($result !== false) {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($encoded !== false) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $encoded);
            fflush($handle);
        }
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return $result;
}

// --------------------------------------------------------------------------
// Zustand je IP – eine kleine Datei pro IP, damit auch unter Last nur ein
// winziger Lese-/Schreibvorgang nötig ist.
// --------------------------------------------------------------------------

function sp_ip_file(string $ip): string
{
    return SP_IP_DIR . '/' . sha1($ip) . '.json';
}

/**
 * $mutator bekommt den Zustand per Referenz:
 *   w  = Beginn des aktuellen Traffic-Fensters
 *   n  = Anfragen in diesem Fenster
 *   v  = Anzahl der Verstöße (bestimmt die Sperrdauer)
 *   lv = Zeitpunkt des letzten Verstoßes
 *   b  = gesperrt bis (Unix-Zeit)
 *   c  = Zeitstempel der letzten Kommentare
 */
function sp_update_ip_state(string $ip, callable $mutator)
{
    sp_boot();

    $handle = @fopen(sp_ip_file($ip), 'c+');
    if (!$handle) { return null; }
    if (!flock($handle, LOCK_EX)) { fclose($handle); return null; }

    $raw   = stream_get_contents($handle);
    $state = json_decode((string) $raw, true);
    if (!is_array($state)) { $state = []; }
    $state += ['w' => 0, 'n' => 0, 'v' => 0, 'lv' => 0, 'b' => 0, 'c' => []];

    $result = $mutator($state);

    $encoded = json_encode($state);
    if ($encoded !== false) {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $encoded);
        fflush($handle);
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return $result;
}

// --------------------------------------------------------------------------
// Traffic-Sperre
// --------------------------------------------------------------------------

// Sperrdauer wie bei einem Handy-Sperrbildschirm: 1, 2, 4, 8 … Minuten.
function sp_block_duration(int $violations): int
{
    if ($violations < 1) { $violations = 1; }
    if ($violations > 20) { $violations = 20; }

    return (int) min(SP_BLOCK_MAX, SP_BLOCK_BASE * (2 ** ($violations - 1)));
}

/**
 * Zählt die Anfrage und liefert die Restsperrzeit in Sekunden (0 = frei).
 */
function sp_register_request(string $ip): int
{
    $now = time();

    $remaining = sp_update_ip_state($ip, function (array &$s) use ($now) {
        // Bereits gesperrt: nur ablehnen, nicht weiter hochzählen.
        if ($s['b'] > $now) { return $s['b'] - $now; }

        // Verstöße nach längerer Ruhe wieder vergessen.
        if ($s['v'] > 0 && $now - $s['lv'] > SP_VIOLATION_TTL) { $s['v'] = 0; }

        // Gleitendes Zeitfenster
        if ($now - $s['w'] >= SP_TRAFFIC_WINDOW) {
            $s['w'] = $now;
            $s['n'] = 0;
        }
        $s['n']++;

        if ($s['n'] > SP_TRAFFIC_MAX) {
            $s['v']++;
            $s['lv'] = $now;
            $s['b']  = $now + sp_block_duration($s['v']);
            $s['n']  = 0;
            $s['w']  = $now;
            return $s['b'] - $now;
        }

        return 0;
    });

    return is_int($remaining) ? $remaining : 0;
}

function sp_reject_blocked(int $seconds): void
{
    $minutes = (int) ceil($seconds / 60);

    if (!headers_sent()) {
        http_response_code(429);
        header('Retry-After: ' . $seconds);
        header('Cache-Control: no-store');
    }

    $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    $text  = "Zu viele Anfragen. Bitte in {$minutes} Minute(n) erneut versuchen.";

    if ($isApi) {
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['success' => false, 'message' => $text], JSON_UNESCAPED_UNICODE);
    } else {
        if (!headers_sent()) { header('Content-Type: text/plain; charset=utf-8'); }
        echo $text . "\n";
    }
    exit;
}

// Einmal pro Anfrage: Traffic zählen und bei Sperre sofort abbrechen.
function sp_guard(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    sp_boot();
    sp_gc();

    $remaining = sp_register_request(sp_ip());
    if ($remaining > 0) { sp_reject_blocked($remaining); }
}

// --------------------------------------------------------------------------
// Schreib-Limit: 3 Kommentare/Antworten pro Minute und IP
// --------------------------------------------------------------------------

function sp_check_write_limit(string $ip): bool
{
    $now = time();

    $allowed = sp_update_ip_state($ip, function (array &$s) use ($now) {
        $recent = array_values(array_filter(
            is_array($s['c']) ? $s['c'] : [],
            fn($t) => is_int($t) && $now - $t < SP_WRITE_WINDOW
        ));

        if (count($recent) >= SP_WRITE_MAX) {
            $s['c'] = $recent;
            return false;
        }

        $recent[] = $now;
        $s['c']   = $recent;
        return true;
    });

    return $allowed === true;
}

// --------------------------------------------------------------------------
// Aufräumen: alte IP-Zustandsdateien gelegentlich entfernen, damit das
// Verzeichnis nicht unbegrenzt wächst.
// --------------------------------------------------------------------------

function sp_gc(): void
{
    if (random_int(1, 200) !== 1) { return; }

    $dir = @opendir(SP_IP_DIR);
    if (!$dir) { return; }

    $now     = time();
    $checked = 0;
    while (($entry = readdir($dir)) !== false && $checked < 500) {
        if ($entry === '.' || $entry === '..') { continue; }
        $checked++;

        $path = SP_IP_DIR . '/' . $entry;
        $age  = $now - (int) @filemtime($path);
        if ($age > SP_VIOLATION_TTL) { @unlink($path); }
    }
    closedir($dir);
}
