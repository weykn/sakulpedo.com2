<?php
header('Content-Type: application/json');

$commentsFile = '../data/comments.json';
$likesFile    = '../data/likes.json';
$userIp       = $_SERVER['REMOTE_ADDR'];

if (!file_exists($commentsFile)) {
    file_put_contents($commentsFile, json_encode([]));
}

$comments  = json_decode(file_get_contents($commentsFile), true) ?: [];
$likesData = file_exists($likesFile)
    ? (json_decode(file_get_contents($likesFile), true) ?: [])
    : [];

foreach ($comments as &$comment) {
    $id = $comment['id'];
    $comment['userLiked'] = isset($likesData[$id]) && in_array($userIp, $likesData[$id]);
    foreach ($comment['replies'] ?? [] as &$reply) {
        $rid = $reply['id'];
        $reply['userLiked'] = isset($likesData[$rid]) && in_array($userIp, $likesData[$rid]);
    }
    unset($reply);
}

echo json_encode(array_values($comments));
?>