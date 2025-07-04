<?php
// config/config.php

// Prevent direct access to this file
if (basename($_SERVER['SCRIPT_FILENAME']) === 'config.php') {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// Nominatim User-Agent for geocoding requests
define('NOMINATIM_USER_AGENT', 'CompanionX/1.0 (arafathakash24601@gmail.com)'); // Email for OSM compliance

// Application Settings
define('APP_NAME', 'CompanionX');
define('APP_URL', 'http://localhost/companion/consultants_near_me'); // Update to your production URL
define('APP_TIMEZONE', 'UTC'); // Set your preferred timezone

// Security Settings
define('SESSION_COOKIE_SECURE', false); // Set to true in production with HTTPS
define('SESSION_COOKIE_HTTPONLY', true);
define('SESSION_COOKIE_SAMESITE', 'Lax');

// Error Logging
define('ERROR_LOG_PATH', 'C:/xampp/logs/php_error.log');

// Agora Settings for real-time video calling
define('AGORA_APP_ID', 'f713b3dd3d814d968da39ac2748b8eea'); // Replace with your Agora App ID from agora.io

/**
 * Instructions:
 * 1. Replace 'your_agora_app_id' with your actual Agora App ID from https://www.agora.io.
 * 2. Keep NOMINATIM_USER_AGENT email (arafathakash24601@gmail.com) for OSM compliance.
 * 3. Store this file outside the web root or protect with .htaccess:
 *    ```
 *    <Files config.php>
 *        Order Allow,Deny
 *        Deny from all
 *    </Files>
 *    ```
 * 4. Update APP_URL to your production domain (e.g., https://yourdomain.com).
 * 5. Set SESSION_COOKIE_SECURE to true if using HTTPS in production.
 * 6. For Agora, ensure you also set the App Certificate in agora_token.php for token generation.
 */
?>