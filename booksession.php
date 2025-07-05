<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

require 'config/db.php';

$user_id = $_SESSION['user_id'];
$consultant_id = $_POST['consultant_id'] ?? null;
$preferred_date = $_POST['preferred_date'] ?? null;
$preferred_time = $_POST['preferred_time'] ?? null;

// Basic validation
if (!$consultant_id || !$preferred_date || !$preferred_time) {
    echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
    exit();
}

// Optional: Validate date/time format, check consultant availability, etc.

try {
    $stmt = $pdo->prepare("INSERT INTO anonymous_counselling_bookings (user_id, consultant_id, preferred_date, preferred_time, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
    $stmt->execute([$user_id, $consultant_id, $preferred_date, $preferred_time]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
