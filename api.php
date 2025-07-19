
<?php
// Set response headers for JSON and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow all origins (adjust for production)
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Include database connection
require_once 'config/db.php';

try {
    // Verify PDO connection
    if (!isset($pdo)) {
        throw new Exception('Database connection not established');
    }
    // Enable PDO error mode for exception handling
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    // Return 500 error if database connection fails
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Determine HTTP method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($method) {
    case 'GET':
        if ($action === 'posts') {
            // Fetch approved posts with sorting and pagination
            $sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

            // Set sorting order based on query parameter
            $sortQuery = 'ORDER BY created_at DESC'; // Default: Newest first
            if ($sort === 'oldest') {
                $sortQuery = 'ORDER BY created_at ASC';
            } elseif ($sort === 'most_liked') {
                $sortQuery = 'ORDER BY like_count DESC, created_at DESC';
            }

            // Query to fetch approved posts with like and comment counts
            $query = "
                SELECT p.id, p.user_id, p.content, p.created_at, p.is_approved, p.is_anonymous,
                       (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = p.id) AS like_count,
                       (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count
                FROM community_posts p
                WHERE p.is_approved = 1
                $sortQuery
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $pdo->prepare($query);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format posts for frontend (anonymize user_id, convert dates)
            foreach ($posts as &$post) {
                $post['user_id'] = $post['is_anonymous'] ? 'Anonymous' : ($post['user_id'] ?: 'Anonymous');
                $post['created_at'] = date('c', strtotime($post['created_at']));
            }

            // Return posts as JSON
            echo json_encode($posts);
        } elseif ($action === 'comments' && isset($_GET['post_id'])) {
            // Fetch comments for a specific post
            $postId = (int)$_GET['post_id'];
            $query = "
                SELECT id, user_id, content, created_at
                FROM comments
                WHERE post_id = :post_id
                ORDER BY created_at ASC
            ";
            $stmt = $pdo->prepare($query);
            $stmt->bindValue(':post_id', $postId, PDO::PARAM_INT);
            $stmt->execute();
            $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format comments (anonymize user_id, convert dates)
            foreach ($comments as &$comment) {
                $comment['user_id'] = $comment['user_id'] ?: 'Anonymous';
                $comment['created_at'] = date('c', strtotime($comment['created_at']));
            }

            // Return comments as JSON
            echo json_encode($comments);
        } else {
            // Return 400 for invalid action
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        break;

    case 'POST':
        // Parse JSON input
        $data = json_decode(file_get_contents('php://input'), true);

        if ($action === 'posts') {
            // Submit a new post
            $userId = !empty($data['user_id']) ? filter_var($data['user_id'], FILTER_SANITIZE_STRING) : null;
            $content = filter_var($data['content'], FILTER_SANITIZE_STRING);
            $isAnonymous = isset($data['is_anonymous']) ? (bool)$data['is_anonymous'] : true;

            // Validate content
            if (empty($content) || strlen($content) > 500) {
                http_response_code(400);
                echo json_encode(['error' => 'Content is required and must be 500 characters or less']);
                exit;
            }

            // Insert new post (pending approval)
            $query = "
                INSERT INTO community_posts (user_id, content, is_anonymous, created_at, is_approved)
                VALUES (:user_id, :content, :is_anonymous, NOW(), 0)
            ";
            $stmt = $pdo->prepare($query);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
            $stmt->bindValue(':content', $content, PDO::PARAM_STR);
            $stmt->bindValue(':is_anonymous', $isAnonymous, PDO::PARAM_BOOL);

            if ($stmt->execute()) {
                // Return created post details
                $postId = $pdo->lastInsertId();
                echo json_encode([
                    'id' => $postId,
                    'user_id' => $isAnonymous ? 'Anonymous' : ($userId ?: 'Anonymous'),
                    'content' => $content,
                    'created_at' => date('c'),
                    'is_approved' => false,
                    'is_anonymous' => $isAnonymous,
                    'like_count' => 0,
                    'comment_count' => 0
                ]);
            } else {
                // Return 500 for database error
                http_response_code(500);
                echo json_encode(['error' => 'Failed to submit post']);
            }
        } elseif ($action === 'comments' && isset($data['post_id'])) {
            // Submit a new comment
            $postId = (int)$data['post_id'];
            $userId = !empty($data['user_id']) ? filter_var($data['user_id'], FILTER_SANITIZE_STRING) : null;
            $content = filter_var($data['content'], FILTER_SANITIZE_STRING);

            // Validate content
            if (empty($content)) {
                http_response_code(400);
                echo json_encode(['error' => 'Comment content is required']);
                exit;
            }

            // Insert new comment
            $query = "
                INSERT INTO comments (post_id, user_id, content, created_at)
                VALUES (:post_id, :user_id, :content, NOW())
            ";
            $stmt = $pdo->prepare($query);
            $stmt->bindValue(':post_id', $postId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
            $stmt->bindValue(':content', $content, PDO::PARAM_STR);

            if ($stmt->execute()) {
                // Return created comment details
                $commentId = $pdo->lastInsertId();
                echo json_encode([
                    'id' => $commentId,
                    'post_id' => $postId,
                    'user_id' => $userId ?: 'Anonymous',
                    'content' => $content,
                    'created_at' => date('c')
                ]);
            } else {
                // Return 500 for database error
                http_response_code(500);
                echo json_encode(['error' => 'Failed to submit comment']);
            }
        } elseif ($action === 'likes' && isset($data['post_id']) && isset($data['user_id'])) {
            // Toggle like for a post
            $postId = (int)$data['post_id'];
            $userId = filter_var($data['user_id'], FILTER_SANITIZE_STRING);

            // Check if user already liked the post
            $query = "SELECT id FROM post_likes WHERE post_id = :post_id AND user_id = :user_id";
            $stmt = $pdo->prepare($query);
            $stmt->bindValue(':post_id', $postId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
            $stmt->execute();
            $like = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($like) {
                // Unlike the post
                $query = "DELETE FROM post_likes WHERE id = :id";
                $stmt = $pdo->prepare($query);
                $stmt->bindValue(':id', $like['id'], PDO::PARAM_INT);
                $action = 'unliked';
            } else {
                // Like the post
                $query = "INSERT INTO post_likes (post_id, user_id, created_at) VALUES (:post_id, :user_id, NOW())";
                $stmt = $pdo->prepare($query);
                $stmt->bindValue(':post_id', $postId, PDO::PARAM_INT);
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
                $action = 'liked';
            }

            if ($stmt->execute()) {
                // Get updated like count
                $query = "SELECT COUNT(*) as like_count FROM post_likes WHERE post_id = :post_id";
                $stmt = $pdo->prepare($query);
                $stmt->bindValue(':post_id', $postId, PDO::PARAM_INT);
                $stmt->execute();
                $likeCount = $stmt->fetch(PDO::FETCH_ASSOC)['like_count'];

                // Return like action and updated count
                echo json_encode(['action' => $action, 'like_count' => $likeCount]);
            } else {
                // Return 500 for database error
                http_response_code(500);
                echo json_encode(['error' => 'Failed to update like']);
            }
        } else {
            // Return 400 for invalid action
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        break;

    default:
        // Return 405 for unsupported methods
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}
?>