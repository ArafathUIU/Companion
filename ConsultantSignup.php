<?php
require_once 'config/db.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $dob = $_POST['dob'];
    $gender = $_POST['gender'];
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $specialization = trim($_POST['specialization']);
    $bio = trim($_POST['bio']);
    $office_address = trim($_POST['office_address']);
    $session_charge = (int) $_POST['session_charge'];
    $tags = trim($_POST['tags']);

    // Handle profile picture upload
    $profile_picture = null;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $target_dir = "uploads/consultants/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $file_name = time() . '_' . basename($_FILES['profile_picture']['name']);
        $target_file = $target_dir . $file_name;
        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_file)) {
            $profile_picture = $target_file;
        }
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO consultants (
            first_name, last_name, dob, gender, email, password, specialization, bio,
            office_address, session_charge, profile_picture, is_available, video_consult_url,
            tags, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NULL, ?, 'pending')");

        $stmt->execute([
            $first_name, $last_name, $dob, $gender, $email, $password, $specialization,
            $bio, $office_address, $session_charge, $profile_picture, $tags
        ]);

        $success = "Consultant register successful! Give the email and password to Consultant.";
    } catch (PDOException $e) {
        if ($e->errorInfo[1] === 1062) {
            $error = "Email already exists!";
        } else {
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Consultant Signup - CompanionX</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 to-purple-50">
<div class="bg-white p-10 rounded-2xl shadow-xl w-full max-w-2xl border border-gray-100 transform transition-transform hover:scale-[1.005] relative">
    <a href="adminDashboard.php" class="absolute top-5 left-5 text-blue-600 hover:text-blue-800 transition-colors">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
    </a>
    <h2 class="text-3xl font-bold mb-8 text-center text-blue-600">Consultant Signup</h2>

    <?php if ($success): ?>
        <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4"><?= $success ?></div>
    <?php elseif ($error): ?>
        <div class="bg-red-100 text-red-800 px-4 py-2 rounded mb-4"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <input type="text" name="first_name" placeholder="First Name" required class="input" />
        <input type="text" name="last_name" placeholder="Last Name" required class="input" />
        <input type="date" name="dob" placeholder="Date of Birth" required class="input" />
        <select name="gender" required class="input">
            <option value="">Gender</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
            <option value="other">Other</option>
        </select>
        <input type="email" name="email" placeholder="Email" required class="input" />
        <input type="password" name="password" placeholder="Password" required class="input" />
        <input type="text" name="specialization" placeholder="Specialization" required class="input" />
        <input type="number" name="session_charge" placeholder="Session Charge (৳)" required class="input" />
        <input type="text" name="office_address" placeholder="Office Address" required class="input" />
        <input type="text" name="tags" placeholder="Tags (e.g., anxiety, youth, adult)" class="input" />
        <input type="file" name="profile_picture" accept="image/*" class="input" />
        <textarea name="bio" placeholder="Short Bio..." rows="4" class="col-span-1 md:col-span-2 input"></textarea>
        <button type="submit" class="col-span-1 md:col-span-2 bg-gradient-to-r from-blue-600 to-blue-700 text-white py-3 rounded-lg hover:shadow-lg transition-all">
            Sign Up
        </button>
    </form>
</div>

<style>
    .input {
        padding: 0.75rem;
        border-radius: 0.75rem;
        border: 1px solid #e5e7eb;
        width: 100%;
        transition: all 0.3s ease;
        background-color: #f9fafb;
    }
    .input:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
    }
</style>
</body>
</html>