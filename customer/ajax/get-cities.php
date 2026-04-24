<?php
header('Content-Type: application/json');
$provinceCode = trim($_GET['province_code'] ?? '');
if (empty($provinceCode)) { echo json_encode([]); exit; }

$url      = "https://psgc.gitlab.io/api/provinces/$provinceCode/cities-municipalities/";
$response = file_get_contents($url);
$data     = json_decode($response, true) ?? [];

$cities = array_map(fn($c) => [
    'code' => $c['code'],
    'name' => $c['name'],
], $data);

usort($cities, fn($a, $b) => strcmp($a['name'], $b['name']));
echo json_encode($cities);