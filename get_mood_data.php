<?php
header('Content-Type: application/json');
require_once 'config/db.php';
require_once 'includes/session.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'User not logged in']);
    exit();
}

$userId = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("SELECT mood, intensity, created_at FROM user_mood_entries WHERE user_id = ? ORDER BY created_at DESC LIMIT 7");
    $stmt->execute([$userId]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $moodMap = ['Happy' => 80, 'Excited' => 70, 'Neutral' => 50, 'Sad' => 30, 'Angry' => 20];
    $labels = [];
    $moods = [];
    
    foreach (array_reverse($entries) as $entry) {
        $labels[] = date('D', strtotime($entry['created_at']));
        $moods[] = $moodMap[$entry['mood']] * ($entry['intensity'] / 10);
    }
    
    echo json_encode([
        'success' => true,
        'labels' => $labels ?: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        'moods' => $moods ?: [50, 50, 50, 50, 50, 50, 50]
    ]);
} catch (PDOException $e) {
    error_log("Error fetching mood data: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error', 'debug' => $e->getMessage()]);
}
?>