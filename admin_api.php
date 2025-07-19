<?php
// Load DB configuration
require_once __DIR__ . 'config/db.php';

if (isset($_SERVER['PATH_INFO'])) {
    header('Content-Type: application/json');
    $endpoint = trim($_SERVER['PATH_INFO'], '/');

    switch ($endpoint) {
        case 'stats/active-users':
            $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM users');
            $stmt->execute();
            echo json_encode(['count' => $stmt->fetch(PDO::FETCH_ASSOC)['count'], 'percentageChange' => 12.5]);
            break;

        case 'stats/consultants':
            $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM consultants WHERE status = "active"');
            $stmt->execute();
            echo json_encode(['count' => $stmt->fetch(PDO::FETCH_ASSOC)['count'], 'percentageChange' => 5.3]);
            break;

        case 'stats/sessions-today':
            $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM user_sessions WHERE DATE(session_date) = CURDATE()');
            $stmt->execute();
            echo json_encode(['count' => $stmt->fetch(PDO::FETCH_ASSOC)['count'], 'percentageChange' => -2.1]);
            break;

        case 'stats/revenue':
            $stmt = $pdo->prepare('SELECT SUM(amount) as total FROM payments WHERE DATE(created_at) = CURDATE()');
            $stmt->execute();
            echo json_encode(['total' => $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0, 'percentageChange' => 8.7]);
            break;

        case 'sessions/recent':
            $stmt = $pdo->prepare("
                SELECT s.id as session_id, s.user_id, CONCAT(c.first_name, ' ', c.last_name) as consultant_name, 
                       TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) as duration_minutes, s.status
                FROM user_sessions s
                JOIN consultants c ON s.consultant_id = c.id
                ORDER BY s.created_at DESC
                LIMIT 4
            ");
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'charts/activity':
            $period = $_GET['period'] ?? '7days';
            $interval = match($period) {
                '30days' => '30 DAY',
                '90days' => '90 DAY',
                default => '7 DAY'
            };

            $users = getChartData($pdo, 'users', $interval);
            $consultants = getChartData($pdo, 'consultants', $interval);
            $sessions = getChartData($pdo, 'user_sessions', $interval);
            $activities = getChartData($pdo, 'user_activities', $interval);

            echo json_encode([
                'users' => $users,
                'consultants' => $consultants,
                'sessions' => $sessions,
                'activities' => $activities
            ]);
            break;

        case 'charts/user-distribution':
            $type = $_GET['type'] ?? 'gender';
            if ($type === 'month') {
                $query = "SELECT DATE_FORMAT(created_at, '%Y-%m') as label, COUNT(*) as count FROM users GROUP BY label ORDER BY label";
            } else {
                $query = "SELECT gender as label, COUNT(*) as count FROM users GROUP BY gender";
            }
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'moderation-queue':
            $posts = $pdo->prepare('SELECT id as post_id, content, status FROM community_posts WHERE status = "reported" LIMIT 1');
            $posts->execute();

            $applications = $pdo->prepare('SELECT id, CONCAT(first_name, " ", last_name) as name FROM consultants WHERE status = "pending" LIMIT 1');
            $applications->execute();

            $discussions = $pdo->prepare('SELECT discussion_id, title, status FROM discussions WHERE status = "pending" LIMIT 1');
            $discussions->execute();

            echo json_encode([
                'posts' => $posts->fetchAll(PDO::FETCH_ASSOC),
                'applications' => $applications->fetchAll(PDO::FETCH_ASSOC),
                'discussions' => $discussions->fetchAll(PDO::FETCH_ASSOC)
            ]);
            break;

        case 'announcements':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                $recipient_type = $data['recipient_type'] ?? '';
                $title = $data['title'] ?? '';
                $message = $data['message'] ?? '';

                if ($recipient_type && $title && $message) {
                    $stmt = $pdo->prepare('INSERT INTO announcements (announcement_id, recipient_type, title, message, created_at) 
                                           VALUES (:id, :recipient_type, :title, :message, NOW())');
                    $stmt->execute([
                        'id' => 'ANN-' . substr(md5(uniqid()), 0, 8),
                        'recipient_type' => $recipient_type,
                        'title' => $title,
                        'message' => $message
                    ]);
                    echo json_encode(['success' => true]);
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing required fields']);
                }
            }
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
    exit;
}

// Helper function
function getChartData($pdo, $table, $interval) {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count 
        FROM $table WHERE created_at >= CURDATE() - INTERVAL $interval 
        GROUP BY month ORDER BY month
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
