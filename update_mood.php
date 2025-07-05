<?php
header('Content-Type: application/json');
require_once 'config/db.php';
require_once 'includes/session.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'User not logged in']);
    exit();
}

$userId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['mood'])) {
    echo json_encode(['success' => false, 'error' => 'Mood not provided']);
    exit();
}

$mood = $data['mood'];
$notes = isset($data['notes']) ? trim($data['notes']) : null;

// Map dashboard moods to user_mood_entries enum
$moodMap = [
    'Happy' => 'Happy',
    'Calm' => 'Neutral',
    'Anxious' => 'Sad',
    'Sad' => 'Sad',
    'Angry' => 'Angry'
];

if (!isset($moodMap[$mood])) {
    echo json_encode(['success' => false, 'error' => 'Invalid mood']);
    exit();
}

$mappedMood = $moodMap[$mood];
$intensity = 5; // Default intensity for dashboard submissions

try {
    $stmt = $pdo->prepare("INSERT INTO user_mood_entries (user_id, mood, intensity, notes, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$userId, $mappedMood, $intensity, $notes]);
    
    // Trigger mood prediction
    $pythonPath = "\"C:/Users/laptop universe/AppData/Local/Programs/Python/Python311/python.exe\"";
    $scriptPath = "\"C:/xampp/htdocs/Companion/predict_mood.py\"";
    $pythonScript = "$pythonPath $scriptPath " . escapeshellarg($userId);
    $output = shell_exec($pythonScript . " 2>&1");
    
    if ($output) {
        $prediction = json_decode($output, true);
        error_log("Mood prediction triggered after update for user_id $userId: " . print_r($prediction, true));
    } else {
        error_log("Failed to trigger mood prediction for user_id $userId");
    }
    
    echo json_encode(['success' => true, 'message' => 'Mood logged successfully']);
} catch (PDOException $e) {
    error_log("Error saving mood entry: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error', 'debug' => $e->getMessage()]);
}
?>