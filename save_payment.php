<?php
session_start();
include 'config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

$data = json_decode(file_get_contents('php://input'), true);
$user_id = $data['user_id'] ?? null;
$consultant_id = $data['consultant_id'] ?? null;
$payment_method = $data['payment_method'] ?? null;
$amount = $data['amount'] ?? null;
$payment_details = $data['payment_details'] ?? [];
$is_daily_goal = $data['is_daily_goal'] ?? false;

if (!$user_id || !$consultant_id || !$payment_method || !$amount) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Missing required fields']));
}

// Generate transaction ID
$transaction_id = 'TX-' . strtoupper(substr(md5(uniqid()), 0, 10));

// Save to payments table
try {
    $sql = "INSERT INTO payments (user_id, consultant_id, payment_method, amount, transaction_id, payment_details, status, created_at) 
            VALUES (:user_id, :consultant_id, :payment_method, :amount, :transaction_id, :payment_details, 'complete', NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'user_id' => $user_id,
        'consultant_id' => $consultant_id,
        'payment_method' => $payment_method,
        'amount' => $amount,
        'transaction_id' => $transaction_id,
        'payment_details' => json_encode($payment_details)
    ]);

    // If this is a daily goal, update user_activities
    if ($is_daily_goal) {
        $sql = "SELECT id FROM mental_exercises WHERE title = 'Consultant Session' LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $exercise = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($exercise) {
            $sql = "INSERT INTO user_activities (user_id, exercise_id, exercise_title, exercise_type, score, time_taken, created_at) 
                    VALUES (:user_id, :exercise_id, 'Consultant Session', 'Daily Goal', 100, '45:00', NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'user_id' => $user_id,
                'exercise_id' => $exercise['id']
            ]);
        }
    }

    echo json_encode(['success' => true, 'transaction_id' => $transaction_id]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>