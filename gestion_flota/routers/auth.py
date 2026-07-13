"""Endpoints de registro, login y logout (sin verificación por email)."""

import secrets

from fastapi import APIRouter, Depends, HTTPException, Request, Response
from sqlmodel import Session, select

from ..database import get_session
from ..models import Sesion, Usuario
from ..schemas import Credenciales, UsuarioRead
from ..seguridad import (
    COOKIE_SESION,
    hashear_password,
    usuario_actual,
    verificar_password,
)

router = APIRouter(prefix="/api/auth", tags=["auth"])

# Reason: 30 días de sesión evita re-loguearse a diario en la PC de la oficina.
DURACION_COOKIE = 60 * 60 * 24 * 30


def _iniciar_sesion(response: Response, session: Session, usuario: Usuario) -> None:
    """
    Crea una sesión en la base y setea la cookie en la respuesta.

    Args:
        response (Response): respuesta HTTP donde va la cookie.
        session (Session): sesión de base de datos.
        usuario (Usuario): usuario que inicia sesión.

    Returns:
        None
    """
    token = secrets.token_urlsafe(32)
    session.add(Sesion(token=token, usuario_id=usuario.id))
    session.commit()
    response.set_cookie(
        COOKIE_SESION,
        token,
        max_age=DURACION_COOKIE,
        httponly=True,
        samesite="lax",
    )


@router.post("/registro", response_model=UsuarioRead, status_code=201)
def registrarse(
    datos: Credenciales, response: Response, session: Session = Depends(get_session)
) -> Usuario:
    """
    Crea una cuenta nueva y deja al usuario logueado.

    Args:
        datos (Credenciales): usuario y contraseña elegidos.

    Returns:
        Usuario: el usuario creado.
    """
    username = datos.username.strip().lower()
    existente = session.exec(select(Usuario).where(Usuario.username == username)).first()
    if existente is not None:
        raise HTTPException(status_code=409, detail="Ese nombre de usuario ya existe")
    usuario = Usuario(username=username, password_hash=hashear_password(datos.password))
    session.add(usuario)
    session.commit()
    session.refresh(usuario)
    _iniciar_sesion(response, session, usuario)
    return usuario


@router.post("/login", response_model=UsuarioRead)
def ingresar(
    datos: Credenciales, response: Response, session: Session = Depends(get_session)
) -> Usuario:
    """
    Inicia sesión con usuario y contraseña.

    Args:
        datos (Credenciales): credenciales ingresadas.

    Returns:
        Usuario: el usuario autenticado.
    """
    username = datos.username.strip().lower()
    usuario = session.exec(select(Usuario).where(Usuario.username == username)).first()
    if usuario is None or not verificar_password(datos.password, usuario.password_hash):
        raise HTTPException(status_code=401, detail="Usuario o contraseña incorrectos")
    _iniciar_sesion(response, session, usuario)
    return usuario


@router.post("/logout", status_code=204)
def salir(
    request: Request, response: Response, session: Session = Depends(get_session)
) -> None:
    """
    Cierra la sesión actual y borra la cookie.

    Returns:
        None
    """
    token = request.cookies.get(COOKIE_SESION)
    if token:
        sesion = session.get(Sesion, token)
        if sesion is not None:
            session.delete(sesion)
            session.commit()
    response.delete_cookie(COOKIE_SESION)


@router.get("/yo", response_model=UsuarioRead)
def quien_soy(usuario: Usuario = Depends(usuario_actual)) -> Usuario:
    """
    Devuelve el usuario logueado (401 si no hay sesión).

    Returns:
        Usuario: el usuario actual.
    """
    return usuario
