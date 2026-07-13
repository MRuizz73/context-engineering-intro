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
    // Con 'host' completo usa SMTP (Gmail: smtp.gmail.com + contraseña de
    // aplicación). Con 'host' vacío usa la función mail() del hosting y
    // 'remitente' como dirección De:.
    'smtp' => [
        'host'     => '',
        'puerto'   => 587,
        'usuario'  => 'empresa@gmail.com',
        'password' => '',
        'tls'      => true,
    ],
    'email_remitente' => '',   // solo si se usa mail() del hosting

    // Adónde van los avisos de camiones y de choferes sin email.
    'email_admin' => 'oficina@empresa.com',

    // Cada cuántos días se repite el email de un documento no renovado.
    'aviso_email_cada_dias' => 7,

    // Códigos para crear cuentas nuevas desde la pantalla de registro.
    'codigo_registro' => 'cambiar-este-codigo',  // responsables (ven todo)
    'codigo_chofer'   => null,                   // choferes (null = mismo código)

    // Importar datos_iniciales.csv y cuentas_iniciales.csv al primer uso.
    'auto_importar' => true,
];
