"""Envío de recordatorios de vencimiento por email con el correo de la empresa.

La conexión se configura por variables de entorno (archivo `.env`):
    SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASSWORD  → cuenta de la empresa
    SMTP_TLS (1/0)                                  → usar STARTTLS (default 1)
    EMAIL_ADMIN            → destino de avisos de camiones y choferes sin email
    AVISO_EMAIL_CADA_DIAS  → cada cuántos días se repite el aviso (default 7)
"""

import os
import smtplib
from datetime import date
from email.message import EmailMessage
from typing import Dict, List, Optional, Tuple

from dotenv import load_dotenv
from sqlmodel import Session, select

from .models import Documento, EstadoDocumento

load_dotenv()


def smtp_configurado() -> bool:
    """
    Indica si hay un servidor de correo configurado.

    Returns:
        bool: True si SMTP_HOST y SMTP_USER están definidos.
    """
    return bool(os.getenv("SMTP_HOST") and os.getenv("SMTP_USER"))


def _enviar_smtp(destinatario: str, asunto: str, cuerpo: str) -> None:
    """
    Envía un email por SMTP con la cuenta de la empresa.

    Args:
        destinatario (str): dirección de destino.
        asunto (str): asunto del mensaje.
        cuerpo (str): texto plano del mensaje.

    Returns:
        None
    """
    host = os.getenv("SMTP_HOST", "")
    puerto = int(os.getenv("SMTP_PORT", "587"))
    usuario = os.getenv("SMTP_USER", "")
    password = os.getenv("SMTP_PASSWORD", "")
    usar_tls = os.getenv("SMTP_TLS", "1") == "1"

    mensaje = EmailMessage()
    mensaje["From"] = usuario
    mensaje["To"] = destinatario
    mensaje["Subject"] = asunto
    mensaje.set_content(cuerpo)

    with smtplib.SMTP(host, puerto, timeout=30) as smtp:
        if usar_tls:
            smtp.starttls()
        if password:
            smtp.login(usuario, password)
        smtp.send_message(mensaje)


def _describir(doc: Documento, hoy: date) -> str:
    """
    Arma la línea de detalle de un documento para el email.

    Args:
        doc (Documento): documento vencido o por vencer.
        hoy (date): fecha de referencia.

    Returns:
        str: línea legible con nombre, titular y vencimiento.
    """
    restantes = doc.dias_restantes(hoy)
    if restantes < 0:
        estado = f"VENCIDO hace {abs(restantes)} día(s)"
    elif restantes == 0:
        estado = "VENCE HOY"
    else:
        estado = f"vence en {restantes} día(s)"
    titular = (
        f"{doc.chofer.apellido}, {doc.chofer.nombre}"
        if doc.chofer
        else f"Camión {doc.camion.patente}" if doc.camion else "Sin titular"
    )
    fecha = doc.fecha_vencimiento.strftime("%d/%m/%Y")
    return f"- {doc.nombre} ({titular}): {estado} — vencimiento {fecha}"


def enviar_recordatorios(session: Session, hoy: Optional[date] = None) -> Dict:
    """
    Envía por email los recordatorios pendientes, agrupados por destinatario.

    Los documentos de choferes van al email del chofer; los de camiones (o de
    choferes sin email) van al email administrativo (EMAIL_ADMIN o SMTP_USER).
    Cada documento se avisa como máximo una vez cada AVISO_EMAIL_CADA_DIAS
    días, hasta que se renueve.

    Args:
        session (Session): sesión de base de datos.
        hoy (date | None): fecha de referencia; por defecto, hoy.

    Returns:
        Dict: resumen {"emails_enviados", "documentos_avisados", "sin_destinatario"}.
    """
    hoy = hoy or date.today()
    cada_dias = int(os.getenv("AVISO_EMAIL_CADA_DIAS", "7"))
    admin = os.getenv("EMAIL_ADMIN") or os.getenv("SMTP_USER", "")

    documentos = session.exec(select(Documento)).all()
    por_destinatario: Dict[str, List[Documento]] = {}
    sin_destinatario = 0

    for doc in documentos:
        if doc.estado(hoy) == EstadoDocumento.VIGENTE:
            continue
        if doc.ultimo_aviso_email and (hoy - doc.ultimo_aviso_email).days < cada_dias:
            continue
        destino = doc.chofer.email if doc.chofer and doc.chofer.email else admin
        if not destino:
            sin_destinatario += 1
            continue
        por_destinatario.setdefault(destino, []).append(doc)

    avisados = 0
    for destino, docs in por_destinatario.items():
        lineas = "\n".join(_describir(d, hoy) for d in docs)
        cuerpo = (
            "Hola,\n\n"
            "Estos cursos/permisos están vencidos o por vencer y necesitan renovación:\n\n"
            f"{lineas}\n\n"
            "Por favor coordina la renovación cuanto antes.\n\n"
            "— Gestión de Flota (mensaje automático)"
        )
        _enviar_smtp(destino, "⛽ Recordatorio de renovación de cursos/permisos", cuerpo)
        for doc in docs:
            doc.ultimo_aviso_email = hoy
            session.add(doc)
        avisados += len(docs)

    session.commit()
    return {
        "emails_enviados": len(por_destinatario),
        "documentos_avisados": avisados,
        "sin_destinatario": sin_destinatario,
    }
