<?php
require __DIR__ . '/lib.php';
header('Content-Type: application/json');

$comments  = read_json(data_file('comments.json'));
$likeStore = read_json(data_file('likes.json'));
$userIp    = client_ip();

/**
 * Ergänzt Like-Zahl und userLiked aus likes.json. Baut bewusst ein neues
 * Array statt per Referenz zu schreiben – so kann ein Wert nicht am falschen
 * Element landen.
 */
function with_likes(array $item, array $likeStore, string $userIp): array {
    $entry = like_entry($likeStore, (string)$item['id'], (int)($item['likes'] ?? 0));

    $item['likes']     = like_total($entry);
    $item['userLiked'] = in_array($userIp, $entry['ips'], true);
    unset($item['liked']);   // Altlast, wurde nie aktualisiert

    return $item;
}

$out = [];
foreach ($comments as $comment) {
    if (!is_array($comment) || !isset($comment['id'])) {
        continue;
    }

    $replies = [];
    foreach (($comment['replies'] ?? []) as $reply) {
        if (is_array($reply) && isset($reply['id'])) {
            $replies[] = with_likes($reply, $likeStore, $userIp);
        }
    }

    $comment = with_likes($comment, $likeStore, $userIp);
    $comment['replies'] = $replies;
    $out[] = $comment;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
