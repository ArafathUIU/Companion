```php
<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: adminLogin.php");
    exit;
}

$success = "";
$error = "";

// Handle form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient_type = $_POST['recipient_type'];
    $title = trim($_POST['title']);
    $message = trim($_POST['message']);

    if ($title && $message && in_array($recipient_type, ['user', 'consultant', 'both'])) {
        try {
            // Insert into announcements
            $stmt = $pdo->prepare("INSERT INTO announcements (recipient_type, title, message, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$recipient_type, $title, $message]);
            $announcement_id = $pdo->lastInsertId();

            // Notify users/consultants based on type
            $targets = [];

            if ($recipient_type === 'user' || $recipient_type === 'both') {
                $users = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($users as $uid) {
                    $targets[] = ['user', $uid];
                }
            }

            if ($recipient_type === 'consultant' || $recipient_type === 'both') {
                $consultants = $pdo->query("SELECT id FROM consultants")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($consultants as $cid) {
                    $targets[] = ['consultant', $cid];
                }
            }

            // Insert notifications
            $notiStmt = $pdo->prepare("INSERT INTO notifications (type, related_id, status, created_at) VALUES ('announcement', ?, 'unread', NOW())");
            foreach ($targets as $target) {
                $notiStmt->execute([$announcement_id]);
            }

            $success = "Announcement posted and notifications sent.";

        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "All fields are required and recipient type must be valid.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Announcement | Companion</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Custom scrollbar */
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
        
        /* Dark mode toggle animation */
        .dark-toggle {
            transition: all 0.3s ease;
        }
        
        .dark-toggle:hover {
            transform: rotate(30deg);
        }
        
        /* Sidebar animation */
        .sidebar-item {
            transition: all 0.2s ease;
        }
        
        .sidebar-item:hover {
            transform: translateX(5px);
        }
        
        /* Card hover effects */
        .dashboard-card {
            transition: all 0.3s ease;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        /* Notification animation */
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
        }
        
        .notification-pulse {
            animation: pulse 2s infinite;
        }
        
        /* Responsive adjustments */
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
                        </a>
                    </li>
                    <li>
                        <a href="manage_circles.php" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-gray-700 rounded-lg">
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
                <div class="flex items-center space-x-4">
                    <button onclick="window.location.href='adminDashboard.php'" class="bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg transition-all">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
                    </button>
                    <h1 class="text-2xl font-bold text-gray-800 dark:text-white">Create Announcement</h1>
                </div>
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
            <div class="max-w-2xl mx-auto">
                <div class="dashboard-card bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                    <?php if ($success): ?>
                        <div class="bg-green-100 dark:bg-green-900/50 text-green-800 dark:text-green-300 p-3 rounded mb-4"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="bg-red-100 dark:bg-red-900/50 text-red-800 dark:text-red-300 p-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" class="space-y-6">
                        <div>
                            <label class="block font-medium text-gray-700 dark:text-gray-200 mb-2">Recipient Type</label>
                            <select name="recipient_type" class="w-full bg-gray-100 dark:bg-gray-700 border-none rounded-lg px-3 py-2 focus:ring-blue-500 focus:border-blue-500 text-gray-800 dark:text-gray-200" required>
                                <option value="">-- Select --</option>
                                <option value="user">Users</option>
                                <option value="consultant">Consultants</option>
                                <option value="both">Both</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-medium text-gray-700 dark:text-gray-200 mb-2">Title</label>
                            <input type="text" name="title" class="w-full bg-gray-100 dark:bg-gray-700 border-none rounded-lg px-3 py-2 focus:ring-blue-500 focus:border-blue-500 text-gray-800 dark:text-gray-200" required>
                        </div>
                        <div>
                            <label class="block font-medium text-gray-700 dark:text-gray-200 mb-2">Message</label>
                            <textarea name="message" rows="5" class="w-full bg-gray-100 dark:bg-gray-700 border-none rounded-lg px-3 py-2 focus:ring-blue-500 focus:border-blue-500 text-gray-800 dark:text-gray-200" required></textarea>
                        </div>
                        <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition-all">Send Announcement</button>
                    </form>
                </div>
            </div>
        </main>
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
            
            // Toggle icon between moon and sun
            const icon = darkModeToggle.querySelector('i');
            if (icon.classList.contains('fa-moon')) {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
            }
        });
    </script>
</body>
</html>
