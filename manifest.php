<?php
// Clean any accidental output that might have started
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo json_encode([
    "name" => "Okoya Group Company Limited",
    "short_name" => "Okoya Group",
    "description" => "Secure access for all authorized personnel. Manage orders, logistics, HR operations, and departmental workflows from a unified platform.",
    "start_url" => "/",
    "scope" => "/",
    "display" => "standalone",
    "orientation" => "any",
    "dir" => "ltr",
    "lang" => "en",
    "theme_color" => "#059669",
    "background_color" => "#f8fafc",
    "id" => "/okoya-group-pwa",
    "categories" => ["business", "productivity"],
    "icons" => [
        ["src" => "/icon-64.png", "type" => "image/png", "sizes" => "64x64", "purpose" => "any"],
        ["src" => "/icon-192.png", "type" => "image/png", "sizes" => "192x192", "purpose" => "any"],
        ["src" => "/icon-192-maskable.png", "type" => "image/png", "sizes" => "192x192", "purpose" => "maskable"],
        ["src" => "/LargeTile.scale-100.png", "type" => "image/png", "sizes" => "310x310", "purpose" => "any"],
        ["src" => "/icon-512.png", "type" => "image/png", "sizes" => "512x512", "purpose" => "any"],
        ["src" => "/icon-512-maskable.png", "type" => "image/png", "sizes" => "512x512", "purpose" => "maskable"]
    ]
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

exit; // make sure nothing else is outputted after this