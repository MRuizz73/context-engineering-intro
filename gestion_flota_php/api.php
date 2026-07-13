<?php
/**
 * Router de la API JSON de Gestión de Flota (misma interfaz que la app web).
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/datos.php';
require_once __DIR__ . '/lib/correo.php';

header('Content-Type: application/json; charset=utf-8');

$metodo = $_SERVER['REQUEST_METHOD'];
$ruta_completa = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$posicion = strpos($ruta_completa, '/api/');
$ruta = $posicion === false ? '' : substr($ruta_completa, $posicion + 5);
$seg = array_values(array_filter(explode('/', $ruta), 'strlen'));
$cuerpo = json_decode((string) file_get_contents('php://input'), true) ?? [];

try {
    $pdo = db();
    $respuesta = enrutar($pdo, $metodo, $seg, $cuerpo);
    if (http_response_code() !== 204) {
        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
    }
    // Chequeo de recordatorios disparado por el uso normal de la app.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    chequeo_automatico_avisos($pdo);
} catch (ErrorHttp $e) {
    http_response_code($e->codigo);
    echo json_encode(['detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Gestión de Flota: ' . $e->getMessage());
    echo json_encode(['detail' => 'Error interno del servidor'], JSON_UNESCAPED_UNICODE);
}

/** Despacha la petición al handler correspondiente. */
function enrutar(PDO $pdo, string $metodo, array $seg, array $cuerpo): mixed
{
    $recurso = $seg[0] ?? '';
    $id = isset($seg[1]) && ctype_digit($seg[1]) ? (int) $seg[1] : null;
    $accion = $seg[2] ?? null;

    // --- auth ---
    if ($recurso === 'auth') {
        return match ("$metodo {$seg[1]}") {
            'POST registro'         => auth_registro($cuerpo),
            'POST login'            => auth_login($cuerpo),
            'POST logout'           => auth_logout(),
            'POST cambiar-password' => auth_cambiar_password($cuerpo),
            'GET yo'                => usuario_a_read(requiere_usuario()),
            default                 => throw new ErrorHttp(404, 'Ruta no encontrada'),
        };
    }

    $usuario = requiere_usuario();

    // --- choferes ---
    if ($recurso === 'choferes') {
        if ($id === null) {
            return match ($metodo) {
                'GET'   => choferes_listar($pdo),
                'POST'  => chofer_crear($pdo, $cuerpo),
                default => throw new ErrorHttp(405, 'Método no permitido'),
            };
        }
        if ($accion === 'crear-cuenta' && $metodo === 'POST') {
            requiere_admin();
            return crear_cuenta_chofer(chofer_obtener_fila($pdo, $id));
        }
        return match ($metodo) {
            'GET' => (function () use ($pdo, $id, $usuario) {
                verificar_acceso_chofer($usuario, $id);
                return chofer_a_read($pdo, chofer_obtener_fila($pdo, $id));
            })(),
            'PUT'    => chofer_actualizar($pdo, $id, $cuerpo, $usuario),
            'DELETE' => (function () use ($pdo, $id) {
                requiere_admin();
                chofer_obtener_fila($pdo, $id);
                $pdo->prepare('DELETE FROM documento WHERE chofer_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM usuario WHERE chofer_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM chofer WHERE id = ?')->execute([$id]);
                http_response_code(204);
                return null;
            })(),
            default => throw new ErrorHttp(405, 'Método no permitido'),
        };
    }

    // --- camiones (solo admin) ---
    if ($recurso === 'camiones') {
        requiere_admin();
        if ($id === null) {
            return match ($metodo) {
                'GET'   => camiones_listar($pdo),
                'POST'  => camion_crear($pdo, $cuerpo),
                default => throw new ErrorHttp(405, 'Método no permitido'),
            };
        }
        return match ($metodo) {
            'GET' => (function () use ($pdo, $id) {
                [, $por_camion] = docs_agrupados($pdo);
                return camion_a_read(camion_obtener_fila($pdo, $id), $por_camion[$id] ?? []);
            })(),
            'PUT'    => camion_actualizar($pdo, $id, $cuerpo),
            'DELETE' => (function () use ($pdo, $id) {
                camion_obtener_fila($pdo, $id);
                $pdo->prepare('DELETE FROM documento WHERE camion_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM camion WHERE id = ?')->execute([$id]);
                http_response_code(204);
                return null;
            })(),
            default => throw new ErrorHttp(405, 'Método no permitido'),
        };
    }

    // --- documentos ---
    if ($recurso === 'documentos') {
        if ($id === null) {
            if ($metodo === 'GET') {
                $filtro = $usuario['rol'] === 'admin' ? null : (int) $usuario['chofer_id'];
                $docs = array_map('doc_publico', documentos_con_titular($pdo, $filtro));
                if (isset($_GET['estado'])) {
                    $docs = array_values(array_filter($docs, fn($d) => $d['estado'] === $_GET['estado']));
                }
                return $docs;
            }
            if ($metodo === 'POST') {
                return documento_crear($pdo, $cuerpo, $usuario);
            }
            throw new ErrorHttp(405, 'Método no permitido');
        }
        if ($accion === 'renovar' && $metodo === 'POST') {
            return documento_renovar($pdo, $id, $cuerpo, $usuario);
        }
        return match ($metodo) {
            'PUT'    => documento_actualizar($pdo, $id, $cuerpo),
            'DELETE' => (function () use ($pdo, $id) {
                documento_eliminar($pdo, $id);
                return null;
            })(),
            default => throw new ErrorHttp(405, 'Método no permitido'),
        };
    }

    // --- vencimientos ---
    if ($recurso === 'vencimientos' && $metodo === 'GET') {
        $filtro = $usuario['rol'] === 'admin' ? null : (int) $usuario['chofer_id'];
        $docs = array_map('doc_publico', documentos_con_titular($pdo, $filtro));
        return array_values(array_filter($docs, fn($d) => $d['estado'] !== 'vigente'));
    }

    // --- avisos ---
    if ($recurso === 'avisos' && ($seg[1] ?? '') === 'enviar' && $metodo === 'POST') {
        requiere_admin();
        if (!correo_configurado()) {
            throw new ErrorHttp(503, 'El correo de la empresa no está configurado. Completá la sección smtp de config.php.');
        }
        return enviar_recordatorios($pdo);
    }

    throw new ErrorHttp(404, 'Ruta no encontrada');
}
