<?php
session_start();
require 'config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_GET['period'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
$period = filter_var($_GET['period'], FILTER_SANITIZE_STRING);
$validPeriods = ['week', 'month', 'year'];
if (!in_array($period, $validPeriods)) {
    echo json_encode(['success' => false, 'message' => 'Invalid period']);
    exit;
}

$interval = $period === 'week' ? '7 DAY' : ($period === 'month' ? '1 MONTH' : '1 YEAR');
try {
    $stmt = $pdo->prepare("SELECT mood, intensity, notes, created_at FROM user_mood_entries WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL $interval) ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'entries' => $entries]);
} catch (PDOException $e) {
    error_log("Error fetching mood history: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to fetch mood history']);
}
?>