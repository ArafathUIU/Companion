<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['avatar'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$avatar = $_POST['avatar'];
$user_id = $_SESSION['user_id'];

// Optional: Validate avatar filename whitelist
$allowed_avatars = ['avatar1.avif','avatar2.avif','avatar3.jpg','avatar4.webp','avatar5.webp'];
if (!in_array($avatar, $allowed_avatars)) {
    echo json_encode(['success' => false, 'message' => 'Invalid avatar selection']);
    exit;
}

// Save avatar in session and database if needed
$_SESSION['avatar'] = $avatar;

// Save in DB if you want, example assuming a users table:
require_once 'config/db.php';

$stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
if ($stmt->execute([$avatar, $user_id])) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'DB error']);
}
