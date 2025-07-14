<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require 'config/db.php';

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
$firstName = isset($_SESSION['first_name']) ? htmlspecialchars($_SESSION['first_name'], ENT_QUOTES, 'UTF-8') : 'User';
$avatar = isset($_SESSION['avatar']) ? htmlspecialchars($_SESSION['avatar'], ENT_QUOTES, 'UTF-8') : 'default.jpg';

// Function to get avatar URL
function getAvatarUrl($avatar) {
    $basePath = '/assets/avatars/';
    return file_exists($_SERVER['DOCUMENT_ROOT'] . $basePath . $avatar) ? $basePath . $avatar : $basePath . 'default.jpg';
}

// Fetch mood entries
$moodEntries = [];
$hasRecentMood = false;
try {
    $stmt = $pdo->prepare("SELECT mood, intensity, notes, created_at FROM user_mood_entries WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $moodEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Check if there's a mood entry from the last 24 hours
    if (!empty($moodEntries)) {
        $latestEntryTime = strtotime($moodEntries[0]['created_at']);
        $hasRecentMood = (time() - $latestEntryTime) < 24 * 3600;
    }
} catch (PDOException $e) {
    error_log("Error fetching mood entries: " . $e->getMessage());
}

// Run mood prediction
$prediction = null;
try {
    $pythonPath = escapeshellarg("C:/Users/laptop universe/AppData/Local/Programs/Python/Python311/python.exe");
    $scriptPath = escapeshellarg("C:/xampp/htdocs/Companion/predict_mood.py");
    $pythonScript = "$pythonPath $scriptPath " . escapeshellarg((string)$userId);
    $output = shell_exec($pythonScript . " 2>&1");
    if ($output) {
        $prediction = json_decode($output, true);
        error_log("Mood prediction output for user_id $userId: $output");
    } else {
        error_log("Failed to execute mood prediction script for user_id: $userId");
    }
} catch (Exception $e) {
    error_log("Error running mood prediction: " . $e->getMessage());
}

// Handle mood entry submission
$errorMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mood'], $_POST['csrf_token'])) {
    // Validate CSRF token
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = 'Invalid CSRF token. Please try again.';
    } else {
        $mood = filter_var($_POST['mood'], FILTER_SANITIZE_STRING);
        $intensity = filter_var($_POST['intensity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        $notes = isset($_POST['notes']) ? trim(filter_var($_POST['notes'], FILTER_SANITIZE_STRING)) : null;

        $validMoods = ['Happy', 'Sad', 'Neutral', 'Angry', 'Excited'];
        if (!in_array($mood, $validMoods) || !$intensity) {
            $errorMessage = 'Invalid mood or intensity. Please try again.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO user_mood_entries (user_id, mood, intensity, notes, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$userId, $mood, $intensity, $notes]);
                // Re-run prediction
                $output = shell_exec($pythonScript . " 2>&1");
                if ($output) {
                    $prediction = json_decode($output, true);
                }
                // Regenerate CSRF token
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header("Location: mood_prediction.php");
                exit();
            } catch (PDOException $e) {
                error_log("Error saving mood entry: " . $e->getMessage());
                $errorMessage = 'Failed to save mood entry. Please try again.';
            }
        }
    }

    $prediction = null;
try {
    if (!is_numeric($userId) || $userId <= 0) {
        throw new Exception("Invalid user ID");
    }
    $pythonPath = escapeshellarg("C:/Users/laptop universe/AppData/Local/Programs/Python/Python311/python.exe");
    $scriptPath = escapeshellarg("C:/xampp/htdocs/Companion/predict_mood.py");
    $pythonScript = "$pythonPath $scriptPath " . escapeshellarg((string)$userId);
    $output = shell_exec($pythonScript . " 2>&1");
    if ($output) {
        $prediction = json_decode($output, true);
        error_log("Mood prediction output for user_id $userId: $output");
        if (!is_array($prediction) || !isset($prediction['success'])) {
            throw new Exception("Invalid prediction response format");
        }
        if (!$prediction['success']) {
            $errorMessage = htmlspecialchars($prediction['message'], ENT_QUOTES, 'UTF-8');
            $prediction = null; // Reset to avoid using invalid data
        }
    } else {
        error_log("Failed to execute mood prediction script for user_id: $userId");
        $errorMessage = 'Failed to generate mood prediction. Please try again.';
    }
} catch (Exception $e) {
    error_log("Error running mood prediction for user_id $userId: " . $e->getMessage());
    $errorMessage = 'Error running mood prediction: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="CompanionX - Understand your mood with AI-driven insights">
    <title>CompanionX - Mood Insights</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 50%, #80deea 100%);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        .topbar {
            background: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }

        .card-hover:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .progress-ring__circle {
            transition: stroke-dashoffset 0.5s ease;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
        }

        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .petal {
            position: absolute;
            background: url('https://cdn.pixabay.com/photo/2016/04/15/04/02/flower-1330062_1280.png') no-repeat center;
            background-size: contain;
            width: 16px;
            height: 16px;
            opacity: 0.4;
            animation: float 12s infinite;
            z-index: -1;
        }

        @keyframes float {
            0% { transform: translateY(0) rotate(0deg); opacity: 0.4; }
            50% { opacity: 0.7; }
            100% { transform: translateY(100vh) rotate(360deg); opacity: 0.1; }
        }

        .modal-overlay {
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(3px);
        }

        .loading-spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #00bcd4;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .mood-option:hover {
            transform: scale(1.1);
        }

        .mood-option:focus {
            outline: none;
            ring: 2px solid #00bcd4;
        }

        .notification-popup {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        .notification-popup.hidden {
            opacity: 0;
            transform: translateY(10px);
            pointer-events: none;
        }

        .btn-primary {
            transition: transform 0.2s ease, background 0.2s ease;
        }

        .btn-primary:hover {
            transform: scale(1.05);
            background: #26c6da;
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
<body class="min-h-screen">
    <!-- Floating Petals -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let petalCount = 0;
            const maxPetals = 8;
            function createPetals() {
                if (petalCount >= maxPetals) return;
                const newPetal = document.createElement('div');
                newPetal.className = 'petal';
                newPetal.style.left = Math.random() * 100 + 'vw';
                newPetal.style.animationDelay = Math.random() * 4 + 's';
                newPetal.addEventListener('animationend', () => {
                    newPetal.remove();
                    petalCount--;
                });
                document.body.appendChild(newPetal);
                petalCount++;
            }
            createPetals();
            setInterval(createPetals, 6000);
        });
    </script>

    <!-- Topbar -->
    <header class="topbar">
        <div class="container mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-primary-500 rounded-full flex items-center justify-center text-white">
                    <i class="fas fa-brain text-xl"></i>
                </div>
                <h1 class="text-xl font-bold text-gray-800">CompanionX</h1>
            </div>
            <div class="flex items-center space-x-4">
                <a href="user_session.php" class="px-3 py-2 text-primary-600 hover:bg-primary-50 rounded-lg transition flex items-center" aria-label="Conduct Session" data-tooltip="Join or book a session">
                    <i class="fas fa-video mr-2"></i> Conduct Session
                </a>
                <a href="dashboard.php" class="px-3 py-2 text-primary-600 hover:bg-primary-50 rounded-lg transition flex items-center" aria-label="Dashboard" data-tooltip="View your dashboard">
                    <i class="fas fa-home mr-2"></i> Dashboard
                </a>
                <button id="notificationBtn" class="p-2 text-gray-600 hover:text-primary-500 relative" aria-label="Notifications" data-tooltip="View notifications">
                    <i class="fas fa-bell text-lg"></i>
                    <?php if (!$hasRecentMood): ?>
                        <span class="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full"></span>
                    <?php endif; ?>
                </button>
                <div class="flex items-center space-x-2">
                    <span class="text-sm text-gray-600 hidden sm:block"><?php echo $firstName; ?></span>
                    <div class="w-8 h-8 rounded-full overflow-hidden border-2 border-primary-200">
                        <img src="<?php echo htmlspecialchars(getAvatarUrl($avatar)); ?>" alt="User Avatar" class="w-full h-full object-cover">
                    </div>
                </div>
                <a href="logout.php" class="px-3 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition" aria-label="Logout" data-tooltip="Sign out of your account">Logout</a>
            </div>
        </div>
    </header>

    <!-- Notification Popup -->
    <div id="notificationPopup" class="hidden notification-popup absolute right-4 top-14 bg-white rounded-lg shadow-lg w-80 z-50">
        <div class="p-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-800">Notifications</h3>
        </div>
        <div id="notificationList" class="max-h-64 overflow-y-auto">
            <!-- Notifications populated via JS -->
        </div>
        <div class="p-4 border-t border-gray-100">
            <button id="clearNotifications" class="text-primary-500 hover:text-primary-600 text-sm" aria-label="Clear all notifications">Clear All</button>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container mx-auto px-4 pt-20 pb-12">
        <div class="max-w-6xl mx-auto">
            <!-- Header Section -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 fade-in">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Your Mood Insights</h2>
                    <p class="text-gray-600 mt-1">AI-driven analysis to understand your emotional well-being</p>
                </div>
                <button id="newEntryBtn" class="mt-4 md:mt-0 px-5 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition flex items-center btn-primary" aria-label="Log new mood" data-tooltip="Log your current mood">
                    <i class="fas fa-plus mr-2"></i> Log Mood
                </button>
            </div>

            <?php if (isset($errorMessage)): ?>
                <div class="mb-6 p-4 bg-red-50 text-red-700 rounded-lg fade-in flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>

            <!-- Insights Section -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <!-- Current Mood -->
                <div class="bg-white rounded-xl shadow-sm p-6 card-hover fade-in relative">
                    <div id="predictionLoading" class="loading-spinner absolute top-4 right-4 hidden"></div>
                    <div class="relative w-32 h-32 mx-auto mb-4">
                        <svg class="w-full h-full" viewBox="0 0 100 100" role="img" aria-label="Mood stability meter">
                            <circle class="text-gray-100" stroke-width="6" stroke="currentColor" fill="transparent" r="42" cx="50" cy="50" />
                            <circle class="text-primary-500 progress-ring__circle" stroke-width="6" stroke-dasharray="263.89" stroke-dashoffset="<?php echo $prediction ? (263.89 * (1 - $prediction['stability'] / 100)) : 79.17; ?>" stroke-linecap="round" stroke="currentColor" fill="transparent" r="42" cx="50" cy="50" />
                            <div class="absolute inset-0 flex items-center justify-center">
                                <div class="text-center">
                                    <div class="text-3xl font-bold text-primary-600"><?php echo $prediction ? round($prediction['stability']) : 70; ?>%</div>
                                    <div class="text-sm text-gray-500">Mood Stability</div>
                                </div>
                            </div>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 text-center mb-2">Current Mood</h3>
                    <div class="px-4 py-1 bg-primary-100 text-primary-800 rounded-full text-sm font-medium text-center">
                        <?php echo $prediction ? htmlspecialchars($prediction['mood']) : 'Mostly Stable'; ?>
                    </div>
                    <p class="text-gray-600 text-center mt-3 text-sm"><?php echo $prediction ? htmlspecialchars($prediction['message']) : 'Your mood has been consistent with slight fluctuations'; ?></p>
                </div>

                <!-- Mood Trend -->
                <div class="bg-white rounded-xl shadow-sm p-6 card-hover fade-in">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Weekly Mood Trend</h3>
                    <div class="h-48">
                        <canvas id="moodTrendChart" aria-label="Weekly mood trend chart"></canvas>
                    </div>
                    <div class="mt-4 text-sm text-gray-600 flex items-center">
                        <i class="fas fa-info-circle mr-2 text-primary-500"></i>
                        <?php echo count($moodEntries) < 14 ? 'Log more moods to unlock detailed trends!' : 'Your mood shows a positive trend this week'; ?>
                    </div>
                </div>

                <!-- Recommendations -->
                <div class="bg-white rounded-xl shadow-sm p-6 card-hover fade-in">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Personalized Recommendations</h3>
                    <div class="space-y-3">
                        <?php
                        $colors = ['primary', 'secondary', 'purple'];
                        if ($prediction && !empty($prediction['tasks'])) {
                            foreach ($prediction['tasks'] as $index => $task) {
                                $color = $colors[$index % count($colors)];
                                $icon = htmlspecialchars($task['icon'] ?? 'fas fa-lightbulb', ENT_QUOTES, 'UTF-8');
                                $taskType = htmlspecialchars($task['task_type'] ?? 'Task', ENT_QUOTES, 'UTF-8');
                                $description = htmlspecialchars($task['description'] ?? 'No description available', ENT_QUOTES, 'UTF-8');
                                echo <<<HTML
                                <div class="flex items-start p-3 bg-{$color}-50 rounded-lg transition hover:bg-{$color}-100">
                                    <div class="flex-shrink-0 mt-1 mr-3 text-{$color}-500">
                                        <i class="$icon"></i>
                                    </div>
                                    <div>
                                        <h4 class="font-medium text-{$color}-800 text-sm">$taskType</h4>
                                        <p class="text-sm text-{$color}-600">$description</p>
                                    </div>
                                </div>
                                HTML;
                            }
                        } else {
                            echo '<p class="text-gray-600 text-sm">Log more moods to receive tailored recommendations.</p>';
                        }
                        ?>
                    </div>
                    <?php if ($prediction && in_array($prediction['mood'], ['Sad', 'Angry'])): ?>
                        <a href="user_session.php" class="mt-4 block w-full py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition text-center text-sm btn-primary" data-tooltip="Book a counseling session">Book a Counseling Session</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Mood History -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-8 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg font-semibold text-gray-800">Mood History</h3>
                    <div class="flex space-x-2">
                        <button class="px-3 py-1 bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200 transition" data-period="week" aria-label="Filter by week">Week</button>
                        <button class="px-3 py-1 bg-primary-500 text-white rounded-lg" data-period="month" aria-label="Filter by month">Month</button>
                        <button class="px-3 py-1 bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200 transition" data-period="year" aria-label="Filter by year">Year</button>
                    </div>
                </div>
                <div id="moodHistory" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4 max-h-80 overflow-y-auto">
                    <?php
                    $moodStyles = [
                        'Happy' => ['bg-green-50', 'bg-green-100', 'text-green-700', 'fas fa-smile'],
                        'Sad' => ['bg-purple-50', 'bg-purple-100', 'text-purple-700', 'fas fa-sad-tear'],
                        'Neutral' => ['bg-blue-50', 'bg-blue-100', 'text-blue-700', 'fas fa-meh'],
                        'Angry' => ['bg-red-50', 'bg-red-100', 'text-red-700', 'fas fa-angry'],
                        'Excited' => ['bg-yellow-50', 'bg-yellow-100', 'text-yellow-700', 'fas fa-laugh']
                    ];
                    foreach ($moodEntries as $entry) {
                        $styles = $moodStyles[$entry['mood']];
                        $date = date('D, M d', strtotime($entry['created_at']));
                        $tooltip = htmlspecialchars($entry['notes'] ?? 'No notes', ENT_QUOTES, 'UTF-8');
                        echo <<<HTML
                        <div class="mood-card {$styles[0]} rounded-lg p-3 text-center card-hover transition cursor-pointer" role="button" tabindex="0" aria-label="Mood entry: {$entry['mood']} on $date" data-notes="$tooltip">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-full {$styles[1]} flex items-center justify-center {$styles[2]}">
                                <i class="{$styles[3]} text-xl"></i>
                            </div>
                            <div class="text-sm font-medium {$styles[2]}">{$entry['mood']}</div>
                            <div class="text-xs text-gray-500">$date</div>
                        </div>
                        HTML;
                    }
                    ?>
                </div>
            </div>

            <!-- Behavioral Insights -->
            <div class="bg-white rounded-xl shadow-sm p-6 fade-in">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Behavioral Insights</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h4 class="font-medium text-gray-700 mb-3">Mood Patterns</h4>
                        <div class="space-y-4">
                            <?php
                            $patterns = [];
                            if (count($moodEntries) >= 14) {
                                $weekdayMoods = array_filter($moodEntries, function($e) {
                                    return (new DateTime($e['created_at']))->format('N') < 6;
                                });
                                $weekendMoods = array_filter($moodEntries, function($e) {
                                    return (new DateTime($e['created_at']))->format('N') >= 6;
                                });

                                $weekdayAvg = $weekdayMoods ? array_sum(array_map(function($e) {
                                    return $e['intensity'];
                                }, $weekdayMoods)) / count($weekdayMoods) : 0;
                                $weekendAvg = $weekendMoods ? array_sum(array_map(function($e) {
                                    return $e['intensity'];
                                }, $weekendMoods)) / count($weekendMoods) : 0;

                                $avgIntensity = count($moodEntries) ? array_sum(array_map(function($e) {
                                    return $e['intensity'];
                                }, $moodEntries)) / count($moodEntries) : 0;
                                $patterns[] = ['Morning Mood', round($avgIntensity * 10), 'positive'];

                                $weekendWeekdayDiff = ($weekdayAvg && $weekendAvg) ? min(round(abs($weekendAvg - $weekdayAvg) * 10), 100) : 30;
                                $patterns[] = ['Weekend vs Weekday', $weekendWeekdayDiff, 'difference'];

                                $consistency = count($moodEntries) ? array_sum(array_map(function($e) {
                                    return abs($e['intensity'] - 5);
                                }, $moodEntries)) / count($moodEntries) : 0;
                                $consistencyScore = round(max(0, (1 - ($consistency / 5)) * 100));
                                $patterns[] = ['Mood Consistency', $consistencyScore, 'stability'];
                            } else {
                                $patterns = [
                                    ['Morning Mood', 65, 'positive'],
                                    ['Weekend vs Weekday', 30, 'difference'],
                                    ['Mood Consistency', 70, 'stability']
                                ];
                            }
                            foreach ($patterns as $index => $pattern) {
                                $color = $colors[$index % count($colors)];
                                echo <<<HTML
                                <div>
                                    <div class="flex justify-between mb-1">
                                        <span class="text-sm font-medium text-{$color}-700">{$pattern[0]}</span>
                                        <span class="text-sm font-medium text-gray-600">{$pattern[1]}% {$pattern[2]}</span>
                                    </div>
                                    <div class="w-full bg-gray-100 rounded-full h-2">
                                        <div class="bg-{$color}-500 h-2 rounded-full" style="width: {$pattern[1]}%"></div>
                                    </div>
                                </div>
                                HTML;
                            }
                            ?>
                        </div>
                    </div>
                    <div>
                        <h4 class="font-medium text-gray-700 mb-3">Recent Insights</h4>
                        <div class="space-y-3">
                            <?php
                            $insights = count($moodEntries) < 14 ? [
                                ['Physical activity boosts your mood.', 'fas fa-running', 'primary'],
                                ['Tuesdays may feel more challenging.', 'fas fa-calendar-day', 'purple'],
                                ['Better sleep improves positivity.', 'fas fa-moon', 'secondary']
                            ] : [];
                            if (count($moodEntries) >= 14) {
                                $sadCount = count(array_filter($moodEntries, fn($e) => $e['mood'] === 'Sad'));
                                $insights[] = [$sadCount > 3 ? 'Frequent sad moods detected. Consider professional support.' : 'Your mood is generally balanced.', 'fas fa-lightbulb', 'primary'];
                                $tuesdayMoods = array_filter($moodEntries, fn($e) => (new DateTime($e['created_at']))->format('D') === 'Tue');
                                $tuesdayAvg = $tuesdayMoods ? array_sum(array_map(fn($e) => $e['intensity'], $tuesdayMoods)) / count($tuesdayMoods) : 5;
                                $insights[] = [$tuesdayAvg < 4 ? 'Tuesdays show lower mood intensity.' : 'No specific day-based patterns.', 'fas fa-calendar-day', 'purple'];
                                $highIntensityCount = count(array_filter($moodEntries, fn($e) => $e['intensity'] >= 8));
                                $insights[] = [$highIntensityCount > 3 ? 'High-intensity moods detected. Try mindfulness.' : 'Mood intensity is balanced.', 'fas fa-meditation', 'secondary'];
                            }
                            foreach ($insights as $insight) {
                                $insightText = htmlspecialchars($insight[0], ENT_QUOTES, 'UTF-8');
                                echo <<<HTML
                                <div class="p-3 bg-{$insight[2]}-50 rounded-lg transition hover:bg-{$insight[2]}-100">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0 mt-1 mr-3 text-{$insight[2]}-500">
                                            <i class="{$insight[1]}"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm text-{$insight[2]}-800">$insightText</p>
                                        </div>
                                    </div>
                                </div>
                                HTML;
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- New Entry Modal -->
    <div id="entryModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 modal-overlay hidden" role="dialog" aria-labelledby="entry-modal-title">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 fade-in">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 id="entry-modal-title" class="text-lg font-semibold text-gray-800">Log Your Mood</h3>
                    <button id="closeModalBtn" class="text-gray-500 hover:text-gray-700" aria-label="Close modal">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="moodForm" method="POST" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">How are you feeling?</label>
                        <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                            <?php
                            $moods = ['Angry', 'Sad', 'Neutral', 'Happy', 'Excited'];
                            $moodStyles = [
                                'Angry' => ['bg-red-50', 'text-red-600', 'hover:bg-red-100', 'fas fa-angry'],
                                'Sad' => ['bg-purple-50', 'text-purple-600', 'hover:bg-purple-100', 'fas fa-sad-tear'],
                                'Neutral' => ['bg-blue-50', 'text-blue-600', 'hover:bg-blue-100', 'fas fa-meh'],
                                'Happy' => ['bg-green-50', 'text-green-600', 'hover:bg-green-100', 'fas fa-smile'],
                                'Excited' => ['bg-yellow-50', 'text-yellow-600', 'hover:bg-yellow-100', 'fas fa-laugh']
                            ];
                            foreach ($moods as $mood) {
                                $styles = $moodStyles[$mood];
                                echo <<<HTML
                                <button type="button" class="mood-option p-3 rounded-lg {$styles[0]} {$styles[1]} {$styles[2]} transition flex flex-col items-center focus:ring-2 focus:ring-primary-500" data-mood="$mood" tabindex="0" aria-label="Select $mood mood">
                                    <i class="{$styles[3]} text-xl mb-1"></i>
                                    <span class="text-xs">$mood</span>
                                </button>
                                HTML;
                            }
                            ?>
                            <input type="hidden" name="mood" id="selectedMood" aria-required="true">
                        </div>
                        <p id="moodError" class="text-red-600 text-sm mt-2 hidden" role="alert">Please select a mood.</p>
                    </div>
                    <div>
                        <label for="intensity" class="block text-sm font-medium text-gray-700 mb-2">Intensity (1-10)</label>
                        <div class="flex items-center space-x-2">
                            <span class="text-xs text-gray-500">Low</span>
                            <input type="range" id="intensity" name="intensity" min="1" max="10" value="5" class="w-full h-2 bg-gray-200 rounded-lg cursor-pointer focus:ring-2 focus:ring-primary-500" aria-label="Mood intensity slider" aria-valuemin="1" aria-valuemax="10" aria-valuenow="5">
                            <span class="text-xs text-gray-500">High</span>
                            <span id="intensityValue" class="text-sm text-gray-700" aria-live="polite">5</span>
                        </div>
                    </div>
                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Notes (optional)</label>
                        <textarea id="notes" name="notes" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-primary-500 focus:border-primary-500 transition" rows="3" maxlength="500" placeholder="What's on your mind today?" aria-describedby="notes-desc"></textarea>
                        <p id="notes-desc" class="text-xs text-gray-500 mt-1">Max 500 characters</p>
                    </div>
                    <div class="pt-2 flex items-center justify-between">
                        <button type="submit" class="w-full py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition font-medium disabled:bg-gray-400 disabled:cursor-not-allowed btn-primary" id="submitMoodBtn" aria-label="Submit mood entry" data-tooltip="Submit your mood entry">Submit</button>
                        <div id="formSpinner" class="loading-spinner ml-3 hidden"></div>
                    </div>
                </form>
                <div id="formMessage" class="mt-3 text-sm font-medium hidden" role="alert"></div>
            </div>
        </div>
    </div>

    <!-- Notes Modal -->
    <div id="notesModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 modal-overlay hidden" role="dialog" aria-labelledby="notes-modal-title">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 fade-in">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 id="notes-modal-title" class="text-lg font-semibold text-gray-800">Mood Notes</h3>
                    <button id="closeNotesModalBtn" class="text-gray-500 hover:text-gray-700" aria-label="Close notes modal">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <p id="notesContent" class="text-gray-600 text-sm"></p>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-100 py-8">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-6 md:mb-0">
                    <div class="flex items-center">
                        <div class="w-8 h-8 bg-primary-500 rounded-full flex items-center justify-center text-white mr-2">
                            <i class="fas fa-brain text-lg"></i>
                        </div>
                        <span class="text-lg font-bold text-gray-800">CompanionX</span>
                    </div>
                    <p class="text-gray-500 text-sm mt-2">Your trusted mental health companion</p>
                </div>
                <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-8">
                    <div>
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Resources</h3>
                        <ul class="space-y-2">
                            <li><a href="#" class="text-gray-600 hover:text-primary-500 text-sm">Articles</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500 text-sm">Self-Help Guides</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500 text-sm">Therapist Directory</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Company</h3>
                        <ul class="space-y-2">
                            <li><a href="#" class="text-gray-600 hover:text-primary-500 text-sm">About</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500 text-sm">Privacy</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500 text-sm">Terms</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Connect</h3>
                        <div class="flex space-x-4">
                            <a href="#" class="text-gray-500 hover:text-primary-500" aria-label="Twitter">
                                <i class="fab fa-twitter text-lg"></i>
                            </a>
                            <a href="#" class="text-gray-500 hover:text-primary-500" aria-label="Instagram">
                                <i class="fab fa-instagram text-lg"></i>
                            </a>
                            <a href="#" class="text-gray-500 hover:text-primary-500" aria-label="Facebook">
                                <i class="fab fa-facebook text-lg"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-6 pt-6 border-t border-gray-100 text-center text-gray-500 text-xs">
                © <?php echo date('Y'); ?> CompanionX. All rights reserved. This platform does not provide medical advice.
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        // Chart.js Fallback
        if (typeof Chart === 'undefined') {
            console.error('Chart.js failed to load');
            document.getElementById('moodTrendChart').parentElement.innerHTML = '<p class="text-gray-600 text-center text-sm">Unable to load mood trend chart. Please refresh the page or contact support.</p>';
        } else {
            // Mood Trend Chart
            const ctx = document.getElementById('moodTrendChart').getContext('2d');
            const moodData = <?php
                $moodMap = ['Happy' => 80, 'Excited' => 70, 'Neutral' => 50, 'Sad' => 30, 'Angry' => 20];
                $labels = [];
                $data = [];
                foreach (array_slice($moodEntries, 0, 7) as $entry) {
                    $labels[] = date('D', strtotime($entry['created_at']));
                    $data[] = $moodMap[$entry['mood']] * ($entry['intensity'] / 10);
                }
                echo json_encode([
                    'labels' => $labels ?: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    'data' => $data ?: [65, 59, 70, 71, 56, 55, 68]
                ]);
            ?>;
            const moodTrendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: moodData.labels,
                    datasets: [{
                        label: 'Mood Score',
                        data: moodData.data,
                        backgroundColor: 'rgba(0, 188, 212, 0.1)',
                        borderColor: 'rgba(0, 188, 212, 1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: 'rgba(0, 188, 212, 1)',
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Mood: ${context.parsed.y}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            min: 0,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            },
                            grid: { color: 'rgba(0, 0, 0, 0.05)' }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });
        }

        // Modal and Notification Handling
        const entryModal = document.getElementById('entryModal');
        const newEntryBtn = document.getElementById('newEntryBtn');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const moodForm = document.getElementById('moodForm');
        const moodOptions = document.querySelectorAll('.mood-option');
        const selectedMoodInput = document.getElementById('selectedMood');
        const formMessage = document.getElementById('formMessage');
        const submitMoodBtn = document.getElementById('submitMoodBtn');
        const formSpinner = document.getElementById('formSpinner');
        const intensityInput = document.getElementById('intensity');
        const intensityValue = document.getElementById('intensityValue');
        const moodError = document.getElementById('moodError');
        const notesModal = document.getElementById('notesModal');
        const closeNotesModalBtn = document.getElementById('closeNotesModalBtn');
        const notesContent = document.getElementById('notesContent');
        const periodButtons = document.querySelectorAll('[data-period]');
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationPopup = document.getElementById('notificationPopup');
        const clearNotifications = document.getElementById('clearNotifications');

        newEntryBtn.addEventListener('click', () => {
            entryModal.classList.remove('hidden');
            moodOptions[0].focus();
        });

        closeModalBtn.addEventListener('click', resetForm);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !entryModal.classList.contains('hidden')) resetForm();
            if (e.key === 'Escape' && !notesModal.classList.contains('hidden')) notesModal.classList.add('hidden');
            if (e.key === 'Escape' && !notificationPopup.classList.contains('hidden')) notificationPopup.classList.add('hidden');
        });

        window.addEventListener('click', (e) => {
            if (e.target === entryModal) resetForm();
            if (e.target === notesModal) notesModal.classList.add('hidden');
            if (!notificationPopup.contains(e.target) && !notificationBtn.contains(e.target)) {
                notificationPopup.classList.add('hidden');
            }
        });

        closeNotesModalBtn.addEventListener('click', () => notesModal.classList.add('hidden'));

        moodOptions.forEach(option => {
            option.addEventListener('click', () => selectMood(option));
            option.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    selectMood(option);
                }
            });
        });

        document.querySelectorAll('.mood-card').forEach(card => {
            card.addEventListener('click', () => {
                notesContent.textContent = card.dataset.notes || 'No notes available.';
                notesModal.classList.remove('hidden');
                closeNotesModalBtn.focus();
            });
            card.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    notesContent.textContent = card.dataset.notes || 'No notes available.';
                    notesModal.classList.remove('hidden');
                    closeNotesModalBtn.focus();
                }
            });
        });

        intensityInput.addEventListener('input', () => {
            intensityValue.textContent = intensityInput.value;
            intensityInput.setAttribute('aria-valuenow', intensityInput.value);
        });

        periodButtons.forEach(button => {
            button.addEventListener('click', () => {
                periodButtons.forEach(btn => {
                    btn.classList.remove('bg-primary-500', 'text-white');
                    btn.classList.add('bg-gray-100', 'text-gray-600');
                });
                button.classList.remove('bg-gray-100', 'text-gray-600');
                button.classList.add('bg-primary-500', 'text-white');
                fetchMoodHistory(button.dataset.period);
            });
        });

        notificationBtn.addEventListener('click', () => {
            notificationPopup.classList.toggle('hidden');
            if (!notificationPopup.classList.contains('hidden')) fetchNotifications();
        });

        clearNotifications.addEventListener('click', () => {
            fetch('clear_notifications.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('notificationList').innerHTML = '<p class="p-4 text-gray-500 text-sm">No notifications</p>';
                }
            });
        });

        function fetchNotifications() {
            fetch('get_notifications.php')
                .then(response => response.json())
                .then(data => {
                    const notificationList = document.getElementById('notificationList');
                    if (!data.success || data.notifications.length === 0) {
                        notificationList.innerHTML = '<p class="p-4 text-gray-500 text-sm">No notifications</p>';
                        return;
                    }
                    notificationList.innerHTML = data.notifications.map(n => `
                        <div class="p-3 border-b border-gray-100 hover:bg-gray-50 transition">
                            <p class="text-sm text-gray-600">${n.message}</p>
                            <p class="text-xs text-gray-400">${n.timestamp}</p>
                        </div>
                    `).join('');
                });
        }

        function fetchMoodHistory(period) {
            fetch(`get_mood_history.php?period=${period}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(response => response.json())
                .then(data => {
                    const moodHistory = document.getElementById('moodHistory');
                    if (!data.success || data.entries.length === 0) {
                        moodHistory.innerHTML = '<p class="text-gray-600 text-center text-sm">No mood entries found for this period.</p>';
                        return;
                    }
                    const moodStyles = {
                        'Happy': ['bg-green-50', 'bg-green-100', 'text-green-700', 'fas fa-smile'],
                        'Sad': ['bg-purple-50', 'bg-purple-100', 'text-purple-700', 'fas fa-sad-tear'],
                        'Neutral': ['bg-blue-50', 'bg-blue-100', 'text-blue-700', 'fas fa-meh'],
                        'Angry': ['bg-red-50', 'bg-red-100', 'text-red-700', 'fas fa-angry'],
                        'Excited': ['bg-yellow-50', 'bg-yellow-100', 'text-yellow-700', 'fas fa-laugh']
                    };
                    moodHistory.innerHTML = data.entries.map(entry => {
                        const styles = moodStyles[entry.mood];
                        const date = new Date(entry.created_at).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
                        const tooltip = entry.notes ? entry.notes.replace(/"/g, '&quot;') : 'No notes';
                        return `
                            <div class="mood-card ${styles[0]} rounded-lg p-3 text-center card-hover transition cursor-pointer" role="button" tabindex="0" aria-label="Mood entry: ${entry.mood} on ${date}" data-notes="${tooltip}">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-full ${styles[1]} flex items-center justify-center ${styles[2]}">
                                    <i class="${styles[3]} text-xl"></i>
                                </div>
                                <div class="text-sm font-medium ${styles[2]}">${entry.mood}</div>
                                <div class="text-xs text-gray-500">${date}</div>
                            </div>
                        `;
                    }).join('');
                    // Re-attach event listeners for new mood cards
                    document.querySelectorAll('.mood-card').forEach(card => {
                        card.addEventListener('click', () => {
                            notesContent.textContent = card.dataset.notes || 'No notes available.';
                            notesModal.classList.remove('hidden');
                            closeNotesModalBtn.focus();
                        });
                        card.addEventListener('keydown', (e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                notesContent.textContent = card.dataset.notes || 'No notes available.';
                                notesModal.classList.remove('hidden');
                                closeNotesModalBtn.focus();
                            }
                        });
                    });
                });
        }

        moodForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!selectedMoodInput.value) {
                moodError.classList.remove('hidden');
                formMessage.classList.remove('hidden');
                formMessage.classList.add('text-red-600');
                formMessage.textContent = 'Please select a mood.';
                return;
            }

            submitMoodBtn.disabled = true;
            formSpinner.classList.remove('hidden');
            formMessage.classList.remove('hidden');
            formMessage.classList.add('text-primary-600');
            formMessage.textContent = 'Submitting mood entry...';

            try {
                const response = await fetch(moodForm.action, {
                    method: 'POST',
                    body: new FormData(moodForm)
                });

                const result = await response.json();
                formSpinner.classList.add('hidden');
                formMessage.classList.remove('text-primary-600');
                if (result.success) {
                    formMessage.classList.add('text-green-600');
                    formMessage.textContent = result.message;
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    formMessage.classList.add('text-red-600');
                    formMessage.textContent = result.message;
                    submitMoodBtn.disabled = false;
                }
            } catch (error) {
                formSpinner.classList.add('hidden');
                formMessage.classList.add('text-green-600');
                formMessage.textContent = 'New Mood Entry Successfull! Please Reload the window ';
                submitMoodBtn.disabled = false;
            }
        });

        function selectMood(option) {
            moodOptions.forEach(opt => opt.classList.remove('ring-2', 'ring-primary-500'));
            option.classList.add('ring-2', 'ring-primary-500');
            selectedMoodInput.value = option.dataset.mood;
            moodError.classList.add('hidden');
        }

        function resetForm() {
            entryModal.classList.add('hidden');
            moodForm.reset();
            moodOptions.forEach(opt => opt.classList.remove('ring-2', 'ring-primary-500'));
            selectedMoodInput.value = '';
            intensityValue.textContent = '5';
            intensityInput.setAttribute('aria-valuenow', '5');
            formMessage.classList.add('hidden');
            formSpinner.classList.add('hidden');
            submitMoodBtn.disabled = false;
            moodError.classList.add('hidden');
        }

        // Show notification if no recent mood
        <?php if (!$hasRecentMood): ?>
            setTimeout(() => {
                const notificationList = document.getElementById('notificationList');
                notificationList.innerHTML = `
                    <div class="p-3 border-b border-gray-100">
                        <p class="text-sm text-gray-600">How are you feeling today? Log your mood now!</p>
                        <p class="text-xs text-gray-400"><?php echo date('Y-m-d H:i:s'); ?></p>
                    </div>
                `;
                notificationPopup.classList.remove('hidden');
                setTimeout(() => notificationPopup.classList.add('hidden'), 5000);
            }, 1000);
        <?php endif; ?>
    </script>
</body>
</html>