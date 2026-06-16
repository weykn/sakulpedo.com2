<?php
header('Content-Type: application/json');

// Pfad zur JSON-Datei
$commentsFile = '../data/comments.json';
$rateLimitFile = '../data/rate_limits.json'; // Rate-Limit-Datei

// IP-Adresse des Benutzers ermitteln
$userIp = $_SERVER['REMOTE_ADDR'];

// Überprüfen, ob die Datei existiert
if (!file_exists($commentsFile)) {
    echo json_encode(['success' => false, 'message' => 'Kommentardatei nicht gefunden']);
    exit;
}

// Rate-Limit-Datei erstellen, falls nicht vorhanden
if (!file_exists($rateLimitFile)) {
    file_put_contents($rateLimitFile, json_encode([]));
}

// Rate-Limit-Daten aus der Datei lesen
$rateLimits = json_decode(file_get_contents($rateLimitFile), true);

// Aktuelle Zeit
$currentTime = time();

// Rate-Limit-Prüfung (maximal 5 Kommentare/Antworten pro Stunde)
$userComments = isset($rateLimits[$userIp]) ? $rateLimits[$userIp] : [];
$recentComments = array_filter($userComments, function($timestamp) use ($currentTime) {
    return $currentTime - $timestamp < 3600; // 1 Stunde in Sekunden
});

if (count($recentComments) >= 5) {
    echo json_encode(['success' => false, 'message' => 'Rate-Limit überschritten. Sie können nur 5 Kommentare/Antworten pro Stunde hinterlassen.']);
    exit;
}

// POST-Daten empfangen
$data = json_decode(file_get_contents('php://input'), true);

// Validieren
if (empty($data['commentId']) || empty($data['content'])) {
    echo json_encode(['success' => false, 'message' => 'Kommentar-ID und Inhalt sind erforderlich']);
    exit;
}

// Kommentare aus der Datei lesen
$comments = json_decode(file_get_contents($commentsFile), true);

// Kommentar finden
$found = false;
foreach ($comments as &$comment) {
    if ($comment['id'] === $data['commentId']) {
        // Neue Antwort erstellen
        $newReply = [
            'id' => uniqid(),
            'name' => htmlspecialchars(!empty($data['name']) ? $data['name'] : 'anonym'),
            'content' => htmlspecialchars($data['content']),
            'date' => date('c')
        ];
        
        // Antworten initialisieren, falls nicht vorhanden
        if (!isset($comment['replies'])) {
            $comment['replies'] = [];
        }
        
        // Antwort hinzufügen
        array_unshift($comment['replies'], $newReply);
        $found = true;
        break;
    }
}

// Wenn der Kommentar nicht gefunden wurde
if (!$found) {
    echo json_encode(['success' => false, 'message' => 'Kommentar nicht gefunden']);
    exit;
}

// Rate-Limit aktualisieren
$recentComments[] = $currentTime;
$rateLimits[$userIp] = $recentComments;

// Kommentare und Rate-Limits in die Dateien schreiben
file_put_contents($commentsFile, json_encode($comments));
file_put_contents($rateLimitFile, json_encode($rateLimits));

// Erfolg und neue Antwort zurückgeben
echo json_encode(['success' => true, 'reply' => $newReply]);
?>