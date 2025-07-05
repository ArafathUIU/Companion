<?php
session_start();
header('Content-Type: application/json');

require_once 'config/db.php';
require_once 'includes/session.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized', 'notifications' => []]);
    exit;
}

try {
    // Initialize notifications array
    $notifications = [];

    // Fetch user-specific notifications (session_approved, post_approved, announcement)
    $stmt = $pdo->prepare("
        SELECT n.notification_id, n.type, n.related_id, n.status, n.created_at
        FROM notifications n
        WHERE n.user_id = ? AND n.status = 'unread'
        ORDER BY n.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $userNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($userNotifications as $notification) {
        $message = '';
        $timestamp = date('M d, Y H:i', strtotime($notification['created_at']));

        switch ($notification['type']) {
            case 'session_approved':
                // Fetch session details
                $stmt = $pdo->prepare("SELECT session_id, scheduled_at FROM sessions WHERE session_id = ? AND user_id = ?");
                $stmt->execute([$notification['related_id'], $_SESSION['user_id']]);
                $session = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($session) {
                    $message = "Your session scheduled for " . date('M d, Y H:i', strtotime($session['scheduled_at'])) . " has been approved.";
                } else {
                    $message = "A session you booked has been approved.";
                }
                break;

            case 'post_approved':
                // Fetch post details
                $stmt = $pdo->prepare("SELECT title FROM community_posts WHERE id = ? AND user_id = ?");
                $stmt->execute([$notification['related_id'], $_SESSION['user_id']]);
                $post = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($post) {
                    $message = "Your post '" . htmlspecialchars($post['title']) . "' has been approved.";
                } else {
                    $message = "A post you submitted has been approved.";
                }
                break;

            case 'announcement':
                // Fetch announcement details
                $stmt = $pdo->prepare("SELECT title, message FROM announcements WHERE announcement_id = ? AND recipient_type IN ('all', 'users')");
                $stmt->execute([$notification['related_id']]);
                $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($announcement) {
                    $message = htmlspecialchars($announcement['title']) . ": " . htmlspecialchars($announcement['message']);
                } else {
                    $message = "New announcement received.";
                }
                break;

            default:
                $message = "New notification received.";
                break;
        }

        if ($message) {
            $notifications[] = [
                'message' => $message,
                'timestamp' => $timestamp
            ];
        }
    }

    // Fetch recent announcements for 'all' or 'users' (not already in notifications)
    $stmt = $pdo->prepare("
        SELECT a.announcement_id, a.title, a.message, a.created_at
        FROM announcements a
        WHERE a.recipient_type IN ('all', 'users')
        AND a.announcement_id NOT IN (
            SELECT related_id FROM notifications WHERE user_id = ? AND type = 'announcement'
        )
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($announcements as $announcement) {
        $notifications[] = [
            'message' => htmlspecialchars($announcement['title']) . ": " . htmlspecialchars($announcement['message']),
            'timestamp' => date('M d, Y H:i', strtotime($announcement['created_at']))
        ];
    }

    // Sort notifications by timestamp (most recent first)
    usort($notifications, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });

    // Limit to 50 notifications
    $notifications = array_slice($notifications, 0, 50);

    echo json_encode([
        'success' => true,
        'notifications' => $notifications
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'notifications' => []
    ]);
}
?>