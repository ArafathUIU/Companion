<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit("Unauthorized");
}

$user_id = $_SESSION['user_id'];

// Expire the latest active session
$stmt = $pdo->prepare("UPDATE video_sessions SET status = 'expired', used_at = NOW() WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$user_id]);

echo json_encode(['status' => 'success']);
