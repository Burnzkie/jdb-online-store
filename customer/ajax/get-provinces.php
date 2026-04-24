<?php
header('Content-Type: application/json');
// PSGC API — official Philippine government data
$url      = 'https://psgc.gitlab.io/api/provinces/';
$response = file_get_contents($url);
$data     = json_decode($response, true) ?? [];

$provinces = array_map(fn($p) => [
    'code' => $p['code'],
    'name' => $p['name'],
], $data);

usort($provinces, fn($a, $b) => strcmp($a['name'], $b['name']));
echo json_encode($provinces);