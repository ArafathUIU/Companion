<?php
session_start();
require_once 'config/db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: adminLogin.php");
    exit;
}

// Fetch all consultants
$stmt = $pdo->prepare("SELECT * FROM consultants ORDER BY specialization ASC, last_name ASC");
$stmt->execute();
$consultants = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Consultants - CompanionX</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <nav class="bg-white shadow px-6 py-4 flex justify-between items-center">
        <div class="text-indigo-700 font-bold text-xl">Companion<span class="text-blue-500">X</span></div>
        <div>
            <a href="adminDashboard.php" class="mr-6 hover:text-blue-600">Dashboard</a>
            <a href="logout.php" class="text-red-600 hover:underline">Logout</a>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <h1 class="text-3xl font-semibold mb-6">Consultants Overview</h1>

        <?php if (count($consultants) === 0): ?>
            <p class="text-gray-600">No consultants found in the system.</p>
        <?php else: ?>
            <div class="overflow-x-auto bg-white rounded-lg shadow p-4">
                <table class="min-w-full text-sm text-left">
                    <thead class="bg-gray-200 text-gray-700 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-2">Avatar</th>
                            <th class="px-4 py-2">Name</th>
                            <th class="px-4 py-2">Specialization</th>
                            <th class="px-4 py-2">Available</th>
                            <th class="px-4 py-2">Session Charge (৳)</th>
                            <th class="px-4 py-2">Email</th>
                            <th class="px-4 py-2">Office Address</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-800">
                        <?php foreach ($consultants as $consultant): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="px-4 py-2">
                                    <?php if (!empty($consultant['profile_picture'])): ?>
                                        <img src="uploads/<?= htmlspecialchars($consultant['profile_picture']) ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover">
                                    <?php else: ?>
                                        <div class="w-10 h-10 rounded-full bg-gray-300 flex items-center justify-center text-white">N/A</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2 font-medium"><?= htmlspecialchars($consultant['first_name'] . ' ' . $consultant['last_name']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($consultant['specialization']) ?></td>
                                <td class="px-4 py-2">
                                    <span class="<?= $consultant['is_available'] ? 'text-green-600' : 'text-red-600' ?>">
                                        <?= $consultant['is_available'] ? 'Available' : 'Unavailable' ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2">৳<?= htmlspecialchars($consultant['session_charge']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($consultant['email']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($consultant['office_address']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
