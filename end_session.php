<?php
session_start();
require_once 'config/db.php';
require_once 'config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$session_id = $input['session_id'] ?? null;
$csrf_token = $input['csrf_token'] ?? null;

if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (!isset($_SESSION['consultant_id']) || !$session_id) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized or missing session ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE sessions 
        SET status = 'completed'
        WHERE id = ? AND consultant_id = ?
    ");
    $stmt->execute([$session_id, $_SESSION['consultant_id']]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Error ending session: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to end session']);
}
?>