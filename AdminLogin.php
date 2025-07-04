<?php
require_once 'config/db.php';
require_once 'includes/session.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($_POST['password'], $admin['password'])) {
        $_SESSION['admin_id'] = $admin['id'];
        header('Location: adminDashboard.php');
        exit();
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
  <title>Admin Login - CompanionX</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet"/>
  <style>
    @keyframes fadeIn {
      0% { opacity: 0; transform: translateY(20px); }
      100% { opacity: 1; transform: translateY(0); }
    }
    .fade-in {
      animation: fadeIn 1s ease-out forwards;
    }
    .bg-companion {
      background: linear-gradient(to top right, #e0e7ff, #f3f4f6, #dbeafe);
    }
  </style>
</head>
<body class="bg-companion min-h-screen">

  <!-- Navbar -->
  <nav class="bg-white shadow-md py-4">
    <div class="container mx-auto px-6 flex flex-col md:flex-row justify-between items-center gap-4">
      <div class="text-2xl font-bold text-indigo-700">Companion<span class="text-blue-500">X</span></div>
      <div class="flex items-center gap-6 text-gray-700 font-medium">
        <a href="#about" class="hover:text-blue-600 text-sm">About Us</a>
        <a href="#services" class="hover:text-blue-600 text-sm">Our Services</a>
        <div class="flex items-center gap-2">
          <label for="roleSelect" class="text-sm">Your Role:</label>
          <select id="roleSelect"
                  class="bg-white text-gray-700 border border-gray-300 rounded-md px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
            <option value="login.php">User</option>
            <option value="consultantLogin.php">Consultant</option>
            <option value="AdminLogin.php" selected>Admin</option>
          </select>
        </div>
      </div>
    </div>
  </nav>

  <!-- Login Section -->
  <div class="flex items-center justify-center py-12 px-4 fade-in">
    <div class="w-full max-w-md bg-white rounded-xl p-8 shadow-2xl">
      <h2 class="text-2xl font-bold text-center text-gray-800 mb-2">Admin Login</h2>
      <p class="text-center text-gray-600 mb-6 text-sm">Login to access the admin dashboard</p>

      <?php if (!empty($error)): ?>
        <p class="text-red-600 text-sm text-center mb-4"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>

      <form method="POST" class="space-y-4">
        <div>
          <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
          <input type="email" name="email" id="email" required
                 class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400">
        </div>

        <div>
          <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
          <input type="password" name="password" id="password" required
                 class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400">
        </div>

        <button type="submit"
                class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2 rounded-lg font-semibold transition duration-200">
          Login
        </button>
      </form>

      <!-- Register Link -->
      <div class="text-center mt-4">
        <a href="AdminSignup.php" class="text-sm text-blue-600 hover:underline font-medium">
          ➕ Register as Admin
        </a>
      </div>
    </div>
  </div>

  <!-- Role Redirect Script -->
  <script>
    document.getElementById('roleSelect').addEventListener('change', function () {
      const selectedPage = this.value;
      if (selectedPage !== 'AdminLogin.php') {
        window.location.href = selectedPage;
      }
    });
  </script>

</body>
</html>
