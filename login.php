<?php
require_once 'config/db.php';
require_once 'includes/session.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];

        // First time login check
        if (!$user['has_completed_questionnaire']) {
            header('Location: onboarding.php');
            exit();
        } else {
            header('Location: dashboard.php');
            exit();
        }
    } else {
        $error = "Invalid email or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CompanionX</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet"/>
  <style>
    @keyframes fadeIn {
      0% { opacity: 0; transform: translateY(20px); }
      100% { opacity: 1; transform: translateY(0); }
    }
    .fade-in {
      animation: fadeIn 0.8s ease-out forwards;
    }
  </style>
</head>
<body class="bg-gradient-to-tr from-blue-100 via-blue-200 to-blue-300 min-h-screen font-sans">

  <nav class="bg-white shadow-md p-4 flex justify-between items-center w-full">
    <h1 class="text-2xl font-bold text-blue-800">CompanionX</h1>
    <div class="flex items-center space-x-4 ml-auto">
      <span class="text-gray-700">Role:</span>
      <select id="roleSelect" class="border border-gray-300 rounded px-3 py-1 focus:outline-none focus:ring-2 focus:ring-blue-400">
        <option value="user" selected>User</option>
        <option value="admin">Admin</option>
        <option value="consultant">Consultant</option>
      </select>
    </div>
  </nav>

  <div class="flex flex-col items-center justify-center mt-10 px-4">
    <div class="w-full max-w-md bg-white rounded-2xl p-8 shadow-2xl fade-in">
      <h1 class="text-3xl font-bold text-blue-800 mb-2 text-center">Welcome</h1>
      <p class="text-gray-600 text-md mb-4 text-center">Login to continue to your account</p>

      <?php if (!empty($error)): ?>
        <p class="text-red-600 text-sm text-center mb-4"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>

      <form method="POST" class="space-y-5">
        <div>
          <label for="email" class="block text-sm font-medium text-blue-700 mb-1">Email</label>
          <input type="email" name="email" id="email" required
            class="w-full px-4 py-2 border border-blue-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400"/>
        </div>

        <div>
          <label for="password" class="block text-sm font-medium text-blue-700 mb-1">Password</label>
          <input type="password" name="password" id="password" required
            class="w-full px-4 py-2 border border-blue-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400"/>
        </div>

        <div class="flex justify-between items-center text-sm text-blue-700">
          <label class="inline-flex items-center">
            <input type="checkbox" class="form-checkbox text-blue-600 mr-2"> Remember me
          </label>
          <a href="#" class="hover:underline">Forgot password?</a>
        </div>

        <button type="submit"
          class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold transition duration-200 shadow-md">
          Login
        </button>
      </form>

      <p class="text-sm text-center mt-6 text-blue-700">
        Don't have an account?
        <a href="signup.php" class="text-blue-800 font-semibold hover:underline">Register</a>
      </p>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      document.getElementById('roleSelect').addEventListener('change', function() {
        const role = this.value;
        if (role === 'admin') {
          window.location.href = 'AdminLogin.php';
        } else if (role === 'consultant') {
          window.location.href = 'ConsultantLogin.php';
        }
      });
    });
  </script>

</body>
</html>
