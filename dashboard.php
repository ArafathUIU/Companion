<?php
require_once 'config/db.php';
require_once 'includes/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

$firstName = htmlspecialchars($user['first_name']);
$userId = '#' . str_pad($user['id'], 4, '0', STR_PAD_LEFT);
$avatar = isset($user['avatar']) ? htmlspecialchars($user['avatar']) : 'f1';

// Count mood entries
$stmt = $pdo->prepare("SELECT COUNT(*) as entry_count FROM user_mood_entries WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$entryCount = $stmt->fetch()['entry_count'];

// Check for recent mood entries (last 24 hours)
$stmt = $pdo->prepare("SELECT COUNT(*) as recent_count FROM user_mood_entries WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 DAY");
$stmt->execute([$_SESSION['user_id']]);
$hasRecentMood = $stmt->fetch()['recent_count'] > 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CompanionX - Your Mental Health Companion</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f0f8ff;
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 50%, #80deea 100%);
        }
        
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .modal-overlay {
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
        }
        
        .avatar-selection:hover {
            transform: scale(1.1);
            filter: drop-shadow(0 0 8px rgba(100, 181, 246, 0.8));
        }

        #notificationPopup {
            transition: opacity 0.3s ease;
        }
        #notificationPopup.hidden {
            opacity: 0;
            pointer-events: none;
        }
        .notification-item {
            padding: 1rem;
            border-bottom: 1px solid #f1f1f1;
        }
        .notification-item:hover {
            background-color: #f9fafb;
        }

        .mood-emoji {
            transition: all 0.2s ease;
        }
        .mood-emoji:hover {
            transform: scale(1.2);
        }
        .mood-selected {
            transform: scale(1.3);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.3);
        }
        .selected-blue {
            background-color: #3b82f6 !important;
            color: white !important;
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

        function getAvatarUrl(avatarCode) {
            const avatars = {
                'f1': 'avatars/avatar1.avif',
                'f2': 'avatars/avatar2.avif',
                'f3': 'avatars/avatar3.jpg',
                'm1': 'avatars/avatar4.webp',
                'm2': 'avatars/avatar5.webp'
            };
            return avatars[avatarCode] || avatars['f1'];
        }

        function updateAvatar(avatarCode) {
            const avatarImg = document.querySelector('#userAvatar img');
            if (avatarImg) {
                avatarImg.src = getAvatarUrl(avatarCode);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Verify Chart.js is loaded
            if (typeof Chart === 'undefined') {
                console.error('Chart.js failed to load');
                document.getElementById('moodChart').parentElement.innerHTML = '<p class="text-gray-500">Chart library not loaded</p>';
                return;
            }

            // Avatar Modal
            const isAvatarSelected = localStorage.getItem('companionx_avatar_selected');
            if (!isAvatarSelected) {
                document.getElementById('avatarModal').classList.remove('hidden');
            }
            
            const avatars = document.querySelectorAll('.avatar-option');
            avatars.forEach(avatar => {
                avatar.addEventListener('click', function() {
                    avatars.forEach(a => a.classList.remove('ring-4', 'ring-blue-400'));
                    this.classList.add('ring-4', 'ring-blue-400');
                    document.getElementById('selectedAvatar').value = this.dataset.avatar;
                });
            });
            
            document.getElementById('avatarForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const selected = document.getElementById('selectedAvatar').value;
                const skip = document.getElementById('skipAvatar').checked;
                
                if (selected || skip) {
                    const avatar = selected || 'f1';
                    localStorage.setItem('companionx_avatar_selected', avatar);
                    
                    fetch('update_avatar.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ avatar: avatar })
                    }).then(response => response.json()).then(data => {
                        if (data.success) {
                            updateAvatar(avatar);
                            document.getElementById('avatarModal').classList.add('hidden');
                        }
                    });
                }
            });
            
            document.getElementById('closeModal').addEventListener('click', function() {
                document.getElementById('avatarModal').classList.add('hidden');
                localStorage.setItem('companionx_avatar_selected', 'f1');
                updateAvatar('f1');
            });

            // Mood Chart Initialization
            const moodChartCtx = document.getElementById('moodChart').getContext('2d');
            let moodChart;

            function updateMoodChart() {
                fetch('get_mood_data.php')
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            document.getElementById('moodChart').parentElement.innerHTML = '<p class="text-gray-500">No mood data available</p>';
                            return;
                        }
                        if (moodChart) {
                            moodChart.destroy();
                        }
                        moodChart = new Chart(moodChartCtx, {
                            type: 'line',
                            data: {
                                labels: data.labels,
                                datasets: [{
                                    label: 'Mood Score',
                                    data: data.moods,
                                    borderColor: '#3b82f6',
                                    backgroundColor: 'rgba(59, 130, 246, 0.2)',
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 5,
                                    pointBackgroundColor: '#3b82f6'
                                }]
                            },
                            options: {
                                responsive: true,
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        max: 100,
                                        title: { display: true, text: 'Mood Score' },
                                        ticks: {
                                            callback: function(value) {
                                                if (value === 80) return 'Happy';
                                                if (value === 70) return 'Excited';
                                                if (value === 50) return 'Neutral';
                                                if (value === 30) return 'Sad';
                                                if (value === 20) return 'Angry';
                                                return value;
                                            }
                                        }
                                    },
                                    x: {
                                        title: { display: true, text: 'Day of Week' }
                                    }
                                },
                                plugins: {
                                    legend: { display: true },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                let score = context.raw;
                                                let mood = score === 80 ? 'Happy' :
                                                           score === 70 ? 'Excited' :
                                                           score === 50 ? 'Neutral' :
                                                           score === 30 ? 'Sad' :
                                                           score === 20 ? 'Angry' : 'None';
                                                return `Mood: ${mood} (Score: ${score})`;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    });
            }

            // Initial chart load
            updateMoodChart();

            // Mood Submission
            const moodLabels = document.querySelectorAll('.mood-emoji');
            const moodInputs = document.querySelectorAll('.mood-radio');
            const moodNotes = document.getElementById('moodNotes');
            const moodFeedback = document.getElementById('moodFeedback');

            moodLabels.forEach(label => {
                label.addEventListener('click', () => {
                    moodLabels.forEach(l => l.classList.remove('mood-selected', 'selected-blue'));
                    label.classList.add('mood-selected', 'selected-blue');
                    const mood = label.querySelector('input').value;
                    const notes = moodNotes.value.trim();
                    fetch('update_mood.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({ mood: mood, notes: notes })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            updateMoodChart();
                            moodFeedback.classList.remove('hidden');
                            moodFeedback.querySelector('p').textContent = 'Mood logged! Check your insights in Mood Prediction.';
                            setTimeout(() => moodFeedback.classList.add('hidden'), 3000);
                            moodNotes.value = '';
                            // Update entry count
                            fetch('get_mood_data.php').then(res => res.json()).then(data => {
                                if (data.success) {
                                    const count = data.labels.length;
                                    const progress = document.getElementById('moodProgress');
                                    if (progress) {
                                        progress.style.width = `${(count / 14) * 100}%`;
                                        document.getElementById('entryCount').textContent = `${count}/14`;
                                    }
                                }
                            });
                        } else {
                            alert('Error updating mood: ' + data.error);
                            label.classList.remove('mood-selected', 'selected-blue');
                        }
                    })
                    .catch(error => {
                        alert('Error connecting to server');
                        label.classList.remove('mood-selected', 'selected-blue');
                    });
                });
            });

            // Notification Popup
            const notificationButton = document.querySelector('.fa-bell').parentElement;
            const notificationPopup = document.getElementById('notificationPopup');
            const clearNotificationsButton = document.getElementById('clearNotifications');

            notificationButton.addEventListener('click', () => {
                notificationPopup.classList.toggle('hidden');
                if (!notificationPopup.classList.contains('hidden')) {
                    fetchNotifications();
                }
            });

            document.addEventListener('click', (e) => {
                if (!notificationPopup.contains(e.target) && !notificationButton.contains(e.target)) {
                    notificationPopup.classList.add('hidden');
                }
            });

            clearNotificationsButton.addEventListener('click', () => {
                fetch('clear_notifications.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('notificationList').innerHTML = '<p class="p-4 text-gray-500">No notifications</p>';
                    }
                });
            });

            function fetchNotifications() {
                fetch('get_notifications.php')
                    .then(response => response.json())
                    .then(data => {
                        const notificationList = document.getElementById('notificationList');
                        if (!data.success || data.notifications.length === 0) {
                            notificationList.innerHTML = '<p class="p-4 text-gray-500">No notifications</p>';
                            return;
                        }
                        notificationList.innerHTML = data.notifications.map(n => `
                            <div class="notification-item">
                                <p class="text-sm text-gray-600">${n.message}</p>
                                <p class="text-xs text-gray-400">${n.timestamp}</p>
                            </div>
                        `).join('');
                    });
            }

            // Show notification if no recent mood
            <?php if (!$hasRecentMood): ?>
                setTimeout(() => {
                    const notificationList = document.getElementById('notificationList');
                    notificationList.innerHTML = `
                        <div class="notification-item">
                            <p class="text-sm text-gray-600">How are you feeling today? Log your mood below!</p>
                            <p class="text-xs text-gray-400"><?php echo date('Y-m-d H:i:s'); ?></p>
                        </div>
                    `;
                    notificationPopup.classList.remove('hidden');
                    setTimeout(() => notificationPopup.classList.add('hidden'), 5000);
                }, 1000);
            <?php endif; ?>
        });
    </script>
</head>
<body class="min-h-screen gradient-bg">
    <!-- Avatar Selection Modal -->
    <div id="avatarModal" class="hidden fixed inset-0 z-50 flex items-center justify-center modal-overlay">
        <div class="bg-white rounded-2xl p-8 w-full max-w-2xl mx-4">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-semibold text-gray-800">Welcome to CompanionX</h2>
                <button id="closeModal" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="text-gray-600 mb-6">Let's personalize your experience. Choose an avatar that represents you:</p>
            <form id="avatarForm">
                <div class="grid grid-cols-3 sm:grid-cols-5 gap-4 mb-8">
                    <div class="avatar-selection transition-all cursor-pointer p-2 rounded-full avatar-option" data-avatar="f1">
                        <img src="avatars/avatar1.avif" alt="Female Avatar 1" class="w-full rounded-full">
                    </div>
                    <div class="avatar-selection transition-all cursor-pointer p-2 rounded-full avatar-option" data-avatar="f2">
                        <img src="avatars/avatar2.avif" alt="Female Avatar 2" class="w-full rounded-full">
                    </div>
                    <div class="avatar-selection transition-all cursor-pointer p-2 rounded-full avatar-option" data-avatar="f3">
                        <img src="avatars/avatar3.jpg" alt="Female Avatar 3" class="w-full rounded-full">
                    </div>
                    <div class="avatar-selection transition-all cursor-pointer p-2 rounded-full avatar-option" data-avatar="m1">
                        <img src="avatars/avatar4.webp" alt="Male Avatar 1" class="w-full rounded-full">
                    </div>
                    <div class="avatar-selection transition-all cursor-pointer p-2 rounded-full avatar-option" data-avatar="m2">
                        <img src="avatars/avatar5.webp" alt="Male Avatar 2" class="w-full rounded-full">
                    </div>
                </div>
                <input type="hidden" id="selectedAvatar" name="selectedAvatar">
                <div class="flex items-center mb-6">
                    <input type="checkbox" id="skipAvatar" class="mr-2">
                    <label for="skipAvatar" class="text-gray-600">Skip for now</label>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="submit" class="px-6 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition">
                        Continue
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Main Dashboard -->
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <header class="flex justify-between items-center mb-10">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-primary-500 rounded-full flex items-center justify-center text-white mr-3">
                    <i class="fas fa-brain text-2xl"></i>
                </div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">CompanionX</h1>
            </div>
            <div class="flex items-center space-x-4">
                <button class="p-2 text-gray-600 hover:text-primary-500">
                    <i class="fas fa-bell text-xl"></i>
                </button>
                <div class="flex items-center space-x-2">
                    <div class="text-sm text-gray-600">ID: <span id="userID"><?php echo $userId; ?></span></div>
                    <div id="userAvatar" class="w-10 h-10 rounded-full overflow-hidden border-2 border-primary-200">
                        <img src="<?php echo htmlspecialchars($avatar); ?>" alt="User Avatar" class="w-full h-full object-cover" onload="this.src=getAvatarUrl(this.src)">
                    </div>
                </div>
                <a href="logout.php" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition">Logout</a>
            </div>
        </header>

        <!-- Notification Popup -->
        <div id="notificationPopup" class="hidden absolute right-4 top-16 bg-white rounded-lg shadow-lg w-80 z-50">
            <div class="p-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Notifications</h3>
            </div>
            <div id="notificationList" class="max-h-64 overflow-y-auto">
                <!-- Notifications will be populated here -->
            </div>
            <div class="p-4 border-t border-gray-200">
                <button id="clearNotifications" class="text-primary-500 hover:text-primary-600 text-sm">Clear All</button>
            </div>
        </div>
        
        <!-- Welcome Section -->
        <section class="mb-10">
            <div class="bg-white rounded-2xl p-6 shadow-sm">
                <h2 class="text-xl sm:text-2xl font-semibold text-gray-800 mb-2">Welcome back, <?php echo $firstName; ?>!</h2>
                <p class="text-gray-600 mb-4">How are you feeling today? Remember, you're not alone in this journey.</p>
                
                <?php if ($entryCount < 14): ?>
                    <div class="bg-blue-100 p-4 rounded-lg mb-4">
                        <p class="text-blue-800">Mood entries: <span id="entryCount"><?php echo $entryCount; ?>/14</span></p>
                        <div class="w-full bg-gray-200 rounded-full h-2.5 mt-2">
                            <div id="moodProgress" class="bg-blue-600 h-2.5 rounded-full" style="width: <?php echo ($entryCount / 14) * 100; ?>%"></div>
                        </div>
                        <p class="text-sm text-gray-600 mt-2">Log <?php echo 14 - $entryCount; ?> more entries for mood predictions!</p>
                    </div>
                <?php endif; ?>
                
                <div id="moodFeedback" class="hidden bg-green-100 p-3 rounded-lg mb-4">
                    <p class="text-green-800"></p>
                </div>
                
                <div class="flex flex-wrap gap-3 mb-4">
                    <input type="radio" name="mood" id="happy" value="Happy" class="hidden mood-radio" />
                    <label for="happy" class="mood-emoji cursor-pointer">
                        <div class="px-4 py-2 bg-primary-50 hover:bg-primary-100 text-primary-600 rounded-full flex items-center">
                            <span class="text-lg mr-1">😄</span> Happy
                        </div>
                    </label>
                    <input type="radio" name="mood" id="calm" value="Calm" class="hidden mood-radio" />
                    <label for="calm" class="mood-emoji cursor-pointer">
                        <div class="px-4 py-2 bg-primary-50 hover:bg-primary-100 text-primary-600 rounded-full flex items-center">
                            <span class="text-lg mr-1">😊</span> Neutral
                        </div>
                    </label>
                    <input type="radio" name="mood" id="anxious" value="Anxious" class="hidden mood-radio" />
                    <label for="anxious" class="mood-emoji cursor-pointer">
                        <div class="px-4 py-2 bg-primary-50 hover:bg-primary-100 text-primary-600 rounded-full flex items-center">
                            <span class="text-lg mr-1">😐</span> Sad
                        </div>
                    </label>
                    <input type="radio" name="mood" id="sad" value="Sad" class="hidden mood-radio" />
                    <label for="sad" class="mood-emoji cursor-pointer">
                        <div class="px-4 py-2 bg-primary-50 hover:bg-primary-100 text-primary-600 rounded-full flex items-center">
                            <span class="text-lg mr-1">😔</span> Sad
                        </div>
                    </label>
                    <input type="radio" name="mood" id="angry" value="Angry" class="hidden mood-radio" />
                    <label for="angry" class="mood-emoji cursor-pointer">
                        <div class="px-4 py-2 bg-primary-50 hover:bg-primary-100 text-primary-600 rounded-full flex items-center">
                            <span class="text-lg mr-1">😠</span> Angry
                        </div>
                    </label>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Notes (optional)</label>
                    <textarea id="moodNotes" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" rows="3" placeholder="What's influencing your mood today?"></textarea>
                </div>
            </div>
        </section>

        <!-- Features Grid -->
        <section class="mb-10">
            <h2 class="text-xl font-semibold text-gray-800 mb-6">Your Mental Health Tools</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <a href="anonymous_counselling.php" class="bg-white rounded-xl p-6 shadow-sm transition-all duration-300 card-hover block">
                    <div class="w-14 h-14 bg-primary-50 rounded-xl flex items-center justify-center text-primary-500 mb-4">
                        <i class="fas fa-user-secret text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Anonymous Counselling</h3>
                    <p class="text-gray-600 mb-4">Connect with certified professionals while maintaining complete anonymity.</p>
                    <span class="text-primary-500 hover:text-primary-600 font-medium">Explore →</span>
                </a>
                <a href="mood_prediction.php" class="bg-white rounded-xl p-6 shadow-sm transition-all duration-300 card-hover block">
                    <div class="w-14 h-14 bg-purple-50 rounded-xl flex items-center justify-center text-purple-500 mb-4">
                        <i class="fas fa-chart-line text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Mood Prediction</h3>
                    <p class="text-gray-600 mb-4">Our AI analyzes your mood patterns to help you prepare for challenging days.</p>
                    <span class="text-purple-500 hover:text-purple-600 font-medium">View Insights →</span>
                </a>
                <!-- Other feature cards remain unchanged -->
                <a href="mood_journal.php" class="bg-white rounded-xl p-6 shadow-sm transition-all duration-300 card-hover block">
                    <div class="w-14 h-14 bg-secondary-50 rounded-xl flex items-center justify-center text-secondary-500 mb-4">
                        <i class="fas fa-book-open text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Mood Journal</h3>
                    <p class="text-gray-600 mb-4">Track your emotions, identify patterns, and gain personal insights over time.</p>
                    <span class="text-secondary-500 hover:text-secondary-600 font-medium">Start Journaling →</span>
                </a>
                <a href="community_post.php" class="bg-white rounded-xl p-6 shadow-sm transition-all duration-300 card-hover block">
                    <div class="w-14 h-14 bg-blue-50 rounded-xl flex items-center justify-center text-blue-500 mb-4">
                        <i class="fas fa-users text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Community</h3>
                    <p class="text-gray-600 mb-4">Share your thoughts and connect with others on similar journeys.</p>
                    <span class="text-blue-500 hover:text-blue-600 font-medium">Join Conversations →</span>
                </a>
                <a href="circle_talk.php" class="bg-white rounded-xl p-6 shadow-sm transition-all duration-300 card-hover block">
                    <div class="w-14 h-14 bg-green-50 rounded-xl flex items-center justify-center text-green-500 mb-4">
                        <i class="fas fa-comments text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Circle Talk</h3>
                    <p class="text-gray-600 mb-4">Join moderated group discussions about mental health topics.</p>
                    <span class="text-green-500 hover:text-green-600 font-medium">View Schedule →</span>
                </a>
                <a href="mental_exercises.php" class="bg-white rounded-xl p-6 shadow-sm transition-all duration-300 card-hover block">
                    <div class="w-14 h-14 bg-yellow-50 rounded-xl flex items-center justify-center text-yellow-500 mb-4">
                        <i class="fas fa-medal text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Mental Exercises</h3>
                    <p class="text-gray-600 mb-4">Interactive exercises to build resilience and emotional strength.</p>
                    <span class="text-yellow-500 hover:text-yellow-600 font-medium">Try Exercises →</span>
                </a>
                <a href="consultants_near_me.php" class="bg-white rounded-xl p-6 shadow-sm transition-all duration-300 card-hover block">
                    <div class="w-14 h-14 bg-red-50 rounded-xl flex items-center justify-center text-red-500 mb-4">
                        <i class="fas fa-map-marker-alt text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Consultants Near Me</h3>
                    <p class="text-gray-600 mb-4">Find local mental health professionals with verified ratings.</p>
                    <span class="text-red-500 hover:text-red-600 font-medium">Find Help →</span>
                </a>
                <a href="payment.php" class="bg-white rounded-xl p-6 shadow-sm transition-all duration-300 card-hover block">
                    <div class="w-14 h-14 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-500 mb-4">
                        <i class="fas fa-wallet text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Payment</h3>
                    <p class="text-gray-600 mb-4">Manage your subscriptions and payment methods securely.</p>
                    <span class="text-indigo-500 hover:text-indigo-600 font-medium">View Plans →</span>
                </a>
                <a href="http://127.0.0.1:5000/" class="bg-white rounded-xl p-6 shadow-sm transition-all duration-300 card-hover block">
                    <div class="w-14 h-14 bg-pink-50 rounded-xl flex items-center justify-center text-pink-500 mb-4">
                        <i class="fas fa-comment-medical text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Chat with Dr. Pookie</h3>
                    <p class="text-gray-600 mb-4">Our virtual therapy assistant is available 24/7 for support.</p>
                    <span class="text-pink-500 hover:text-pink-600 font-medium">Start Chat →</span>
                </a>
                <a href="user_session.php" class="bg-white rounded-xl p-6 shadow-sm transition-all duration-300 card-hover block col-span-1 sm:col-span-2 lg:col-span-1">
                    <div class="w-14 h-14 bg-blue-50 rounded-xl flex items-center justify-center text-blue-500 mb-4">
                        <i class="fas fa-video text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Join Session</h3>
                    <p class="text-gray-600 mb-4">Join your scheduled therapy session in our secure platform.</p>
                    <span class="block w-full py-3 text-center bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition">
                        Join Session
                    </span>
                </a>
            </div>
        </section>

        <!-- Weekly Mood Summary -->
        <section class="mb-10">
            <div class="bg-white rounded-2xl p-6 shadow-sm">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-800">Your Mood This Week</h2>
                    <a href="mood_prediction.php" class="text-primary-500 hover:text-primary-600 font-medium">View Details</a>
                </div>
                <canvas id="moodChart" height="100"></canvas>
                <div class="flex justify-center mt-4 space-x-6">
                    <div class="flex items-center">
                        <div class="w-3 h-3 bg-green-400 rounded-full mr-2"></div>
                        <span class="text-xs text-gray-600">Happy (80)</span>
                    </div>
                    <div class="flex items-center">
                        <div class="w-3 h-3 bg-yellow-400 rounded-full mr-2"></div>
                        <span class="text-xs text-gray-600">Excited (70)</span>
                    </div>
                    <div class="flex items-center">
                        <div class="w-3 h-3 bg-blue-400 rounded-full mr-2"></div>
                        <span class="text-xs text-gray-600">Neutral (50)</span>
                    </div>
                    <div class="flex items-center">
                        <div class="w-3 h-3 bg-purple-400 rounded-full mr-2"></div>
                        <span class="text-xs text-gray-600">Sad (30)</span>
                    </div>
                    <div class="flex items-center">
                        <div class="w-3 h-3 bg-red-400 rounded-full mr-2"></div>
                        <span class="text-xs text-gray-600">Angry (20)</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Emergency Section -->
        <section class="mb-10">
            <div class="bg-gradient-to-r from-red-50 to-pink-50 rounded-2xl p-6 shadow-sm border border-red-100">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                    <div class="mb-4 md:mb-0">
                        <h2 class="text-xl font-semibold text-gray-800 mb-2">Need Immediate Help?</h2>
                        <p class="text-gray-600">If you're in crisis, these resources are available 24/7</p>
                    </div>
                    <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-3 w-full md:w-auto">
                        <button class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition flex items-center justify-center">
                            <i class="fas fa-phone-alt mr-2"></i> Emergency Hotline
                        </button>
                        <button class="px-4 py-2 bg-white hover:bg-gray-50 text-red-500 border border-red-300 rounded-lg transition flex items-center justify-center">
                            <i class="fas fa-comment-medical mr-2"></i> Crisis Text Line
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 py-8">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-6 md:mb-0">
                    <div class="flex items-center">
                        <div classS="w-10 h-10 bg-primary-500 rounded-full flex items-center justify-center text-white mr-3">
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
                © 2023 CompanionX. All rights reserved. This platform does not provide medical advice.
            </div>
        </div>
    </footer>
</body>
</html>