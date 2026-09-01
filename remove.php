<?php
// Kommentare oder Antworten von der Konsole löschen – nach ID oder nach Name.
//
//   php remove.php --id=6a97...            einen Kommentar oder eine Antwort
//   php remove.php --user=Spammer          alles von diesem Namen
//   php remove.php --user=A --user=B       mehrere auf einmal
//   php remove.php --user=Spammer --dry-run   nur anzeigen, nichts löschen
//
// Im Container:
//   docker exec <name> php /app/remove.php --user=Spammer

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/api/lib.php';

$ids    = [];
$users  = [];
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--id=(.+)$/s', $arg, $m)) {
        $ids[$m[1]] = true;
    } elseif (preg_match('/^--user=(.+)$/s', $arg, $m)) {
        $users[mb_strtolower(trim($m[1]))] = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } else {
        fwrite(STDERR, "Unbekannte Option: {$arg}\n");
        $ids = $users = [];
        break;
    }
}

if (!$ids && !$users) {
    fwrite(STDERR,
        "Aufruf: php remove.php --id=<ID> | --user=<Name> [weitere ...] [--dry-run]\n"
        . "  --id=<ID>      löscht den Kommentar oder die Antwort mit dieser ID\n"
        . "  --user=<Name>  löscht alle Kommentare und Antworten dieses Namens\n"
        . "                 (Groß-/Kleinschreibung egal)\n"
        . "  --dry-run      zeigt nur an, was gelöscht würde\n");
    exit(1);
}

// Trifft dieser Eintrag auf eine der Vorgaben zu?
$matches = function (array $entry) use ($ids, $users): bool {
    if (isset($ids[$entry['id'] ?? ''])) { return true; }

    $name = mb_strtolower(trim((string) ($entry['name'] ?? '')));
    return $name !== '' && isset($users[$name]);
};

$hitComments = [];
$hitReplies  = [];
$goneIds     = [];

$note = function (array $entry, array &$bucket) use (&$goneIds): void {
    $goneIds[] = $entry['id'] ?? '';
    $bucket[]  = sprintf(
        '%s  %-20s %s',
        $entry['id'] ?? '?',
        mb_substr((string) ($entry['name'] ?? '?'), 0, 20),
        str_replace(["\n", "\r"], ' ', mb_substr((string) ($entry['content'] ?? ''), 0, 60))
    );
};

$ok = sp_update_json(SP_COMMENTS_FILE, function (array &$comments) use ($matches, $note, &$hitComments, &$hitReplies, $dryRun) {
    $keep = [];

    foreach ($comments as $comment) {
        if (!is_array($comment)) { continue; }

        // Ganzen Kommentar löschen – die Antworten daran gehen mit.
        if ($matches($comment)) {
            $note($comment, $hitComments);
            foreach ($comment['replies'] ?? [] as $reply) {
                if (is_array($reply)) { $note($reply, $hitReplies); }
            }
            continue;
        }

        $stored  = $comment['replies'] ?? [];
        $replies = [];
        foreach (is_array($stored) ? $stored : [] as $reply) {
            if (is_array($reply) && $matches($reply)) {
                $note($reply, $hitReplies);
                continue;
            }
            $replies[] = $reply;
        }
        $comment['replies'] = $replies;

        $keep[] = $comment;
    }

    $comments = $keep;

    // Beim Probelauf nichts schreiben
    return $dryRun ? false : true;
});

if ($ok === false && !$dryRun) {
    fwrite(STDERR, "Fehler: " . SP_COMMENTS_FILE . " konnte nicht geschrieben werden\n");
    exit(1);
}

// Passende Like-Einträge mit entfernen
if (!$dryRun && $goneIds) {
    sp_update_json(SP_LIKES_FILE, function (array &$likes) use ($goneIds) {
        foreach ($goneIds as $id) { unset($likes[$id]); }
        return true;
    });
}

// --------------------------------------------------------------------------

$prefix = $dryRun ? '[Probelauf] ' : '';

foreach ($hitComments as $line) { echo $prefix . "Kommentar  " . $line . "\n"; }
foreach ($hitReplies  as $line) { echo $prefix . "Antwort    " . $line . "\n"; }

printf(
    "%s%d Kommentar(e) und %d Antwort(en) %s\n",
    $prefix,
    count($hitComments),
    count($hitReplies),
    $dryRun ? 'würden gelöscht' : 'gelöscht'
);

exit(($hitComments || $hitReplies) ? 0 : 1);
