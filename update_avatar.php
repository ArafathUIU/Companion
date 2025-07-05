<?php
session_start();
header('Content-Type: application/json');

require_once 'config/db.php';
require_once 'includes/session.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check if request is AJAX
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: login.php');
    exit;
}

// Get and validate input
$data = json_decode(file_get_contents('php://input'), true);
$avatar = isset($data['avatar']) ? trim($data['avatar']) : null;

// Valid avatar codes
$validAvatars = ['f1', 'f2', 'f3', 'm1', 'm2'];

if (!$avatar || !in_array($avatar, $validAvatars)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing avatar code']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
    $success = $stmt->execute([$avatar, $_SESSION['user_id']]);

    if ($success) {
        echo json_encode(['success' => true, 'avatar' => $avatar]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update avatar']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>