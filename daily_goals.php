
<?php
session_start();
include 'config/db.php';

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header("Location: login.php");
    exit();
}

// Fetch recommended exercises as daily goals
$pythonPath = "\"C:/Users/laptop universe/AppData/Local/Programs/Python/Python311/python.exe\"";
$scriptPath = "\"C:/xampp/htdocs/Companion/recommend_exercises.py\"";
$pythonScript = "$pythonPath $scriptPath " . escapeshellarg($user_id);
$output = shell_exec($pythonScript . " 2>&1");
$recommended_exercises = $output ? json_decode($output, true) : [];

// Fetch all exercises for fallback
$sql = "SELECT id, title, description, category AS type, instructions, expected_input, duration, tags 
        FROM mental_exercises 
        ORDER BY RAND()";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$exercises = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch daily task completion
$sql = "SELECT COUNT(*) AS completed_today 
        FROM user_activities 
        WHERE user_id = :user_id 
        AND DATE(created_at) = CURDATE()";
$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$daily_completed = $stmt->fetch(PDO::FETCH_ASSOC)['completed_today'] >= 3;

// Fetch recent activities for today's goals
$sql = "SELECT exercise_title AS exercise, exercise_type AS type, score, time_taken AS time 
        FROM user_activities 
        WHERE user_id = :user_id 
        AND DATE(created_at) = CURDATE() 
        ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$today_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate streak
$sql = "SELECT COUNT(DISTINCT DATE(created_at)) AS streak 
        FROM user_activities 
        WHERE user_id = :user_id 
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$streak = $stmt->fetch(PDO::FETCH_ASSOC)['streak'];

$js_data = [
    'exercises' => $recommended_exercises ?: $exercises,
    'daily_completed' => $daily_completed,
    'today_activities' => $today_activities,
    'streak' => $streak
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CompanionX - Daily Goals</title>
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
                        <li><a href="progress.php" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-100 rounded-lg"><i class="fas fa-chart-line mr-3"></i>Progress</a></li>
                        <li><a href="daily_goals.php" class="flex items-center px-4 py-3 bg-indigo-100 text-indigo-700 rounded-lg"><i class="fas fa-medal mr-3"></i>Daily Goals</a></li>
                        <li><a href="mental_exercises.php#achievements" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-100 rounded-lg"><i class="fas fa-trophy mr-3"></i>Achievements</a></li>
                    </ul>
                </nav>
            </aside>

            <main class="flex-1">
                <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                    <div class="bg-gradient-to-r from-indigo-500 to-purple-600 p-6 text-white">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-2xl font-bold">Daily Goals</h2>
                                <p class="text-indigo-100">Complete your personalized tasks today</p>
                            </div>
                            <div class="bg-white text-indigo-600 rounded-xl px-4 py-2 font-medium">
                                <i class="fas fa-fire mr-2 text-orange-500"></i> <?php echo $streak; ?>-day streak!
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
                        <!-- Daily Goals -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Today's Goals</h3>
                            <?php if ($js_data['daily_completed']): ?>
                                <div class="bg-green-100 p-4 rounded-lg text-green-800">
                                    <i class="fas fa-check-circle mr-2"></i>All daily goals completed! Great job!
                                </div>
                            <?php else: ?>
                                <div id="goalsList" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <?php foreach ($js_data['exercises'] as $ex): if (isset($ex['is_daily_task']) && $ex['is_daily_task']): ?>
                                        <div class="bg-indigo-50 rounded-xl p-4" data-exercise-id="<?php echo htmlspecialchars($ex['id']); ?>">
                                            <h4 class="text-lg font-medium text-indigo-900"><?php echo htmlspecialchars($ex['title']); ?></h4>
                                            <p class="text-gray-600"><?php echo htmlspecialchars($ex['description']); ?></p>
                                            <span class="text-indigo-600 text-sm"><?php echo htmlspecialchars($ex['category']); ?></span>
                                            <button class="startGoalBtn bg-indigo-600 text-white px-4 py-2 rounded-lg mt-2" data-exercise-id="<?php echo htmlspecialchars($ex['id']); ?>">
                                                Start <i class="fas fa-play ml-2"></i>
                                            </button>
                                        </div>
                                    <?php endif; endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Goal Progress -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Goal Progress</h3>
                            <div class="bg-gray-50 rounded-xl p-4">
                                <p class="text-gray-600">Completed <?php echo count($today_activities); ?> of 3 goals today</p>
                                <div class="w-full bg-gray-200 rounded-full h-2.5 mt-2">
                                    <div class="bg-indigo-600 h-2.5 rounded-full" style="width: <?php echo min((count($today_activities) / 3) * 100, 100); ?>%"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Today's Completed Activities -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Today's Completed Activities</h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full bg-white rounded-lg" id="activitiesTable">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Exercise</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Score</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php foreach ($today_activities as $activity): ?>
                                            <tr>
                                                <td class="px-6 py-4"><?php echo htmlspecialchars($activity['exercise']); ?></td>
                                                <td class="px-6 py-4"><?php echo htmlspecialchars($activity['type']); ?></td>
                                                <td class="px-6 py-4">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $activity['score'] >= 80 ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                                        <?php echo $activity['score']; ?>%
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4"><?php echo htmlspecialchars($activity['time']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($today_activities)): ?>
                                            <tr><td colspan="4" class="px-6 py-4 text-gray-600">No activities completed today.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        const exerciseData = <?php echo json_encode($js_data); ?>;
        const exercises = exerciseData.exercises.map(ex => ({
            id: ex.id,
            title: ex.title,
            type: ex.type || ex.category,
            description: ex.description,
            instructions: ex.instructions || '',
            expectedInput: ex.expected_input ? JSON.parse(ex.expected_input) : null,
            duration: ex.duration || 300
        }));

        let currentExercise = null;

        function generateExerciseContent(exercise) {
            if (exercise.type === 'Mindfulness') {
                return `
                    <div class="text-center">
                        <div class="text-xl font-medium text-indigo-800 mb-4">${exercise.instructions}</div>
                        <div class="max-w-md mx-auto">
                            <button id="startBreathingBtn" class="bg-indigo-600 text-white px-4 py-2 rounded-lg">
                                Start Breathing <i class="fas fa-play ml-2"></i>
                            </button>
                            <div id="breathingTimer" class="text-2xl font-bold text-indigo-800 mt-4 hidden"></div>
                        </div>
                    </div>
                `;
            } else if (exercise.type === 'Gratitude') {
                return `
                    <div class="text-center">
                        <div class="text-xl font-medium text-indigo-800 mb-4">${exercise.instructions}</div>
                        <div class="max-w-md mx-auto">
                            <textarea id="gratitudeInput" class="w-full px-4 py-3 rounded-xl border border-indigo-200 focus:ring-2 focus:ring-indigo-500" placeholder="Write your thoughts here..." rows="4"></textarea>
                        </div>
                    </div>
                `;
            } else if (exercise.type === 'CBT') {
                return `
                    <div class="text-center">
                        <div class="text-xl font-medium text-indigo-800 mb-4">${exercise.instructions}</div>
                        <div class="max-w-md mx-auto">
                            <input type="text" id="cbtInput" class="w-full px-4 py-3 rounded-xl border border-indigo-200 focus:ring-2 focus:ring-indigo-500" placeholder="Your response">
                        </div>
                    </div>
                `;
            }
            return '';
        }

        function setupMindfulnessExercise(exercise) {
            const startBreathingBtn = document.getElementById('startBreathingBtn');
            const timerEl = document.getElementById('breathingTimer');
            let timeLeft = exercise.duration;

            startBreathingBtn.addEventListener('click', () => {
                startBreathingBtn.classList.add('hidden');
                timerEl.classList.remove('hidden');
                const timer = setInterval(() => {
                    if (timeLeft <= 0) {
                        clearInterval(timer);
                        timerEl.textContent = 'Breathing Complete!';
                        showFeedback(true, 'Mindfulness');
                        saveActivity(exercise);
                    } else {
                        timerEl.textContent = `Breathe: ${timeLeft}s`;
                        timeLeft--;
                    }
                }, 1000);
            });
        }

        function setupGratitudeExercise(exercise) {
            document.getElementById('checkAnswerBtn').onclick = () => {
                const userInput = document.getElementById('gratitudeInput').value.trim();
                const isValid = userInput.length > 10;
                showFeedback(isValid, 'Gratitude');
                if (isValid) saveActivity(exercise);
            };
        }

        function setupCBTExercise(exercise) {
            document.getElementById('checkAnswerBtn').onclick = () => {
                const userInput = document.getElementById('cbtInput').value.trim();
                const isValid = exercise.expectedInput 
                    ? userInput.toLowerCase().includes(exercise.expectedInput.toLowerCase()) 
                    : userInput.length > 10;
                showFeedback(isValid, 'CBT');
                if (isValid) saveActivity(exercise);
            };
        }

        function showFeedback(isCorrect, type) {
            const feedbackEl = document.createElement('div');
            feedbackEl.className = `mt-4 p-3 rounded-lg ${isCorrect ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`;
            feedbackEl.innerHTML = `<i class="fas fa-${isCorrect ? 'check-circle' : 'times-circle'} mr-2"></i>${isCorrect ? 'Well Done!' : 'Please provide a valid response.'}`;
            document.getElementById('exerciseContent').querySelector('div').appendChild(feedbackEl);
            document.getElementById('checkAnswerBtn').classList.add('hidden');
            document.getElementById('closeExerciseBtn').classList.remove('hidden');
        }

        function saveActivity(exercise) {
            fetch('save_activity.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: <?php echo $user_id; ?>,
                    exercise_id: exercise.id,
                    exercise_title: exercise.title,
                    exercise_type: exercise.type,
                    score: 100,
                    time_taken: exercise.duration
                })
            }).then(response => response.json()).then(data => {
                if (data.success) {
                    location.reload(); // Refresh to update goals and activities
                }
            });
        }

        // Handle Start Goal buttons
        document.querySelectorAll('.startGoalBtn').forEach(btn => {
            btn.addEventListener('click', () => {
                const exerciseId = btn.getAttribute('data-exercise-id');
                currentExercise = exercises.find(ex => ex.id == exerciseId);
                if (currentExercise) {
                    document.getElementById('goalsList').classList.add('hidden');
                    document.getElementById('exerciseContainer').classList.remove('hidden');
                    document.getElementById('exerciseContent').innerHTML = `
                        <div>
                            <h4 class="text-xl font-semibold text-indigo-900 mb-2">${currentExercise.title}</h4>
                            <p class="text-gray-600 mb-6">${currentExercise.description}</p>
                            ${generateExerciseContent(currentExercise)}
                        </div>
                    `;
                    document.getElementById('checkAnswerBtn').classList.remove('hidden');
                    document.getElementById('closeExerciseBtn').classList.add('hidden');

                    if (currentExercise.type === 'Mindfulness') setupMindfulnessExercise(currentExercise);
                    else if (currentExercise.type === 'Gratitude') setupGratitudeExercise(currentExercise);
                    else if (currentExercise.type === 'CBT') setupCBTExercise(currentExercise);
                }
            });
        });

        // Exercise Container
        document.getElementById('exerciseContainer')?.addEventListener('click', (e) => {
            if (e.target.id === 'closeExerciseBtn') {
                document.getElementById('exerciseContainer').classList.add('hidden');
                document.getElementById('goalsList').classList.remove('hidden');
                currentExercise = null;
            }
        });
    </script>

    <!-- Exercise Container (Hidden by Default) -->
    <div id="exerciseContainer" class="hidden bg-white rounded-2xl shadow-lg p-6 mt-6">
        <div id="exerciseContent"></div>
        <div class="flex justify-between items-center mt-4">
            <button id="checkAnswerBtn" class="bg-purple-600 text-white px-4 py-2 rounded-lg hidden">
                Submit <i class="fas fa-check ml-2"></i>
            </button>
            <button id="closeExerciseBtn" class="bg-gray-600 text-white px-4 py-2 rounded-lg hidden">
                Close <i class="fas fa-times ml-2"></i>
            </button>
        </div>
    </div>
</body>
</html>
