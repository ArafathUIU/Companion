<?php
session_start();
require_once 'config/db.php';

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Redirect if not logged in as consultant
if (!isset($_SESSION['consultant_id'])) {
    header("Location: consultantLogin.php");
    exit;
}

$consultant_id = $_SESSION['consultant_id'];

// Fetch consultant profile
try {
    $stmt = $pdo->prepare("SELECT first_name, last_name, specialization, bio, is_available FROM consultants WHERE id = ?");
    $stmt->execute([$consultant_id]);
    $consultant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$consultant) {
        die("Consultant not found.");
    }
} catch (PDOException $e) {
    die("Error fetching consultant: " . htmlspecialchars($e->getMessage()));
}

// Fetch pending session requests
try {
    $stmt = $pdo->prepare(" 
        SELECT * 
        FROM anonymous_counselling_bookings 
        WHERE status = 'pending' AND (consultant_id IS NULL OR consultant_id = ?) 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$consultant_id]);
    $pending_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pending_sessions = [];
    error_log("Error fetching pending sessions: " . $e->getMessage());
}

// Fetch upcoming accepted sessions
try {
    $stmt = $pdo->prepare("
        SELECT id, user_id, preferred_date, preferred_time, status, created_at 
        FROM anonymous_counselling_bookings 
        WHERE consultant_id = ? AND status = 'accepted' AND preferred_date >= CURDATE() 
        ORDER BY preferred_date, preferred_time
    ");
    $stmt->execute([$consultant_id]);
    $upcoming_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $upcoming_sessions = [];
    error_log("Error fetching upcoming sessions: " . $e->getMessage());
}

// Fetch recent completed sessions
try {
    $stmt = $pdo->prepare("
        SELECT id, user_id, preferred_date, preferred_time, status 
        FROM anonymous_counselling_bookings 
        WHERE consultant_id = ? AND status = 'completed' 
        ORDER BY preferred_date DESC LIMIT 10
    ");
    $stmt->execute([$consultant_id]);
    $completed_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $completed_sessions = [];
    error_log("Error fetching completed sessions: " . $e->getMessage());
}

// Fetch circles led by the consultant
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.id, c.title, c.description, c.category, c.meeting_day, c.meeting_time, c.max_members, c.status,
            (SELECT COUNT(*) FROM circle_members cm WHERE cm.circle_id = c.id AND cm.status = 'approved') AS member_count
        FROM circles c
        WHERE c.lead_consultant_id = ?
        ORDER BY c.title ASC
    ");
    $stmt->execute([$consultant_id]);
    $circles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $circles = [];
    error_log("Error fetching circles: " . $e->getMessage());
}

// Stats for dashboard
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_sessions,
            SUM(status = 'pending' AND consultant_id IS NOT NULL) AS pending_sessions,
            SUM(status = 'accepted') AS accepted_sessions,
            SUM(status = 'completed') AS completed_sessions
        FROM anonymous_counselling_bookings
        WHERE consultant_id = ?
    ");
    $stmt->execute([$consultant_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = ['total_sessions' => 0, 'pending_sessions' => 0, 'accepted_sessions' => 0, 'completed_sessions' => 0];
    error_log("Error fetching stats: " . $e->getMessage());
}

// Fetch session activity for chart (last 30 days)
try {
    $stmt = $pdo->prepare("
        SELECT DATE(preferred_date) AS session_date, COUNT(*) AS count 
        FROM anonymous_counselling_bookings 
        WHERE consultant_id = ? AND preferred_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
        GROUP BY DATE(preferred_date)
    ");
    $stmt->execute([$consultant_id]);
    $session_dates = [];
    $session_counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $session_dates[] = $row['session_date'];
        $session_counts[] = $row['count'];
    }
} catch (PDOException $e) {
    $session_dates = [];
    $session_counts = [];
    error_log("Error fetching session activity: " . $e->getMessage());
}

// Recent activities
$recent_activities = [];
foreach ($pending_sessions as $session) {
    $recent_activities[] = [
        'icon' => 'fas fa-hourglass-half',
        'bg' => 'bg-yellow-100 dark:bg-yellow-900/50',
        'text_color' => 'text-yellow-600 dark:text-yellow-300',
        'title' => 'New Session Request',
        'desc' => "Session #{$session['id']} requested for {$session['preferred_date']}",
        'time' => time_elapsed_string($session['created_at'] ?? date('Y-m-d H:i:s')),
    ];
}
foreach ($upcoming_sessions as $session) {
    $recent_activities[] = [
        'icon' => 'fas fa-calendar-check',
        'bg' => 'bg-green-100 dark:bg-green-900/50',
        'text_color' => 'text-green-600 dark:text-green-300',
        'title' => 'Session Accepted',
        'desc' => "Session #{$session['id']} scheduled for {$session['preferred_date']}",
        'time' => time_elapsed_string($session['created_at'] ?? date('Y-m-d H:i:s')),
    ];
}
foreach ($completed_sessions as $session) {
    $recent_activities[] = [
        'icon' => 'fas fa-check-circle',
        'bg' => 'bg-blue-100 dark:bg-blue-900/50',
        'text_color' => 'text-blue-600 dark:text-blue-300',
        'title' => 'Session Completed',
        'desc' => "Session #{$session['id']} completed on {$session['preferred_date']}",
        'time' => time_elapsed_string($session['created_at'] ?? date('Y-m-d H:i:s')),
    ];
}
foreach ($circles as $circle) {
    $recent_activities[] = [
        'icon' => 'fas fa-users',
        'bg' => 'bg-purple-100 dark:bg-purple-900/50',
        'text_color' => 'text-purple-600 dark:text-purple-300',
        'title' => 'Circle Activity',
        'desc' => "Circle '{$circle['title']}' has {$circle['member_count']} members",
        'time' => time_elapsed_string(date('Y-m-d H:i:s')), // Placeholder; use circle activity timestamp if available
    ];
}
usort($recent_activities, fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));
$recent_activities = array_slice($recent_activities, 0, 5);

// Handle tab selection
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : (strpos($_SERVER['REQUEST_URI'], 'conduct_session.php') !== false ? 'conduct_session' : 'dashboard');

function time_elapsed_string($datetime, $full = false) {
    if ($datetime === null || $datetime === '') {
        return 'Unknown time';
    }
    try {
        $now = new DateTime();
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
    } catch (Exception $e) {
        error_log("DateTime error: " . $e->getMessage());
        return 'Invalid time';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultant Dashboard | Companion</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Gradient Styles */
        .gradient-indigo {
            background: linear-gradient(135deg, #6366f1, #3b82f6);
        }
        .gradient-mint {
            background: linear-gradient(135deg, #34d399, #6ee7b7);
        }
        .gradient-purple {
            background: linear-gradient(135deg, #a855f7, #d946ef);
        }
        .hover-scale {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .hover-scale:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Sidebar Animation */
        .sidebar-item {
            transition: all 0.2s ease;
        }
        .sidebar-item:hover {
            transform: translateX(5px);
            background: linear-gradient(to right, rgba(255, 255, 255, 0.1), transparent);
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

        /* Responsive Sidebar */
        @media (max-width: 768px) {
            .mobile-menu { display: block; }
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
<body class="bg-gray-50 dark:bg-gray-900 font-inter transition-colors duration-300">
    <!-- Mobile Menu Button -->
    <div class="lg:hidden fixed top-4 left-4 z-50">
        <button id="mobileMenuButton" class="p-2 rounded-lg gradient-indigo text-white shadow-md">
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
            <button id="darkModeToggle" class="p-2 rounded-full hover:bg-gray-700">
                <i class="fas fa-moon"></i>
            </button>
        </div>
        
        <div class="p-4">
            <div class="flex items-center space-x-4 p-3 gradient-indigo rounded-lg mb-6">
                <img src="https://placehold.co/50" alt="Consultant" class="rounded-full border-2 border-white">
                <div>
                    <h2 class="font-semibold"><?php echo htmlspecialchars($consultant['first_name'] . ' ' . $consultant['last_name']); ?></h2>
                    <p class="text-gray-300 text-sm">Consultant</p>
                </div>
            </div>
            
            <nav>
                <h3 class="text-gray-400 uppercase text-xs font-semibold mb-3">Main</h3>
                <ul class="space-y-2">
                    <li>
                        <a href="?tab=dashboard" class="sidebar-item flex items-center space-x-3 p-3 <?php echo $active_tab == 'dashboard' ? 'gradient-indigo' : 'hover:bg-gray-700'; ?> rounded-lg">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=sessions" class="sidebar-item flex items-center space-x-3 p-3 <?php echo $active_tab == 'sessions' ? 'gradient-indigo' : 'hover:bg-gray-700'; ?> rounded-lg">
                            <i class="fas fa-calendar-alt w-5 text-center"></i>
                            <span>Sessions</span>
                            <span class="ml-auto bg-yellow-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $stats['pending_sessions'] ?? 0; ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="conduct_session.php" class="sidebar-item flex items-center space-x-3 p-3 <?php echo $active_tab == 'conduct_session' ? 'gradient-indigo' : 'hover:bg-gray-700'; ?> rounded-lg">
                            <i class="fas fa-video w-5 text-center"></i>
                            <span>Conduct Session</span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=circles" class="sidebar-item flex items-center space-x-3 p-3 <?php echo $active_tab == 'circles' ? 'gradient-indigo' : 'hover:bg-gray-700'; ?> rounded-lg">
                            <i class="fas fa-users w-5 text-center"></i>
                            <span>Circles</span>
                            <span class="ml-auto bg-purple-500 text-white text-xs px-2 py-1 rounded-full"><?php echo count($circles); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="?tab=profile" class="sidebar-item flex items-center space-x-3 p-3 <?php echo $active_tab == 'profile' ? 'gradient-indigo' : 'hover:bg-gray-700'; ?> rounded-lg">
                            <i class="fas fa-user w-5 text-center"></i>
                            <span>Profile</span>
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
                <p>Companion Consultant</p>
            </div>
        </div>
    </aside>
    
    <!-- Main Content -->
    <div class="ml-0 lg:ml-64 transition-all duration-300">
        <!-- Top Navigation -->
        <header class="bg-white dark:bg-gray-800 shadow-sm">
            <div class="flex justify-between items-center p-4">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo ucfirst(str_replace('_', ' ', $active_tab)); ?> Overview</h1>
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
            <!-- Dashboard Tab -->
            <?php if ($active_tab == 'dashboard'): ?>
            <div class="space-y-6 fade-in">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="dashboard-card bg-gradient-to-br from-indigo-500 to-blue-600 text-white rounded-xl shadow-md overflow-hidden hover-scale">
                        <div class="p-5">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-white/80 text-sm font-medium">Total Sessions</p>
                                    <h3 class="text-3xl font-bold mt-2"><?php echo number_format($stats['total_sessions'] ?? 0); ?></h3>
                                </div>
                                <div class="p-3 rounded-full bg-white/20">
                                    <i class="fas fa-calendar-alt text-xl"></i>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white/10 px-5 py-3">
                            <a href="?tab=sessions" class="text-sm font-medium text-white hover:text-white/80">
                                View details →
                            </a>
                        </div>
                    </div>
                    <div class="dashboard-card bg-gradient-to-br from-yellow-500 to-orange-600 text-white rounded-xl shadow-md overflow-hidden hover-scale">
                        <div class="p-5">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-white/80 text-sm font-medium">Pending Requests</p>
                                    <h3 class="text-3xl font-bold mt-2"><?php echo number_format($stats['pending_sessions'] ?? 0); ?></h3>
                                </div>
                                <div class="p-3 rounded-full bg-white/20">
                                    <i class="fas fa-hourglass-half text-xl"></i>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white/10 px-5 py-3">
                            <a href="?tab=sessions" class="text-sm font-medium text-white hover:text-white/80">
                                View details →
                            </a>
                        </div>
                    </div>
                    <div class="dashboard-card bg-gradient-to-br from-green-500 to-teal-600 text-white rounded-xl shadow-md overflow-hidden hover-scale">
                        <div class="p-5">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-white/80 text-sm font-medium">Accepted Sessions</p>
                                    <h3 class="text-3xl font-bold mt-2"><?php echo number_format($stats['accepted_sessions'] ?? 0); ?></h3>
                                </div>
                                <div class="p-3 rounded-full bg-white/20">
                                    <i class="fas fa-check-circle text-xl"></i>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white/10 px-5 py-3">
                            <a href="?tab=sessions" class="text-sm font-medium text-white hover:text-white/80">
                                View details →
                            </a>
                        </div>
                    </div>
                    <div class="dashboard-card bg-gradient-to-br from-purple-500 to-pink-600 text-white rounded-xl shadow-md overflow-hidden hover-scale">
                        <div class="p-5">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-white/80 text-sm font-medium">Completed Sessions</p>
                                    <h3 class="text-3xl font-bold mt-2"><?php echo number_format($stats['completed_sessions'] ?? 0); ?></h3>
                                </div>
                                <div class="p-3 rounded-full bg-white/20">
                                    <i class="fas fa-calendar-check text-xl"></i>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white/10 px-5 py-3">
                            <a href="?tab=sessions" class="text-sm font-medium text-white hover:text-white/80">
                                View details →
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Charts and Activity Section -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Session Activity Chart -->
                    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 fade-in">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-lg font-semibold text-gray-800 dark:text-white">Session Activity</h2>
                            <select class="bg-gray-100 dark:bg-gray-700 border-none text-sm rounded-lg px-3 py-1 focus:ring-indigo-500 focus:border-indigo-500">
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
                    
                    <!-- Quick Actions -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 fade-in">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-6">Quick Actions</h2>
                        <div class="space-y-3">
                            <a href="doctors_journals.php" class="flex items-center space-x-3 p-3 gradient-indigo text-white rounded-lg hover-scale">
                                <div class="p-2 bg-white/20 rounded-lg">
                                    <i class="fas fa-book"></i>
                                </div>
                                <span class="font-medium">Doctors Journals</span>
                            </a>
                            <a href="community_posts.php" class="flex items-center space-x-3 p-3 gradient-mint text-white rounded-lg hover-scale">
                                <div class="p-2 bg-white/20 rounded-lg">
                                    <i class="fas fa-comment-dots"></i>
                                </div>
                                <span class="font-medium">Community Posts</span>
                            </a>
                            <a href="daily_overview.php" class="flex items-center space-x-3 p-3 gradient-purple text-white rounded-lg hover-scale">
                                <div class="p-2 bg-white/20 rounded-lg">
                                    <i class="fas fa-calendar-day"></i>
                                </div>
                                <span class="font-medium">Overview of the Day</span>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Recent Activity -->
                    <div class="lg:col-span-3 bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 fade-in">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-6">Recent Activity</h2>
                        <div class="space-y-4">
                            <?php foreach ($recent_activities as $activity): ?>
                                <div class="flex items-start space-x-4">
                                    <div class="p-2 <?php echo htmlspecialchars($activity['bg']); ?> rounded-lg <?php echo htmlspecialchars($activity['text_color']); ?>">
                                        <i class="<?php echo htmlspecialchars($activity['icon']); ?>"></i>
                                    </div>
                                    <div>
                                        <p class="text-gray-800 dark:text-gray-100 font-medium"><?php echo htmlspecialchars($activity['title']); ?></p>
                                        <p class="text-gray-500 dark:text-gray-400 text-sm"><?php echo htmlspecialchars($activity['desc']); ?></p>
                                    </div>
                                    <span class="ml-auto text-gray-400 text-sm"><?php echo htmlspecialchars($activity['time']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Sessions Tab -->
            <?php if ($active_tab == 'sessions'): ?>
            <div class="space-y-6 fade-in">
                <!-- Pending Session Requests -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-6">Pending Session Requests</h2>
                    <?php if (empty($pending_sessions)): ?>
                        <p class="text-gray-600 dark:text-gray-400">No pending session requests at the moment.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-white">
                                        <th class="text-left py-3 px-4 font-semibold">Session ID</th>
                                        <th class="text-left py-3 px-4 font-semibold">Date</th>
                                        <th class="text-left py-3 px-4 font-semibold">Time</th>
                                        <th class="text-left py-3 px-4 font-semibold">Requested</th>
                                        <th class="text-left py-3"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_sessions as $session): ?>
                                        <tr class="border-b hover:bg-gray-50 dark:hover:bg-gray-700">
                                            <td class="py-3 px-4"><?php echo htmlspecialchars($session['id']); ?></td>
                                            <td class="py-3 px-4"><?php echo htmlspecialchars($session['preferred_date']); ?></td>
                                            <td class="py-3 px-4"><?php echo htmlspecialchars(substr($session['preferred_time'], 0, 5)); ?></td>
                                            <td class="py-3 px-4"><?php echo htmlspecialchars($session['created_at'] ?? 'N/A'); ?></td>
                                            <td class="py-3 px-4 space-x-2 flex">
                                                <a href="acceptSession.php?id=<?php echo htmlspecialchars($session['id']); ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" class="gradient-indigo px-3 py-1 rounded-lg text-sm text-white hover-scale">Accept</a>
                                                <a href="declineSession.php?id=<?php echo htmlspecialchars($session['id']); ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" class="px-3 py-1 rounded-lg text-sm text-white hover-scale bg-red-600">Decline</a>
                                                <a href="conduct_session.php?id=<?php echo htmlspecialchars($session['id']); ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" class="gradient-mint px-3 py-1 rounded-lg text-sm text-white hover-scale">Conduct</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Upcoming Sessions -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-6">Upcoming Sessions</h2>
                    <?php if (empty($upcoming_sessions)): ?>
                        <p class="text-gray-600 dark:text-gray-400">No upcoming sessions scheduled.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-white">
                                        <th class="text-left py-3 px-4 font-semibold">Date</th>
                                        <th class="text-left py-3 px-4 font-semibold">Time</th>
                                        <th class="text-left py-3 px-4 font-semibold">Status</th>
                                        <th class="text-left py-3"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcoming_sessions as $session): ?>
                                        <tr class="border-b hover:bg-gray-50 dark:hover:bg-gray-700">
                                            <td class="py-3 px-4"><?php echo htmlspecialchars($session['preferred_date']); ?></td>
                                            <td class="py-3 px-4"><?php echo htmlspecialchars(substr($session['preferred_time'], 0, 5)); ?></td>
                                            <td class="py-3 px-4"><span class="gradient-mint text-white px-3 py-1 rounded-full text-xs">Accepted</span></td>
                                            <td class="py-3 px-4">
                                                <a href="conduct_session.php?id=<?php echo htmlspecialchars($session['id']); ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" class="gradient-mint px-4 py-1 rounded-lg text-sm text-white hover-scale">Conduct</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Completed Sessions -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-6">Recent Completed Sessions</h2>
                    <?php if (empty($completed_sessions)): ?>
                        <p class="text-gray-600 dark:text-gray-400">No completed sessions.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-white">
                                        <th class="text-left py-3 px-4 font-semibold">Date</th>
                                        <th class="text-left py-3 px-4 font-semibold">Time</th>
                                        <th class="text-left py-3 px-4 font-semibold"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($completed_sessions as $session): ?>
                                        <tr class="border-b hover:bg-gray-50 dark:hover:bg-gray-700">
                                            <td class="py-3 px-4"><?php echo htmlspecialchars($session['preferred_date']); ?></td>
                                            <td class="py-3 px-4"><?php echo htmlspecialchars(substr($session['preferred_time'], 0, 5)); ?></td>
                                            <td class="py-3 px-4"><span class="gradient-indigo text-white px-3 py-1 rounded-full text-xs">Completed</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Circles Tab -->
            <?php if ($active_tab == 'circles'): ?>
            <div class="space-y-6 fade-in">
                <!-- My Circles -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-6">My Circles</h2>
                    <?php if (empty($circles)): ?>
                        <p class="text-gray-600 dark:text-gray-400">You are not leading any circles at the moment.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-white">
                                        <th class="text-left py-3 px-4 font-semibold">Circle</th>
                                        <th class="text-left py-3 px-4 font-semibold">Category</th>
                                        <th class="text-left py-3 px-4 font-semibold">Members</th>
                                        <th class="text-left py-3 px-4 font-semibold">Schedule</th>
                                        <th class="text-left py-3 px-4 font-semibold">Status</th>
                                        <th class="text-left py-3"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($circles as $circle): ?>
                                        <tr class="border-b hover:bg-gray-50 dark:hover:bg-gray-700">
                                            <td class="py-3 px-4">
                                                <div class="text-sm font-medium text-gray-800 dark:text-white"><?php echo htmlspecialchars($circle['title']); ?></div>
                                                <div class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars(substr($circle['description'], 0, 50)); ?>...</div>
                                            </td>
                                            <td class="py-3 px-4"><?php echo htmlspecialchars(ucfirst(str_replace('-', ' ', $circle['category']))); ?></td>
                                            <td class="py-3 px-4"><?php echo htmlspecialchars($circle['member_count'] . '/' . $circle['max_members']); ?></td>
                                            <td class="py-3 px-4"><?php echo htmlspecialchars(ucfirst($circle['meeting_day']) . ', ' . date('h:i A', strtotime($circle['meeting_time']))); ?></td>
                                            <td class="py-3 px-4">
                                                <span class="px-3 py-1 rounded-full text-xs <?php echo $circle['status'] === 'active' ? 'gradient-mint text-white' : 'bg-red-100 text-red-600'; ?>">
                                                    <?php echo htmlspecialchars(ucfirst($circle['status'])); ?>
                                                </span>
                                            </td>
                                            <td class="py-3 px-4">
                                                <a href="conduct_circle.php?circle_id=<?php echo htmlspecialchars($circle['id']); ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" class="gradient-purple px-4 py-1 rounded-lg text-sm text-white hover-scale">Conduct</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Profile Tab -->
            <?php if ($active_tab == 'profile'): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 max-w-2xl fade-in">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-6">Profile Settings</h2>
                <form method="POST" action="consultantProfile.php" class="space-y-5">
                    <div>
                        <label for="first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">First Name</label>
                        <input type="text" id="first_name" name="first_name" class="w-full mt-1 p-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-gray-700 text-gray-800 dark:text-white" value="<?php echo htmlspecialchars($consultant['first_name']); ?>" required>
                    </div>
                    <div>
                        <label for="last_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Last Name</label>
                        <input type="text" id="last_name" name="last_name" class="w-full mt-1 p-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-gray-700 text-gray-800 dark:text-white" value="<?php echo htmlspecialchars($consultant['last_name']); ?>" required>
                    </div>
                    <div>
                        <label for="specialization" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Specialization</label>
                        <input type="text" id="specialization" name="specialization" class="w-full mt-1 p-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-gray-700 text-gray-800 dark:text-white" value="<?php echo htmlspecialchars($consultant['specialization'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="bio" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Bio</label>
                        <textarea id="bio" name="bio" class="w-full mt-1 p-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-gray-700 text-gray-800 dark:text-white h-32"><?php echo htmlspecialchars($consultant['bio'] ?? ''); ?></textarea>
                    </div>
                    <div>
                        <label for="is_available" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Availability</label>
                        <select id="is_available" name="is_available" class="w-full mt-1 p-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-gray-700 text-gray-800 dark:text-white">
                            <option value="1" <?php echo $consultant['is_available'] ? 'selected' : ''; ?>>Available</option>
                            <option value="0" <?php echo !$consultant['is_available'] ? 'selected' : ''; ?>>Unavailable</option>
                        </select>
                    </div>
                    <button type="submit" class="gradient-indigo px-6 py-2 rounded-lg text-sm text-white hover-scale">Save Changes</button>
                </form>
            </div>
            <?php endif; ?>
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
            const icon = darkModeToggle.querySelector('i');
            if (icon.classList.contains('fa-moon')) {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
            }
        });
        
        // Initialize chart
        document.addEventListener('DOMContentLoaded', () => {
            const sessionCtx = document.getElementById('sessionChart')?.getContext('2d');
            if (sessionCtx) {
                new Chart(sessionCtx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($session_dates); ?>,
                        datasets: [{
                            label: 'Sessions',
                            data: <?php echo json_encode($session_counts); ?>,
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
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.05)' } },
                            x: { grid: { display: false } }
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>