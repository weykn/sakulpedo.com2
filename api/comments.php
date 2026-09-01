<?php
require_once __DIR__ . '/lib.php';

sp_guard();

$userIp    = sp_ip();
$comments  = sp_read_json(SP_COMMENTS_FILE);
$likesData = sp_read_json(SP_LIKES_FILE);

// Über den Index laufen: eine Referenz auf `$x['replies'] ?? []` würde ins
// Leere schreiben, weil ?? einen Wert und keine Variable liefert.
foreach ($comments as $ci => $comment) {
    if (!is_array($comment)) { unset($comments[$ci]); continue; }

    $id = $comment['id'] ?? '';
    $comments[$ci]['userLiked'] = isset($likesData[$id]) && in_array($userIp, $likesData[$id], true);

    if (!isset($comment['replies']) || !is_array($comment['replies'])) {
        $comments[$ci]['replies'] = [];
        continue;
    }

    foreach ($comment['replies'] as $ri => $reply) {
        $rid = $reply['id'] ?? '';
        $comments[$ci]['replies'][$ri]['userLiked'] =
            isset($likesData[$rid]) && in_array($userIp, $likesData[$rid], true);
    }
}

sp_json(array_values($comments));
