<?php
session_start();
include 'config/db.php';

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header("Location: login.php");
    exit();
}

// Fetch initial progress data (last 30 days)
$sql = "SELECT score, DATE_FORMAT(created_at, '%Y-%m-%d') AS date 
        FROM user_progress 
        WHERE user_id = :user_id 
        ORDER BY created_at ASC 
        LIMIT 30";
$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$progress = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary statistics
$sql = "SELECT AVG(score) AS avg_score, COUNT(*) AS total_exercises 
        FROM user_activities 
        WHERE user_id = :user_id";
$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch daily task completion
$sql = "SELECT COUNT(*) AS completed_today 
        FROM user_activities 
        WHERE user_id = :user_id 
        AND DATE(created_at) = CURDATE()";
$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$daily_completed = $stmt->fetch(PDO::FETCH_ASSOC)['completed_today'] >= 3;

// Fetch achievements
$sql = "SELECT achievement_name, description, icon, DATE_FORMAT(awarded_at, '%Y-%m-%d') AS date 
        FROM achievements 
        WHERE user_id = :user_id 
        ORDER BY awarded_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$js_data = [
    'progress' => [
        'dates' => array_column($progress, 'date'),
        'scores' => array_column($progress, 'score')
    ],
    'stats' => $stats,
    'daily_completed' => $daily_completed,
    'achievements' => $achievements
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CompanionX - Progress</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .bg-gradient-to-br { background: linear-gradient(to bottom right, #e0f7fa, #e9d5ff); }
    </style>
</head>
<body class="bg-gradient-to-br from-indigo-50 to-purple-50 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <header class="flex justify-between items-center mb-10">
            <div class="flex items-center">
                <div class="bg-indigo-600 text-white p-3 rounded-xl mr-3">
                    <i class="fas fa-brain text-2xl"></i>
                </div>
                <h1 class="text-3xl font-bold text-indigo-900">CompanionX</h1>
            </div>
            <div class="flex items-center space-x-4">
                <a href="dashboard.php" class="bg-indigo-600 text-white px-4 py-2 rounded-lg shadow">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                </a>
            </div>
        </header>

        <div class="flex flex-col lg:flex-row gap-8">
            <aside class="w-full lg:w-64 bg-white rounded-2xl shadow-lg p-6 h-fit">
                <h2 class="text-xl font-semibold text-gray-800 mb-6">Mind Gym</h2>
                <nav>
                    <ul class="space-y-3">
                        <li><a href="mental_exercises.php" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-100 rounded-lg"><i class="fas fa-brain mr-3"></i>Mental Wellness</a></li>
                        <li><a href="progress.php" class="flex items-center px-4 py-3 bg-indigo-100 text-indigo-700 rounded-lg"><i class="fas fa-chart-line mr-3"></i>Progress</a></li>
                        <li><a href="mental_exercises.php#daily-tasks" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-100 rounded-lg"><i class="fas fa-medal mr-3"></i>Daily Tasks</a></li>
                        <li><a href="mental_exercises.php#achievements" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-100 rounded-lg"><i class="fas fa-trophy mr-3"></i>Achievements</a></li>
                    </ul>
                </nav>
            </aside>

            <main class="flex-1">
                <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                    <div class="bg-gradient-to-r from-indigo-500 to-purple-600 p-6 text-white">
                        <h2 class="text-2xl font-bold">Your Progress</h2>
                        <p class="text-indigo-100">Track your mental wellness journey</p>
                    </div>
                    <div class="p-6">
                        <!-- Wellness and Mood Trends -->
                        <div class="mb-8">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="text-lg font-semibold text-gray-800">Wellness & Mood Trends</h3>
                                <select id="timeRange" class="bg-gray-100 border-0 rounded-lg text-sm px-3 py-1">
                                    <option>Last 7 Days</option>
                                    <option selected>Last 30 Days</option>
                                    <option>Last 90 Days</option>
                                </select>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-6">
                                <canvas id="progressChart" height="300"></canvas>
                            </div>
                        </div>

                        <!-- Daily Tasks -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Daily Tasks</h3>
                            <div id="dailyTasks" class="bg-indigo-50 rounded-xl p-4">
                                <?php if ($js_data['daily_completed']): ?>
                                    <p class="text-green-800"><i class="fas fa-check-circle mr-2"></i>All daily tasks completed! Great job!</p>
                                <?php else: ?>
                                    <p class="text-gray-600">Complete your daily tasks in <a href="mental_exercises.php#daily-tasks" class="text-indigo-600 hover:underline">Mental Wellness</a>.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Mental Health Needs -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Your Mental Health Needs</h3>
                            <div id="needsList" class="bg-indigo-50 rounded-xl p-4">
                                <p class="text-gray-600">Loading your needs...</p>
                            </div>
                        </div>

                        <!-- Achievements -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Achievements</h3>
                            <div id="achievementsList" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                <?php foreach ($achievements as $ach): ?>
                                    <div class="bg-yellow-50 rounded-xl p-4">
                                        <div class="flex items-center">
                                            <i class="<?php echo htmlspecialchars($ach['icon']); ?> text-yellow-500 text-2xl mr-3"></i>
                                            <div>
                                                <h4 class="text-lg font-medium text-indigo-900"><?php echo htmlspecialchars($ach['achievement_name']); ?></h4>
                                                <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($ach['description']); ?></p>
                                                <p class="text-gray-500 text-xs"><?php echo htmlspecialchars($ach['date']); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($achievements)): ?>
                                    <p class="text-gray-600">No achievements yet. Keep practicing!</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Summary Statistics -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Summary Statistics</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="bg-indigo-50 rounded-xl p-4">
                                    <h4 class="text-sm font-medium text-indigo-800">Average Wellness Score</h4>
                                    <p class="text-2xl font-bold text-indigo-900"><?php echo round($stats['avg_score'], 1); ?>%</p>
                                </div>
                                <div class="bg-indigo-50 rounded-xl p-4">
                                    <h4 class="text-sm font-medium text-indigo-800">Total Exercises Completed</h4>
                                    <p class="text-2xl font-bold text-indigo-900"><?php echo $stats['total_exercises']; ?></p>
                                </div>
                                <div class="bg-indigo-50 rounded-xl p-4">
                                    <h4 class="text-sm font-medium text-indigo-800">Completion Rate</h4>
                                    <p class="text-2xl font-bold text-indigo-900" id="completionRate">0%</p>
                                </div>
                                <div class="bg-indigo-50 rounded-xl p-4">
                                    <h4 class="text-sm font-medium text-indigo-800">Most Active Category</h4>
                                    <p class="text-2xl font-bold text-indigo-900" id="topCategory">N/A</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const progressData = <?php echo json_encode($js_data); ?>;
        const ctx = document.getElementById('progressChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: progressData.progress.dates.length ? progressData.progress.dates : ['No Data'],
                datasets: [
                    {
                        label: 'Wellness Score',
                        data: progressData.progress.scores.length ? progressData.progress.scores : [0],
                        borderColor: 'rgba(79, 70, 229, 1)',
                        backgroundColor: 'rgba(79, 70, 229, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'Mood Score',
                        data: [],
                        borderColor: 'rgba(236, 72, 153, 1)',
                        backgroundColor: 'rgba(236, 72, 153, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: true },
                    tooltip: { mode: 'index', intersect: false }
                },
                scales: {
                    y: {
                        min: 0,
                        max: 100,
                        ticks: {
                            callback: value => {
                                if (value === 80) return 'Happy';
                                if (value === 70) return 'Excited';
                                if (value === 50) return 'Neutral';
                                if (value === 30) return 'Sad';
                                if (value === 20) return 'Angry';
                                return value + '%';
                            }
                        }
                    },
                    x: { grid: { display: false } }
                }
            }
        });

        async function updateProgress(days) {
            const response = await fetch(`fetch_progress.php?days=${days}&user_id=<?php echo $user_id; ?>`);
            const data = await response.json();
            if (data.success) {
                chart.data.labels = data.progress.dates.length ? data.progress.dates : ['No Data'];
                chart.data.datasets[0].data = data.progress.scores.length ? data.progress.scores : [0];
                chart.data.datasets[1].data = data.moods.length ? data.moods.map(m => m.score) : [0];
                chart.update();

                // Update needs
                const needsList = document.getElementById('needsList');
                needsList.innerHTML = data.needs.length 
                    ? `<p class="text-gray-600">Focus areas: ${data.needs.join(', ')}</p>`
                    : `<p class="text-gray-600">No specific needs identified. Answer more questions in Anonymous Counselling.</p>`;

                // Update stats
                const completionRate = data.activity_stats.length 
                    ? Math.round(data.activity_stats.reduce((sum, stat) => sum + (stat.avg_score >= 80 ? stat.count : 0), 0) / data.activity_stats.reduce((sum, stat) => sum + stat.count, 0) * 100)
                    : 0;
                document.getElementById('completionRate').textContent = `${completionRate}%`;

                const topCategory = data.activity_stats.length 
                    ? data.activity_stats.reduce((max, stat) => stat.count > max.count ? stat : max, data.activity_stats[0]).exercise_type
                    : 'N/A';
                document.getElementById('topCategory').textContent = topCategory;

                // Update daily tasks
                const dailyTasks = document.getElementById('dailyTasks');
                dailyTasks.innerHTML = data.daily_completed 
                    ? `<p class="text-green-800"><i class="fas fa-check-circle mr-2"></i>All daily tasks completed! Great job!</p>`
                    : `<p class="text-gray-600">Complete your daily tasks in <a href="mental_exercises.php#daily-tasks" class="text-indigo-600 hover:underline">Mental Wellness</a>.</p>`;
            } else {
                console.error('Error fetching progress:', data.error);
            }
        }

        document.getElementById('timeRange').addEventListener('change', (e) => {
            const range = e.target.value;
            const days = range === 'Last 7 Days' ? 7 : range === 'Last 30 Days' ? 30 : 90;
            updateProgress(days);
        });

        // Initial load
        updateProgress(30);
    </script>
</body>
</html>