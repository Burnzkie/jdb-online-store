<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$cacheFile = __DIR__ . '/cache/provinces.json';
$cacheTTL  = 60 * 60 * 24 * 7; // 7 days — provinces never change

// Serve from cache if fresh
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    echo file_get_contents($cacheFile);
    exit;
}

// Fetch from PSGC with timeout
$context = stream_context_create([
    'http' => [
        'timeout'       => 10, // 10 second max wait
        'ignore_errors' => true,
    ]
]);

$raw = @file_get_contents('https://psgc.gitlab.io/api/provinces/', false, $context);

// If fetch failed, serve stale cache if it exists
if ($raw === false || empty($raw)) {
    if (file_exists($cacheFile)) {
        echo file_get_contents($cacheFile); // serve stale rather than fail
    } else {
        http_response_code(503);
        echo json_encode(['error' => 'Address data unavailable. Please try again later.']);
    }
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    if (file_exists($cacheFile)) {
        echo file_get_contents($cacheFile);
    } else {
        http_response_code(503);
        echo json_encode([]);
    }
    exit;
}

// Sort and simplify
$provinces = array_map(fn($p) => [
    'code' => $p['code'],
    'name' => $p['name'],
], $data);

usort($provinces, fn($a, $b) => strcmp($a['name'], $b['name']));

$json = json_encode($provinces);

// Save to cache
file_put_contents($cacheFile, $json, LOCK_EX);

echo $json;