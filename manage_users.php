<?php
session_start();
require_once 'config/db.php';

// Check if admin logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminLogin.php");
    exit;
}

// Fetch total users count
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users");
$stmt->execute();
$total_users = $stmt->fetchColumn();

// Fetch total consultants count
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM consultants");
$stmt->execute();
$total_consultants = $stmt->fetchColumn();

// Calculate User:Consultant Ratio
$user_consultant_ratio = $total_consultants > 0 ? round($total_users / $total_consultants, 1) : 0;

// Gender distribution
$stmt = $pdo->prepare("SELECT gender, COUNT(*) as count FROM users GROUP BY gender");
$stmt->execute();
$gender_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$gender_data = [];
$gender_labels = array_keys($gender_counts);
foreach ($gender_counts as $gender => $count) {
    $gender_data[] = $total_users > 0 ? round(($count / $total_users) * 100, 2) : 0;
}

// Age group distribution
$stmt = $pdo->prepare("SELECT dob FROM users");
$stmt->execute();
$dob_list = $stmt->fetchAll(PDO::FETCH_COLUMN);
$age_groups = [
    'Under 20' => 0,
    '20-30' => 0,
    '31-40' => 0,
    '41 and above' => 0,
];
$today = new DateTime();
foreach ($dob_list as $dob) {
    $birthdate = new DateTime($dob);
    $age = $today->diff($birthdate)->y;
    if ($age < 20) {
        $age_groups['Under 20']++;
    } elseif ($age <= 30) {
        $age_groups['20-30']++;
    } elseif ($age <= 40) {
        $age_groups['31-40']++;
    } else {
        $age_groups['41 and above']++;
    }
}
$age_data = [];
$age_labels = array_keys($age_groups);
foreach ($age_groups as $group => $count) {
    $age_data[] = $total_users > 0 ? round(($count / $total_users) * 100, 2) : 0;
}

// Percentage of users with problems
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE has_completed_questionnaire = 1");
$stmt->execute();
$users_with_problems = $stmt->fetchColumn();
$problem_percentage = $total_users > 0 ? round(($users_with_problems / $total_users) * 100, 2) : 0;

// Placeholder for problem types (simulated data)
$problem_types = [
    'Anxiety' => $total_users * 0.32,
    'Depression' => $total_users * 0.28,
    'Stress' => $total_users * 0.22,
    'Relationships' => $total_users * 0.12,
    'Other' => $total_users * 0.06,
];
$problem_data = [];
$problem_labels = array_keys($problem_types);
foreach ($problem_types as $type => $count) {
    $problem_data[] = $total_users > 0 ? round(($count / $total_users) * 100, 2) : 0;
}

// Fetch badge data for sidebar
try {
    $post_approval_stmt = $pdo->query("SELECT COUNT(*) AS pending_posts FROM community_posts WHERE is_approved = 0");
    $pending_posts = $post_approval_stmt->fetch()['pending_posts'];
} catch (PDOException $e) {
    error_log("Badge query failed: " . $e->getMessage());
    $pending_posts = 0;
}

// Fetch user data for table
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where_clause = $search ? "WHERE email LIKE :search OR id = :id" : "";
try {
    $stmt = $pdo->prepare("SELECT id, email, gender, dob, has_completed_questionnaire, created_at FROM users $where_clause ORDER BY created_at DESC");
    if ($search) {
        $search_param = "%$search%";
        $stmt->bindValue(':search', $search_param);
        if (is_numeric($search)) {
            $stmt->bindValue(':id', (int)$search);
        } else {
            $stmt->bindValue(':id', 0);
        }
    }
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User query failed: " . $e->getMessage());
    $users = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users | CompanionX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
        .dark-toggle {
            transition: all 0.3s ease;
        }
        .dark-toggle:hover {
            transform: rotate(30deg);
        }
        .sidebar-item {
            transition: all 0.2s ease;
        }
        .sidebar-item:hover {
            transform: translateX(5px);
        }
        .dashboard-card {
            transition: all 0.3s ease;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .notification-pulse {
            animation: pulse 2s infinite;
        }
        @media (max-width: 768px) {
            .mobile-menu { display: block; }
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                z-index: 50;
                height: 100vh;
                transition: transform 0.3s ease;
            }
            .sidebar.open { transform: translateX(0); }
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
            .sidebar-overlay.open { display: block; }
        }
    </style>
</head>
<body class="bg-white dark:bg-gray-900 transition-colors duration-300">
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
                <i class="fas fa-brain text-2xl text-blue-400"></i>
                <h1 class="text-xl font-bold">CompanionX</h1>
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
                        <a href="manage_users.php" class="sidebar-item flex items-center space-x-3 p-3 bg-blue-600 rounded-lg">
                            <i class="fas fa-users w-5 text-center"></i>
                            <span>Manage Users</span>
                            <span class="ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full"><?php echo htmlspecialchars($total_users); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="list_consultants.php" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-gray-700 rounded-lg">
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
                            <span class="ml-auto bg-yellow-500 text-white text-xs px-2 py-1 rounded-full"><?php echo htmlspecialchars($pending_posts); ?></span>
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
                <p>CompanionX Admin</p>
            </div>
        </div>
    </aside>
    
    <!-- Main Content -->
    <div class="ml-0 lg:ml-64 transition-all duration-300">
        <!-- Top Navigation -->
        <header class="bg-white dark:bg-gray-800 shadow-sm">
            <div class="flex justify-between items-center p-4">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white">Manage Users</h1>
                <div class="flex items-center space-x-4">
                    <button class="p-2 rounded-full hover:bg-gray-200 dark:hover:bg-gray-700 relative">
                        <i class="fas fa-bell text-gray-600 dark:text-gray-300"></i>
                        <?php if ($pending_posts > 0): ?>
                            <span class="absolute top-0 right-0 h-3 w-3 bg-red-500 rounded-full notification-pulse"><?php echo htmlspecialchars($pending_posts); ?></span>
                        <?php endif; ?>
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
        
        <main class="p-6">
            <!-- Search Bar -->
            <div class="mb-6">
                <form method="GET" class="flex items-center space-x-4">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by email or user ID" class="w-full max-w-md px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-400">
                        <i class="fas fa-search mr-2"></i>Search
                    </button>
                </form>
            </div>
            
            <!-- Stats Overview Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="dashboard-card bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                    <div class="flex items-center space-x-4">
                        <div class="p-3 bg-blue-100 dark:bg-blue-900/50 rounded-full text-blue-600 dark:text-blue-300">
                            <i class="fas fa-users text-xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm font-medium">Total Users</p>
                            <h3 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo number_format($total_users); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="dashboard-card bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                    <div class="flex items-center space-x-4">
                        <div class="p-3 bg-yellow-100 dark:bg-yellow-900/50 rounded-full text-yellow-600 dark:text-yellow-300">
                            <i class="fas fa-user-graduate text-xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm font-medium">User:Consultant Ratio</p>
                            <h3 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $user_consultant_ratio; ?>:1</h3>
                        </div>
                    </div>
                </div>
                <div class="dashboard-card bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                    <div class="flex items-center space-x-4">
                        <div class="p-3 bg-green-100 dark:bg-green-900/50 rounded-full text-green-600 dark:text-green-300">
                            <i class="fas fa-brain text-xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm font-medium">Users with Concerns</p>
                            <h3 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $problem_percentage; ?>%</h3>
                        </div>
                    </div>
                </div>
                <div class="dashboard-card bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                    <div class="flex items-center space-x-4">
                        <div class="p-3 bg-purple-100 dark:bg-purple-900/50 rounded-full text-purple-600 dark:text-purple-300">
                            <i class="fas fa-users-cog text-xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm font-medium">Active Consultants</p>
                            <h3 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo number_format($total_consultants); ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- User Management Table 
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 mb-8">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white">User List</h2>
                   <!-- <a href="create_user.php" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-blue-400">
                        Add New User
                    </a>
                </div>
                <?php if (empty($users)): ?>
                    <p class="text-gray-500 dark:text-gray-400">No users found.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-gray-100 dark:bg-gray-700">
                                    <th class="p-3 text-white">ID</th>
                                    <th class="p-3 text-white">Email</th>
                                    <th class="p-3 text-white">Gender</th>
                                    <th class="p-3 text-white">Age</th>
                                    <th class="p-3 text-white">Has Concerns</th>
                                    <th class="p-3 text-white">Joined</th>
                                    <th class="p-3 text-white">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <?php
                                    $birthdate = new DateTime($user['dob']);
                                    $age = $today->diff($birthdate)->y;
                                    ?>
                                    <tr class="border-b dark:border-gray-700">
                                        <td class="p-3 text-white"><?php echo htmlspecialchars($user['id']); ?></td>
                                        <td class="p-3 text-white"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td class="p-3 text-white"><?php echo htmlspecialchars($user['gender'] ?? 'N/A'); ?></td>
                                        <td class="p-3 text-white"><?php echo $age; ?></td>
                                        <td class="p-3 text-white">
                                            <span class="px-2 py-1 rounded-full text-xs <?php echo $user['has_completed_questionnaire'] ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                                                <?php echo $user['has_completed_questionnaire'] ? 'Yes' : 'No'; ?>
                                            </span>
                                        </td>
                                        <td class="p-3 text-white"><?php echo htmlspecialchars(date('Y-m-d', strtotime($user['created_at']))); ?></td>
                                        <td class="p-3">
                                            <a href="view_user.php?id=<?php echo $user['id']; ?>" class="text-blue-600 hover:text-blue-500"><i class="fas fa-eye"></i></a>
                                            <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="text-green-600 hover:text-green-500 ml-3"><i class="fas fa-edit"></i></a>
                                            <a href="delete_user.php?id=<?php echo $user['id']; ?>" class="text-red-600 hover:text-red-500 ml-3" onclick="return confirm('Are you sure you want to delete this user?');"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>-->
            
            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Gender Distribution -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 dashboard-card">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">
                        <i class="fas fa-venus-mars text-pink-500 mr-2"></i>User Distribution by Gender
                    </h2>
                    <div class="h-[300px]">
                        <canvas id="genderChart"></canvas>
                    </div>
                </div>
                
                <!-- Age Distribution -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 dashboard-card">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">
                        <i class="fas fa-user-clock text-blue-500 mr-2"></i>Age Distribution
                    </h2>
                    <div class="h-[300px]">
                        <canvas id="ageChart"></canvas>
                    </div>
                </div>
                
                <!-- Problems Distribution -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 lg:col-span-2 dashboard-card">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">
                        <i class="fas fa-brain text-purple-500 mr-2"></i>Mental Health Concerns
                    </h2>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="h-[300px]">
                            <canvas id="problemsChart"></canvas>
                        </div>
                        <div class="flex flex-col justify-center">
                            <div class="space-y-4">
                                <?php foreach ($problem_types as $type => $count): ?>
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($type); ?></span>
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?php echo $total_users > 0 ? round(($count / $total_users) * 100, 2) : 0; ?>%</span>
                                        </div>
                                        <div class="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-2.5">
                                            <div class="bg-<?php echo strtolower($type) === 'anxiety' ? 'red' : (strtolower($type) === 'depression' ? 'blue' : (strtolower($type) === 'stress' ? 'yellow' : (strtolower($type) === 'relationships' ? 'green' : 'purple'))); ?>-500 h-2.5 rounded-full" style="width: <?php echo $total_users > 0 ? round(($count / $total_users) * 100, 2) : 0; ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
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
        
        // Dark mode toggle with localStorage
        const darkModeToggle = document.getElementById('darkModeToggle');
        const html = document.documentElement;
        const savedTheme = localStorage.getItem('theme') || 'light';
        if (savedTheme === 'dark') {
            html.classList.add('dark');
            darkModeToggle.querySelector('i').classList.replace('fa-moon', 'fa-sun');
        }
        
        darkModeToggle.addEventListener('click', () => {
            html.classList.toggle('dark');
            const isDark = html.classList.contains('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            const icon = darkModeToggle.querySelector('i');
            icon.classList.toggle('fa-moon');
            icon.classList.toggle('fa-sun');
        });
        
        // Initialize charts
        const genderCtx = document.getElementById('genderChart').getContext('2d');
        const genderChart = new Chart(genderCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($gender_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($gender_data); ?>,
                    backgroundColor: ['rgba(255, 99, 132, 0.8)', 'rgba(54, 162, 235, 0.8)', 'rgba(153, 162, 235, 0.8)', 'rgba(201, 203, 207, 0.5)'],
                    borderColor: ['rgba(255, 99, 132, 1)', 'rgba(54, 162, 235, 1)', 'rgba(153, 204, 255, 1)', 'rgba(201, 203, 207, 1)'],
                    borderWidth: 1
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right' },
                    tooltip: { callbacks: { label: function(context) { return `${context.label}: ${context.raw}%`; } } }
                },
                cutout: '65%',
            },
        });

        const ageCtx = document.getElementById('ageChart').getContext('2d');
        const ageChart = new Chart(ageCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($age_labels); ?>,
                datasets: [{
                    label: 'Users by Age Group',
                    data: <?php echo json_encode($age_data); ?>,
                    backgroundColor: 'rgba(75, 192, 192, 0.6)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function(value) { return value + '%'; } } },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(context) { return `${context.raw}% of total users`; } } }
                },
            },
        });

        const problemsCtx = document.getElementById('problemsChart').getContext('2d');
        const problemsChart = new Chart(problemsCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode($problem_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($problem_data); ?>,
                    backgroundColor: ['rgba(255, 99, 132, 0.8)', 'rgba(54, 162, 235, 0.8)', 'rgba(255, 206, 86, 0.8)', 'rgba(75, 192, 192, 0.8)', 'rgba(153, 102, 255, 0.8)'],
                    borderColor: ['rgba(255, 99, 132, 1)', 'rgba(54, 162, 235, 1)', 'rgba(255, 206, 86, 1)', 'rgba(75, 192, 192, 1)', 'rgba(153, 102, 255, 1)'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right' },
                    tooltip: { callbacks: { label: function(context) { return `${context.label}: ${context.raw}%`; } } }
                }
            }
        });
    </script>
</body>
</html>