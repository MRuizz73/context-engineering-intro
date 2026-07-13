"""Hashing de contraseñas y dependencia de usuario autenticado."""

import hashlib
import secrets

from fastapi import Depends, HTTPException, Request
from sqlmodel import Session

from .database import get_session
from .models import RolUsuario, Sesion, Usuario

# Reason: PBKDF2 viene en la biblioteca estándar; evita sumar dependencias
# de terceros para un login sencillo sin verificación por email.
ITERACIONES = 200_000
COOKIE_SESION = "sesion"


def hashear_password(password: str) -> str:
    """
    Genera un hash PBKDF2 con salt aleatorio.

    Args:
        password (str): contraseña en texto plano.

    Returns:
        str: hash en formato "salt$hash".
    """
    salt = secrets.token_hex(16)
    derivado = hashlib.pbkdf2_hmac(
        "sha256", password.encode(), salt.encode(), ITERACIONES
    )
    return f"{salt}${derivado.hex()}"


def verificar_password(password: str, guardado: str) -> bool:
    """
    Compara una contraseña contra el hash guardado.

    Args:
        password (str): contraseña ingresada.
        guardado (str): hash almacenado ("salt$hash").

    Returns:
        bool: True si coinciden.
    """
    try:
        salt, esperado = guardado.split("$", 1)
    except ValueError:
        return False
    derivado = hashlib.pbkdf2_hmac(
        "sha256", password.encode(), salt.encode(), ITERACIONES
    )
    return secrets.compare_digest(derivado.hex(), esperado)


def usuario_actual(
    request: Request, session: Session = Depends(get_session)
) -> Usuario:
    """
    Obtiene el usuario logueado a partir de la cookie de sesión.

    Args:
        request (Request): petición HTTP entrante.
        session (Session): sesión de base de datos.

    Returns:
        Usuario: el usuario autenticado.

    Raises:
        HTTPException: 401 si no hay sesión válida.
    """
    token = request.cookies.get(COOKIE_SESION)
    if not token:
        raise HTTPException(status_code=401, detail="No autenticado")
    sesion = session.get(Sesion, token)
    if sesion is None:
        raise HTTPException(status_code=401, detail="Sesión inválida o expirada")
    usuario = session.get(Usuario, sesion.usuario_id)
    if usuario is None:
        raise HTTPException(status_code=401, detail="Usuario inexistente")
    return usuario


def requiere_admin(usuario: Usuario = Depends(usuario_actual)) -> Usuario:
    """
    Exige que el usuario logueado tenga rol de administrador.

    Args:
        usuario (Usuario): usuario autenticado.

    Returns:
        Usuario: el mismo usuario, si es admin.

    Raises:
        HTTPException: 403 si la cuenta es de chofer.
    """
    if usuario.rol != RolUsuario.ADMIN:
        raise HTTPException(
            status_code=403, detail="Solo el responsable de transporte puede hacer esto"
        )
    return usuario
