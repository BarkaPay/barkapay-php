<?php

// Router for `php -S`: serves the bodies CurlClientTest needs.
declare(strict_types=1);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(\is_string($uri) ? $uri : '/', PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/small') {
    echo '{"status":"success","code":"success","data":{"ok":true}}';

    return true;
}

$size = 11 * 1024 * 1024;
if ($path === '/announced') {
    header('Content-Length: ' . $size);
}

$chunk = str_repeat('x', 65536);
for ($sent = 0; $sent < $size; $sent += \strlen($chunk)) {
    echo $chunk;
    flush();
}

return true;
