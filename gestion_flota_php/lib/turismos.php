<?php
/**
 * Módulo de TURISMOS (coches de empresa) — solicitud y devolución con
 * ubicación GPS, contrato de cesión firmado con el dedo y registro
 * exportable para auditorías.
 *
 * Módulo 100% aditivo: crea sus propias tablas (vehiculo, turismo_solicitud,
 * turismo_usuario) y no modifica ninguna tabla ni función existente.
 * Rol nuevo: 'vehiculos' → solo puede usar los endpoints de este módulo.
 */
declare(strict_types=1);

// Texto que ocupa el hueco "hasta ..." del contrato mientras el coche no se
// devuelve; al confirmar la devolución se sustituye por la fecha y hora real.
const TURISMO_PENDIENTE_DEVOLUCION = 'pendiente de devolución (se completa al devolverlo en la app)';

// ---------- esquema y siembra ----------

/** Crea las tablas del módulo si no existen y siembra los vehículos del CSV. */
function turismos_asegurar_esquema(PDO $pdo): void
{
    static $hecho = false;
    if ($hecho) {
        return;
    }
    $hecho = true;

    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id    = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $tablas = [
        "CREATE TABLE IF NOT EXISTS vehiculo (
            id $id,
            matricula VARCHAR(20) NOT NULL UNIQUE,
            modelo VARCHAR(100) NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'disponible'
        )$motor",
        "CREATE TABLE IF NOT EXISTS turismo_solicitud (
            id $id,
            vehiculo_id INT NOT NULL,
            usuario_id INT NULL,
            nombre VARCHAR(150) NOT NULL,
            telefono VARCHAR(50) NOT NULL,
            motivo TEXT NOT NULL,
            fecha_solicitud VARCHAR(19) NOT NULL,
            lat_solicitud DOUBLE NULL,
            lng_solicitud DOUBLE NULL,
            fecha_devolucion VARCHAR(19) NULL,
            lat_devolucion DOUBLE NULL,
            lng_devolucion DOUBLE NULL,
            devuelto_via VARCHAR(10) NULL,
            contrato MEDIUMTEXT NULL,
            firma MEDIUMTEXT NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            token VARCHAR(40) NOT NULL UNIQUE,
            ultimo_aviso DATE NULL
        )$motor",
        "CREATE TABLE IF NOT EXISTS turismo_usuario (
            id $id,
            usuario_id INT NOT NULL UNIQUE,
            nombre VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL,
            dni VARCHAR(20) NULL,
            telefono VARCHAR(50) NULL,
            domicilio VARCHAR(200) NULL,
            permiso VARCHAR(30) NULL,
            clase_permiso VARCHAR(15) NULL,
            permiso_caduca VARCHAR(10) NULL,
            empleado TINYINT NOT NULL DEFAULT 1
        )$motor",
    ];
    foreach ($tablas as $sql) {
        $pdo->exec($sql);
    }

    // Columnas añadidas después de la primera versión del módulo.
    turismos_asegurar_columna($pdo, 'turismo_usuario', 'dni', 'VARCHAR(20) NULL');
    turismos_asegurar_columna($pdo, 'turismo_usuario', 'telefono', 'VARCHAR(50) NULL');
    turismos_asegurar_columna($pdo, 'turismo_usuario', 'domicilio', 'VARCHAR(200) NULL');
    turismos_asegurar_columna($pdo, 'turismo_usuario', 'permiso', 'VARCHAR(30) NULL');
    turismos_asegurar_columna($pdo, 'turismo_usuario', 'clase_permiso', 'VARCHAR(15) NULL');
    turismos_asegurar_columna($pdo, 'turismo_usuario', 'permiso_caduca', 'VARCHAR(10) NULL');
    turismos_asegurar_columna($pdo, 'turismo_usuario', 'empleado', 'TINYINT NOT NULL DEFAULT 1');
    turismos_asegurar_columna($pdo, 'turismo_solicitud', 'dni', 'VARCHAR(20) NULL');
    turismos_asegurar_columna($pdo, 'turismo_solicitud', 'km', 'VARCHAR(20) NULL');
    turismos_asegurar_columna($pdo, 'turismo_solicitud', 'nivel', 'VARCHAR(10) NULL');
    turismos_asegurar_columna($pdo, 'turismo_solicitud', 'accesorios', 'VARCHAR(200) NULL');
    turismos_asegurar_columna($pdo, 'turismo_solicitud', 'danos', 'TEXT NULL');
    turismos_asegurar_columna($pdo, 'turismo_solicitud', 'finalidad', 'VARCHAR(80) NULL');

    $csv = __DIR__ . '/../vehiculos_iniciales.csv';
    if ((int) $pdo->query('SELECT COUNT(*) FROM vehiculo')->fetchColumn() === 0 && file_exists($csv)) {
        turismos_importar_csv($pdo, $csv);
    }
}

/** Añade una columna si falta (migración suave, vale para MariaDB y SQLite). */
function turismos_asegurar_columna(PDO $pdo, string $tabla, string $columna, string $tipo): void
{
    try {
        $pdo->exec("ALTER TABLE $tabla ADD COLUMN $columna $tipo");
    } catch (Throwable $e) {
        // Reason: si la columna ya existe el ALTER falla y no hay que hacer
        // nada; así la migración es idempotente sin consultar el catálogo.
    }
}

/** Importa vehiculos_iniciales.csv (matricula,modelo con anotaciones de estado). */
function turismos_importar_csv(PDO $pdo, string $ruta): void
{
    $archivo = fopen($ruta, 'r');
    fgetcsv($archivo, 0, ',', '"', '\\'); // cabecera
    $stmt = $pdo->prepare('INSERT INTO vehiculo (matricula, modelo, estado) VALUES (?, ?, ?)');
    while (($fila = fgetcsv($archivo, 0, ',', '"', '\\')) !== false) {
        $matricula = strtoupper(trim((string) ($fila[0] ?? '')));
        $modelo    = trim((string) ($fila[1] ?? ''));
        if ($matricula === '') {
            continue;
        }
        // Reason: en el listado de la empresa el estado viene anotado entre
        // paréntesis dentro del modelo; se separa a su propio campo.
        $estado = 'disponible';
        if (preg_match('/\(mantenimiento\)/i', $modelo)) {
            $estado = 'mantenimiento';
        } elseif (preg_match('/\(fuera de flota\)/i', $modelo)) {
            $estado = 'baja';
        }
        $modelo = trim((string) preg_replace('/\s*\((mantenimiento|fuera de flota)\)\s*/i', '', $modelo));
        $stmt->execute([$matricula, $modelo ?: null, $estado]);
    }
    fclose($archivo);
}

// ---------- router del módulo ----------

/** Despacha /api/turismos/... (las rutas de devolución por token son públicas). */
function turismos_enrutar(PDO $pdo, string $metodo, array $seg, array $cuerpo): mixed
{
    turismos_asegurar_esquema($pdo);
    turismos_guardar_url_base($pdo);
    $r = $seg[1] ?? '';
    $id = isset($seg[2]) && ctype_digit($seg[2]) ? (int) $seg[2] : null;

    // Devolución por el enlace del email: sin sesión, autenticada por token.
    if ($r === 'devolucion' && isset($seg[2])) {
        $sol = turismo_por_token($pdo, $seg[2]);
        if ($metodo === 'GET') {
            return [
                'matricula'       => $sol['matricula'],
                'modelo'          => $sol['modelo'],
                'nombre'          => $sol['nombre'],
                'fecha_solicitud' => $sol['fecha_solicitud'],
                'devuelta'        => $sol['fecha_devolucion'] !== null,
            ];
        }
        if ($metodo === 'POST') {
            return turismo_devolver($pdo, $sol, $cuerpo, 'email');
        }
        throw new ErrorHttp(405, 'Método no permitido');
    }

    $usuario = requiere_usuario();
    $es_admin = $usuario['rol'] === 'admin';
    if (!$es_admin && $usuario['rol'] !== 'vehiculos') {
        throw new ErrorHttp(403, 'No tienes acceso a la sección de turismos');
    }

    if ($r === 'contrato' && $metodo === 'GET') {
        return ['texto' => turismos_contrato_plantilla()];
    }

    // Datos de la cuenta del solicitante: si ya existen, la app no vuelve a
    // pedirlos al solicitar un coche (solo pregunta el motivo).
    if ($r === 'yo' && $metodo === 'GET') {
        $perfil = turismo_perfil_usuario($pdo, (int) $usuario['id']);
        return $perfil ?? [
            'nombre' => null, 'dni' => null, 'telefono' => null, 'email' => null,
            'domicilio' => null, 'permiso' => null, 'clase_permiso' => null,
            'permiso_caduca' => null, 'empleado' => null,
        ];
    }

    if ($r === 'vehiculos') {
        if ($metodo === 'GET') {
            return turismos_vehiculos_listar($pdo, $es_admin);
        }
        requiere_admin();
        if ($metodo === 'POST') {
            return turismo_vehiculo_crear($pdo, $cuerpo);
        }
        if ($id !== null && $metodo === 'PUT') {
            return turismo_vehiculo_actualizar($pdo, $id, $cuerpo);
        }
        if ($id !== null && $metodo === 'DELETE') {
            return turismo_vehiculo_eliminar($pdo, $id);
        }
        throw new ErrorHttp(405, 'Método no permitido');
    }

    if ($r === 'solicitudes') {
        if ($id !== null && ($seg[3] ?? '') === 'devolver' && $metodo === 'POST') {
            $sol = turismo_solicitud_fila($pdo, $id);
            if (!$es_admin && (int) $sol['usuario_id'] !== (int) $usuario['id']) {
                throw new ErrorHttp(403, 'Solo puedes devolver tus propias solicitudes');
            }
            return turismo_devolver($pdo, $sol, $cuerpo, 'app');
        }
        if ($id !== null && $metodo === 'GET') {
            requiere_admin();
            return turismo_solicitud_a_read(turismo_solicitud_fila($pdo, $id), true);
        }
        if ($metodo === 'GET') {
            return turismos_solicitudes_listar($pdo, $es_admin ? null : (int) $usuario['id']);
        }
        if ($metodo === 'POST') {
            return turismo_solicitar($pdo, $cuerpo, $usuario);
        }
        throw new ErrorHttp(405, 'Método no permitido');
    }

    if ($r === 'usuarios') {
        requiere_admin();
        if ($metodo === 'GET' && $id === null) {
            return turismos_usuarios_listar($pdo);
        }
        if ($metodo === 'POST' && $id === null) {
            return turismo_usuario_crear($pdo, $cuerpo);
        }
        if ($metodo === 'PUT' && $id !== null) {
            return turismo_usuario_actualizar($pdo, $id, $cuerpo);
        }
        if ($metodo === 'DELETE' && $id !== null) {
            return turismo_usuario_eliminar($pdo, $id);
        }
        throw new ErrorHttp(405, 'Método no permitido');
    }

    throw new ErrorHttp(404, 'Ruta no encontrada');
}

// ---------- vehículos ----------

function turismos_vehiculos_listar(PDO $pdo, bool $es_admin): array
{
    $abiertas = [];
    $sql = 'SELECT s.vehiculo_id, s.nombre, s.fecha_solicitud FROM turismo_solicitud s
            WHERE s.fecha_devolucion IS NULL';
    foreach ($pdo->query($sql)->fetchAll() as $s) {
        $abiertas[(int) $s['vehiculo_id']] = $s;
    }
    $filas = $pdo->query('SELECT * FROM vehiculo ORDER BY matricula')->fetchAll();
    return array_map(function (array $v) use ($abiertas, $es_admin): array {
        $abierta = $abiertas[(int) $v['id']] ?? null;
        $r = [
            'id'        => (int) $v['id'],
            'matricula' => $v['matricula'],
            'modelo'    => $v['modelo'],
            'estado'    => $v['estado'],
            'en_uso'    => $abierta !== null,
        ];
        if ($es_admin && $abierta !== null) {
            $r['usado_por']   = $abierta['nombre'];
            $r['usado_desde'] = $abierta['fecha_solicitud'];
        }
        return $r;
    }, $filas);
}

function turismo_vehiculo_fila(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM vehiculo WHERE id = ?');
    $stmt->execute([$id]);
    $fila = $stmt->fetch();
    if ($fila === false) {
        throw new ErrorHttp(404, 'Vehículo no encontrado');
    }
    return $fila;
}

function turismo_validar_vehiculo(array $c): array
{
    $matricula = strtoupper(trim((string) ($c['matricula'] ?? '')));
    $estado = trim((string) ($c['estado'] ?? 'disponible')) ?: 'disponible';
    if ($matricula === '') {
        throw new ErrorHttp(422, 'La matrícula es obligatoria');
    }
    if (!in_array($estado, ['disponible', 'mantenimiento', 'baja'], true)) {
        throw new ErrorHttp(422, 'Estado no válido (disponible, mantenimiento o baja)');
    }
    return [$matricula, trim((string) ($c['modelo'] ?? '')) ?: null, $estado];
}

function turismo_vehiculo_crear(PDO $pdo, array $cuerpo): array
{
    $v = turismo_validar_vehiculo($cuerpo);
    $stmt = $pdo->prepare('SELECT id FROM vehiculo WHERE matricula = ?');
    $stmt->execute([$v[0]]);
    if ($stmt->fetchColumn() !== false) {
        throw new ErrorHttp(409, 'Ya existe un vehículo con esa matrícula');
    }
    $pdo->prepare('INSERT INTO vehiculo (matricula, modelo, estado) VALUES (?, ?, ?)')->execute($v);
    http_response_code(201);
    return turismo_vehiculo_fila($pdo, (int) $pdo->lastInsertId());
}

function turismo_vehiculo_actualizar(PDO $pdo, int $id, array $cuerpo): array
{
    turismo_vehiculo_fila($pdo, $id);
    $v = turismo_validar_vehiculo($cuerpo);
    $stmt = $pdo->prepare('SELECT id FROM vehiculo WHERE matricula = ? AND id <> ?');
    $stmt->execute([$v[0], $id]);
    if ($stmt->fetchColumn() !== false) {
        throw new ErrorHttp(409, 'Ya existe un vehículo con esa matrícula');
    }
    $v[] = $id;
    $pdo->prepare('UPDATE vehiculo SET matricula = ?, modelo = ?, estado = ? WHERE id = ?')->execute($v);
    return turismo_vehiculo_fila($pdo, $id);
}

function turismo_vehiculo_eliminar(PDO $pdo, int $id): mixed
{
    turismo_vehiculo_fila($pdo, $id);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM turismo_solicitud WHERE vehiculo_id = ?');
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() > 0) {
        // Reason: el registro es para auditorías; no se borra el historial de
        // un coche usado, se marca como "baja" para sacarlo de la lista.
        throw new ErrorHttp(409, 'Este vehículo tiene historial de uso: márcalo como "baja" en vez de borrarlo');
    }
    $pdo->prepare('DELETE FROM vehiculo WHERE id = ?')->execute([$id]);
    http_response_code(204);
    return null;
}

// ---------- solicitudes ----------

function turismo_solicitud_fila(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT s.*, v.matricula, v.modelo FROM turismo_solicitud s
         JOIN vehiculo v ON v.id = s.vehiculo_id WHERE s.id = ?'
    );
    $stmt->execute([$id]);
    $fila = $stmt->fetch();
    if ($fila === false) {
        throw new ErrorHttp(404, 'Solicitud no encontrada');
    }
    return $fila;
}

function turismo_por_token(PDO $pdo, string $token): array
{
    $stmt = $pdo->prepare(
        'SELECT s.*, v.matricula, v.modelo FROM turismo_solicitud s
         JOIN vehiculo v ON v.id = s.vehiculo_id WHERE s.token = ?'
    );
    $stmt->execute([$token]);
    $fila = $stmt->fetch();
    if ($fila === false) {
        throw new ErrorHttp(404, 'Enlace de devolución no válido');
    }
    return $fila;
}

function turismo_solicitud_a_read(array $f, bool $con_detalle = false): array
{
    $hasta = $f['fecha_devolucion'] ?? date('Y-m-d H:i:s');
    $dias = (int) floor((strtotime($hasta) - strtotime($f['fecha_solicitud'])) / 86400);
    $r = [
        'id'               => (int) $f['id'],
        'usuario_id'       => $f['usuario_id'] !== null ? (int) $f['usuario_id'] : null,
        'vehiculo_id'      => (int) $f['vehiculo_id'],
        'matricula'        => $f['matricula'],
        'modelo'           => $f['modelo'],
        'nombre'           => $f['nombre'],
        'telefono'         => $f['telefono'],
        'dni'              => $f['dni'] ?? null,
        'motivo'           => $f['motivo'],
        'km'               => $f['km'] ?? null,
        'nivel'            => $f['nivel'] ?? null,
        'accesorios'       => $f['accesorios'] ?? null,
        'danos'            => $f['danos'] ?? null,
        'finalidad'        => $f['finalidad'] ?? null,
        'fecha_solicitud'  => $f['fecha_solicitud'],
        'lat_solicitud'    => $f['lat_solicitud'] !== null ? (float) $f['lat_solicitud'] : null,
        'lng_solicitud'    => $f['lng_solicitud'] !== null ? (float) $f['lng_solicitud'] : null,
        'fecha_devolucion' => $f['fecha_devolucion'],
        'lat_devolucion'   => $f['lat_devolucion'] !== null ? (float) $f['lat_devolucion'] : null,
        'lng_devolucion'   => $f['lng_devolucion'] !== null ? (float) $f['lng_devolucion'] : null,
        'devuelta'         => $f['fecha_devolucion'] !== null,
        'devuelto_via'     => $f['devuelto_via'],
        'dias_en_uso'      => max(0, $dias),
    ];
    if ($con_detalle) {
        $r['contrato'] = $f['contrato'];
        $r['firma']    = $f['firma'];
        $r['ip']       = $f['ip'];
    }
    return $r;
}

/** Lista el registro completo (admin) o las solicitudes propias sin devolver. */
function turismos_solicitudes_listar(PDO $pdo, ?int $solo_usuario_id): array
{
    $sql = 'SELECT s.*, v.matricula, v.modelo FROM turismo_solicitud s
            JOIN vehiculo v ON v.id = s.vehiculo_id';
    $parametros = [];
    if ($solo_usuario_id !== null) {
        $sql .= ' WHERE s.usuario_id = ? AND s.fecha_devolucion IS NULL';
        $parametros[] = $solo_usuario_id;
    }
    $sql .= ' ORDER BY s.fecha_solicitud DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);
    return array_map('turismo_solicitud_a_read', $stmt->fetchAll());
}

/** Datos de la cuenta de vehículos del usuario (o null si no tiene). */
function turismo_perfil_usuario(PDO $pdo, int $usuario_id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT nombre, dni, telefono, email, domicilio, permiso, clase_permiso,
                permiso_caduca, empleado
         FROM turismo_usuario WHERE usuario_id = ?'
    );
    $stmt->execute([$usuario_id]);
    $fila = $stmt->fetch();
    if ($fila === false) {
        return null;
    }
    $fila['empleado'] = (bool) $fila['empleado'];
    return $fila;
}

/** POST /api/turismos/solicitudes — pedir un coche con contrato firmado. */
function turismo_solicitar(PDO $pdo, array $cuerpo, array $usuario): array
{
    $vehiculo_id = (int) ($cuerpo['vehiculo_id'] ?? 0);
    $nombre   = trim((string) ($cuerpo['nombre'] ?? ''));
    $telefono = trim((string) ($cuerpo['telefono'] ?? ''));
    $dni      = trim((string) ($cuerpo['dni'] ?? ''));
    $motivo   = trim((string) ($cuerpo['motivo'] ?? ''));
    $firma    = (string) ($cuerpo['firma'] ?? '');

    // Estado del vehículo en la entrega (cuestionario extra del contrato).
    $km         = trim((string) ($cuerpo['km'] ?? ''));
    $nivel      = trim((string) ($cuerpo['nivel'] ?? ''));
    $accesorios = trim((string) ($cuerpo['accesorios'] ?? '')) ?: 'Ninguno';
    $danos      = trim((string) ($cuerpo['danos'] ?? '')) ?: 'Sin daños anotados';
    $finalidad  = trim((string) ($cuerpo['finalidad'] ?? ''));

    if ($km === '' || !preg_match('/^\d{1,7}$/', $km)) {
        throw new ErrorHttp(422, 'Indica los kilómetros que marca el coche (solo números)');
    }
    if ($nivel === '' || !is_numeric($nivel) || (float) $nivel < 0 || (float) $nivel > 100) {
        throw new ErrorHttp(422, 'Indica la carga/combustible en % (de 0 a 100)');
    }
    if (!in_array($finalidad, ['Exclusivamente profesional', 'Profesional y uso personal autorizado'], true)) {
        throw new ErrorHttp(422, 'Elige la finalidad de la cesión');
    }

    // Reason: si la cuenta ya se creó con los datos de la persona, mandan
    // los de la cuenta (no se le vuelven a pedir ni puede cambiarlos aquí).
    $perfil = turismo_perfil_usuario($pdo, (int) $usuario['id']);
    if ($perfil !== null) {
        $nombre   = trim((string) $perfil['nombre']) ?: $nombre;
        $telefono = trim((string) ($perfil['telefono'] ?? '')) ?: $telefono;
        $dni      = trim((string) ($perfil['dni'] ?? '')) ?: $dni;
        $caduca = trim((string) ($perfil['permiso_caduca'] ?? ''));
        if ($caduca !== '' && $caduca < date('Y-m-d')) {
            throw new ErrorHttp(422, 'Tu permiso de conducir figura caducado desde el '
                . DateTimeImmutable::createFromFormat('!Y-m-d', $caduca)->format('d/m/Y')
                . '. Habla con el responsable para actualizarlo.');
        }
    }

    if ($nombre === '' || $telefono === '' || $motivo === '') {
        throw new ErrorHttp(422, 'Nombre, teléfono y motivo son obligatorios');
    }
    if (empty($cuerpo['acepta_contrato'])) {
        throw new ErrorHttp(422, 'Debes leer y aceptar el contrato de cesión');
    }
    if (!str_starts_with($firma, 'data:image/png;base64,') || strlen($firma) < 500) {
        throw new ErrorHttp(422, 'Falta la firma: firma con el dedo dentro del recuadro');
    }
    if (strlen($firma) > 500000) {
        throw new ErrorHttp(422, 'La firma es demasiado grande');
    }

    $veh = turismo_vehiculo_fila($pdo, $vehiculo_id);
    if ($veh['estado'] !== 'disponible') {
        throw new ErrorHttp(409, "El vehículo {$veh['matricula']} no está disponible ({$veh['estado']})");
    }
    $stmt = $pdo->prepare('SELECT id FROM turismo_solicitud WHERE vehiculo_id = ? AND fecha_devolucion IS NULL');
    $stmt->execute([$vehiculo_id]);
    if ($stmt->fetchColumn() !== false) {
        throw new ErrorHttp(409, "El vehículo {$veh['matricula']} ya está en uso por otra persona");
    }

    [$lat, $lng] = turismo_coordenadas($cuerpo);
    $contrato = turismos_contrato_relleno([
        'nombre'     => $nombre,
        'telefono'   => $telefono,
        'dni'        => $dni,
        'email'      => (string) ($perfil['email'] ?? ''),
        'domicilio'  => (string) ($perfil['domicilio'] ?? ''),
        'permiso'    => (string) ($perfil['permiso'] ?? ''),
        'clase'      => (string) ($perfil['clase_permiso'] ?? ''),
        'caducidad'  => (string) ($perfil['permiso_caduca'] ?? ''),
        'empleado'   => $perfil !== null ? (bool) $perfil['empleado'] : null,
        'km'         => $km,
        'nivel'      => $nivel,
        'accesorios' => $accesorios,
        'danos'      => $danos,
        'finalidad'  => $finalidad,
    ], $veh);
    $token = bin2hex(random_bytes(16));

    $pdo->prepare(
        'INSERT INTO turismo_solicitud (vehiculo_id, usuario_id, nombre, telefono, dni, motivo,
             km, nivel, accesorios, danos, finalidad,
             fecha_solicitud, lat_solicitud, lng_solicitud, contrato, firma, ip, user_agent, token)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $vehiculo_id, (int) $usuario['id'], $nombre, $telefono, $dni ?: null, $motivo,
        $km, $nivel, $accesorios, $danos, $finalidad,
        date('Y-m-d H:i:s'), $lat, $lng, $contrato, $firma,
        substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        $token,
    ]);
    http_response_code(201);
    return turismo_solicitud_a_read(turismo_solicitud_fila($pdo, (int) $pdo->lastInsertId()));
}

/** Marca la devolución (desde la app o desde el enlace del email). */
function turismo_devolver(PDO $pdo, array $sol, array $cuerpo, string $via): array
{
    if ($sol['fecha_devolucion'] !== null) {
        throw new ErrorHttp(409, "Este coche ya se marcó como devuelto el {$sol['fecha_devolucion']}");
    }
    [$lat, $lng] = turismo_coordenadas($cuerpo);
    $pdo->prepare(
        'UPDATE turismo_solicitud SET fecha_devolucion = ?, lat_devolucion = ?, lng_devolucion = ?, devuelto_via = ?
         WHERE id = ?'
    )->execute([date('Y-m-d H:i:s'), $lat, $lng, $via, $sol['id']]);

    // El periodo de cesión del contrato archivado se completa al devolver:
    // el hueco "hasta ..." pasa a ser la fecha y hora reales de devolución.
    if (!empty($sol['contrato']) && str_contains((string) $sol['contrato'], TURISMO_PENDIENTE_DEVOLUCION)) {
        $contrato = str_replace(TURISMO_PENDIENTE_DEVOLUCION, date('d/m/Y H:i'), (string) $sol['contrato']);
        $pdo->prepare('UPDATE turismo_solicitud SET contrato = ? WHERE id = ?')
            ->execute([$contrato, $sol['id']]);
    }
    return turismo_solicitud_a_read(turismo_solicitud_fila($pdo, (int) $sol['id']));
}

/** Lee lat/lng del cuerpo si vienen (la ubicación puede denegarse en el móvil). */
function turismo_coordenadas(array $cuerpo): array
{
    $lat = isset($cuerpo['lat']) && is_numeric($cuerpo['lat']) ? (float) $cuerpo['lat'] : null;
    $lng = isset($cuerpo['lng']) && is_numeric($cuerpo['lng']) ? (float) $cuerpo['lng'] : null;
    if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
        return [null, null];
    }
    return [$lat, $lng];
}

// ---------- usuarios de vehículos ----------

/** Valida los datos completos de un usuario de vehículos (alta o edición). */
function turismo_usuario_validar(array $cuerpo, bool $es_alta): array
{
    $nombre    = trim((string) ($cuerpo['nombre'] ?? ''));
    $email     = strtolower(trim((string) ($cuerpo['email'] ?? '')));
    $dni       = strtoupper(trim((string) ($cuerpo['dni'] ?? '')));
    $telefono  = trim((string) ($cuerpo['telefono'] ?? ''));
    $domicilio = trim((string) ($cuerpo['domicilio'] ?? ''));
    $permiso   = strtoupper(trim((string) ($cuerpo['permiso'] ?? '')));
    $clase     = strtoupper(trim((string) ($cuerpo['clase_permiso'] ?? '')));
    $caduca    = trim((string) ($cuerpo['permiso_caduca'] ?? ''));
    $empleado  = !empty($cuerpo['empleado']) ? 1 : 0;

    if ($nombre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ErrorHttp(422, 'Hacen falta el nombre y un email válido');
    }
    // Reason: con todos los datos en la cuenta, el contrato de cesión sale
    // completo y al solicitar un coche solo se pregunta el motivo y el
    // estado del vehículo.
    if ($dni === '' || $telefono === '' || $domicilio === '' || $permiso === '' || $clase === '') {
        throw new ErrorHttp(422, 'Faltan datos: DNI, teléfono, domicilio y permiso de conducir (nº y clase) son necesarios para el contrato');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $caduca)) {
        throw new ErrorHttp(422, 'Indica hasta cuándo es válido el permiso de conducir');
    }
    // Reason: en una edición se permite guardar con el permiso ya vencido
    // (p. ej. corregir un teléfono); la solicitud de coche sigue bloqueada
    // hasta que se actualice la fecha del permiso.
    if ($es_alta && $caduca < date('Y-m-d')) {
        throw new ErrorHttp(422, 'Ese permiso de conducir ya está caducado');
    }
    return [$nombre, $email, $dni, $telefono, $domicilio, $permiso, $clase, $caduca, $empleado];
}

/** Lista completa para el panel del admin (con si tiene coches sin devolver). */
function turismos_usuarios_listar(PDO $pdo): array
{
    $filas = $pdo->query(
        'SELECT t.usuario_id AS id, t.nombre, t.email, t.dni, t.telefono, t.domicilio,
                t.permiso, t.clase_permiso, t.permiso_caduca, t.empleado, u.username,
                (SELECT COUNT(*) FROM turismo_solicitud s
                  WHERE s.usuario_id = t.usuario_id AND s.fecha_devolucion IS NULL) AS coches_en_uso
         FROM turismo_usuario t
         JOIN usuario u ON u.id = t.usuario_id
         ORDER BY t.nombre'
    )->fetchAll();
    return array_map(function (array $f): array {
        $f['id'] = (int) $f['id'];
        $f['empleado'] = (bool) $f['empleado'];
        $f['coches_en_uso'] = (int) $f['coches_en_uso'];
        return $f;
    }, $filas);
}

/** POST /api/turismos/usuarios — botón "Crear usuario de vehículos". */
function turismo_usuario_crear(PDO $pdo, array $cuerpo): array
{
    [$nombre, $email, $dni, $telefono, $domicilio, $permiso, $clase, $caduca, $empleado] =
        turismo_usuario_validar($cuerpo, true);
    $stmt = $pdo->prepare('SELECT id FROM turismo_usuario WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() !== false) {
        throw new ErrorHttp(409, 'Ya hay un usuario de vehículos con ese email');
    }

    $base = strtolower(trim((string) iconv('UTF-8', 'ASCII//TRANSLIT', $nombre)));
    $base = str_replace(' ', '.', trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]/', '', $base))));
    $candidato = $base !== '' ? $base : 'vehiculos';
    $sufijo = 1;
    $stmt = $pdo->prepare('SELECT id FROM usuario WHERE username = ?');
    while (true) {
        $stmt->execute([$candidato]);
        if ($stmt->fetchColumn() === false) {
            break;
        }
        $sufijo++;
        $candidato = $base . $sufijo;
    }

    $password = generar_password();
    $pdo->prepare('INSERT INTO usuario (username, password_hash, rol, chofer_id) VALUES (?, ?, ?, NULL)')
        ->execute([$candidato, password_hash($password, PASSWORD_DEFAULT), 'vehiculos']);
    $pdo->prepare(
        'INSERT INTO turismo_usuario (usuario_id, nombre, email, dni, telefono,
             domicilio, permiso, clase_permiso, permiso_caduca, empleado)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([(int) $pdo->lastInsertId(), $nombre, $email, $dni, $telefono,
                $domicilio, $permiso, $clase, $caduca, $empleado]);

    http_response_code(201);
    return ['username' => $candidato, 'password' => $password, 'nombre' => $nombre, 'email' => $email];
}

/** PUT /api/turismos/usuarios/{usuario_id} — editar los datos de la cuenta. */
function turismo_usuario_actualizar(PDO $pdo, int $usuario_id, array $cuerpo): array
{
    $stmt = $pdo->prepare('SELECT id FROM turismo_usuario WHERE usuario_id = ?');
    $stmt->execute([$usuario_id]);
    if ($stmt->fetchColumn() === false) {
        throw new ErrorHttp(404, 'Usuario de vehículos no encontrado');
    }
    [$nombre, $email, $dni, $telefono, $domicilio, $permiso, $clase, $caduca, $empleado] =
        turismo_usuario_validar($cuerpo, false);
    $stmt = $pdo->prepare('SELECT id FROM turismo_usuario WHERE email = ? AND usuario_id <> ?');
    $stmt->execute([$email, $usuario_id]);
    if ($stmt->fetchColumn() !== false) {
        throw new ErrorHttp(409, 'Ya hay otro usuario de vehículos con ese email');
    }
    $pdo->prepare(
        'UPDATE turismo_usuario SET nombre = ?, email = ?, dni = ?, telefono = ?,
             domicilio = ?, permiso = ?, clase_permiso = ?, permiso_caduca = ?, empleado = ?
         WHERE usuario_id = ?'
    )->execute([$nombre, $email, $dni, $telefono, $domicilio, $permiso, $clase, $caduca, $empleado, $usuario_id]);

    foreach (turismos_usuarios_listar($pdo) as $u) {
        if ($u['id'] === $usuario_id) {
            return $u;
        }
    }
    throw new ErrorHttp(404, 'Usuario de vehículos no encontrado');
}

/** DELETE /api/turismos/usuarios/{usuario_id} — borrar cuenta de vehículos. */
function turismo_usuario_eliminar(PDO $pdo, int $usuario_id): mixed
{
    $stmt = $pdo->prepare('SELECT nombre FROM turismo_usuario WHERE usuario_id = ?');
    $stmt->execute([$usuario_id]);
    if ($stmt->fetchColumn() === false) {
        throw new ErrorHttp(404, 'Usuario de vehículos no encontrado');
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM turismo_solicitud WHERE usuario_id = ? AND fecha_devolucion IS NULL');
    $stmt->execute([$usuario_id]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new ErrorHttp(409, 'Esta persona tiene un coche sin devolver: registra la devolución antes de borrar su cuenta');
    }
    // Reason: se borra la cuenta de acceso pero NO su historial de
    // solicitudes, que debe conservarse para las auditorías (las solicitudes
    // guardan copia de nombre, DNI, contrato y firma).
    $pdo->prepare('DELETE FROM turismo_usuario WHERE usuario_id = ?')->execute([$usuario_id]);
    $pdo->prepare("DELETE FROM usuario WHERE id = ? AND rol = 'vehiculos'")->execute([$usuario_id]);
    http_response_code(204);
    return null;
}

// ---------- contrato ----------

/** Plantilla del contrato con los datos fijos de la empresa ya rellenos. */
function turismos_contrato_plantilla(): string
{
    $ruta = __DIR__ . '/../contrato_cesion.md';
    $texto = file_exists($ruta)
        ? (string) file_get_contents($ruta)
        : "# CONTRATO DE CESIÓN TEMPORAL DE USO DE VEHÍCULO\n\n(No se encontró contrato_cesion.md)";
    return strtr($texto, [
        '{{EMPRESA}}'           => 'IR MAXOINVERSIONES, S.L.U. / H2GORENT, S.L.',
        '{{CIF}}'               => 'B-76185586 / B-19926344',
        '{{DOMICILIO_EMPRESA}}' => 'Puerto del Rosario, Fuerteventura',
        '{{LUGAR}}'             => 'Puerto del Rosario (Fuerteventura)',
        '{{ISLA}}'              => 'Fuerteventura',
    ]);
}

/** Contrato definitivo que se archiva con la solicitud (prueba de la firma). */
function turismos_contrato_relleno(array $d, array $veh): string
{
    $caduca = '';
    if (!empty($d['caducidad']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['caducidad'])) {
        $caduca = DateTimeImmutable::createFromFormat('!Y-m-d', $d['caducidad'])->format('d/m/Y');
    }
    $relacion = '________';
    if ($d['empleado'] !== null) {
        $relacion = $d['empleado'] ? '☑ Empleado' : '☑ Colaborador/tercero (no empleado)';
    }
    $texto = strtr(turismos_contrato_plantilla(), [
        '{{NOMBRE_APELLIDOS}}'       => $d['nombre'],
        '{{TELEFONO}}'               => $d['telefono'],
        '{{DNI}}'                    => $d['dni'] ?: '________',
        '{{EMAIL}}'                  => $d['email'] ?: '________',
        '{{DOMICILIO}}'              => $d['domicilio'] ?: '________',
        '{{PERMISO}}'                => $d['permiso'] ?: '________',
        '{{CLASE}}'                  => $d['clase'] ?: '________',
        '{{CADUCIDAD}}'              => $caduca ?: '________',
        '{{RELACION}}'               => $relacion,
        '{{KM}}'                     => $d['km'],
        '{{NIVEL}}'                  => $d['nivel'],
        '{{ACCESORIOS}}'             => $d['accesorios'],
        '{{DANOS_PREVIOS}}'          => $d['danos'],
        '{{FINALIDAD}}'              => $d['finalidad'],
        '{{MATRICULA}}'              => $veh['matricula'],
        '{{MARCA_MODELO}}'           => $veh['modelo'] ?: '—',
        '{{FECHA_HORA_ENTREGA}}'     => date('d/m/Y H:i'),
        '{{FECHA_HORA_DEVOLUCION}}'  => TURISMO_PENDIENTE_DEVOLUCION,
        '{{FECHA}}'                  => date('d/m/Y'),
    ]);
    // Reason: los campos sin dato (DNI, franquicia…) quedan como raya para
    // que el documento archivado sea legible y no muestre {{PLACEHOLDERS}}.
    return (string) preg_replace('/\{\{[A-Z_]+\}\}/', '________', $texto);
}

// ---------- aviso de los 7 días ----------

/** Guarda la URL pública de la app para armar los enlaces de los emails. */
function turismos_guardar_url_base(PDO $pdo): void
{
    if (empty($_SERVER['HTTP_HOST'])) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $pos = strpos($ruta, '/api/');
    $base = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
        . ($pos === false ? '/' : substr($ruta, 0, $pos + 1));
    $stmt = $pdo->prepare('SELECT valor FROM ajuste WHERE clave = ?');
    $stmt->execute(['url_app']);
    $actual = $stmt->fetchColumn();
    if ($actual === false) {
        $pdo->prepare('INSERT INTO ajuste (clave, valor) VALUES (?, ?)')->execute(['url_app', $base]);
    } elseif ($actual !== $base) {
        $pdo->prepare('UPDATE ajuste SET valor = ? WHERE clave = ?')->execute([$base, 'url_app']);
    }
}

/**
 * Si un coche lleva más de 7 días sin devolverse, se le manda un email al
 * solicitante con un enlace directo para confirmar la devolución.
 * Se dispara con las visitas (máx. cada 12 h) y desde cron.php.
 */
function turismos_chequeo_avisos(PDO $pdo): void
{
    if (!correo_configurado()) {
        return;
    }
    turismos_asegurar_esquema($pdo);

    $stmt = $pdo->prepare('SELECT valor FROM ajuste WHERE clave = ?');
    $stmt->execute(['ultimo_chequeo_turismos']);
    $ultimo = $stmt->fetchColumn();
    if ($ultimo !== false && time() - (int) $ultimo < 12 * 3600) {
        return;
    }
    if ($ultimo === false) {
        $pdo->prepare('INSERT INTO ajuste (clave, valor) VALUES (?, ?)')
            ->execute(['ultimo_chequeo_turismos', (string) time()]);
    } else {
        $pdo->prepare('UPDATE ajuste SET valor = ? WHERE clave = ?')
            ->execute([(string) time(), 'ultimo_chequeo_turismos']);
    }

    $cfg = config();
    $admin = $cfg['email_admin'] ?: ($cfg['smtp']['usuario'] ?: ($cfg['email_remitente'] ?? ''));
    $stmt = $pdo->prepare('SELECT valor FROM ajuste WHERE clave = ?');
    $stmt->execute(['url_app']);
    $base = (string) ($stmt->fetchColumn() ?: '');

    $limite = date('Y-m-d H:i:s', time() - 7 * 86400);
    $relimite = date('Y-m-d', time() - 7 * 86400);
    $stmt = $pdo->prepare(
        'SELECT s.*, v.matricula, v.modelo, t.email AS email_usuario
         FROM turismo_solicitud s
         JOIN vehiculo v ON v.id = s.vehiculo_id
         LEFT JOIN turismo_usuario t ON t.usuario_id = s.usuario_id
         WHERE s.fecha_devolucion IS NULL AND s.fecha_solicitud <= ?
           AND (s.ultimo_aviso IS NULL OR s.ultimo_aviso <= ?)'
    );
    $stmt->execute([$limite, $relimite]);

    foreach ($stmt->fetchAll() as $sol) {
        $destino = $sol['email_usuario'] ?: $admin;
        if ($destino === '') {
            continue;
        }
        $dias = (int) floor((time() - strtotime($sol['fecha_solicitud'])) / 86400);
        $enlace = $base !== '' ? $base . 'index.php?devolucion=' . $sol['token'] : '(abre la app de Gestión de Flota)';
        $fecha = date('d/m/Y', strtotime($sol['fecha_solicitud']));
        $cuerpo = "Hola {$sol['nombre']},\n\n"
            . "El $fecha solicitaste el coche {$sol['matricula']}"
            . ($sol['modelo'] ? " ({$sol['modelo']})" : '')
            . " y todavía no consta su devolución en la app (han pasado $dias días).\n\n"
            . "¿Devolviste el coche y no lo pusiste en la app? Ponlo aquí:\n\n"
            . "$enlace\n\n"
            . "Si todavía lo estás usando, no hace falta que hagas nada: cuando lo\n"
            . "devuelvas, confírmalo en la app o con ese mismo enlace.\n\n"
            . '— Gestión de Flota (mensaje automático)';
        try {
            enviar_email($destino, "🚗 ¿Devolviste el coche {$sol['matricula']}? Confírmalo en la app", $cuerpo);
            $pdo->prepare('UPDATE turismo_solicitud SET ultimo_aviso = ? WHERE id = ?')
                ->execute([date('Y-m-d'), $sol['id']]);
        } catch (Throwable $e) {
            error_log('Gestión de Flota (turismos): error avisando devolución: ' . $e->getMessage());
        }
    }
}
