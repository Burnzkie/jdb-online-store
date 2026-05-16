<?php
header('Content-Type: application/json');

$cityCode = trim($_GET['city_code'] ?? '');
if (empty($cityCode) || !preg_match('/^\d+$/', $cityCode)) {
    echo json_encode([]);
    exit;
}

$cacheFile = __DIR__ . '/cache/barangays_' . $cityCode . '.json';
$cacheTTL  = 60 * 60 * 24 * 7; // 7 days

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    echo file_get_contents($cacheFile);
    exit;
}

$context = stream_context_create([
    'http' => ['timeout' => 10, 'ignore_errors' => true]
]);

$url = "https://psgc.gitlab.io/api/cities-municipalities/{$cityCode}/barangays/";
$raw = @file_get_contents($url, false, $context);

if ($raw === false || empty($raw)) {
    if (file_exists($cacheFile)) {
        echo file_get_contents($cacheFile);
    } else {
        echo json_encode([]);
    }
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    echo file_exists($cacheFile) ? file_get_contents($cacheFile) : json_encode([]);
    exit;
}

$barangays = array_map(fn($b) => [
    'code' => $b['code'],
    'name' => $b['name'],
], $data);

usort($barangays, fn($a, $b) => strcmp($a['name'], $b['name']));

$json = json_encode($barangays);
file_put_contents($cacheFile, $json, LOCK_EX);
echo $json;