<?php
require __DIR__ . '/lib.php';
header('Content-Type: application/json');

// Nur POST: ein GET würde sonst z. B. beim Prefetch einen Like auslösen.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    json_fail('Methode nicht erlaubt');
}

$commentId = trim((string)($_GET['id'] ?? ''));
$userIp    = client_ip();

if ($commentId === '') {
    json_fail('Kommentar-ID ist erforderlich');
}

$commentsFile = data_file('comments.json');
if (!is_file($commentsFile)) {
    json_fail('Kommentardatei nicht gefunden');
}

// Prüft, ob die ID überhaupt existiert (Kommentar oder Antwort), und liefert
// den Alt-Zähler für die einmalige Übernahme in likes.json.
$legacyLikes = legacy_like_count(read_json($commentsFile), $commentId);
if ($legacyLikes === null) {
    json_fail('Kommentar nicht gefunden');
}

$total = 0;
$liked = false;

$saved = with_locked_json(
    data_file('likes.json'),
    function (array &$store) use ($commentId, $userIp, $legacyLikes, &$total, &$liked) {
        $entry = like_entry($store, $commentId, $legacyLikes);

        $pos = array_search($userIp, $entry['ips'], true);
        if ($pos === false) {
            $entry['ips'][] = $userIp;   // liken
            $liked = true;
        } else {
            array_splice($entry['ips'], $pos, 1);   // unliken
            $liked = false;
        }

        $store[$commentId] = $entry;
        $total = like_total($entry);
    }
);

if (!$saved) {
    json_fail('Like konnte nicht gespeichert werden');
}

echo json_encode(['success' => true, 'likes' => $total, 'liked' => $liked]);
