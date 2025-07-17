<?php
ob_start(); // Start output buffering
session_start();
require_once 'config/db.php';
require_once 'config/config.php';

// Define Nominatim User-Agent if not already defined
if (!defined('NOMINATIM_USER_AGENT')) {
    define('NOMINATIM_USER_AGENT', 'CompanionX/1.0 (contact@companionx.com)');
}

// Apply session security settings
ini_set('session.cookie_secure', SESSION_COOKIE_SECURE);
ini_set('session.cookie_httponly', SESSION_COOKIE_HTTPONLY);
ini_set('session.cookie_samesite', SESSION_COOKIE_SAMESITE);

// Error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', ERROR_LOG_PATH);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$consultants = [];

// Expected columns and their default values based on actual table schema
$columns = [
    'id' => ['field' => 'id', 'default' => null],
    'name' => ['field' => 'COALESCE(CONCAT(first_name, \' \', last_name), \'Unknown\') AS name', 'default' => 'Unknown'],
    'specialization' => ['field' => 'COALESCE(specialization, \'N/A\') AS specialization', 'default' => 'N/A'],
    'office_address' => ['field' => 'COALESCE(office_address, \'N/A\') AS office_address', 'default' => 'N/A'],
    'latitude' => ['field' => 'latitude', 'default' => 0],
    'longitude' => ['field' => 'longitude', 'default' => 0],
    'bio' => ['field' => 'COALESCE(bio, \'\') AS bio', 'default' => ''],
    'session_charge' => ['field' => 'COALESCE(session_charge, 0) AS session_charge', 'default' => 0],
    'is_available' => ['field' => 'COALESCE(is_available, 1) AS is_available', 'default' => 1],
    'profile_picture' => ['field' => 'COALESCE(profile_picture, \'https://via.placeholder.com/150\') AS profile_picture', 'default' => 'https://via.placeholder.com/150'],
    'tags' => ['field' => 'COALESCE(tags, \'\') AS tags', 'default' => ''],
    'available_days' => ['field' => 'COALESCE(available_days, \'\') AS available_days', 'default' => ''],
    'available_times' => ['field' => 'COALESCE(available_times, \'\') AS available_times', 'default' => ''],
    'status' => ['field' => 'status', 'default' => 'active']
];

try {
    if (!$pdo) {
        throw new Exception("Database connection is not established.");
    }

    // Get database name for logging
    $db_name = $pdo->query("SELECT DATABASE()")->fetchColumn();
    error_log("Connected to database: " . ($db_name ?: 'Unknown'));

    // Check if consultants table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'consultants'");
    if ($stmt->rowCount() === 0) {
        throw new PDOException("Table 'consultants' does not exist in database '$db_name'.");
    }

    // Get existing columns in the consultants table
    $stmt = $pdo->query("SHOW COLUMNS FROM consultants");
    $existing_columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    error_log("Existing columns in consultants table: " . implode(', ', $existing_columns));

    // Build dynamic query
    $select_fields = [];
    $required_columns = ['id', 'status'];
    $used_columns = ['id', 'status'];
    foreach ($columns as $key => $config) {
        if ($key === 'id' || $key === 'status') {
            $select_fields[] = $config['field'];
            continue;
        }
        if ($key === 'name') {
            if (in_array('first_name', $existing_columns) && in_array('last_name', $existing_columns)) {
                $select_fields[] = $config['field'];
                $used_columns[] = $key;
            }
        } elseif (in_array($key, $existing_columns)) {
            $select_fields[] = $config['field'];
            $used_columns[] = $key;
        }
    }

    // Ensure required columns are present
    if (!in_array('id', $existing_columns) || !in_array('status', $existing_columns)) {
        throw new PDOException("Required columns 'id' or 'status' missing in consultants table.");
    }

    // Construct and execute query
    $query = "SELECT " . implode(', ', $select_fields) . " FROM consultants WHERE status = 'active'";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $consultants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalize and sanitize data
    foreach ($consultants as &$consultant) {
        foreach ($columns as $key => $config) {
            if (!array_key_exists($key, $consultant) && $key !== 'status') {
                $consultant[$key] = $config['default'];
            }
            // Sanitize text fields to ensure UTF-8 compatibility
            if (in_array($key, ['bio', 'office_address', 'tags', 'specialization', 'name', 'available_days', 'available_times'])) {
                $consultant[$key] = mb_convert_encoding($consultant[$key], 'UTF-8', 'UTF-8');
            }
        }
        $consultant['coordinates'] = [
            'lat' => floatval($consultant['latitude'] ?? 0),
            'lng' => floatval($consultant['longitude'] ?? 0)
        ];
        $consultant['is_available'] = boolval($consultant['is_available'] ?? 1);
        $consultant['session_charge'] = intval($consultant['session_charge'] ?? 0);
        $consultant['profile_picture'] = $consultant['profile_picture'] ?: 'https://via.placeholder.com/150';
    }
    unset($consultant);

    if (count($used_columns) < count($columns) - 1) {
        $missing = array_diff(array_keys($columns), $used_columns);
        $errors[] = "Some consultant data is unavailable due to missing columns: " . implode(', ', $missing);
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage() . " | Query: " . ($stmt->queryString ?? 'N/A') . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    $errors[] = "Database error occurred. Please try again or contact support with error code: DB-" . time();

    // Fallback query
    try {
        $stmt = $pdo->prepare("SELECT id, 'Unknown' AS name FROM consultants WHERE status = 'active'");
        $stmt->execute();
        $consultants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($consultants as &$consultant) {
            $consultant['coordinates'] = ['lat' => 0, 'lng' => 0];
            $consultant['specialization'] = 'N/A';
            $consultant['office_address'] = 'N/A';
            $consultant['bio'] = '';
            $consultant['session_charge'] = 0;
            $consultant['is_available'] = false;
            $consultant['profile_picture'] = 'https://via.placeholder.com/150';
            $consultant['tags'] = '';
            $consultant['available_days'] = '';
            $consultant['available_times'] = '';
        }
        unset($consultant);
        $errors[] = "Limited consultant data loaded due to database issue.";
    } catch (PDOException $e) {
        error_log("Fallback query failed: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
        $consultants = [];
        $errors[] = "No consultant data could be loaded. Please contact support.";
    }
} catch (Exception $e) {
    error_log("General error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    $errors[] = "An unexpected error occurred. Please contact support with error code: GEN-" . time();
    $consultants = [];
}

// Validate JSON encoding
$consultants_json = json_encode($consultants, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON encoding error: " . json_last_error_msg());
    $consultants_json = '[]';
    $errors[] = "Error processing consultant data. Please contact support.";
}

// Clean output buffer
ob_end_clean();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Find mental health consultants near you with CompanionX. Search by name, specialization, or location.">
    <title>CompanionX - Find Consultants Near Me</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 50%, #80deea 100%);
            position: relative;
            overflow-x: hidden;
        }
        #map {
            height: 450px;
            width: 100%;
            border-radius: 1rem;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            transition: height 0.3s ease;
        }
        .consultant-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .consultant-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 8px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #e5e7eb;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #4dd0e1;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #26c6da;
        }
        .error-alert {
            animation: slideIn 0.3s ease;
        }
        .search-input {
            position: relative;
        }
        .search-input i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
        }
        #suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 0.75rem;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
            margin-top: 0.5rem;
        }
        #suggestions div {
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        #suggestions div:hover, #suggestions div.focused {
            background: #e0f7fa;
        }
        .btn-primary {
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .btn-primary:hover {
            transform: scale(1.05);
            background: #26c6da;
        }
        .btn-secondary {
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .btn-secondary:hover {
            transform: scale(1.05);
            background: #e0f7fa;
        }
        @keyframes slideIn {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .petal {
            position: absolute;
            background: url('https://cdn.pixabay.com/photo/2016/04/15/04/02/flower-1330062_1280.png') no-repeat center;
            background-size: contain;
            width: 20px;
            height: 20px;
            opacity: 0.5;
            animation: float 15s infinite;
            z-index: 0;
        }
        @keyframes float {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 0.5;
            }
            50% {
                opacity: 0.8;
            }
            100% {
                transform: translateY(100vh) rotate(360deg);
                opacity: 0.2;
            }
        }
        .loading-spinner {
            display: none;
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }
        .loading-spinner.active {
            display: block;
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
                        },
                        secondary: {
                            50: '#e8f5e9',
                            100: '#c8e6c9',
                            200: '#a5d6a7',
                            300: '#81c784',
                            400: '#66bb6a',
                            500: '#4caf50',
                            600: '#43a047',
                            700: '#388e3c',
                            800: '#2e7d32',
                            900: '#1b5e20',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen">
    <!-- Floating Petals -->
    <script>
        function createPetals() {
            const petalCount = 5;
            for (let i = 0; i < petalCount; i++) {
                const petal = document.createElement('div');
                petal.className = 'petal';
                petal.style.left = Math.random() * 100 + 'vw';
                petal.style.animationDelay = Math.random() * 5 + 's';
                petal.addEventListener('animationend', () => petal.remove());
                document.body.appendChild(petal);
            }
        }
        document.addEventListener('DOMContentLoaded', createPetals);
        setInterval(createPetals, 5000);
    </script>

    <!-- Header -->
    <header class="bg-white shadow-sm fixed top-0 left-0 right-0 z-50">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-primary-500 rounded-full flex items-center justify-center text-white mr-3">
                    <i class="fas fa-brain text-2xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-gray-800">CompanionX</h1>
            </div>
            <nav class="flex items-center space-x-4">
                <a href="dashboard.php" class="px-4 py-2 text-gray-600 hover:text-primary-500 transition rounded-lg hover:bg-primary-50">
                    <i class="fas fa-users mr-2"></i> Dashboard
                </a>
                <a href="profile.php" class="px-4 py-2 text-gray-600 hover:text-primary-500 transition rounded-lg hover:bg-primary-50">
                    <i class="fas fa-user mr-2"></i> Profile
                </a>
                <a href="logout.php" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition">
                    <i class="fas fa-sign-out-alt mr-2"></i> Logout
                </a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <div class="container mx-auto px-4 pt-24 pb-12">
        <!-- Error Messages -->
        <?php if (!empty($errors)): ?>
            <div id="error-alert" class="error-alert bg-red-50 text-red-700 p-4 rounded-xl mb-6 flex items-center justify-between max-w-2xl mx-auto">
                <div>
                    <ul class="list-disc list-inside space-y-2">
                        <?php foreach ($errors as $error): ?>
                            <li class="text-sm"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="flex space-x-2">
                    <button id="retry-btn" class="text-primary-600 hover:text-primary-800 transition" aria-label="Retry">
                        <i class="fas fa-redo-alt"></i> Retry
                    </button>
                    <button id="dismiss-error" class="text-red-600 hover:text-red-800 transition" aria-label="Dismiss error">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Search Section -->
        <section class="mb-8">
            <div class="bg-white rounded-2xl p-6 shadow-sm">
                <h2 class="text-2xl font-semibold text-gray-800 mb-6 flex items-center">
                    Find Consultants Near You
                    <i class="fas fa-location-dot text-primary-600 ml-2"></i>
                </h2>
                <form id="search-form" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="search-input">
                        <label for="name-search" class="block text-sm font-medium text-gray-700 mb-2">Name</label>
                        <div class="relative">
                            <i class="fas fa-user-md"></i>
                            <input type="text" id="name-search" name="name-search" placeholder="Enter doctor's name..."
                                   class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent transition"
                                   aria-label="Search by doctor's name">
                        </div>
                    </div>
                    <div class="search-input">
                        <label for="specialization-search" class="block text-sm font-medium text-gray-700 mb-2">Specialization</label>
                        <div class="relative">
                            <i class="fas fa-stethoscope"></i>
                            <input type="text" id="specialization-search" name="specialization-search" placeholder="e.g., Anxiety, Stress..."
                                   class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent transition"
                                   aria-label="Search by specialization">
                        </div>
                    </div>
                    <div class="search-input relative">
                        <label for="area-search" class="block text-sm font-medium text-gray-700 mb-2">Area</label>
                        <div class="relative">
                            <i class="fas fa-map-marker-alt"></i>
                            <input type="text" id="area-search" name="area-search" placeholder="e.g., Mirpur, Dhaka"
                                   class="w-full pl-10 pr-10 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent transition"
                                   autocomplete="off" aria-label="Search by area" aria-autocomplete="list" aria-controls="suggestions">
                            <i class="fas fa-spinner fa-spin loading-spinner"></i>
                            <div id="suggestions" class="hidden custom-scrollbar" role="listbox"></div>
                        </div>
                    </div>
                    <div class="flex space-x-2">
                        <button type="button" id="near-me-btn" class="btn-primary flex-1 bg-primary-500 text-white py-3 rounded-lg hover:bg-primary-600 transition flex items-center justify-center">
                            <i class="fas fa-map-pin mr-2"></i> Near Me
                        </button>
                        <button type="button" id="search-btn" class="btn-primary flex-1 bg-primary-500 text-white py-3 rounded-lg hover:bg-primary-600 transition flex items-center justify-center">
                            <i class="fas fa-search mr-2"></i> Search
                        </button>
                    </div>
                </form>
                <div class="flex justify-between items-center mt-4">
                    <div class="flex items-center text-gray-600 text-sm">
                        <i class="fas fa-map-marker-alt mr-2"></i>
                        <span id="location-display">Detecting your location...</span>
                    </div>
                    <button id="clear-filters" class="btn-secondary text-primary-600 hover:text-primary-700 text-sm underline">
                        Clear Filters
                    </button>
                </div>
            </div>
        </section>

        <!-- Map Section -->
        <section class="mb-8">
            <div id="map-container" class="relative">
                <div id="map" class="rounded-xl"></div>
                <div id="map-error" class="hidden absolute inset-0 bg-red-50 text-red-700 p-4 flex items-center justify-center rounded-xl">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <span>Failed to load map. Please check your internet connection or try again later.</span>
                    <button id="retry-map" class="ml-4 text-primary-600 hover:text-primary-800 transition" aria-label="Retry map">
                        <i class="fas fa-redo-alt"></i> Retry
                    </button>
                </div>
            </div>
        </section>

        <!-- Consultants List -->
        <section>
            <div class="bg-white rounded-2xl p-6 shadow-sm">
                <h2 class="text-2xl font-semibold text-gray-800 mb-6">Available Consultants</h2>
                <div id="consultants-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 custom-scrollbar max-h-[600px] overflow-y-auto">
                    <!-- Consultants will be rendered here -->
                </div>
            </div>
        </section>
    </div>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 py-8">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-6 md:mb-0">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-primary-500 rounded-full flex items-center justify-center text-white mr-3">
                            <i class="fas fa-brain"></i>
                        </div>
                        <span class="text-xl font-bold text-gray-800">CompanionX</span>
                    </div>
                    <p class="text-gray-500 mt-2">Your trusted mental health companion</p>
                </div>
                <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-8">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Resources</h3>
                        <ul class="space-y-2">
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Articles</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Self-Help Guides</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Therapist Directory</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Company</h3>
                        <ul class="space-y-2">
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">About</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Privacy</a></li>
                            <li><a href="#" class="text-gray-600 hover:text-primary-500">Terms</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Connect</h3>
                        <div class="flex space-x-4">
                            <a href="#" class="text-gray-500 hover:text-primary-500" aria-label="Twitter">
                                <i class="fab fa-twitter"></i>
                            </a>
                            <a href="#" class="text-gray-500 hover:text-primary-500" aria-label="Instagram">
                                <i class="fab fa-instagram"></i>
                            </a>
                            <a href="#" class="text-gray-500 hover:text-primary-500" aria-label="Facebook">
                                <i class="fab fa-facebook"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-8 pt-8 border-t border-gray-200 text-center text-gray-500 text-sm">
                © <?php echo date('Y'); ?> CompanionX. All rights reserved. This platform does not provide medical advice.
            </div>
        </div>
    </footer>

    <script>
        const consultants = <?php echo $consultants_json; ?>;
        const csrfToken = <?php echo json_encode($_SESSION['csrf_token'], JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
        let map, markers = [], userMarker;
        const NOMINATIM_URL = 'https://nominatim.openstreetmap.org';
        const USER_AGENT = '<?php echo htmlspecialchars(NOMINATIM_USER_AGENT, ENT_QUOTES, 'UTF-8'); ?>';
        let userLocation = null;
        let lastRequestTime = 0;
        const REQUEST_INTERVAL = 1000; // 1 second rate limit for Nominatim
        // Default map center (configurable via config.php or fallback to a global default)
        const DEFAULT_MAP_CENTER = <?php echo defined('DEFAULT_MAP_CENTER') ? json_encode(DEFAULT_MAP_CENTER) : '[23.8103, 90.4125]'; ?>;
        const DEFAULT_MAP_ZOOM = <?php echo defined('DEFAULT_MAP_ZOOM') ? json_encode(DEFAULT_MAP_ZOOM) : '10'; ?>;

        // Haversine formula for distance calculation
        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371; // Earth's radius in km
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                      Math.sin(dLon / 2) * Math.sin(dLon / 2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            return R * c;
        }

        function initMap() {
            try {
                map = L.map('map').setView(DEFAULT_MAP_CENTER, DEFAULT_MAP_ZOOM);
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
                document.getElementById('map-error').classList.add('hidden');

                // Load last known location from localStorage
                const savedLocation = localStorage.getItem('userLocation');
                if (savedLocation) {
                    userLocation = JSON.parse(savedLocation);
                    map.setView(userLocation, 12);
                    updateUserMarker(userLocation);
                    fetchLocationName(userLocation);
                    document.getElementById('location-display').textContent = 'Using saved location';
                    renderConsultants('', '', '', userLocation);
                    return;
                }

                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            userLocation = [position.coords.latitude, position.coords.longitude];
                            localStorage.setItem('userLocation', JSON.stringify(userLocation));
                            map.setView(userLocation, 12);
                            updateUserMarker(userLocation);
                            fetchLocationName(userLocation);
                            document.getElementById('location-display').textContent = 'Using your current location';
                            renderConsultants('', '', '', userLocation);
                        },
                        (error) => {
                            console.error('Geolocation error:', error);
                            document.getElementById('location-display').textContent = 'Location not detected. Using default location.';
                            renderConsultants();
                        }
                    );
                } else {
                    document.getElementById('location-display').textContent = 'Geolocation not supported. Using default location.';
                    renderConsultants();
                }
            } catch (error) {
                console.error('Map initialization error:', error);
                document.getElementById('map-error').classList.remove('hidden');
            }
        }

        function updateMarkers(filteredConsultants) {
            markers.forEach(marker => map.removeLayer(marker));
            markers = [];

            filteredConsultants.forEach(consultant => {
                if (consultant.coordinates.lat && consultant.coordinates.lng) {
                    try {
                        const marker = L.marker([consultant.coordinates.lat, consultant.coordinates.lng], {
                            icon: L.icon({
                                iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-blue.png',
                                iconSize: [25, 41],
                                iconAnchor: [12, 41],
                                popupAnchor: [1, -34]
                            })
                        }).bindPopup(`
                            <div class="p-2 w-64">
                                <h3 class="font-semibold text-gray-800">${consultant.name}</h3>
                                <p class="text-sm text-gray-600">${consultant.specialization || 'N/A'}</p>
                                <p class="text-sm text-gray-500">${consultant.office_address || 'N/A'}</p>
                                <a href="book_consultant.php?id=${consultant.id}&csrf_token=${encodeURIComponent(csrfToken)}" 
                                   class="mt-2 inline-block bg-primary-500 text-white px-3 py-1 rounded-lg text-sm hover:bg-primary-600">
                                    Book Now
                                </a>
                            </div>
                        `);
                        marker.addTo(map);
                        markers.push(marker);
                    } catch (error) {
                        console.error('Marker creation error for', consultant.name, ':', error);
                    }
                }
            });
        }

        function updateUserMarker(position) {
            try {
                if (userMarker) map.removeLayer(userMarker);
                userMarker = L.marker(position, {
                    icon: L.icon({
                        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-red.png',
                        iconSize: [25, 41],
                        iconAnchor: [12, 41],
                        popupAnchor: [1, -34]
                    })
                }).bindPopup('Your Location').addTo(map);
            } catch (error) {
                console.error('User marker error:', error);
            }
        }

        function fetchLocationName(pos) {
            try {
                const now = Date.now();
                if (now - lastRequestTime < REQUEST_INTERVAL) {
                    setTimeout(() => fetchLocationName(pos), REQUEST_INTERVAL - (now - lastRequestTime));
                    return;
                }
                lastRequestTime = now;
                fetch(`${NOMINATIM_URL}/reverse?format=json&lat=${pos[0]}&lon=${pos[1]}`, {
                    headers: { 'User-Agent': USER_AGENT }
                })
                    .then(response => response.json())
                    .then(data => {
                        document.getElementById('location-display').textContent = 
                            data.display_name || 'Location not identified';
                    })
                    .catch(error => {
                        console.error('Reverse geocode error:', error);
                        document.getElementById('location-display').textContent = 'Location not identified';
                    });
            } catch (error) {
                console.error('Geocoder error:', error);
                document.getElementById('location-display').textContent = 'Location not identified';
            }
        }

        function renderConsultants(nameQuery = '', specializationQuery = '', areaQuery = '', centerLocation = null) {
            const list = document.getElementById('consultants-list');
            let filtered = consultants;

            if (nameQuery) {
                filtered = filtered.filter(c => c.name.toLowerCase().includes(nameQuery.toLowerCase()));
            }

            if (specializationQuery) {
                filtered = filtered.filter(c => 
                    (c.specialization && c.specialization.toLowerCase().includes(specializationQuery.toLowerCase())) ||
                    (c.tags && c.tags.toLowerCase().includes(specializationQuery.toLowerCase()))
                );
            }

            if (centerLocation || areaQuery) {
                if (centerLocation) {
                    filtered = filtered.filter(c => {
                        if (c.coordinates.lat && c.coordinates.lng) {
                            const distance = calculateDistance(
                                centerLocation[0], centerLocation[1],
                                c.coordinates.lat, c.coordinates.lng
                            );
                            return distance <= 50;
                        }
                        return false;
                    });
                }
                if (areaQuery && filtered.length === 0) {
                    filtered = consultants.filter(c => 
                        c.office_address.toLowerCase().includes(areaQuery.toLowerCase())
                    );
                }
            }

            // Optimize rendering for large lists
            list.innerHTML = '';
            const fragment = document.createDocumentFragment();
            if (filtered.length) {
                filtered.forEach(c => {
                    const div = document.createElement('div');
                    div.className = 'consultant-card bg-white rounded-xl p-6 border border-gray-200 shadow-sm';
                    div.innerHTML = `
                        <div class="flex items-center mb-4">
                            <img src="${c.profile_picture}" alt="${c.name}" class="w-16 h-16 rounded-full mr-4 object-cover border-2 border-primary-100" loading="lazy" />
                            <div>
                                <h3 class="font-semibold text-lg text-gray-800">${c.name}</h3>
                                <p class="text-sm text-gray-600">${c.specialization || 'N/A'} Specialist</p>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500 mb-3">${c.office_address || 'N/A'}</p>
                        <p class="text-sm text-gray-600 mb-3">${c.bio ? c.bio.substring(0, 80) + (c.bio.length > 80 ? '...' : '') : 'No bio available'}</p>
                        <div class="flex justify-between items-center mb-3">
                            <span class="text-sm ${c.is_available ? 'text-green-600' : 'text-red-600'} font-medium">
                                ${c.is_available ? 'Available Now' : 'Unavailable'}
                            </span>
                            <span class="text-sm text-gray-600">$${c.session_charge}/session</span>
                        </div>
                        ${c.tags ? `<p class="text-sm text-gray-500 mb-2">Tags: ${c.tags}</p>` : ''}
                        ${c.available_days ? `<p class="text-sm text-gray-500 mb-2">Days: ${c.available_days}</p>` : ''}
                        ${c.available_times ? `<p class="text-sm text-gray-500 mb-2">Times: ${c.available_times}</p>` : ''}
                        <a href="book_consultant.php?id=${c.id}&csrf_token=${encodeURIComponent(csrfToken)}" 
                           class="block w-full text-center bg-primary-500 text-white py-2 rounded-lg hover:bg-primary-600 transition btn-primary">
                            Book Now
                        </a>
                    `;
                    fragment.appendChild(div);
                });
            } else {
                const p = document.createElement('p');
                p.className = 'text-gray-500 col-span-full text-center py-8';
                p.textContent = 'No consultants found.';
                fragment.appendChild(p);
            }
            list.appendChild(fragment);

            // Update feedback
            const existingFeedback = list.parentNode.querySelector('.feedback');
            if (existingFeedback) existingFeedback.remove();
            const feedback = document.createElement('div');
            feedback.className = 'feedback text-sm text-gray-600 mt-4 text-center';
            feedback.textContent = filtered.length ? `${filtered.length} consultant${filtered.length === 1 ? '' : 's'} found` : 'No consultants found';
            list.parentNode.insertBefore(feedback, list);

            if (map) {
                updateMarkers(filtered);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            try {
                initMap();

                const nameSearch = document.getElementById('name-search');
                const specializationSearch = document.getElementById('specialization-search');
                const areaSearch = document.getElementById('area-search');
                const suggestionsDiv = document.getElementById('suggestions');
                const nearMeBtn = document.getElementById('near-me-btn');
                const searchBtn = document.getElementById('search-btn');
                const clearFiltersBtn = document.getElementById('clear-filters');
                const dismissErrorBtn = document.getElementById('dismiss-error');
                const retryBtn = document.getElementById('retry-btn');
                const retryMapBtn = document.getElementById('retry-map');
                const areaSpinner = areaSearch.parentNode.querySelector('.loading-spinner');

                let debounceTimeout;
                let focusedSuggestionIndex = -1;

                function performAreaSearch(query, callback) {
                    clearTimeout(debounceTimeout);
                    if (query.length < 3) {
                        suggestionsDiv.classList.add('hidden');
                        suggestionsDiv.innerHTML = '';
                        areaSpinner.classList.remove('active');
                        callback(null);
                        return;
                    }
                    areaSpinner.classList.add('active');
                    const now = Date.now();
                    if (now - lastRequestTime < REQUEST_INTERVAL) {
                        debounceTimeout = setTimeout(() => performAreaSearch(query, callback), REQUEST_INTERVAL - (now - lastRequestTime));
                        return;
                    }
                    lastRequestTime = now;
                    fetch(`${NOMINATIM_URL}/search?q=${encodeURIComponent(query)}&format=json&limit=5`, {
                        headers: { 'User-Agent': USER_AGENT }
                    })
                        .then(response => response.json())
                        .then(data => {
                            suggestionsDiv.innerHTML = '';
                            areaSpinner.classList.remove('active');
                            if (data.length === 0) {
                                suggestionsDiv.classList.add('hidden');
                                callback(null);
                                return;
                            }
                            data.forEach((item, index) => {
                                const div = document.createElement('div');
                                div.textContent = item.display_name;
                                div.setAttribute('role', 'option');
                                div.setAttribute('aria-selected', 'false');
                                div.addEventListener('click', () => {
                                    areaSearch.value = item.display_name;
                                    suggestionsDiv.classList.add('hidden');
                                    callback({ lat: parseFloat(item.lat), lon: parseFloat(item.lon), display_name: item.display_name });
                                });
                                suggestionsDiv.appendChild(div);
                            });
                            suggestionsDiv.classList.remove('hidden');
                            focusedSuggestionIndex = -1;
                            if (data[0]) {
                                callback({ lat: parseFloat(data[0].lat), lon: parseFloat(data[0].lon), display_name: data[0].display_name });
                            }
                        })
                        .catch(error => {
                            console.error('Area search error:', error);
                            areaSpinner.classList.remove('active');
                            suggestionsDiv.classList.add('hidden');
                            callback(null);
                        });
                }

                // Keyboard navigation for suggestions
                areaSearch.addEventListener('keydown', (e) => {
                    const suggestions = suggestionsDiv.querySelectorAll('div');
                    if (suggestions.length === 0) return;

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        focusedSuggestionIndex = Math.min(focusedSuggestionIndex + 1, suggestions.length - 1);
                        updateSuggestionFocus(suggestions);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        focusedSuggestionIndex = Math.max(focusedSuggestionIndex - 1, -1);
                        updateSuggestionFocus(suggestions);
                    } else if (e.key === 'Enter' && focusedSuggestionIndex >= 0) {
                        e.preventDefault();
                        suggestions[focusedSuggestionIndex].click();
                    } else if (e.key === 'Escape') {
                        suggestionsDiv.classList.add('hidden');
                        focusedSuggestionIndex = -1;
                    }
                });

                function updateSuggestionFocus(suggestions) {
                    suggestions.forEach((s, i) => {
                        s.classList.toggle('focused', i === focusedSuggestionIndex);
                        s.setAttribute('aria-selected', i === focusedSuggestionIndex ? 'true' : 'false');
                    });
                    if (focusedSuggestionIndex >= 0) {
                        suggestions[focusedSuggestionIndex].scrollIntoView({ block: 'nearest' });
                    }
                }

                areaSearch.addEventListener('input', () => {
                    debounceTimeout = setTimeout(() => {
                        performAreaSearch(areaSearch.value.trim(), () => {});
                    }, 300);
                });

                document.addEventListener('click', (e) => {
                    if (!areaSearch.contains(e.target) && !suggestionsDiv.contains(e.target)) {
                        suggestionsDiv.classList.add('hidden');
                        focusedSuggestionIndex = -1;
                    }
                });

                nearMeBtn.addEventListener('click', () => {
                    if (userLocation) {
                        map.setView(userLocation, 12);
                        updateUserMarker(userLocation);
                        renderConsultants('', '', '', userLocation);
                        document.getElementById('location-display').textContent = 'Showing consultants near your location';
                    } else {
                        const alert = document.createElement('div');
                        alert.className = 'error-alert bg-red-50 text-red-700 p-4 rounded-xl mb-6 flex items-center justify-between max-w-2xl mx-auto';
                        alert.innerHTML = `
                            <span>Location access denied. Please allow location access or enter an area manually.</span>
                            <button class="dismiss-alert text-red-600 hover:text-red-800 transition" aria-label="Dismiss alert">
                                <i class="fas fa-times"></i>
                            </button>
                        `;
                        document.getElementById('search-form').parentNode.insertBefore(alert, document.getElementById('search-form'));
                        alert.querySelector('.dismiss-alert').addEventListener('click', () => alert.remove());
                        setTimeout(() => alert.remove(), 5000);
                    }
                });

                searchBtn.addEventListener('click', () => {
                    const nameQuery = nameSearch.value.trim();
                    const specializationQuery = specializationSearch.value.trim();
                    const areaQuery = areaSearch.value.trim();

                    searchBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Searching...';
                    searchBtn.disabled = true;

                    if (!nameQuery && !specializationQuery && !areaQuery) {
                        renderConsultants();
                        map.setView(DEFAULT_MAP_CENTER, DEFAULT_MAP_ZOOM);
                        document.getElementById('location-display').textContent = 'Showing all consultants';
                        searchBtn.innerHTML = '<i class="fas fa-search mr-2"></i> Search';
                        searchBtn.disabled = false;
                        return;
                    }

                    if (areaQuery) {
                        performAreaSearch(areaQuery, (location) => {
                            if (location) {
                                map.setView([location.lat, location.lon], 12);
                                updateUserMarker([location.lat, location.lon]);
                                document.getElementById('location-display').textContent = location.display_name;
                                renderConsultants(nameQuery, specializationQuery, areaQuery, [location.lat, location.lon]);
                            } else {
                                const alert = document.createElement('div');
                                alert.className = 'error-alert bg-red-50 text-red-700 p-4 rounded-xl mb-6 flex items-center justify-between max-w-2xl mx-auto';
                                alert.innerHTML = `
                                    <span>Invalid area. Please select a valid location.</span>
                                    <button class="dismiss-alert text-red-600 hover:text-red-800 transition" aria-label="Dismiss alert">
                                        <i class="fas fa-times"></i>
                                    </button>
                                `;
                                document.getElementById('search-form').parentNode.insertBefore(alert, document.getElementById('search-form'));
                                alert.querySelector('.dismiss-alert').addEventListener('click', () => alert.remove());
                                setTimeout(() => alert.remove(), 5000);
                                renderConsultants(nameQuery, specializationQuery);
                            }
                            searchBtn.innerHTML = '<i class="fas fa-search mr-2"></i> Search';
                            searchBtn.disabled = false;
                        });
                    } else if (userLocation) {
                        map.setView(userLocation, 12);
                        updateUserMarker(userLocation);
                        renderConsultants(nameQuery, specializationQuery, '', userLocation);
                        document.getElementById('location-display').textContent = 'Searching from your location';
                        searchBtn.innerHTML = '<i class="fas fa-search mr-2"></i> Search';
                        searchBtn.disabled = false;
                    } else {
                        renderConsultants(nameQuery, specializationQuery);
                        map.setView(DEFAULT_MAP_CENTER, DEFAULT_MAP_ZOOM);
                        document.getElementById('location-display').textContent = 'Searching all consultants';
                        searchBtn.innerHTML = '<i class="fas fa-search mr-2"></i> Search';
                        searchBtn.disabled = false;
                    }
                });

                // Keyboard shortcut for search
                [nameSearch, specializationSearch, areaSearch].forEach(input => {
                    input.addEventListener('keypress', (e) => {
                        if (e.key === 'Enter') searchBtn.click();
                    });
                });

                // Clear filters
                clearFiltersBtn.addEventListener('click', () => {
                    nameSearch.value = '';
                    specializationSearch.value = '';
                    areaSearch.value = '';
                    suggestionsDiv.classList.add('hidden');
                    focusedSuggestionIndex = -1;
                    renderConsultants();
                    map.setView(DEFAULT_MAP_CENTER, DEFAULT_MAP_ZOOM);
                    document.getElementById('location-display').textContent = 'Showing all consultants';
                });

                // Dismiss initial error
                if (dismissErrorBtn) {
                    dismissErrorBtn.addEventListener('click', () => {
                        document.getElementById('error-alert').remove();
                    });
                }

                // Retry button for database errors
                if (retryBtn) {
                    retryBtn.addEventListener('click', () => {
                        window.location.reload();
                    });
                }

                // Retry button for map errors
                if (retryMapBtn) {
                    retryMapBtn.addEventListener('click', () => {
                        document.getElementById('map-error').classList.add('hidden');
                        initMap();
                    });
                }
            } catch (error) {
                console.error('Initial render error:', error);
                document.getElementById('consultants-list').innerHTML = 
                    '<p class="text-red-600 col-span-full text-center py-8">Error loading consultants. Please try again or contact support.</p>';
                document.getElementById('map-error').classList.remove('hidden');
            }
        });

        window.addEventListener('error', (event) => {
            console.error('JavaScript error:', event);
            if (event.message.includes('Leaflet') || event.message.includes('map')) {
                document.getElementById('map-error').classList.remove('hidden');
            }
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
?>