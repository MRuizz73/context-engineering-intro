"""Funciones de serialización de modelos a esquemas de lectura."""

from datetime import date
from typing import Optional

from .models import Camion, Chofer, Documento
from .schemas import CamionRead, ChoferRead, DocumentoRead


def titular_de(doc: Documento) -> str:
    """
    Devuelve una descripción legible del titular del documento.

    Args:
        doc (Documento): documento a describir.

    Returns:
        str: nombre del chofer o patente del camión.
    """
    if doc.chofer is not None:
        return f"{doc.chofer.apellido}, {doc.chofer.nombre}"
    if doc.camion is not None:
        return f"Camión {doc.camion.patente}"
    return "Sin titular"


def documento_a_read(doc: Documento, hoy: Optional[date] = None) -> DocumentoRead:
    """
    Convierte un Documento a su esquema de lectura con estado calculado.

    Args:
        doc (Documento): documento de la base.
        hoy (date | None): fecha de referencia para el estado.

    Returns:
        DocumentoRead: esquema listo para la API.
    """
    return DocumentoRead(
        id=doc.id,
        nombre=doc.nombre,
        tipo=doc.tipo,
        fecha_emision=doc.fecha_emision,
        fecha_vencimiento=doc.fecha_vencimiento,
        dias_aviso=doc.dias_aviso,
        notas=doc.notas,
        chofer_id=doc.chofer_id,
        camion_id=doc.camion_id,
        estado=doc.estado(hoy),
        dias_restantes=doc.dias_restantes(hoy),
        titular=titular_de(doc),
    )


def chofer_a_read(chofer: Chofer) -> ChoferRead:
    """
    Convierte un Chofer a su esquema de lectura con documentos.

    Args:
        chofer (Chofer): chofer de la base.

    Returns:
        ChoferRead: esquema listo para la API.
    """
    return ChoferRead(
        id=chofer.id,
        nombre=chofer.nombre,
        apellido=chofer.apellido,
        dni=chofer.dni,
        telefono=chofer.telefono,
        email=chofer.email,
        activo=chofer.activo,
        documentos=[documento_a_read(d) for d in chofer.documentos],
    )


def camion_a_read(camion: Camion) -> CamionRead:
    """
    Convierte un Camion a su esquema de lectura con documentos.

    Args:
        camion (Camion): camión de la base.

    Returns:
        CamionRead: esquema listo para la API.
    """
    return CamionRead(
        id=camion.id,
        patente=camion.patente,
        marca=camion.marca,
        modelo=camion.modelo,
        anio=camion.anio,
        activo=camion.activo,
        documentos=[documento_a_read(d) for d in camion.documentos],
    )
