<?php
// Läuft beim Start des Containers und räumt die Kommentardatei auf.
//
// Die Datei kann in Produktion sehr groß oder beschädigt sein. Deshalb wird
// sie nicht als Ganzes eingelesen, sondern Objekt für Objekt durchlaufen: der
// Speicherbedarf bleibt konstant, und kaputte Stellen führen nicht dazu, dass
// alles verloren geht.
//
// Entfernt wird alles, was die Grenzen aus api/lib.php verletzt – zu lange
// Namen, zu lange Inhalte und zu große Kommentare fliegen komplett raus,
// samt ihren Antworten.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('memory_limit', '256M');

require_once __DIR__ . '/api/lib.php';

sp_boot();

$stats = [
    'gelesen'      => 0, // gefundene Objekte
    'behalten'     => 0,
    'kaputt'       => 0, // nicht lesbares JSON
    'name'         => 0, // Name zu lang oder unsauber
    'inhalt'       => 0, // Inhalt zu lang oder unsauber
    'zu_gross'     => 0, // Kommentar über der Datenmenge
    'ueberzaehlig' => 0, // über Anzahl- oder Gesamtgrenze
    'antworten'    => 0, // entfernte Antworten
];

/**
 * Prüft einen gespeicherten Eintrag gegen dieselben Grenzen, die zur Laufzeit
 * gelten. Gibt null zurück, wenn er gelöscht werden muss, und schreibt den
 * Grund nach $reason.
 */
$check = function ($entry, ?string &$reason = null): ?array {
    $reason = 'kaputt';
    if (!is_array($entry)) { return null; }
    if (!sp_is_clean_text($entry['id'] ?? null, 64)) { return null; }

    if (!sp_is_clean_text($entry['content'] ?? null, SP_MAX_CONTENT)) {
        $reason = 'inhalt';
        return null;
    }

    $name = $entry['name'] ?? '';
    if ($name === '' || $name === null) {
        $entry['name'] = 'anonym';
    } elseif (!sp_is_clean_text($name, SP_MAX_NAME)) {
        $reason = 'name';
        return null;
    }

    $entry['likes'] = max(0, (int) ($entry['likes'] ?? 0));

    if (sp_size(sp_entry_only($entry)) > SP_MAX_ENTRY_BYTES) {
        $reason = 'zu_gross';
        return null;
    }

    $reason = null;
    return $entry;
};

// --------------------------------------------------------------------------
// Ausgabe: wird direkt in eine Zwischendatei geschrieben, damit auch die
// bereinigte Liste nie komplett im Speicher liegt.
// --------------------------------------------------------------------------

$tmpFile = SP_DATA_DIR . '/comments.cleanup.tmp';
$out     = @fopen($tmpFile, 'wb');
if (!$out) {
    fwrite(STDERR, "cleanup: " . $tmpFile . " nicht schreibbar – Abbruch\n");
    exit(1);
}

fwrite($out, '[');
$first    = true;
$written  = 2; // die beiden Klammern
$kept     = 0;
$validIds = [];
$idsFull  = false;

$writeEntry = function (array $entry) use ($out, &$first, &$written, &$kept, &$validIds, &$idsFull): bool {
    if ($kept >= SP_MAX_COMMENTS) { return false; }

    $json = json_encode($entry, JSON_UNESCAPED_UNICODE);
    if ($json === false) { return false; }

    $extra = strlen($json) + ($first ? 0 : 1);
    if ($written + $extra > SP_MAX_TOTAL_BYTES) { return false; }

    fwrite($out, ($first ? '' : ',') . $json);
    $written += $extra;
    $first    = false;
    $kept++;

    // IDs für das Aufräumen der Likes merken (nach oben begrenzt)
    if (!$idsFull) {
        $validIds[$entry['id']] = true;
        foreach ($entry['replies'] as $reply) { $validIds[$reply['id']] = true; }
        if (count($validIds) > 200000) { $idsFull = true; $validIds = []; }
    }

    return true;
};

// Nimmt ein vollständig gelesenes Objekt entgegen und entscheidet darüber.
$handle = function (string $json) use ($check, $writeEntry, &$stats): void {
    $stats['gelesen']++;

    $comment = json_decode($json, true);
    $comment = $check($comment, $reason);
    if ($comment === null) {
        $stats[$reason]++;
        return;
    }

    // Antworten einzeln prüfen. Das Feld kann in einer gewachsenen Datei auch
    // etwas anderes als eine Liste enthalten – dann zählt es als leer.
    $replies = [];
    $stored  = $comment['replies'] ?? [];
    if (!is_array($stored)) { $stored = []; }

    foreach ($stored as $reply) {
        $reply = $check($reply, $replyReason);
        if ($reply === null) { $stats['antworten']++; continue; }
        $replies[] = $reply;
    }

    $stats['antworten']  += max(0, count($replies) - SP_MAX_REPLIES);
    $comment['replies']   = array_slice($replies, 0, SP_MAX_REPLIES);

    // Zu große Kommentare werden komplett gelöscht – nichts wird gekürzt.
    if (sp_size($comment) > SP_MAX_COMMENT_BYTES) {
        $stats['zu_gross']++;
        $stats['antworten'] += count($comment['replies']);
        return;
    }

    if ($writeEntry($comment)) {
        $stats['behalten']++;
    } else {
        $stats['ueberzaehlig']++;
        $stats['antworten'] += count($comment['replies']);
    }
};

// --------------------------------------------------------------------------
// Eingabe: Zeichen für Zeichen durch die Datei, Objekte auf oberster Ebene
// einsammeln. Alles dazwischen (Kommas, Klammern, Müll) wird übersprungen.
// --------------------------------------------------------------------------

$in = @fopen(SP_COMMENTS_FILE, 'rb');

if ($in) {
    $buffer   = '';
    $depth    = 0;
    $inString = false;
    $escape   = false;
    $skipping = false; // Objekt ist zu groß – nur noch bis zum Ende zählen

    while (($chunk = fread($in, 65536)) !== false && $chunk !== '') {
        $length = strlen($chunk);

        for ($i = 0; $i < $length; $i++) {
            $char = $chunk[$i];

            if ($depth === 0) {
                // Außerhalb eines Objekts: auf die nächste öffnende Klammer warten
                if ($char === '{') {
                    $depth    = 1;
                    $buffer   = '{';
                    $inString = false;
                    $escape   = false;
                    $skipping = false;
                }
                continue;
            }

            if (!$skipping) { $buffer .= $char; }

            if ($escape) {
                $escape = false;
            } elseif ($inString && $char === '\\') {
                $escape = true;
            } elseif ($char === '"') {
                $inString = !$inString;
            } elseif (!$inString) {
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;

                    if ($depth === 0) {
                        if ($skipping) {
                            $stats['gelesen']++;
                            $stats['zu_gross']++;
                        } else {
                            $handle($buffer);
                        }
                        $buffer   = '';
                        $skipping = false;
                        continue;
                    }
                }
            }

            // Riesiges Objekt gar nicht erst sammeln – es wäre ohnehin zu groß.
            if (!$skipping && strlen($buffer) > SP_SCAN_MAX_BYTES) {
                $skipping = true;
                $buffer   = '';
            }
        }
    }

    // Abgeschnittenes Objekt am Dateiende
    if ($depth > 0) { $stats['gelesen']++; $stats['kaputt']++; }

    fclose($in);
}

fwrite($out, ']');
fflush($out);
fclose($out);

// --------------------------------------------------------------------------
// Ergebnis an die Stelle der Originaldatei schreiben – unter Sperre und ohne
// die Datei zu ersetzen, damit parallele Zugriffe dieselbe Datei sehen.
// --------------------------------------------------------------------------

$replaced = false;
$target   = @fopen(SP_COMMENTS_FILE, 'c+');

if ($target && flock($target, LOCK_EX)) {
    $source = @fopen($tmpFile, 'rb');
    if ($source) {
        ftruncate($target, 0);
        rewind($target);
        stream_copy_to_stream($source, $target);
        fflush($target);
        fclose($source);
        $replaced = true;
    }
    flock($target, LOCK_UN);
}
if ($target) { fclose($target); }
@unlink($tmpFile);

if (!$replaced) {
    fwrite(STDERR, "cleanup: Ergebnis konnte nicht geschrieben werden\n");
    exit(1);
}

// --------------------------------------------------------------------------
// Likes: verwaiste Einträge entfernen. Ist die Datei unbrauchbar oder zu
// groß, wird sie zurückgesetzt – die Zähler selbst stehen in comments.json.
// --------------------------------------------------------------------------

$likesSize  = (int) @filesize(SP_LIKES_FILE);
$likesReset = false;

if ($likesSize > SP_MAX_TOTAL_BYTES) {
    @file_put_contents(SP_LIKES_FILE, '{}');
    $likesReset = true;
} else {
    sp_update_json(SP_LIKES_FILE, function (array &$likes) use ($validIds, $idsFull) {
        foreach ($likes as $id => $ips) {
            if (!is_array($ips) || (!$idsFull && !isset($validIds[$id]))) {
                unset($likes[$id]);
                continue;
            }
            $likes[$id] = array_slice(array_values(array_unique($ips)), 0, SP_MAX_LIKE_IPS);
        }
        return true;
    });
}

// --------------------------------------------------------------------------

printf(
    "cleanup (%s): %d gefunden, %d behalten, %d entfernt "
    . "(Name %d, Inhalt %d, zu groß %d, kaputt %d, überzählig %d), %d Antwort(en) entfernt%s\n",
    SP_DATA_DIR,
    $stats['gelesen'],
    $stats['behalten'],
    $stats['gelesen'] - $stats['behalten'],
    $stats['name'],
    $stats['inhalt'],
    $stats['zu_gross'],
    $stats['kaputt'],
    $stats['ueberzaehlig'],
    $stats['antworten'],
    $likesReset ? ', likes.json zurückgesetzt' : ''
);
