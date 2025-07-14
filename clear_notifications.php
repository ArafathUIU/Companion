<?php
session_start();
require 'config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
try {
    // Example: Fetch session notifications
    $stmt = $pdo->prepare("SELECT id, consultant_name, room_link, created_at FROM sessions WHERE user_id = ? AND status = 'active' AND expiry_time > NOW() ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = [];
    foreach ($sessions as $session) {
        $notifications[] = [
            'message' => "New session with Dr. " . htmlspecialchars($session['consultant_name']) . ": <a href='" . htmlspecialchars($session['room_link']) . "' class='text-primary-500 hover:underline'>Join Now</a>",
            'timestamp' => date('Y-m-d H:i:s', strtotime($session['created_at']))
        ];
    }

    // Add other notifications (e.g., reminders)
    if (!$hasRecentMood) {
        $notifications[] = [
            'message' => 'How are you feeling today? Log your mood now!',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    echo json_encode(['success' => true, 'notifications' => $notifications]);
} catch (PDOException $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to fetch notifications']);
}
?>