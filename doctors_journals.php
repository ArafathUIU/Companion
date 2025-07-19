<?php
session_start();
require_once 'config/db.php';
require_once 'config/config.php';

// Session security
ini_set('session.cookie_secure', SESSION_COOKIE_SECURE);
ini_set('session.cookie_httponly', SESSION_COOKIE_HTTPONLY);
ini_set('session.cookie_samesite', SESSION_COOKIE_SAMESITE);

// Error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', ERROR_LOG_PATH);
error_reporting(E_ALL);

// Check consultant login
if (!isset($_SESSION['consultant_id']) || empty($_SESSION['consultant_id'])) {
    error_log("Consultant ID not set in session");
    header('Location: login.php');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = [];
$consultant = null;
$journal_entries = [];
$history = [];
$selected_user_id = null;
$filter_string = '';
$filter_tag = '';

// Test database connection
try {
    $pdo->query("SELECT 1");
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $errors[] = "Database connection error. Please contact support.";
}

// Fetch consultant details
try {
    $stmt = $pdo->prepare("
        SELECT id, CONCAT(first_name, ' ', last_name) AS name, profile_picture, specialization
        FROM consultants 
        WHERE id = ? AND status = 'active'
    ");
    $stmt->execute([$_SESSION['consultant_id']]);
    $consultant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$consultant) {
        error_log("Consultant not found or inactive for ID: " . $_SESSION['consultant_id']);
        $errors[] = "Consultant account not found or inactive. Please contact support.";
    }
} catch (PDOException $e) {
    error_log("Error fetching consultant: " . $e->getMessage());
    $errors[] = "Failed to load consultant details.";
}

// Handle filters
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filter_string = trim($_GET['filter_string'] ?? '');
    $filter_tag = trim($_GET['tag'] ?? '');
}

// Fetch journal entries with filters
try {
    $query = "
        SELECT j.id, j.user_id, j.title, j.content, j.tags, j.created_at,
               COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Unknown User') AS user_name,
               u.age, u.gender
        FROM doctor_journals j
        LEFT JOIN users u ON j.user_id = u.id
        WHERE j.consultant_id = ?
    ";
    $params = [$_SESSION['consultant_id']];
    
    if ($filter_string) {
        $query .= " AND COALESCE(CONCAT(u.first_name, ' ', u.last_name), '') LIKE ?";
        $params[] = "%{$filter_string}%";
    }
    
    if ($filter_tag) {
        $query .= " AND j.tags LIKE ?";
        $params[] = "%{$filter_tag}%";
    }
    
    $query .= " ORDER BY j.created_at DESC";
    
    error_log("Journal query: $query, Params: " . json_encode($params));
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $selected_user_id = $journal_entries[0]['user_id'] ?? null;
} catch (PDOException $e) {
    error_log("Error fetching journals: " . $e->getMessage());
    $errors[] = "Failed to load journal entries.";
}

// Fetch session history for selected user
if ($selected_user_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.user_id, s.start_time, s.status,
                   COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Unknown User') AS user_name
            FROM sessions s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.consultant_id = ? AND s.user_id = ? AND s.status = 'completed'
            ORDER BY s.start_time DESC
            LIMIT 10
        ");
        $stmt->execute([$_SESSION['consultant_id'], $selected_user_id]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching session history: " . $e->getMessage());
        $errors[] = "Failed to load session history.";
    }
}

// Fetch patients for dropdown
try {
    $stmt = $pdo->prepare("
        SELECT u.id, COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Unknown User') AS user_name
        FROM users u
        JOIN sessions s ON u.id = s.user_id
        WHERE s.consultant_id = ?
        GROUP BY u.id
        ORDER BY COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Unknown User')
    ");
    $stmt->execute([$_SESSION['consultant_id']]);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching patients: " . $e->getMessage());
    $errors[] = "Failed to load patients.";
}

// Fetch unique tags
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT tags
        FROM doctor_journals
        WHERE consultant_id = ?
    ");
    $stmt->execute([$_SESSION['consultant_id']]);
    $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $unique_tags = [];
    foreach ($tags as $tag_list) {
        if ($tag_list) {
            $tag_array = array_map('trim', explode(',', $tag_list));
            $unique_tags = array_merge($unique_tags, $tag_array);
        }
    }
    $unique_tags = array_unique($unique_tags);
} catch (PDOException $e) {
    error_log("Error fetching tags: " . $e->getMessage());
    $errors[] = "Failed to load tags.";
}

// Handle journal submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_journal'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $user_id = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $tags = trim($_POST['tags']);
        $journal_id = isset($_POST['journal_id']) ? filter_var($_POST['journal_id'], FILTER_VALIDATE_INT) : null;

        if (!$user_id || !$title || !$content) {
            $errors[] = "All required fields must be filled.";
        } else {
            try {
                if ($journal_id) {
                    $stmt = $pdo->prepare("
                        UPDATE doctor_journals 
                        SET user_id = ?, title = ?, content = ?, tags = ?, updated_at = NOW()
                        WHERE id = ? AND consultant_id = ?
                    ");
                    $stmt->execute([$user_id, $title, $content, $tags, $journal_id, $_SESSION['consultant_id']]);
                    $success[] = "Journal updated successfully.";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO doctor_journals (consultant_id, user_id, title, content, tags)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$_SESSION['consultant_id'], $user_id, $title, $content, $tags]);
                    $success[] = "Journal created successfully.";
                }
            } catch (PDOException $e) {
                error_log("Error saving journal: " . $e->getMessage());
                $errors[] = "Failed to save journal.";
            }
        }
    }
}

// Handle journal deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_journal'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $journal_id = filter_var($_POST['journal_id'], FILTER_VALIDATE_INT);
        if ($journal_id) {
            try {
                $stmt = $pdo->prepare("
                    DELETE FROM doctor_journals 
                    WHERE id = ? AND consultant_id = ?
                ");
                $stmt->execute([$journal_id, $_SESSION['consultant_id']]);
                $success[] = "Journal deleted successfully.";
            } catch (PDOException $e) {
                error_log("Error deleting journal: " . $e->getMessage());
                $errors[] = "Failed to delete journal.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="dark:bg-gray-900">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CompanionX - Doctor's Journals</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            scroll-behavior: smooth;
            background: linear-gradient(to bottom, #e0f2fe, #f3f4f6);
        }
        .ql-editor {
            min-height: 200px;
            background-color: #fff;
            border-radius: 0 0 0.5rem 0.5rem;
        }
        .ql-toolbar.ql-snow {
            background-color: #f3f4f6;
            border-radius: 0.5rem 0.5rem 0 0;
            border-color: #bfdbfe;
        }
        .sidebar {
            scrollbar-width: thin;
            scrollbar-color: #3b82f6 #f3f4f6;
            position: sticky;
            top: 0;
            height: 100vh;
            background: linear-gradient(to bottom, #f0f9ff, #fff);
        }
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar::-webkit-scrollbar-track {
            background: #f3f4f6;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background-color: #3b82f6;
            border-radius: 3px;
        }
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid #bfdbfe;
        }
        .card:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .modal {
            transition: opacity 0.3s ease;
        }
        .modal-content {
            animation: slideIn 0.3s ease;
            background: linear-gradient(to bottom, #ffffff, #f0f9ff);
        }
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .mobile-sidebar {
            transition: transform 0.3s ease;
        }
        @media (max-width: 1024px) {
            .mobile-sidebar {
                transform: translateX(-100%);
            }
            .mobile-sidebar.open {
                transform: translateX(0);
            }
            .modal-content {
                width: 90%;
                max-height: 90vh;
                overflow-y: auto;
            }
        }
    </style>
</head>
<body class="text-gray-800 dark:text-gray-100 min-h-screen">
    <!-- Navigation -->
    <nav class="bg-blue-500 text-white px-4 py-3 flex justify-between items-center shadow-md">
        <div class="flex items-center space-x-4">
            <i class="fas fa-heartbeat text-2xl text-pink-400"></i>
            <h1 class="text-xl font-bold">CompanionX</h1>
        </div>
        <div class="flex items-center space-x-4">
            <button id="mobile-menu-btn" class="lg:hidden text-2xl"><i class="fas fa-bars"></i></button>
            <button class="relative">
                <i class="fas fa-bell text-xl"></i>
                <span class="absolute -top-1 -right-1 bg-red-500 text-xs rounded-full h-4 w-4 flex items-center justify-center">0</span>
            </button>
            <div class="flex items-center space-x-2">
                <img src="<?= htmlspecialchars($consultant['profile_picture'] ?? 'https://via.placeholder.com/150') ?>" alt="Consultant" class="w-8 h-8 rounded-full">
                <span class="text-sm font-medium"><?= htmlspecialchars($consultant['name'] ?? 'Consultant Name') ?></span>
            </div>
        </div>
    </nav>

    <div class="flex max-w-7xl mx-auto">
        <!-- Sidebar -->
        <aside class="w-full lg:w-64 h-screen lg:h-auto lg:min-h-screen overflow-y-auto sidebar mobile-sidebar lg:sticky top-0">
            <div class="p-6">
                <div class="bg-blue-50 dark:bg-gray-700 rounded-lg p-4 mb-6 card">
                    <div class="flex items-center space-x-3 mb-3">
                        <div class="relative">
                            <img src="<?= htmlspecialchars($consultant['profile_picture'] ?? 'https://via.placeholder.com/150') ?>" alt="Consultant" class="w-10 h-10 rounded-full">
                            <span class="absolute bottom-0 right-0 bg-green-400 rounded-full w-3 h-3"></span>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-800 dark:text-gray-100 text-base"><?= htmlspecialchars($consultant['name'] ?? 'Consultant Name') ?></h3>
                            <p class="text-xs text-gray-600 dark:text-gray-300"><?= htmlspecialchars($consultant['specialization'] ?? 'Specialist') ?></p>
                        </div>
                    </div>
                    <div class="flex justify-center text-xs">
                        <div class="text-center">
                            <p class="font-bold text-gray-800 dark:text-gray-100"><?= rand(10, 50) ?></p>
                            <p class="text-gray-600 dark:text-gray-300">Sessions</p>
                        </div>
                    </div>
                </div>
                <nav>
                    <ul class="space-y-2">
                        <li><a href="consultantDashboard.php" class="flex items-center space-x-3 p-3 rounded-lg hover:bg-blue-100 dark:hover:bg-gray-700"><i class="fas fa-tachometer-alt w-5"></i><span>Dashboard</span></a></li>
                        <li><a href="conduct_session.php" class="flex items-center space-x-3 p-3 rounded-lg hover:bg-blue-100 dark:hover:bg-gray-700"><i class="fas fa-video w-5"></i><span>Conduct Session</span></a></li>
                        <li><a href="doctors_journals.php" class="flex items-center space-x-3 p-3 rounded-lg bg-blue-100 dark:bg-blue-900 text-blue-500 dark:text-blue-300"><i class="fas fa-book w-5"></i><span>Journals</span></a></li>
                        <li><a href="appointments.php" class="flex items-center space-x-3 p-3 rounded-lg hover:bg-blue-100 dark:hover:bg-gray-700"><i class="fas fa-calendar-alt w-5"></i><span>Appointments</span></a></li>
                        <li><a href="resources.php" class="flex items-center space-x-3 p-3 rounded-lg hover:bg-blue-100 dark:hover:bg-gray-700"><i class="fas fa-file-alt w-5"></i><span>Resources</span></a></li>
                        <li><a href="profile.php" class="flex items-center space-x-3 p-3 rounded-lg hover:bg-blue-100 dark:hover:bg-gray-700"><i class="fas fa-user w-5"></i><span>Profile</span></a></li>
                        <li><a href="logout.php" class="flex items-center space-x-3 p-3 rounded-lg hover:bg-blue-100 dark:hover:bg-gray-700"><i class="fas fa-sign-out-alt w-5"></i><span>Logout</span></a></li>
                    </ul>
                </nav>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-6">
            <?php if (!empty($errors)): ?>
                <div class="bg-red-500 text-white p-4 rounded-lg mb-6 flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
                <div class="bg-green-500 text-white p-4 rounded-lg mb-6 flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <ul class="list-disc list-inside">
                        <?php foreach ($success as $msg): ?>
                            <li><?= htmlspecialchars($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Journals Section -->
                <div class="lg:w-2/3">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md mb-6 card">
                        <div class="p-6 border-b border-blue-200 dark:border-gray-700 flex justify-between items-center">
                            <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100">Doctor's Private Journals</h2>
                            <button id="new-journal-btn" class="inline-flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-transform hover:scale-105">
                                <i class="fas fa-plus mr-2"></i> New Journal
                            </button>
                        </div>
                        <!-- Filter Section -->
                        <div class="p-6 border-b border-blue-200 dark:border-gray-700">
                            <form method="GET" class="flex flex-col sm:flex-row gap-4">
                                <input type="text" name="filter_string" value="<?= htmlspecialchars($filter_string) ?>" placeholder="Search by patient name..." class="flex-1 px-4 py-2 border border-blue-200 dark:border-gray-600 rounded-lg bg-blue-50 dark:bg-gray-700 text-gray-800 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <select name="tag" class="px-4 py-2 border border-blue-200 dark:border-gray-600 rounded-lg bg-blue-50 dark:bg-gray-700 text-gray-800 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">All Tags</option>
                                    <?php foreach ($unique_tags as $tag): ?>
                                        <option value="<?= htmlspecialchars($tag) ?>" <?= $filter_tag === $tag ? 'selected' : '' ?>><?= htmlspecialchars($tag) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-transform hover:scale-105">Filter</button>
                            </form>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($journal_entries)): ?>
                                <ul class="space-y-4">
                                    <?php foreach ($journal_entries as $entry): ?>
                                        <li class="bg-blue-50 dark:bg-gray-700 p-4 rounded-lg shadow-sm card">
                                            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100"><?= htmlspecialchars($entry['title']) ?></h3>
                                            <p class="text-sm text-gray-600 dark:text-gray-300">Patient: <?= htmlspecialchars($entry['user_name']) ?> | Created: <?= date('F j, Y, g:i a', strtotime($entry['created_at'])) ?></p>
                                            <?php if ($entry['tags']): ?>
                                                <p class="text-sm text-gray-500 dark:text-gray-400">Tags: <?= htmlspecialchars($entry['tags']) ?></p>
                                            <?php endif; ?>
                                            <div class="text-sm text-gray-700 dark:text-gray-200 mt-2 line-clamp-3"><?= strip_tags($entry['content']) ?></div>
                                            <div class="mt-3 flex space-x-2">
                                                <button class="edit-journal-btn inline-flex items-center px-3 py-1 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-transform hover:scale-105" data-id="<?= $entry['id'] ?>" data-user-id="<?= $entry['user_id'] ?>" data-title="<?= htmlspecialchars($entry['title']) ?>" data-content="<?= htmlspecialchars($entry['content']) ?>" data-tags="<?= htmlspecialchars($entry['tags']) ?>">
                                                    <i class="fas fa-edit mr-1"></i> Edit
                                                </button>
                                                <form method="POST" onsubmit="return confirm('Delete this journal?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="journal_id" value="<?= $entry['id'] ?>">
                                                    <button type="submit" name="delete_journal" class="inline-flex items-center px-3 py-1 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-transform hover:scale-105">
                                                        <i class="fas fa-trash mr-1"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-gray-600 dark:text-gray-300">No journal entries found.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Sidebar -->
                <div class="lg:w-1/3">
                    <!-- Patient Info Table -->
                    <?php if ($selected_user_id): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md mb-6 card">
                            <div class="p-6 border-b border-blue-200 dark:border-gray-700">
                                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100">Patient Information</h2>
                            </div>
                            <div class="p-6">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-blue-200 dark:border-gray-700">
                                            <th class="text-left py-2 text-gray-700 dark:text-gray-300">User ID</th>
                                            <th class="text-left py-2 text-gray-700 dark:text-gray-300">Age</th>
                                            <th class="text-left py-2 text-gray-700 dark:text-gray-300">Gender</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="py-2 text-gray-800 dark:text-gray-100"><?= htmlspecialchars($journal_entries[0]['user_id']) ?></td>
                                            <td class="py-2 text-gray-800 dark:text-gray-100"><?= htmlspecialchars($journal_entries[0]['age'] ?? 'N/A') ?></td>
                                            <td class="py-2 text-gray-800 dark:text-gray-100"><?= htmlspecialchars($journal_entries[0]['gender'] ?: 'N/A') ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Session History -->
                    <div id="history" class="bg-white dark:bg-gray-800 rounded-lg shadow-md mb-6 card">
                        <div class="p-6 border-b border-blue-200 dark:border-gray-700 flex justify-between items-center">
                            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100">Session History</h2>
                            <button id="toggle-history" class="inline-flex items-center px-3 py-1 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-transform hover:scale-105 lg:hidden">Toggle</button>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($history)): ?>
                                <ul class="space-y-2">
                                    <?php foreach ($history as $session): ?>
                                        <li class="bg-blue-50 dark:bg-gray-700 px-4 py-2 rounded shadow-sm text-gray-700 dark:text-gray-200 hover:bg-blue-100 dark:hover:bg-gray-600 transition-colors">
                                            Session with <?= htmlspecialchars($session['user_name']) ?> on <?= date('F j, Y, g:i a', strtotime($session['start_time'])) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-gray-600 dark:text-gray-300">No session history found.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal for New/Edit Journal -->
    <div id="journal-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden modal">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg modal-content relative">
            <button id="close-modal" class="absolute top-2 right-2 text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-gray-100"><i class="fas fa-times"></i></button>
            <h2 id="modal-title" class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-4"></h2>
            <form id="journal-form" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="journal_id" id="journal-id">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Patient</label>
                    <select name="user_id" id="user-id" class="w-full px-4 py-2 border border-blue-200 dark:border-gray-600 rounded-lg bg-blue-50 dark:bg-gray-700 text-gray-800 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        <option value="">Select Patient</option>
                        <?php foreach ($patients as $patient): ?>
                            <option value="<?= $patient['id'] ?>"><?= htmlspecialchars($patient['user_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Title</label>
                    <input type="text" name="title" id="journal-title" class="w-full px-4 py-2 border border-blue-200 dark:border-gray-600 rounded-lg bg-blue-50 dark:bg-gray-700 text-gray-800 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Content</label>
                    <div id="editor" class="bg-white dark:bg-gray-700 border border-blue-200 dark:border-gray-600 rounded-lg"></div>
                    <input type="hidden" name="content" id="journal-content">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tags (comma-separated)</label>
                    <input type="text" name="tags" id="journal-tags" placeholder="e.g., Diagnosis, Follow-up" class="w-full px-4 py-2 border border-blue-200 dark:border-gray-600 rounded-lg bg-blue-50 dark:bg-gray-700 text-gray-800 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex justify-end space-x-2">
                    <button type="button" id="cancel-modal" class="inline-flex items-center px-4 py-2 bg-gray-300 dark:bg-gray-600 text-gray-800 dark:text-gray-100 rounded-lg hover:bg-gray-400 dark:hover:bg-gray-500 transition-transform hover:scale-105">Cancel</button>
                    <button type="submit" name="save_journal" class="inline-flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-transform hover:scale-105">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const newJournalBtn = document.querySelector('#new-journal-btn');
            const modal = document.querySelector('#journal-modal');
            const cancelModalBtn = document.querySelector('#cancel-modal');
            const closeModalBtn = document.querySelector('#close-modal');
            const modalTitle = document.querySelector('#modal-title');
            const journalForm = document.querySelector('#journal-form');
            const journalIdInput = document.querySelector('#journal-id');
            const userIdSelect = document.querySelector('#user-id');
            const journalTitleInput = document.querySelector('#journal-title');
            const journalContentInput = document.querySelector('#journal-content');
            const journalTagsInput = document.querySelector('#journal-tags');
            const editJournalBtns = document.querySelectorAll('.edit-journal-btn');
            const toggleHistoryBtn = document.querySelector('#toggle-history');
            const historyDiv = document.querySelector('#history');
            const mobileMenuBtn = document.querySelector('#mobile-menu-btn');
            const sidebar = document.querySelector('.mobile-sidebar');

            // Initialize Quill editor
            const quill = new Quill('#editor', {
                theme: 'snow',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline'],
                        ['link', 'blockquote'],
                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                        ['clean']
                    ]
                }
            });

            // Update hidden content input on editor change
            quill.on('text-change', () => {
                journalContentInput.value = quill.root.innerHTML;
            });

            // Toggle mobile sidebar
            mobileMenuBtn.addEventListener('click', () => {
                sidebar.classList.toggle('open');
            });

            // Toggle history
            toggleHistoryBtn.addEventListener('click', () => {
                historyDiv.classList.toggle('hidden');
            });

            // Open modal for new journal
            newJournalBtn.addEventListener('click', () => {
                modalTitle.textContent = 'New Journal Entry';
                journalIdInput.value = '';
                userIdSelect.value = '';
                journalTitleInput.value = '';
                journalTagsInput.value = '';
                quill.root.innerHTML = '';
                journalContentInput.value = '';
                modal.classList.remove('hidden');
            });

            // Open modal for editing journal
            editJournalBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    modalTitle.textContent = 'Edit Journal Entry';
                    journalIdInput.value = btn.dataset.id;
                    userIdSelect.value = btn.dataset.userId;
                    journalTitleInput.value = btn.dataset.title;
                    journalTagsInput.value = btn.dataset.tags;
                    quill.root.innerHTML = btn.dataset.content;
                    journalContentInput.value = btn.dataset.content;
                    modal.classList.remove('hidden');
                });
            });

            // Close modal
            const closeModal = () => {
                modal.classList.add('hidden');
                sidebar.classList.remove('open');
            };
            cancelModalBtn.addEventListener('click', closeModal);
            closeModalBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeModal();
            });

            // Handle form submission with client-side validation
            journalForm.addEventListener('submit', (e) => {
                if (!userIdSelect.value || !journalTitleInput.value || !quill.root.innerHTML.trim()) {
                    e.preventDefault();
                    alert('Please fill all required fields.');
                    return;
                }
                journalContentInput.value = quill.root.innerHTML;
            });
        });
    </script>
</body>
</html>