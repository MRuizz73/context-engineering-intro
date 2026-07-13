"""Endpoints CRUD de documentos (cursos/permisos) y recordatorios de vencimiento."""

from typing import List, Optional

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlmodel import Session, select

from ..database import get_session
from ..models import Camion, Chofer, Documento, EstadoDocumento
from ..schemas import DocumentoCreate, DocumentoRead
from ..serializers import documento_a_read

router = APIRouter(prefix="/api", tags=["documentos"])


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
) -> List[DocumentoRead]:
    """
    Lista todos los documentos, opcionalmente filtrados por estado.

    Args:
        estado (EstadoDocumento | None): filtro opcional (vigente/por_vencer/vencido).

    Returns:
        List[DocumentoRead]: documentos ordenados por fecha de vencimiento.
    """
    docs = session.exec(select(Documento).order_by(Documento.fecha_vencimiento)).all()
    leidos = [documento_a_read(d) for d in docs]
    if estado is not None:
        leidos = [d for d in leidos if d.estado == estado]
    return leidos


@router.get("/vencimientos", response_model=List[DocumentoRead])
def proximos_vencimientos(session: Session = Depends(get_session)) -> List[DocumentoRead]:
    """
    Devuelve los recordatorios activos: documentos vencidos o por vencer.

    Un documento entra en la lista cuando quedan `dias_aviso` días o menos
    para su vencimiento (o ya venció).

    Returns:
        List[DocumentoRead]: alertas ordenadas de más urgente a menos urgente.
    """
    docs = session.exec(select(Documento).order_by(Documento.fecha_vencimiento)).all()
    leidos = [documento_a_read(d) for d in docs]
    return [d for d in leidos if d.estado != EstadoDocumento.VIGENTE]


@router.post("/documentos", response_model=DocumentoRead, status_code=201)
def crear_documento(
    datos: DocumentoCreate, session: Session = Depends(get_session)
) -> DocumentoRead:
    """
    Crea un documento asociado a un chofer o a un camión.

    Args:
        datos (DocumentoCreate): datos del documento.

    Returns:
        DocumentoRead: el documento creado con su estado.
    """
    _validar_titular(session, datos)
    doc = Documento(**datos.model_dump())
    session.add(doc)
    session.commit()
    session.refresh(doc)
    return documento_a_read(doc)


@router.put("/documentos/{documento_id}", response_model=DocumentoRead)
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


@router.delete("/documentos/{documento_id}", status_code=204)
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
