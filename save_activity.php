```php
<?php
header('Content-Type: application/json');
require_once 'config/db.php';
require_once 'includes/session.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'User not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['exercise_id'], $data['exercise_title'], $data['exercise_type'], $data['score'], $data['time_taken'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit();
}

try {
    $stmt = $pdo->prepare("INSERT INTO user_activities (user_id, exercise_id, exercise_title, exercise_type, score, time_taken, created_at) 
                           VALUES (:user_id, :exercise_id, :exercise_title, :exercise_type, :score, :time_taken, NOW())");
    $stmt->execute([
        'user_id' => $user_id,
        'exercise_id' => $data['exercise_id'],
        'exercise_title' => $data['exercise_title'],
        'exercise_type' => $data['exercise_type'],
        'score' => $data['score'],
        'time_taken' => $data['time_taken']
    ]);

    // Update user_progress with the score
    $stmt = $pdo->prepare("INSERT INTO user_progress (user_id, score, created_at) 
                           VALUES (:user_id, :score, NOW())");
    $stmt->execute(['user_id' => $user_id, 'score' => $data['score']]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Error saving activity: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
```