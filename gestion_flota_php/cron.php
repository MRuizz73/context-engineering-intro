<?php
/**
 * Envío de recordatorios para el Cron Job del hosting (opcional pero
 * recomendado; la app también los dispara sola con las visitas).
 *
 * Cron de cPanel (una vez al día):
 *     /usr/bin/php /home/USUARIO/public_html/flota/cron.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo ejecutable por línea de comandos (cron)');
}

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/datos.php';
require_once __DIR__ . '/lib/correo.php';
require_once __DIR__ . '/lib/turismos.php';

if (!correo_configurado()) {
    fwrite(STDERR, "Correo no configurado: completá la sección smtp de config.php\n");
    exit(1);
}
$resumen = enviar_recordatorios(db());
turismos_chequeo_avisos(db());
echo json_encode($resumen, JSON_UNESCAPED_UNICODE) . "\n";
