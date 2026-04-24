<?php
header('Content-Type: application/json');
$cityCode = trim($_GET['city_code'] ?? '');
if (empty($cityCode)) { echo json_encode([]); exit; }

$url      = "https://psgc.gitlab.io/api/cities-municipalities/$cityCode/barangays/";
$response = file_get_contents($url);
$data     = json_decode($response, true) ?? [];

$barangays = array_map(fn($b) => [
    'code' => $b['code'],
    'name' => $b['name'],
], $data);

usort($barangays, fn($a, $b) => strcmp($a['name'], $b['name']));
echo json_encode($barangays);