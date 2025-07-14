<?php
include 'config/db.php';

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

try {
    $query = "SELECT m.id, m.user_id, m.mood_emoji, m.mood_title, m.mood_note, m.entry_date, m.created_at, u.email AS user_email 
              FROM mood_entries m 
              LEFT JOIN users u ON m.user_id = u.id 
              WHERE m.entry_date BETWEEN :start_date AND :end_date";
    $params = [':start_date' => $start_date, ':end_date' => $end_date];
    
    if ($user_id) {
        $query .= " AND m.user_id = :user_id";
        $params[':user_id'] = $user_id;
    }
    
    $query .= " ORDER BY m.entry_date DESC, m.created_at DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $mood_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Mood entry query failed: " . $e->getMessage());
    $mood_entries = [];
    $error = "Failed to load mood entries: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mood Tracker | Companiox</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .table-row:hover { background-color: #f1f5f9; }
        .dark .table-row:hover { background-color: #374151; }
        .mood-emoji { font-size: 1.5em; }
        .mood-neutral { color: #eab308; } /* Yellow */
        .mood-sad { color: #ef4444; } /* Red */
        .mood-happy { color: #22c55e; } /* Green */
        .mood-angry { color: #f97316; } /* Orange */
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900">
    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-6">
        <header class="bg-white dark:bg-gray-800 shadow-sm p-4 mb-6">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white">Mood Tracker</h1>
        </header>
        
        <main>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                <div class="flex justify-between items-center mb-6 flex-col lg:flex-row gap-4">
                    <div class="flex space-x-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Start Date</label>
                            <input type="date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                                   class="mt-1 bg-gray-100 dark:bg-gray-700 border-none rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">End Date</label>
                            <input type="date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                                   class="mt-1 bg-gray-100 dark:bg-gray-700 border-none rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">User</label>
                            <select id="user_id" class="mt-1 bg-gray-100 dark:bg-gray-700 border-none rounded-lg px-3 py-2">
                                <option value="">All Users</option>
                                <?php
                                try {
                                    $users_stmt = $pdo->query("SELECT id, email FROM users ORDER BY email");
                                    while ($user = $users_stmt->fetch(PDO::FETCH_ASSOC)) {
                                        $selected = $user_id == $user['id'] ? 'selected' : '';
                                        echo "<option value=\"{$user['id']}\" $selected>" . htmlspecialchars($user['email']) . "</option>";
                                    }
                                } catch (PDOException $e) {
                                    error_log("User query failed: " . $e->getMessage());
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <button onclick="filterEntries()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                        <i class="fas fa-filter mr-2"></i>Filter
                    </button>
                </div>
                
                <?php if (isset($error)): ?>
                    <div class="mb-4 p-3 bg-red-100 text-red-600 rounded-lg">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($mood_entries)): ?>
                    <p class="text-gray-500 dark:text-white">No mood entries found.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-gray-100 dark:bg-gray-700">
                                   <!-- <th class="p-3 text-white">ID</th>-->
                                    <th class="p-3 text-white">User ID</th>
                                   <!-- <th class="p-3 text-white">User</th>-->
                                    <th class="p-3 text-white">Mood Emoji</th>
                                    <th class="p-3 text-white">Mood Title</th>
                                    <th class="p-3 text-white">Notes</th>
                                    <th class="p-3 text-white">Entry Date</th>
                                    <th class="p-3 text-white">Created At</th>
                                    <th class="p-3 text-white">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mood_entries as $entry): ?>
                                    <tr class="table-row border-b dark:border-gray-700">
                                        <!--<td class="p-3 text-white"><?php echo htmlspecialchars($entry['id']); ?></td>-->
                                        <td class="p-3 text-white"><?php echo htmlspecialchars($entry['user_id'] ?? 'N/A'); ?></td>
                                       <!-- <td class="p-3 text-white"><?php echo htmlspecialchars($entry['user_email'] ?? 'Unknown User'); ?></td>-->
                                        <td class="p-3 text-white mood-emoji mood-<?php echo htmlspecialchars(str_replace(' ', '-', $entry['mood_emoji'])); ?>">
                                            <?php echo htmlspecialchars($entry['mood_emoji']); ?>
                                        </td>
                                        <td class="p-3 text-white"><?php echo htmlspecialchars($entry['mood_title'] ?? 'N/A'); ?></td>
                                        <td class="p-3 text-white truncate"><?php echo htmlspecialchars($entry['mood_note'] ?? 'N/A'); ?></td>
                                        <td class="p-3 text-white"><?php echo htmlspecialchars($entry['entry_date'] ?? 'N/A'); ?></td>
                                        <td class="p-3 text-white"><?php echo htmlspecialchars($entry['created_at'] ?? 'N/A'); ?></td>
                                        <td class="p-3 text-white">
                                            <a href="view_graph.php?user_id=<?php echo $entry['user_id']; ?>" class="bg-blue-600 text-white px-2 py-1 rounded hover:bg-blue-700">
                                                <i class="fas fa-chart-line"></i> View Graph
                                            </a>
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

    <script>
        function filterEntries() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const userId = document.getElementById('user_id').value;
            let url = 'mood_tracker.php?start_date=' + startDate + '&end_date=' + endDate;
            if (userId) url += '&user_id=' + userId;
            window.location.href = url;
        }
    </script>
</body>
</html>