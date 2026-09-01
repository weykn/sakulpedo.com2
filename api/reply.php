<?php
require_once __DIR__ . '/lib.php';

sp_guard();

$userIp = sp_ip();

// Schreib-Limit: Antworten zählen zu denselben 3 Beiträgen pro Minute
if (!sp_check_write_limit($userIp)) {
    sp_json([
        'success' => false,
        'message' => 'Rate-Limit überschritten. Es sind nur 3 Beiträge pro Minute möglich.',
    ], 429);
}

$data = sp_body();
if ($data === null) {
    sp_json(['success' => false, 'message' => 'Ungültige oder zu große Anfrage'], 400);
}

$commentId = sp_text($data['commentId'] ?? '', 64);
$content   = sp_text($data['content'] ?? '', SP_MAX_CONTENT);

if ($commentId === '' || $content === '') {
    sp_json(['success' => false, 'message' => 'Kommentar-ID und Inhalt sind erforderlich'], 400);
}
$name = sp_text($data['name'] ?? '', SP_MAX_NAME);

$newReply = [
    'id'      => uniqid('', true),
    'name'    => $name !== '' ? $name : 'anonym',
    'content' => $content,
    'date'    => date('c'),
    'likes'   => 0,
];

$found = sp_update_json(SP_COMMENTS_FILE, function (array &$comments) use ($commentId, $newReply) {
    foreach ($comments as &$comment) {
        if (($comment['id'] ?? '') === $commentId) {
            $replies = isset($comment['replies']) && is_array($comment['replies']) ? $comment['replies'] : [];
            array_unshift($replies, $newReply);
            $comment['replies'] = array_slice($replies, 0, SP_MAX_REPLIES);
            return true;
        }
    }
    unset($comment);

    return false;
});

if ($found !== true) {
    sp_json(['success' => false, 'message' => 'Kommentar nicht gefunden'], 404);
}

sp_json(['success' => true, 'reply' => $newReply]);
