<?php
session_start();
require_once 'config/db.php';



// Check if admin is logged in (aligned with example’s implicit check, assuming no explicit admin_id)
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminLogin.php");
    exit;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = "";
$errors = [];

// Fetch all consultants
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM consultants ORDER BY first_name ASC");
    $stmt->execute();
    $consultants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "Error . consultants: " . htmlspecialchars($e->getMessage());
}

// Fetch pending users join requests
try {
    $stmt = $pdo->prepare("
        SELECT 
            cjr.id AS request_id,
            cjr.user_id,
            cjr.circle_id,
            cjr.request_date,
            u.first_name AS user_first_name,
            u.last_name AS user_last_name,
            u.email,
            c.title AS circle_title,
            c.lead_consultant_id,
            con.first_name AS consultant_first_name,
            con.last_name AS consultant_last_name
        FROM circle_join_requests cjr
        JOIN users u ON cjr.user_id = u.id
        JOIN circles c ON cjr.circle_id = c.id
        JOIN consultants con ON c.lead_consultant_id = con.id
        WHERE cjr.status = 'pending'
        ORDER BY cjr.request_date DESC
    ");
    $stmt->execute();
    $pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "Error fetching pending requests: " . htmlspecialchars($e->getMessage());
}

// Fetch sidebar badge data (like example)
try {
    $user_count_stmt = $pdo->query("SELECT COUNT(*) AS total_users FROM users");
    $total_users = $user_count_stmt->fetch()['total_users'];

    $post_approval_stmt = $pdo->query("SELECT COUNT(*) AS pending_posts FROM community_posts WHERE is_approved = 0");
    $pending_posts = $post_approval_stmt->fetch()['pending_posts'];
} catch (PDOException $e) {
    $errors[] = "Error fetching badge data: " . htmlspecialchars($e->getMessage());
}

// Handle circle creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_circle') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
        $consultant_id = filter_input(INPUT_POST, 'lead_consultant_id', FILTER_VALIDATE_INT);
        $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
        $meeting_day = filter_input(INPUT_POST, 'meeting_day', FILTER_SANITIZE_STRING);
        $meeting_time = filter_input(INPUT_POST, 'meeting_time', FILTER_SANITIZE_STRING);
        $max_members = filter_input(INPUT_POST, 'max_members', FILTER_VALIDATE_INT);

        // Validate inputs
        if (empty($title)) {
            $errors[] = "Circle title is required.";
        }
        if (strlen($title) > 100) {
            $errors[] = "Circle title must be less than 100 characters.";
        }
        if (empty($description)) {
            $errors[] = "Description is required.";
        }
        if (!$consultant_id) {
            $errors[] = "Please select a valid consultant.";
        }
        if (empty($category)) {
            $errors[] = "Category is required.";
        }
        if (empty($meeting_day)) {
            $errors[] = "Meeting day is required.";
        }
        if (empty($meeting_time) || !DateTime::createFromFormat('H:i', $meeting_time)) {
            $errors[] = "Invalid time format.";
        }
        if (!$max_members || $max_members < 5 || $max_members > 20) {
            $errors[] = "Maximum members must be between 5 and 20.";
        }

        // If no errors, insert circle
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO circles (title, description, lead_consultant_id, category, meeting_day, meeting_time, max_members, status) 
                    VALUES (:title, :description, :consultant_id, :category, :meeting_day, :meeting_time, :max_members, 'active')
                ");
                $stmt->execute([
                    ':title' => $title,
                    ':description' => $description,
                    ':consultant_id' => $consultant_id,
                    ':category' => $category,
                    ':meeting_day' => $meeting_day,
                    ':meeting_time' => $meeting_time,
                    ':max_members' => $max_members
                ]);
                $success = "Circle created successfully!";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate CSRF token
            } catch (PDOException $e) {
                $errors[] = "Failed to create circle: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    // Output JSON for AJAX
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'errors' => $errors]);
    exit;
}

// Handle join request actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'handle_request') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
        $action = filter_input(INPUT_POST, 'request_action', FILTER_SANITIZE_STRING);

        if (!$request_id) {
            $errors[] = "Invalid request ID.";
        }
        if (!in_array($action, ['approve', 'reject'])) {
            $errors[] = "Invalid action.";
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("UPDATE circle_join_requests SET status = ? WHERE id = ?");
                $stmt->execute([$action === 'approve' ? 'approved' : 'rejected', $request_id]);
                if ($stmt->rowCount() > 0) {
                    $success = "Request " . ($action === 'approve' ? 'approved' : 'rejected') . " successfully!";
                } else {
                    $errors[] = "Failed to update request.";
                }
            } catch (PDOException $e) {
                $errors[] = "Database error: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    // Output JSON for AJAX
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'errors' => $errors]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Circle | Companion</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        /* Dark Mode Toggle Animation */
        .dark-toggle {
            transition: all 0.3s ease;
        }
        
        .dark-toggle:hover {
            transform: rotate(30deg);
        }
        
        /* Sidebar Animation */
        .sidebar-item {
            transition: all 0.2s ease;
        }
        
        .sidebar-item:hover {
            transform: translateX(5px);
        }
        
        /* Card Hover Effects */
        .dashboard-card {
            transition: all 0.3s ease;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        /* Notification Animation */
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .notification-pulse {
            animation: pulse 2s infinite;
        }
        
        /* Modal Transitions */
        .modal {
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        /* Mindful Pro Pastel Styles */
        .bg-pastel-indigo {
            background-color: #e0e7ff;
        }
        
        .text-pastel-mint {
            color: #6ee7b7;
        }
        
        .bg-pastel-mint {
            background-color: #d1fae5;
        }
        
        .focus-ring-pastel {
            --tw-ring-color: #a5b4fc;
        }
        
        /* Responsive Sidebar */
        @media (max-width: 768px) {
            .mobile-menu {
                display: block;
            }
            
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                z-index: 50;
                height: 100vh;
                transition: transform 0.3s ease;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0,0,0,0.5);
                z-index: 40;
            }
            
            .sidebar-overlay.open {
                display: block;
            }
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 transition-colors duration-300">
    <!-- Mobile Menu Button -->
    <div class="lg:hidden fixed top-4 left-4 z-50">
        <button id="mobileMenuButton" class="p-2 rounded-lg bg-blue-600 text-white shadow-md">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="sidebar-overlay"></div>
    
    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar fixed top-0 left-0 w-64 h-full bg-gray-800 text-white shadow-xl overflow-y-auto transition-all duration-300 z-30">
        <div class="p-4 flex items-center justify-between border-b border-gray-700">
            <div class="flex items-center space-x-3">
                <img src="https://placehold.co/40" alt="Logo" class="rounded-lg">
                <h1 class="text-xl font-bold">Companion</h1>
            </div>
            <button id="darkModeToggle" class="dark-toggle p-2 rounded-full hover:bg-gray-700">
                <i class="fas fa-moon"></i>
            </button>
        </div>
        
        <div class="p-4">
            <div class="flex items-center space-x-4 p-3 bg-gray-700 rounded-lg mb-6">
                <img src="https://placehold.co/50" alt="Admin" class="rounded-full border-2 border-blue-500">
                <div>
                    <h2 class="font-semibold">Admin User</h2>
                    <p class="text-gray-400 text-sm">Super Admin</p>
                </div>
            </div>
            
            <nav>
                <h3 class="text-gray-400 uppercase text-xs font-semibold mb-3">Main</h3>
                <ul class="space-y-2">
                    <li>
                        <a href="adminDashboard.php" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-gray-700 rounded-lg">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_users.php" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-gray-700 rounded-lg">
                            <i class="fas fa-users w-5 text-center"></i>
                            <span>Manage Users</span>
                            <span class="ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $total_users; ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_consultants.php" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-gray-700 rounded-lg">
                            <i class="fas fa-user-tie w-5 text-center"></i>
                            <span>Manage Consultants</span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_sessions.php" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-gray-700 rounded-lg">
                            <i class="fas fa-calendar-alt w-5 text-center"></i>
                            <span>Manage Sessions</span>
                        </a>
                    </li>
                </ul>
                
                <h3 class="text-gray-400 uppercase text-xs font-semibold mb-3 mt-6">Content</h3>
                <ul class="space-y-2">
                    <li>
                        <a href="approve_posts.php" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-gray-700 rounded-lg">
                            <i class="fas fa-check-circle w-5 text-center"></i>
                            <span>Approve Posts</span>
                            <span class="ml-auto bg-yellow-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $pending_posts; ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_circles.php" class="sidebar-item flex items-center space-x-3 p-3 bg-blue-600 rounded-lg">
                            <i class="fas fa-comments w-5 text-center"></i>
                            <span>Manage Circles</span>
                        </a>
                    </li>
                    <li>
                        <a href="mood_tracker.php" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-gray-700 rounded-lg">
                            <i class="fas fa-heart w-5 text-center"></i>
                            <span>Mood Tracker</span>
                        </a>
                    </li>
                </ul>
                
                <h3 class="text-gray-400 uppercase text-xs font-semibold mb-3 mt-6">Settings</h3>
                <ul class="space-y-2">
                    <li>
                        <a href="system_settings.php" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-gray-700 rounded-lg">
                            <i class="fas fa-cog w-5 text-center"></i>
                            <span>System Settings</span>
                        </a>
                    </li>
                    <li>
                        <a href="logout.php" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-gray-700 rounded-lg">
                            <i class="fas fa-sign-out-alt w-5 text-center"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        
        <div class="p-4 border-t border-gray-700 mt-auto">
            <div class="text-center text-gray-400 text-sm">
                <p>Companion Admin</p>
            </div>
        </div>
    </aside>
    
    <!-- Main Content -->
    <div class="ml-0 lg:ml-64 transition-all duration-300">
        <!-- Top Navigation -->
        <header class="bg-white dark:bg-gray-800 shadow-sm">
            <div class="flex justify-between items-center p-4">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white">Create New Circle</h1>
                <div class="flex items-center space-x-4">
                    <button class="p-2 rounded-full hover:bg-gray-200 dark:hover:bg-gray-700 relative">
                        <i class="fas fa-bell text-gray-600 dark:text-gray-300"></i>
                        <span class="absolute top-0 right-0 h-3 w-3 bg-red-500 rounded-full notification-pulse"></span>
                    </button>
                    <button class="p-2 rounded-full hover:bg-gray-200 dark:hover:bg-gray-700">
                        <i class="fas fa-envelope text-gray-600 dark:text-gray-300"></i>
                    </button>
                    <button class="p-2 rounded-full hover:bg-gray-200 dark:hover:bg-gray-700">
                        <i class="fas fa-question-circle text-gray-600 dark:text-gray-300"></i>
                    </button>
                </div>
            </div>
        </header>
        
        <main class="p-4">
            <!-- Create Circle Form -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 dashboard-card mb-8">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-6">Create a New Circle</h2>
                
                <?php if ($success): ?>
                    <div class="bg-pastel-mint text-pastel-mint-600 p-4 rounded-lg mb-6 flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 text-red-700 p-4 rounded-lg mb-6">
                        <ul class="list-disc list-inside">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form id="createCircleForm" class="space-y-6" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="create_circle">
                    
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Circle Name</label>
                        <input type="text" id="title" name="title" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-pastel focus:border-indigo-500 dark:bg-gray-700 dark:text-white" placeholder="Enter circle name" required maxlength="100" value="<?= isset($_POST['title']) ? htmlspecialchars($_POST['title']) : '' ?>">
                    </div>
                    
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                        <textarea id="description" name="description" rows="4" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-pastel focus:border-indigo-500 dark:bg-gray-700 dark:text-white" placeholder="Describe the purpose of this circle" required><?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?></textarea>
                    </div>
                    
                    <div>
                        <label for="category" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Category</label>
                        <select id="category" name="category" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-pastel focus:border-indigo-500 dark:bg-gray-700 dark:text-white" required>
                            <option value="mental-health" <?= isset($_POST['category']) && $_POST['category'] === 'mental-health' ? 'selected' : '' ?>>Mental Health</option>
                            <option value="addiction" <?= isset($_POST['category']) && $_POST['category'] === 'addiction' ? 'selected' : '' ?>>Addiction Recovery</option>
                            <option value="grief" <?= isset($_POST['category']) && $_POST['category'] === 'grief' ? 'selected' : '' ?>>Grief Support</option>
                            <option value="stress" <?= isset($_POST['category']) && $_POST['category'] === 'stress' ? 'selected' : '' ?>>Stress Management</option>
                            <option value="relationships" <?= isset($_POST['category']) && $_POST['category'] === 'relationships' ? 'selected' : '' ?>>Relationships</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="lead_consultant_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Circle Lead (Consultant)</label>
                        <select id="lead_consultant_id" name="lead_consultant_id" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-pastel focus:border-indigo-500 dark:bg-gray-700 dark:text-white" required>
                            <option value="">Select a consultant</option>
                            <?php foreach ($consultants as $c): ?>
                                <option value="<?= htmlspecialchars($c['id']) ?>" <?= isset($_POST['lead_consultant_id']) && $_POST['lead_consultant_id'] == $c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Meeting Schedule</label>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Day</label>
                                <select name="meeting_day" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-pastel focus:border-indigo-500 dark:bg-gray-700 dark:text-white" required>
                                    <option value="monday" <?= isset($_POST['meeting_day']) && $_POST['meeting_day'] === 'monday' ? 'selected' : '' ?>>Monday</option>
                                    <option value="tuesday" <?= isset($_POST['meeting_day']) && $_POST['meeting_day'] === 'tuesday' ? 'selected' : '' ?>>Tuesday</option>
                                    <option value="wednesday" <?= isset($_POST['meeting_day']) && $_POST['meeting_day'] === 'wednesday' ? 'selected' : '' ?>>Wednesday</option>
                                    <option value="thursday" <?= isset($_POST['meeting_day']) && $_POST['meeting_day'] === 'thursday' ? 'selected' : '' ?>>Thursday</option>
                                    <option value="friday" <?= isset($_POST['meeting_day']) && $_POST['meeting_day'] === 'friday' ? 'selected' : '' ?>>Friday</option>
                                    <option value="saturday" <?= isset($_POST['meeting_day']) && $_POST['meeting_day'] === 'saturday' ? 'selected' : '' ?>>Saturday</option>
                                    <option value="sunday" <?= isset($_POST['meeting_day']) && $_POST['meeting_day'] === 'sunday' ? 'selected' : '' ?>>Sunday</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Time</label>
                                <input type="time" name="meeting_time" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-pastel focus:border-indigo-500 dark:bg-gray-700 dark:text-white" required value="<?= isset($_POST['meeting_time']) ? htmlspecialchars($_POST['meeting_time']) : '' ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <label for="max_members" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Maximum Members</label>
                        <input type="number" id="max_members" name="max_members" min="5" max="20" value="<?= isset($_POST['max_members']) ? htmlspecialchars($_POST['max_members']) : '10' ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-pastel focus:border-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>
                    
                    <div class="flex justify-end space-x-4">
                        <button type="button" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700" onclick="window.location.href='dashboard.php'">Cancel</button>
                        <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-pastel focus:ring-offset-2">Create Circle</button>
                    </div>
                </form>
            </div>
            
            <!-- Pending Requests Section -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 dashboard-card">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-6">Pending Join Requests</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">User</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Circle</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Request Date</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($pending_requests as $request): ?>
                                <tr data-request-id="<?= htmlspecialchars($request['request_id']) ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10 rounded-full bg-pastel-indigo dark:bg-indigo-900/50 flex items-center justify-center">
                                                <span class="text-indigo-600 dark:text-indigo-300 font-medium"><?= strtoupper(substr($request['user_first_name'], 0, 1)) ?></span>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900 dark:text-gray-100"><?= htmlspecialchars($request['user_first_name'] . ' ' . $request['user_last_name']) ?></div>
                                                <div class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($request['email']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-gray-100"><?= htmlspecialchars($request['circle_title']) ?></div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">Led by <?= htmlspecialchars($request['consultant_first_name'] . ' ' . $request['consultant_last_name']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?= htmlspecialchars(date('F j, Y', strtotime($request['request_date']))) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <button class="text-pastel-mint hover:text-green-900 mr-4 approve-btn" data-request-id="<?= htmlspecialchars($request['request_id']) ?>">Approve</button>
                                        <button class="text-red-600 hover:text-red-900 reject-btn" data-request-id="<?= htmlspecialchars($request['request_id']) ?>">Reject</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Success Modal -->
    <div id="successModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 modal opacity-0 invisible">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6 transform transition-all duration-300 scale-95">
            <div class="flex justify-between items-start mb-4">
                <div class="flex items-center">
                    <div class="bg-pastel-mint p-2 rounded-full mr-3">
                        <i class="fas fa-check-circle text-pastel-mint-600 text-xl"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white">Success!</h3>
                </div>
                <button id="closeSuccessModal" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="mb-6">
                <p class="text-gray-600 dark:text-gray-300">The circle has been created successfully and is now visible to users. They can request to join, and you can approve or reject their requests from the Pending Requests section.</p>
            </div>
            <div class="flex justify-end">
                <button id="confirmSuccess" class="px-4 py-2 bg-pastel-mint text-pastel-mint-600 rounded-lg hover:bg-green-200 focus:outline-none focus:ring-2 focus:ring-pastel focus:ring-offset-2">
                    OK
                </button>
            </div>
        </div>
    </div>
    
    <!-- Request Action Modal -->
    <div id="actionModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 modal opacity-0 invisible">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6 transform transition-all duration-300 scale-95">
            <div class="flex justify-between items-start mb-4">
                <h3 id="actionModalTitle" class="text-lg font-bold text-gray-800 dark:text-white">Confirm Action</h3>
                <button id="closeActionModal" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="mb-6">
                <p id="actionModalMessage" class="text-gray-600 dark:text-gray-300">Are you sure you want to approve this join request?</p>
            </div>
            <div class="flex justify-end space-x-3">
                <button id="cancelAction" class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                    Cancel
                </button>
                <button id="confirmAction" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-pastel focus:ring-offset-2">
                    Confirm
                </button>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle mobile sidebar
        const mobileMenuButton = document.getElementById('mobileMenuButton');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        
        mobileMenuButton.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            sidebarOverlay.classList.toggle('open');
        });
        
        sidebarOverlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            sidebarOverlay.classList.remove('open');
        });
        
        // Dark mode toggle
        const darkModeToggle = document.getElementById('darkModeToggle');
        const html = document.documentElement;
        
        darkModeToggle.addEventListener('click', () => {
            html.classList.toggle('dark');
            const icon = darkModeToggle.querySelector('i');
            if (icon.classList.contains('fa-moon')) {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
            }
        });
        
        // Form submission with AJAX
        document.getElementById('createCircleForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('create_circle.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('successModal').classList.remove('opacity-0', 'invisible');
                    document.getElementById('successModal').classList.add('opacity-100', 'visible');
                    document.querySelector('#successModal > div').classList.remove('scale-95');
                    document.querySelector('#successModal > div').classList.add('scale-100');
                    document.getElementById('createCircleForm').reset();
                } else {
                    const errorContainer = document.createElement('div');
                    errorContainer.className = 'bg-red-50 text-red-700 p-4 rounded-lg mb-6';
                    errorContainer.innerHTML = '<ul class="list-disc list-inside">' + 
                        data.errors.map(error => `<li>${error}</li>`).join('') + 
                        '</ul>';
                    document.getElementById('createCircleForm').prepend(errorContainer);
                    setTimeout(() => errorContainer.remove(), 5000);
                }
            })
            .catch(error => console.error('Error:', error));
        });
        
        // Close success modal
        document.getElementById('closeSuccessModal').addEventListener('click', closeSuccessModal);
        document.getElementById('confirmSuccess').addEventListener('click', closeSuccessModal);
        
        function closeSuccessModal() {
            closeModal('successModal');
        }
        
        // Handle join request actions
        let currentRequestId = null;
        let currentAction = null;
        
        document.querySelectorAll('.approve-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                currentRequestId = this.getAttribute('data-request-id');
                currentAction = 'approve';
                showActionModal('Approve Request', 'Are you sure you want to approve this join request? The user will be added to the circle.');
            });
        });
        
        document.querySelectorAll('.reject-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                currentRequestId = this.getAttribute('data-request-id');
                currentAction = 'reject';
                showActionModal('Reject Request', 'Are you sure you want to reject this join request? The user will be notified.');
            });
        });
        
        // Confirm action in modal
        document.getElementById('confirmAction').addEventListener('click', function() {
            const formData = new FormData();
            formData.append('action', 'handle_request');
            formData.append('request_id', currentRequestId);
            formData.append('request_action', currentAction);
            formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
            
            fetch('create_circle.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const row = document.querySelector(`tr[data-request-id="${currentRequestId}"]`);
                    if (row) row.remove();
                    const alert = document.createElement('div');
                    alert.className = 'bg-pastel-mint text-pastel-mint-600 p-4 rounded-lg mb-6 flex items-center fixed top-20 right-4 z-50';
                    alert.innerHTML = `<i class="fas fa-check-circle mr-2"></i>${data.success}`;
                    document.body.appendChild(alert);
                    setTimeout(() => alert.remove(), 3000);
                } else {
                    alert('Error: ' + data.errors.join(', '));
                }
                closeModal('actionModal');
            })
            .catch(error => {
                console.error('Error:', error);
                closeModal('actionModal');
            });
        });
        
        // Cancel action in modal
        document.getElementById('cancelAction').addEventListener('click', function() {
            closeModal('actionModal');
        });
        
        // Close action modal
        document.getElementById('closeActionModal').addEventListener('click', function() {
            closeModal('actionModal');
        });
        
        // Helper functions
        function showActionModal(title, message) {
            document.getElementById('actionModalTitle').textContent = title;
            document.getElementById('actionModalMessage').textContent = message;
            document.getElementById('actionModal').classList.remove('opacity-0', 'invisible');
            document.getElementById('actionModal').classList.add('opacity-100', 'visible');
            document.querySelector('#actionModal > div').classList.remove('scale-95');
            document.querySelector('#actionModal > div').ClassList.add('scale-100');
        }
        
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.remove('opacity-100', 'visible');
            modal.classList.add('opacity-0', 'invisible');
            document.querySelector(`#${modalId} > div`).classList.remove('scale-100');
            document.querySelector(`#${modalId} > div`).classList.add('scale-95');
        }
    </script>
</body>
</html>
```