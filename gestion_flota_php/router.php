<?php
/**
 * Router para el servidor de desarrollo de PHP (NO se usa en el hosting,
 * ahí manda el .htaccess):
 *     php -S 127.0.0.1:8080 router.php
 */
declare(strict_types=1);

$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($ruta, '/api/')) {
    require __DIR__ . '/api.php';
    return true;
}
if ($ruta === '/' || $ruta === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}
return false; // archivos estáticos
