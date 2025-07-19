<?php
include 'config/db.php';

$user_count_query = "SELECT COUNT(*) AS total_users FROM users";
$stmt = $pdo->query($user_count_query);
$total_users = $stmt->fetch()['total_users'];

$post_approval_query = "SELECT COUNT(*) AS pending_posts FROM community_posts WHERE is_approved = 0";
$stmt = $pdo->query($post_approval_query);
$pending_posts = $stmt->fetch()['pending_posts'];
?>

<aside id="sidebar" class="sidebar fixed top-0 left-0 w-64 h-full bg-gray-800 text-white shadow-xl overflow-y-auto transition-all duration-300 z-30">
    <div class="p-4 flex items-center justify-between border-b border-gray-700">
        <div class="flex items-center space-x-3">
            <i class="fas fa-brain text-2xl text-blue-400"></i>
            <h1 class="text-xl font-bold">Companiox</h1>
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
            <p>Companiox Admin</p>
        </div>
    </div>
</aside>