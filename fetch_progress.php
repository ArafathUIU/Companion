<?php
header('Content-Type: application/json');
require_once 'config/db.php';
require_once 'includes/session.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'User not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;

try {
    // Fetch progress data
    $stmt = $pdo->prepare("SELECT score, DATE_FORMAT(created_at, '%Y-%m-%d') AS date 
                           FROM user_progress 
                           WHERE user_id = :user_id 
                           AND created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                           ORDER BY created_at ASC");
    $stmt->execute(['user_id' => $user_id, 'days' => $days]);
    $progress = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch mood data
    $stmt = $pdo->prepare("SELECT mood, intensity, DATE_FORMAT(created_at, '%Y-%m-%d') AS date 
                           FROM user_mood_entries 
                           WHERE user_id = :user_id 
                           AND created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                           ORDER BY created_at ASC");
    $stmt->execute(['user_id' => $user_id, 'days' => $days]);
    $moods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Map moods to scores for chart
    $mood_map = ['Happy' => 80, 'Excited' => 70, 'Neutral' => 50, 'Sad' => 30, 'Angry' => 20];
    $mood_scores = [];
    foreach ($moods as $mood) {
        $mood_scores[] = [
            'date' => $mood['date'],
            'score' => $mood_map[$mood['mood']] * ($mood['intensity'] / 10)
        ];
    }

    // Fetch activity stats
    $stmt = $pdo->prepare("SELECT exercise_type, COUNT(*) AS count, AVG(score) AS avg_score 
                           FROM user_activities 
                           WHERE user_id = :user_id 
                           AND created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                           GROUP BY exercise_type");
    $stmt->execute(['user_id' => $user_id, 'days' => $days]);
    $activity_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch daily task completion
    $stmt = $pdo->prepare("SELECT COUNT(*) AS completed_today 
                           FROM user_activities 
                           WHERE user_id = :user_id 
                           AND DATE(created_at) = CURDATE()");
    $stmt->execute(['user_id' => $user_id]);
    $daily_completed = $stmt->fetch(PDO::FETCH_ASSOC)['completed_today'] >= 3;

    // Fetch user needs from answers
    $stmt = $pdo->prepare("SELECT q.question_text, ua.answer 
                           FROM user_answers ua 
                           JOIN questions q ON ua.question_id = q.id 
                           WHERE ua.user_id = :user_id 
                           AND ua.created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)");
    $stmt->execute(['user_id' => $user_id, 'days' => $days]);
    $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $needs = [];
    $needs_map = [
        'Feeling stressed or overwhelmed' => 'Stress',
        'Struggling with anxiety' => 'Anxiety',
        'Feeling low or depressed' => 'Depression',
        'Difficulty sleeping' => 'Sleep Issues',
        'Lack of motivation' => 'Low Motivation',
        'Low self-esteem' => 'Low Self-Esteem'
    ];
    foreach ($answers as $answer) {
        foreach ($needs_map as $question => $need) {
            if (strpos(strtolower($answer['question_text']), strtolower($question)) !== false && strpos(strtolower($answer['answer']), 'yes') !== false) {
                $needs[] = $need;
            }
        }
    }
    $needs = array_unique($needs);

    // Fetch achievements
    $stmt = $pdo->prepare("SELECT achievement_name, description, icon, DATE_FORMAT(awarded_at, '%Y-%m-%d') AS date 
                           FROM achievements 
                           WHERE user_id = :user_id 
                           AND awarded_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                           ORDER BY awarded_at DESC");
    $stmt->execute(['user_id' => $user_id, 'days' => $days]);
    $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'progress' => [
            'dates' => array_column($progress, 'date'),
            'scores' => array_column($progress, 'score')
        ],
        'moods' => $mood_scores,
        'activity_stats' => $activity_stats,
        'daily_completed' => $daily_completed,
        'needs' => $needs,
        'achievements' => $achievements
    ]);
} catch (PDOException $e) {
    error_log("Error fetching progress: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>