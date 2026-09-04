<?php
require_once __DIR__ . '/lib.php';

sp_guard();

$userIp    = sp_ip();
$comments  = sp_read_json(SP_COMMENTS_FILE);
$likesData = sp_read_json(SP_LIKES_FILE);

// Hat dieser Besucher den Eintrag schon geliket?
$likedByUser = function ($id) use ($likesData, $userIp): bool {
    $id = (string) $id;
    return $id !== ''
        && isset($likesData[$id])
        && is_array($likesData[$id])
        && in_array($userIp, $likesData[$id], true);
};

// Neu aufbauen statt im Original herumzuschreiben: so ist sicher, dass jeder
// Eintrag die Felder hat, die das Frontend erwartet, und dass die IP – das
// einzige rein serverseitige Feld – nirgends mit hinausgeht.
$out = [];

foreach ($comments as $comment) {
    if (!is_array($comment)) { continue; }

    $replies = [];
    foreach ((isset($comment['replies']) && is_array($comment['replies'])) ? $comment['replies'] : [] as $reply) {
        if (!is_array($reply)) { continue; }
        unset($reply['ip']);
        $reply['likes']     = max(0, (int) ($reply['likes'] ?? 0));
        $reply['userLiked'] = $likedByUser($reply['id'] ?? '');
        $replies[] = $reply;
    }

    unset($comment['ip']);
    $comment['likes']     = max(0, (int) ($comment['likes'] ?? 0));
    $comment['userLiked'] = $likedByUser($comment['id'] ?? '');
    $comment['replies']   = $replies;

    $out[] = $comment;
}

// Die Liste ändert sich mit jedem Beitrag – ein zwischengespeichertes
// Ergebnis würde neue Kommentare verschlucken.
if (!headers_sent()) { header('Cache-Control: no-store'); }

sp_json($out);
