"""Endpoints CRUD de choferes."""

from typing import List

from fastapi import APIRouter, Depends, HTTPException
from sqlmodel import Session, select

from ..database import get_session
from ..models import Chofer
from ..schemas import ChoferCreate, ChoferRead
from ..serializers import chofer_a_read

router = APIRouter(prefix="/api/choferes", tags=["choferes"])


def _obtener_chofer(session: Session, chofer_id: int) -> Chofer:
    """
    Busca un chofer por id o lanza 404.

    Args:
        session (Session): sesión de base de datos.
        chofer_id (int): id del chofer.

    Returns:
        Chofer: el chofer encontrado.
    """
    chofer = session.get(Chofer, chofer_id)
    if chofer is None:
        raise HTTPException(status_code=404, detail="Chofer no encontrado")
    return chofer


@router.get("", response_model=List[ChoferRead])
def listar_choferes(session: Session = Depends(get_session)) -> List[ChoferRead]:
    """
    Lista todos los choferes con sus documentos.

    Returns:
        List[ChoferRead]: choferes ordenados por apellido.
    """
    choferes = session.exec(select(Chofer).order_by(Chofer.apellido, Chofer.nombre)).all()
    return [chofer_a_read(c) for c in choferes]


@router.post("", response_model=ChoferRead, status_code=201)
def crear_chofer(datos: ChoferCreate, session: Session = Depends(get_session)) -> ChoferRead:
    """
    Crea un chofer nuevo.

    Args:
        datos (ChoferCreate): datos del chofer.

    Returns:
        ChoferRead: el chofer creado.
    """
    existente = session.exec(select(Chofer).where(Chofer.dni == datos.dni)).first()
    if existente is not None:
        raise HTTPException(status_code=409, detail="Ya existe un chofer con ese DNI")
    chofer = Chofer(**datos.model_dump())
    session.add(chofer)
    session.commit()
    session.refresh(chofer)
    return chofer_a_read(chofer)


@router.get("/{chofer_id}", response_model=ChoferRead)
def obtener_chofer(chofer_id: int, session: Session = Depends(get_session)) -> ChoferRead:
    """
    Devuelve un chofer por id.

    Args:
        chofer_id (int): id del chofer.

    Returns:
        ChoferRead: el chofer con sus documentos.
    """
    return chofer_a_read(_obtener_chofer(session, chofer_id))


@router.put("/{chofer_id}", response_model=ChoferRead)
def actualizar_chofer(
    chofer_id: int, datos: ChoferCreate, session: Session = Depends(get_session)
) -> ChoferRead:
    """
    Actualiza los datos de un chofer.

    Args:
        chofer_id (int): id del chofer.
        datos (ChoferCreate): nuevos datos.

    Returns:
        ChoferRead: el chofer actualizado.
    """
    chofer = _obtener_chofer(session, chofer_id)
    for campo, valor in datos.model_dump().items():
        setattr(chofer, campo, valor)
    session.add(chofer)
    session.commit()
    session.refresh(chofer)
    return chofer_a_read(chofer)


@router.delete("/{chofer_id}", status_code=204)
def eliminar_chofer(chofer_id: int, session: Session = Depends(get_session)) -> None:
    """
    Elimina un chofer y todos sus documentos.

    Args:
        chofer_id (int): id del chofer.

    Returns:
        None
    """
    chofer = _obtener_chofer(session, chofer_id)
    session.delete(chofer)
    session.commit()
