<?php
session_start();
require_once 'config/db.php';

// Redirect if consultant not logged in
if (!isset($_SESSION['consultant_id'])) {
    header("Location: consultantLogin.php");
    exit;
}

$consultant_id = $_SESSION['consultant_id'];

// Check if session ID is passed
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Invalid session ID.");
}

$booking_id = $_GET['id'];

// Fetch the booking to confirm it's pending
$stmt = $pdo->prepare("SELECT * FROM anonymous_counselling_bookings WHERE id = ? AND status = 'pending'");
$stmt->execute([$booking_id]);
$booking = $stmt->fetch();

if (!$booking) {
    die("Session not found or already handled.");
}

// Update the booking: assign consultant and mark as accepted
$update = $pdo->prepare("UPDATE anonymous_counselling_bookings SET status = 'accepted', consultant_id = ? WHERE id = ?");
$update->execute([$consultant_id, $booking_id]);

// Redirect back
header("Location: consultantDashboard.php?accepted=1");
exit;
?>
