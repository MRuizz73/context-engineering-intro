<?php
/**
 * Endpoints de choferes, camiones y documentos (con reglas por rol).
 */
declare(strict_types=1);

/** Días de diferencia entre dos fechas Y-m-d (negativo si $hasta ya pasó). */
function dias_entre(string $desde, string $hasta): int
{
    $a = new DateTimeImmutable($desde);
    $b = new DateTimeImmutable($hasta);
    return (int) $a->diff($b)->format('%r%a');
}

/** Todos los documentos con titular, estado y email de destino calculados. */
function documentos_con_titular(PDO $pdo, ?int $solo_chofer_id = null): array
{
    $sql = 'SELECT d.*, c.nombre AS ch_nombre, c.apellido AS ch_apellido, c.email AS ch_email,
                   m.patente AS ca_patente
            FROM documento d
            LEFT JOIN chofer c ON d.chofer_id = c.id
            LEFT JOIN camion m ON d.camion_id = m.id';
    $parametros = [];
    if ($solo_chofer_id !== null) {
        $sql .= ' WHERE d.chofer_id = ?';
        $parametros[] = $solo_chofer_id;
    }
    $sql .= ' ORDER BY d.fecha_vencimiento';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);

    $hoy = date('Y-m-d');
    return array_map(function (array $f) use ($hoy): array {
        $dias = dias_entre($hoy, $f['fecha_vencimiento']);
        if ($dias < 0) {
            $estado = 'vencido';
        } elseif ($dias <= (int) $f['dias_aviso']) {
            $estado = 'por_vencer';
        } else {
            $estado = 'vigente';
        }
        if ($f['chofer_id'] !== null) {
            $titular = "{$f['ch_apellido']}, {$f['ch_nombre']}";
        } elseif ($f['camion_id'] !== null) {
            $titular = "Camión {$f['ca_patente']}";
        } else {
            $titular = 'Sin titular';
        }
        return [
            'id'                 => (int) $f['id'],
            'nombre'             => $f['nombre'],
            'tipo'               => $f['tipo'],
            'fecha_emision'      => $f['fecha_emision'],
            'fecha_vencimiento'  => $f['fecha_vencimiento'],
            'dias_aviso'         => (int) $f['dias_aviso'],
            'notas'              => $f['notas'],
            'chofer_id'          => $f['chofer_id'] !== null ? (int) $f['chofer_id'] : null,
            'camion_id'          => $f['camion_id'] !== null ? (int) $f['camion_id'] : null,
            'estado'             => $estado,
            'dias_restantes'     => $dias,
            'titular'            => $titular,
            'email_destino'      => $f['ch_email'] ?: null,
            'ultimo_aviso_email' => $f['ultimo_aviso_email'],
        ];
    }, $stmt->fetchAll());
}

/** Documentos de la respuesta pública (sin campos internos). */
function doc_publico(array $d): array
{
    unset($d['ultimo_aviso_email']);
    return $d;
}

function chofer_a_read(PDO $pdo, array $c, ?array $docs = null): array
{
    $docs = $docs ?? array_map('doc_publico', documentos_con_titular($pdo, (int) $c['id']));
    return [
        'id'         => (int) $c['id'],
        'nombre'     => $c['nombre'],
        'apellido'   => $c['apellido'],
        'dni'        => $c['dni'],
        'telefono'   => $c['telefono'],
        'email'      => $c['email'],
        'activo'     => (bool) $c['activo'],
        'documentos' => $docs,
    ];
}

function camion_a_read(array $c, array $docs): array
{
    return [
        'id'         => (int) $c['id'],
        'patente'    => $c['patente'],
        'marca'      => $c['marca'],
        'modelo'     => $c['modelo'],
        'anio'       => $c['anio'] !== null ? (int) $c['anio'] : null,
        'activo'     => (bool) $c['activo'],
        'documentos' => $docs,
    ];
}

/** Agrupa todos los documentos por titular para armar los listados. */
function docs_agrupados(PDO $pdo): array
{
    $por_chofer = [];
    $por_camion = [];
    foreach (documentos_con_titular($pdo) as $d) {
        if ($d['chofer_id'] !== null) {
            $por_chofer[$d['chofer_id']][] = doc_publico($d);
        } elseif ($d['camion_id'] !== null) {
            $por_camion[$d['camion_id']][] = doc_publico($d);
        }
    }
    return [$por_chofer, $por_camion];
}

// ---------- choferes ----------

function choferes_listar(PDO $pdo): array
{
    requiere_admin();
    [$por_chofer] = docs_agrupados($pdo);
    $filas = $pdo->query('SELECT * FROM chofer ORDER BY apellido, nombre')->fetchAll();
    return array_map(
        fn($c) => chofer_a_read($pdo, $c, $por_chofer[(int) $c['id']] ?? []),
        $filas
    );
}

function chofer_obtener_fila(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM chofer WHERE id = ?');
    $stmt->execute([$id]);
    $fila = $stmt->fetch();
    if ($fila === false) {
        throw new ErrorHttp(404, 'Chófer no encontrado');
    }
    return $fila;
}

function verificar_acceso_chofer(array $usuario, int $chofer_id): void
{
    if ($usuario['rol'] !== 'admin' && (int) $usuario['chofer_id'] !== $chofer_id) {
        throw new ErrorHttp(403, 'Solo puedes ver tu propio perfil');
    }
}

function validar_chofer(array $c): array
{
    $nombre = trim((string) ($c['nombre'] ?? ''));
    $apellido = trim((string) ($c['apellido'] ?? ''));
    $dni = trim((string) ($c['dni'] ?? ''));
    if ($nombre === '' || $apellido === '' || $dni === '') {
        throw new ErrorHttp(422, 'Nombre, apellido y DNI son obligatorios');
    }
    return [
        $nombre, $apellido, $dni,
        trim((string) ($c['telefono'] ?? '')) ?: null,
        trim((string) ($c['email'] ?? '')) ?: null,
        !empty($c['activo']) ? 1 : 0,
    ];
}

function chofer_crear(PDO $pdo, array $cuerpo): array
{
    requiere_admin();
    $v = validar_chofer($cuerpo);
    $stmt = $pdo->prepare('SELECT id FROM chofer WHERE dni = ?');
    $stmt->execute([$v[2]]);
    if ($stmt->fetchColumn() !== false) {
        throw new ErrorHttp(409, 'Ya existe un chófer con ese DNI');
    }
    $pdo->prepare('INSERT INTO chofer (nombre, apellido, dni, telefono, email, activo) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute($v);
    http_response_code(201);
    return chofer_a_read($pdo, chofer_obtener_fila($pdo, (int) $pdo->lastInsertId()));
}

function chofer_actualizar(PDO $pdo, int $id, array $cuerpo, array $usuario): array
{
    verificar_acceso_chofer($usuario, $id);
    chofer_obtener_fila($pdo, $id);
    $v = validar_chofer($cuerpo);
    $stmt = $pdo->prepare('SELECT id FROM chofer WHERE dni = ? AND id <> ?');
    $stmt->execute([$v[2], $id]);
    if ($stmt->fetchColumn() !== false) {
        throw new ErrorHttp(409, 'Ya existe un chófer con ese DNI');
    }
    $v[] = $id;
    $pdo->prepare('UPDATE chofer SET nombre = ?, apellido = ?, dni = ?, telefono = ?, email = ?, activo = ? WHERE id = ?')
        ->execute($v);
    return chofer_a_read($pdo, chofer_obtener_fila($pdo, $id));
}

// ---------- camiones ----------

function validar_camion(array $c): array
{
    $patente = strtoupper(trim((string) ($c['patente'] ?? '')));
    if ($patente === '') {
        throw new ErrorHttp(422, 'La matrícula es obligatoria');
    }
    return [
        $patente,
        trim((string) ($c['marca'] ?? '')) ?: null,
        trim((string) ($c['modelo'] ?? '')) ?: null,
        isset($c['anio']) && $c['anio'] !== null && $c['anio'] !== '' ? (int) $c['anio'] : null,
        !empty($c['activo']) ? 1 : 0,
    ];
}

function camion_obtener_fila(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM camion WHERE id = ?');
    $stmt->execute([$id]);
    $fila = $stmt->fetch();
    if ($fila === false) {
        throw new ErrorHttp(404, 'Camión no encontrado');
    }
    return $fila;
}

function camiones_listar(PDO $pdo): array
{
    [, $por_camion] = docs_agrupados($pdo);
    $filas = $pdo->query('SELECT * FROM camion ORDER BY patente')->fetchAll();
    return array_map(
        fn($c) => camion_a_read($c, $por_camion[(int) $c['id']] ?? []),
        $filas
    );
}

function camion_crear(PDO $pdo, array $cuerpo): array
{
    $v = validar_camion($cuerpo);
    $stmt = $pdo->prepare('SELECT id FROM camion WHERE patente = ?');
    $stmt->execute([$v[0]]);
    if ($stmt->fetchColumn() !== false) {
        throw new ErrorHttp(409, 'Ya existe un camión con esa matrícula');
    }
    $pdo->prepare('INSERT INTO camion (patente, marca, modelo, anio, activo) VALUES (?, ?, ?, ?, ?)')
        ->execute($v);
    http_response_code(201);
    return camion_a_read(camion_obtener_fila($pdo, (int) $pdo->lastInsertId()), []);
}

function camion_actualizar(PDO $pdo, int $id, array $cuerpo): array
{
    camion_obtener_fila($pdo, $id);
    $v = validar_camion($cuerpo);
    $stmt = $pdo->prepare('SELECT id FROM camion WHERE patente = ? AND id <> ?');
    $stmt->execute([$v[0], $id]);
    if ($stmt->fetchColumn() !== false) {
        throw new ErrorHttp(409, 'Ya existe un camión con esa matrícula');
    }
    $v[] = $id;
    $pdo->prepare('UPDATE camion SET patente = ?, marca = ?, modelo = ?, anio = ?, activo = ? WHERE id = ?')
        ->execute($v);
    [, $por_camion] = docs_agrupados($pdo);
    return camion_a_read(camion_obtener_fila($pdo, $id), $por_camion[$id] ?? []);
}

// ---------- documentos ----------

function validar_documento(array $c): array
{
    $nombre = trim((string) ($c['nombre'] ?? ''));
    $venc = (string) ($c['fecha_vencimiento'] ?? '');
    if ($nombre === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $venc)) {
        throw new ErrorHttp(422, 'Nombre y fecha de vencimiento son obligatorios');
    }
    $chofer_id = isset($c['chofer_id']) && $c['chofer_id'] ? (int) $c['chofer_id'] : null;
    $camion_id = isset($c['camion_id']) && $c['camion_id'] ? (int) $c['camion_id'] : null;
    if (($chofer_id === null) === ($camion_id === null)) {
        throw new ErrorHttp(422, 'El documento debe pertenecer a un chófer O a un camión (exactamente uno).');
    }
    $dias_aviso = (int) ($c['dias_aviso'] ?? 30);
    if ($dias_aviso < 0) {
        throw new ErrorHttp(422, 'dias_aviso no puede ser negativo');
    }
    return [
        $nombre,
        trim((string) ($c['tipo'] ?? 'curso')) ?: 'curso',
        ($c['fecha_emision'] ?? null) ?: null,
        $venc,
        $dias_aviso,
        trim((string) ($c['notas'] ?? '')) ?: null,
        $chofer_id,
        $camion_id,
    ];
}

function documento_buscar(PDO $pdo, int $id): array
{
    foreach (documentos_con_titular($pdo) as $d) {
        if ($d['id'] === $id) {
            return $d;
        }
    }
    throw new ErrorHttp(404, 'Documento no encontrado');
}

function documento_crear(PDO $pdo, array $cuerpo, array $usuario): array
{
    $v = validar_documento($cuerpo);
    if ($usuario['rol'] !== 'admin' && $v[6] !== (int) $usuario['chofer_id']) {
        throw new ErrorHttp(403, 'Solo puedes añadir cursos a tu propio perfil');
    }
    if ($v[6] !== null) {
        chofer_obtener_fila($pdo, $v[6]);
    }
    if ($v[7] !== null) {
        camion_obtener_fila($pdo, $v[7]);
    }
    $pdo->prepare('INSERT INTO documento (nombre, tipo, fecha_emision, fecha_vencimiento, dias_aviso, notas, chofer_id, camion_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute($v);
    http_response_code(201);
    return doc_publico(documento_buscar($pdo, (int) $pdo->lastInsertId()));
}

function documento_actualizar(PDO $pdo, int $id, array $cuerpo): array
{
    requiere_admin();
    documento_buscar($pdo, $id);
    $v = validar_documento($cuerpo);
    $v[] = $id;
    $pdo->prepare('UPDATE documento SET nombre = ?, tipo = ?, fecha_emision = ?, fecha_vencimiento = ?,
                   dias_aviso = ?, notas = ?, chofer_id = ?, camion_id = ? WHERE id = ?')->execute($v);
    return doc_publico(documento_buscar($pdo, $id));
}

function documento_renovar(PDO $pdo, int $id, array $cuerpo, array $usuario): array
{
    $doc = documento_buscar($pdo, $id);
    if ($usuario['rol'] !== 'admin' && $doc['chofer_id'] !== (int) $usuario['chofer_id']) {
        throw new ErrorHttp(403, 'Solo puedes renovar tus propios documentos');
    }
    $venc = (string) ($cuerpo['fecha_vencimiento'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $venc) || $venc <= date('Y-m-d')) {
        throw new ErrorHttp(422, 'La nueva fecha de vencimiento debe ser posterior a hoy');
    }
    $emision = ($cuerpo['fecha_emision'] ?? null) ?: date('Y-m-d');
    $pdo->prepare('UPDATE documento SET fecha_emision = ?, fecha_vencimiento = ?, ultimo_aviso_email = NULL WHERE id = ?')
        ->execute([$emision, $venc, $id]);
    return doc_publico(documento_buscar($pdo, $id));
}

function documento_eliminar(PDO $pdo, int $id): void
{
    requiere_admin();
    documento_buscar($pdo, $id);
    $pdo->prepare('DELETE FROM documento WHERE id = ?')->execute([$id]);
    http_response_code(204);
}
