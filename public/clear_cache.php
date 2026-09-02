<?php
if (($_GET['key'] ?? '') !== 'paya_secret_clear_2026') {
    http_response_code(403);
    exit('Forbidden');
}

$base = dirname(__DIR__);
$files = [
    $base . '/bootstrap/cache/routes-v7.php',
    $base . '/bootstrap/cache/config.php',
    $base . '/bootstrap/cache/services.php',
    $base . '/bootstrap/cache/packages.php',
];

$results = [];
foreach ($files as $file) {
    if (file_exists($file)) {
        $ok = @unlink($file);
        $results[] = basename($file) . ': ' . ($ok ? 'DELETED' : 'FAILED');
    } else {
        $results[] = basename($file) . ': NOT_FOUND';
    }
}

echo implode("\n", $results);
