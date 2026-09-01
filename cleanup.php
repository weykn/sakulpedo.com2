<?php
// Wird beim Start des Containers ausgeführt: entfernt zu lange oder kaputte
// Kommentare, damit alte Daten die Größenbegrenzungen nicht umgehen.

require_once __DIR__ . '/api/lib.php';

sp_boot();

$removedComments = 0;
$removedReplies  = 0;

sp_update_json(SP_COMMENTS_FILE, function (array &$comments) use (&$removedComments, &$removedReplies) {
    $clean = [];

    foreach ($comments as $comment) {
        if (!is_array($comment) || empty($comment['id']) || !isset($comment['content'])) {
            $removedComments++;
            continue;
        }
        if (!is_string($comment['content']) || mb_strlen($comment['content']) > SP_MAX_CONTENT) {
            $removedComments++;
            continue;
        }

        $comment['name'] = sp_text($comment['name'] ?? '', SP_MAX_NAME) ?: 'anonym';

        $replies = [];
        foreach ($comment['replies'] ?? [] as $reply) {
            if (!is_array($reply) || empty($reply['id']) || !isset($reply['content'])) {
                $removedReplies++;
                continue;
            }
            if (!is_string($reply['content']) || mb_strlen($reply['content']) > SP_MAX_CONTENT) {
                $removedReplies++;
                continue;
            }
            $reply['name'] = sp_text($reply['name'] ?? '', SP_MAX_NAME) ?: 'anonym';
            $replies[]     = $reply;
        }

        $removedReplies   += max(0, count($replies) - SP_MAX_REPLIES);
        $comment['replies'] = array_slice($replies, 0, SP_MAX_REPLIES);

        $clean[] = $comment;
    }

    $removedComments += max(0, count($clean) - SP_MAX_COMMENTS);
    $comments = array_slice($clean, 0, SP_MAX_COMMENTS);

    return true;
});

// Verwaiste Like-Einträge und übergroße IP-Listen entfernen.
$validIds = [];
foreach (sp_read_json(SP_COMMENTS_FILE) as $comment) {
    $validIds[$comment['id']] = true;
    foreach ($comment['replies'] ?? [] as $reply) { $validIds[$reply['id']] = true; }
}

sp_update_json(SP_LIKES_FILE, function (array &$likes) use ($validIds) {
    foreach ($likes as $id => $ips) {
        if (!isset($validIds[$id]) || !is_array($ips)) {
            unset($likes[$id]);
            continue;
        }
        $likes[$id] = array_slice(array_values(array_unique($ips)), 0, SP_MAX_LIKE_IPS);
    }
    return true;
});

echo "cleanup: {$removedComments} Kommentar(e), {$removedReplies} Antwort(en) entfernt\n";
