<?php
session_start();
require_once 'config/db.php'; // Using your PDO setup

// Simulate logged-in user
if (!isset($_SESSION['user_id'])) {
    echo "Please log in first.";
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch active session
$active_session = null;
$stmt = $pdo->prepare("SELECT * FROM video_sessions WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$user_id]);
$active_session = $stmt->fetch();

// Fetch history
$history_stmt = $pdo->prepare("SELECT * FROM video_sessions WHERE user_id = ? AND status = 'expired' ORDER BY created_at DESC");
$history_stmt->execute([$user_id]);
$session_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CompanionX - Conduct Session</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 50%, #80deea 100%);
            position: relative;
            overflow-x: hidden;
            min-height: 100vh;
        }
        .typewriter {
            overflow: hidden;
            border-right: 0.15em solid #00bcd4;
            white-space: nowrap;
            margin: 0 auto;
            letter-spacing: 0.1em;
            animation: typing 3s steps(30, end), blink-caret 0.75s step-end infinite;
        }
        @keyframes typing {
            from { width: 0 }
            to { width: 100% }
        }
        @keyframes blink-caret {
            from, to { border-color: transparent }
            50% { border-color: #00bcd4; }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            0% { opacity: 0; transform: translateY(10px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 8px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #e5e7eb;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #4dd0e1;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #26c6da;
        }
        .petal {
            position: absolute;
            background: url('https://cdn.pixabay.com/photo/2016/04/15/04/02/flower-1330062_1280.png') no-repeat center;
            background-size: contain;
            width: 20px;
            height: 20px;
            opacity: 0.5;
            animation: float 15s infinite;
            z-index: 0;
        }
        @keyframes float {
            0% { transform: translateY(0) rotate(0deg); opacity: 0.5; }
            50% { opacity: 0.8; }
            100% { transform: translateY(100vh) rotate(360deg); opacity: 0.2; }
        }
        .btn-primary {
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .btn-primary:hover {
            transform: scale(1.05);
            background: #26c6da;
        }
        .btn-secondary {
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .btn-secondary:hover {
            transform: scale(1.05);
            background: #e0f7fa;
        }
        .tooltip {
            position: relative;
        }
        .tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #1f2937;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 10;
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
                        },
                        secondary: {
                            50: '#e8f5e9',
                            100: '#c8e6c9',
                            200: '#a5d6a7',
                            300: '#81c784',
                            400: '#66bb6a',
                            500: '#4caf50',
                            600: '#43a047',
                            700: '#388e3c',
                            800: '#2e7d32',
                            900: '#1b5e20',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body>
    <!-- Floating Petals -->
    <script>
        function createPetals() {
            const petalCount = 5;
            for (let i = 0; i < petalCount; i++) {
                const petal = document.createElement('div');
                petal.className = 'petal';
                petal.style.left = Math.random() * 100 + 'vw';
                petal.style.animationDelay = Math.random() * 5 + 's';
                petal.addEventListener('animationend', () => petal.remove());
                document.body.appendChild(petal);
            }
            setInterval(createPetals, 5000);
        }
        document.addEventListener('DOMContentLoaded', createPetals);
    </script>

    <!-- Header -->
    <header class="bg-white shadow-sm fixed top-0 left-0 right-0 z-50">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-primary-500 rounded-full flex items-center justify-center text-white mr-3">
                    <i class="fas fa-brain text-2xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-gray-800">CompanionX</h1>
            </div>
            <nav class="flex items-center space-x-4">
                <a href="mood_journal.php" class="px-4 py-2 text-gray-600 hover:text-primary-500 transition rounded-lg hover:bg-primary-50">
                    <i class="fas fa-heart mr-2"></i> Mood Journal
                </a>
                <a href="community_post.php" class="px-4 py-2 text-gray-600 hover:text-primary-500 transition rounded-lg hover:bg-primary-50">
                    <i class="fas fa-user-secret mr-2"></i> Wall of Whispers
                </a>
                <a href="consultants_near_me.php" class="px-4 py-2 text-gray-600 hover:text-primary-500 transition rounded-lg hover:bg-primary-50">
                    <i class="fas fa-user-md mr-2"></i> Consultants
                </a>
                <a href="profile.php" class="px-4 py-2 text-gray-600 hover:text-primary-500 transition rounded-lg hover:bg-primary-50">
                    <i class="fas fa-user mr-2"></i> Profile
                </a>
                <a href="logout.php" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition">
                    <i class="fas fa-sign-out-alt mr-2"></i> Logout
                </a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto px-4 pt-24 pb-12">
        <!-- Hero Section -->
        <section class="text-center mb-12">
            <h1 class="text-4xl font-bold text-gray-800 mb-4 flex items-center justify-center">
                Conduct Session
                <i class="fas fa-video text-primary-600 ml-2"></i>
            </h1>
            <p class="text-xl text-gray-600 max-w-2xl mx-auto typewriter">Connect with your CompanionX consultant</p>
        </section>

        <!-- Session Container -->
        <div class="max-w-4xl mx-auto bg-white p-8 rounded-2xl shadow-lg space-y-8 border border-primary-100">
            <?php if ($active_session): ?>
                <div class="space-y-6 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-primary-100 rounded-full mb-4">
                        <i class="fas fa-video text-2xl text-primary-600"></i>
                    </div>
                    <h2 class="text-2xl font-semibold text-gray-800">
                        Dr. <?= htmlspecialchars($active_session['consultant_name'] ?? 'Consultant'); ?> is waiting for you
                    </h2>
                    <p class="text-gray-600">Your video session is ready to begin</p>
                    <a href="<?= $active_session['room_link']; ?>" target="_blank" id="join-session" class="btn-primary inline-flex items-center px-8 py-4 bg-primary-500 text-white rounded-xl shadow-md hover:bg-primary-600 transition-all duration-300">
                        <i class="fas fa-sign-in-alt mr-2"></i> Join Session Now
                    </a>
                </div>
            <?php else: ?>
                <div class="text-center py-8 fade-in">
                    <i class="fas fa-video-slash text-5xl text-primary-300 mb-4"></i>
                    <p class="text-gray-600 text-lg">No active video session available right now.</p>
                    <a href="consultants_near_me.php" class="btn-primary inline-flex items-center px-6 py-3 mt-4 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition">
                        <i class="fas fa-user-md mr-2"></i> Book a Session
                    </a>
                </div>
            <?php endif; ?>

            <!-- Privacy Notice -->
            <div class="p-4 bg-primary-50 rounded-lg border border-primary-100 fade-in">
                <div class="flex items-start">
                    <i class="fas fa-info-circle text-primary-500 mt-0.5 mr-2 flex-shrink-0 text-lg"></i>
                    <p class="text-sm text-primary-700">
                        This is an anonymous platform. Your personal information is never shared with consultants.
                        For additional privacy, you may cover your camera or use a virtual background.
                    </p>
                </div>
            </div>

            <!-- History Section -->
            <div id="history" class="hidden mt-8 border-t pt-8 border-primary-100">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-history mr-2 text-primary-600"></i> Your Session History
                </h2>
                <?php if ($session_history): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 custom-scrollbar max-h-[400px] overflow-y-auto">
                        <?php foreach ($session_history as $index => $row): ?>
                            <div class="bg-white border border-primary-100 rounded-lg p-4 shadow-sm hover:shadow-md transition-shadow duration-200 fade-in" style="animation-delay: <?= $index * 0.1 ?>s">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h3 class="font-medium text-gray-800">Session with Dr. <?= htmlspecialchars($row['consultant_name'] ?? 'Consultant'); ?></h3>
                                        <p class="text-sm text-gray-600"><?= date('F j, Y, g:i a', strtotime($row['created_at'])); ?></p>
                                    </div>
                                    <span class="bg-green-100 text-green-800 text-xs px-2 py-1 rounded-full">Completed</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8 fade-in">
                        <i class="fas fa-history text-5xl text-primary-300 mb-4"></i>
                        <p class="text-gray-600 text-lg">No session history found.</p>
                        <a href="consultants_near_me.php" class="btn-primary inline-flex items-center px-6 py-3 mt-4 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition">
                            <i class="fas fa-user-md mr-2"></i> Find a Consultant
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Toggle History Button -->
        <div class="text-center mt-6">
            <button onclick="toggleHistory()" class="btn-secondary px-6 py-3 bg-primary-100 text-primary-700 rounded-lg shadow-md hover:bg-primary-200 transition-all duration-300 flex items-center mx-auto" data-tooltip="View or hide your past sessions">
                <i class="fas fa-history mr-2"></i> <span id="history-btn-text">Show Session History</span>
            </button>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 py-8 mt-12">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-6 md:mb-0">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-primary-500 rounded-full flex items-center justify-center text-white mr-3">
                            <i class="fas fa-brain"></i>
                        </div>
                        <span class="text-xl font-bold text-gray-800">CompanionX</span>
                    </div>
                    <p class="text-gray-500 mt-2">Your trusted mental health companion</p>
                </div>
                <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-8">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Resources</h3>
                        <ul class="space-y-2">
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Articles</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Self-Help Guides</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Therapist Directory</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Company</h3>
                        <ul class="space-y-2">
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">About</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Privacy</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Terms</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Connect</h3>
                        <div class="flex space-x-4">
                            <a href="#" class="text-gray-500 hover:text-primary-500">
                                <i class="fab fa-twitter"></i>
                            </a>
                            <a href="#" class="text-gray-500 hover:text-primary-500">
                                <i class="fab fa-instagram"></i>
                            </a>
                            <a href="#" class="text-gray-500 hover:text-primary-500">
                                <i class="fab fa-facebook"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-8 pt-8 border-t border-gray-200 text-center text-gray-500 text-sm">
                © <?php echo date('Y'); ?> CompanionX. All rights reserved. This platform does not provide medical advice.
            </div>
        </div>
    </footer>

    <script>
        function toggleHistory() {
            const historyDiv = document.getElementById('history');
            const historyBtnText = document.getElementById('history-btn-text');
            historyDiv.classList.toggle('hidden');
            historyBtnText.textContent = historyDiv.classList.contains('hidden') ? 'Show Session History' : 'Hide Session History';
        }

        document.addEventListener('DOMContentLoaded', () => {
            const joinSessionBtn = document.getElementById('join-session');
            if (joinSessionBtn) {
                joinSessionBtn.addEventListener('click', () => {
                    joinSessionBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Joining...';
                    joinSessionBtn.classList.add('pointer-events-none');
                });
            }

            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && document.activeElement !== document.querySelector('a[href*="consultants_near_me"]')) {
                    const joinSessionBtn = document.getElementById('join-session');
                    if (joinSessionBtn) {
                        joinSessionBtn.click();
                    }
                }
                if (e.key.toLowerCase() === 'h') {
                    toggleHistory();
                }
            });
        });
    </script>
</body>
</html> 

