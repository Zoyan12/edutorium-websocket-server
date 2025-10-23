<?php
/**
 * Configuration file for Edutorium WebSocket Server
 */

// Application settings
define('APP_NAME', 'Edutorium WebSocket Server');
define('APP_ENV', getenv('APP_ENV') ?: 'production');

// Supabase settings from environment variables
$supabase_url = getenv('SUPABASE_URL');
$supabase_key = getenv('SUPABASE_KEY');

// Default fallback values
if (empty($supabase_url)) {
    $supabase_url = 'https://ratxqmbqzwbvfgsonlrd.supabase.co';
}

if (empty($supabase_key)) {
    $supabase_key = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InJhdHhxbWJxendidmZnc29ubHJkIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NDQyMDI0NDAsImV4cCI6MjA1OTc3ODQ0MH0.HJ9nQbvVvVisvQb6HMVMlmQBVmW7Ie42Z6Afdwn8W2M';
}

// Define constants
define('SUPABASE_URL', $supabase_url);
define('SUPABASE_ANON_KEY', $supabase_key);

// WebSocket settings
define('WEBSOCKET_PORT', getenv('WEBSOCKET_PORT') ?: 8080);
define('WEBSOCKET_HOST', '0.0.0.0');

// Logging settings
define('LOG_LEVEL', getenv('LOG_LEVEL') ?: 'info');
define('LOG_FILE', __DIR__ . '/logs/websocket.log');

// Error reporting
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Create logs directory if it doesn't exist
if (!is_dir(dirname(LOG_FILE))) {
    mkdir(dirname(LOG_FILE), 0755, true);
}

/**
 * Log a message to the log file
 */
function logMessage($level, $message) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
    
    // Also output to console for Docker logs
    echo "[{$level}] {$message}" . PHP_EOL;
}

/**
 * Make a request to the Supabase API
 */
function supabaseRequest($endpoint, $method = 'GET', $data = null, $token = null) {
    $url = SUPABASE_URL . $endpoint;
    $ch = curl_init($url);
    
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_ANON_KEY,
        'Prefer: return=representation'
    ];
    
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    if ($method === 'POST' || $method === 'PATCH' || $method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($error) {
        logMessage('ERROR', "Supabase request failed: {$error}");
        return ['status' => 0, 'error' => $error];
    }
    
    $responseData = json_decode($response, true);
    
    return [
        'status' => $statusCode,
        'data' => $responseData
    ];
}
