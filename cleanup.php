<?php
// Wird beim Start des Containers ausgeführt: entfernt zu lange oder kaputte
// Kommentare, damit alte Daten die Größenbegrenzungen nicht umgehen.

require_once __DIR__ . '/api/lib.php';

sp_boot();

$removedComments = 0;
$removedReplies  = 0;
$trimmedComments = 0;

/**
 * Prüft einen gespeicherten Eintrag gegen dieselben Grenzen, die zur Laufzeit
 * gelten. Gibt null zurück, wenn der Eintrag gelöscht werden muss.
 */
$check = function ($entry): ?array {
    if (!is_array($entry)) { return null; }

    // ID, Inhalt und Name müssen sauber und innerhalb der Grenzen sein.
    if (!sp_is_clean_text($entry['id'] ?? null, 64))              { return null; }
    if (!sp_is_clean_text($entry['content'] ?? null, SP_MAX_CONTENT)) { return null; }

    $name = $entry['name'] ?? '';
    if ($name === '' || $name === null) {
        $entry['name'] = 'anonym';
    } elseif (!sp_is_clean_text($name, SP_MAX_NAME)) {
        return null;
    }

    $entry['likes'] = max(0, (int) ($entry['likes'] ?? 0));

    // Datenmenge des einzelnen Eintrags (ohne Antworten)
    if (sp_size(sp_entry_only($entry)) > SP_MAX_ENTRY_BYTES) { return null; }

    return $entry;
};

sp_update_json(SP_COMMENTS_FILE, function (array &$comments) use ($check, &$removedComments, &$removedReplies, &$trimmedComments) {
    $clean = [];

    foreach ($comments as $comment) {
        $comment = $check($comment);
        if ($comment === null) { $removedComments++; continue; }

        $replies = [];
        foreach ($comment['replies'] ?? [] as $reply) {
            $reply = $check($reply);
            if ($reply === null) { $removedReplies++; continue; }
            $replies[] = $reply;
        }

        $removedReplies    += max(0, count($replies) - SP_MAX_REPLIES);
        $comment['replies'] = array_slice($replies, 0, SP_MAX_REPLIES);

        // Gesamte Datenmenge: älteste Antworten abschneiden, bis es passt.
        $trimmed = false;
        while (sp_size($comment) > SP_MAX_COMMENT_BYTES && $comment['replies']) {
            array_pop($comment['replies']);
            $removedReplies++;
            $trimmed = true;
        }
        if ($trimmed) { $trimmedComments++; }

        // Passt der Kommentar auch ohne Antworten nicht, fliegt er ganz raus.
        if (sp_size($comment) > SP_MAX_COMMENT_BYTES) { $removedComments++; continue; }

        $clean[] = $comment;
    }

    $removedComments += max(0, count($clean) - SP_MAX_COMMENTS);
    $comments = array_slice($clean, 0, SP_MAX_COMMENTS);

    return true;
});

// Verwaiste Like-Einträge und übergroße IP-Listen entfernen.
$validIds = [];
foreach (sp_read_json(SP_COMMENTS_FILE) as $comment) {
    $validIds[$comment['id']] = true;
    foreach ($comment['replies'] ?? [] as $reply) { $validIds[$reply['id']] = true; }
}

sp_update_json(SP_LIKES_FILE, function (array &$likes) use ($validIds) {
    foreach ($likes as $id => $ips) {
        if (!isset($validIds[$id]) || !is_array($ips)) {
            unset($likes[$id]);
            continue;
        }
        $likes[$id] = array_slice(array_values(array_unique($ips)), 0, SP_MAX_LIKE_IPS);
    }
    return true;
});

echo "cleanup: {$removedComments} Kommentar(e), {$removedReplies} Antwort(en) entfernt, "
   . "{$trimmedComments} Kommentar(e) auf die maximale Datenmenge gekürzt\n";
