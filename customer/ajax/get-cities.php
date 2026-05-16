<?php
header('Content-Type: application/json');

$provinceCode = trim($_GET['province_code'] ?? '');
if (empty($provinceCode) || !preg_match('/^\d+$/', $provinceCode)) {
    echo json_encode([]);
    exit;
}

$cacheFile = __DIR__ . '/cache/cities_' . $provinceCode . '.json';
$cacheTTL  = 60 * 60 * 24 * 7; // 7 days

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    echo file_get_contents($cacheFile);
    exit;
}

$context = stream_context_create([
    'http' => ['timeout' => 10, 'ignore_errors' => true]
]);

$url = "https://psgc.gitlab.io/api/provinces/{$provinceCode}/cities-municipalities/";
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

$cities = array_map(fn($c) => [
    'code' => $c['code'],
    'name' => $c['name'],
], $data);

usort($cities, fn($a, $b) => strcmp($a['name'], $b['name']));

$json = json_encode($cities);
file_put_contents($cacheFile, $json, LOCK_EX);
echo $json;