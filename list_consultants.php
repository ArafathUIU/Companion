<?php
include 'config/db.php';

try {
    $consultants_query = "SELECT id, name, email, specialization, created_at, status 
                         FROM consultants 
                         ORDER BY created_at DESC";
    $stmt = $pdo->query($consultants_query);
    $consultants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Consultant query failed: " . $e->getMessage());
    $consultants = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Consultants | Companiox</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .table-row:hover { background-color: #f1f5f9; }
        .dark .table-row:hover { background-color: #374151; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900">
    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-6">
        <header class="bg-white dark:bg-gray-800 shadow-sm p-4 mb-6">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white">Manage Consultants</h1>
        </header>
        
        <main>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white">Consultant List</h2>
                    <a href="ConsultantSignup.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i>Add Consultant
                    </a>
                </div>
                
                <?php if (empty($consultants)): ?>
                    <p class="text-gray-500 dark:text-white">No consultants found.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-gray-100 dark:bg-gray-700">
                                    <th class="p-3 text-white">ID</th>
                                    <th class="p-3 text-white">Name</th>
                                    <th class="p-3 text-white">Email</th>
                                    <th class="p-3 text-white">Specialization</th>
                                    <th class="p-3 text-white">Status</th>
                                    <th class="p-3 text-white">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($consultants as $consultant): ?>
                                    <tr class="table-row border-b dark:border-gray-700">
                                        <td class="p-3 text-white"><?php echo htmlspecialchars($consultant['id']); ?></td>
                                        <td class="p-3 text-white"><?php echo htmlspecialchars($consultant['name'] ?? 'N/A'); ?></td>
                                        <td class="p-3 text-white"><?php echo htmlspecialchars($consultant['email'] ?? 'N/A'); ?></td>
                                        <td class="p-3 text-white"><?php echo htmlspecialchars($consultant['specialization'] ?? 'N/A'); ?></td>
                                        <td class="p-3 text-white">
                                            <span class="px-2 py-1 rounded-full text-xs <?php echo ($consultant['status'] ?? 'active') == 'active' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                                                <?php echo htmlspecialchars($consultant['status'] ?? 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td class="p-3 text-white">
                                            <a href="edit_consultant.php?id=<?php echo $consultant['id']; ?>" class="text-blue-600 hover:text-blue-500"><i class="fas fa-edit"></i></a>
                                            <a href="delete_consultant.php?id=<?php echo $consultant['id']; ?>" class="text-red-600 hover:text-red-500 ml-3" onclick="return confirm('Are you sure?');"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>