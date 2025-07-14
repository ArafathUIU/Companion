<?php
session_start();
require_once 'config/db.php';

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/logs/php_error.log'); // Update path as needed
error_reporting(E_ALL);

// Redirect if not logged in as consultant
if (!isset($_SESSION['consultant_id'])) {
    header("Location: consultantLogin.php");
    exit;
}

$consultant_id = $_SESSION['consultant_id'];

// Validate session_id instead of circle_id for individual sessions
$session_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$session_id) {
    die("Invalid session ID.");
}

// Fetch session details and verify consultant access
try {
    $stmt = $pdo->prepare("
        SELECT acb.id, acb.user_id, acb.preferred_date, acb.preferred_time, acb.status,
               u.gender, u.age
        FROM anonymous_counselling_bookings acb
        LEFT JOIN users u ON acb.user_id = u.id
        WHERE acb.id = ? AND acb.consultant_id = ? AND acb.status = 'accepted'
    ");
    $stmt->execute([$session_id, $consultant_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        die("Session not found or you are not authorized to conduct this session.");
    }
} catch (PDOException $e) {
    error_log("Error fetching session: " . $e->getMessage());
    die("Error fetching session.");
}

// Fetch accepted sessions for display
try {
    $stmt = $pdo->prepare("
        SELECT acb.id, acb.user_id, acb.preferred_date, acb.preferred_time
        FROM anonymous_counselling_bookings acb
        LEFT JOIN users u ON acb.user_id = u.id
        WHERE acb.consultant_id = ? AND acb.status = 'accepted'
        ORDER BY acb.preferred_date, acb.preferred_time
    ");
    $stmt->execute([$consultant_id]);
    $accepted_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $accepted_sessions = [];
    error_log("Error fetching accepted sessions: " . $e->getMessage());
}

// Function to calculate time remaining
function time_remaining($preferred_date, $preferred_time) {
    try {
        $session_datetime = new DateTime("$preferred_date $preferred_time");
        $now = new DateTime();
        if ($session_datetime <= $now) {
            return "In progress";
        }
        $interval = $now->diff($session_datetime);
        $parts = [];
        if ($interval->d > 0) {
            $parts[] = $interval->d . " day" . ($interval->d > 1 ? "s" : "");
        }
        if ($interval->h > 0) {
            $parts[] = $interval->h . " hour" . ($interval->h > 1 ? "s" : "");
        }
        if ($interval->i > 0) {
            $parts[] = $interval->i . " minute" . ($interval->i > 1 ? "s" : "");
        }
        return !empty($parts) ? implode(", ", $parts) : "Less than a minute";
    } catch (Exception $e) {
        error_log("Error calculating time remaining: " . $e->getMessage());
        return "Unknown";
    }
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Format date for display
function format_date($datetime, $type = 'request') {
    if (empty($datetime)) return "Unknown";
    $date = new DateTime($datetime);
    $now = new DateTime();
    if ($type === 'message') {
        if ($date->format('Y-m-d') === $now->format('Y-m-d')) {
            return 'Today, ' . $date->format('h:i A');
        }
        return $date->format('F j, Y, h:i A');
    }
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
    <title>CompanionX - Conduct Session</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 50%, #80deea 100%);
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
            100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
        }
        .pulse {
            animation: pulse 2s infinite;
        }
        @keyframes typing {
            0% { opacity: 0.5; transform: translateY(0px); }
            50% { opacity: 1; transform: translateY(-5px); }
            100% { opacity: 0.5; transform: translateY(0px); }
        }
        .typing-dot {
            animation: typing 1.5s infinite ease-in-out;
        }
        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }
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
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                z-index: 50;
                height: 100vh;
                width: 80%;
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
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen flex flex-col">
    <!-- Mobile Menu Button -->
    <div class="lg:hidden fixed top-4 left-4 z-50">
        <button id="mobileMenuButton" class="p-2 rounded-lg gradient-indigo text-white shadow-md">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="sidebar-overlay"></div>

    <!-- Header -->
    <header class="bg-gradient-to-r from-indigo-600 to-blue-600 text-white p-4 shadow-md">
        <div class="container mx-auto flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <i class="fas fa-calendar-alt text-2xl"></i>
                <h1 class="text-xl font-bold">Conduct Session #<?php echo htmlspecialchars($session['id']); ?></h1>
                <span class="bg-indigo-800 text-xs px-2 py-1 rounded-full"><?php echo htmlspecialchars(ucfirst($session['status'])); ?></span>
            </div>
            <div class="flex items-center space-x-4">
                <a href="consultantDashboard.php" class="p-2 rounded-full hover:bg-indigo-700 transition">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <button class="p-2 rounded-full hover:bg-indigo-700 transition">
                    <i class="fas fa-cog"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 container mx-auto flex flex-col p-4 gap-4 fade-in">
        <!-- Accepted Sessions Section -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-6">Accepted Sessions</h2>
            <?php if (empty($accepted_sessions)): ?>
                <p class="text-gray-600">No accepted sessions at the moment.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gray-100 text-gray-800">
                                <th class="text-left py-3 px-4 font-semibold">Session ID</th>
                                <th class="text-left py-3 px-4 font-semibold">User ID</th>
                                <th class="text-left py-3 px-4 font-semibold">Time Remaining</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accepted_sessions as $accepted_session): ?>
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="py-3 px-4"><?php echo htmlspecialchars($accepted_session['id']); ?></td>
                                    <td class="py-3 px-4"><?php echo htmlspecialchars($accepted_session['user_id'] ?? 'N/A'); ?></td>
                                    <td class="py-3 px-4"><?php echo htmlspecialchars(time_remaining($accepted_session['preferred_date'], $accepted_session['preferred_time'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Session Details Panel -->
        <aside id="sidebar" class="sidebar w-full md:w-64 bg-white rounded-lg shadow-md p-4 h-fit transition-all duration-300">
            <div class="flex justify-between items-center mb-4">
                <h2 class="font-semibold text-gray-700">Session Details</h2>
                <button class="text-indigo-600 hover:text-indigo-800">
                    <i class="fas fa-info-circle"></i>
                </button>
            </div>
            
            <!-- Session Info -->
            <div class="mb-6">
                <div class="flex items-center space-x-3 mb-2">
                    <div class="relative">
                        <img src="https://placehold.co/40" alt="Consultant" class="w-10 h-10 rounded-full border-2 border-indigo-500">
                        <span class="absolute -bottom-1 -right-1 bg-indigo-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center pulse">
                            <i class="fas fa-star"></i>
                        </span>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-800">Session #<?php echo htmlspecialchars($session['id']); ?></p>
                        <p class="text-xs text-gray-500">Individual Counselling</p>
                    </div>
                </div>
                <p class="text-sm text-gray-600">User ID: <?php echo htmlspecialchars($session['user_id'] ?? 'N/A'); ?></p>
                <p class="text-sm text-gray-600">Gender: <?php echo htmlspecialchars($session['gender'] ?? 'N/A'); ?></p>
                <p class="text-sm text-gray-600">Age: <?php echo htmlspecialchars($session['age'] ?? 'N/A'); ?></p>
                <p class="text-sm text-gray-600">Scheduled: <?php echo htmlspecialchars($session['preferred_date'] . ' ' . substr($session['preferred_time'], 0, 5)); ?></p>
                <p class="text-sm text-gray-600">Time Remaining: <?php echo htmlspecialchars(time_remaining($session['preferred_date'], $session['preferred_time'])); ?></p>
            </div>
        </aside>

        <!-- Chat Area -->
        <div class="flex-1 flex flex-col bg-white rounded-lg shadow-md overflow-hidden">
            <!-- Chat Header -->
            <div class="border-b p-4 flex justify-between items-center bg-gray-50">
                <div>
                    <h2 class="font-semibold text-gray-800">Session #<?php echo htmlspecialchars($session['id']); ?> Chat</h2>
                    <p class="text-xs text-gray-500">With User ID: <?php echo htmlspecialchars($session['user_id'] ?? 'N/A'); ?></p>
                </div>
                <div class="flex space-x-2">
                    <button class="p-2 text-gray-500 hover:text-indigo-600 hover:bg-gray-100 rounded-full transition">
                        <i class="fas fa-phone-alt"></i>
                    </button>
                    <button class="p-2 text-gray-500 hover:text-indigo-600 hover:bg-gray-100 rounded-full transition">
                        <i class="fas fa-video"></i>
                    </button>
                    <button class="p-2 text-gray-500 hover:text-indigo-600 hover:bg-gray-100 rounded-full transition">
                        <i class="fas fa-ellipsis-h"></i>
                    </button>
                </div>
            </div>
            
            <!-- Messages -->
            <div id="chatMessages" class="flex-1 p-4 overflow-y-auto custom-scrollbar space-y-4" style="min-height: 400px;">
                <p class="text-center text-gray-500">Chat functionality for individual sessions is not implemented in this view.</p>
            </div>
            
            <!-- Message Input -->
            <div class="border-t p-4 bg-gray-50">
                <p class="text-gray-500 text-sm">Messaging is disabled for this session view.</p>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t p-3 text-center text-xs text-gray-500">
        <p>CompanionX Session - Secure & Confidential Counselling</p>
    </footer>

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

        // Auto-scroll chat to bottom
        const chatMessages = document.getElementById('chatMessages');
        chatMessages.scrollTop = chatMessages.scrollHeight;
    </script>
</body>
</html>