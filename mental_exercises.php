
<?php
session_start();
include 'config/db.php';

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header("Location: login.php");
    exit();
}

// Fetch recommended exercises
$pythonPath = "\"C:/Users/laptop universe/AppData/Local/Programs/Python/Python311/python.exe\"";
$scriptPath = "\"C:/xampp/htdocs/Companion/recommend_exercises.py\"";
$pythonScript = "$pythonPath $scriptPath " . escapeshellarg($user_id);
$output = shell_exec($pythonScript . " 2>&1");
$recommended_exercises = $output ? json_decode($output, true) : [];

// Fetch all exercises for fallback
$sql = "SELECT id, title, description, category AS type, instructions, expected_input, duration 
        FROM mental_exercises 
        ORDER BY RAND()";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$exercises = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user progress
$sql = "SELECT score, DATE_FORMAT(created_at, '%Y-%m-%d') AS date 
        FROM user_progress 
        WHERE user_id = :user_id 
        ORDER BY created_at ASC 
        LIMIT 7";
$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$progress = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for JavaScript
$js_data = [
    'exercises' => $recommended_exercises ?: $exercises,
    'progress' => [
        'dates' => array_column($progress, 'date'),
        'scores' => array_column($progress, 'score')
    ],
    'activities' => [], // Empty due to missing user_activities table
    'achievements' => [], // Empty due to missing achievements table
    'streak' => 0, // No streak calculation without user_activities
    'daily_completed' => false // No daily task check without user_activities
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CompanionX - Mental Wellness</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-pulse { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        .fade-in { animation: fadeIn 0.3s ease-out forwards; }
        .card-hover:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }
        .transition-all { transition: all 0.3s ease; }
        .exercise-container { opacity: 1; transition: opacity 0.3s ease; }
        .exercise-container.loading { opacity: 0.5; pointer-events: none; }
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
                <a href="dashboard.php" class="bg-indigo-600 text-white px-4 py-2 rounded-lg shadow hover:bg-indigo-700 transition-all">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                </a>
            </div>
        </header>

        <div class="flex flex-col lg:flex-row gap-8">
            <aside class="w-full lg:w-64 bg-white rounded-2xl shadow-lg p-6 h-fit">
                <h2 class="text-xl font-semibold text-gray-800 mb-6">Mind Gym</h2>
                <nav>
                    <ul class="space-y-3">
                        <li><a href="mental_exercises.php" class="flex items-center px-4 py-3 bg-indigo-100 text-indigo-700 rounded-lg"><i class="fas fa-brain mr-3"></i>Mental Wellness</a></li>
                        <li><a href="progress.php" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-100 rounded-lg"><i class="fas fa-chart-line mr-3"></i>Progress</a></li>
                        <li><a href="#daily-tasks" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-100 rounded-lg"><i class="fas fa-medal mr-3"></i>Daily Tasks</a></li>
                    </ul>
                </nav>
            </aside>

            <main class="flex-1">
                <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                    <div class="bg-gradient-to-r from-indigo-500 to-purple-600 p-6 text-white">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-2xl font-bold">Mental Wellness</h2>
                                <p class="text-indigo-100">Personalized exercises for your mental health</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
                        <!-- Daily Tasks -->
                        <div id="daily-tasks" class="mb-8">
                            <h3 class="text-xl font-semibold text-indigo-800 mb-4">Daily Tasks</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach ($js_data['exercises'] as $ex): if (isset($ex['is_daily_task']) && $ex['is_daily_task']): ?>
                                    <div class="bg-indigo-50 rounded-xl p-4 card-hover">
                                        <h4 class="text-lg font-medium text-indigo-900"><?php echo htmlspecialchars($ex['title']); ?></h4>
                                        <p class="text-gray-600"><?php echo htmlspecialchars($ex['description']); ?></p>
                                        <span class="text-indigo-600 text-sm"><?php echo htmlspecialchars($ex['category']); ?></span>
                                    </div>
                                <?php endif; endforeach; ?>
                            </div>
                        </div>

                        <!-- Recommended Exercises -->
                        <div class="exercise-container mb-8">
                            <div class="flex justify-between items-center mb-6">
                                <div>
                                    <h3 class="text-xl font-semibold text-indigo-800">Recommended Exercises</h3>
                                    <p id="progressText" class="text-indigo-600">Start your wellness practice</p>
                                </div>
                            </div>

                            <div id="exerciseContent" class="bg-indigo-50 rounded-2xl p-8 min-h-[400px] flex flex-col justify-center">
                                <div id="initialMessage" class="text-center">
                                    <div class="mx-auto bg-white rounded-full p-5 w-24 h-24 flex items-center justify-center mb-6 shadow-sm">
                                        <i class="fas fa-brain text-indigo-500 text-3xl"></i>
                                    </div>
                                    <h4 class="text-xl font-medium text-indigo-900 mb-3">Ready to Nurture Your Mind?</h4>
                                    <p class="text-gray-600 mb-6">Complete your personalized exercises today.</p>
                                    <button id="startExercisesBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-3 px-8 rounded-xl transition-all">
                                        Begin Exercises <i class="fas fa-arrow-right ml-2"></i>
                                    </button>
                                </div>
                            </div>

                            <div id="exerciseControls" class="flex justify-between items-center">
                                <button id="checkAnswerBtn" class="bg-purple-600 hover:bg-purple-700 text-white font-medium py-3 px-6 rounded-xl transition-all hidden">
                                    Submit <i class="fas fa-check ml-2"></i>
                                </button>
                                <button id="nextExerciseBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-3 px-8 rounded-xl transition-all opacity-0 hidden ml-auto">
                                    Next Exercise <i class="fas fa-arrow-right ml-2"></i>
                                </button>
                            </div>

                            <div id="completionMessage" class="text-center hidden">
                                <div class="mx-auto bg-gradient-to-r from-indigo-500 to-purple-600 rounded-full p-5 w-24 h-24 flex items-center justify-center mb-6 shadow-lg">
                                    <i class="fas fa-trophy text-white text-3xl"></i>
                                </div>
                                <h4 class="text-2xl font-bold text-indigo-900 mb-3">Great Job!</h4>
                                <p class="text-gray-600 mb-6">You've completed today's wellness practice.</p>
                                <div class="flex justify-center space-x-4">
                                    <button id="reviewExercisesBtn" class="bg-white hover:bg-gray-100 text-indigo-700 border border-indigo-200 font-medium py-2 px-6 rounded-xl transition-all">
                                        Review Exercises <i class="fas fa-list-ul ml-2"></i>
                                    </button>
                                    <button id="newWorkoutBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-6 rounded-xl transition-all">
                                        Start New Practice <i class="fas fa-plus ml-2"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Progress -->
                        <div class="mb-8">
                            <h3 class="text-xl font-semibold text-indigo-800 mb-4">Your Progress</h3>
                            <div class="bg-gray-50 rounded-xl p-6">
                                <canvas id="progressChart" height="300"></canvas>
                            </div>
                            <p class="text-gray-600 mt-4">Completion Rate: <?php echo count($progress) ? round(count(array_filter($progress, fn($p) => $p['score'] >= 80)) / count($progress) * 100) : 0; ?>%</p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const exerciseData = <?php echo json_encode($js_data); ?>;
        const exercises = exerciseData.exercises.map(ex => ({
            id: ex.id,
            title: ex.title,
            type: ex.type || ex.category,
            description: ex.description,
            instructions: ex.instructions || '',
            content: generateExerciseContent(ex),
            expectedInput: ex.expected_input ? JSON.parse(ex.expected_input) : null,
            duration: ex.duration || 300
        }));

        function generateExerciseContent(exercise) {
            if (exercise.type === 'Mindfulness') {
                return `
                    <div class="text-center">
                        <div class="text-xl font-medium text-indigo-800 mb-4">${exercise.instructions}</div>
                        <div class="max-w-md mx-auto">
                            <button id="startBreathingBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-3 px-8 rounded-xl transition-all">
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

        let currentExerciseIndex = -1;
        let completedExercises = [];

        const exerciseContentEl = document.getElementById('exerciseContent');
        const progressTextEl = document.getElementById('progressText');
        const startBtn = document.getElementById('startExercisesBtn');
        const checkAnswerBtn = document.getElementById('checkAnswerBtn');
        const nextExerciseBtn = document.getElementById('nextExerciseBtn');
        const completionMessage = document.getElementById('completionMessage');
        const initialMessage = document.getElementById('initialMessage');

        startBtn.addEventListener('click', () => {
            currentExerciseIndex = 0;
            initialMessage.classList.add('hidden');
            loadExercise(currentExerciseIndex);
        });

        function loadExercise(index) {
            if (index >= exercises.length) {
                showCompletionMessage();
                return;
            }

            const exercise = exercises[index];
            progressTextEl.textContent = `Exercise ${index + 1} of ${exercises.length} • ${exercise.type}`;
            exerciseContentEl.classList.add('opacity-0');
            
            setTimeout(() => {
                exerciseContentEl.innerHTML = `
                    <div>
                        <h4 class="text-xl font-semibold text-indigo-900 mb-2">${exercise.title}</h4>
                        <p class="text-gray-600 mb-6">${exercise.description}</p>
                        ${exercise.content}
                    </div>
                `;
                checkAnswerBtn.classList.remove('hidden');
                nextExerciseBtn.classList.add('hidden', 'opacity-0');
                
                if (exercise.type === 'Mindfulness') setupMindfulnessExercise();
                else if (exercise.type === 'Gratitude') setupGratitudeExercise();
                else if (exercise.type === 'CBT') setupCBTExercise();
                
                exerciseContentEl.classList.remove('opacity-0');
            }, 300);
        }

        function setupMindfulnessExercise() {
            const startBreathingBtn = document.getElementById('startBreathingBtn');
            const timerEl = document.getElementById('breathingTimer');
            let timeLeft = exercises[currentExerciseIndex].duration;

            startBreathingBtn.addEventListener('click', () => {
                startBreathingBtn.classList.add('hidden');
                timerEl.classList.remove('hidden');
                const timer = setInterval(() => {
                    if (timeLeft <= 0) {
                        clearInterval(timer);
                        timerEl.textContent = 'Breathing Complete!';
                        showFeedback(true, 'Mindfulness');
                    } else {
                        timerEl.textContent = `Breathe: ${timeLeft}s`;
                        timeLeft--;
                    }
                }, 1000);
            });
            checkAnswerBtn.onclick = null;
        }

        function setupGratitudeExercise() {
            checkAnswerBtn.onclick = () => {
                const userInput = document.getElementById('gratitudeInput').value.trim();
                const isValid = userInput.length > 10;
                showFeedback(isValid, 'Gratitude');
            };
        }

        function setupCBTExercise() {
            checkAnswerBtn.onclick = () => {
                const userInput = document.getElementById('cbtInput').value.trim();
                const isValid = exercises[currentExerciseIndex].expectedInput 
                    ? userInput.toLowerCase().includes(exercises[currentExerciseIndex].expectedInput.toLowerCase()) 
                    : userInput.length > 10;
                showFeedback(isValid, 'CBT');
            };
        }

        function showFeedback(isCorrect, type) {
            const feedbackEl = document.createElement('div');
            feedbackEl.className = `mt-4 p-3 rounded-lg ${isCorrect ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`;
            feedbackEl.innerHTML = `<i class="fas fa-${isValid ? 'check-circle' : 'times-circle'} mr-2"></i>${isValid ? 'Well Done!' : 'Please provide a valid response.'}`;
            exerciseContentEl.querySelector('div').appendChild(feedbackEl);
            checkAnswerBtn.classList.add('hidden');
            nextExerciseBtn.classList.remove('hidden', 'opacity-0');
            if (isCorrect) completedExercises.push(exercises[currentExerciseIndex]);
        }

        function showCompletionMessage() {
            exerciseContentEl.classList.add('opacity-0');
            checkAnswerBtn.classList.add('hidden');
            nextExerciseBtn.classList.add('hidden');
            setTimeout(() => {
                exerciseContentEl.classList.add('hidden');
                completionMessage.classList.remove('hidden');
                completionMessage.classList.remove('opacity-0');
            }, 300);
        }

        nextExerciseBtn.addEventListener('click', () => {
            currentExerciseIndex++;
            loadExercise(currentExerciseIndex);
        });

        const ctx = document.getElementById('progressChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: exerciseData.progress.dates.length ? exerciseData.progress.dates : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'Wellness Score',
                    data: exerciseData.progress.scores.length ? exerciseData.progress.scores : [50, 50, 50, 50, 50, 50, 50],
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    borderColor: 'rgba(79, 70, 229, 1)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
                scales: { y: { min: 50, max: 100, ticks: { callback: value => value + '%' } }, x: { grid: { display: false } } }
            }
        });
    </script>
</body>
</html>
