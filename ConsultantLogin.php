<?php
require_once 'config/db.php';
require_once 'includes/session.php'; // for setting $_SESSION

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM consultants WHERE email = ?");
    $stmt->execute([$email]);
    $consultant = $stmt->fetch();

    if ($consultant && password_verify($password, $consultant['password'])) {
        $_SESSION['consultant_id'] = $consultant['id'];
        $_SESSION['consultant_email'] = $consultant['email'];
        $_SESSION['consultant_name'] = $consultant['first_name'] . ' ' . $consultant['last_name'];

        // Optional: check if approved
        if ($consultant && password_verify($_POST['password'], $consultant['password'])) {
        $_SESSION['consultant_id'] = $consultant['id'];
        header('Location: consultantDashboard.php');
        exit();
    } else {
        $error = "Invalid email or password.";
    }
}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Consultant Login - CompanionX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .fade-in {
            animation: fadeIn 1s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-100 via-purple-100 to-indigo-200 min-h-screen flex flex-col">

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
                        <option value="consultantLogin.php" selected>Consultant</option>
                        <option value="AdminLogin.php">Admin</option>
                    </select>
                </div>
            </div>
        </div>
    </nav>

    <!-- Login Card -->
    <div class="flex flex-1 items-center justify-center px-4 py-12">
        <div class="bg-white p-8 rounded-2xl shadow-lg w-full max-w-md fade-in">
            <h2 class="text-2xl font-bold mb-6 text-center text-indigo-700">Consultant Login</h2>

            <?php if (!empty($error)): ?>
                <div class="bg-red-100 text-red-800 px-4 py-2 rounded mb-4"><?= $error ?></div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <input type="email" name="email" placeholder="Email" required class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400" />
                <input type="password" name="password" placeholder="Password" required class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400" />
                <button type="submit" class="w-full bg-indigo-600 text-white p-3 rounded-lg hover:bg-indigo-700 transition">
                    Login
                </button>
            </form>

            <div class="text-center text-sm text-gray-600 mt-4">
                <a href="consultantSignup.php" class="text-indigo-600 hover:underline">Don't have an account? Sign up</a>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('roleSelect').addEventListener('change', function () {
            window.location.href = this.value;
        });
    </script>
</body>
</html>
