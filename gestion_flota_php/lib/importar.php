<?php
/**
 * Importador del CSV de cursos y vencimientos (Curso,Fecha,Hora,Detalle).
 * Mismo comportamiento que la versión original: crea choferes y camiones
 * por matrícula, deduplica citas repetidas y guarda las notas.
 */
declare(strict_types=1);

const CURSOS_VEHICULO = [
    'ITV'            => 'revision_tecnica',
    'ADR (vehiculo)' => 'permiso',
    'Tacografo'      => 'otro',
    'Extintores'     => 'otro',
    'Seguro'         => 'seguro',
];

const CURSOS_CHOFER = [
    'Certificado Medico'  => 'otro',
    'CAP Conductor'       => 'curso',
    'Permiso de Conducir' => 'licencia',
    'Online Curso'        => 'curso',
    'Curso Terminales'    => 'curso',
    'DNI'                 => 'otro',
    'ADR Conductor'       => 'licencia',
];

const NOMBRES_UNIFICADOS = [
    'Jonathan Quintero'      => 'Jonathan Quinteros',
    'Raul Ivan Rodriguez'    => 'Raul Rodriguez',
    'Francisco Javier Castro' => 'Francisco Castro',
];

/** Importa el CSV y devuelve un resumen con contadores y filas omitidas. */
function importar_csv(PDO $pdo, string $ruta, ?string $hoy = null): array
{
    $hoy = $hoy ?? date('Y-m-d');
    $grupos = [];
    $omitidas = [];

    $archivo = fopen($ruta, 'r');
    $cabecera = fgetcsv($archivo, 0, ',', '"', '\\');
    $cabecera[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cabecera[0]);
    while (($fila = fgetcsv($archivo, 0, ',', '"', '\\')) !== false) {
        $datos = array_combine($cabecera, array_pad($fila, count($cabecera), ''));
        $curso = trim($datos['Curso'] ?? '');
        $fecha = DateTimeImmutable::createFromFormat('!d/m/Y', trim($datos['Fecha'] ?? ''));
        if ($fecha === false) {
            $omitidas[] = "Fecha inválida: " . json_encode($datos);
            continue;
        }
        $hora = trim($datos['Hora'] ?? '');
        [$titulares, $nota] = separar_detalle(trim($datos['Detalle'] ?? ''));

        if (isset(CURSOS_VEHICULO[$curso])) {
            $tipo_titular = 'camion';
        } elseif (isset(CURSOS_CHOFER[$curso])) {
            $tipo_titular = 'chofer';
        } else {
            $omitidas[] = "$curso ({$datos['Fecha']}): {$datos['Detalle']}";
            continue;
        }
        if ($titulares === []) {
            $omitidas[] = "$curso ({$datos['Fecha']}): sin titular";
            continue;
        }
        foreach ($titulares as $titular) {
            $titular = NOMBRES_UNIFICADOS[$titular] ?? $titular;
            $clave = "$curso|$tipo_titular|$titular";
            $grupos[$clave][] = [$fecha->format('Y-m-d'), $hora, $nota];
        }
    }
    fclose($archivo);

    $contador_dni = 0;
    $documentos = 0;
    ksort($grupos);
    foreach ($grupos as $clave => $citas) {
        [$curso, $tipo_titular, $titular] = explode('|', $clave, 3);
        $fechas = array_column($citas, 0);
        $elegida = elegir_fecha($fechas, $hoy);
        [$hora, $nota] = [null, null];
        foreach ($citas as $cita) {
            if ($cita[0] === $elegida) {
                [, $hora, $nota] = $cita;
                break;
            }
        }

        $notas = [];
        if ($nota) {
            $notas[] = "Nota del calendario: $nota";
        }
        if ($hora && strtolower($hora) !== 'todo el dia') {
            $notas[] = "Cita: $hora";
        }
        $otras = array_filter($fechas, fn($f) => $f !== $elegida);
        sort($otras);
        if ($otras !== []) {
            $legibles = array_map(
                fn($f) => DateTimeImmutable::createFromFormat('!Y-m-d', $f)->format('d/m/Y'),
                array_unique($otras)
            );
            $notas[] = 'Otras fechas del calendario: ' . implode(', ', $legibles);
        }

        $doc = [
            'nombre'            => $curso,
            'tipo'              => CURSOS_VEHICULO[$curso] ?? CURSOS_CHOFER[$curso],
            'fecha_vencimiento' => $elegida,
            'dias_aviso'        => 30,
            'notas'             => $notas === [] ? null : implode('; ', $notas),
            'chofer_id'         => null,
            'camion_id'         => null,
        ];
        if ($tipo_titular === 'chofer') {
            $doc['chofer_id'] = obtener_chofer_importado($pdo, $titular, $contador_dni);
        } else {
            $doc['camion_id'] = obtener_camion_importado($pdo, $titular);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO documento (nombre, tipo, fecha_vencimiento, dias_aviso, notas, chofer_id, camion_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $doc['nombre'], $doc['tipo'], $doc['fecha_vencimiento'],
            $doc['dias_aviso'], $doc['notas'], $doc['chofer_id'], $doc['camion_id'],
        ]);
        $documentos++;
    }

    return ['documentos_creados' => $documentos, 'filas_omitidas' => $omitidas];
}

/** Separa el Detalle en titulares (separados por "/") y nota entre paréntesis. */
function separar_detalle(string $detalle): array
{
    $nota = null;
    if (preg_match('/^(.*)\(([^)]*)\)\s*$/', $detalle, $m)) {
        $detalle = $m[1];
        $nota = trim($m[2]);
    }
    $titulares = array_values(array_filter(array_map('trim', explode('/', $detalle))));
    return [$titulares, $nota];
}

/** La próxima fecha futura, o la última pasada si no hay futuras. */
function elegir_fecha(array $fechas, string $hoy): string
{
    $futuras = array_filter($fechas, fn($f) => $f >= $hoy);
    if ($futuras !== []) {
        sort($futuras);
        return $futuras[0];
    }
    rsort($fechas);
    return $fechas[0];
}

/** Busca o crea un chofer por nombre completo (DNI provisorio PTE-…). */
function obtener_chofer_importado(PDO $pdo, string $nombre_completo, int &$contador): int
{
    $partes = preg_split('/\s+/', $nombre_completo);
    $nombre = $partes[0];
    $apellido = implode(' ', array_slice($partes, 1)) ?: '-';
    $stmt = $pdo->prepare('SELECT id FROM chofer WHERE nombre = ? AND apellido = ?');
    $stmt->execute([$nombre, $apellido]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $contador++;
    $existentes = (int) $pdo->query("SELECT COUNT(*) FROM chofer WHERE dni LIKE 'PTE-%'")->fetchColumn();
    $dni = sprintf('PTE-%03d', $existentes + 1);
    $pdo->prepare('INSERT INTO chofer (nombre, apellido, dni) VALUES (?, ?, ?)')
        ->execute([$nombre, $apellido, $dni]);
    return (int) $pdo->lastInsertId();
}

/** Busca o crea un camión por patente. */
function obtener_camion_importado(PDO $pdo, string $patente): int
{
    $patente = strtoupper($patente);
    $stmt = $pdo->prepare('SELECT id FROM camion WHERE patente = ?');
    $stmt->execute([$patente]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO camion (patente) VALUES (?)')->execute([$patente]);
    return (int) $pdo->lastInsertId();
}
