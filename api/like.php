<?php
header('Content-Type: application/json');

$commentsFile = '../data/comments.json';
$likesFile    = '../data/likes.json';
$commentId    = $_GET['id'] ?? '';
$userIp       = $_SERVER['REMOTE_ADDR'];

if (empty($commentId)) {
    echo json_encode(['success' => false, 'message' => 'Kommentar-ID ist erforderlich']);
    exit;
}

if (!file_exists($commentsFile)) {
    echo json_encode(['success' => false, 'message' => 'Kommentardatei nicht gefunden']);
    exit;
}

if (!file_exists($likesFile)) {
    file_put_contents($likesFile, json_encode([]));
}

$likesData   = json_decode(file_get_contents($likesFile), true) ?: [];
$comments    = json_decode(file_get_contents($commentsFile), true) ?: [];
$alreadyLiked = isset($likesData[$commentId]) && in_array($userIp, $likesData[$commentId]);

$found    = false;
$newLikes = 0;

foreach ($comments as &$comment) {
    if ($comment['id'] !== $commentId) continue;
    $found = true;
    if ($alreadyLiked) {
        $comment['likes'] = max(0, ($comment['likes'] ?? 0) - 1);
        $likesData[$commentId] = array_values(
            array_filter($likesData[$commentId], fn($ip) => $ip !== $userIp)
        );
    } else {
        $comment['likes'] = ($comment['likes'] ?? 0) + 1;
        $likesData[$commentId][] = $userIp;
    }
    $newLikes = $comment['likes'];
    break;
}

if (!$found) {
    echo json_encode(['success' => false, 'message' => 'Kommentar nicht gefunden']);
    exit;
}

file_put_contents($commentsFile, json_encode($comments));
file_put_contents($likesFile, json_encode($likesData));

echo json_encode(['success' => true, 'likes' => $newLikes, 'liked' => !$alreadyLiked]);
?>
