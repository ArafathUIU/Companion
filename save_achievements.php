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

if (!isset($data['achievements']) || !is_array($data['achievements'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid achievements data']);
    exit();
}

try {
    $stmt = $pdo->prepare("INSERT INTO achievements (user_id, achievement_name, description, icon, awarded_at) VALUES (:user_id, :name, :description, :icon, NOW())");
    foreach ($data['achievements'] as $ach) {
        $stmt->execute([
            'user_id' => $user_id,
            'name' => $ach['name'],
            'description' => $ach['description'],
            'icon' => $ach['icon']
        ]);
    }
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Error saving achievements: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
```