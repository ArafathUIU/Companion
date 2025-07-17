
<?php
require_once 'config/db.php';
require_once 'includes/session.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Simple CSRF token generation and verification
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check CSRF token validity
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid form submission.';
    }

    $userId = $_SESSION['user_id'];

    // Validate and save ratings
    if (isset($_POST['ratings']) && is_array($_POST['ratings'])) {
        $stmt = $pdo->prepare("INSERT INTO user_signup_answers (user_id, question_id, answer_text, created_at) VALUES (?, ?, ?, NOW())");
        foreach ($_POST['ratings'] as $qid => $value) {
            $qid = (int)$qid;
            $value = (int)$value;
            // Validate rating range 1-10
            if ($qid > 0 && $value >= 1 && $value <= 10) {
                $stmt->execute([$userId, $qid, $value]);
            }
        }
    } else {
        $errors[] = "Please answer all rating questions.";
    }

    // Validate and save checklist reasons
    if (!empty($_POST['checklist']) && is_array($_POST['checklist'])) {
        $allowedReasons = [
            "Reduce stress", "Manage anxiety", "Deal with depression",
            "Improve sleep", "Boost confidence", "Overcome burnout",
            "Enhance emotional wellbeing", "Find a counselor"
        ];
        $selected = array_intersect($allowedReasons, $_POST['checklist']);
        if (!empty($selected)) {
            $reasonText = implode(', ', $selected);
            $stmt = $pdo->prepare("INSERT INTO user_signup_answers (user_id, question_id, answer_text, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$userId, 999, $reasonText]);
        }
    }

    if (empty($errors)) {
        // Mark user as completed questionnaire
        $pdo->prepare("UPDATE users SET has_completed_questionnaire = 1 WHERE id = ?")->execute([$userId]);

        // Trigger exercise recommendation script
        $pythonPath = "\"C:/Users/laptop universe/AppData/Local/Programs/Python/Python311/python.exe\"";
        $scriptPath = "\"C:/xampp/htdocs/Companion/recommend_exercises.py\"";
        $pythonScript = "$pythonPath $scriptPath " . escapeshellarg($userId);
        $output = shell_exec($pythonScript . " 2>&1");
        if ($output === null) {
            error_log("Failed to execute recommend_exercises.py for user_id: $userId");
        } else {
            error_log("Exercise recommendation script output for user_id $userId: $output");
            // Optionally store recommendations in session for dashboard display
            $_SESSION['exercise_recommendations'] = json_decode($output, true);
        }

        // Optionally keep the consultant recommendation script
        $consultantScriptPath = "\"C:/xampp/htdocs/Companion/recommend_consultants.py\"";
        $consultantScript = "$pythonPath $consultantScriptPath " . escapeshellarg($userId);
        $consultantOutput = shell_exec($consultantScript . " 2>&1");
        if ($consultantOutput === null) {
            error_log("Failed to execute recommend_consultants.py for user_id: $userId");
        } else {
            error_log("Consultant recommendation script output for user_id $userId: $consultantOutput");
        }

        // Regenerate CSRF token after successful submission
        unset($_SESSION['csrf_token']);

        // Redirect to dashboard.php
        header('Location: dashboard.php');
        exit();
    }
}

// Fetch questions from DB
$questions = $pdo->query("SELECT id, question_text FROM signup_questions ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Predefined checklist reasons
$reasons = [
    "Reduce stress", "Manage anxiety", "Deal with depression",
    "Improve sleep", "Boost confidence", "Overcome burnout",
    "Enhance emotional wellbeing", "Find a counselor"
];
?>

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Onboarding - CompanionX</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;700&family=Inter:wght@400;600&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>
  /* Whitish gradient background with subtle animation */
  body {
    background: linear-gradient(-45deg, #f0f4ff, #e6e8ff, #f3e8ff, #e6e8ff);
    background-size: 400% 400%;
    animation: gradientBG 20s ease infinite;
  }

  @keyframes gradientBG {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
  }

  /* Form container with glassmorphism effect */
  .form-container {
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
    border-radius: 1.5rem;
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
    max-width: 560px;
    width: 100%;
    padding: 3rem 2.5rem;
    color: #1e3a8a;
    font-family: 'Inter', sans-serif;
  }

  /* Step animations with fade and slide */
  .step {
    transition: opacity 0.5s ease, transform 0.5s ease;
    opacity: 1;
    transform: translateY(0);
  }

  .step.hidden {
    opacity: 0;
    transform: translateY(20px);
    display: none;
  }

  /* Button hover animations */
  button {
    transition: transform 0.2s ease, background-color 0.3s ease;
  }

  button:hover {
    transform: translateY(-2px);
  }

  /* Error message styling */
  .error-message {
    background-color: #fef2f2;
    border: 1px solid #f87171;
    color: #b91c1c;
    padding: 0.75rem 1rem;
    border-radius: 0.5rem;
    margin-bottom: 1.5rem;
    font-weight: 600;
  }

  /* Custom range input styling */
  input[type="range"] {
    -webkit-appearance: none;
    width: 100%;
    height: 8px;
    background: #e5e7eb;
    border-radius: 5px;
    outline: none;
  }

  input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 20px;
    height: 20px;
    background: #3b82f6;
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
    transition: transform 0.2s ease;
  }

  input[type="range"]::-webkit-slider-thumb:hover {
    transform: scale(1.2);
  }

  /* Flower animation container */
  .flower-container {
    display: flex;
    justify-content: center;
    margin-bottom: 1.5rem;
  }

  /* Headings */
  h1, h2 {
    font-family: 'Poppins', sans-serif;
    color: #1e3a8a;
  }
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">

<div class="form-container shadow-2xl">
  <!-- Flower SVG Animation -->
  <div class="flower-container">
    <svg id="flowerSvg" width="120" height="120" viewBox="0 0 100 100">
      <g id="flower">
        <!-- Petals -->
        <path class="petal" d=" _

M50 30 C40 10, 60 10, 50 30" fill="#ff6b6b" transform="rotate(0 50 50)" />
        <path class="petal" d="M50 30 C40 10, 60 10, 50 30" fill="#ff6b6b" transform="rotate(72 50 50)" />
        <path class="petal" d="M50 30 C40 10, 60 10, 50 30" fill="#ff6b6b" transform="rotate(144 50 50)" />
        <path class="petal" d="M50 30 C40 10, 60 10, 50 30" fill="#ff6b6b" transform="rotate(216 50 50)" />
        <path class="petal" d="M50 30 C40 10, 60 10, 50 30" fill="#ff6b6b" transform="rotate(288 50 50)" />
        <!-- Center -->
        <circle cx="50" cy="50" r="10" fill="#facc15" />
        <!-- Stem -->
        <path id="stem" d="M50 50 V80" stroke="#4ade80" stroke-width="4" />
      </g>
    </svg>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="error-message" role="alert" aria-live="assertive">
      <ul class="list-disc pl-5">
        <?php foreach ($errors as $err): ?>
          <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="POST" id="onboardingForm" novalidate onsubmit="showLoading()">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>" />

    <!-- Step 0: Welcome -->
    <div class="step" id="step-welcome" aria-live="polite">
      <h1 class="text-4xl font-bold mb-4 text-center">Welcome to CompanionX</h1>
      <p class="mb-8 text-center text-lg leading-relaxed">
        Your journey to mental wellness begins here.<br>
        Answer a few questions to personalize your experience.
      </p>
      <button type="button" id="startBtn" class="bg-blue-600 hover:bg-blue-700 text-white py-3 px-8 rounded-lg block mx-auto focus:outline-none focus:ring-2 focus:ring-blue-400">Begin Your Journey</button>
    </div>

    <!-- Steps: Rating Questions -->
    <?php foreach ($questions as $index => $q): ?>
      <div class="step hidden" data-step="<?= $index + 1 ?>" role="region" aria-labelledby="question-label-<?= $q['id'] ?>">
        <h2 class="text-2xl font-semibold mb-4">Question <?= $index + 1 ?> of <?= count($questions) ?></h2>
        <p id="question-label-<?= $q['id'] ?>" class="mb-6 text-lg"><?= htmlspecialchars($q['question_text']) ?></p>

        <label for="slider-<?= $q['id'] ?>" class="block text-sm font-medium mb-2">
          Your rating: <span class="font-bold text-blue-600" id="sliderValue-<?= $q['id'] ?>">5</span>
        </label>
        <input
          type="range"
          id="slider-<?= $q['id'] ?>"
          name="ratings[<?= $q['id'] ?>]"
          min="1"
          max="10"
          value="5"
          class="w-full accent-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-400"
          aria-valuemin="1"
          aria-valuemax="10"
          aria-valuenow="5"
          aria-describedby="sliderValue-<?= $q['id'] ?>"
          data-question-id="<?= $q['id'] ?>"
        />

        <div class="flex justify-between text-xs mt-2 text-gray-600 select-none">
          <span>1 (Very Low)</span>
          <span>10 (Very High)</span>
        </div>

        <div class="mt-8 flex justify-between">
          <?php if ($index > 0): ?>
            <button type="button" class="prevBtn bg-gray-200 hover:bg-gray-300 text-gray-700 py-2 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-400">Back</button>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
          <button type="button" class="nextBtn bg-blue-600 hover:bg-blue-700 text-white py-2 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400">Next</button>
        </div>
      </div>
    <?php endforeach; ?>

    <!-- Final Step: Checklist -->
    <div class="step hidden" data-step="<?= count($questions) + 1 ?>" role="region" aria-labelledby="final-step-label">
      <h2 class="text-2xl font-semibold mb-4" id="final-step-label">Your Goals</h2>
      <p class="mb-4">Why did you come to CompanionX? (Select all that apply)</p>

      <?php foreach ($reasons as $reason): ?>
        <label class="block mb-3 cursor-pointer select-none group">
          <input
            type="checkbox"
            name="checklist[]"
            value="<?= htmlspecialchars($reason) ?>"
            class="mr-2 accent-blue-600 focus:ring-2 focus:ring-blue-400"
          />
          <span class="group-hover:text-blue-600 transition-colors"><?= htmlspecialchars($reason) ?></span>
        </label>
      <?php endforeach; ?>

      <div class="mt-8 flex justify-between">
        <button type="button" class="prevBtn bg-gray-200 hover:bg-gray-300 text-gray-700 py-2 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-400">Back</button>
        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white py-2 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-400">Finish</button>
      </div>
    </div>
  </form>
</div>

<!-- Loading overlay -->
<div id="loadingOverlay" class="fixed inset-0 bg-white bg-opacity-90 flex items-center justify-center z-50 hidden">
  <div class="text-center">
    <svg class="animate-spin h-12 w-12 text-blue-600 mx-auto mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
    </svg>
    <p class="text-blue-700 font-semibold text-lg">Crafting your personalized plan...</p>
  </div>
</div>

<script>
  // Multi-step form logic
  const steps = document.querySelectorAll('.step');
  let currentStep = 0;

  function showStep(index) {
    steps.forEach((step, i) => {
      step.classList.toggle('hidden', i !== index);
      if (i === index) {
        step.setAttribute('aria-hidden', 'false');
      } else {
        step.setAttribute('aria-hidden', 'true');
      }
    });
  }

  // Start button
  document.getElementById('startBtn').addEventListener('click', () => {
    currentStep = 1;
    showStep(currentStep);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  // Next buttons
  document.querySelectorAll('.nextBtn').forEach(btn => {
    btn.addEventListener('click', () => {
      if (currentStep < steps.length - 1) {
        currentStep++;
        showStep(currentStep);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
    });
  });

  // Previous buttons
  document.querySelectorAll('.prevBtn').forEach(btn => {
    btn.addEventListener('click', () => {
      if (currentStep > 0) {
        currentStep--;
        showStep(currentStep);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
    });
  });

  // Flower animation based on slider input
  const petals = document.querySelectorAll('.petal');
  const stem = document.getElementById('stem');
  const flower = document.getElementById('flower');

  function updateFlower(rating) {
    const scale = 0.5 + (rating / 10) * 0.5; // Scale petals from 0.5 to 1
    const color = rating < 4 ? '#a3a3a3' : rating < 7 ? '#f87171' : '#ff6b6b';
    const stemLength = 30 + (rating / 10) * 30; // Stem length from 30 to 60
    const rotation = rating < 4 ? -20 : 0; // Wilted effect for low ratings

    petals.forEach(petal => {
      petal.style.transform = `rotate(${petal.getAttribute('transform').split(' ')[1]} scale(${scale})`;
      petal.style.fill = color;
      petal.style.transition = 'transform 0.5s ease, fill 0.5s ease';
    });

    stem.setAttribute('d', `M50 50 V${50 + stemLength}`);
    stem.style.transition = 'd 0.5s ease';

    flower.style.transform = `rotate(${rotation}deg)`;
    flower.style.transition = 'transform 0.5s ease';
  }

  // Live update slider values and flower animation
  document.querySelectorAll('input[type=range]').forEach(slider => {
    slider.addEventListener('input', (e) => {
      const id = e.target.id.split('-')[1];
      const display = document.getElementById('sliderValue-' + id);
      display.textContent = e.target.value;
      e.target.setAttribute('aria-valuenow', e.target.value);
      updateFlower(parseInt(e.target.value));
    });
  });

  // Initialize flower for welcome step
  updateFlower(5); // Default to middle rating

  function showLoading() {
    document.getElementById('loadingOverlay').classList.remove('hidden');
  }
</script>

</body>
</html>
