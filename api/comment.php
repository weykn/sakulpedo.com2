<?php
require_once __DIR__ . '/lib.php';

sp_guard();

$userIp = sp_ip();

// Schreib-Limit: maximal 3 Kommentare pro Minute
if (!sp_check_write_limit($userIp)) {
    sp_json([
        'success' => false,
        'message' => 'Rate-Limit überschritten. Es sind nur 3 Kommentare pro Minute möglich.',
    ], 429);
}

// POST-Daten empfangen (Größe begrenzt)
$data = sp_body();
if ($data === null) {
    sp_json(['success' => false, 'message' => 'Ungültige oder zu große Anfrage'], 400);
}

// Validieren
$content = sp_text($data['content'] ?? '', SP_MAX_CONTENT);
if ($content === '') {
    sp_json(['success' => false, 'message' => 'Inhalt ist erforderlich'], 400);
}
$name = sp_text($data['name'] ?? '', SP_MAX_NAME);

$newComment = [
    'id'      => uniqid('', true),
    'name'    => $name !== '' ? $name : 'anonym',
    'content' => $content,
    'date'    => date('c'),
    'likes'   => 0,
    'liked'   => false,
    'ip'      => $userIp,
    'replies' => [],
];

// Datenmenge des Eintrags begrenzen
if (sp_size($newComment) > SP_MAX_ENTRY_BYTES) {
    sp_json(['success' => false, 'message' => 'Kommentar ist zu groß'], 413);
}

// Duplikat-Prüfung und Einfügen atomar unter der Schreibsperre
$isDuplicate = false;
$ok = sp_update_json(SP_COMMENTS_FILE, function (array &$comments) use ($newComment, &$isDuplicate) {
    if (sp_is_duplicate_in($comments, $newComment['name'], $newComment['content'])) {
        $isDuplicate = true;
        return false;
    }
    array_unshift($comments, $newComment);
    sp_trim_comments($comments);
    return true;
});

if ($isDuplicate) {
    sp_flag_ip_spam($userIp);
    sp_json(['success' => false, 'message' => 'Doppelter Inhalt wurde abgelehnt.'], 429);
}

if ($ok === false) {
    sp_json(['success' => false, 'message' => 'Kommentar konnte nicht gespeichert werden'], 500);
}

sp_json(['success' => true, 'comment' => $newComment]);
