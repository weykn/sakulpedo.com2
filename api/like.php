<?php
require_once __DIR__ . '/lib.php';

sp_guard();

$commentId = sp_text($_GET['id'] ?? '', 64);
$userIp    = sp_ip();

if ($commentId === '') {
    sp_json(['success' => false, 'message' => 'Kommentar-ID ist erforderlich'], 400);
}

$likesData    = sp_read_json(SP_LIKES_FILE);
$alreadyLiked = isset($likesData[$commentId]) && in_array($userIp, $likesData[$commentId], true);

$newLikes = null;

// Kommentar bzw. Antwort suchen und den Like-Zähler anpassen
sp_update_json(SP_COMMENTS_FILE, function (array &$comments) use ($commentId, $alreadyLiked, &$newLikes) {
    $apply = function (array &$entry) use ($alreadyLiked, &$newLikes) {
        $entry['likes'] = $alreadyLiked
            ? max(0, ((int) ($entry['likes'] ?? 0)) - 1)
            : ((int) ($entry['likes'] ?? 0)) + 1;
        $newLikes = $entry['likes'];
    };

    foreach ($comments as $ci => $comment) {
        if (($comment['id'] ?? '') === $commentId) {
            $apply($comments[$ci]);
            return true;
        }
        if (!isset($comment['replies']) || !is_array($comment['replies'])) { continue; }

        foreach ($comment['replies'] as $ri => $reply) {
            if (($reply['id'] ?? '') === $commentId) {
                $apply($comments[$ci]['replies'][$ri]);
                return true;
            }
        }
    }

    return false; // nicht gefunden – Datei bleibt unverändert
});

if ($newLikes === null) {
    sp_json(['success' => false, 'message' => 'Kommentar nicht gefunden'], 404);
}

// Like-Liste getrennt und ebenfalls unter Sperre pflegen
sp_update_json(SP_LIKES_FILE, function (array &$likes) use ($commentId, $userIp, $alreadyLiked) {
    $ips = isset($likes[$commentId]) && is_array($likes[$commentId]) ? $likes[$commentId] : [];

    if ($alreadyLiked) {
        $ips = array_values(array_filter($ips, fn($ip) => $ip !== $userIp));
    } elseif (!in_array($userIp, $ips, true)) {
        $ips[] = $userIp;
    }

    $likes[$commentId] = array_slice($ips, -SP_MAX_LIKE_IPS);
    return true;
});

sp_json(['success' => true, 'likes' => $newLikes, 'liked' => !$alreadyLiked]);
