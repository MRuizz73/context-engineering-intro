<?php
/**
 * Configuración de Gestión de Flota (versión PHP + MySQL).
 *
 * Copiar este archivo como `config.php` y completar los datos reales.
 * `config.php` NUNCA debe subirse al repositorio.
 */
return [
    // Conexión a la base MariaDB 10.11 del hosting (cPanel → Bases de datos).
    // OJO: el prefijo "mysql:" es el nombre del driver de PHP y es el
    // CORRECTO también para MariaDB — no hay que cambiarlo.
    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=gestion_flota;charset=utf8mb4',
        'usuario'  => 'usuario_bd',
        'password' => 'CAMBIAR-CLAVE-BD',
    ],

    // Correo de la empresa para los recordatorios.
    //
    // ▶ IONOS (recomendado si vuestro correo está en IONOS):
    //     'host'     => 'smtp.ionos.es',    // o smtp.ionos.com / smtp.ionos.de
    //     'puerto'   => 587,                // 587 con TLS; también vale 465 (SSL)
    //     'usuario'  => 'avisos@tudominio.com',  // el BUZÓN COMPLETO, no un alias
    //     'password' => 'la clave DEL BUZÓN (no la de la cuenta de cliente IONOS)',
    //     'tls'      => true,
    //   Tras configurarlo, usa el botón "🧪 Probar correo" de la app: si algo
    //   falla, el mensaje de error dice exactamente qué respondió IONOS.
    //
    // ▶ Gmail: host smtp.gmail.com, puerto 587, contraseña DE APLICACIÓN.
    // ▶ Con 'host' vacío se usa la función mail() del hosting con
    //   'email_remitente' como remitente (menos fiable, puede caer en spam).
    'smtp' => [
        'host'     => 'smtp.ionos.es',
        'puerto'   => 587,
        'usuario'  => 'avisos@tudominio.com',
        'password' => 'CAMBIAR-CLAVE-BUZON',
        'tls'      => true,
    ],
    'email_remitente' => '',   // solo si se usa mail() del hosting

    // Adónde van los avisos de camiones y de choferes sin email.
    'email_admin' => 'oficina@empresa.com',

    // Cada cuántos días se repite el email de un documento no renovado.
    'aviso_email_cada_dias' => 7,

    // Registro de cuentas desde la web: DESACTIVADO por defecto. Las cuentas
    // las crea el responsable desde la app (botón 🔐). Solo si se pone en
    // true vuelven a aplicar los códigos de abajo.
    'registro_abierto' => false,
    'codigo_registro'  => 'cambiar-este-codigo',  // responsables (ven todo)
    'codigo_chofer'    => null,                   // choferes (null = mismo código)

    // Importar datos_iniciales.csv y cuentas_iniciales.csv al primer uso.
    'auto_importar' => true,
];
