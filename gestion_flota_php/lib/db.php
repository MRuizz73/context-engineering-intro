<?php
/**
 * Conexión a la base, creación del esquema y siembra de datos iniciales.
 * Soporta MySQL/MariaDB (producción) y SQLite (pruebas locales).
 */
declare(strict_types=1);

class ErrorHttp extends Exception
{
    public int $codigo;

    public function __construct(int $codigo, string $detalle)
    {
        parent::__construct($detalle);
        $this->codigo = $codigo;
    }
}

/** Devuelve la configuración (config.php o el ejemplo) con overrides de entorno. */
function config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $ruta = file_exists(__DIR__ . '/../config.php')
        ? __DIR__ . '/../config.php'
        : __DIR__ . '/../config.example.php';
    $base = require $ruta;

    // Overrides por variables de entorno (desarrollo y pruebas).
    if (getenv('GF_DSN') !== false)          $base['db']['dsn'] = getenv('GF_DSN');
    if (getenv('GF_DB_USUARIO') !== false)   $base['db']['usuario'] = getenv('GF_DB_USUARIO');
    if (getenv('GF_DB_PASSWORD') !== false)  $base['db']['password'] = getenv('GF_DB_PASSWORD');
    if (getenv('GF_SMTP_HOST') !== false)    $base['smtp']['host'] = getenv('GF_SMTP_HOST');
    if (getenv('GF_SMTP_PUERTO') !== false)  $base['smtp']['puerto'] = (int) getenv('GF_SMTP_PUERTO');
    if (getenv('GF_SMTP_USUARIO') !== false) $base['smtp']['usuario'] = getenv('GF_SMTP_USUARIO');
    if (getenv('GF_SMTP_TLS') !== false)     $base['smtp']['tls'] = getenv('GF_SMTP_TLS') === '1';
    if (getenv('GF_EMAIL_ADMIN') !== false)  $base['email_admin'] = getenv('GF_EMAIL_ADMIN');
    if (getenv('GF_CODIGO_REGISTRO') !== false) $base['codigo_registro'] = getenv('GF_CODIGO_REGISTRO') ?: null;

    return $cfg = $base;
}

/** Conexión PDO única; crea el esquema y siembra los datos la primera vez. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = config()['db'];
    $pdo = new PDO($c['dsn'], $c['usuario'] ?? null, $c['password'] ?? null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    crear_esquema($pdo);
    sembrar($pdo);
    return $pdo;
}

/** Crea las tablas si no existen (dialecto según el driver). */
function crear_esquema(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id    = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $tablas = [
        "CREATE TABLE IF NOT EXISTS chofer (
            id $id,
            nombre VARCHAR(100) NOT NULL,
            apellido VARCHAR(100) NOT NULL,
            dni VARCHAR(50) NOT NULL UNIQUE,
            telefono VARCHAR(50) NULL,
            email VARCHAR(190) NULL,
            activo TINYINT NOT NULL DEFAULT 1
        )$motor",
        "CREATE TABLE IF NOT EXISTS camion (
            id $id,
            patente VARCHAR(50) NOT NULL UNIQUE,
            marca VARCHAR(100) NULL,
            modelo VARCHAR(100) NULL,
            anio INT NULL,
            activo TINYINT NOT NULL DEFAULT 1
        )$motor",
        "CREATE TABLE IF NOT EXISTS documento (
            id $id,
            nombre VARCHAR(200) NOT NULL,
            tipo VARCHAR(30) NOT NULL DEFAULT 'curso',
            fecha_emision DATE NULL,
            fecha_vencimiento DATE NOT NULL,
            dias_aviso INT NOT NULL DEFAULT 30,
            notas TEXT NULL,
            chofer_id INT NULL,
            camion_id INT NULL,
            ultimo_aviso_email DATE NULL
        )$motor",
        "CREATE TABLE IF NOT EXISTS usuario (
            id $id,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            rol VARCHAR(10) NOT NULL DEFAULT 'admin',
            chofer_id INT NULL
        )$motor",
        "CREATE TABLE IF NOT EXISTS ajuste (
            clave VARCHAR(60) NOT NULL PRIMARY KEY,
            valor VARCHAR(200) NOT NULL
        )$motor",
    ];
    foreach ($tablas as $sql) {
        $pdo->exec($sql);
    }
}

/** Importa el CSV de la empresa y crea las cuentas iniciales si la base está vacía. */
function sembrar(PDO $pdo): void
{
    if (!config()['auto_importar']) {
        return;
    }
    require_once __DIR__ . '/importar.php';

    $sin_datos = (int) $pdo->query('SELECT COUNT(*) FROM chofer')->fetchColumn() === 0
        && (int) $pdo->query('SELECT COUNT(*) FROM camion')->fetchColumn() === 0
        && (int) $pdo->query('SELECT COUNT(*) FROM documento')->fetchColumn() === 0;
    $csv_datos = __DIR__ . '/../datos_iniciales.csv';
    if ($sin_datos && file_exists($csv_datos)) {
        importar_csv($pdo, $csv_datos);
    }

    $sin_usuarios = (int) $pdo->query('SELECT COUNT(*) FROM usuario')->fetchColumn() === 0;
    $csv_cuentas = __DIR__ . '/../cuentas_iniciales.csv';
    if ($sin_usuarios && file_exists($csv_cuentas)) {
        crear_cuentas_csv($pdo, $csv_cuentas);
    }
}

/** Crea las cuentas del CSV inicial (usuario,password,rol,chofer). */
function crear_cuentas_csv(PDO $pdo, string $ruta): void
{
    $archivo = fopen($ruta, 'r');
    $cabecera = fgetcsv($archivo, 0, ',', '"', '\\');
    while (($fila = fgetcsv($archivo, 0, ',', '"', '\\')) !== false) {
        $datos = array_combine($cabecera, array_pad($fila, count($cabecera), ''));
        $username = strtolower(trim($datos['usuario'] ?? ''));
        $password = trim($datos['password'] ?? '');
        $rol      = strtolower(trim($datos['rol'] ?? 'admin'));
        $nombre   = trim($datos['chofer'] ?? '');
        if ($username === '' || strlen($password) < 6) {
            continue;
        }
        $chofer_id = null;
        if ($rol === 'chofer' && $nombre !== '') {
            $partes = preg_split('/\s+/', $nombre);
            $stmt = $pdo->prepare('SELECT id FROM chofer WHERE nombre = ? AND apellido = ?');
            $stmt->execute([$partes[0], implode(' ', array_slice($partes, 1))]);
            $chofer_id = $stmt->fetchColumn() ?: null;
            if ($chofer_id === null) {
                continue;
            }
        }
        $stmt = $pdo->prepare(
            'INSERT INTO usuario (username, password_hash, rol, chofer_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $rol, $chofer_id]);
    }
    fclose($archivo);
}
