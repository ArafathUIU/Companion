<?php
ob_start(); // Start output buffering
session_start();
require_once 'config/db.php'; // PDO connection
require_once 'config/config.php'; // Configuration constants

// Apply session security settings
ini_set('session.cookie_secure', SESSION_COOKIE_SECURE);
ini_set('session.cookie_httponly', SESSION_COOKIE_HTTPONLY);
ini_set('session.cookie_samesite', SESSION_COOKIE_SAMESITE);

// Error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', ERROR_LOG_PATH);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables
$success = null;
$errors = [];
$user_id = $_SESSION['user_id'];
$posts_per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $posts_per_page;
$persisted_form = ['title' => '', 'content' => '', 'anonymous_choice' => 'anonymous'];

// Check database connection and table existence
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query("SHOW TABLES LIKE 'community_posts'");
    if ($stmt->rowCount() === 0) {
        throw new PDOException("Table 'community_posts' does not exist.");
    }
} catch (Exception $e) {
    error_log("DB connection or table check error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    $errors[] = "Cannot connect to the database or table missing. Please try again or contact support with error code: DB-" . time();
}

// Handle post submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_post'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token. Please try again.";
        $persisted_form = [
            'title' => trim($_POST['title'] ?? ''),
            'content' => trim($_POST['content'] ?? ''),
            'anonymous_choice' => $_POST['anonymous_choice'] ?? 'anonymous'
        ];
    } else {
        $anonymous_choice = $_POST['anonymous_choice'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');

        // Validate inputs
        if (empty($anonymous_choice)) {
            $errors[] = "Please select an anonymity option.";
        } elseif (empty($title) || strlen($title) > 255) {
            $errors[] = "Title is required and must be 255 characters or less.";
        } elseif (empty($content) || strlen($content) > 500) {
            $errors[] = "Content is required and must be 500 characters or less.";
        } else {
            try {
                $is_anonymous = ($anonymous_choice === 'anonymous' || $user_id === 0) ? 1 : 0;
                $post_user_id = $is_anonymous ? 0 : $user_id;

                // Verify user exists if not anonymous
                if (!$is_anonymous) {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    if (!$stmt->fetch()) {
                        $errors[] = "Invalid user ID.";
                    }
                }

                if (empty($errors)) {
                    $stmt = $pdo->prepare("INSERT INTO community_posts (user_id, title, content, is_anonymous, created_at, is_approved) VALUES (?, ?, ?, ?, NOW(), 0)");
                    $stmt->execute([$post_user_id, $title, $content, $is_anonymous]);
                    $success = "Post submitted for admin approval. You'll see it here once approved.";
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate CSRF token
                } else {
                    $persisted_form = [
                        'title' => $title,
                        'content' => $content,
                        'anonymous_choice' => $anonymous_choice
                    ];
                }
            } catch (PDOException $e) {
                error_log("Post submission error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
                $errors[] = "Failed to submit post. Please try again or contact support with error code: DB-" . time();
                $persisted_form = [
                    'title' => $title,
                    'content' => $content,
                    'anonymous_choice' => $anonymous_choice
                ];
            }
        }
    }
}

// Fetch approved and pending posts
$posts = [];
$pending_posts = [];
try {
    // Fetch approved posts with pagination
    $stmt = $pdo->prepare("SELECT id, user_id, title, content, created_at, is_anonymous FROM community_posts WHERE is_approved = 1 ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $posts_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sanitize post data
    foreach ($posts as &$post) {
        $post['title'] = htmlspecialchars(mb_convert_encoding($post['title'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $post['content'] = htmlspecialchars(mb_convert_encoding($post['content'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8');
    }
    unset($post);

    // Fetch user's pending posts
    $stmt = $pdo->prepare("SELECT id, user_id, title, content, created_at, is_anonymous FROM community_posts WHERE user_id = :user_id AND is_approved = 0 ORDER BY created_at DESC");
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $pending_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sanitize pending post data
    foreach ($pending_posts as &$post) {
        $post['title'] = htmlspecialchars(mb_convert_encoding($post['title'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $post['content'] = htmlspecialchars(mb_convert_encoding($post['content'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8');
    }
    unset($post);
} catch (PDOException $e) {
    error_log("Post fetch error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    $errors[] = "Failed to load posts. Please try again or contact support with error code: DB-" . time();
}

// Check if more posts are available
$has_more_posts = false;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM community_posts WHERE is_approved = 1");
    $stmt->execute();
    $total_posts = $stmt->fetchColumn();
    $has_more_posts = $total_posts > ($page * $posts_per_page);
} catch (PDOException $e) {
    error_log("Post count error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
}

// Clean output buffer
ob_end_clean();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Share and view anonymous posts on CompanionX's Wall of Whispers.">
    <title>CompanionX - Wall of Whispers</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 50%, #80deea 100%);
            position: relative;
            overflow-x: hidden;
        }
        .post-card {
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .post-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        .typewriter {
            overflow: hidden;
            border-right: 0.15em solid #00bcd4;
            white-space: nowrap;
            margin: 0 auto;
            letter-spacing: 0.15em;
            animation: typing 3.5s steps(40, end), blink-caret 0.75s step-end infinite;
        }
        @keyframes typing {
            from { width: 0 }
            to { width: 100% }
        }
        @keyframes blink-caret {
            from, to { border-color: transparent }
            50% { border-color: #00bcd4; }
        }
        .fade-in {
            animation: fadeIn 1s ease-in;
        }
        @keyframes fadeIn {
            0% { opacity: 0; transform: translateY(10px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .error-alert, .success-alert {
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 8px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #e5e7eb;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #4dd0e1;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #26c6da;
        }
        .petal {
            position: absolute;
            background: url('https://cdn.pixabay.com/photo/2016/04/15/04/02/flower-1330062_1280.png') no-repeat center;
            background-size: contain;
            width: 20px;
            height: 20px;
            opacity: 0.5;
            animation: float 15s infinite;
            z-index: 0;
        }
        @keyframes float {
            0% { transform: translateY(0) rotate(0deg); opacity: 0.5; }
            50% { opacity: 0.8; }
            100% { transform: translateY(100vh) rotate(360deg); opacity: 0.2; }
        }
        .btn-primary {
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .btn-primary:hover {
            transform: scale(1.05);
            background: #26c6da;
        }
        .btn-secondary {
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .btn-secondary:hover {
            transform: scale(1.05);
            background: #e0f7fa;
        }
        #title-suggestions div {
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        #title-suggestions div:hover, #title-suggestions div.focused {
            background: #e0f7fa;
        }
        .loading-spinner {
            display: none;
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }
        .loading-spinner.active {
            display: block;
        }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#e0f7fa',
                            100: '#b2ebf2',
                            200: '#80deea',
                            300: '#4dd0e1',
                            400: '#26c6da',
                            500: '#00bcd4',
                            600: '#00acc1',
                            700: '#0097a7',
                            800: '#00838f',
                            900: '#006064',
                        },
                        secondary: {
                            50: '#e8f5e9',
                            100: '#c8e6c9',
                            200: '#a5d6a7',
                            300: '#81c784',
                            400: '#66bb6a',
                            500: '#4caf50',
                            600: '#43a047',
                            700: '#388e3c',
                            800: '#2e7d32',
                            900: '#1b5e20',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen">
    <!-- Floating Petals -->
    <script>
        function createPetals() {
            const petalCount = 5;
            for (let i = 0; i < petalCount; i++) {
                const petal = document.createElement('div');
                petal.className = 'petal';
                petal.style.left = Math.random() * 100 + 'vw';
                petal.style.animationDelay = Math.random() * 5 + 's';
                petal.addEventListener('animationend', () => petal.remove());
                document.body.appendChild(petal);
            }
        }
        document.addEventListener('DOMContentLoaded', createPetals);
        setInterval(createPetals, 5000);
    </script>

    <!-- Header -->
    <header class="bg-white shadow-sm fixed top-0 left-0 right-0 z-50">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-primary-500 rounded-full flex items-center justify-center text-white mr-3">
                    <i class="fas fa-brain text-2xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-gray-800">CompanionX</h1>
            </div>
            <nav class="flex items-center space-x-4">
                <a href="mood_journal.php" class="px-4 py-2 text-gray-600 hover:text-primary-500 transition rounded-lg hover:bg-primary-50">
                    <i class="fas fa-heart mr-2"></i> Mood Journal
                </a>
                <a href="consultants_near_me.php" class="px-4 py-2 text-gray-600 hover:text-primary-500 transition rounded-lg hover:bg-primary-50">
                    <i class="fas fa-user-md mr-2"></i> Consultants
                </a>
                <a href="profile.php" class="px-4 py-2 text-gray-600 hover:text-primary-500 transition rounded-lg hover:bg-primary-50">
                    <i class="fas fa-user mr-2"></i> Profile
                </a>
                <a href="logout.php" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition">
                    <i class="fas fa-sign-out-alt mr-2"></i> Logout
                </a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto px-4 pt-24 pb-12">
        <!-- Hero Section -->
        <section class="text-center mb-12">
            <h2 class="text-4xl font-bold text-gray-800 mb-4 flex items-center justify-center">
                Wall of Whispers
                <i class="fas fa-user-secret text-primary-600 ml-2"></i>
            </h2>
            <p class="text-xl text-gray-600 max-w-2xl mx-auto">Share your thoughts anonymously and connect with others in a safe space.</p>
            <div class="mt-6">
                <span class="typewriter text-primary-600 font-mono text-lg">Your secrets are safe with us...</span>
            </div>
        </section>

        <!-- Success/Error Messages -->
        <?php if ($success): ?>
            <div id="success-alert" class="success-alert bg-green-50 text-green-700 p-4 rounded-xl mb-6 flex items-center justify-between max-w-2xl mx-auto">
                <div>
                    <span class="text-sm"><i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="flex space-x-2">
                    <button id="dismiss-success" class="text-green-600 hover:text-green-800 transition" aria-label="Dismiss success">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div id="error-alert" class="error-alert bg-red-50 text-red-700 p-4 rounded-xl mb-6 flex items-center justify-between max-w-2xl mx-auto">
                <div>
                    <ul class="list-disc list-inside space-y-2">
                        <?php foreach ($errors as $error): ?>
                            <li class="text-sm"><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="flex space-x-2">
                    <button id="retry-btn" class="text-primary-600 hover:text-primary-800 transition" aria-label="Retry">
                        <i class="fas fa-redo-alt"></i> Retry
                    </button>
                    <button id="dismiss-error" class="text-red-600 hover:text-red-800 transition" aria-label="Dismiss error">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Post Form -->
        <section class="bg-white rounded-2xl shadow-lg p-6 mb-12">
            <h3 class="text-2xl font-semibold text-gray-800 mb-4 flex items-center">
                Whisper Something
                <i class="fas fa-pen text-primary-600 ml-2"></i>
            </h3>
            <form id="postForm" method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <!-- Anonymity Choice -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Choose Anonymity</label>
                    <div class="flex items-center space-x-4">
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" name="anonymous_choice" value="show_id" class="mr-2 focus:ring-primary-500" <?= $persisted_form['anonymous_choice'] === 'show_id' ? 'checked' : '' ?> aria-label="Show your user ID">
                            <span class="text-sm">Show Your User ID</span>
                        </label>
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" name="anonymous_choice" value="anonymous" class="mr-2 focus:ring-primary-500" <?= $persisted_form['anonymous_choice'] === 'anonymous' ? 'checked' : '' ?> aria-label="Remain anonymous">
                            <span class="text-sm">Remain Anonymous</span>
                        </label>
                    </div>
                    <div id="userIdContainer" class="mt-2 <?= $persisted_form['anonymous_choice'] === 'show_id' ? '' : 'hidden' ?>">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Your User ID</label>
                        <p class="px-4 py-2 bg-primary-50 border border-primary-200 rounded-lg text-primary-700">
                            <?= htmlspecialchars("USERID#" . $user_id, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                </div>
                <!-- Post Title -->
                <div class="relative">
                    <label for="postTitle" class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                    <div class="relative">
                        <i class="fas fa-pen absolute left-3 top-3 text-gray-500"></i>
                        <input type="text" id="postTitle" name="title" value="<?= htmlspecialchars($persisted_form['title'], ENT_QUOTES, 'UTF-8') ?>" class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent transition" placeholder="Title (max 255 chars)" maxlength="255" required aria-label="Post title" aria-autocomplete="list" aria-controls="title-suggestions">
                        <div id="title-suggestions" class="hidden custom-scrollbar absolute top-full left-0 right-0 bg-white border border-gray-300 rounded-lg mt-1 max-h-40 overflow-y-auto z-10" role="listbox"></div>
                    </div>
                </div>
                <!-- Post Content -->
                <div>
                    <label for="postContent" class="block text-sm font-medium text-gray-700 mb-1">Your Whisper</label>
                    <textarea id="postContent" name="content" rows="5" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent transition custom-scrollbar" placeholder="What's on your mind? (Max 500 chars)" maxlength="500" required aria-label="Post content"><?= htmlspecialchars($persisted_form['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div class="text-right text-sm text-gray-500 mt-1">
                        <span id="charCount"><?= strlen($persisted_form['content']) ?></span>/500
                    </div>
                </div>
                <div class="flex justify-between items-center">
                    <div class="text-sm text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>
                        Posts are reviewed by admin within 24 hours.
                    </div>
                    <div class="flex space-x-4">
                        <button type="submit" name="submit_post" id="submit-btn" class="btn-primary bg-primary-500 text-white py-3 px-6 rounded-lg hover:bg-primary-600 transition flex items-center" disabled data-last-submit="0">
                            <i class="fas fa-paper-plane mr-2"></i> Post Whisper
                        </button>
                        <button type="button" id="clear-form" class="btn-secondary bg-gray-100 text-gray-700 py-3 px-6 rounded-lg hover:bg-gray-200 transition flex items-center">
                            <i class="fas fa-eraser mr-2"></i> Clear Form
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <!-- Recent Whispers -->
        <section>
            <h3 class="text-2xl font-semibold text-gray-800 mb-6 flex items-center">
                Recent Whispers
                <i class="fas fa-comment-alt text-primary-600 ml-2"></i>
            </h3>
            <div id="postsContainer" class="grid grid-cols-1 md:grid-cols-2 gap-6 relative">
                <div id="posts-loading" class="hidden absolute inset-0 bg-gray-100 bg-opacity-75 flex items-center justify-center rounded-xl">
                    <i class="fas fa-spinner fa-spin text-primary-600 text-2xl"></i>
                </div>
                <?php if (!empty($pending_posts)): ?>
                    <?php foreach ($pending_posts as $index => $post): ?>
                        <div class="post-card bg-white rounded-xl p-6 border-l-4 border-yellow-400 fade-in" style="animation-delay: <?= $index * 0.1 ?>s">
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex items-center">
                                    <div class="bg-yellow-100 text-yellow-800 rounded-full w-10 h-10 flex items-center justify-center">
                                        <i class="fas fa-user-secret"></i>
                                    </div>
                                    <div class="ml-3">
                                        <p class="font-medium text-gray-700"><?= htmlspecialchars($post['is_anonymous'] || $post['user_id'] == 0 ? 'You (Pending)' : "USERID#" . $post['user_id'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <p class="text-xs text-gray-500"><?= date('M j, Y, g:i a', strtotime($post['created_at'])) ?></p>
                                    </div>
                                </div>
                            </div>
                            <h4 class="text-lg font-semibold text-gray-800 mb-2"><?= $post['title'] ?></h4>
                            <p class="text-gray-700 mb-4"><?= $post['content'] ?></p>
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-yellow-600 font-medium">Pending Approval</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($posts)): ?>
                    <?php foreach ($posts as $index => $post): ?>
                        <div class="post-card bg-white rounded-xl p-6 fade-in" style="animation-delay: <?= ($index + count($pending_posts)) * 0.1 ?>s">
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex items-center">
                                    <div class="bg-primary-100 text-primary-800 rounded-full w-10 h-10 flex items-center justify-center">
                                        <i class="fas fa-user-secret"></i>
                                    </div>
                                    <div class="ml-3">
                                        <p class="font-medium text-gray-700"><?= htmlspecialchars($post['is_anonymous'] || $post['user_id'] == 0 ? 'Anonymous' : "USERID#" . $post['user_id'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <p class="text-xs text-gray-500"><?= date('M j, Y, g:i a', strtotime($post['created_at'])) ?></p>
                                    </div>
                                </div>
                            </div>
                            <h4 class="text-lg font-semibold text-gray-800 mb-2"><?= $post['title'] ?></h4>
                            <p class="text-gray-700 mb-4"><?= $post['content'] ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-gray-600 text-center col-span-full">No whispers yet.</p>
                <?php endif; ?>
            </div>
            <?php if ($has_more_posts): ?>
                <div class="text-center mt-6">
                    <a href="?page=<?= $page + 1 ?>" id="load-more" class="btn-primary bg-primary-500 text-white py-3 px-6 rounded-lg hover:bg-primary-600 transition">Load More</a>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 py-8 mt-12">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-6 md:mb-0">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-primary-500 rounded-full flex items-center justify-center text-white mr-3">
                            <i class="fas fa-brain"></i>
                        </div>
                        <span class="text-xl font-bold text-gray-800">CompanionX</span>
                    </div>
                    <p class="text-gray-500 mt-2">Your trusted mental health companion</p>
                </div>
                <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-8">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Resources</h3>
                        <ul class="space-y-2">
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Articles</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Self-Help Guides</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Therapist Directory</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Company</h3>
                        <ul class="space-y-2">
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">About</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Privacy</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Terms</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Connect</h3>
                        <div class="flex space-x-4">
                            <a href="#" class="text-gray-500 hover:text-primary-500" aria-label="Twitter">
                                <i class="fab fa-twitter"></i>
                            </a>
                            <a href="#" class="text-gray-500 hover:text-primary-500" aria-label="Instagram">
                                <i class="fab fa-instagram"></i>
                            </a>
                            <a href="#" class="text-gray-500 hover:text-primary-500" aria-label="Facebook">
                                <i class="fab fa-facebook"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-8 pt-8 border-t border-gray-200 text-center text-gray-500 text-sm">
                © <?php echo date('Y'); ?> CompanionX. All rights reserved. This platform does not provide medical advice.
            </div>
        </div>
    </footer>

    <script>
        // Initialize
        const postForm = document.getElementById('postForm');
        const postContent = document.getElementById('postContent');
        const charCount = document.getElementById('charCount');
        const radioButtons = document.querySelectorAll('input[name="anonymous_choice"]');
        const userIdContainer = document.getElementById('userIdContainer');
        const submitBtn = document.getElementById('submit-btn');
        const titleInput = document.getElementById('postTitle');
        const titleSuggestions = document.getElementById('title-suggestions');
        const dismissSuccess = document.getElementById('dismiss-success');
        const dismissError = document.getElementById('dismiss-error');
        const retryBtn = document.getElementById('retry-btn');
        const postsContainer = document.getElementById('postsContainer');
        const loadMoreBtn = document.getElementById('load-more');

        // Rate limiting
        const SUBMIT_COOLDOWN = 30000; // 30 seconds
        function updateSubmitButton() {
            const lastSubmit = parseInt(submitBtn.dataset.lastSubmit);
            const now = Date.now();
            if (lastSubmit && now - lastSubmit < SUBMIT_COOLDOWN) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i> Wait ${Math.ceil((SUBMIT_COOLDOWN - (now - lastSubmit)) / 1000)}s`;
                setTimeout(updateSubmitButton, 1000);
            } else {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i> Post Whisper';
            }
        }
        updateSubmitButton();

        // Character count
        postContent.addEventListener('input', () => {
            charCount.textContent = postContent.value.length;
        });

        // Anonymity toggle
        radioButtons.forEach(radio => {
            radio.addEventListener('change', () => {
                userIdContainer.classList.toggle('hidden', radio.value !== 'show_id');
            });
        });

        // Form submission
        postForm.addEventListener('submit', (e) => {
            const title = titleInput.value.trim();
            const content = postContent.value.trim();
            const anonymity = document.querySelector('input[name="anonymous_choice"]:checked');
            if (!title || !content || !anonymity) {
                e.preventDefault();
                const alert = document.createElement('div');
                alert.className = 'error-alert bg-red-50 text-red-700 p-4 rounded-xl mb-6 flex items-center justify-between max-w-2xl mx-auto';
                alert.innerHTML = `
                    <span><i class="fas fa-exclamation-circle mr-2"></i>Please fill in all required fields.</span>
                    <button class="dismiss-alert text-red-600 hover:text-red-800 transition" aria-label="Dismiss alert">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                postForm.parentNode.insertBefore(alert, postForm);
                alert.querySelector('.dismiss-alert').addEventListener('click', () => alert.remove());
                setTimeout(() => alert.remove(), 5000);
                return;
            }
            submitBtn.dataset.lastSubmit = Date.now();
            updateSubmitButton();

            // Store title in localStorage
            const storedTitles = JSON.parse(localStorage.getItem('postTitles') || '[]');
            if (title && !storedTitles.includes(title)) {
                storedTitles.unshift(title);
                if (storedTitles.length > 10) storedTitles.pop();
                localStorage.setItem('postTitles', JSON.stringify(storedTitles));
            }
        });

        // Clear form
        document.getElementById('clear-form').addEventListener('click', () => {
            postForm.reset();
            charCount.textContent = '0';
            userIdContainer.classList.add('hidden');
            document.querySelector('input[name="anonymous_choice"][value="anonymous"]').checked = true;
            titleSuggestions.classList.add('hidden');
        });

        // Dismiss alerts
        if (dismissSuccess) {
            dismissSuccess.addEventListener('click', () => {
                document.getElementById('success-alert').remove();
            });
        }
        if (dismissError) {
            dismissError.addEventListener('click', () => {
                document.getElementById('error-alert').remove();
            });
        }
        if (retryBtn) {
            retryBtn.addEventListener('click', () => {
                document.getElementById('posts-loading').classList.remove('hidden');
                window.location.reload();
            });
        }

        // Title autocomplete with keyboard navigation
        const storedTitles = JSON.parse(localStorage.getItem('postTitles') || '[]');
        let debounceTimeout;
        let focusedSuggestionIndex = -1;
        titleInput.addEventListener('input', () => {
            clearTimeout(debounceTimeout);
            debounceTimeout = setTimeout(() => {
                const query = titleInput.value.trim().toLowerCase();
                if (query.length < 2) {
                    titleSuggestions.classList.add('hidden');
                    titleSuggestions.innerHTML = '';
                    focusedSuggestionIndex = -1;
                    return;
                }
                const suggestions = storedTitles.filter(title => title.toLowerCase().includes(query)).slice(0, 5);
                titleSuggestions.innerHTML = suggestions.map((title, index) => `
                    <div class="p-2 cursor-pointer hover:bg-primary-100 rounded" role="option" aria-selected="${index === focusedSuggestionIndex}">
                        ${title}
                    </div>
                `).join('');
                titleSuggestions.classList.toggle('hidden', suggestions.length === 0);
                titleSuggestions.querySelectorAll('div').forEach((div, index) => {
                    div.addEventListener('click', () => {
                        titleInput.value = div.textContent;
                        titleSuggestions.classList.add('hidden');
                        focusedSuggestionIndex = -1;
                    });
                });
            }, 300);
        });

        titleInput.addEventListener('keydown', (e) => {
            const suggestions = titleSuggestions.querySelectorAll('div');
            if (suggestions.length === 0) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                focusedSuggestionIndex = Math.min(focusedSuggestionIndex + 1, suggestions.length - 1);
                updateSuggestionFocus(suggestions);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                focusedSuggestionIndex = Math.max(focusedSuggestionIndex - 1, -1);
                updateSuggestionFocus(suggestions);
            } else if (e.key === 'Enter' && focusedSuggestionIndex >= 0) {
                e.preventDefault();
                suggestions[focusedSuggestionIndex].click();
            } else if (e.key === 'Escape') {
                titleSuggestions.classList.add('hidden');
                focusedSuggestionIndex = -1;
            }
        });

        function updateSuggestionFocus(suggestions) {
            suggestions.forEach((s, i) => {
                s.classList.toggle('focused', i === focusedSuggestionIndex);
                s.setAttribute('aria-selected', i === focusedSuggestionIndex ? 'true' : 'false');
            });
            if (focusedSuggestionIndex >= 0) {
                suggestions[focusedSuggestionIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        document.addEventListener('click', (e) => {
            if (!titleInput.contains(e.target) && !titleSuggestions.contains(e.target)) {
                titleSuggestions.classList.add('hidden');
                focusedSuggestionIndex = -1;
            }
        });

        // Keyboard shortcuts
        postForm.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !e.shiftKey && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
                postForm.dispatchEvent(new Event('submit'));
            }
        });

        // Error handling
        window.addEventListener('error', (event) => {
            console.error('JavaScript error:', event);
            const alert = document.createElement('div');
            alert.className = 'error-alert bg-red-50 text-red-700 p-4 rounded-xl mb-6 flex items-center justify-between max-w-2xl mx-auto';
            alert.innerHTML = `
                <span><i class="fas fa-exclamation-circle mr-2"></i>An error occurred. Please try again or contact support.</span>
                <button class="dismiss-alert text-red-600 hover:text-red-800 transition" aria-label="Dismiss alert">
                    <i class="fas fa-times"></i>
                </button>
            `;
            document.querySelector('main').prepend(alert);
            alert.querySelector('.dismiss-alert').addEventListener('click', () => alert.remove());
            setTimeout(() => alert.remove(), 5000);
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
?>