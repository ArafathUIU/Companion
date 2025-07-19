<?php
session_start();
require_once 'config/db.php';

$consultantId = $_SESSION['consultant_id'] ?? 1; // Replace with actual session logic

$full_room_link = "";
$success_message = "";
$error_message = "";

// Generate new room by default
$full_room_link = "https://meet.jit.si/session_" . uniqid();

// Fetch consultant name
try {
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM consultants WHERE id = ?");
    $stmt->execute([$consultantId]);
    $consultant = $stmt->fetch(PDO::FETCH_ASSOC);
    $consultantName = $consultant ? htmlspecialchars($consultant['first_name'] . ' ' . $consultant['last_name']) : 'Consultant';
} catch (PDOException $e) {
    $consultantName = 'Consultant';
    error_log("Error fetching consultant name: " . $e->getMessage());
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['user_id'])) {
    $userId = intval($_POST['user_id']);
    $uniqueRoomLink = "https://meet.jit.si/session_" . uniqid();

    try {
        $stmt = $pdo->prepare("INSERT INTO video_sessions (consultant_id, user_id, room_link, is_active) VALUES (?, ?, ?, 1)");
        $stmt->execute([$consultantId, $userId, $uniqueRoomLink]);
        $full_room_link = $uniqueRoomLink;
        $success_message = "✅ Link successfully sent to User ID: $userId";
    } catch (PDOException $e) {
        $error_message = "❌ Failed to send session link: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conduct Session | Companion</title>
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
            background: #6366f1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #3b82f6;
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

        /* Card Styles */
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Jitsi Container */
        .jitsi-container {
            position: relative;
            padding-bottom: 56.25%; /* 16:9 aspect ratio */
            height: 0;
            overflow: hidden;
            border-radius: 0.5rem;
            background: #f3f4f6;
        }
        .jitsi-container iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: 0;
        }
    </style>
</head>
<body class="bg-gray-50 font-inter">
    <!-- Mobile Menu Button -->
    <div class="lg:hidden fixed top-4 left-4 z-50">
        <button id="mobileMenuButton" class="p-2 rounded-lg gradient-indigo text-white shadow-md">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="sidebar-overlay"></div>
    
    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar fixed top-0 left-0 w-64 h-full bg-indigo-900 text-white shadow-xl overflow-y-auto transition-all duration-300 z-30">
        <div class="p-4 flex items-center justify-between border-b border-indigo-700">
            <div class="flex items-center space-x-3">
                <img src="https://placehold.co/40" alt="Logo" class="rounded-lg">
                <h1 class="text-xl font-bold">Companion</h1>
            </div>
        </div>
        
        <div class="p-4">
            <div class="flex items-center space-x-4 p-3 gradient-indigo rounded-lg mb-6">
                <img src="https://placehold.co/50" alt="Consultant" class="rounded-full border-2 border-white">
                <div>
                    <h2 class="font-semibold"><?= $consultantName ?></h2>
                    <p class="text-indigo-200 text-sm">Consultant</p>
                </div>
            </div>
            
            <nav>
                <h3 class="text-indigo-300 uppercase text-xs font-semibold mb-3">Main</h3>
                <ul class="space-y-2">
                    <li>
                        <a href="consultantDashboard.php" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-indigo-800 rounded-lg">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="consultantDashboard.php?tab=sessions" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-indigo-800 rounded-lg">
                            <i class="fas fa-calendar-alt w-5 text-center"></i>
                            <span>Sessions</span>
                        </a>
                    </li>
                    <li>
                        <a href="conduct_session.php" class="sidebar-item flex items-center space-x-3 p-3 gradient-indigo rounded-lg">
                            <i class="fas fa-video w-5 text-center"></i>
                            <span>Conduct Session</span>
                        </a>
                    </li>
                    <li>
                        <a href="consultantDashboard.php?tab=circles" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-indigo-800 rounded-lg">
                            <i class="fas fa-users w-5 text-center"></i>
                            <span>Circles</span>
                        </a>
                    </li>
                    <li>
                        <a href="consultantDashboard.php?tab=profile" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-indigo-800 rounded-lg">
                            <i class="fas fa-user w-5 text-center"></i>
                            <span>Profile</span>
                        </a>
                    </li>
                    <li>
                        <a href="logout.php" class="sidebar-item flex items-center space-x-3 p-3 hover:bg-indigo-800 rounded-lg">
                            <i class="fas fa-sign-out-alt w-5 text-center"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        
        <div class="p-4 border-t border-indigo-700 mt-auto">
            <div class="text-center text-indigo-300 text-sm">
                <p>Companion Consultant</p>
            </div>
        </div>
    </aside>
    
    <!-- Main Content -->
    <div class="ml-0 lg:ml-64 transition-all duration-300">
        <!-- Top Navigation -->
        <header class="bg-white shadow-sm">
            <div class="flex justify-between items-center p-4">
                <h1 class="text-2xl font-bold text-indigo-700">Video Consultation Room - <?= $consultantName ?></h1>
                <div class="flex items-center space-x-4">
                    <button class="p-2 rounded-full hover:bg-indigo-100 relative">
                        <i class="fas fa-bell text-indigo-600"></i>
                        <span class="absolute top-0 right-0 h-3 w-3 bg-red-500 rounded-full notification-pulse"></span>
                    </button>
                    <button class="p-2 rounded-full hover:bg-indigo-100">
                        <i class="fas fa-envelope text-indigo-600"></i>
                    </button>
                    <button class="p-2 rounded-full hover:bg-indigo-100">
                        <i class="fas fa-question-circle text-indigo-600"></i>
                    </button>
                </div>
            </div>
        </header>
        
        <main class="p-6">
            

            <div class="max-w-6xl mx-auto fade-in">
                <div class="bg-white shadow-lg rounded-xl p-8 space-y-8 card">
                    <h1 class="text-3xl font-bold text-center text-indigo-700 flex items-center justify-center space-x-2">
                        <i class="fas fa-video text-indigo-500"></i>
                        <span>Video Consultation Room</span>
                    </h1>

                    <!-- Room Link Display -->
                    <div>
                        <label class="font-semibold text-lg mb-2 block text-indigo-700 flex items-center space-x-2">
                            <i class="fas fa-link text-indigo-500"></i>
                            <span>Your Video Room Link:</span>
                        </label>
                        <input type="text" value="<?= $full_room_link ?>" readonly class="w-full bg-gray-100 border border-indigo-300 px-4 py-2 rounded text-indigo-800 font-mono focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <!-- Send Link to User -->
                    <div>
                        <h2 class="font-semibold text-lg mb-2 text-indigo-700 flex items-center space-x-2">
                            <i class="fas fa-user text-indigo-500"></i>
                            <span>Send this session link to a User:</span>
                        </h2>
                        <form method="POST" class="flex flex-col sm:flex-row gap-4">
                            <input type="number" name="user_id" placeholder="Enter User ID" required class="flex-1 px-4 py-2 border border-indigo-300 rounded bg-gray-100 text-indigo-800 focus:ring-2 focus:ring-indigo-500">
                            <button type="submit" class="gradient-indigo text-white px-6 py-2 rounded-lg hover-scale flex items-center space-x-2">
                                <i class="fas fa-paper-plane"></i>
                                <span>Send Link</span>
                            </button>
                        </form>

                        <?php if ($success_message): ?>
                            <p class="mt-3 gradient-mint text-white px-4 py-2 rounded-lg font-semibold fade-in"><?= $success_message ?></p>
                        <?php elseif ($error_message): ?>
                            <p class="mt-3 bg-red-500 text-white px-4 py-2 rounded-lg font-semibold fade-in"><?= $error_message ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Embedded Jitsi Room -->
                    <div>
                        <h2 class="font-semibold text-lg mb-3 text-indigo-700 flex items-center space-x-2">
                            <i class="fas fa-play text-indigo-500"></i>
                            <span>Live Jitsi Room</span>
                        </h2>
                        <div class="jitsi-container card">
                            <iframe 
                                src="<?= $full_room_link ?>" 
                                allow="camera; microphone; fullscreen; display-capture" 
                                title="Jitsi Video Session"
                            ></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Toggle mobile sidebar
        const mobileMenuButton = document.querySelector('#mobileMenuButton');
        const sidebar = document.querySelector('#sidebar');
        const sidebarOverlay = document.querySelector('#sidebarOverlay');

        mobileMenuButton.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            sidebarOverlay.classList.toggle('open');
        });

        sidebarOverlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            sidebarOverlay.classList.remove('open');
        });
    </script>
</body>
</html>