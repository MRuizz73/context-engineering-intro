"""Endpoints CRUD de choferes."""

from typing import List

from fastapi import APIRouter, Depends, HTTPException
from sqlmodel import Session, select

from .. import cuentas
from ..database import get_session
from ..models import Chofer, RolUsuario, Usuario
from ..schemas import ChoferCreate, ChoferRead
from ..seguridad import requiere_admin, usuario_actual
from ..serializers import chofer_a_read

router = APIRouter(prefix="/api/choferes", tags=["choferes"])


def _verificar_acceso(usuario: Usuario, chofer_id: int) -> None:
    """
    Permite el acceso al admin o al chofer dueño del perfil.

    Args:
        usuario (Usuario): usuario autenticado.
        chofer_id (int): perfil al que se quiere acceder.

    Raises:
        HTTPException: 403 si un chofer intenta ver un perfil ajeno.
    """
    if usuario.rol != RolUsuario.ADMIN and usuario.chofer_id != chofer_id:
        raise HTTPException(status_code=403, detail="Solo puedes ver tu propio perfil")


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
        raise HTTPException(status_code=404, detail="Chófer no encontrado")
    return chofer


@router.get("", response_model=List[ChoferRead], dependencies=[Depends(requiere_admin)])
def listar_choferes(session: Session = Depends(get_session)) -> List[ChoferRead]:
    """
    Lista todos los choferes con sus documentos.

    Returns:
        List[ChoferRead]: choferes ordenados por apellido.
    """
    choferes = session.exec(select(Chofer).order_by(Chofer.apellido, Chofer.nombre)).all()
    return [chofer_a_read(c) for c in choferes]


@router.post(
    "", response_model=ChoferRead, status_code=201, dependencies=[Depends(requiere_admin)]
)
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
        raise HTTPException(status_code=409, detail="Ya existe un chófer con ese DNI")
    chofer = Chofer(**datos.model_dump())
    session.add(chofer)
    session.commit()
    session.refresh(chofer)
    return chofer_a_read(chofer)


@router.get("/{chofer_id}", response_model=ChoferRead)
def obtener_chofer(
    chofer_id: int,
    session: Session = Depends(get_session),
    usuario: Usuario = Depends(usuario_actual),
) -> ChoferRead:
    """
    Devuelve un chofer por id (un chofer solo puede ver su propio perfil).

    Args:
        chofer_id (int): id del chofer.

    Returns:
        ChoferRead: el chofer con sus documentos.
    """
    _verificar_acceso(usuario, chofer_id)
    return chofer_a_read(_obtener_chofer(session, chofer_id))


@router.put("/{chofer_id}", response_model=ChoferRead)
def actualizar_chofer(
    chofer_id: int,
    datos: ChoferCreate,
    session: Session = Depends(get_session),
    usuario: Usuario = Depends(usuario_actual),
) -> ChoferRead:
    """
    Actualiza un chofer (un chofer solo puede editar su propio perfil).

    Args:
        chofer_id (int): id del chofer.
        datos (ChoferCreate): nuevos datos.

    Returns:
        ChoferRead: el chofer actualizado.
    """
    _verificar_acceso(usuario, chofer_id)
    chofer = _obtener_chofer(session, chofer_id)
    for campo, valor in datos.model_dump().items():
        setattr(chofer, campo, valor)
    session.add(chofer)
    session.commit()
    session.refresh(chofer)
    return chofer_a_read(chofer)


@router.post(
    "/{chofer_id}/crear-cuenta",
    status_code=201,
    dependencies=[Depends(requiere_admin)],
)
def crear_cuenta(chofer_id: int, session: Session = Depends(get_session)) -> dict:
    """
    Genera la cuenta de acceso de un chofer (usuario y contraseña).

    La contraseña se devuelve UNA sola vez: anotarla y entregársela al
    chofer, que luego puede cambiarla desde la app.

    Args:
        chofer_id (int): id del chofer.

    Returns:
        dict: {"username", "password"} generados.
    """
    chofer = _obtener_chofer(session, chofer_id)
    try:
        return cuentas.crear_cuenta_chofer(session, chofer)
    except ValueError as exc:
        raise HTTPException(status_code=409, detail=str(exc))


@router.delete("/{chofer_id}", status_code=204, dependencies=[Depends(requiere_admin)])
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
