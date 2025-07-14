<?php
include 'config/db.php';

$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$where_clause = $status_filter !== 'all' ? "WHERE status = :status" : "";
try {
    $session_query = "SELECT b.id, b.user_id, b.consultant_id, c.name AS consultant_name, b.status, b.created_at, b.scheduled_at 
                     FROM anonymous_counselling_bookings b 
                     LEFT JOIN consultants c ON b.consultant_id = c.id 
                     $where_clause 
                     ORDER BY b.created_at DESC";
    $stmt = $pdo->prepare($session_query);
    if ($status_filter !== 'all') {
        $stmt->bindParam(':status', $status_filter);
    }
    $stmt->execute();
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Session query failed: " . $e->getMessage());
    $sessions = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Sessions | Companiox</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 dark:bg-gray-900">
    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-6">
        <header class="bg-white dark:bg-gray-800 shadow-sm p-4 mb-6">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white">Manage Sessions</h1>
        </header>
        
        <main>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white">Session List</h2>
                    <select onchange="window.location.href='manage_sessions.php?status='+this.value" class="bg-gray-100 dark:bg-gray-700 border-none text-sm rounded-lg px-3 py-1">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                
                <?php if (empty($sessions)): ?>
                    <p class="text-gray-500 dark:text-gray-400">No sessions found.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-gray-100 dark:bg-gray-700">
                                    <th class="p-3">ID</th>
                                    <th class="p-3">User ID</th>
                                    <th class="p-3">Consultant</th>
                                    <th class="p-3">Status</th>
                                    <th class="p-3">Scheduled At</th>
                                    <th class="p-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessions as $session): ?>
                                    <tr class="border-b dark:border-gray-700">
                                        <td class="p-3"><?php echo htmlspecialchars($session['id']); ?></td>
                                        <td class="p-3"><?php echo htmlspecialchars($session['user_id']); ?></td>
                                        <td class="p-3"><?php echo htmlspecialchars($session['consultant_name'] ?? 'Unknown Consultant'); ?></td>
                                        <td class="p-3">
                                            <span class="px-2 py-1 rounded-full text-xs <?php echo ($session['status'] ?? 'pending') == 'completed' ? 'bg-green-100 text-green-600' : (($session['status'] ?? 'pending') == 'pending' ? 'bg-yellow-100 text-yellow-600' : 'bg-red-100 text-red-600'); ?>">
                                                <?php echo htmlspecialchars($session['status'] ?? 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td class="p-3"><?php echo htmlspecialchars($session['scheduled_at'] ?? 'N/A'); ?></td>
                                        <td class="p-3">
                                            <a href="edit_session.php?id=<?php echo $session['id']; ?>" class="text-blue-600 hover:text-blue-500"><i class="fas fa-edit"></i></a>
                                            <a href="cancel_session.php?id=<?php echo $session['id']; ?>" class="text-red-600 hover:text-red-500 ml-3" onclick="return confirm('Are you sure?');"><i class="fas fa-trash"></i></a>
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