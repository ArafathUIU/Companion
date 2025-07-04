<?php
require_once 'config/db.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = $_POST['first_name'];
    $last = $_POST['last_name'];
    $dob = $_POST['dob'];
    $gender = $_POST['gender'];
    $email = $_POST['email'];
    $pass = password_hash($_POST['password'], PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, dob, gender, email, password) 
                           VALUES (?, ?, ?, ?, ?, ?)");
    try {
        $stmt->execute([$first, $last, $dob, $gender, $email, $pass]);
        header('Location: login.php');
        exit();
    } catch (PDOException $e) {
        $error = "Signup error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Signup - CompanionX</title>
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
<body class="bg-gradient-to-tr from-green-100 via-blue-200 to-indigo-200 min-h-screen flex items-center justify-center font-sans">

  <div class="w-full max-w-xl bg-white rounded-2xl p-8 shadow-2xl fade-in">
    <div class="text-center mb-6">
      <h2 class="text-3xl font-bold text-blue-800">Create Your Account</h2>
      <p class="text-blue-600 text-sm">Join CompanionX and start your journey</p>
    </div>

    <?php if (!empty($error)): ?>
      <p class="text-red-600 text-sm text-center mb-4"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm text-blue-700 mb-1">First Name</label>
        <input name="first_name" placeholder="First Name" required
          class="w-full px-4 py-2 border border-blue-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400"/>
      </div>
      <div>
        <label class="block text-sm text-blue-700 mb-1">Last Name</label>
        <input name="last_name" placeholder="Last Name" required
          class="w-full px-4 py-2 border border-blue-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400"/>
      </div>
      <div class="md:col-span-2">
        <label class="block text-sm text-blue-700 mb-1">Date of Birth</label>
        <input type="date" name="dob" required
          class="w-full px-4 py-2 border border-blue-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400"/>
      </div>
      <div class="md:col-span-2">
        <label class="block text-sm text-blue-700 mb-1">Gender</label>
        <select name="gender" required
          class="w-full px-4 py-2 border border-blue-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400">
          <option value="">Select gender</option>
          <option>Male</option>
          <option>Female</option>
          <option>Other</option>
        </select>
      </div>
      <div class="md:col-span-2">
        <label class="block text-sm text-blue-700 mb-1">Email</label>
        <input type="email" name="email" placeholder="Email" required
          class="w-full px-4 py-2 border border-blue-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400"/>
      </div>
      <div class="md:col-span-2">
        <label class="block text-sm text-blue-700 mb-1">Password</label>
        <input type="password" name="password" placeholder="Password" required
          class="w-full px-4 py-2 border border-blue-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400"/>
      </div>

      <div class="md:col-span-2">
        <button type="submit"
          class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg font-semibold transition duration-200 shadow-md">
          Sign Up
        </button>
      </div>
    </form>

    <p class="text-sm text-center mt-6 text-blue-700">
      Already have an account?
      <a href="login.php" class="text-blue-800 font-semibold hover:underline">Login</a>
    </p>
  </div>

</body>
</html>
