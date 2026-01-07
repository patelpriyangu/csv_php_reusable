<?php

// Simple Autoloader
// Runtime config for large processing
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300); // 5 minutes per request (e.g. big upload)

spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = 'App\\';

    // Base directory for the namespace prefix
    $base_dir = __DIR__ . '/../src/';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // no, move to the next registered autoloader
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace the namespace prefix with the base directory, replace namespace
    // separators with directory separators in the relative class name, append
    // with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

// Routing
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Remove query parameters and trailing slashes for safer matching
$path = rtrim($path, '/');
if (empty($path)) {
    $path = '/';
}

use App\Controllers\InventoryController;

$controller = new InventoryController();

// Basic Router
switch ($path) {
    case '/':
        $controller->index();
        break;
    case '/download-template':
        $controller->downloadTemplate();
        break;
    case '/preview':
        // Changed to read from uploaded file ID
        if ($method === 'POST')
            $controller->preview();
        break;
    case '/upload':
        if ($method === 'POST')
            $controller->upload();
        break;
    case '/import-chunk':
        if ($method === 'POST')
            $controller->importChunk();
        break;
    case '/export':
        $controller->export();
        break;
    case '/history':
        $controller->history();
        break;
    case '/api/history':
        if ($method === 'GET')
            $controller->getHistory();
        break;
    case '/api/history/detail':
        if ($method === 'GET')
            $controller->getHistoryDetail();
        break;
    default:
        http_response_code(404);
        echo "404 Not Found";
        break;
}
