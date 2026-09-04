<?php
require_once __DIR__ . '/lib.php';

sp_guard();
sp_require_post();

$commentId = sp_text($_GET['id'] ?? '', 64);
$userIp    = sp_ip();

if ($commentId === '') {
    sp_json(['success' => false, 'message' => 'Kommentar-ID ist erforderlich'], 400);
}

// Die IP-Liste in likes.json ist die Wahrheit darüber, ob dieser Besucher den
// Eintrag mag; der Zähler im Kommentar folgt ihr nur. Der Zustand wird deshalb
// unter der Sperre umgeschaltet und nicht vorher ungesperrt gelesen – sonst
// zählen zwei schnelle Klicks zweimal hoch, aber nur einmal wieder herunter.
$toggle = function (array &$likes) use ($commentId, $userIp): bool {
    $ips = isset($likes[$commentId]) && is_array($likes[$commentId])
        ? array_values($likes[$commentId])
        : [];
    $had = in_array($userIp, $ips, true);

    if ($had) {
        $ips = array_values(array_filter($ips, fn($ip) => $ip !== $userIp));
    } else {
        $ips[] = $userIp;
    }

    if ($ips) {
        $likes[$commentId] = array_slice($ips, -SP_MAX_LIKE_IPS);
    } else {
        unset($likes[$commentId]);
    }

    return !$had; // neuer Zustand
};

$liked = null;
sp_update_json(SP_LIKES_FILE, function (array &$likes) use ($toggle, &$liked) {
    $liked = $toggle($likes);
    return true;
});

if ($liked === null) {
    sp_json(['success' => false, 'message' => 'Like konnte nicht gespeichert werden'], 500);
}

// Kommentar bzw. Antwort suchen und den Zähler um genau diesen Schritt ändern.
$delta    = $liked ? 1 : -1;
$newLikes = null;

sp_update_json(SP_COMMENTS_FILE, function (array &$comments) use ($commentId, $delta, &$newLikes) {
    $apply = function (array &$entry) use ($delta, &$newLikes) {
        $entry['likes'] = max(0, ((int) ($entry['likes'] ?? 0)) + $delta);
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

// Nichts gefunden: den eben gesetzten Like wieder zurücknehmen, damit beide
// Dateien zusammenpassen.
if ($newLikes === null) {
    sp_update_json(SP_LIKES_FILE, function (array &$likes) use ($toggle) {
        $toggle($likes);
        return true;
    });
    sp_json(['success' => false, 'message' => 'Kommentar nicht gefunden'], 404);
}

sp_json(['success' => true, 'likes' => $newLikes, 'liked' => $liked]);
