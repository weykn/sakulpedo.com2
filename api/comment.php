<?php
require __DIR__ . '/lib.php';
header('Content-Type: application/json');

// Pfad zur JSON-Datei
$commentsFile = data_file('comments.json');
$rateLimitFile = data_file('rate_limits.json'); // Neue Datei für Rate Limits

// IP-Adresse des Benutzers ermitteln
$userIp = client_ip();

// Überprüfen, ob die Datei existiert
if (!file_exists($commentsFile)) {
    file_put_contents($commentsFile, json_encode([]));
}

// Rate-Limit-Datei erstellen, falls nicht vorhanden
if (!file_exists($rateLimitFile)) {
    file_put_contents($rateLimitFile, json_encode([]));
}

// Rate-Limit-Daten aus der Datei lesen
$rateLimits = read_json($rateLimitFile);

// Aktuelle Zeit
$currentTime = time();

// Rate-Limit-Prüfung (maximal 3 Kommentare pro Stunde)
$userComments = isset($rateLimits[$userIp]) ? $rateLimits[$userIp] : [];
$recentComments = array_filter($userComments, function($timestamp) use ($currentTime) {
    return $currentTime - $timestamp < 3600; // 1 Stunde in Sekunden
});

if (count($recentComments) >= 3) {
    echo json_encode(['success' => false, 'message' => 'Rate-Limit überschritten. Sie können nur 3 Kommentare pro Stunde hinterlassen.']);
    exit;
}

// POST-Daten empfangen
$data = json_decode(file_get_contents('php://input'), true);

// Validieren
if (empty($data['content'])) {
    echo json_encode(['success' => false, 'message' => 'Inhalt ist erforderlich']);
    exit;
}

// Kommentare aus der Datei lesen
$comments = read_json($commentsFile);

// Neuen Kommentar erstellen
$newComment = [
    'id' => new_id(),
    'name' => !empty($data['name']) ? $data['name'] : 'anonym',
    'content' => $data['content'],
    'date' => date('c'),
    'likes' => 0,
    'replies' => []
];

// Kommentar am Anfang des Arrays hinzufügen
array_unshift($comments, $newComment);

// Rate-Limit aktualisieren
$recentComments[] = $currentTime;
$rateLimits[$userIp] = $recentComments;

// Kommentare und Rate-Limits in die Dateien schreiben
file_put_contents($commentsFile, json_encode($comments));
file_put_contents($rateLimitFile, json_encode($rateLimits));

// Erfolg und neuen Kommentar zurückgeben
echo json_encode(['success' => true, 'comment' => $newComment]);
?>