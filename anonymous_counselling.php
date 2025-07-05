<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

try {
    require 'config/db.php';
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Fetch user data for profile picture
$stmt = $pdo->prepare("SELECT avatar, first_name, last_name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$avatar = isset($user['avatar']) ? htmlspecialchars($user['avatar']) : 'default';
$firstName = htmlspecialchars($user['first_name']);
$currency = htmlspecialchars($user['currency'] ?? 'BDT');

// Fetch all counselors for search/filter functionality
try {
    $stmt = $pdo->query("SELECT id, first_name, last_name, specialization, is_available, bio, session_charge, profile_picture FROM consultants WHERE status = 'active'");
    $counselors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("DB Query failed: " . $e->getMessage());
}

// Fetch AI-recommended counselors
$userId = $_SESSION['user_id'];
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.first_name, c.last_name, c.specialization, c.is_available, c.bio, c.session_charge, c.profile_picture, acr.recommendation_score
        FROM ai_consultant_recommendations acr
        JOIN consultants c ON acr.consultant_id = c.id
        WHERE acr.user_id = ?
        ORDER BY acr.recommendation_score DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recommended = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("DB Query failed for recommendations: " . $e->getMessage());
}

function getAvatarUrl($avatarCode) {
    $avatars = [
        'f1' => 'https://i.ibb.co/mhvBfNw/anime-girl-1.png',
        'f2' => 'https://i.ibb.co/ynzwDtc/anime-girl-2.png',
        'f3' => 'https://i.ibb.co/SdznH0H/anime-girl-3.png',
        'f4' => 'https://i.ibb.co/2h8ZV0R/anime-girl-4.png',
        'f5' => 'https://i.ibb.co/hygY2pv/anime-girl-5.png',
        'm1' => 'https://i.ibb.co/kDKtKqc/anime-boy-1.png',
        'm2' => 'https://i.ibb.co/tLQQ7H2/anime-boy-2.png',
        'm3' => 'https://i.ibb.co/JpRpK4Z/anime-boy-3.png',
        'm4' => 'https://i.ibb.co/m6gWJvq/anime-boy-4.png',
        'm5' => 'https://i.ibb.co/hYSPHhP/anime-boy-5.png',
        'n1' => 'https://i.ibb.co/j8DLjps/cat-avatar.png',
        'n2' => 'https://i.ibb.co/BCJttDL/fox-avatar.png',
        'default' => 'https://i.ibb.co/zxvWn2b/default-avatar.png'
    ];
    return $avatars[$avatarCode] ?? $avatars['default'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anonymous Counselling - CompanionX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 50%, #80deea 100%);
            position: relative;
            overflow-x: hidden;
        }

        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .modal-overlay {
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
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

        .notification-item {
            padding: 1rem;
            border-bottom: 1px solid #f1f1f1;
        }

        .notification-item:hover {
            background-color: #f9fafb;
        }

        .search-btn {
            transition: all 0.3s ease;
        }

        .search-btn:hover {
            transform: scale(1.05);
        }

        .counselor-card {
            transition: all 0.3s ease;
        }

        .counselor-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        #notificationPopup {
            transition: opacity 0.3s ease;
        }

        #notificationPopup.hidden {
            opacity: 0;
            pointer-events: none;
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

        function getAvatarUrl(avatarCode) {
            const avatars = {
                'f1': 'https://i.ibb.co/mhvBfNw/anime-girl-1.png',
                'f2': 'https://i.ibb.co/ynzwDtc/anime-girl-2.png',
                'f3': 'https://i.ibb.co/SdznH0H/anime-girl-3.png',
                'f4': 'https://i.ibb.co/2h8ZV0R/anime-girl-4.png',
                'f5': 'https://i.ibb.co/hygY2pv/anime-girl-5.png',
                'm1': 'https://i.ibb.co/kDKtKqc/anime-boy-1.png',
                'm2': 'https://i.ibb.co/tLQQ7H2/anime-boy-2.png',
                'm3': 'https://i.ibb.co/JpRpK4Z/anime-boy-3.png',
                'm4': 'https://i.ibb.co/m6gWJvq/anime-boy-4.png',
                'm5': 'https://i.ibb.co/hYSPHhP/anime-boy-5.png',
                'n1': 'https://i.ibb.co/j8DLjps/cat-avatar.png',
                'n2': 'https://i.ibb.co/BCJttDL/fox-avatar.png',
                'default': 'https://i.ibb.co/zxvWn2b/default-avatar.png'
            };
            return avatars[avatarCode] || avatars['default'];
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Create floating petals
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
            createPetals();

            // Notification Popup
            const notificationButton = document.querySelector('.fa-bell').parentElement;
            const notificationPopup = document.getElementById('notificationPopup');
            const clearNotificationsButton = document.getElementById('clearNotifications');

            notificationButton.addEventListener('click', () => {
                notificationPopup.classList.toggle('hidden');
                if (!notificationPopup.classList.contains('hidden')) {
                    fetchNotifications();
                }
            });

            document.addEventListener('click', (e) => {
                if (!notificationPopup.contains(e.target) && !notificationButton.contains(e.target)) {
                    notificationPopup.classList.add('hidden');
                }
            });

            clearNotificationsButton.addEventListener('click', () => {
                fetch('clear_notifications.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?>'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('notificationList').innerHTML = '<p class="p-4 text-gray-500">No notifications</p>';
                    }
                });
            });

            function fetchNotifications() {
                fetch('get_notifications.php')
                    .then(response => response.json())
                    .then(data => {
                        const notificationList = document.getElementById('notificationList');
                        if (!data.success || data.notifications.length === 0) {
                            notificationList.innerHTML = '<p class="p-4 text-gray-500">No notifications</p>';
                            return;
                        }
                        notificationList.innerHTML = data.notifications.map(n => `
                            <div class="notification-item">
                                <p class="text-sm text-gray-600">${n.message}</p>
                                <p class="text-xs text-gray-400">${n.timestamp}</p>
                            </div>
                        `).join('');
                    });
            }

            // Counselor filtering
            const counselors = <?php echo json_encode($counselors); ?>;

            function filterCounselors(name, expertise, availability) {
                name = name.toLowerCase();
                const now = new Date();
                return counselors.filter(c => {
                    const fullName = (c.first_name + ' ' + c.last_name).toLowerCase();
                    const specialization = c.specialization.toLowerCase();
                    const isAvailable = c.is_available == 1;
                    const nextAvailable = new Date(c.next_available);

                    let matchesName = fullName.includes(name);
                    let matchesExpertise = expertise === '' || specialization.includes(expertise);
                    let matchesAvailability = true;
                    if (availability === 'now') {
                        matchesAvailability = isAvailable;
                    } else if (availability === 'today') {
                        matchesAvailability = nextAvailable.toDateString() === now.toDateString();
                    } else if (availability === 'week') {
                        const oneWeekLater = new Date(now);
                        oneWeekLater.setDate(now.getDate() + 7);
                        matchesAvailability = nextAvailable <= oneWeekLater;
                    }

                    return matchesName && matchesExpertise && matchesAvailability;
                });
            }

            function renderCounselors(list) {
                const container = document.getElementById('counselor-results');
                if (list.length === 0) {
                    container.innerHTML = '<p class="text-gray-500 text-center py-8">No counselors found.</p>';
                    return;
                }

                container.innerHTML = `
                    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                        ${list.map(c => `
                        <div class="counselor-card bg-white rounded-xl p-6 border border-gray-200 shadow-sm">
                            <div class="flex items-center mb-4">
                                <img src="${getAvatarUrl(c.profile_picture || 'default')}" alt="${c.first_name} ${c.last_name}" class="w-16 h-16 rounded-full mr-4 object-cover" />
                                <div>
                                    <h3 class="font-semibold text-lg text-gray-800">${c.first_name} ${c.last_name}</h3>
                                    <p class="text-gray-600 text-sm">${c.specialization.charAt(0).toUpperCase() + c.specialization.slice(1)} Specialist</p>
                                </div>
                            </div>
                            <p class="text-gray-600 text-sm mb-3">${c.bio ? c.bio.substring(0, 80) + (c.bio.length > 80 ? '...' : '') : 'No bio available'}</p>
                            <div class="flex justify-between items-center text-sm mb-3">
                                <span class="text-primary-600 font-medium">${c.is_available ? 'Available Now' : 'Unavailable'}</span>
                                
                            </div>
                            <button class="book-btn w-full bg-primary-500 text-white py-2 rounded-lg hover:bg-primary-600 transition" data-id="${c.id}">
                                Book Session
                            </button>
                        </div>
                        `).join('')}
                    </div>
                `;
            }

            document.getElementById('search-btn').addEventListener('click', () => {
                const name = document.getElementById('search-name').value.trim();
                const expertise = document.getElementById('expertise-filter').value;
                const availability = document.getElementById('availability-filter').value;

                const filtered = filterCounselors(name, expertise, availability);
                renderCounselors(filtered);
            });

            // Modal open/close & booking logic
            const modal = document.getElementById('booking-modal');
            const closeModalBtn = document.getElementById('close-modal');
            const bookingForm = document.getElementById('booking-form');
            const bookingMessage = document.getElementById('booking-message');

            document.body.addEventListener('click', e => {
                if (e.target.classList.contains('book-btn')) {
                    const consultantId = e.target.getAttribute('data-id');
                    document.getElementById('consultant-id').value = consultantId;
                    bookingMessage.textContent = '';
                    bookingForm.reset();
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                }
            });

            closeModalBtn.addEventListener('click', () => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }
            });

            bookingForm.addEventListener('submit', async e => {
                e.preventDefault();
                bookingMessage.textContent = 'Booking session...';

                const preferredDate = new Date(document.getElementById('preferred_date').value);
                const now = new Date();
                if (preferredDate < now) {
                    bookingMessage.classList.add('text-red-600');
                    bookingMessage.textContent = 'Please select a future date.';
                    return;
                }

                const formData = new FormData(bookingForm);
                formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');

                try {
                    const response = await fetch('bookSession.php', {
                        method: 'POST',
                        body: formData,
                    });

                    const result = await response.json();

                    if (result.success) {
                        bookingMessage.classList.remove('text-red-600');
                        bookingMessage.classList.add('text-green-600');
                        bookingMessage.textContent = 'Booking successful! We will notify you soon.';
                        setTimeout(() => {
                            modal.classList.add('hidden');
                            modal.classList.remove('flex');
                        }, 2000);
                    } else {
                        bookingMessage.classList.remove('text-green-600');
                        bookingMessage.classList.add('text-red-600');
                        bookingMessage.textContent = result.message || 'Booking failed. Try again.';
                    }
                } catch (error) {
                    bookingMessage.classList.remove('text-green-600');
                    bookingMessage.classList.add('text-red-600');
                    bookingMessage.textContent = 'Network error. Please try again.';
                }
            });
        });
    </script>
</head>
<body class="min-h-screen">
    <!-- Topbar -->
    <header class="bg-white shadow-sm fixed top-0 left-0 right-0 z-50">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-primary-500 rounded-full flex items-center justify-center text-white mr-3">
                    <i class="fas fa-brain text-2xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-gray-800">CompanionX</h1>
            </div>
            <div class="flex items-center space-x-4">
                <a href="dashboard.php" class="px-4 py-2 bg-primary-50 text-primary-600 rounded-lg hover:bg-primary-100 transition">
                    <i class="fas fa-home mr-2"></i> Dashboard
                </a>
                <button class="p-2 text-gray-600 hover:text-primary-500 relative">
                    <i class="fas fa-bell text-xl"></i>
                </button>
                <div class="flex items-center space-x-2">
                    <div class="text-sm text-gray-600"><?php echo $firstName; ?></div>
                    <div class="w-10 h-10 rounded-full overflow-hidden border-2 border-primary-200">
                        <img src="<?= htmlspecialchars(getAvatarUrl($avatar)) ?>" alt="User Avatar" class="w-full h-full object-cover">
                    </div>
                </div>
                <a href="logout.php" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition">Logout</a>
            </div>
        </div>
    </header>

    <!-- Notification Popup -->
    <div id="notificationPopup" class="hidden absolute right-4 top-16 sm:right-8 sm:top-20 bg-white rounded-lg shadow-lg w-80 max-w-[90vw] z-50">
        <div class="p-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-800">Notifications</h3>
        </div>
        <div id="notificationList" class="max-h-64 overflow-y-auto">
            <!-- Notifications will be populated here -->
        </div>
        <div class="p-4 border-t border-gray-200">
            <button id="clearNotifications" class="text-primary-500 hover:text-primary-600 text-sm">Clear All</button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mx-auto px-4 py-24">
        <!-- Search and Filter Section -->
        <section class="mb-10">
            <div class="bg-white rounded-2xl p-6 shadow-sm">
                <h2 class="text-xl sm:text-2xl font-semibold text-gray-800 mb-6">Find Your Counselor</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Search by Name</label>
                        <input type="text" id="search-name" placeholder="Enter counselor name..." class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Area of Expertise</label>
                        <select id="expertise-filter" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition">
                            <option value="">All Specializations</option>
                            <option value="stress">Stress Management</option>
                            <option value="anxiety">Anxiety</option>
                            <option value="depression">Depression</option>
                            <option value="relationships">Relationships</option>
                            <option value="trauma">Trauma</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Availability</label>
                        <select id="availability-filter" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition">
                            <option value="">All Availability</option>
                            <option value="now">Available Now</option>
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                        </select>
                    </div>
                </div>
                <button id="search-btn" class="search-btn px-6 py-3 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition">
                    <i class="fas fa-search mr-2"></i> Search Counselors
                </button>
            </div>
        </section>

        <!-- Recommended Counselors -->
        <section class="mb-10">
            <div class="bg-white rounded-2xl p-6 shadow-sm">
                <h2 class="text-xl sm:text-2xl font-semibold text-gray-800 mb-6">Recommended for You</h2>
                <div id="top-counselors" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6">
                    <?php if (count($recommended) === 0): ?>
                        <p class="text-gray-500 col-span-5 text-center py-8">No recommendations available. Please complete the onboarding questionnaire.</p>
                    <?php else: ?>
                        <?php foreach ($recommended as $c): ?>
                            <div class="counselor-card bg-white rounded-xl p-6 border border-gray-200 shadow-sm text-center">
                                <img src="<?= htmlspecialchars(getAvatarUrl($c['profile_picture'] ?? 'default')) ?>" alt="<?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?>" class="w-20 h-20 rounded-full mx-auto mb-4 object-cover" />
                                <h3 class="font-semibold text-lg text-gray-800"><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></h3>
                                <p class="text-gray-600 text-sm mb-2"><?= htmlspecialchars(ucfirst($c['specialization'])) ?> Specialist</p>
                                <p class="text-gray-600 text-sm mb-2"><?= htmlspecialchars(substr($c['bio'], 0, 50) . (strlen($c['bio']) > 50 ? '...' : '')) ?></p>
                                <p class="text-gray-600 text-sm mb-2">Fee: <?= htmlspecialchars($c['session_charge']) ?> <?= $currency ?></p>
                                <p class="text-gray-600 text-sm mb-2">Match: <?= number_format($c['recommendation_score'] * 100, 1) ?>%</p>
                                <div class="flex justify-center mb-2">
                                    <i class="fas fa-star text-yellow-400"></i>
                                   
                                </div>
                                <button class="book-btn bg-primary-500 text-white px-4 py-2 rounded-lg hover:bg-primary-600 transition" data-id="<?= $c['id'] ?>">
                                    Book Now
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Available Counselors Results -->
        <section class="mb-10">
            <div class="bg-white rounded-2xl p-6 shadow-sm">
                <h2 class="text-xl sm:text-2xl font-semibold text-gray-800 mb-6">Available Counselors</h2>
                <div id="counselor-results" class="min-h-[200px]">
                    <p class="text-gray-500 text-center py-8">Use the search above to find counselors</p>
                </div>
            </div>
        </section>

        <!-- Booking Modal -->
        <div id="booking-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden justify-center items-center z-50 modal-overlay" role="dialog" aria-labelledby="booking-modal-title" aria-modal="true">
            <div class="bg-white rounded-2xl p-8 w-full max-w-md mx-4 relative">
                <button id="close-modal" class="absolute top-4 right-4 text-gray-600 hover:text-gray-900 text-xl font-bold" aria-label="Close modal">&times;</button>
                <h3 id="booking-modal-title" class="text-xl font-semibold text-gray-800 mb-6">Book Counseling Session</h3>
                <form id="booking-form" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" id="consultant-id" name="consultant_id" />
                    <div>
                        <label for="preferred_date" class="block text-sm font-medium text-gray-700 mb-2">Preferred Date</label>
                        <input type="date" id="preferred_date" name="preferred_date" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition" />
                    </div>
                    <div>
                        <label for="preferred_time" class="block text-sm font-medium text-gray-700 mb-2">Preferred Time</label>
                        <input type="time" id="preferred_time" name="preferred_time" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition" />
                    </div>
                    <div class="text-right">
                        <button type="submit" class="px-6 py-3 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition">Book Session</button>
                    </div>
                </form>
                <div id="booking-message" class="mt-4 text-sm font-medium"></div>
            </div>
        </div>
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
                            <a href="#" class="text-gray-500 hover:text-primary-500">
                                <i class="fab fa-twitter"></i>
                            </a>
                            <a href="#" class="text-gray-500 hover:text-primary-500">
                                <i class="fab fa-instagram"></i>
                            </a>
                            <a href="#" class="text-gray-500 hover:text-primary-500">
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
</body>
</html>