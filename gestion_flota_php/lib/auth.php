<?php
/**
 * Sesiones, roles y endpoints de autenticación.
 * Roles: 'admin' (responsable, ve todo) y 'chofer' (ve solo lo suyo).
 */
declare(strict_types=1);

/** Arranca la sesión PHP con cookie segura de 30 días. */
function iniciar_sesion_php(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('gf_sesion');
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/** Usuario logueado o null. */
function usuario_actual(): ?array
{
    iniciar_sesion_php();
    if (empty($_SESSION['usuario_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM usuario WHERE id = ?');
    $stmt->execute([$_SESSION['usuario_id']]);
    return $stmt->fetch() ?: null;
}

/** Exige sesión iniciada. */
function requiere_usuario(): array
{
    $usuario = usuario_actual();
    if ($usuario === null) {
        throw new ErrorHttp(401, 'No autenticado');
    }
    return $usuario;
}

/** Exige rol de responsable (admin). */
function requiere_admin(): array
{
    $usuario = requiere_usuario();
    if ($usuario['rol'] !== 'admin') {
        throw new ErrorHttp(403, 'Solo el responsable de transporte puede hacer esto');
    }
    return $usuario;
}

/** Respuesta pública de un usuario (sin hash). */
function usuario_a_read(array $u): array
{
    return [
        'id'        => (int) $u['id'],
        'username'  => $u['username'],
        'rol'       => $u['rol'],
        'chofer_id' => $u['chofer_id'] !== null ? (int) $u['chofer_id'] : null,
    ];
}

/** POST /api/auth/registro */
function auth_registro(array $cuerpo): array
{
    $username = strtolower(trim((string) ($cuerpo['username'] ?? '')));
    $password = (string) ($cuerpo['password'] ?? '');
    $codigo   = $cuerpo['codigo'] ?? null;
    $email    = trim((string) ($cuerpo['email_chofer'] ?? ''));

    $cfg = config();
    // Reason: por defecto el registro está cerrado; las cuentas las crea el
    // responsable desde la app (generador 🔐) y las entrega en mano.
    if (!($cfg['registro_abierto'] ?? false)) {
        throw new ErrorHttp(403, 'El registro está desactivado. El responsable de transporte crea las cuentas.');
    }
    if (strlen($username) < 3 || strlen($password) < 6) {
        throw new ErrorHttp(422, 'Usuario (mín. 3) o contraseña (mín. 6) demasiado cortos');
    }
    $es_chofer = $email !== '';
    $codigo_requerido = $es_chofer
        ? ($cfg['codigo_chofer'] ?? null) ?: ($cfg['codigo_registro'] ?? null)
        : ($cfg['codigo_registro'] ?? null);
    if ($codigo_requerido && $codigo !== $codigo_requerido) {
        throw new ErrorHttp(403, 'Código de empresa incorrecto. Pídeselo al responsable de transporte.');
    }

    $chofer_id = null;
    if ($es_chofer) {
        $stmt = db()->prepare('SELECT id FROM chofer WHERE LOWER(TRIM(email)) = ?');
        $stmt->execute([strtolower($email)]);
        $chofer_id = $stmt->fetchColumn();
        if ($chofer_id === false) {
            throw new ErrorHttp(404, 'No hay ningún chófer con ese email. Pídele al responsable que cargue tu email en tu perfil primero.');
        }
        $stmt = db()->prepare('SELECT id FROM usuario WHERE chofer_id = ?');
        $stmt->execute([$chofer_id]);
        if ($stmt->fetchColumn() !== false) {
            throw new ErrorHttp(409, 'Ese chófer ya tiene cuenta');
        }
    }

    $stmt = db()->prepare('SELECT id FROM usuario WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetchColumn() !== false) {
        throw new ErrorHttp(409, 'Ese nombre de usuario ya existe');
    }

    $stmt = db()->prepare(
        'INSERT INTO usuario (username, password_hash, rol, chofer_id) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        $es_chofer ? 'chofer' : 'admin',
        $chofer_id ?: null,
    ]);

    iniciar_sesion_php();
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int) db()->lastInsertId();
    http_response_code(201);
    return usuario_a_read(usuario_actual());
}

/** POST /api/auth/login */
function auth_login(array $cuerpo): array
{
    $username = strtolower(trim((string) ($cuerpo['username'] ?? '')));
    $stmt = db()->prepare('SELECT * FROM usuario WHERE username = ?');
    $stmt->execute([$username]);
    $usuario = $stmt->fetch();
    if (!$usuario || !password_verify((string) ($cuerpo['password'] ?? ''), $usuario['password_hash'])) {
        throw new ErrorHttp(401, 'Usuario o contraseña incorrectos');
    }
    iniciar_sesion_php();
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int) $usuario['id'];
    return usuario_a_read($usuario);
}

/** POST /api/auth/logout */
function auth_logout(): void
{
    iniciar_sesion_php();
    $_SESSION = [];
    session_destroy();
    http_response_code(204);
}

/** POST /api/auth/cambiar-password */
function auth_cambiar_password(array $cuerpo): void
{
    $usuario = requiere_usuario();
    $nueva = (string) ($cuerpo['password_nueva'] ?? '');
    if (strlen($nueva) < 6) {
        throw new ErrorHttp(422, 'La contraseña nueva debe tener al menos 6 caracteres');
    }
    if (!password_verify((string) ($cuerpo['password_actual'] ?? ''), $usuario['password_hash'])) {
        throw new ErrorHttp(401, 'La contraseña actual no es correcta');
    }
    db()->prepare('UPDATE usuario SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuario['id']]);
    http_response_code(204);
}

/** Genera una contraseña segura sin caracteres ambiguos (xxxx-xxxx). */
function generar_password(): string
{
    $alfabeto = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $trozo = function () use ($alfabeto): string {
        $s = '';
        for ($i = 0; $i < 4; $i++) {
            $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        return $s;
    };
    return $trozo() . '-' . $trozo();
}

/** Crea la cuenta de un chofer con usuario/contraseña generados. */
function crear_cuenta_chofer(array $chofer): array
{
    $stmt = db()->prepare('SELECT username FROM usuario WHERE chofer_id = ?');
    $stmt->execute([$chofer['id']]);
    $existente = $stmt->fetchColumn();
    if ($existente !== false) {
        throw new ErrorHttp(409, "Este chófer ya tiene la cuenta '$existente'");
    }

    $base = strtolower(trim(
        (string) iconv('UTF-8', 'ASCII//TRANSLIT', "{$chofer['nombre']} {$chofer['apellido']}")
    ));
    $base = preg_replace('/[^a-z0-9 ]/', '', $base);
    $base = str_replace(' ', '.', trim((string) preg_replace('/\s+/', ' ', $base)));

    $candidato = $base;
    $sufijo = 1;
    $stmt = db()->prepare('SELECT id FROM usuario WHERE username = ?');
    while (true) {
        $stmt->execute([$candidato]);
        if ($stmt->fetchColumn() === false) {
            break;
        }
        $sufijo++;
        $candidato = $base . $sufijo;
    }

    $password = generar_password();
    db()->prepare('INSERT INTO usuario (username, password_hash, rol, chofer_id) VALUES (?, ?, ?, ?)')
        ->execute([$candidato, password_hash($password, PASSWORD_DEFAULT), 'chofer', $chofer['id']]);
    http_response_code(201);
    return ['username' => $candidato, 'password' => $password];
}
