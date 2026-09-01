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
    'ip'      => $userIp,
];

// Datenmenge der einzelnen Antwort begrenzen
if (sp_size($newReply) > SP_MAX_ENTRY_BYTES) {
    sp_json(['success' => false, 'message' => 'Antwort ist zu groß'], 413);
}

$status = 'notfound';

sp_update_json(SP_COMMENTS_FILE, function (array &$comments) use ($commentId, $newReply, $userIp, &$status) {
    // Duplikat-Prüfung über alle Kommentare und Antworten
    if (sp_is_duplicate_in($comments, $newReply['name'], $newReply['content'])) {
        $status = 'duplicate';
        return false;
    }

    foreach ($comments as $ci => $comment) {
        if (($comment['id'] ?? '') !== $commentId) { continue; }

        $replies = isset($comment['replies']) && is_array($comment['replies']) ? $comment['replies'] : [];
        array_unshift($replies, $newReply);
        $replies = array_slice($replies, 0, SP_MAX_REPLIES);

        // Gesamte Datenmenge des Kommentars samt Antworten begrenzen
        $candidate = $comment;
        $candidate['replies'] = $replies;
        if (sp_size($candidate) > SP_MAX_COMMENT_BYTES) {
            $status = 'full';
            return false;
        }

        $comments[$ci]['replies'] = $replies;
        sp_trim_comments($comments);
        $status = 'ok';
        return true;
    }

    return false;
});

if ($status === 'duplicate') {
    sp_flag_ip_spam($userIp);
    sp_json(['success' => false, 'message' => 'Doppelter Inhalt wurde abgelehnt.'], 429);
}

if ($status === 'full') {
    sp_json([
        'success' => false,
        'message' => 'Dieser Kommentar hat die maximale Datenmenge erreicht.',
    ], 413);
}
if ($status !== 'ok') {
    sp_json(['success' => false, 'message' => 'Kommentar nicht gefunden'], 404);
}

sp_json(['success' => true, 'reply' => $newReply]);
