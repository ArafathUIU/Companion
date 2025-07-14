<?php
session_start();
require_once 'config/db.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: adminLogin.php");
    exit;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = "";
$errors = [];

// Fetch all circles with member count and lead consultant
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.title,
            c.description,
            c.category,
            c.meeting_day,
            c.meeting_time,
            c.max_members,
            c.status,
            con.first_name AS lead_first_name,
            con.last_name AS lead_last_name,
            (SELECT COUNT(*) FROM circle_members cm WHERE cm.circle_id = c.id AND cm.status = 'approved') AS member_count
        FROM circles c
        JOIN consultants con ON c.lead_consultant_id = con.id
        ORDER BY c.title ASC
    ");
    $stmt->execute();
    $circles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "Error fetching circles: " . htmlspecialchars($e->getMessage());
}

// Fetch all consultants for lead change dropdown
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM consultants ORDER BY first_name ASC");
    $stmt->execute();
    $consultants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "Error fetching consultants: " . htmlspecialchars($e->getMessage());
}

// Fetch sidebar badge data
try {
    $user_count_stmt = $pdo->query("SELECT COUNT(*) AS total_users FROM users");
    $total_users = $user_count_stmt->fetch()['total_users'];

    $post_approval_stmt = $pdo->query("SELECT COUNT(*) AS pending_posts FROM community_posts WHERE is_approved = 0");
    $pending_posts = $post_approval_stmt->fetch()['pending_posts'];
} catch (PDOException $e) {
    $errors[] = "Error fetching badge data: " . htmlspecialchars($e->getMessage());
}

// Handle lead consultant change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_lead') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $circle_id = filter_input(INPUT_POST, 'circle_id', FILTER_VALIDATE_INT);
        $new_lead_id = filter_input(INPUT_POST, 'new_lead_id', FILTER_VALIDATE_INT);

        if (!$circle_id) {
            $errors[] = "Invalid circle ID.";
        }
        if (!$new_lead_id) {
            $errors[] = "Please select a valid consultant.";
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("UPDATE circles SET lead_consultant_id = :new_lead_id WHERE id = :circle_id");
                $stmt->execute([
                    ':new_lead_id' => $new_lead_id,
                    ':circle_id' => $circle_id
                ]);
                if ($stmt->rowCount() > 0) {
                    $success = "Lead consultant updated successfully!";
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate CSRF token
                } else {
                    $errors[] = "No changes made to the circle.";
                }
            } catch (PDOException $e) {
                $errors[] = "Failed to update lead consultant: " . htmlspecialchars($e->getMessage());
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
    <title>Manage Circles | Companion</title>
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
                            <span class="ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full"><?php echo htmlspecialchars($total_users); ?></span>
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
                            <span class="ml-auto bg-yellow-500 text-white text-xs px-2 py-1 rounded-full"><?php echo htmlspecialchars($pending_posts); ?></span>
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
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white">Manage Circles</h1>
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
            <!-- Circles Table -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 dashboard-card">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white">All Circles</h2>
                    <a href="create_circle.php" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-pastel focus:ring-offset-2">
                        Create New Circle
                    </a>
                </div>
                
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 text-red-700 p-4 rounded-lg mb-6">
                        <ul class="list-disc list-inside">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Circle</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Category</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Lead</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Members</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Schedule</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($circles as $circle): ?>
                                <tr data-circle-id="<?= htmlspecialchars($circle['id']) ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100"><?= htmlspecialchars($circle['title']) ?></div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars(substr($circle['description'], 0, 50)) ?>...</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?= htmlspecialchars(ucfirst(str_replace('-', ' ', $circle['category']))) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <span class="lead-name"><?= htmlspecialchars($circle['lead_first_name'] . ' ' . $circle['lead_last_name']) ?></span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?= htmlspecialchars($circle['member_count']) ?> / <?= htmlspecialchars($circle['max_members']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?= htmlspecialchars(ucfirst($circle['meeting_day'])) ?>, <?= htmlspecialchars(date('h:i A', strtotime($circle['meeting_time']))) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="px-2 py-1 rounded-full text-xs <?= $circle['status'] === 'active' ? 'bg-pastel-mint text-pastel-mint-600' : 'bg-red-100 text-red-600' ?>">
                                            <?= htmlspecialchars(ucfirst($circle['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <a href="circle_chat.php?circle_id=<?= htmlspecialchars($circle['id']) ?>" class="text-pastel-mint hover:text-green-900 mr-4">
                                            <i class="fas fa-comment-alt"></i> Join Chat
                                        </a>
                                        <button class="text-indigo-600 hover:text-indigo-900 change-lead-btn" data-circle-id="<?= htmlspecialchars($circle['id']) ?>">
                                            <i class="fas fa-user-edit"></i> Change Lead
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Change Lead Consultant Modal -->
    <div id="changeLeadModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 modal opacity-0 invisible">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6 transform transition-all duration-300 scale-95">
            <div class="flex justify-between items-start mb-4">
                <h3 class="text-lg font-bold text-gray-800 dark:text-white">Change Lead Consultant</h3>
                <button id="closeChangeLeadModal" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="changeLeadForm" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="change_lead">
                <input type="hidden" name="circle_id" id="circleId">
                
                <div>
                    <label for="new_lead_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Select New Lead Consultant</label>
                    <select id="new_lead_id" name="new_lead_id" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-pastel focus:border-indigo-500 dark:bg-gray-700 dark:text-white" required>
                        <option value="">Select a consultant</option>
                        <?php foreach ($consultants as $c): ?>
                            <option value="<?= htmlspecialchars($c['id']) ?>">
                                <?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancelChangeLead" class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-pastel focus:ring-offset-2">
                        Confirm
                    </button>
                </div>
            </form>
        </div>
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
                <p class="text-gray-600 dark:text-gray-300" id="successMessage"></p>
            </div>
            <div class="flex justify-end">
                <button id="confirmSuccess" class="px-4 py-2 bg-pastel-mint text-pastel-mint-600 rounded-lg hover:bg-green-200 focus:outline-none focus:ring-2 focus:ring-pastel focus:ring-offset-2">
                    OK
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
        
        // Open Change Lead Modal
        document.querySelectorAll('.change-lead-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const circleId = this.getAttribute('data-circle-id');
                document.getElementById('circleId').value = circleId;
                document.getElementById('changeLeadModal').classList.remove('opacity-0', 'invisible');
                document.getElementById('changeLeadModal').classList.add('opacity-100', 'visible');
                document.querySelector('#changeLeadModal > div').classList.remove('scale-95');
                document.querySelector('#changeLeadModal > div').classList.add('scale-100');
            });
        });
        
        // Close Change Lead Modal
        document.getElementById('closeChangeLeadModal').addEventListener('click', closeChangeLeadModal);
        document.getElementById('cancelChangeLead').addEventListener('click', closeChangeLeadModal);
        
        function closeChangeLeadModal() {
            closeModal('changeLeadModal');
            document.getElementById('changeLeadForm').reset();
        }
        
        // Submit Change Lead Form
        document.getElementById('changeLeadForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('manage_circles.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                closeChangeLeadModal();
                if (data.success) {
                    // Update lead name in table
                    const row = document.querySelector(`tr[data-circle-id="${formData.get('circle_id')}"]`);
                    if (row) {
                        const newLeadId = formData.get('new_lead_id');
                        const newLeadOption = document.querySelector(`#new_lead_id option[value="${newLeadId}"]`);
                        if (newLeadOption) {
                            row.querySelector('.lead-name').textContent = newLeadOption.textContent.trim();
                        }
                    }
                    // Show success modal
                    document.getElementById('successMessage').textContent = data.success;
                    document.getElementById('successModal').classList.remove('opacity-0', 'invisible');
                    document.getElementById('successModal').classList.add('opacity-100', 'visible');
                    document.querySelector('#successModal > div').classList.remove('scale-95');
                    document.querySelector('#successModal > div').classList.add('scale-100');
                } else {
                    const alert = document.createElement('div');
                    alert.className = 'bg-red-50 text-red-700 p-4 rounded-lg mb-6 fixed top-20 right-4 z-50';
                    alert.innerHTML = '<ul class="list-disc list-inside">' + 
                        data.errors.map(error => `<li>${error}</li>`).join('') + 
                        '</ul>';
                    document.body.appendChild(alert);
                    setTimeout(() => alert.remove(), 5000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                closeChangeLeadModal();
            });
        });
        
        // Close Success Modal
        document.getElementById('closeSuccessModal').addEventListener('click', closeSuccessModal);
        document.getElementById('confirmSuccess').addEventListener('click', closeSuccessModal);
        
        function closeSuccessModal() {
            closeModal('successModal');
        }
        
        // Helper function to close modals
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