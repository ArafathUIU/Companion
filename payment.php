```php
<?php
session_start();

// Check session validity
if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    error_log("Session error: user_id not set or invalid");
    header("Location: login.php");
    exit;
}

require_once 'config/db.php';
ini_set('log_errors', 1);
ini_set('error_log', 'logs/php_errors.log');
error_reporting(E_ALL);

// Verify database connection
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->query("SELECT 1");
} catch (Exception $e) {
    error_log("DB connection error: " . $e->getMessage());
    $error = "Database connection failed. Please try again later.";
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check for confirmed booking
$booking = null;
$error = null;
$success = null;
if (!$error) {
    try {
        // Verify table existence
        $tables = $pdo->query("SHOW TABLES LIKE 'anonymous_counselling_bookings'")->fetchAll();
        if (empty($tables)) {
            error_log("Table anonymous_counselling_bookings does not exist");
            $error = "System error: Booking table not found.";
        } else {
            $stmt = $pdo->prepare("
                SELECT id, preferred_date, preferred_time, status, consultant_id
                FROM anonymous_counselling_bookings
                WHERE user_id = ? AND status = 'confirmed'
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$booking) {
                error_log("No confirmed booking found for user_id: " . $_SESSION['user_id']);
                $error = "No confirmed session found. Please schedule and get confirmation from a consultant.";
            }
        }
    } catch (PDOException $e) {
        error_log("Booking fetch error: " . $e->getMessage() . " | user_id: " . $_SESSION['user_id']);
        $error = "Failed to verify session: " . $e->getMessage();
    }
}

// Check for cancellations and handle refunds
if ($booking && !$error) {
    try {
        $stmt = $pdo->prepare("SELECT id, amount, status FROM payments WHERE booking_id = ? AND user_id = ?");
        $stmt->execute([$booking['id'], $_SESSION['user_id']]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($payment && $booking['status'] === 'cancelled_by_consultant' && $payment['status'] === 'completed') {
            $stmt = $pdo->prepare("UPDATE payments SET status = 'refunded', amount = 137.50, payment_date = NOW(), payment_details = JSON_SET(payment_details, '$.refund_status', 'full_refunded') WHERE id = ?");
            $stmt->execute([$payment['id']]);
            $success = "Session cancelled by consultant. Full refund processed ($137.50).";
        } elseif ($payment && $booking['status'] === 'cancelled_by_user' && $payment['status'] === 'completed') {
            $stmt = $pdo->prepare("UPDATE payments SET status = 'partially_refunded', amount = 68.75, payment_date = NOW(), payment_details = JSON_SET(payment_details, '$.refund_status', 'partial_refunded') WHERE id = ?");
            $stmt->execute([$payment['id']]);
            $success = "Session cancelled by you. 50% refund processed ($68.75).";
        }
    } catch (PDOException $e) {
        error_log("Refund check error: " . $e->getMessage() . " | booking_id: " . $booking['id']);
        $error = "Failed to process refund status: " . $e->getMessage();
    }
}

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment']) && !$error && $booking) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        error_log("CSRF validation failed for user_id: " . $_SESSION['user_id']);
        $error = "Invalid submission.";
    } else {
        $payment_method = $_POST['payment_method'] ?? '';
        $amount = 137.50;
        $transaction_id = null;
        $payment_details = [];

        if (!in_array($payment_method, ['bkash', 'card', 'bank', 'paypal'])) {
            error_log("Invalid payment method: " . $payment_method . " | user_id: " . $_SESSION['user_id']);
            $error = "Invalid payment method.";
        } else {
            if ($payment_method === 'bkash') {
                $bkash_number = trim($_POST['bkash_number'] ?? '');
                $bkash_pin = trim($_POST['bkash_pin'] ?? '');
                if (!preg_match('/^01[0-9]{9}$/', $bkash_number)) {
                    $error = "Invalid bKash number.";
                } elseif (empty($bkash_pin)) {
                    $error = "bKash PIN required.";
                } else {
                    $transaction_id = 'BKASH_' . bin2hex(random_bytes(8));
                    $payment_details = ['bkash_number' => substr($bkash_number, -4)];
                }
            } elseif ($payment_method === 'card') {
                $card_number = trim($_POST['card_number'] ?? '');
                $expiry_date = trim($_POST['expiry_date'] ?? '');
                $cvv = trim($_POST['cvv'] ?? '');
                $cardholder_name = trim($_POST['cardholder_name'] ?? '');
                if (!preg_match('/^[0-9]{16}$/', str_replace(' ', '', $card_number))) {
                    $error = "Invalid card number.";
                } elseif (!preg_match('/^(0[1-9]|1[0-2])\/[0-9]{2}$/', $expiry_date)) {
                    $error = "Invalid expiry date (MM/YY).";
                } elseif (!preg_match('/^[0-9]{3}$/', $cvv)) {
                    $error = "Invalid CVV.";
                } elseif (empty($cardholder_name)) {
                    $error = "Cardholder name required.";
                } else {
                    $transaction_id = 'CARD_' . bin2hex(random_bytes(8));
                    $payment_details = ['card_last4' => substr($card_number, -4), 'cardholder_name' => $cardholder_name];
                }
            } elseif ($payment_method === 'bank') {
                $transaction_id = trim($_POST['transaction_id'] ?? '');
                if (empty($transaction_id)) {
                    $error = "Transaction ID required.";
                } else {
                    $payment_details = ['bank_transaction_id' => $transaction_id];
                }
            } elseif ($payment_method === 'paypal') {
                $transaction_id = 'PAYPAL_' . bin2hex(random_bytes(8));
                $payment_details = ['paypal_transaction_id' => $transaction_id];
            }

            if (!$error) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO payments (booking_id, user_id, amount, payment_date, payment_method, transaction_id, status, consultant_id, payment_details, created_at)
                        VALUES (?, ?, ?, NOW(), ?, ?, 'completed', ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $booking['id'],
                        $_SESSION['user_id'],
                        $amount,
                        $payment_method,
                        $transaction_id,
                        $booking['consultant_id'],
                        json_encode($payment_details)
                    ]);
                    $success = "Payment successful! Redirecting to confirmation...";
                    header("Refresh: 2; url=confirmation.php");
                } catch (PDOException $e) {
                    error_log("Payment error: " . $e->getMessage() . " | user_id: " . $_SESSION['user_id'] . " | booking_id: " . $booking['id']);
                    $error = "Failed to process payment: " . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CompanionX - Subscription Plans</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 50%, #80deea 100%);
            position: relative;
            overflow-x: hidden;
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
            0% { transform: translateY(0) rotate(0deg); opacity: 0.5; }
            50% { opacity: 0.8; }
            100% { transform: translateY(100vh) rotate(360deg); opacity: 0.2; }
        }
        .plan-card {
            transition: all 0.3s ease;
            transform-style: preserve-3d;
        }
        .plan-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 10px 25px -5px rgba(0, 188, 212, 0.2);
        }
        .plan-card.popular {
            border: 2px solid #00bcd4;
        }
        .btn-primary {
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .btn-primary:hover {
            transform: scale(1.05);
            background: #26c6da;
        }
        .feature-icon {
            width: 24px;
            height: 24px;
            background-color: #e0f7fa;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 8px;
        }
        .custom-radio:checked + .plan-card {
            border: 2px solid #00bcd4;
            background-color: #f0fdfe;
        }
        .payment-method {
            transition: all 0.2s ease;
        }
        .payment-method:hover {
            transform: scale(1.02);
        }
        .payment-method.selected {
            border-color: #00bcd4;
            background-color: #f0fdfe;
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
            setInterval(createPetals, 5000);
        }
        document.addEventListener('DOMContentLoaded', createPetals);
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
                <a href="anonymous_counselling.php" class="px-4 py-2 text-gray-600 hover:text-primary-500 transition rounded-lg hover:bg-primary-50">
                    <i class="fas fa-user-md mr-2"></i> Counselling
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
        <div class="max-w-4xl mx-auto text-center mb-12">
            <h2 class="text-3xl font-bold text-gray-800 mb-4">Choose Your Subscription Plan</h2>
            <p class="text-gray-600 text-lg">Select the package that best fits your mental wellness journey</p>
        </div>

        <!-- Subscription Plans -->
        <form id="subscriptionForm" class="space-y-8">
            <div class="grid md:grid-cols-3 gap-6">
                <!-- Basic Plan -->
                <div class="relative">
                    <input type="radio" name="subscription_plan" id="basic-plan" value="basic" class="hidden custom-radio" checked>
                    <label for="basic-plan" class="block h-full">
                        <div class="plan-card bg-white rounded-xl shadow-md p-6 h-full flex flex-col">
                            <div class="mb-4">
                                <span class="inline-block px-3 py-1 text-xs font-semibold bg-primary-100 text-primary-700 rounded-full">Basic</span>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-800 mb-2">৳1,500</h3>
                            <p class="text-gray-500 mb-6">per month</p>
                            <div class="flex-1 space-y-4 mb-6">
                                <div class="flex items-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-check text-primary-500 text-xs"></i>
                                    </div>
                                    <span class="text-gray-700">5 Anonymous Counselling Sessions</span>
                                </div>
                                <div class="flex items-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-check text-primary-500 text-xs"></i>
                                    </div>
                                    <span class="text-gray-700">Daily Mood Tracking</span>
                                </div>
                                <div class="flex items-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-check text-primary-500 text-xs"></i>
                                    </div>
                                    <span class="text-gray-700">Basic Self-Help Resources</span>
                                </div>
                            </div>
                            <button type="button" class="w-full btn-primary bg-primary-500 text-white py-3 rounded-lg hover:bg-primary-600 transition">
                                Select Plan
                            </button>
                        </div>
                    </label>
                </div>

                <!-- Standard Plan (Popular) -->
                <div class="relative">
                    <input type="radio" name="subscription_plan" id="standard-plan" value="standard" class="hidden custom-radio">
                    <label for="standard-plan" class="block h-full">
                        <div class="plan-card bg-white rounded-xl shadow-md p-6 h-full flex flex-col border-2 border-primary-500 relative">
                            <div class="absolute top-0 right-0 bg-primary-500 text-white px-3 py-1 text-xs font-bold rounded-bl-lg rounded-tr-lg">
                                MOST POPULAR
                            </div>
                            <div class="mb-4">
                                <span class="inline-block px-3 py-1 text-xs font-semibold bg-primary-100 text-primary-700 rounded-full">Standard</span>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-800 mb-2">৳2,500</h3>
                            <p class="text-gray-500 mb-6">per month</p>
                            <div class="flex-1 space-y-4 mb-6">
                                <div class="flex items-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-check text-primary-500 text-xs"></i>
                                    </div>
                                    <span class="text-gray-700">10 Anonymous Counselling Sessions</span>
                                </div>
                                <div class="flex items-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-check text-primary-500 text-xs"></i>
                                    </div>
                                    <span class="text-gray-700">Daily Mood Tracking + Analytics</span>
                                </div>
                                <div class="flex items-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-check text-primary-500 text-xs"></i>
                                    </div>
                                    <span class="text-gray-700">Premium Self-Help Resources</span>
                                </div>
                                <div class="flex items-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-check text-primary-500 text-xs"></i>
                                    </div>
                                    <span class="text-gray-700">Weekly Progress Reports</span>
                                </div>
                            </div>
                            <button type="button" class="w-full btn-primary bg-primary-500 text-white py-3 rounded-lg hover:bg-primary-600 transition">
                                Select Plan
                            </button>
                        </div>
                    </label>
                </div>

                <!-- Premium Plan -->
                <div class="relative">
                    <input type="radio" name="subscription_plan" id="premium-plan" value="premium" class="hidden custom-radio">
                    <label for="premium-plan" class="block h-full">
                        <div class="plan-card bg-white rounded-xl shadow-md p-6 h-full flex flex-col">
                            <div class="mb-4">
                                <span class="inline-block px-3 py-1 text-xs font-semibold bg-primary-100 text-primary-700 rounded-full">Premium</span>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-800 mb-2">৳4,000</h3>
                            <p class="text-gray-500 mb-6">per month</p>
                            <div class="flex-1 space-y-4 mb-6">
                                <div class="flex items-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-check text-primary-500 text-xs"></i>
                                    </div>
                                    <span class="text-gray-700">Unlimited Counselling Sessions</span>
                                </div>
                                <div class="flex items-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-check text-primary-500 text-xs"></i>
                                    </div>
                                    <span class="text-gray-700">Advanced Mood Analytics</span>
                                </div>
                                <div class="flex items-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-check text-primary-500 text-xs"></i>
                                    </div>
                                    <span class="text-gray-700">Personalized Therapy Plans</span>
                                </div>
                                <div class="flex items-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-check text-primary-500 text-xs"></i>
                                    </div>
                                    <span class="text-gray-700">24/7 Crisis Support</span>
                                </div>
                                <div class="flex items-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-check text-primary-500 text-xs"></i>
                                    </div>
                                    <span class="text-gray-700">Monthly Therapist Check-in</span>
                                </div>
                            </div>
                            <button type="button" class="w-full btn-primary bg-primary-500 text-white py-3 rounded-lg hover:bg-primary-600 transition">
                                Select Plan
                            </button>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Payment Method Selection -->
            <div class="bg-white rounded-xl shadow-md p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-6">Payment Method</h3>
                <div class="grid md:grid-cols-3 gap-4">
                    <!-- bKash -->
                    <div class="payment-method bg-white border border-gray-200 rounded-lg p-4 cursor-pointer" onclick="selectPaymentMethod('bkash')">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center mr-4">
                                <i class="fas fa-mobile-alt text-purple-600 text-xl"></i>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-800">bKash</h4>
                                <p class="text-gray-500 text-sm">Mobile Payment</p>
                            </div>
                        </div>
                    </div>

                    <!-- Credit Card -->
                    <div class="payment-method bg-white border border-gray-200 rounded-lg p-4 cursor-pointer" onclick="selectPaymentMethod('credit_card')">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mr-4">
                                <i class="far fa-credit-card text-blue-600 text-xl"></i>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-800">Credit/Debit Card</h4>
                                <p class="text-gray-500 text-sm">Visa, Mastercard</p>
                            </div>
                        </div>
                    </div>

                    <!-- Bank Transfer -->
                    <div class="payment-method bg-white border border-gray-200 rounded-lg p-4 cursor-pointer" onclick="selectPaymentMethod('bank_transfer')">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center mr-4">
                                <i class="fas fa-university text-green-600 text-xl"></i>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-800">Bank Transfer</h4>
                                <p class="text-gray-500 text-sm">Direct Deposit</p>
                            </div>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="payment_method" id="payment_method" value="">
            </div>

            <!-- Payment Details -->
            <div id="payment-details" class="bg-white rounded-xl shadow-md p-6 hidden">
                <h3 class="text-xl font-semibold text-gray-800 mb-6">Payment Details</h3>
                
                <!-- bKash Payment Form (initially hidden) -->
                <div id="bkash-form" class="hidden">
                    <div class="mb-4">
                        <label class="block text-gray-700 mb-2">bKash Number</label>
                        <input type="tel" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent" placeholder="01XXXXXXXXX">
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700 mb-2">Transaction ID</label>
                        <input type="text" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent" placeholder="Enter transaction ID">
                    </div>
                    <div class="text-sm text-gray-500">
                        <p>Send money to our bKash merchant number: 01712345678</p>
                        <p>Enter your bKash number and transaction ID above.</p>
                    </div>
                </div>
                
                <!-- Credit Card Payment Form (initially hidden) -->
                <div id="credit-card-form" class="hidden">
                    <div class="mb-4">
                        <label class="block text-gray-700 mb-2">Card Number</label>
                        <input type="text" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent" placeholder="1234 5678 9012 3456">
                    </div>
                    <div class="grid md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <label class="block text-gray-700 mb-2">Expiry Date</label>
                            <input type="text" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent" placeholder="MM/YY">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">CVV</label>
                            <input type="text" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent" placeholder="123">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">Cardholder Name</label>
                            <input type="text" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent" placeholder="Full Name">
                        </div>
                    </div>
                </div>
                
                <!-- Bank Transfer Form (initially hidden) -->
                <div id="bank-transfer-form" class="hidden">
                    <div class="mb-4">
                        <label class="block text-gray-700 mb-2">Bank Name</label>
                        <select class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                            <option>Select Bank</option>
                            <option>Dutch-Bangla Bank</option>
                            <option>BRAC Bank</option>
                            <option>Eastern Bank</option>
                            <option>City Bank</option>
                            <option>Standard Chartered</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700 mb-2">Account Number</label>
                        <input type="text" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent" placeholder="Enter account number">
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700 mb-2">Transaction ID</label>
                        <input type="text" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent" placeholder="Enter transaction ID">
                    </div>
                    <div class="text-sm text-gray-500">
                        <p>Our bank details:</p>
                        <p>Bank: Dutch-Bangla Bank</p>
                        <p>Account Name: CompanionX Ltd.</p>
                        <p>Account Number: 123.456.7890</p>
                        <p>Branch: Gulshan, Dhaka</p>
                    </div>
                </div>
            </div>

            <!-- Terms and Conditions -->
            <div class="bg-white rounded-xl shadow-md p-6">
                <div class="flex items-start">
                    <input type="checkbox" id="terms" name="terms" class="mt-1 mr-3">
                    <label for="terms" class="text-gray-700">
                        I agree to the <a href="#" class="text-primary-500 hover:underline">Terms of Service</a> and <a href="#" class="text-primary-500 hover:underline">Privacy Policy</a>. 
                        I understand that my subscription will automatically renew each month unless canceled.
                    </label>
                </div>
            </div>

            <!-- Payment Button -->
            <div class="text-center">
                <button type="submit" id="pay-button" class="btn-primary inline-flex items-center bg-primary-500 text-white px-8 py-4 rounded-lg hover:bg-primary-600 transition text-lg font-medium">
                    <i class="fas fa-lock mr-3"></i> Complete Subscription Payment
                </button>
            </div>
        </form>
    </div>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 py-8 mt-12">
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
                © 2023 CompanionX. All rights reserved. This platform does not provide medical advice.
            </div>
        </div>
    </footer>

    <script>
        // Select payment method
        function selectPaymentMethod(method) {
            document.querySelectorAll('.payment-method').forEach(el => {
                el.classList.remove('selected');
                el.classList.remove('border-primary-500');
            });
            
            const selectedMethod = document.querySelector(`.payment-method[onclick="selectPaymentMethod('${method}')"]`);
            selectedMethod.classList.add('selected');
            selectedMethod.classList.add('border-primary-500');
            
            document.getElementById('payment_method').value = method;
            
            // Show the appropriate payment form
            document.getElementById('payment-details').classList.remove('hidden');
            document.getElementById('bkash-form').classList.add('hidden');
            document.getElementById('credit-card-form').classList.add('hidden');
            document.getElementById('bank-transfer-form').classList.add('hidden');
            
            if (method === 'bkash') {
                document.getElementById('bkash-form').classList.remove('hidden');
            } else if (method === 'credit_card') {
                document.getElementById('credit-card-form').classList.remove('hidden');
            } else if (method === 'bank_transfer') {
                document.getElementById('bank-transfer-form').classList.remove('hidden');
            }
        }

        // Handle plan selection
        document.querySelectorAll('input[name="subscription_plan"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.plan-card').forEach(card => {
                    card.classList.remove('border-primary-500');
                    card.classList.remove('bg-primary-50');
                });
                
                if (this.checked) {
                    const label = document.querySelector(`label[for="${this.id}"]`);
                    const card = label.querySelector('.plan-card');
                    card.classList.add('border-primary-500');
                    card.classList.add('bg-primary-50');
                }
            });
        });

        // Form submission
        document.getElementById('subscriptionForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const paymentMethod = document.getElementById('payment_method').value;
            const termsChecked = document.getElementById('terms').checked;
            
            if (!paymentMethod) {
                alert('Please select a payment method');
                return;
            }
            
            if (!termsChecked) {
                alert('Please agree to the terms and conditions');
                return;
            }
            
            // Show loading state
            const payButton = document.getElementById('pay-button');
            payButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-3"></i> Processing Payment...';
            payButton.disabled = true;
            
            // Simulate payment processing
            setTimeout(() => {
                alert('Payment successful! Your subscription is now active.');
                // In a real app, you would redirect to a success page or dashboard
                // window.location.href = 'dashboard.php?subscription=success';
            }, 2000);
        });

        // Initialize the default selected plan
        document.addEventListener('DOMContentLoaded', () => {
            const defaultPlan = document.querySelector('input[name="subscription_plan"]:checked');
            if (defaultPlan) {
                const label = document.querySelector(`label[for="${defaultPlan.id}"]`);
                const card = label.querySelector('.plan-card');
                card.classList.add('border-primary-500');
                card.classList.add('bg-primary-50');
            }
        });
    </script>
</body>
</html>