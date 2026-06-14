<?php


return [
    'google' => [
        'client_id'     => $_ENV['GOOGLE_CLIENT_ID']     ?? '',
        'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
        'redirect_uri'  => $_ENV['GOOGLE_REDIRECT_URI']  ?? '',
    ],
    'facebook' => [
        'client_id'     => $_ENV['FACEBOOK_CLIENT_ID']     ?? '',
        'client_secret' => $_ENV['FACEBOOK_CLIENT_SECRET'] ?? '',
        'redirect_uri'  => $_ENV['FACEBOOK_REDIRECT_URI']  ?? '',
    ],
    'github' => [
        'client_id'     => $_ENV['GITHUB_CLIENT_ID']     ?? '',
        'client_secret' => $_ENV['GITHUB_CLIENT_SECRET'] ?? '',
        'redirect_uri'  => $_ENV['GITHUB_REDIRECT_URI']  ?? '',
    ],
];