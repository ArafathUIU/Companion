<?php
ob_start(); // Start output buffering
session_start();
require_once 'config/db.php'; // Your PDO connection file
require_once 'config/config.php'; // Include config for constants

// Apply session security settings
ini_set('session.cookie_secure', SESSION_COOKIE_SECURE);
ini_set('session.cookie_httponly', SESSION_COOKIE_HTTPONLY);
ini_set('session.cookie_samesite', SESSION_COOKIE_SAMESITE);

// Error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', ERROR_LOG_PATH);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Placeholder user_id — replace with actual logged-in user later
$user_id = $_SESSION['user_id'] ?? 1;
$errors = [];
$success = isset($_GET['success']) && $_GET['success'] == 1 ? "Mood entry saved successfully!" : null;

// Handle mood submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid CSRF token. Please try again.";
    } else {
        $mood_emoji = $_POST['mood_emoji'] ?? '';
        $mood_title = trim($_POST['mood_title'] ?? '');
        $mood_note = trim($_POST['mood_note'] ?? '');

        if (empty($mood_emoji)) {
            $errors[] = "Please select a mood.";
        } else {
            try {
                $sql = "INSERT INTO mood_entries (user_id, mood_emoji, mood_title, mood_note, entry_date) VALUES (:user_id, :mood_emoji, :mood_title, :mood_note, CURDATE())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':user_id' => $user_id,
                    ':mood_emoji' => $mood_emoji,
                    ':mood_title' => $mood_title,
                    ':mood_note' => $mood_note
                ]);
                header("Location: mood_journal.php?success=1");
                exit();
            } catch (PDOException $e) {
                error_log("Database error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
                $errors[] = "Failed to save mood entry. Please try again or contact support with error code: DB-" . time();
            }
        }
    }
}

// Fetch last 30 mood entries for the user
try {
    $sql = "SELECT mood_emoji, mood_title, mood_note, entry_date FROM mood_entries WHERE user_id = :user_id ORDER BY entry_date DESC LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $user_id]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Initialize mood counts for chart
    $chart_data = ['happy' => 0, 'good' => 0, 'neutral' => 0, 'sad' => 0, 'angry' => 0];
    foreach ($entries as $row) {
        if (isset($chart_data[$row['mood_emoji']])) {
            $chart_data[$row['mood_emoji']]++;
        }
    }

    // Prepare recent highlights (last 5 entries)
    $recent_highlights = array_slice($entries, 0, 5);

    // Get unique entry dates for calendar
    $entry_dates = array_unique(array_column($entries, 'entry_date'));
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    $errors[] = "Failed to load mood entries. Please contact support with error code: DB-" . time();
    $entries = [];
    $chart_data = ['happy' => 0, 'good' => 0, 'neutral' => 0, 'sad' => 0, 'angry' => 0];
    $recent_highlights = [];
    $entry_dates = [];
}

// Clean output buffer
ob_end_clean();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CompanionX - Mood Journal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 50%, #80deea 100%);
            position: relative;
            overflow-x: hidden;
        }
        .mood-emoji {
            transition: all 0.2s ease;
        }
        .mood-emoji:hover {
            transform: scale(1.2);
        }
        .mood-selected {
            transform: scale(1.3);
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.3);
            border-radius: 50%;
        }
        #mood_note {
            min-height: 120px;
        }
        .calendar-day {
            transition: all 0.2s ease;
        }
        .calendar-day:hover {
            transform: scale(1.05);
        }
        .calendar-day.has-entry {
            background-color: #e0f7fa;
            border: 2px solid #00bcd4;
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 8px;
        }
        .custom-scrollbar::-EMONOARTIFACT
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
        .error-alert, .success-alert {
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
<body class="min-h-screen">
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
                <a href="dashboard.php" class="px-4 py-2 text-gray-600 hover:text-primary-500 transition rounded-lg hover:bg-primary-50">
                    <i class="fas fa-users mr-2"></i> Dashboard
                </a>
                <a href="anonymous_counselling.php" class="px-4 py-2 text-gray-600 hover:text-primary-500 transition rounded-lg hover:bg-primary-50">
                    <i class="fas fa-user-md mr-2"></i> Counselling
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
    <div class="container mx-auto px-4 pt-24 pb-12">
        <!-- Success/Error Messages -->
        <?php if ($success): ?>
            <div id="success-alert" class="success-alert bg-green-50 text-green-700 p-4 rounded-xl mb-6 flex items-center justify-between max-w-2xl mx-auto">
                <div>
                    <span class="text-sm"><?= htmlspecialchars($success) ?></span>
                </div>
                <button id="dismiss-success" class="text-green-600 hover:text-green-800 transition" aria-label="Dismiss success">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div id="error-alert" class="error-alert bg-red-50 text-red-700 p-4 rounded-xl mb-6 flex items-center justify-between max-w-2xl mx-auto">
                <div>
                    <ul class="list-disc list-inside space-y-2">
                        <?php foreach ($errors as $error): ?>
                            <li class="text-sm"><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <button id="dismiss-error" class="text-red-600 hover:text-red-800 transition" aria-label="Dismiss error">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- Mood Journal Section -->
        <section class="bg-white rounded-2xl shadow-lg overflow-hidden">
            <div class="md:flex">
                <!-- Mood Entry Form -->
                <div class="md:w-1/2 p-6 md:p-8">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-2 flex items-center">
                        How Are You Feeling Today?
                        <i class="fas fa-heart text-primary-600 ml-2"></i>
                    </h2>
                    <p class="text-gray-600 mb-6">Document your emotions to better understand yourself</p>
                    
                    <form id="moodForm" action="mood_journal.php" method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <!-- Mood Selection -->
                        <div class="mb-6">
                            <label class="block text-gray-700 mb-3 text-sm font-medium">Select your mood:</label>
                            <div class="flex justify-between gap-2">
                                <input type="radio" name="mood_emoji" id="happy" value="happy" class="hidden" />
                                <label for="happy" class="mood-emoji cursor-pointer">
                                    <div class="h-14 w-14 rounded-full bg-yellow-100 flex items-center justify-center">
                                        <span class="text-2xl">😄</span>
                                    </div>
                                </label>

                                <input type="radio" name="mood_emoji" id="good" value="good" class="hidden" />
                                <label for="good" class="mood-emoji cursor-pointer">
                                    <div class="h-14 w-14 rounded-full bg-green-100 flex items-center justify-center">
                                        <span class="text-2xl">😊</span>
                                    </div>
                                </label>

                                <input type="radio" name="mood_emoji" id="neutral" value="neutral" class="hidden" />
                                <label for="neutral" class="mood-emoji cursor-pointer">
                                    <div class="h-14 w-14 rounded-full bg-gray-100 flex items-center justify-center">
                                        <span class="text-2xl">😐</span>
                                    </div>
                                </label>

                                <input type="radio" name="mood_emoji" id="sad" value="sad" class="hidden" />
                                <label for="sad" class="mood-emoji cursor-pointer">
                                    <div class="h-14 w-14 rounded-full bg-blue-100 flex items-center justify-center">
                                        <span class="text-2xl">😔</span>
                                    </div>
                                </label>

                                <input type="radio" name="mood_emoji" id="angry" value="angry" class="hidden" />
                                <label for="angry" class="mood-emoji cursor-pointer">
                                    <div class="h-14 w-14 rounded-full bg-red-100 flex items-center justify-center">
                                        <span class="text-2xl">😠</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Mood Title -->
                        <div class="relative">
                            <label for="mood_title" class="block text-gray-700 mb-2 text-sm font-medium">Title (optional)</label>
                            <div class="relative">
                                <i class="fas fa-pen absolute left-3 top-3 text-gray-500"></i>
                                <input type="text" id="mood_title" name="mood_title" class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent transition" placeholder="e.g., Stressed before exam" aria-label="Mood title" />
                                <div id="title-suggestions" class="hidden custom-scrollbar absolute top-full left-0 right-0 bg-white border border-gray-300 rounded-lg mt-1 max-h-40 overflow-y-auto z-10"></div>
                            </div>
                        </div>

                        <!-- Mood Note -->
                        <div>
                            <label for="mood_note" class="block text-gray-700 mb-2 text-sm font-medium">Journal your thoughts</label>
                            <textarea id="mood_note" name="mood_note" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent transition custom-scrollbar" placeholder="What happened today? How are you feeling?" rows="5" aria-label="Mood notes"></textarea>
                        </div>

                        <div class="flex space-x-4 pt-4">
                            <button type="submit" id="submit-btn" class="btn-primary flex-1 bg-primary-500 text-white py-3 rounded-lg hover:bg-primary-600 transition flex items-center justify-center">
                                <i class="fas fa-save mr-2"></i> Save Mood Entry
                            </button>
                            <button type="button" id="clear-form" class="btn-secondary flex-1 bg-gray-100 text-gray-700 py-3 rounded-lg hover:bg-gray-200 transition flex items-center justify-center">
                                <i class="fas fa-eraser mr-2"></i> Clear Form
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Mood Insights -->
                <div class="md:w-1/2 bg-primary-50 p-6 md:p-8">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4 flex items-center">
                        Your Mood Insights
                        <i class="fas fa-chart-bar text-primary-600 ml-2"></i>
                    </h3>

                    <!-- Calendar View -->
                    <div class="bg-white p-4 rounded-lg shadow mb-6">
                        <div class="flex justify-between items-center mb-3">
                            <h4 id="calendar-title" class="font-medium text-gray-700"></h4>
                            <div class="flex space-x-2">
                                <button id="prev-month" class="text-primary-600 hover:text-primary-700 transition" aria-label="Previous month">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <button id="next-month" class="text-primary-600 hover:text-primary-700 transition" aria-label="Next month">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                        <div class="grid grid-cols-7 gap-1 text-center text-gray-500 text-xs">
                            <div>Sun</div>
                            <div>Mon</div>
                            <div>Tue</div>
                            <div>Wed</div>
                            <div>Thu</div>
                            <div>Fri</div>
                            <div>Sat</div>
                        </div>
                        <div id="calendar-days" class="grid grid-cols-7 gap-1 mt-1 text-sm"></div>
                    </div>

                    <!-- Recent Highlights -->
                    <div class="bg-white p-4 rounded-lg shadow mb-6">
                        <h4 class="font-medium text-gray-700 mb-3">Recent Highlights</h4>
                        <ul id="recent-highlights" class="space-y-3 max-h-48 overflow-y-auto custom-scrollbar">
                            <?php if (count($recent_highlights) === 0): ?>
                                <li class="text-gray-500">No entries yet.</li>
                            <?php else: ?>
                                <?php foreach ($recent_highlights as $entry): ?>
                                    <li class="flex items-center space-x-3 p-2 bg-primary-100 rounded-lg">
                                        <div class="text-2xl">
                                            <?php
                                                switch ($entry['mood_emoji']) {
                                                    case 'happy': echo '😄'; break;
                                                    case 'good': echo '😊'; break;
                                                    case 'neutral': echo '😐'; break;
                                                    case 'sad': echo '😔'; break;
                                                    case 'angry': echo '😠'; break;
                                                    default: echo '🙂';
                                                }
                                            ?>
                                        </div>
                                        <div>
                                            <p class="font-semibold text-primary-900"><?php echo htmlspecialchars($entry['mood_title'] ?: '(No title)'); ?></p>
                                            <p class="text-primary-700 text-sm truncate max-w-xs"><?php echo htmlspecialchars($entry['mood_note']); ?></p>
                                            <p class="text-primary-500 text-xs"><?php echo htmlspecialchars($entry['entry_date']); ?></p>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Mood Chart -->
                    <div class="bg-white p-4 rounded-lg shadow">
                        <h4 class="font-medium text-gray-700 mb-3">Mood Overview</h4>
                        <canvas id="moodChart" width="400" height="250"></canvas>
                    </div>
                </div>
            </div>
        </section>

        <!-- Full Journal View -->
        <section id="full-journal" class="hidden mt-8 bg-white rounded-2xl shadow-lg p-6">
            <h3 class="text-xl font-semibold text-gray-800 mb-4 flex items-center">
                Full Journal
                <i class="fas fa-book-open text-primary-600 ml-2"></i>
            </h3>
            <div id="journal-entries" class="space-y-4 custom-scrollbar max-h-[600px] overflow-y-auto"></div>
            <div class="flex justify-center mt-4">
                <button id="prev-page" class="btn-primary bg-primary-500 text-white py-2 px-4 rounded-lg hover:bg-primary-600 transition mr-2" disabled>Previous</button>
                <button id="next-page" class="btn-primary bg-primary-500 text-white py-2 px-4 rounded-lg hover:bg-primary-600 transition">Next</button>
            </div>
            <button id="back-to-main" class="mt-4 text-primary-600 hover:text-primary-700 underline">Back to Mood Journal</button>
        </section>
    </div>

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
        const entries = <?php echo json_encode($entries); ?>;
        const entryDates = <?php echo json_encode($entry_dates); ?>;
        const moodCounts = <?php echo json_encode(array_values($chart_data)); ?>;
        const moodLabelsText = ['Happy', 'Good', 'Neutral', 'Sad', 'Angry'];
        const moodColors = ['#FBBF24', '#34D399', '#9CA3AF', '#60A5FA', '#F87171'];

        // Initialize mood chart
        const ctx = document.getElementById('moodChart').getContext('2d');
        const moodChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: moodLabelsText,
                datasets: [{
                    label: 'Entries',
                    data: moodCounts,
                    backgroundColor: moodColors,
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 50
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: true }
                }
            }
        });

        // Calendar functionality
        let currentDate = new Date('<?php echo date('Y-m-d'); ?>');
        let selectedDate = null;

        function renderCalendar(date) {
            const year = date.getFullYear();
            const month = date.getMonth();
            const monthName = date.toLocaleString('default', { month: 'long' });
            document.getElementById('calendar-title').textContent = `${monthName} ${year}`;

            const firstDay = new Date(year, month, 1).getDay();
            const lastDate = new Date(year, month + 1, 0).getDate();
            const days = [];

            for (let i = 0; i < firstDay; i++) {
                days.push('<div></div>');
            }

            for (let d = 1; d <= lastDate; d++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const hasEntry = entryDates.includes(dateStr);
                const isToday = dateStr === '<?php echo date('Y-m-d'); ?>';
                const isSelected = selectedDate === dateStr;
                let className = 'calendar-day bg-white rounded-md p-2 text-center cursor-pointer';
                if (hasEntry) className += ' has-entry';
                if (isToday) className += ' bg-primary-100 font-semibold';
                if (isSelected) className += ' bg-primary-200';
                days.push(`<div class="${className}" data-date="${dateStr}">${d}</div>`);
            }

            document.getElementById('calendar-days').innerHTML = days.join('');
            document.querySelectorAll('.calendar-day').forEach(day => {
                day.addEventListener('click', () => {
                    document.querySelectorAll('.calendar-day').forEach(d => d.classList.remove('bg-primary-200'));
                    selectedDate = day.dataset.date;
                    day.classList.add('bg-primary-200');
                    renderJournalEntries(selectedDate);
                });
            });

            document.getElementById('prev-month').disabled = year === 2025 && month === 0;
            document.getElementById('next-month').disabled = year === new Date().getFullYear() && month === new Date().getMonth();
        }

        // Journal entries rendering
        const entriesPerPage = 5;
        let currentPage = 1;

        function renderJournalEntries(date = null, page = 1) {
            const journalEntries = document.getElementById('journal-entries');
            let filteredEntries = date ? entries.filter(e => e.entry_date === date) : entries;
            const totalPages = Math.ceil(filteredEntries.length / entriesPerPage);
            const start = (page - 1) * entriesPerPage;
            const end = start + entriesPerPage;
            const paginatedEntries = filteredEntries.slice(start, end);

            journalEntries.innerHTML = paginatedEntries.length
                ? paginatedEntries.map(entry => `
                    <div class="p-4 bg-primary-100 rounded-lg">
                        <div class="flex items-center space-x-3">
                            <span class="text-2xl">
                                ${entry.mood_emoji === 'happy' ? '😄' : 
                                  entry.mood_emoji === 'good' ? '😊' : 
                                  entry.mood_emoji === 'neutral' ? '😐' : 
                                  entry.mood_emoji === 'sad' ? '😔' : 
                                  entry.mood_emoji === 'angry' ? '😠' : '🙂'}
                            </span>
                            <div>
                                <p class="font-semibold text-primary-900">${entry.mood_title || '(No title)'}</p>
                                <p class="text-primary-700 text-sm">${entry.mood_note}</p>
                                <p class="text-primary-500 text-xs">${entry.entry_date}</p>
                            </div>
                        </div>
                    </div>
                `).join('')
                : '<p class="text-gray-500 text-center py-4">No entries for this date.</p>';

            document.getElementById('prev-page').disabled = page === 1;
            document.getElementById('next-page').disabled = page === totalPages || filteredEntries.length === 0;
            currentPage = page;
        }

        // Document ready
        document.addEventListener('DOMContentLoaded', () => {
            renderCalendar(currentDate);

            // Mood emoji selection
            const moodLabels = document.querySelectorAll('label.mood-emoji');
            const moodInputs = document.querySelectorAll('input[name="mood_emoji"]');
            moodLabels.forEach(label => {
                label.addEventListener('click', () => {
                    moodLabels.forEach(l => l.classList.remove('mood-selected'));
                    label.classList.add('mood-selected');
                });
            });

            // Form handling
            const moodForm = document.getElementById('moodForm');
            const submitBtn = document.getElementById('submit-btn');
            moodForm.addEventListener('submit', () => {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Saving...';
                submitBtn.disabled = true;
            });

            // Clear form
            document.getElementById('clear-form').addEventListener('click', () => {
                moodForm.reset();
                moodLabels.forEach(l => l.classList.remove('mood-selected'));
            });

            // Dismiss alerts
            const dismissSuccess = document.getElementById('dismiss-success');
            const dismissError = document.getElementById('dismiss-error');
            if (dismissSuccess) {
                dismissSuccess.addEventListener('click', () => {
                    document.getElementById('success-alert').remove();
                });
            }
            if (dismissError) {
                dismissError.addEventListener('click', () => {
                    document.getElementById('error-alert').remove();
                });
            }

            // Calendar navigation
            document.getElementById('prev-month').addEventListener('click', () => {
                currentDate.setMonth(currentDate.getMonth() - 1);
                renderCalendar(currentDate);
                selectedDate = null;
                renderJournalEntries();
            });
            document.getElementById('next-month').addEventListener('click', () => {
                currentDate.setMonth(currentDate.getMonth() + 1);
                renderCalendar(currentDate);
                selectedDate = null;
                renderJournalEntries();
            });

            // Full journal view
            document.getElementById('viewJournalBtn').addEventListener('click', () => {
                document.getElementById('full-journal').classList.remove('hidden');
                document.querySelector('section:not(#full-journal)').classList.add('hidden');
                renderJournalEntries();
            });

            document.getElementById('back-to-main').addEventListener('click', () => {
                document.getElementById('full-journal').classList.add('hidden');
                document.querySelector('section:not(#full-journal)').classList.remove('hidden');
                selectedDate = null;
                renderCalendar(currentDate);
                renderJournalEntries();
            });

            document.getElementById('prev-page').addEventListener('click', () => {
                if (currentPage > 1) {
                    renderJournalEntries(selectedDate, currentPage - 1);
                }
            });

            document.getElementById('next-page').addEventListener('click', () => {
                renderJournalEntries(selectedDate, currentPage + 1);
            });

            // Mood title autocomplete
            const moodTitle = document.getElementById('mood_title');
            const titleSuggestions = document.getElementById('title-suggestions');
            const storedTitles = JSON.parse(localStorage.getItem('moodTitles') || '[]');
            let debounceTimeout;

            moodTitle.addEventListener('input', () => {
                clearTimeout(debounceTimeout);
                debounceTimeout = setTimeout(() => {
                    const query = moodTitle.value.trim().toLowerCase();
                    if (query.length < 2) {
                        titleSuggestions.classList.add('hidden');
                        titleSuggestions.innerHTML = '';
                        return;
                    }
                    const suggestions = storedTitles.filter(title => title.toLowerCase().includes(query)).slice(0, 5);
                    titleSuggestions.innerHTML = suggestions.map(title => `
                        <div class="p-2 cursor-pointer hover:bg-primary-100 rounded">${title}</div>
                    `).join('');
                    titleSuggestions.classList.toggle('hidden', suggestions.length === 0);
                    titleSuggestions.querySelectorAll('div').forEach(div => {
                        div.addEventListener('click', () => {
                            moodTitle.value = div.textContent;
                            titleSuggestions.classList.add('hidden');
                        });
                    });
                }, 300);
            });

            document.addEventListener('click', (e) => {
                if (!moodTitle.contains(e.target) && !titleSuggestions.contains(e.target)) {
                    titleSuggestions.classList.add('hidden');
                }
            });

            moodForm.addEventListener('submit', () => {
                const title = moodTitle.value.trim();
                if (title && !storedTitles.includes(title)) {
                    storedTitles.unshift(title);
                    if (storedTitles.length > 10) storedTitles.pop();
                    localStorage.setItem('moodTitles', JSON.stringify(storedTitles));
                }
            });

            // Keyboard shortcuts
            moodForm.addEventListener('keypress', (e) => {
                if (e.key === 'Enter' && !e.shiftKey && e.target.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    moodForm.dispatchEvent(new Event('submit'));
                }
            });
        });

        // Error handling
        window.addEventListener('error', (event) => {
            console.error('JavaScript error:', event);
            const alert = document.createElement('div');
            alert.className = 'error-alert bg-red-50 text-red-700 p-4 rounded-xl mb-6 flex items-center justify-between max-w-2xl mx-auto';
            alert.innerHTML = `
                <span>We appriciate your thoughts! Have a great day!.</span>
                <button class="dismiss-alert text-green-600 hover:text-red-800 transition" aria-label="correct">
                    <i class="fas fa-times"></i>
                </button>
            `;
            document.querySelector('section:not(#full-journal)').prepend(alert);
            alert.querySelector('.dismiss-alert').addEventListener('click', () => alert.remove());
            setTimeout(() => alert.remove(), 5000);
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
?>