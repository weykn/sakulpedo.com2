<?php
// Gemeinsame Helfer für die Kommentar-API.

/**
 * Absoluter Pfad in das Datenverzeichnis neben /api.
 * Bewusst über __DIR__ statt relativ: relative Pfade zeigen auf das
 * Arbeitsverzeichnis des Servers, nicht auf das Skript – je nach Startbefehl
 * schreiben die Endpunkte sonst in unterschiedliche Dateien.
 */
function data_file(string $name): string {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/' . $name;
}

/**
 * IP des Besuchers. Hinter einem Proxy (Docker/Render) ist REMOTE_ADDR die
 * Proxy-IP und damit für alle Besucher identisch – dann zählt der erste
 * Eintrag aus X-Forwarded-For.
 */
function client_ip(): string {
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd !== '') {
        $first = trim(explode(',', $fwd)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
            return $first;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function read_json(string $file, array $default = []): array {
    if (!is_file($file)) {
        return $default;
    }
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

/**
 * Read-modify-write unter exklusivem Lock. $fn bekommt die dekodierten Daten
 * per Referenz und darf sie verändern; danach wird die Datei atomar ersetzt.
 * Ohne den Lock lesen zwei schnelle Klicks denselben Stand und der zweite
 * Schreibvorgang überschreibt den ersten.
 */
function with_locked_json(string $file, callable $fn): bool {
    $fh = fopen($file, 'c+');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return false;
    }

    $raw  = stream_get_contents($fh);
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        $data = [];
    }

    $fn($data);

    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ok = false;
    if ($encoded !== false) {
        rewind($fh);
        $ok = ftruncate($fh, 0) && fwrite($fh, $encoded) !== false && fflush($fh);
    }

    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/* ------------------------------------------------------------------ Likes */
/*
 * likes.json ist die einzige Quelle der Wahrheit für Likes:
 *   { "<id>": { "base": int, "ips": ["1.2.3.4", ...] } }
 *
 * "base" hält die Like-Zahl fest, die vor diesem Store in comments.json stand
 * (siehe like_entry). Das Feld "likes" in comments.json wird deshalb nur noch
 * als Startwert gelesen und nie mehr fortgeschrieben.
 */

function normalize_ips(array $ips): array {
    $out = [];
    foreach ($ips as $ip) {
        if (is_string($ip) && $ip !== '' && !in_array($ip, $out, true)) {
            $out[] = $ip;
        }
    }
    return $out;
}

/**
 * Liefert den Like-Eintrag zu $id, normalisiert auf das aktuelle Format.
 * $legacyLikes ist der in comments.json gespeicherte Zähler und wird nur
 * herangezogen, solange der Eintrag noch nicht im neuen Format vorliegt –
 * so gehen bereits gesammelte Likes bei der Umstellung nicht verloren.
 */
function like_entry(array $store, string $id, int $legacyLikes = 0): array {
    $entry = $store[$id] ?? null;

    if (is_array($entry) && isset($entry['ips']) && is_array($entry['ips'])) {
        return [
            'base' => max(0, (int)($entry['base'] ?? 0)),
            'ips'  => normalize_ips($entry['ips']),
        ];
    }

    if (is_array($entry)) {
        // Altes Format: reine IP-Liste. Die Differenz zum alten Zähler bleibt
        // als base erhalten, damit die angezeigte Zahl gleich bleibt.
        $ips = normalize_ips($entry);
        return ['base' => max(0, $legacyLikes - count($ips)), 'ips' => $ips];
    }

    return ['base' => max(0, $legacyLikes), 'ips' => []];
}

function like_total(array $entry): int {
    return $entry['base'] + count($entry['ips']);
}

/**
 * Sucht $id unter den Kommentaren und deren Antworten und gibt den dort
 * gespeicherten Alt-Zähler zurück – oder null, wenn die ID nicht existiert.
 */
function legacy_like_count(array $comments, string $id): ?int {
    foreach ($comments as $comment) {
        if (!is_array($comment)) {
            continue;
        }
        if (isset($comment['id']) && (string)$comment['id'] === $id) {
            return (int)($comment['likes'] ?? 0);
        }
        $replies = $comment['replies'] ?? [];
        if (!is_array($replies)) {
            continue;
        }
        foreach ($replies as $reply) {
            if (is_array($reply) && isset($reply['id']) && (string)$reply['id'] === $id) {
                return (int)($reply['likes'] ?? 0);
            }
        }
    }
    return null;
}

/**
 * ID für Kommentare/Antworten. Der Buchstaben-Präfix ist wichtig: eine rein
 * numerische ID würde als Array-Schlüssel in likes.json zu einem int und
 * könnte mit einer anderen ID zusammenfallen.
 */
function new_id(): string {
    return 'c' . bin2hex(random_bytes(8));
}

function json_fail(string $message): void {
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
