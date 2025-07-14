<?php
session_start();
require_once 'config/db.php';

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/logs/php_error.log'); // Update path as needed
error_reporting(E_ALL);

// Redirect if not logged in as user
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user info
try {
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching user: " . $e->getMessage());
    die("Database error fetching user.");
}

// Validate circle_id
$circle_id = filter_input(INPUT_GET, 'circle_id', FILTER_VALIDATE_INT);
if ($circle_id) {
    // Verify user is approved member
    try {
        $stmt = $pdo->prepare("
            SELECT cm.status
            FROM circle_members cm
            WHERE cm.circle_id = ? AND cm.user_id = ? AND cm.status = 'approved'
        ");
        $stmt->execute([$circle_id, $user_id]);
        if (!$stmt->fetch()) {
            die("You are not authorized to access this circle.");
        }
    } catch (PDOException $e) {
        error_log("Error verifying membership: " . $e->getMessage());
        die("Error accessing circle.");
    }

    // Fetch circle and consultant details
    try {
        $stmt = $pdo->prepare("
            SELECT c.title, c.status, con.first_name, con.last_name
            FROM circles c
            JOIN consultants con ON c.lead_consultant_id = con.id
            WHERE c.id = ?
        ");
        $stmt->execute([$circle_id]);
        $circle = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$circle) {
            die("Circle not found.");
        }
    } catch (PDOException $e) {
        error_log("Error fetching circle: " . $e->getMessage());
        die("Error fetching circle.");
    }

    // Fetch messages
    try {
        $stmt = $pdo->prepare("
            SELECT cm.id, cm.message, cm.sent_at,
                   CASE 
                       WHEN cm.sender_user_id = ? THEN 'user'
                       WHEN cm.sender_consultant_id IS NOT NULL THEN 'consultant'
                       ELSE 'unknown'
                   END AS sender_type,
                   COALESCE(u.first_name, con.first_name, 'Unknown') AS first_name,
                   COALESCE(u.last_name, con.last_name, '') AS last_name
            FROM circle_messages cm
            LEFT JOIN users u ON cm.sender_user_id = u.id
            LEFT JOIN consultants con ON cm.sender_consultant_id = con.id
            WHERE cm.circle_id = ?
            ORDER BY cm.sent_at ASC
        ");
        $stmt->execute([$user_id, $circle_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $messages = [];
        error_log("Error fetching messages: " . $e->getMessage());
    }

    // Handle message submission
    $errors = [];
    $success = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $errors[] = "Invalid CSRF token.";
        } else {
            $message = trim($_POST['message'] ?? '');
            $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            if (empty($message)) {
                $errors[] = "Message cannot be empty.";
            } elseif (strlen($message) > 1000) {
                $errors[] = "Message is too long.";
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO circle_messages (circle_id, user_id, message, sender_user_id)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$circle_id, $user_id, $message, $user_id]);
                    $success = "Message sent successfully.";
                    header("Location: circle_talk.php?circle_id=$circle_id");
                    exit;
                } catch (PDOException $e) {
                    $errors[] = "Error sending message.";
                    error_log("Error sending message: " . $e->getMessage());
                }
            }
        }
    }
}

// Fetch available circles
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.title, c.description, c.category, c.lead_consultant_id,
               c.meeting_day, c.meeting_time, c.max_members,
               COUNT(cm.user_id) as member_count,
               con.first_name, con.last_name
        FROM circles c
        LEFT JOIN circle_members cm ON c.id = cm.circle_id AND cm.status = 'approved'
        LEFT JOIN consultants con ON c.lead_consultant_id = con.id
        WHERE c.status = 'active'
          AND c.id NOT IN (
              SELECT circle_id FROM circle_members WHERE user_id = ? AND status IN ('approved', 'pending')
          )
        GROUP BY c.id
        ORDER BY COALESCE(c.created_at, CURRENT_TIMESTAMP) DESC
    ");
    $stmt->execute([$user_id]);
    $available_circles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching available circles: " . $e->getMessage());
    $available_circles = [];
}

// Fetch joined circles
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.title, c.description, c.category, c.lead_consultant_id,
               c.meeting_day, c.meeting_time, c.max_members,
               COUNT(cm2.user_id) as member_count,
               con.first_name, con.last_name
        FROM circles c
        JOIN circle_members cm ON c.id = cm.circle_id
        LEFT JOIN circle_members cm2 ON c.id = cm2.circle_id AND cm2.status = 'approved'
        LEFT JOIN consultants con ON c.lead_consultant_id = con.id
        WHERE cm.user_id = ? AND cm.status = 'approved'
        GROUP BY c.id
        ORDER BY COALESCE(c.created_at, CURRENT_TIMESTAMP) DESC
    ");
    $stmt->execute([$user_id]);
    $joined_circles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching joined circles: " . $e->getMessage());
    $joined_circles = [];
}

// Fetch join requests
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.title, cm.status, cm.requested_at
        FROM circle_members cm
        JOIN circles c ON cm.circle_id = c.id
        WHERE cm.user_id = ? AND cm.status IN ('pending', 'approved')
        ORDER BY cm.requested_at DESC
    ");
    $stmt->execute([$user_id]);
    $join_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching join requests: " . $e->getMessage());
    $join_requests = [];
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Format date for requests and messages
function format_date($datetime, $type = 'request') {
    if (empty($datetime)) return "Unknown";
    $date = new DateTime($datetime);
    $now = new DateTime();
    if ($type === 'message') {
        if ($date->format('Y-m-d') === $now->format('Y-m-d')) {
            return 'Today, ' . $date->format('h:i A');
        }
        return $date->format('F j, Y, h:i A');
    }
    $interval = $now->diff($date);
    if ($interval->days == 0) return "Today";
    if ($interval->days == 1) return "Yesterday";
    if ($interval->days < 7) return $interval->days . " days ago";
    return $date->format('F j, Y');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CompanionX - Circle Talk</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 50%, #80deea 100%);
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        .gradient-indigo {
            background: linear-gradient(135deg, #6366f1, #3b82f6);
        }
        .hover-scale {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .hover-scale:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .tab-active {
            border-bottom: 3px solid #4f46e5;
            color: #4f46e5;
            font-weight: 600;
        }
        .notification {
            animation: fadeIn 0.3s ease-in-out;
        }
        .circle-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        .animate-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#e0f7fa',
                            100: '#b2ebf2',
                            200: '#80deea',
                            300: '#4dd0e1',
                            400: '#26c6da',
                            500: '#00bcd4',
                            600: '#00acc1',
                            700: '#0097a7',
                            800: '#00838f',
                            900: '#006064',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <?php if ($circle_id): ?>
            <!-- Chat Interface -->
            <header class="bg-gradient-to-r from-indigo-600 to-blue-600 text-white p-4 shadow-md mb-8 rounded-lg">
                <div class="flex justify-between items-center">
                    <div class="flex items-center space-x-3">
                        <i class="fas fa-comments text-2xl"></i>
                        <h1 class="text-xl font-bold"><?php echo htmlspecialchars($circle['title']); ?></h1>
                        <span class="bg-indigo-800 text-xs px-2 py-1 rounded-full"><?php echo htmlspecialchars(ucfirst($circle['status'])); ?></span>
                    </div>
                    <div>
                        <a href="circle_talk.php" class="p-2 rounded-full hover:bg-indigo-700 transition">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                    </div>
                </div>
            </header>

            <main class="flex-1 fade-in">
                <div class="flex flex-col bg-white rounded-lg shadow-md overflow-hidden">
                    <!-- Chat Header -->
                    <div class="border-b p-4 bg-gray-50">
                        <h2 class="font-semibold"><?php echo htmlspecialchars($circle['title']); ?></h2>
                        <p class="text-xs text-gray-500">Moderated by <?php echo htmlspecialchars($circle['first_name'] . ' ' . $circle['last_name']); ?></p>
                    </div>
                    
                    <!-- Messages -->
                    <div id="chatMessages" class="flex-1 p-4 overflow-y-auto custom-scrollbar space-y-4" style="min-height: 400px;">
                        <?php if (empty($messages)): ?>
                            <p class="text-center text-gray-500">No messages yet. Start the conversation!</p>
                        <?php else: ?>
                            <?php
                            $last_date = '';
                            foreach ($messages as $message):
                                $current_date = format_date($message['sent_at'], 'message');
                                $date_only = explode(',', $current_date)[0];
                                if ($date_only !== $last_date):
                            ?>
                                <div class="flex justify-center">
                                    <span class="bg-gray-100 text-gray-500 text-xs px-2 py-1 rounded-full"><?php echo htmlspecialchars($date_only); ?></span>
                                </div>
                            <?php
                                $last_date = $date_only;
                                endif;
                            ?>
                                <div class="flex space-x-3 <?php echo $message['sender_type'] === 'user' ? 'justify-end' : ''; ?>">
                                    <div class="flex-shrink-0">
                                        <?php if ($message['sender_type'] === 'consultant'): ?>
                                            <img src="https://placehold.co/40" alt="Consultant" class="w-10 h-10 rounded-full">
                                        <?php else: ?>
                                            <div class="w-10 h-10 bg-gray-300 rounded-full flex items-center justify-center text-gray-600 text-sm">
                                                <?php echo substr($message['first_name'], 0, 1) . substr($message['last_name'], 0, 1); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="<?php echo $message['sender_type'] === 'user' ? 'text-right' : ''; ?>">
                                        <div class="flex items-center space-x-2 <?php echo $message['sender_type'] === 'user' ? 'justify-end' : ''; ?>">
                                            <span class="font-semibold <?php echo $message['sender_type'] === 'consultant' ? 'text-indigo-600' : 'text-gray-800'; ?>">
                                                <?php echo htmlspecialchars($message['first_name'] . ' ' . $message['last_name']); ?>
                                            </span>
                                            <span class="text-xs text-gray-400"><?php echo date('h:i A', strtotime($message['sent_at'])); ?></span>
                                            <?php if ($message['sender_type'] === 'consultant'): ?>
                                                <span class="text-xs bg-indigo-100 text-indigo-800 px-1 rounded">Consultant</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-1 <?php echo $message['sender_type'] === 'consultant' ? 'bg-indigo-50 text-gray-800' : 'bg-primary-100 text-gray-800'; ?> p-3 rounded-lg max-w-md <?php echo $message['sender_type'] === 'user' ? 'rounded-br-none' : 'rounded-bl-none'; ?>">
                                            <p><?php echo htmlspecialchars($message['message']); ?></p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Message Input -->
                    <div class="border-t p-4 bg-gray-50">
                        <?php if (!empty($errors)): ?>
                            <div class="mb-2 text-red-600 text-sm">
                                <?php foreach ($errors as $error): ?>
                                    <p><?php echo htmlspecialchars($error); ?></p>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="mb-2 text-green-600 text-sm">
                                <p><?php echo htmlspecialchars($success); ?></p>
                            </div>
                        <?php endif; ?>
                        <form method="POST" action="" class="flex items-center space-x-2">
                            <input type="hidden" name="action" value="send_message">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="flex-1">
                                <input type="text" name="message" placeholder="Type your message..." 
                                       class="w-full bg-white border border-gray-300 rounded-full py-2 px-4 focus:outline-none focus:ring-2 focus:ring-primary-200 focus:border-primary-500">
                            </div>
                            <button type="submit" class="p-2 gradient-indigo text-white rounded-full hover-scale">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </main>
        <?php else: ?>
            <!-- Circle Discovery Interface -->
            <header class="flex justify-between items-center mb-8">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-comments text-primary-500 text-3xl"></i>
                    <h1 class="text-2xl font-bold text-gray-800">Circle Talk</h1>
                </div>
                <div class="relative">
                    <input type="text" id="searchInput" placeholder="Search circles..." class="pl-10 pr-4 py-2 rounded-full border border-gray-300 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                </div>
            </header>

            <!-- Tabs -->
            <div class="flex border-b border-gray-200 mb-8">
                <button id="available-tab" class="tab-active px-4 py-2 font-medium text-sm focus:outline-none">
                    Available Circles
                </button>
                <button id="joined-tab" class="px-4 py-2 font-medium text-sm text-gray-500 hover:text-gray-700 focus:outline-none">
                    Your Circles
                </button>
                <button id="requests-tab" class="px-4 py-2 font-medium text-sm text-gray-500 hover:text-gray-700 focus:outline-none">
                    Join Requests
                </button>
            </div>

            <!-- Notifications -->
            <div id="notifications" class="fixed top-4 right-4 z-50"></div>

            <!-- Available Circles Section -->
            <section id="available-circles" class="mb-12">
                <h2 class="text-xl font-semibold mb-6 text-gray-800">Discover New Circles</h2>
                <div id="available-circles-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if (empty($available_circles)): ?>
                        <p class="text-gray-600 text-center col-span-3">No available circles found.</p>
                    <?php else: ?>
                        <?php foreach ($available_circles as $circle): 
                            $gradient = match($circle['category']) {
                                'mental-health', 'Mental Health' => 'from-indigo-500 to-purple-600',
                                'Chronic Illness' => 'from-blue-500 to-teal-400',
                                'Parenting' => 'from-amber-500 to-pink-500',
                                'Disability' => 'from-emerald-500 to-green-400',
                                'Neurodiversity' => 'from-purple-500 to-pink-500',
                                'addiction' => 'from-teal-500 to-cyan-600',
                                default => 'from-gray-500 to-gray-600'
                            };
                            $icon = match($circle['category']) {
                                'mental-health', 'Mental Health' => 'fa-users',
                                'Chronic Illness' => 'fa-heartbeat',
                                'Parenting' => 'fa-child',
                                'Disability' => 'fa-wheelchair',
                                'Neurodiversity' => 'fa-brain',
                                'addiction' => 'fa-leaf',
                                default => 'fa-circle'
                            };
                        ?>
                            <div class="bg-white rounded-lg shadow-md overflow-hidden circle-card transition-all duration-300" data-title="<?php echo htmlspecialchars($circle['title']); ?>" data-category="<?php echo htmlspecialchars($circle['category']); ?>">
                                <div class="h-40 bg-gradient-to-r <?php echo $gradient; ?> flex items-center justify-center">
                                    <i class="fas <?php echo $icon; ?> text-white text-6xl"></i>
                                </div>
                                <div class="p-6">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars($circle['title']); ?></h3>
                                    <p class="text-gray-600 text-sm mb-2"><?php echo htmlspecialchars(substr($circle['description'], 0, 100)); ?>...</p>
                                    <p class="text-gray-500 text-xs mb-1">Category: <?php echo htmlspecialchars($circle['category']); ?></p>
                                    <p class="text-gray-500 text-xs mb-1">Meeting: <?php echo htmlspecialchars($circle['meeting_day'] . ' at ' . $circle['meeting_time']); ?></p>
                                    <p class="text-gray-500 text-xs mb-4">Members: <?php echo $circle['member_count']; ?>/<?php echo $circle['max_members']; ?></p>
                                    <div class="flex justify-between items-center">
                                        <div class="flex -space-x-2">
                                            <?php for ($i = 0; $i < min($circle['member_count'], 3); $i++): ?>
                                                <img class="w-8 h-8 rounded-full border-2 border-white" src="https://placehold.co/32x32" alt="Member">
                                            <?php endfor; ?>
                                            <?php if ($circle['member_count'] > 3): ?>
                                                <div class="w-8 h-8 rounded-full border-2 border-white bg-gray-200 flex items-center justify-center text-xs font-medium">
                                                    +<?php echo $circle['member_count'] - 3; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <button class="request-join bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-full text-sm font-medium transition-colors duration-300" data-circle-id="<?php echo $circle['id']; ?>">
                                            Request Join
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="mt-8 text-center">
                    <button class="px-6 py-2 border border-gray-300 rounded-full text-gray-700 hover:bg-gray-100 transition-colors duration-300">
                        Load More Circles
                    </button>
                </div>
            </section>

            <!-- Your Circles Section -->
            <section id="joined-circles" class="hidden mb-12">
                <h2 class="text-xl font-semibold mb-6 text-gray-800">Your Active Circles</h2>
                <div id="joined-circles-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if (empty($joined_circles)): ?>
                        <p class="text-gray-600 text-center col-span-3">You haven't joined any circles yet.</p>
                    <?php else: ?>
                        <?php foreach ($joined_circles as $circle): 
                            $gradient = match($circle['category']) {
                                'mental-health', 'Mental Health' => 'from-indigo-500 to-purple-600',
                                'Chronic Illness' => 'from-blue-500 to-teal-400',
                                'Parenting' => 'from-amber-500 to-pink-500',
                                'Disability' => 'from-emerald-500 to-green-400',
                                'Neurodiversity' => 'from-purple-500 to-pink-500',
                                'addiction' => 'from-teal-500 to-cyan-600',
                                default => 'from-gray-500 to-gray-600'
                            };
                            $icon = match($circle['category']) {
                                'mental-health', 'Mental Health' => 'fa-users',
                                'Chronic Illness' => 'fa-heartbeat',
                                'Parenting' => 'fa-child',
                                'Disability' => 'fa-wheelchair',
                                'Neurodiversity' => 'fa-brain',
                                'addiction' => 'fa-leaf',
                                default => 'fa-circle'
                            };
                        ?>
                            <div class="bg-white rounded-lg shadow-md overflow-hidden circle-card transition-all duration-300" data-title="<?php echo htmlspecialchars($circle['title']); ?>" data-category="<?php echo htmlspecialchars($circle['category']); ?>">
                                <div class="h-40 bg-gradient-to-r <?php echo $gradient; ?> flex items-center justify-center">
                                    <i class="fas <?php echo $icon; ?> text-white text-6xl"></i>
                                </div>
                                <div class="p-6">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars($circle['title']); ?></h3>
                                    <p class="text-gray-600 text-sm mb-2"><?php echo htmlspecialchars(substr($circle['description'], 0, 100)); ?>...</p>
                                    <p class="text-gray-500 text-xs mb-1">Category: <?php echo htmlspecialchars($circle['category']); ?></p>
                                    <p class="text-gray-500 text-xs mb-1">Meeting: <?php echo htmlspecialchars($circle['meeting_day'] . ' at ' . $circle['meeting_time']); ?></p>
                                    <p class="text-gray-500 text-xs mb-4">Members: <?php echo $circle['member_count']; ?>/<?php echo $circle['max_members']; ?></p>
                                    <div class="flex justify-between items-center">
                                        <div class="flex -space-x-2">
                                            <?php for ($i = 0; $i < min($circle['member_count'], 3); $i++): ?>
                                                <img class="w-8 h-8 rounded-full border-2 border-white" src="https://placehold.co/32x32" alt="Member">
                                            <?php endfor; ?>
                                            <?php if ($circle['member_count'] > 3): ?>
                                                <div class="w-8 h-8 rounded-full border-2 border-white bg-gray-200 flex items-center justify-center text-xs font-medium">
                                                    +<?php echo $circle['member_count'] - 3; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <a href="circle_talk.php?circle_id=<?php echo $circle['id']; ?>" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-full text-sm font-medium transition-colors duration-300">
                                            <i class="fas fa-check mr-1"></i> Joined
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="mt-8 text-center">
                    <button class="px-6 py-2 border border-gray-300 rounded-full text-gray-700 hover:bg-gray-100 transition-colors duration-300">
                        View All Your Circles
                    </button>
                </div>
            </section>

            <!-- Join Requests Section -->
            <section id="join-requests" class="hidden mb-12">
                <h2 class="text-xl font-semibold mb-6 text-gray-800">Your Join Requests</h2>
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <?php if (empty($join_requests)): ?>
                        <p class="text-gray-600 text-center p-6">No join requests found.</p>
                    <?php else: ?>
                        <?php foreach ($join_requests as $request): ?>
                            <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                                <div>
                                    <h3 class="font-medium text-gray-800"><?php echo htmlspecialchars($request['title']); ?></h3>
                                    <p class="text-sm text-gray-600">Request sent <?php echo format_date($request['requested_at']); ?></p>
                                </div>
                                <div class="flex space-x-2">
                                    <?php if ($request['status'] == 'pending'): ?>
                                        <button class="cancel-request px-4 py-2 bg-gray-100 text-gray-700 rounded-full text-sm font-medium hover:bg-gray-200 transition-colors duration-300" data-circle-id="<?php echo $request['id']; ?>">
                                            Cancel
                                        </button>
                                        <div class="px-4 py-2 bg-yellow-100 text-yellow-800 rounded-full text-sm font-medium flex items-center">
                                            <span class="h-2 w-2 rounded-full bg-yellow-500 mr-2 animate-pulse"></span>
                                            Pending
                                        </div>
                                    <?php else: ?>
                                        <div class="px-4 py-2 bg-green-100 text-green-800 rounded-full text-sm font-medium flex items-center">
                                            <i class="fas fa-check-circle mr-2"></i> Accepted
                                        </div>
                                        <a href="circle_talk.php?circle_id=<?php echo $request['id']; ?>" class="px-4 py-2 bg-primary-500 text-white rounded-full text-sm font-medium hover:bg-primary-600 transition-colors duration-300">
                                            View Circle
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Create Circle Button -->
            <div class="fixed bottom-8 right-8">
                <button class="w-14 h-14 rounded-full bg-primary-500 text-white shadow-lg hover:bg-primary-600 transition-colors duration-300 flex items-center justify-center" title="Create a new circle">
                    <i class="fas fa-plus text-xl"></i>
                </button>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!$circle_id): ?>
                // Tab functionality
                const availableTab = document.getElementById('available-tab');
                const joinedTab = document.getElementById('joined-tab');
                const requestsTab = document.getElementById('requests-tab');
                const availableSection = document.getElementById('available-circles');
                const joinedSection = document.getElementById('joined-circles');
                const requestsSection = document.getElementById('join-requests');
                const searchInput = document.getElementById('searchInput');

                setActiveTab(availableTab, [joinedTab, requestsTab]);

                availableTab.addEventListener('click', function() {
                    setActiveTab(availableTab, [joinedTab, requestsTab]);
                    availableSection.classList.remove('hidden');
                    joinedSection.classList.add('hidden');
                    requestsSection.classList.add('hidden');
                    filterCircles();
                });

                joinedTab.addEventListener('click', function() {
                    setActiveTab(joinedTab, [availableTab, requestsTab]);
                    availableSection.classList.add('hidden');
                    joinedSection.classList.remove('hidden');
                    requestsSection.classList.add('hidden');
                    filterCircles();
                });

                requestsTab.addEventListener('click', function() {
                    setActiveTab(requestsTab, [availableTab, joinedTab]);
                    availableSection.classList.add('hidden');
                    joinedSection.classList.add('hidden');
                    requestsSection.classList.remove('hidden');
                });

                function setActiveTab(activeTab, inactiveTabs) {
                    activeTab.classList.add('tab-active');
                    activeTab.classList.remove('text-gray-500');
                    activeTab.classList.add('text-primary-600');
                    inactiveTabs.forEach(tab => {
                        tab.classList.remove('tab-active');
                        tab.classList.add('text-gray-500');
                        tab.classList.remove('text-primary-600');
                    });
                }

                // Search functionality
                searchInput.addEventListener('input', filterCircles);
                
                function filterCircles() {
                    const query = searchInput.value.toLowerCase();
                    const activeSection = availableSection.classList.contains('hidden') ? joinedSection : availableSection;
                    const grid = activeSection.querySelector('.grid');
                    const cards = grid.querySelectorAll('.circle-card');
                    
                    cards.forEach(card => {
                        const title = card.dataset.title.toLowerCase();
                        const category = card.dataset.category.toLowerCase();
                        if (title.includes(query) || category.includes(query)) {
                            card.classList.remove('hidden');
                        } else {
                            card.classList.add('hidden');
                        }
                    });

                    const visibleCards = Array.from(cards).filter(card => !card.classList.contains('hidden'));
                    const emptyMessage = grid.querySelector('.text-center');
                    if (visibleCards.length === 0 && emptyMessage) {
                        emptyMessage.classList.remove('hidden');
                    } else if (emptyMessage) {
                        emptyMessage.classList.add('hidden');
                    }
                }

                // Request join functionality
                const requestButtons = document.querySelectorAll('.request-join');
                requestButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        const circleId = this.dataset.circleId;
                        const circleCard = this.closest('.circle-card');
                        const circleName = circleCard.querySelector('h3').textContent;
                        
                        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Requesting...';
                        this.classList.remove('bg-primary-500', 'hover:bg-primary-600');
                        this.classList.add('bg-gray-400', 'cursor-not-allowed');
                        this.disabled = true;

                        fetch('request_join.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                circle_id: circleId,
                                csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>'
                            })
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`HTTP error! Status: ${response.status}`);
                            }
                            return response.text();
                        })
                        .then(text => {
                            try {
                                const data = JSON.parse(text);
                                if (data.success) {
                                    this.innerHTML = '<i class="fas fa-check mr-1"></i> Request Sent';
                                    this.classList.remove('bg-gray-400', 'cursor-not-allowed');
                                    this.classList.add('bg-green-500', 'hover:bg-green-600');
                                    showNotification(`Join request sent to "${circleName}"`, 'success');
                                } else {
                                    this.innerHTML = 'Request Join';
                                    this.classList.remove('bg-gray-400', 'cursor-not-allowed');
                                    this.classList.add('bg-primary-500', 'hover:bg-primary-600');
                                    this.disabled = false;
                                    showNotification(data.message || 'Error sending request', 'error');
                                }
                            } catch (e) {
                                console.error('Invalid JSON:', text);
                                throw new Error('Invalid JSON response');
                            }
                        })
                        .catch(error => {
                            this.innerHTML = 'Request Join';
                            this.classList.remove('bg-gray-400', 'cursor-not-allowed');
                            this.classList.add('bg-primary-500', 'hover:bg-primary-600');
                            this.disabled = false;
                            showNotification('Network error: ' + error.message, 'error');
                            console.error('Fetch error:', error);
                        });
                    });
                });

                // Cancel request functionality
                const cancelButtons = document.querySelectorAll('.cancel-request');
                cancelButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        const circleId = this.dataset.circleId;
                        const requestRow = this.closest('.flex');
                        const circleName = requestRow.querySelector('h3').textContent;

                        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Canceling...';
                        this.classList.add('cursor-not-allowed');
                        this.disabled = true;

                        fetch('cancel_request.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                circle_id: circleId,
                                csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>'
                            })
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`HTTP error! Status: ${response.status}`);
                            }
                            return response.text();
                        })
                        .then(text => {
                            try {
                                const data = JSON.parse(text);
                                if (data.success) {
                                    requestRow.remove();
                                    showNotification(`Canceled request for "${circleName}"`, 'success');
                                    window.location.reload();
                                } else {
                                    this.innerHTML = 'Cancel';
                                    this.classList.remove('cursor-not-allowed');
                                    this.disabled = false;
                                    showNotification(data.message || 'Error canceling request', 'error');
                                }
                            } catch (e) {
                                console.error('Invalid JSON:', text);
                                throw new Error('Invalid JSON response');
                            }
                        })
                        .catch(error => {
                            this.innerHTML = 'Cancel';
                            this.classList.remove('cursor-not-allowed');
                            this.disabled = false;
                            showNotification('Network error: ' + error.message, 'error');
                            console.error('Fetch error:', error);
                        });
                    });
                });
            <?php else: ?>
                // Auto-scroll chat to bottom
                const chatMessages = document.getElementById('chatMessages');
                chatMessages.scrollTop = chatMessages.scrollHeight;

                // Handle Enter key for message input
                document.querySelector('input[name="message"]').addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        e.target.form.submit();
                    }
                });
            <?php endif; ?>

            // Show notification
            function showNotification(message, type = 'success') {
                const notification = document.createElement('div');
                notification.className = `notification fixed top-4 right-4 px-4 py-2 rounded-lg shadow-lg flex items-center animate-fade-in ${
                    type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'
                }`;
                notification.innerHTML = `
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-2"></i>
                    <span>${message}</span>
                `;
                
                const notificationsContainer = document.getElementById('notifications');
                notificationsContainer.appendChild(notification);
                
                setTimeout(() => {
                    notification.classList.add('opacity-0', 'transition-opacity', 'duration-300');
                    setTimeout(() => notification.remove(), 300);
                }, 3000);
            }
        });
    </script>
</body>
</html>