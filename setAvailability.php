<?php
session_start();
require_once 'config/db.php';

// Check if consultant is logged in
if (!isset($_SESSION['consultant_id'])) {
    header("Location: consultantLogin.php");
    exit;
}

$consultant_id = $_SESSION['consultant_id'];
$success = "";
$error = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $available_date = $_POST['available_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];

    if ($available_date && $start_time && $end_time) {
        $stmt = $pdo->prepare("INSERT INTO consultant_availability (consultant_id, available_date, start_time, end_time, is_booked) VALUES (?, ?, ?, ?, 0)");
        if ($stmt->execute([$consultant_id, $available_date, $start_time, $end_time])) {
            $success = "Availability set successfully!";
        } else {
            $error = "Failed to save availability.";
        }
    } else {
        $error = "All fields are required.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Set Availability - CompanionX</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8 min-h-screen">
    <div class="max-w-xl mx-auto bg-white p-6 rounded-lg shadow">
        <h2 class="text-2xl font-semibold mb-4 text-center text-blue-600">Set Your Availability</h2>

        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 p-3 rounded mb-4"><?= htmlspecialchars($success) ?></div>
        <?php elseif ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block mb-1 font-medium text-gray-700">Available Date</label>
                <input type="date" name="available_date" class="w-full px-3 py-2 border rounded" required>
            </div>
            <div>
                <label class="block mb-1 font-medium text-gray-700">Start Time</label>
                <input type="time" name="start_time" class="w-full px-3 py-2 border rounded" required>
            </div>
            <div>
                <label class="block mb-1 font-medium text-gray-700">End Time</label>
                <input type="time" name="end_time" class="w-full px-3 py-2 border rounded" required>
            </div>
            <div>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Save Availability</button>
            </div>
        </form>
    </div>
</body>
</html>
