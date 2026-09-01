<?php
// Kommentare oder Antworten von der Konsole löschen – nach ID, Name oder IP.
//
//   php remove.php --id=6a97...            einen Kommentar oder eine Antwort
//   php remove.php --user=Spammer          alles von diesem Namen
//   php remove.php --ip=1.2.3.4           alles von dieser IP
//   php remove.php --user=A --user=B       mehrere auf einmal
//   php remove.php --user=Spammer --ban    auch die IP(s) der Treffer permanent sperren
//   php remove.php --user=Spammer --dry-run   nur anzeigen, nichts löschen
//
// Im Container:
//   docker exec <name> php /app/remove.php --user=Spammer
//   docker exec <name> php /app/remove.php --ip=1.2.3.4 --ban

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/api/lib.php';

$ids    = [];
$users  = [];
$ipTargets = [];
$doBan  = false;
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--id=(.+)$/s', $arg, $m)) {
        $ids[$m[1]] = true;
    } elseif (preg_match('/^--user=(.+)$/s', $arg, $m)) {
        $users[mb_strtolower(trim($m[1]))] = true;
    } elseif (preg_match('/^--ip=(.+)$/s', $arg, $m)) {
        $ip = trim($m[1]);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            fwrite(STDERR, "Ungültige IP-Adresse: {$ip}\n");
            exit(1);
        }
        $ipTargets[$ip] = true;
    } elseif ($arg === '--ban') {
        $doBan = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } else {
        fwrite(STDERR, "Unbekannte Option: {$arg}\n");
        exit(1);
    }
}

if (!$ids && !$users && !$ipTargets) {
    fwrite(STDERR,
        "Aufruf: php remove.php --id=<ID> | --user=<Name> | --ip=<IP> [weitere ...] [--ban] [--dry-run]\n"
        . "  --id=<ID>      löscht den Kommentar oder die Antwort mit dieser ID\n"
        . "  --user=<Name>  löscht alle Kommentare und Antworten dieses Namens\n"
        . "                 (Groß-/Kleinschreibung egal)\n"
        . "  --ip=<IP>      löscht alle Kommentare und Antworten dieser IP\n"
        . "  --ban          sperrt die IP(s) der gefundenen Einträge permanent\n"
        . "  --dry-run      zeigt nur an, was gelöscht würde\n");
    exit(1);
}

// Trifft dieser Eintrag auf eine der Vorgaben zu?
$matches = function (array $entry) use ($ids, $users, $ipTargets): bool {
    if (isset($ids[$entry['id'] ?? ''])) { return true; }

    $name = mb_strtolower(trim((string) ($entry['name'] ?? '')));
    if ($name !== '' && isset($users[$name])) { return true; }

    $entryIp = (string) ($entry['ip'] ?? '');
    return $entryIp !== '' && isset($ipTargets[$entryIp]);
};

$hitComments = [];
$hitReplies  = [];
$goneIds     = [];
$foundIps    = [];

$note = function (array $entry, array &$bucket) use (&$goneIds, &$foundIps): void {
    $goneIds[] = $entry['id'] ?? '';
    if (($ip = $entry['ip'] ?? '') !== '') { $foundIps[$ip] = true; }
    $bucket[]  = sprintf(
        '%s  %-20s %-15s %s',
        $entry['id'] ?? '?',
        mb_substr((string) ($entry['name'] ?? '?'), 0, 20),
        $ip ?: '(keine IP)',
        str_replace(["\n", "\r"], ' ', mb_substr((string) ($entry['content'] ?? ''), 0, 50))
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

// Permanente IP-Sperren
if ($doBan && $foundIps) {
    foreach (array_keys($foundIps) as $ip) {
        if (!$dryRun) { sp_ban_ip($ip); }
        echo ($dryRun ? '[Probelauf] ' : '') . "IP permanent gesperrt: {$ip}\n";
    }
} elseif ($doBan && !$foundIps) {
    fwrite(STDERR, "Hinweis: --ban angegeben, aber keine IPs in den Treffern gespeichert (Legacy-Einträge?).\n");
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
