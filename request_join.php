<?php
session_start();
require_once 'config/db.php';

header('Content-Type: application/json');
ini_set('display_errors', 0); // Prevent HTML output
error_reporting(E_ALL);

$response = ['success' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['circle_id'], $data['csrf_token'])) {
        throw new Exception('Missing required fields');
    }

    if ($data['csrf_token'] !== $_SESSION['csrf_token']) {
        throw new Exception('Invalid CSRF token');
    }

    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Please log in');
    }

    $circle_id = (int)$data['circle_id'];
    $user_id = (int)$_SESSION['user_id'];

    // Verify circle exists and is active
    $stmt = $pdo->prepare("SELECT max_members, status FROM circles WHERE id = ?");
    $stmt->execute([$circle_id]);
    $circle = $stmt->fetch();
    if (!$circle || $circle['status'] !== 'active') {
        throw new Exception('Circle not found or inactive');
    }

    // Check member count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as member_count
        FROM circle_members
        WHERE circle_id = ? AND status = 'approved'
    ");
    $stmt->execute([$circle_id]);
    $member_count = $stmt->fetchColumn();
    if ($member_count >= $circle['max_members']) {
        throw new Exception('Circle is full');
    }

    // Check if request already exists
    $stmt = $pdo->prepare("
        SELECT status FROM circle_members
        WHERE circle_id = ? AND user_id = ?
    ");
    $stmt->execute([$circle_id, $user_id]);
    $existing = $stmt->fetch();
    if ($existing) {
        throw new Exception('Request already sent or you are a member');
    }

    // Insert join request
    $stmt = $pdo->prepare("
        INSERT INTO circle_members (circle_id, user_id, status, requested_at)
        VALUES (?, ?, 'pending', NOW())
    ");
    $stmt->execute([$circle_id, $user_id]);
    $response['success'] = true;
    $response['message'] = 'Join request sent';

} catch (Exception $e) {
    error_log("Error in request_join: " . $e->getMessage());
    $response['message'] = $e->getMessage();
} catch (PDOException $e) {
    error_log("Database error in request_join: " . $e->getMessage());
    $response['message'] = 'Database error';
}

echo json_encode($response);
exit;
?>