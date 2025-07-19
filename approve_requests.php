<?php
session_start();
require_once 'config/db.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: AdminLogin.php');
    exit;
}

$admin_id = $_SESSION['admin_id'];
try {
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();
    if (!$admin) {
        session_destroy();
        header('Location: AdminLogin.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error checking admin: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Handle approve/decline actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['type'], $data['id'], $data['action'], $data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    try {
        if ($data['type'] === 'post') {
            if ($data['action'] === 'approve') {
                $stmt = $pdo->prepare("UPDATE community_posts SET is_approved = 1 WHERE id = ? AND is_approved = 0");
                $stmt->execute([$data['id']]);
            } elseif ($data['action'] === 'decline') {
                $stmt = $pdo->prepare("DELETE FROM community_posts WHERE id = ? AND is_approved = 0");
                $stmt->execute([$data['id']]);
            }
        } elseif ($data['type'] === 'circle') {
            $stmt = $pdo->prepare("UPDATE circle_members SET status = ? WHERE id = ? AND status = 'pending'");
            $status = $data['action'] === 'approve' ? 'approved' : 'rejected';
            $stmt->execute([$status, $data['id']]);
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => ucfirst($data['action']) . 'd successfully']);
        exit;
    } catch (PDOException $e) {
        error_log("Error updating request: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Fetch community post requests (only unapproved)
try {
    $stmt = $pdo->prepare("
        SELECT cp.id, cp.title, cp.content, cp.is_anonymous, cp.created_at, u.first_name, u.last_name
        FROM community_posts cp
        JOIN users u ON cp.user_id = u.id
        WHERE cp.is_approved = 0
        ORDER BY cp.created_at DESC
    ");
    $stmt->execute();
    $post_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching post requests: " . $e->getMessage());
    $post_requests = [];
}

// Fetch circle join requests
try {
    $stmt = $pdo->prepare("
        SELECT cm.id, cm.status, cm.requested_at, c.title as circle_title, u.first_name, u.last_name
        FROM circle_members cm
        JOIN circles c ON cm.circle_id = c.id
        JOIN users u ON cm.user_id = u.id
        WHERE cm.status IN ('pending', 'approved')
        ORDER BY cm.requested_at DESC
    ");
    $stmt->execute();
    $circle_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching circle requests: " . $e->getMessage());
    $circle_requests = [];
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Format date
function format_date($datetime) {
    if (empty($datetime)) return "Unknown";
    $date = new DateTime($datetime, new DateTimeZone('Asia/Dhaka'));
    $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
    $interval = $now->diff($date);
    if ($interval->days == 0) return "Today";
    if ($interval->days == 1) return "Yesterday";
    if ($interval->days < 7) return $interval->days . " days ago";
    return $date->format('F j, Y');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Requests | CompanionX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f0f8ff;
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 50%, #80deea 100%);
        }
        
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
            box-shadow: 0 10px 15px -5px rgba(0, 0, 0, 0.1);
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

        .tab-active {
            border-bottom: 3px solid #3b82f6;
            color: #3b82f6;
            font-weight: 600;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .notification {
            animation: fadeIn 0.3s ease-in-out;
        }
        
        .selected-blue {
            background-color: #3b82f6 !important;
            color: white !important;
        }
    </style>
</head>
<body class="min-h-screen gradient-bg">
    <!-- Mobile Menu Button -->
    <div class="lg:hidden fixed top-4 left-4 z-50">
        <button id="mobileMenuButton" class="p-2 rounded-lg bg-blue-500 text-white shadow-md">
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
                        <a href="index.php" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-gray-700 rounded-lg">
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
                        <a href="approve_requests.php" class="sidebar-item flex items-center space-x-3 p-3 bg-blue-600 rounded-lg">
                            <i class="fas fa-check-circle w-5 text-center"></i>
                            <span>Approve Requests</span>
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
        <header class="bg-white shadow-sm">
            <div class="flex justify-between items-center p-4">
                <h1 class="text-2xl font-bold text-gray-800">Approve Requests</h1>
                <div class="flex items-center space-x-4">
                    <button class="p-2 rounded-full hover:bg-gray-200 relative">
                        <i class="fas fa-bell text-gray-600"></i>
                        <span class="absolute top-0 right-0 h-3 w-3 bg-red-500 rounded-full notification-pulse"></span>
                    </button>
                    <button class="p-2 rounded-full hover:bg-gray-200">
                        <i class="fas fa-envelope text-gray-600"></i>
                    </button>
                    <button class="p-2 rounded-full hover:bg-gray-200">
                        <i class="fas fa-question-circle text-gray-600"></i>
                    </button>
                </div>
            </div>
        </header>
        
        <main class="p-4">
            <!-- Notifications -->
            <div id="notifications" class="fixed top-4 right-4 z-50"></div>

            <!-- Tabs -->
            <div class="flex border-b border-gray-200 mb-8">
                <button id="post-tab" class="tab-active px-4 py-2 font-medium text-sm focus:outline-none">
                    Community Posts
                </button>
                <button id="circle-tab" class="px-4 py-2 font-medium text-sm text-gray-500 hover:text-gray-700 focus:outline-none">
                    Circle Joins
                </button>
            </div>

            <!-- Community Posts Section -->
            <section id="post-requests" class="mb-12">
                <h2 class="text-lg font-semibold text-gray-800 mb-6">Community Post Requests</h2>
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <?php if (empty($post_requests)): ?>
                        <p class="text-gray-600 text-center p-6">No pending post requests found.</p>
                    <?php else: ?>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Post Title</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Content</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Anonymous</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($post_requests as $request): ?>
                                    <tr class="request-row" data-id="<?php echo $request['id']; ?>" data-type="post">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($request['title'] ?? 'No Title'); ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <?php echo htmlspecialchars(substr($request['content'], 0, 100)) . (strlen($request['content']) > 100 ? '...' : ''); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $request['is_anonymous'] ? 'Anonymous' : htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $request['is_anonymous'] ? 'Yes' : 'No'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo format_date($request['created_at']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <button class="approve-btn px-3 py-1 bg-blue-500 text-white rounded-full text-xs font-medium hover:bg-blue-600 selected-blue transition-colors duration-300" data-action="approve">
                                                Approve
                                            </button>
                                            <button class="decline-btn px-3 py-1 bg-red-500 text-white rounded-full text-xs font-medium hover:bg-red-600 transition-colors duration-300 ml-2" data-action="decline">
                                                Decline
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Circle Join Requests Section (Hidden by default) -->
            <section id="circle-requests" class="hidden mb-12">
                <h2 class="text-lg font-semibold text-gray-800 mb-6">Circle Join Requests</h2>
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <?php if (empty($circle_requests)): ?>
                        <p class="text-gray-600 text-center p-6">No circle join requests found.</p>
                    <?php else: ?>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Circle</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($circle_requests as $request): ?>
                                    <tr class="request-row" data-id="<?php echo $request['id']; ?>" data-type="circle">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($request['circle_title']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $request['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : ($request['status'] === 'approved' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'); ?>">
                                                <?php echo ucfirst($request['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo format_date($request['requested_at']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?php if ($request['status'] === 'pending'): ?>
                                                <button class="approve-btn px-3 py-1 bg-blue-500 text-white rounded-full text-xs font-medium hover:bg-blue-600 selected-blue transition-colors duration-300" data-action="approve">
                                                    Approve
                                                </button>
                                                <button class="decline-btn px-3 py-1 bg-red-500 text-white rounded-full text-xs font-medium hover:bg-red-600 transition-colors duration-300 ml-2" data-action="decline">
                                                    Decline
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>
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
            icon.classList.toggle('fa-moon');
            icon.classList.toggle('fa-sun');
        });

        // Tab and action functionality
        document.addEventListener('DOMContentLoaded', () => {
            const postTab = document.getElementById('post-tab');
            const circleTab = document.getElementById('circle-tab');
            const postSection = document.getElementById('post-requests');
            const circleSection = document.getElementById('circle-requests');

            // Set initial active tab
            setActiveTab(postTab, [circleTab]);

            // Tab click events
            postTab.addEventListener('click', () => {
                setActiveTab(postTab, [circleTab]);
                postSection.classList.remove('hidden');
                circleSection.classList.add('hidden');
            });

            circleTab.addEventListener('click', () => {
                setActiveTab(circleTab, [postTab]);
                postSection.classList.add('hidden');
                circleSection.classList.remove('hidden');
            });

            // Function to set active tab
            function setActiveTab(activeTab, inactiveTabs) {
                activeTab.classList.add('tab-active', 'text-blue-500');
                activeTab.classList.remove('text-gray-500');
                
                inactiveTabs.forEach(tab => {
                    tab.classList.remove('tab-active', 'text-blue-500');
                    tab.classList.add('text-gray-500');
                });
            }

            // Approve/Decline functionality
            const actionButtons = document.querySelectorAll('.approve-btn, .decline-btn');
            actionButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const row = this.closest('.request-row');
                    const id = row.dataset.id;
                    const type = row.dataset.type;
                    const action = this.dataset.action;

                    this.innerHTML = `<i class="fas fa-spinner fa-spin mr-1"></i> ${action === 'approve' ? 'Approving' : 'Declining'}...`;
                    this.classList.add('cursor-not-allowed');
                    this.disabled = true;

                    fetch('approve_requests.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            type: type,
                            id: id,
                            action: action,
                            csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>'
                        })
                    })
                    .then(response => {
                        console.log('Action response status:', response.status);
                        return response.text().then(text => ({ status: response.status, text }));
                    })
                    .then(({ status, text }) => {
                        console.log('Raw response:', text);
                        try {
                            const data = JSON.parse(text);
                            if (data.success) {
                                showNotification(`${type === 'post' ? 'Post' : 'Circle'} request ${action}d`, 'success');
                                row.remove(); // Remove row after action
                                if (type === 'post' && document.querySelectorAll('#post-requests .request-row').length === 0) {
                                    document.querySelector('#post-requests .bg-white').innerHTML = '<p class="text-gray-600 text-center p-6">No pending post requests found.</p>';
                                }
                            } else {
                                this.innerHTML = action === 'approve' ? 'Approve' : 'Decline';
                                this.classList.remove('cursor-not-allowed');
                                this.disabled = false;
                                showNotification(data.message || 'Error processing request', 'error');
                            }
                        } catch (e) {
                            console.error('Invalid JSON:', text);
                            this.innerHTML = action === 'approve' ? 'Approve' : 'Decline';
                            this.classList.remove('cursor-not-allowed');
                            this.disabled = false;
                            showNotification('Invalid JSON response: ' + text, 'error');
                        }
                    })
                    .catch(error => {
                        this.innerHTML = action === 'approve' ? 'Approve' : 'Decline';
                        this.classList.remove('cursor-not-allowed');
                        this.disabled = false;
                        showNotification('Network error: ' + error.message, 'error');
                        console.error('Fetch error:', error);
                    });
                });
            });

            // Show notification
            function showNotification(message, type = 'success') {
                const notification = document.createElement('div');
                notification.className = `notification fixed top-4 right-4 px-4 py-2 rounded-lg shadow-lg flex items-center ${type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'}`;
                notification.innerHTML = `
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-2"></i>
                    <span>${message}</span>
                `;
                
                const notificationsContainer = document.getElementById('notifications');
                notificationsContainer.appendChild(notification);
                
                setTimeout(() => {
                    notification.classList.add('opacity-0', 'transition-opacity', 'duration-300');
                    setTimeout(() => notification.remove(), 300);
                }, 3000);
            }
        });
    </script>
</body>
</html>