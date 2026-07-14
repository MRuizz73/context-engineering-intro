<?php
/**
 * Recordatorios por email con el correo de la empresa.
 * Envío por PHPMailer (STARTTLS en 587 / SSL en 465) o mail() del hosting.
 * PHPMailer 6.8.1 oficial incluido en lib/phpmailer/ (licencia LGPL 2.1).
 */
declare(strict_types=1);

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

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

/** Envío por SMTP con PHPMailer: STARTTLS en 587, SSL directo en 465. */
function smtp_enviar(string $para, string $asunto, string $cuerpo): void
{
    $s = config()['smtp'];
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host     = $s['host'];
        $mail->Port     = (int) $s['puerto'];
        $mail->SMTPAuth = ($s['password'] ?? '') !== '';
        $mail->Username = $s['usuario'];
        $mail->Password = $s['password'];

        // Reason: 587 negocia STARTTLS (ENCRYPTION_STARTTLS); 465 conecta
        // cifrado desde el primer byte (ENCRYPTION_SMTPS). Con tls=false
        // no se fuerza cifrado (solo para pruebas locales).
        if ((int) $s['puerto'] === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (!empty($s['tls'])) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->CharSet  = PHPMailer::CHARSET_UTF8;
        $mail->Timeout  = 30;
        $mail->setLanguage('es', __DIR__ . '/phpmailer/');
        $mail->setFrom($s['usuario']);
        $mail->addAddress($para);
        $mail->Subject = $asunto;
        $mail->Body    = $cuerpo;
        $mail->send();
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        throw new ErrorHttp(
            502,
            'Error del servidor de correo: ' . ($mail->ErrorInfo ?: $e->getMessage())
        );
    }
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
 * Envía el recordatorio de UN documento concreto (botón 📧 Avisar).
 * No espera a la frecuencia automática: envía ya y reinicia el contador.
 */
function avisar_documento(PDO $pdo, int $documento_id): array
{
    $cfg = config();
    $hoy = date('Y-m-d');
    $admin = $cfg['email_admin'] ?: ($cfg['smtp']['usuario'] ?: ($cfg['email_remitente'] ?? ''));

    $doc = null;
    foreach (documentos_con_titular($pdo) as $d) {
        if ($d['id'] === $documento_id) {
            $doc = $d;
            break;
        }
    }
    if ($doc === null) {
        throw new ErrorHttp(404, 'Documento no encontrado');
    }
    $destino = $doc['email_destino'] ?: $admin;
    if ($destino === '') {
        throw new ErrorHttp(422, 'El chófer no tiene email en su perfil y no hay email administrativo configurado');
    }

    $cuerpo = "Hola,\n\n"
        . "Recordatorio sobre este curso/permiso:\n\n"
        . describir_documento($doc, $hoy)
        . "\n\nPor favor coordina la renovación cuanto antes.\n\n"
        . '— Gestión de Flota';
    enviar_email($destino, "⛽ Recordatorio: {$doc['nombre']} ({$doc['titular']})", $cuerpo);
    $pdo->prepare('UPDATE documento SET ultimo_aviso_email = ? WHERE id = ?')
        ->execute([$hoy, $documento_id]);
    return ['enviado_a' => $destino, 'documento' => $doc['nombre']];
}

/**
 * Envía un email de prueba al correo administrativo para verificar la
 * configuración (botón 🧪 Probar correo).
 */
function probar_correo(): array
{
    $cfg = config();
    $destino = $cfg['email_admin'] ?: ($cfg['smtp']['usuario'] ?: ($cfg['email_remitente'] ?? ''));
    if ($destino === '') {
        throw new ErrorHttp(422, 'Configura email_admin (o el buzón SMTP) en config.php');
    }
    enviar_email(
        $destino,
        '🧪 Prueba de correo — Gestión de Flota',
        "Si estás leyendo esto, el correo de la empresa está bien configurado.\n\n"
        . "Servidor: " . ($cfg['smtp']['host'] ?: 'mail() del hosting') . "\n"
        . '— Gestión de Flota'
    );
    return ['enviado_a' => $destino];
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
