<?php
require_once dirname(__DIR__) . '/../../wp-load.php';
header('Content-Type: application/json');
$biz = obm_get('business_name', get_bloginfo('name'));
$color = obm_get('brand_color', '#2c5f2d');
echo json_encode([
    'name' => $biz . ' Bookings',
    'short_name' => 'Bookings',
    'start_url' => '/booking-app/',
    'display' => 'standalone',
    'background_color' => '#1a1a2e',
    'theme_color' => $color,
    'orientation' => 'portrait',
    'icons' => [
        ['src' => plugin_dir_url(__FILE__) . 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => plugin_dir_url(__FILE__) . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ]
]);
