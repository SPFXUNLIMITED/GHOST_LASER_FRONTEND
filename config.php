<?php
// config.php

require_once __DIR__ . '/bootstrap_env.php';

return [
  'version' => '1.0.0',
  'db' => [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'name' => getenv('DB_DATABASE') ?: 'spfx_ghostlaser',
    'user' => getenv('DB_USERNAME') ?: 'spfx_ghost',
    'pass' => getenv('DB_PASSWORD') ?: '',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
  ],
  'recaptcha' => [
    'site_key'   => getenv('RECAPTCHA_SITE_KEY') ?: '',
    'secret_key' => getenv('RECAPTCHA_SECRET_KEY') ?: '',
  ],
  'google_maps' => [
    'api_key' => getenv('GOOGLE_MAPS_API_KEY') ?: '',
  ],
];