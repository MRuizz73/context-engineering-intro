"""Endpoints CRUD de documentos (cursos/permisos) y recordatorios de vencimiento."""

from datetime import date
from typing import List, Optional

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlmodel import Session, select

from .. import correo
from ..database import get_session
from ..models import Camion, Chofer, Documento, EstadoDocumento, RolUsuario, Usuario
from ..schemas import DocumentoCreate, DocumentoRead, RenovacionDocumento
from ..seguridad import requiere_admin, usuario_actual
from ..serializers import documento_a_read

router = APIRouter(prefix="/api", tags=["documentos"])


def _filtrar_por_rol(docs: List[Documento], usuario: Usuario) -> List[Documento]:
    """
    Restringe la lista a los documentos del chofer si la cuenta no es admin.

    Args:
        docs (List[Documento]): documentos a filtrar.
        usuario (Usuario): usuario autenticado.

    Returns:
        List[Documento]: todos para el admin; solo los propios para un chofer.
    """
    if usuario.rol == RolUsuario.ADMIN:
        return docs
    return [d for d in docs if d.chofer_id == usuario.chofer_id]


def _validar_titular(session: Session, datos: DocumentoCreate) -> None:
    """
    Verifica que el chofer o camión referenciado exista.

    Args:
        session (Session): sesión de base de datos.
        datos (DocumentoCreate): datos del documento.

    Raises:
        HTTPException: 404 si el titular no existe.
    """
    if datos.chofer_id is not None and session.get(Chofer, datos.chofer_id) is None:
        raise HTTPException(status_code=404, detail="Chofer no encontrado")
    if datos.camion_id is not None and session.get(Camion, datos.camion_id) is None:
        raise HTTPException(status_code=404, detail="Camión no encontrado")


@router.get("/documentos", response_model=List[DocumentoRead])
def listar_documentos(
    estado: Optional[EstadoDocumento] = Query(default=None),
    session: Session = Depends(get_session),
    usuario: Usuario = Depends(usuario_actual),
) -> List[DocumentoRead]:
    """
    Lista los documentos visibles para el usuario (un chofer ve solo los suyos).

    Args:
        estado (EstadoDocumento | None): filtro opcional (vigente/por_vencer/vencido).

    Returns:
        List[DocumentoRead]: documentos ordenados por fecha de vencimiento.
    """
    docs = session.exec(select(Documento).order_by(Documento.fecha_vencimiento)).all()
    leidos = [documento_a_read(d) for d in _filtrar_por_rol(docs, usuario)]
    if estado is not None:
        leidos = [d for d in leidos if d.estado == estado]
    return leidos


@router.get("/vencimientos", response_model=List[DocumentoRead])
def proximos_vencimientos(
    session: Session = Depends(get_session),
    usuario: Usuario = Depends(usuario_actual),
) -> List[DocumentoRead]:
    """
    Devuelve los recordatorios activos visibles para el usuario.

    Un documento entra en la lista cuando quedan `dias_aviso` días o menos
    para su vencimiento (o ya venció). Un chofer solo ve sus propias alertas.

    Returns:
        List[DocumentoRead]: alertas ordenadas de más urgente a menos urgente.
    """
    docs = session.exec(select(Documento).order_by(Documento.fecha_vencimiento)).all()
    leidos = [documento_a_read(d) for d in _filtrar_por_rol(docs, usuario)]
    return [d for d in leidos if d.estado != EstadoDocumento.VIGENTE]


@router.post("/documentos", response_model=DocumentoRead, status_code=201)
def crear_documento(
    datos: DocumentoCreate,
    session: Session = Depends(get_session),
    usuario: Usuario = Depends(usuario_actual),
) -> DocumentoRead:
    """
    Crea un documento asociado a un chofer o a un camión.

    Un chofer solo puede añadir documentos a su propio perfil.

    Args:
        datos (DocumentoCreate): datos del documento.

    Returns:
        DocumentoRead: el documento creado con su estado.
    """
    if usuario.rol != RolUsuario.ADMIN and datos.chofer_id != usuario.chofer_id:
        raise HTTPException(
            status_code=403, detail="Solo podés añadir cursos a tu propio perfil"
        )
    _validar_titular(session, datos)
    doc = Documento(**datos.model_dump())
    session.add(doc)
    session.commit()
    session.refresh(doc)
    return documento_a_read(doc)


@router.put(
    "/documentos/{documento_id}",
    response_model=DocumentoRead,
    dependencies=[Depends(requiere_admin)],
)
def actualizar_documento(
    documento_id: int, datos: DocumentoCreate, session: Session = Depends(get_session)
) -> DocumentoRead:
    """
    Actualiza un documento (por ejemplo, al renovarlo con nueva fecha).

    Args:
        documento_id (int): id del documento.
        datos (DocumentoCreate): nuevos datos.

    Returns:
        DocumentoRead: el documento actualizado.
    """
    doc = session.get(Documento, documento_id)
    if doc is None:
        raise HTTPException(status_code=404, detail="Documento no encontrado")
    _validar_titular(session, datos)
    for campo, valor in datos.model_dump().items():
        setattr(doc, campo, valor)
    session.add(doc)
    session.commit()
    session.refresh(doc)
    return documento_a_read(doc)


@router.post("/documentos/{documento_id}/renovar", response_model=DocumentoRead)
def renovar_documento(
    documento_id: int,
    datos: RenovacionDocumento,
    session: Session = Depends(get_session),
    usuario: Usuario = Depends(usuario_actual),
) -> DocumentoRead:
    """
    Marca un documento como renovado: nueva vigencia y alerta despejada.

    La alerta de un documento vencido/por vencer permanece activa hasta que
    se confirma la renovación por este endpoint con la nueva fecha. Un
    chofer solo puede renovar sus propios documentos.

    Args:
        documento_id (int): id del documento renovado.
        datos (RenovacionDocumento): nueva fecha de vencimiento (y emisión).

    Returns:
        DocumentoRead: el documento ya renovado (estado vigente).
    """
    doc = session.get(Documento, documento_id)
    if doc is None:
        raise HTTPException(status_code=404, detail="Documento no encontrado")
    if usuario.rol != RolUsuario.ADMIN and doc.chofer_id != usuario.chofer_id:
        raise HTTPException(
            status_code=403, detail="Solo podés renovar tus propios documentos"
        )
    if datos.fecha_vencimiento <= date.today():
        raise HTTPException(
            status_code=422,
            detail="La nueva fecha de vencimiento debe ser posterior a hoy",
        )
    doc.fecha_emision = datos.fecha_emision or date.today()
    doc.fecha_vencimiento = datos.fecha_vencimiento
    doc.ultimo_aviso_email = None
    session.add(doc)
    session.commit()
    session.refresh(doc)
    return documento_a_read(doc)


@router.post("/avisos/enviar", dependencies=[Depends(requiere_admin)])
def enviar_avisos(session: Session = Depends(get_session)) -> dict:
    """
    Envía ahora los recordatorios por email con el correo de la empresa.

    Returns:
        dict: resumen de emails enviados y documentos avisados.

    Raises:
        HTTPException: 503 si no hay servidor de correo configurado.
    """
    if not correo.smtp_configurado():
        raise HTTPException(
            status_code=503,
            detail=(
                "El correo de la empresa no está configurado. Definí SMTP_HOST, "
                "SMTP_USER y SMTP_PASSWORD en el archivo .env y reiniciá la app."
            ),
        )
    try:
        return correo.enviar_recordatorios(session)
    except Exception as exc:  # Reason: el error SMTP debe llegar legible a la UI.
        raise HTTPException(status_code=502, detail=f"Error al enviar emails: {exc}")


@router.delete(
    "/documentos/{documento_id}", status_code=204, dependencies=[Depends(requiere_admin)]
)
def eliminar_documento(documento_id: int, session: Session = Depends(get_session)) -> None:
    """
    Elimina un documento.

    Args:
        documento_id (int): id del documento.

    Returns:
        None
    """
    doc = session.get(Documento, documento_id)
    if doc is None:
        raise HTTPException(status_code=404, detail="Documento no encontrado")
    session.delete(doc)
    session.commit()
