<?php
// Include PDO connection
include 'config/db.php';

// Prepare and execute queries with PDO
$user_count_query = "SELECT COUNT(*) AS total_users FROM users";
$stmt = $pdo->query($user_count_query);
$total_users = $stmt->fetch()['total_users'];

$consultant_count_query = "SELECT COUNT(*) AS total_consultants FROM consultants";
$stmt = $pdo->query($consultant_count_query);
$total_consultants = $stmt->fetch()['total_consultants'];

$session_count_query = "SELECT COUNT(*) AS total_sessions FROM anonymous_counselling_bookings";
$stmt = $pdo->query($session_count_query);
$total_sessions = $stmt->fetch()['total_sessions'];

$post_approval_query = "SELECT COUNT(*) AS pending_posts FROM community_posts WHERE is_approved = 0";
$stmt = $pdo->query($post_approval_query);
$pending_posts = $stmt->fetch()['pending_posts'];

$user_registration_query = "SELECT DATE(created_at) AS reg_date, COUNT(*) AS count 
                          FROM users 
                          WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) 
                          GROUP BY DATE(created_at)";
$stmt = $pdo->query($user_registration_query);

$dates = [];
$registrations = [];
while ($row = $stmt->fetch()) {
    $dates[] = $row['reg_date'];
    $registrations[] = $row['count'];

}
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $string = [
        'y' => 'yr',
        'm' => 'mo',
        'd' => 'd',
        'h' => 'h',
        'i' => 'min',
        's' => 'sec',
    ];
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . $v;
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

$recent_activities = [];

// 1. Anonymous user registrations
$user_stmt = $pdo->query("SELECT id, created_at FROM users ORDER BY created_at DESC LIMIT 3");
while ($row = $user_stmt->fetch()) {
    $recent_activities[] = [
        'icon' => 'fas fa-user-plus',
        'bg' => 'bg-blue-100 dark:bg-blue-900/50',
        'text_color' => 'text-blue-600 dark:text-blue-300',
        'title' => 'New user registered',
        'desc' => 'User #' . $row['id'] . ' joined the platform',
        'time' => time_elapsed_string($row['created_at']),
    ];
}

// 2. Anonymous forum posts
$post_stmt = $pdo->query("SELECT id, user_id, is_anonymous, created_at FROM community_posts ORDER BY created_at DESC LIMIT 3");
while ($row = $post_stmt->fetch()) {
    $user_label = $row['is_anonymous'] ? 'Anonymous user' : 'User #' . $row['user_id'];
    $recent_activities[] = [
        'icon' => 'fas fa-comment-alt',
        'bg' => 'bg-purple-100 dark:bg-purple-900/50',
        'text_color' => 'text-purple-600 dark:text-purple-300',
        'title' => 'New community post',
        'desc' => "$user_label posted in community",
        'time' => time_elapsed_string($row['created_at']),
    ];
}

// 3. Anonymized session completions (if available)
$session_stmt = $pdo->query("
    SELECT id, user_id, status, created_at 
    FROM anonymous_counselling_bookings 
    WHERE status = 'completed' 
    ORDER BY created_at DESC 
    LIMIT 2
");

while ($row = $session_stmt->fetch()) {
    $recent_activities[] = [
        'icon' => 'fas fa-calendar-check',
        'bg' => 'bg-green-100 dark:bg-green-900/50',
        'text_color' => 'text-green-600 dark:text-green-300',
        'title' => 'Session completed',
        'desc' => "Session #{$row['id']} completed by consultant #{$row['consultant_id']}",
        'time' => time_elapsed_string($row['created_at']),
    ];
}


// Optional: sort by most recent time
usort($recent_activities, fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Companion</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                        <a href="#" class="sidebar-item flex items-center space-x-3 p-3 bg-blue-600 rounded-lg">
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
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white">Dashboard Overview</h1>
                
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
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="dashboard-card bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden transition-all">
                    <div class="p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 dark:text-gray-400 text-sm font-medium">Total Users</p>
                                <h3 class="text-3xl font-bold text-gray-800 dark:text-white mt-2"><?php echo number_format($total_users); ?></h3>
                            </div>
                            <div class="p-3 rounded-full bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-300">
                                <i class="fas fa-users text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4">
                            <span class="text-green-500 text-sm font-semibold"><i class="fas fa-arrow-up"></i> 12.5%</span>
                            <span class="text-gray-500 dark:text-gray-400 text-sm ml-2">vs last week</span>
                        </div>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-700 px-5 py-3">
                        <a href="manage_users.php" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-500 dark:hover:text-blue-300">
                            View details →
                        </a>
                    </div>
                </div>
                
                <div class="dashboard-card bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden transition-all">
                    <div class="p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 dark:text-gray-400 text-sm font-medium">Active Consultants</p>
                                <h3 class="text-3xl font-bold text-gray-800 dark:text-white mt-2"><?php echo number_format($total_consultants); ?></h3>
                            </div>
                            <div class="p-3 rounded-full bg-green-100 dark:bg-green-900/50 text-green-600 dark:text-green-300">
                                <i class="fas fa-user-tie text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4">
                            <span class="text-green-500 text-sm font-semibold"><i class="fas fa-arrow-up"></i> 5.3%</span>
                            <span class="text-gray-500 dark:text-gray-400 text-sm ml-2">vs last week</span>
                        </div>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-700 px-5 py-3">
                        <a href="list_consultants.php" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-500 dark:hover:text-blue-300">
                            View details →
                        </a>
                    </div>
                </div>
                
                <div class="dashboard-card bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden transition-all">
                    <div class="p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 dark:text-gray-400 text-sm font-medium">Scheduled Sessions</p>
                                <h3 class="text-3xl font-bold text-gray-800 dark:text-white mt-2"><?php echo number_format($total_sessions); ?></h3>
                            </div>
                            <div class="p-3 rounded-full bg-purple-100 dark:bg-purple-900/50 text-purple-600 dark:text-purple-300">
                                <i class="fas fa-calendar-check text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4">
                            <span class="text-red-500 text-sm font-semibold"><i class="fas fa-arrow-down"></i> 2.1%</span>
                            <span class="text-gray-500 dark:text-gray-400 text-sm ml-2">vs last week</span>
                        </div>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-700 px-5 py-3">
                        <a href="manage_sessions.php" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-500 dark:hover:text-blue-300">
                            View details →
                        </a>
                    </div>
                </div>
                
                <div class="dashboard-card bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden transition-all">
                    <div class="p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-500 dark:text-gray-400 text-sm font-medium">Pending Approvals</p>
                                <h3 class="text-3xl font-bold text-gray-800 dark:text-white mt-2"><?php echo number_format($pending_posts); ?></h3>
                            </div>
                            <div class="p-3 rounded-full bg-yellow-100 dark:bg-yellow-900/50 text-yellow-600 dark:text-yellow-300">
                                <i class="fas fa-exclamation-circle text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4">
                            <span class="text-green-500 text-sm font-semibold"><i class="fas fa-arrow-up"></i> 8.4%</span>
                            <span class="text-gray-500 dark:text-gray-400 text-sm ml-2">vs last week</span>
                        </div>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-700 px-5 py-3">
                        <a href="approve_requests.php" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-500 dark:hover:text-blue-300">
                            View details →
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white">User Registrations</h2>
                        <select class="bg-gray-100 dark:bg-gray-700 border-none text-sm rounded-lg px-3 py-1 focus:ring-blue-500 focus:border-blue-500">
                            <option>Last 7 days</option>
                            <option selected>Last 30 days</option>
                            <option>Last 90 days</option>
                            <option>Last year</option>
                        </select>
                    </div>
                    <div class="h-[300px]">
                        <canvas id="userChart"></canvas>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white">Session Activity</h2>
                        <select class="bg-gray-100 dark:bg-gray-700 border-none text-sm rounded-lg px-3 py-1 focus:ring-blue-500 focus:border-blue-500">
                            <option>Last 7 days</option>
                            <option selected>Last 30 days</option>
                            <option>Last 90 days</option>
                            <option>Last year</option>
                        </select>
                    </div>
                    <div class="h-[300px]">
                        <canvas id="sessionChart"></canvas>
                    </div>
                </div>
            </div>
            
    <!-- Recent Activity & Quick Actions -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Recent Activity Section -->
    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-6">Recent Activity</h2>
        <div class="space-y-4">
            <?php foreach ($recent_activities as $activity): ?>
                <div class="flex items-start space-x-4">
                    <div class="p-2 <?= htmlspecialchars($activity['bg']) ?> rounded-lg <?= htmlspecialchars($activity['text_color']) ?>">
                        <i class="<?= htmlspecialchars($activity['icon']) ?>"></i>
                    </div>
                    <div>
                        <p class="text-gray-800 dark:text-gray-100 font-medium"><?= htmlspecialchars($activity['title']) ?></p>
                        <p class="text-gray-500 dark:text-gray-400 text-sm"><?= htmlspecialchars($activity['desc']) ?></p>
                    </div>
                    <span class="ml-auto text-gray-400 text-sm"><?= htmlspecialchars($activity['time']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Quick Actions Section -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-6">Quick Actions</h2>
        <div class="space-y-3">
            <a href="ConsultantSignup.php" class="flex items-center space-x-3 p-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors">
                <div class="p-2 bg-blue-100 dark:bg-blue-900/50 rounded-lg text-blue-600 dark:text-blue-300">
                    <i class="fas fa-plus"></i>
                </div>
                <span class="font-medium text-gray-800 dark:text-gray-100">Add New Consultant</span>
            </a>
            
            <a href="create_announcement.php" class="flex items-center space-x-3 p-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors">
                <div class="p-2 bg-green-100 dark:bg-green-900/50 rounded-lg text-green-600 dark:text-green-300">
                    <i class="fas fa-bullhorn"></i>
                </div>
                <span class="font-medium text-gray-800 dark:text-gray-100">Create Announcement</span>
            </a>
            
            <a href="create_circle.php" class="flex items-center space-x-3 p-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors">
                <div class="p-2 bg-purple-100 dark:bg-purple-900/50 rounded-lg text-purple-600 dark:text-purple-300">
                    <i class="fas fa-circle"></i>
                </div>
                <span class="font-medium text-gray-800 dark:text-gray-100">Create Support Circle</span>
            </a>
            
            <a href="#" class="flex items-center space-x-3 p-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors">
                <div class="p-2 bg-yellow-100 dark:bg-yellow-900/50 rounded-lg text-yellow-600 dark:text-yellow-300">
                    <i class="fas fa-file-export"></i>
                </div>
                <span class="font-medium text-gray-800 dark:text-gray-100">Export Reports</span>
            </a>
            
            <a href="#" class="flex items-center space-x-3 p-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors">
                <div class="p-2 bg-indigo-100 dark:bg-indigo-900/50 rounded-lg text-indigo-600 dark:text-indigo-300">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <span class="font-medium text-gray-800 dark:text-gray-100">Security Settings</span>
            </a>
        </div>
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
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // User Registrations Chart
            const userCtx = document.getElementById('userChart').getContext('2d');
            const userChart = new Chart(userCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($dates); ?>,
                    datasets: [{
                        label: 'Registrations',
                        data: <?php echo json_encode($registrations); ?>,
                        borderColor: '#3B82F6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#3B82F6'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
            
            // Session Activity Chart
            const sessionCtx = document.getElementById('sessionChart').getContext('2d');
            const sessionChart = new Chart(sessionCtx, {
                type: 'bar',
                data: {
                    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    datasets: [{
                        label: 'Completed',
                        data: [45, 60, 75, 52, 83, 35, 28],
                        backgroundColor: '#10B981',
                        borderRadius: 6
                    }, {
                        label: 'Cancelled',
                        data: [12, 8, 15, 6, 11, 4, 7],
                        backgroundColor: '#EF4444',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>