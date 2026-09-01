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

// Strip server-side fields before sending to clients
$out = array_values($comments);
foreach ($out as &$c) {
    unset($c['ip']);
    if (isset($c['replies']) && is_array($c['replies'])) {
        foreach ($c['replies'] as &$r) { unset($r['ip']); }
        unset($r);
    }
}
unset($c);

sp_json($out);
