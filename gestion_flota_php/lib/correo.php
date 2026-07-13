<?php
/**
 * Recordatorios por email con el correo de la empresa.
 * SMTP propio (socket, STARTTLS, AUTH LOGIN) o mail() del hosting.
 */
declare(strict_types=1);

/** ¿Hay una forma de enviar correo configurada? */
function correo_configurado(): bool
{
    $cfg = config();
    return $cfg['smtp']['host'] !== '' || ($cfg['email_remitente'] ?? '') !== '';
}

/** Envía un email (SMTP si hay host; si no, mail() del hosting). */
function enviar_email(string $para, string $asunto, string $cuerpo): void
{
    $cfg = config();
    if ($cfg['smtp']['host'] !== '') {
        smtp_enviar($para, $asunto, $cuerpo);
        return;
    }
    $de = $cfg['email_remitente'];
    $cabeceras = "From: $de\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    $asunto_mime = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
    if (!mail($para, $asunto_mime, $cuerpo, $cabeceras)) {
        throw new ErrorHttp(502, 'La función mail() del hosting rechazó el envío');
    }
}

/** Cliente SMTP mínimo: EHLO, STARTTLS, AUTH LOGIN, envío. */
function smtp_enviar(string $para, string $asunto, string $cuerpo): void
{
    $s = config()['smtp'];
    $socket = @stream_socket_client("tcp://{$s['host']}:{$s['puerto']}", $err, $msj, 30);
    if ($socket === false) {
        throw new ErrorHttp(502, "No se pudo conectar al servidor de correo: $msj");
    }
    $leer = function () use ($socket): string {
        $resp = '';
        while (($linea = fgets($socket, 515)) !== false) {
            $resp .= $linea;
            if (strlen($linea) < 4 || $linea[3] !== '-') {
                break;
            }
        }
        return $resp;
    };
    $mandar = function (string $cmd) use ($socket, $leer): string {
        fwrite($socket, $cmd . "\r\n");
        $resp = $leer();
        if ((int) substr($resp, 0, 3) >= 400) {
            throw new ErrorHttp(502, 'Error del servidor de correo: ' . trim($resp));
        }
        return $resp;
    };

    $leer();
    $mandar('EHLO gestionflota');
    if ($s['tls']) {
        $mandar('STARTTLS');
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new ErrorHttp(502, 'No se pudo iniciar TLS con el servidor de correo');
        }
        $mandar('EHLO gestionflota');
    }
    if (($s['password'] ?? '') !== '') {
        $mandar('AUTH LOGIN');
        $mandar(base64_encode($s['usuario']));
        $mandar(base64_encode($s['password']));
    }

    $de = $s['usuario'];
    $mandar("MAIL FROM:<$de>");
    $mandar("RCPT TO:<$para>");
    $mandar('DATA');
    $mensaje = "From: $de\r\nTo: $para\r\n"
        . 'Subject: =?UTF-8?B?' . base64_encode($asunto) . "?=\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($cuerpo));
    $mandar($mensaje . "\r\n.");
    $mandar('QUIT');
    fclose($socket);
}

/** Línea legible de un documento para el cuerpo del email. */
function describir_documento(array $doc, string $hoy): string
{
    $dias = dias_entre($hoy, $doc['fecha_vencimiento']);
    if ($dias < 0) {
        $estado = 'VENCIDO hace ' . abs($dias) . ' día(s)';
    } elseif ($dias === 0) {
        $estado = 'VENCE HOY';
    } else {
        $estado = "vence en $dias día(s)";
    }
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $doc['fecha_vencimiento'])->format('d/m/Y');
    return "- {$doc['nombre']} ({$doc['titular']}): $estado — vencimiento $fecha";
}

/**
 * Envía los recordatorios pendientes agrupados por destinatario.
 * Cada documento se avisa una vez cada `aviso_email_cada_dias` días hasta
 * que se renueve. Los de camiones (o choferes sin email) van al admin.
 */
function enviar_recordatorios(PDO $pdo, ?string $hoy = null): array
{
    $cfg = config();
    $hoy = $hoy ?? date('Y-m-d');
    $cada_dias = (int) $cfg['aviso_email_cada_dias'];
    $admin = $cfg['email_admin'] ?: ($cfg['smtp']['usuario'] ?: ($cfg['email_remitente'] ?? ''));

    $docs = documentos_con_titular($pdo);
    $por_destino = [];
    $sin_destinatario = 0;

    foreach ($docs as $doc) {
        if ($doc['estado'] === 'vigente') {
            continue;
        }
        if ($doc['ultimo_aviso_email'] !== null
            && dias_entre($doc['ultimo_aviso_email'], $hoy) < $cada_dias) {
            continue;
        }
        $destino = $doc['email_destino'] ?: $admin;
        if ($destino === '') {
            $sin_destinatario++;
            continue;
        }
        $por_destino[$destino][] = $doc;
    }

    $avisados = 0;
    foreach ($por_destino as $destino => $lista) {
        $lineas = implode("\n", array_map(fn($d) => describir_documento($d, $hoy), $lista));
        $cuerpo = "Hola,\n\n"
            . "Estos cursos/permisos están vencidos o por vencer y necesitan renovación:\n\n"
            . "$lineas\n\nPor favor coordina la renovación cuanto antes.\n\n"
            . '— Gestión de Flota (mensaje automático)';
        enviar_email($destino, '⛽ Recordatorio de renovación de cursos/permisos', $cuerpo);
        $stmt = $pdo->prepare('UPDATE documento SET ultimo_aviso_email = ? WHERE id = ?');
        foreach ($lista as $doc) {
            $stmt->execute([$hoy, $doc['id']]);
            $avisados++;
        }
    }

    return [
        'emails_enviados'     => count($por_destino),
        'documentos_avisados' => $avisados,
        'sin_destinatario'    => $sin_destinatario,
    ];
}

/**
 * Chequeo automático: corre los recordatorios como mucho cada 12 horas,
 * disparado por las visitas a la app (no hace falta configurar cron,
 * aunque cron.php sigue siendo la opción más fiable).
 */
function chequeo_automatico_avisos(PDO $pdo): void
{
    if (!correo_configurado()) {
        return;
    }
    $stmt = $pdo->prepare('SELECT valor FROM ajuste WHERE clave = ?');
    $stmt->execute(['ultimo_chequeo_avisos']);
    $ultimo = $stmt->fetchColumn();
    if ($ultimo !== false && time() - (int) $ultimo < 12 * 3600) {
        return;
    }
    // Reason: se marca ANTES de enviar para que dos visitas simultáneas no
    // dupliquen los emails.
    if ($ultimo === false) {
        $pdo->prepare('INSERT INTO ajuste (clave, valor) VALUES (?, ?)')
            ->execute(['ultimo_chequeo_avisos', (string) time()]);
    } else {
        $pdo->prepare('UPDATE ajuste SET valor = ? WHERE clave = ?')
            ->execute([(string) time(), 'ultimo_chequeo_avisos']);
    }
    try {
        enviar_recordatorios($pdo);
    } catch (Throwable $e) {
        error_log('Gestión de Flota: error enviando recordatorios: ' . $e->getMessage());
    }
}
