<?php
require_once 'config/db.php';
require_once 'includes/session.php';

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $dob = $_POST['dob'];
    $gender = $_POST['gender'];
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($first_name) || empty($last_name) || empty($dob) || empty($gender) || empty($email) || empty($password)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->rowCount() > 0) {
            $error = "An admin with this email already exists.";
        } else {
           $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO admins (first_name, last_name, dob, gender, email, password, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
            if ($stmt->execute([$first_name, $last_name, $dob, $gender, $email, $hashed_password])) {
            header("Location: AdminLogin.php");
            exit;
            } else {
            $error = "Something went wrong. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Signup - CompanionX</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet"/>

  <style>
    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .fade-in {
      animation: fadeIn 0.8s ease-out forwards;
    }
  </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-purple-50 to-indigo-100 min-h-screen flex items-center justify-center p-4">

  <div class="w-full max-w-2xl bg-gradient-to-br from-white to-indigo-50 rounded-2xl p-10 shadow-xl relative backdrop-blur-sm fade-in">
    <a href="index.html" class="absolute top-4 left-4 text-gray-500 hover:text-indigo-600 transition duration-200">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
      </svg>
    </a>
    <h2 class="text-3xl font-bold text-center text-gray-800 mb-4">Admin Registration</h2>
    <p class="text-center text-gray-600 mb-6 text-sm">Fill in the details to create your admin account</p>

    <?php if (!empty($error)): ?>
      <p class="text-red-600 text-sm text-center mb-4"><?php echo htmlspecialchars($error); ?></p>
    <?php elseif (!empty($success)): ?>
      <p class="text-green-600 text-sm text-center mb-4"><?php echo $success; ?></p>
    <?php endif; ?>

    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label for="first_name" class="block text-sm font-medium text-gray-700">First Name</label>
        <input type="text" name="first_name" id="first_name" required
               class="w-full px-4 py-3 border border-gray-200 bg-white/50 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-400 transition-all duration-300 ease-in-out mt-1"/>
      </div>

      <div>
        <label for="last_name" class="block text-sm font-medium text-gray-700">Last Name</label>
        <input type="text" name="last_name" id="last_name" required
               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400"/>
      </div>

      <div>
        <label for="dob" class="block text-sm font-medium text-gray-700">Date of Birth</label>
        <input type="date" name="dob" id="dob" required
               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400"/>
      </div>

      <div>
        <label for="gender" class="block text-sm font-medium text-gray-700">Gender</label>
        <select name="gender" id="gender" required
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400">
          <option value="">Select</option>
          <option value="Male">Male</option>
          <option value="Female">Female</option>
          <option value="Other">Other</option>
        </select>
      </div>

      <div class="md:col-span-2">
        <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
        <input type="email" name="email" id="email" required
               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400"/>
      </div>

      <div>
        <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
        <input type="password" name="password" id="password" required
               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400"/>
      </div>

      <div>
        <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm Password</label>
        <input type="password" name="confirm_password" id="confirm_password" required
               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400"/>
      </div>

      <div class="md:col-span-2">
        <button type="submit"
                class="w-full bg-gradient-to-r from-indigo-600 to-indigo-500 hover:from-indigo-700 hover:to-indigo-600 text-white py-3 rounded-lg font-semibold transition-all duration-300 ease-in-out shadow-md hover:shadow-lg transform hover:scale-[1.01]">
          Register
        </button>
      </div>

      <div class="md:col-span-2 text-center mt-6 pt-4 border-t border-gray-200/50">
        <p class="text-sm text-gray-600">Already have an account? <a href="AdminLogin.php" class="text-indigo-600 font-medium hover:text-indigo-800 hover:underline transition duration-200">Login here</a></p>
      </div>
    </form>
  </div>

</body>
</html>
