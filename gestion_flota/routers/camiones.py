"""Endpoints CRUD de camiones."""

from typing import List

from fastapi import APIRouter, Depends, HTTPException
from sqlmodel import Session, select

from ..database import get_session
from ..models import Camion
from ..schemas import CamionCreate, CamionRead
from ..serializers import camion_a_read

router = APIRouter(prefix="/api/camiones", tags=["camiones"])


def _obtener_camion(session: Session, camion_id: int) -> Camion:
    """
    Busca un camión por id o lanza 404.

    Args:
        session (Session): sesión de base de datos.
        camion_id (int): id del camión.

    Returns:
        Camion: el camión encontrado.
    """
    camion = session.get(Camion, camion_id)
    if camion is None:
        raise HTTPException(status_code=404, detail="Camión no encontrado")
    return camion


@router.get("", response_model=List[CamionRead])
def listar_camiones(session: Session = Depends(get_session)) -> List[CamionRead]:
    """
    Lista todos los camiones con sus documentos.

    Returns:
        List[CamionRead]: camiones ordenados por patente.
    """
    camiones = session.exec(select(Camion).order_by(Camion.patente)).all()
    return [camion_a_read(c) for c in camiones]


@router.post("", response_model=CamionRead, status_code=201)
def crear_camion(datos: CamionCreate, session: Session = Depends(get_session)) -> CamionRead:
    """
    Crea un camión nuevo.

    Args:
        datos (CamionCreate): datos del camión.

    Returns:
        CamionRead: el camión creado.
    """
    existente = session.exec(select(Camion).where(Camion.patente == datos.patente)).first()
    if existente is not None:
        raise HTTPException(status_code=409, detail="Ya existe un camión con esa matrícula")
    camion = Camion(**datos.model_dump())
    session.add(camion)
    session.commit()
    session.refresh(camion)
    return camion_a_read(camion)


@router.get("/{camion_id}", response_model=CamionRead)
def obtener_camion(camion_id: int, session: Session = Depends(get_session)) -> CamionRead:
    """
    Devuelve un camión por id.

    Args:
        camion_id (int): id del camión.

    Returns:
        CamionRead: el camión con sus documentos.
    """
    return camion_a_read(_obtener_camion(session, camion_id))


@router.put("/{camion_id}", response_model=CamionRead)
def actualizar_camion(
    camion_id: int, datos: CamionCreate, session: Session = Depends(get_session)
) -> CamionRead:
    """
    Actualiza los datos de un camión.

    Args:
        camion_id (int): id del camión.
        datos (CamionCreate): nuevos datos.

    Returns:
        CamionRead: el camión actualizado.
    """
    camion = _obtener_camion(session, camion_id)
    for campo, valor in datos.model_dump().items():
        setattr(camion, campo, valor)
    session.add(camion)
    session.commit()
    session.refresh(camion)
    return camion_a_read(camion)


@router.delete("/{camion_id}", status_code=204)
def eliminar_camion(camion_id: int, session: Session = Depends(get_session)) -> None:
    """
    Elimina un camión y todos sus documentos.

    Args:
        camion_id (int): id del camión.

    Returns:
        None
    """
    camion = _obtener_camion(session, camion_id)
    session.delete(camion)
    session.commit()
